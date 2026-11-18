<?php

namespace App\Tests\Functional;

use App\Chat\ChatAgent;
use App\Entity\ChatMessage;
use App\Entity\DogProfile;
use App\Entity\User;
use App\Tests\Support\FakeClaude;

/**
 * Tester feedback: an empty reply used up a message, a reply was cut off mid-sentence, and the
 * profile didn't grow from what the pet parent said in the chat.
 */
final class ChatReliabilityTest extends AppTestCase
{
    private function userWithDog(): User
    {
        $user = $this->createUser();
        $dog = (new DogProfile($user))->fillFromQuiz(QuizAccountTest::result(), 'en');
        $dog->setFood('Orijen', 'Original', 'dry', '', 'chat', new \DateTimeImmutable());
        $this->em->persist($dog);
        $this->em->flush();
        $this->client->loginUser($user);
        return $user;
    }

    private function send(string $text): string
    {
        $csrf = $this->client->request('GET', '/chat')->filter('#chat')->attr('data-csrf');
        $this->client->request('POST', '/chat/send', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], json_encode(['message' => $text]));
        return (string) $this->client->getInternalResponse()->getContent();
    }

    private function messages(): array
    {
        $this->em->clear();
        return $this->em->getRepository(ChatMessage::class)->findBy([], ['id' => 'ASC']);
    }

    public function testThinkingIsOffAndTheProfileIsInEveryRequest(): void
    {
        $this->userWithDog();
        $this->send('What should I do first?');
        $req = FakeClaude::$requests[0];
        $this->assertSame(['type' => 'disabled'], $req['thinking']);
        $this->assertGreaterThanOrEqual(4096, $req['max_tokens']);
        // Rules + course cached, then the dog's profile (with the Bowl Score), cached too.
        $this->assertCount(2, $req['system']);
        $this->assertStringContainsString('Bowl Score:', $req['system'][1]['text']);
        $this->assertStringContainsString('Orijen Original', $req['system'][1]['text']);
        $this->assertSame(['type' => 'ephemeral'], $req['system'][1]['cache_control']);
    }

    public function testAnEmptyReplyIsRetriedOnce(): void
    {
        $this->userWithDog();
        FakeClaude::$script = [['text' => ''], ['text' => 'Weigh her food for a week.']];
        $this->send('What should I do first?');
        $this->assertSame('Weigh her food for a week.', $this->messages()[1]->getContent());
        $this->assertCount(2, FakeClaude::$requests);
    }

    public function testTwoEmptyRepliesDontCountOrGetSaved(): void
    {
        $this->userWithDog();
        FakeClaude::$script = [['text' => ''], ['text' => '']];
        $body = $this->send('What should I do first?');
        $this->assertStringContainsString('event: error', $body);
        $this->assertStringContainsString('"remaining":2', $body);
        $this->assertSame([], $this->messages());
    }

    public function testACutOffReplyEndsAtItsLastFullSentence(): void
    {
        $this->userWithDog();
        FakeClaude::$script = [['text' => 'Weigh her food for a week. Then check her weight.' . "\n\n" . 'Next step: send a photo of', 'stop' => 'max_tokens']];
        $this->send('What should I do first?');
        $saved = $this->messages();
        $this->assertSame('Weigh her food for a week. Then check her weight.', $saved[1]->getContent());
    }

    public function testWhatTheyTellTheCoachIsAddedToTheProfile(): void
    {
        $this->userWithDog();
        FakeClaude::$script = [
            ['text' => '', 'tool' => ['name' => 'update_dog_profile', 'input' => [
                'weight_kg' => 31.5,
                'facts' => [['topic' => 'health', 'text' => 'Itchy paws since spring 2026']],
            ]]],
            ['text' => 'Thanks! Itchy paws are worth a vet check.'],
        ];
        $body = $this->send('She weighs 31.5 kg now and has had itchy paws since spring.');
        $this->assertSame('Thanks! Itchy paws are worth a vet check.', $this->messages()[1]->getContent());
        $this->assertStringContainsString('Added to your dog’s profile: 31.5 kg; Itchy paws since spring 2026', $body);

        $this->em->clear();
        $dog = $this->em->getRepository(DogProfile::class)->findOneBy([]);
        $this->assertSame(31.5, $dog->getWeightUpdate()['kg']);
        $this->assertSame('Itchy paws since spring 2026', $dog->getNotes()[0]['text']);

        // The next request carries the saved facts, and a fact can be replaced.
        FakeClaude::$script = [
            ['text' => '', 'tool' => ['name' => 'update_dog_profile', 'input' => ['facts' => [
                ['topic' => 'health', 'text' => 'Itching stopped after the food switch', 'replaces' => 'Itchy paws since spring 2026'],
            ]]]],
            ['text' => 'Great news!'],
        ];
        $this->send('The itching stopped!');
        $this->assertStringContainsString('Itchy paws since spring 2026', FakeClaude::$requests[2]['system'][1]['text']);
        $this->assertStringContainsString('Weight they told you later', FakeClaude::$requests[2]['system'][1]['text']);
        $this->em->clear();
        $dog = $this->em->getRepository(DogProfile::class)->findOneBy([]);
        $this->assertSame(['Itching stopped after the food switch'], array_column($dog->getNotes(), 'text'));

        // Retaking the quiz keeps what the coach learned; the dashboard shows it.
        $dog->fillFromQuiz(QuizAccountTest::result(), 'en');
        $this->em->flush();
        $this->client->request('GET', '/dashboard');
        $this->assertSelectorTextContains('.dash-knows', 'Itching stopped after the food switch');
        $this->assertSelectorTextContains('.dash-facts', '31.5 kg');
    }

    private function opener(): array
    {
        $crawler = $this->client->request('GET', '/chat');
        $url = $crawler->filter('#chat')->attr('data-opener-url');
        if ($url === null) {
            return [null, ''];
        }
        $this->client->request('POST', $url, [], [], ['HTTP_X_CSRF_TOKEN' => $crawler->filter('#chat')->attr('data-csrf')]);
        return [$this->client->getResponse()->getStatusCode(), (string) $this->client->getInternalResponse()->getContent()];
    }

    public function testTheCoachOpensAFirstChatAndAfterTwoDaysAway(): void
    {
        $this->userWithDog();
        FakeClaude::$script = [['text' => 'Hi Maria! How is Bella doing with her food this week?']];
        [$code] = $this->opener();
        $this->assertSame(200, $code);
        $this->assertStringContainsString('first time they open the chat', json_encode(FakeClaude::$requests[0]['messages']));
        $saved = $this->messages();
        $this->assertCount(1, $saved);
        $this->assertFalse($saved[0]->isFromUser());

        // Not again while the chat is fresh, and it didn't use a message.
        [$code] = $this->opener();
        $this->assertNull($code);
        $this->assertSelectorTextContains('#chat-remaining', '2');

        // Replying to it works: the history starts with a stand-in user turn.
        $this->send('Much better, thanks!');
        $this->assertSame('user', FakeClaude::$requests[1]['messages'][0]['role']);
        $this->assertSame('assistant', FakeClaude::$requests[1]['messages'][1]['role']);

        // Three days later, the coach checks in again, and the check-in cards wait.
        $this->em->getConnection()->executeStatement('UPDATE chat_message SET created_at = ?', [(new \DateTimeImmutable('-3 days'))->format('Y-m-d H:i:s')]);
        FakeClaude::$script = [['text' => 'Hi again! Did the itching settle?']];
        $crawler = $this->client->request('GET', '/chat');
        $this->assertCount(0, $crawler->filter('#chat-checkin'));
        [$code] = $this->opener();
        $this->assertSame(200, $code);
        $this->assertStringContainsString('It has been 3 days', json_encode(end(FakeClaude::$requests)['messages']));
        $this->assertCount(4, $this->messages());
    }

    public function testNoOpenerBeforeTheFoodIsKnownOrWhenNotDue(): void
    {
        $user = $this->createUser();
        $this->em->persist((new DogProfile($user))->fillFromQuiz(QuizAccountTest::result(), 'en'));
        $this->em->flush();
        $this->client->loginUser($user);
        [$code] = $this->opener();
        $this->assertNull($code); // the "snap the food label" step comes first

        $crawler = $this->client->request('GET', '/chat');
        $this->client->request('POST', '/chat/opener', [], [], ['HTTP_X_CSRF_TOKEN' => $crawler->filter('#chat')->attr('data-csrf')]);
        $this->assertResponseStatusCodeSame(204);
        $this->assertCount(0, FakeClaude::$requests);
    }

    public function testARedFlagGetsTheVetMessageAndNotTheCoach(): void
    {
        $this->userWithDog();
        FakeClaude::$redFlag = 'toxin';
        $body = $this->send('Bella just ate a handful of raisins, what should I feed her tonight?');

        // The check saw the message (with the dog's name); the coach was never asked.
        $this->assertStringContainsString('handful of raisins', json_encode(FakeClaude::$triage[0]['messages'], JSON_UNESCAPED_UNICODE));
        $this->assertCount(0, FakeClaude::$requests);
        $this->assertStringContainsString('"urgent":true', $body);
        $this->assertStringContainsString('"remaining":2', $body);

        $saved = $this->messages();
        $this->assertSame('red_flag:toxin', $saved[1]->getFlag());
        $this->assertStringContainsString('call your vet', $saved[1]->getContent());
        $this->assertStringContainsString('Don’t make Bella vomit', $saved[1]->getContent());
        $this->client->request('GET', '/chat');
        $this->assertSelectorExists('.chat-msg--bot.is-urgent');
        $this->assertSelectorExists('a.chat-human[href^="mailto:support@myzenoo.com"]');

        // An ordinary message goes on to the coach.
        FakeClaude::$redFlag = 'none';
        $this->send('Thanks, she is fine now. How much should she eat?');
        $this->assertCount(1, FakeClaude::$requests);
    }

    public function testTheCoachGetsNumbersFromTheCalculators(): void
    {
        $this->userWithDog();
        FakeClaude::$script = [
            ['text' => '', 'tool' => ['name' => 'calculate', 'input' => ['operation' => 'portion', 'kcal_per_100g' => 160, 'share' => 0.25]]],
            ['text' => 'As a topper, give Bella about 170 g a day.'],
        ];
        $this->send('How much raw food should I add as a topper?');
        $this->assertContains('calculate', array_column(FakeClaude::$requests[0]['tools'], 'name'));
        $result = FakeClaude::$requests[1]['messages'][array_key_last(FakeClaude::$requests[1]['messages'])]['content'][0];
        $this->assertSame('tool_result', $result['type']);
        $this->assertSame(185, json_decode($result['content'], true)['grams_per_day']); // not neutered here
        $this->assertSame('As a topper, give Bella about 170 g a day.', $this->messages()[1]->getContent());
    }

    public function testTheOwnerCanAddAndRemoveFacts(): void
    {
        $this->userWithDog();
        $crawler = $this->client->request('GET', '/dashboard');
        $form = $crawler->filter('.dash-knows__add')->form(['text' => 'Allergic to chicken']);
        $this->client->submit($form);
        $crawler = $this->client->followRedirect();
        $this->assertSelectorTextContains('.dash-knows', 'Allergic to chicken');
        $this->assertSelectorTextContains('.dash-knows', '(you added this)');

        // The coach sees who added it.
        $this->send('What treats are OK?');
        $this->assertStringContainsString('added by the pet parent] Allergic to chicken', FakeClaude::$requests[0]['system'][1]['text']);

        $crawler = $this->client->request('GET', '/dashboard');
        $this->client->submit($crawler->filter('.dash-knows__remove')->first()->selectButton('×')->form());
        $this->client->followRedirect();
        $this->assertSelectorTextNotContains('.dash-knows', 'Allergic to chicken');
    }

    public function testLessonLinksOpenTheLessonInTheReadersLanguage(): void
    {
        (new \Symfony\Component\Console\Tester\CommandTester((new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel))->find('app:course:import')))->execute(['--publish' => true]);
        $this->userWithDog();
        $this->client->request('GET', '/courses/lesson/2');
        $this->assertResponseRedirects();
        $this->assertMatchesRegularExpression('~/courses/[a-z0-9-]+/lesson/\d+$~', $this->client->getResponse()->headers->get('Location'));
        $this->client->request('GET', '/courses/lesson/999');
        $this->assertResponseRedirects('/courses');

        FakeClaude::$script = [['text' => 'See [Lesson 2, Reading Labels](/courses/lesson/2) or [this](https://evil.example).']];
        $this->send('How do I read a label?');
        $this->client->request('GET', '/chat');
        $this->assertSelectorExists('.chat-answer a[href="/courses/lesson/2"]');
        $this->assertSelectorNotExists('.chat-answer a[href^="https://evil"]');
    }

    public function testAdminsSeeAlertsAndCanMarkRepliesForReview(): void
    {
        $this->userWithDog();
        FakeClaude::$redFlag = 'bloat';
        $this->send('Her belly is swollen and she keeps trying to vomit.');
        FakeClaude::$redFlag = 'none';
        $this->send('She is fine now, the vet saw her. What should she eat?');

        $this->client->loginUser($this->createUser('admin@example.com', 'Password123', ['ROLE_ADMIN']));
        $crawler = $this->client->request('GET', '/admin/conversations');
        $this->assertStringContainsString('⚠ 1', $crawler->filter('.cv-table tbody tr')->text());

        $crawler = $this->client->request('GET', '/admin/conversations/sample');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.cv-alert', 'bloat');
        $this->assertCount(2, $crawler->filter('form.cv-review'));

        // A note on the coach's reply to the second question.
        $form = $crawler->filter('form.cv-review')->last()->form(['note' => 'OK, good portion advice']);
        $this->client->submit($form);
        $this->assertResponseRedirects('/admin/conversations/sample');
        $this->assertSame('OK, good portion advice', $this->messages()[3]->getReview());
        $crawler = $this->client->request('GET', '/admin/conversations');
        $this->assertStringContainsString('1', $crawler->filter('.cv-table tbody tr td')->eq(5)->text());
    }

    public function testTheVetMessagesExistInEveryLanguage(): void
    {
        foreach (['en', 'lt', 'uk', 'pl'] as $l) {
            foreach (array_keys(\App\Chat\SafetyCheck::FLAGS) as $flag) {
                $text = \App\Chat\SafetyCheck::reply($flag, $l, 'Bella');
                $this->assertStringContainsString('support@myzenoo.com', $text, "$l $flag");
                $this->assertStringNotContainsString('%dog%', $text, "$l $flag");
                $this->assertGreaterThan(3, substr_count($text, "\n") + 1, "$l $flag has head, reason and tail");
            }
        }
    }

    public function testLastFullSentence(): void
    {
        $this->assertSame('One. Two!', ChatAgent::lastFullSentence("One. Two!\n\nNext step: send a photo of"));
        $this->assertSame('No sentence end', ChatAgent::lastFullSentence('No sentence end'));
    }
}
