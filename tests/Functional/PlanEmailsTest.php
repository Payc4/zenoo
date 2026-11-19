<?php

namespace App\Tests\Functional;

use App\Entity\DogProfile;
use App\Entity\QuizSubmission;
use App\Entity\User;
use App\Tests\Support\FakeShop;
use App\Tests\Support\FakeStripe;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** Roadmap 04: the plan email and the opt-in series, in the pet parent's language. */
final class PlanEmailsTest extends AppTestCase
{
    private function confirmLead(string $locale, bool $optIn): User
    {
        $raw = bin2hex(random_bytes(32));
        $data = QuizAccountTest::result(['quiz_fix_1_text' => 'Pasverkite maistą savaitę.', 'quiz_plan_food' => 'Sausas maistas: apie 290 g per dieną', 'quiz_marketing_consent' => $optIn]);
        $sub = new QuizSubmission('ona@example.com', 'Ona', $locale, $data, $raw, 'x');
        (new \ReflectionProperty(QuizSubmission::class, 'tokenHash'))->setValue($sub, hash('sha256', $raw));
        $this->em->persist($sub);
        $this->em->flush();
        $this->client->request('GET', '/quiz/confirm/' . $raw);
        $this->em->clear();
        return $this->em->getRepository(User::class)->findOneBy(['email' => 'ona@example.com']);
    }

    private function series(): string
    {
        static::getContainer()->get('doctrine')->getManager()->clear();
        $tester = new CommandTester((new Application(static::$kernel))->find('app:emails:series'));
        $tester->execute([]);
        return $tester->getDisplay();
    }

    private function age(int $days): void
    {
        $this->em->getConnection()->executeStatement('UPDATE dog_profile SET created_at = ?', [(new \DateTimeImmutable("-$days days -1 hour"))->format('Y-m-d H:i:s')]);
    }

    public function testALithuanianLeadGetsThePlanAndTheSeries(): void
    {
        FakeStripe::$shopProducts = FakeShop::products();
        (new CommandTester((new Application(static::$kernel))->find('app:catalogue:sync')))->execute([]);

        $user = $this->confirmLead('lt', true);
        $this->assertTrue($user->wantsMarketingEmails());
        // The confirmation itself sends the plan email, in Lithuanian, with the ZENOO pick.
        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertEmailSubjectContains($email, 'Bella');
        $this->assertEmailHtmlBodyContains($email, 'Pasverkite maistą savaitę.');
        $this->assertEmailHtmlBodyContains($email, 'ZENOO RAW Jautiena šunims');
        $this->assertEmailHtmlBodyContains($email, '/dashboard#zenoo-h');

        $this->assertStringContainsString('Sent 0', $this->series(), 'nothing due on day 0');
        $this->age(3);
        $this->assertStringContainsString('Sent 1', $this->series());
        $this->assertEmailHtmlBodyContains($this->getMailerMessage(0), '/courses/lesson/1');
        $this->assertStringContainsString('Sent 0', $this->series(), 'once per step');
        $this->age(14);
        $this->series();
        $messages = $this->getMailerMessages();
        $this->assertEmailHtmlBodyContains(end($messages), '/dashboard#zenoo-h');
        $this->assertEmailHtmlBodyContains(end($messages), '/emails/off?');
        $this->em->clear();
        $this->assertSame([0, 3, 14], array_keys($this->em->getRepository(DogProfile::class)->findOneBy([])->getEmailSeries()));
    }

    public function testNoSeriesWithoutTheTickAndEnglishIsLeftToKlaviyo(): void
    {
        $this->confirmLead('pl', false);
        $this->assertEmailCount(1, null, 'the plan email still goes: they asked for the plan');
        $this->age(3);
        $this->assertStringContainsString('Sent 0', $this->series());
    }

    public function testEnglishGetsNoSiteEmailsByDefault(): void
    {
        $this->confirmLead('en', true);
        $this->assertEmailCount(0);
        $this->age(3);
        $this->assertStringContainsString('Sent 0', $this->series());
    }

    public function testUnsubscribing(): void
    {
        $user = $this->confirmLead('uk', true);
        $signed = static::getContainer()->get(\Symfony\Component\HttpFoundation\UriSigner::class)->sign('http://localhost/emails/off?u=' . $user->getId());
        $this->client->request('GET', $signed);
        $this->em->clear();
        $this->assertFalse($this->em->getRepository(User::class)->find($user->getId())->wantsMarketingEmails());
        $this->age(3);
        $this->assertStringContainsString('Sent 0', $this->series());
    }
}
