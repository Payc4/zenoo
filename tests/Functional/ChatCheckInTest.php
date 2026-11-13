<?php

namespace App\Tests\Functional;

use App\Entity\ChatMessage;
use App\Entity\DogProfile;
use App\Entity\User;
use App\Tests\Support\FakeClaude;

/** Chat check-ins: cards every few days, answers saved to the dog profile without any AI call. */
final class ChatCheckInTest extends AppTestCase
{
    private function userWithDog(string $locale = 'en'): User
    {
        $user = $this->createUser();
        $user->setLocale($locale);
        $dog = (new DogProfile($user))->fillFromQuiz(QuizAccountTest::result(), $locale);
        // Check-ins start once the coach knows the food (until then the chat asks for the label).
        $dog->setFood('Orijen', 'Original', 'dry', '', 'chat', new \DateTimeImmutable());
        $this->em->persist($dog);
        $this->em->flush();
        $this->client->loginUser($user);
        return $user;
    }

    private function dog(): DogProfile
    {
        $this->em->clear();
        return $this->em->getRepository(DogProfile::class)->findOneBy([]);
    }

    /** @return array{0: ?array, 1: string} the check-in cards on /chat and the CSRF token */
    private function openChat(): array
    {
        $crawler = $this->client->request('GET', '/chat');
        $this->assertResponseIsSuccessful();
        $node = $crawler->filter('#chat-checkin');
        return [$node->count() ? json_decode($node->text(), true) : null, $crawler->filter('#chat')->attr('data-csrf')];
    }

    private function save(string $csrf, array $answers): array
    {
        $this->client->request('POST', '/chat/checkin', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], json_encode(['answers' => $answers]));
        return json_decode($this->client->getResponse()->getContent(), true);
    }

    public function testCheckInIsShownSavedAndCostsNoAiCall(): void
    {
        $this->userWithDog();
        [$cards, $csrf] = $this->openChat();
        $this->assertSame(['goal', 'stools'], array_column($cards, 'id'), 'the two most important questions');
        $this->assertSame('How have Bella’s stools been this week?', $cards[1]['question']);
        $this->assertSame('soft', $cards[1]['options'][1]['value']);
        $this->assertNotNull($this->dog()->getCheckinAskedAt());

        [$again] = $this->openChat();
        $this->assertNull($again, 'not again within 3 days');

        $res = $this->save($csrf, [
            ['id' => 'goal', 'value' => 'digestion'],
            ['id' => 'stools', 'value' => 'soft', 'text' => ''],
            ['id' => 'stools', 'value' => 'made-up'],
            ['id' => 'nope', 'value' => 'x'],
            ['id' => 'energy', 'value' => '', 'text' => '<b>Sleeps</b> a lot   lately'],
        ]);
        $this->assertSame(3, $res['saved']);
        $this->assertSame('Thanks! Saved to Bella’s profile, so the coach knows.', $res['message']);

        $checkins = $this->dog()->getCheckins();
        $this->assertSame('soft', $checkins['stools'][0]['value']);
        $this->assertSame('Sleeps a lot lately', $checkins['energy'][0]['text']);
        $this->assertSame(0, $this->em->getRepository(ChatMessage::class)->count([]), 'no chat message');
        $this->assertSame([], FakeClaude::$requests, 'no AI call');
    }

    public function testNextCheckInAsksWhatIsDueAndTheCoachSeesTheAnswers(): void
    {
        $this->userWithDog();
        [, $csrf] = $this->openChat();
        $this->save($csrf, [['id' => 'goal', 'value' => 'weight'], ['id' => 'stools', 'value' => 'firm']]);
        $this->save($csrf, [['id' => 'stools', 'value' => 'loose']]);

        $dog = $this->dog();
        $dog->markCheckinAsked(new \DateTimeImmutable('-4 days'));
        $this->em->flush();
        [$cards] = $this->openChat();
        $this->assertSame(['appetite', 'energy'], array_column($cards, 'id'), 'answered questions wait for their repeat_days');

        FakeClaude::$reply = 'OK.';
        $this->client->request('POST', '/chat/send', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], json_encode(['message' => 'Hi']));
        $personal = FakeClaude::$requests[0]['system'][1]['text'];
        $this->assertStringContainsString('What they told the chat check-ins', $personal);
        $this->assertStringContainsString('- Main thing the pet parent wants help with: A healthy weight', $personal);
        $this->assertMatchesRegularExpression('/- Stools this week: Loose or runny \(\d+ \w+ \d{4}\); before: Firm and easy to pick up/', $personal);
    }

    public function testLithuanianWordingAndNoCheckInWithoutADogOrWhenClaudeAsked(): void
    {
        $this->userWithDog('lt');
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('zenoo_lang', 'lt'));
        [$cards, $csrf] = $this->openChat();
        $this->assertSame('Kokios šią savaitę buvo jūsų šuns (Bella) išmatos?', $cards[1]['question']);
        $this->assertSame('Šiek tiek minkštos', $cards[1]['options'][1]['label']);
        $res = $this->save($csrf, [['id' => 'stools', 'value' => 'soft']]);
        $this->assertStringContainsString('Ačiū!', $res['message']);

        // Claude's own question cards win over a check-in.
        $dog = $this->dog();
        $dog->markCheckinAsked(new \DateTimeImmutable('-10 days'));
        $this->em->flush();
        FakeClaude::$askQuestions = ['questions' => [['question' => 'How old?', 'options' => [['label' => 'Puppy']]]]];
        $this->client->request('POST', '/chat/send', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], json_encode(['message' => 'Hi']));
        $crawler = $this->client->request('GET', '/chat');
        $this->assertSame(1, $crawler->filter('#chat-cards')->count());
        $this->assertSame(0, $crawler->filter('#chat-checkin')->count());
    }

    public function testNoDogNoCheckIn(): void
    {
        $this->client->loginUser($this->createUser());
        [$cards, $csrf] = $this->openChat();
        $this->assertNull($cards);
        $this->client->request('POST', '/chat/checkin', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], json_encode(['answers' => []]));
        $this->assertResponseStatusCodeSame(400);
    }

    public function testAdminCanReadConversationsWithTheDogAndCheckIns(): void
    {
        $this->userWithDog();
        [, $csrf] = $this->openChat();
        $this->save($csrf, [['id' => 'stools', 'value' => 'soft']]);
        FakeClaude::$reply = 'Try adding pumpkin.';
        $this->client->request('POST', '/chat/send', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], json_encode(['message' => 'Soft stools, help']));

        $this->client->request('GET', '/admin/conversations');
        $this->assertResponseStatusCodeSame(403, 'pet parents can\'t see it');

        $this->client->loginUser($this->createUser('admin@example.com', 'Password123', ['ROLE_ADMIN']));
        $crawler = $this->client->request('GET', '/admin/conversations');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.cv-table', 'maria@example.com');
        $this->assertSelectorTextContains('.cv-table', 'Bella (52/100)');

        $crawler = $this->client->click($crawler->selectLink('Read →')->link());
        $this->assertSelectorTextContains('.cv-thread', 'Soft stools, help');
        $this->assertSelectorTextContains('.cv-thread', 'Try adding pumpkin.');
        $this->assertSelectorTextContains('.cv-side', 'Stools this week: A bit soft');

        $this->client->click($crawler->selectLink('Download as text')->link());
        $this->assertStringContainsString("PET PARENT:\nSoft stools, help", $this->client->getResponse()->getContent());
        $this->assertResponseHeaderSame('Content-Type', 'text/plain; charset=utf-8');
    }
}
