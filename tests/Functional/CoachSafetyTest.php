<?php

namespace App\Tests\Functional;

use App\Entity\ChatMessage;
use App\Entity\DogProfile;
use App\Entity\User;
use App\Tests\Support\FakeClaude;
use Symfony\Component\Yaml\Yaml;

/** Test report step 2: the right emergency handling (F5) and health facts kept and respected (F6). */
final class CoachSafetyTest extends AppTestCase
{
    private function userWithDog(array $notes = []): DogProfile
    {
        $user = $this->createUser();
        $dog = (new DogProfile($user))->fillFromQuiz(QuizAccountTest::result(['quiz_dog_name' => 'Max']), 'en');
        $dog->setFood('Orijen', 'Original', 'dry', '', 'chat', new \DateTimeImmutable());
        foreach ($notes as [$topic, $text]) {
            $dog->addNote($topic, $text, new \DateTimeImmutable());
        }
        $this->em->persist($dog);
        $this->em->flush();
        $this->client->loginUser($user);
        return $dog;
    }

    private function send(string $text): string
    {
        $csrf = $this->client->request('GET', '/chat')->filter('#chat')->attr('data-csrf');
        $this->client->request('POST', '/chat/send', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], json_encode(['message' => $text]));
        return (string) $this->client->getInternalResponse()->getContent();
    }

    private function dog(): DogProfile
    {
        $this->em->clear();
        return $this->em->getRepository(DogProfile::class)->findOneBy([]);
    }

    public function testCanIGiveXGoesToTheCoachNotTheEmergencyScript(): void
    {
        $this->userWithDog();
        // Even if the small model also names a flag, a question about safety isn't an emergency.
        FakeClaude::$redFlag = 'toxin';
        FakeClaude::$situation = 'safety_question';
        $body = $this->send('Can I give him ibuprofen?');
        $this->assertStringNotContainsString('"urgent":true', $body);
        $this->assertCount(1, FakeClaude::$requests, 'the coach answers');
        $this->assertStringContainsString('"No, X is toxic to dogs"', FakeClaude::$requests[0]['system'][0]['text'], 'plain answer rule');

        // The check is told the difference, with examples in all four languages.
        $system = FakeClaude::$triage[0]['system'];
        foreach (['safety_question', 'happening_now', 'history', 'ar galima duoti vynuogių', 'чи можна давати виноград', 'czy mogę dać psu winogrona'] as $needle) {
            $this->assertStringContainsString($needle, $system);
        }

        // Past pancreatitis is history, not an emergency.
        FakeClaude::$situation = 'history';
        FakeClaude::$redFlag = 'vomiting';
        $this->assertStringNotContainsString('"urgent":true', $this->send('He had pancreatitis two years ago'));

        // Something that happened now still gets the vet message.
        $this->em->createQuery('DELETE FROM App\Entity\ChatUsage u')->execute(); // the test limit is 2 a day
        FakeClaude::$situation = 'happening_now';
        FakeClaude::$redFlag = 'toxin';
        $this->assertStringContainsString('"urgent":true', $this->send('He just ate grapes'));
    }

    public function testHealthFactsFromTheChatAreSavedAndConfirmed(): void
    {
        $this->userWithDog();
        FakeClaude::$healthFacts = [['kind' => 'condition', 'text' => 'Pancreatitis two years ago'], ['kind' => 'nonsense', 'text' => 'x']];
        $body = $this->send('He had pancreatitis two years ago, what treats can he have?');

        $this->assertStringContainsString('Saved to Max’s notes: Pancreatitis two years ago', $body);
        $notes = $this->dog()->getNotes();
        $this->assertSame([['condition', 'Pancreatitis two years ago']], array_map(fn ($n) => [$n['topic'], $n['text']], $notes));
        // Saved before the coach answered: it's already a rule in this reply's profile.
        $this->assertStringContainsString("RULES FOR THIS DOG", FakeClaude::$requests[0]['system'][1]['text']);
        $this->assertStringContainsString('Pancreatitis two years ago', FakeClaude::$requests[0]['system'][1]['text']);

        // Clearing the chat keeps the notes, so the rule still applies.
        $crawler = $this->client->request('GET', '/chat');
        $this->client->submit($crawler->filter('form[action^="/chat/clear"]')->form());
        FakeClaude::$healthFacts = [];
        $this->send('Can he have pork treats?');
        $this->assertStringContainsString('no high-fat food or fatty treats', end(FakeClaude::$requests)['system'][1]['text']);
    }

    public function testAReplyAgainstAnAllergyIsCorrectedBeforeItIsKept(): void
    {
        $this->userWithDog([['allergy', 'Allergic to chicken']]);
        FakeClaude::$reply = 'Try **chicken jerky** as a treat.';
        FakeClaude::$review = ['ok' => false, 'problem' => 'Max is allergic to chicken', 'corrected' => 'Try **dried fish skins** as a treat (no chicken: Max is allergic).'];
        $body = $this->send('Which treats can I give?');

        $this->assertStringContainsString('Allergic to chicken', FakeClaude::$reviews[0]['system']);
        $this->assertStringContainsString('chicken jerky', json_encode(FakeClaude::$reviews[0]['messages']));
        $this->assertStringContainsString('event: replace', $body);
        $this->assertStringContainsString('Corrected to respect Max’s notes: Max is allergic to chicken', $body);
        $this->em->clear();
        $saved = $this->em->getRepository(ChatMessage::class)->findBy([], ['id' => 'ASC']);
        $this->assertSame('Try **dried fish skins** as a treat (no chicken: Max is allergic).', $saved[1]->getContent());
        $this->assertSame('corrected', $saved[1]->getFlag());
    }

    public function testNoReplyCheckWithoutRules(): void
    {
        $this->userWithDog([['food', 'Loves carrots']]);
        $this->send('Hi');
        $this->assertCount(0, FakeClaude::$reviews);
    }

    public function testTheSafetyTestSetCoversFourLanguages(): void
    {
        $cases = Yaml::parseFile(static::getContainer()->getParameter('kernel.project_dir') . '/config/chatbot/safety_eval.yaml')['cases'];
        $this->assertGreaterThanOrEqual(40, \count($cases));
        $this->assertSame(['en', 'lt', 'uk', 'pl'], array_values(array_unique(array_column($cases, 'lang'))));
        $flags = array_merge(['none'], array_keys(\App\Chat\SafetyCheck::FLAGS));
        foreach ($cases as $c) {
            $this->assertContains($c['flag'], $flags, $c['message']);
        }
        // Questions and emergencies in every language.
        foreach (['en', 'lt', 'uk', 'pl'] as $l) {
            $mine = array_filter($cases, fn ($c) => $c['lang'] === $l);
            $this->assertNotEmpty(array_filter($mine, fn ($c) => $c['flag'] === 'none'));
            $this->assertNotEmpty(array_filter($mine, fn ($c) => $c['flag'] !== 'none'));
        }

        // The command runs it through the real check (here: the fake, which answers "none").
        $tester = new \Symfony\Component\Console\Tester\CommandTester((new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel))->find('app:coach:safety-eval'));
        $tester->execute([]);
        $this->assertStringContainsString('cases failed', $tester->getDisplay());
        $this->assertCount(\count($cases), FakeClaude::$triage);
    }
}
