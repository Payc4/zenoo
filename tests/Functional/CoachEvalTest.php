<?php

namespace App\Tests\Functional;

use App\Entity\DogProfile;
use App\Entity\User;
use App\Tests\Support\FakeClaude;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** The coach's fixed test set (app:coach:eval) runs, judges replies and cleans up after itself. */
final class CoachEvalTest extends AppTestCase
{
    private function evaluate(array $options): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:coach:eval'));
        $tester->execute($options);
        return $tester;
    }

    public function testPassingAndFailingCases(): void
    {
        FakeClaude::$reply = 'No: grapes are toxic to dogs, even a few.';
        $tester = $this->evaluate(['--only' => 'Grapes question']);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('pass', $tester->getDisplay());

        // An emergency the safety check misses is a failure.
        $tester = $this->evaluate(['--only' => 'Grapes eaten']);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('safety check missed it', $tester->getDisplay());

        FakeClaude::$redFlag = 'toxin';
        $this->assertSame(0, $this->evaluate(['--only' => 'Grapes eaten'])->getStatusCode());

        // The temporary account and dog are gone.
        $this->em->clear();
        $this->assertCount(0, $this->em->getRepository(DogProfile::class)->findAll());
        $this->assertCount(0, array_filter($this->em->getRepository(User::class)->findAll(), fn (User $u) => str_starts_with($u->getEmail(), 'coach-eval-')));
    }

    public function testEveryCaseIsWellFormed(): void
    {
        $cases = \Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__, 2) . '/config/chatbot/eval.yaml')['cases'];
        $this->assertGreaterThanOrEqual(15, \count($cases));
        foreach (['en', 'lt', 'uk', 'pl'] as $l) {
            $this->assertNotEmpty(array_filter($cases, fn ($c) => $c['locale'] === $l), "a case in $l");
        }
        foreach ($cases as $c) {
            foreach ((array) ($c['flag'] ?? []) as $f) {
                $this->assertArrayHasKey($f, \App\Chat\SafetyCheck::FLAGS, $c['name']);
            }
        }
    }
}
