<?php

namespace App\Tests\Functional;

use App\Entity\ChatConversation;
use App\Entity\ChatMessage;
use App\Entity\DogProfile;
use App\Entity\User;
use App\Tests\Support\FakeClaude;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** Roadmap 06: the coach plans check-ins, emails them when due and asks them in the chat. */
final class CoachFollowUpTest extends AppTestCase
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

    private function dog(): DogProfile
    {
        $this->em->clear();
        return $this->em->getRepository(DogProfile::class)->findOneBy([]);
    }

    private function send(string $text): string
    {
        $csrf = $this->client->request('GET', '/chat')->filter('#chat')->attr('data-csrf');
        $this->client->request('POST', '/chat/send', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], json_encode(['message' => $text]));
        return (string) $this->client->getInternalResponse()->getContent();
    }

    private function runCommand(): string
    {
        static::getContainer()->get('doctrine')->getManager()->clear(); // as in a fresh cron run
        $tester = new CommandTester((new Application(static::$kernel))->find('app:coach:followups'));
        $tester->execute([]);
        return $tester->getDisplay();
    }

    public function testThePlannedCheckInIsEmailedAndAskedInTheChat(): void
    {
        $this->userWithDog();
        FakeClaude::$script = [
            ['text' => '', 'tool' => ['name' => 'schedule_followup', 'input' => ['in_days' => 14, 'question' => 'What does Bella weigh now?', 'topic' => 'weight']]],
            ['text' => 'Start with 270 g a day. I’ll check in in two weeks.'],
        ];
        $body = $this->send('I want Bella to lose weight.');
        $this->assertStringContainsString('The coach will check in on ' . (new \DateTimeImmutable('+14 days'))->format('j M Y'), $body);
        $planned = $this->dog()->getFollowUps();
        $this->assertCount(1, $planned);
        $this->assertSame('What does Bella weigh now?', $planned[0]['question']);

        // Not due yet: nothing is sent.
        $this->assertStringContainsString('Sent 0', $this->runCommand());

        // Two weeks later (and no chat since): the email goes out once.
        $dog = $this->dog();
        $data = (new \ReflectionProperty(DogProfile::class, 'data'));
        $values = $data->getValue($dog);
        $values['followups'][0]['due'] = (new \DateTimeImmutable('-1 day'))->format('Y-m-d');
        $data->setValue($dog, $values);
        $this->em->getConnection()->executeStatement('UPDATE chat_message SET created_at = ?', [(new \DateTimeImmutable('-14 days'))->format('Y-m-d H:i:s')]);
        $this->em->flush();
        $this->assertStringContainsString('Sent 1', $this->runCommand());
        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertEmailSubjectContains($email, 'A quick question about Bella');
        $this->assertEmailHtmlBodyContains($email, 'What does Bella weigh now?');
        $this->assertEmailHtmlBodyContains($email, '/coach-emails/off?');
        $this->assertStringContainsString('Sent 0', $this->runCommand(), 'only once');

        // Opening the chat: the coach asks it, and it's done.
        FakeClaude::$script = [['text' => 'Hi Maria! Two weeks in: what does Bella weigh now?']];
        $crawler = $this->client->request('GET', '/chat');
        $this->client->request('POST', $crawler->filter('#chat')->attr('data-opener-url'), [], [], ['HTTP_X_CSRF_TOKEN' => $crawler->filter('#chat')->attr('data-csrf')]);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('What does Bella weigh now?', json_encode(end(FakeClaude::$requests)['messages']));
        $this->assertTrue($this->dog()->getFollowUps()[0]['done']);
    }

    public function testTheEmailsCanBeSwitchedOff(): void
    {
        $user = $this->userWithDog();
        $dog = $this->dog();
        $dog->addFollowUp('How are Bella’s stools?', 2, 'stools', new \DateTimeImmutable('-3 days'));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/account');
        $this->client->submit($crawler->filter('#coach-emails form')->form());
        $this->client->followRedirect();
        $this->assertSelectorExists('#coach-emails button[role="switch"][aria-checked="false"]');
        $this->assertStringContainsString('Sent 0', $this->runCommand());

        // The one-click link in the email needs a valid signature.
        $this->em->getRepository(User::class)->find($user->getId())->setCoachEmails(true);
        $this->em->flush();
        $this->client->request('GET', '/coach-emails/off?u=' . $user->getId() . '&_hash=forged');
        $this->assertResponseRedirects('/login');
        $signed = static::getContainer()->get(\Symfony\Component\HttpFoundation\UriSigner::class)->sign('http://localhost/coach-emails/off?u=' . $user->getId());
        $this->client->request('GET', $signed);
        $this->em->clear();
        $this->assertFalse($this->em->getRepository(User::class)->find($user->getId())->wantsCoachEmails());
    }
}
