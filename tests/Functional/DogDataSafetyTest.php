<?php

namespace App\Tests\Functional;

use App\Chat\Calculators;
use App\Entity\DogProfile;
use App\Entity\QuizSubmission;
use App\Entity\User;

/** Test report step 1: no overwritten dogs (F1), every pending result saved (M12), nothing left after sign-out (F7). */
final class DogDataSafetyTest extends AppTestCase
{
    private function pending(string $email, array $result, string $ago = 'now'): void
    {
        $s = new QuizSubmission($email, 'Maria', 'en', $result, bin2hex(random_bytes(32)), 'ip');
        $this->em->persist($s);
        $this->em->flush();
        if ($ago !== 'now') {
            $this->em->getConnection()->executeStatement('UPDATE quiz_submission SET created_at = ? WHERE id = ?', [(new \DateTimeImmutable($ago))->format('Y-m-d H:i:s'), $s->getId()]);
        }
    }

    public function testAnotherDogWithTheSameNameIsNotOverwrittenFromAnEmailLink(): void
    {
        // Max the Chihuahua puppy is on the account; a quiz for "max" the Beagle comes in by email.
        $user = $this->createUser();
        $this->createDog($user, ['quiz_dog_name' => 'Max', 'quiz_breed' => 'Chihuahua', 'quiz_bowl_score' => 67]);
        $this->pending('maria@example.com', QuizAccountTest::result(['quiz_dog_name' => 'max', 'quiz_breed' => 'Beagle', 'quiz_bowl_score' => 82]));
        $this->loginViaForm('maria@example.com', 'Password123');
        $this->em->clear();
        $dogs = $this->em->getRepository(DogProfile::class)->findBy([], ['id' => 'ASC']);
        $this->assertSame([['Max', 'Chihuahua', 67], ['max', 'Beagle', 82]], array_map(fn (DogProfile $d) => [$d->getName(), $d->get('breed'), $d->getBowlScore()], $dogs));
        $this->assertSame([67], array_column($dogs[0]->getScoreHistory(), 'score'), 'Max’s history is untouched');
    }

    public function testSigningInSavesEveryPendingResult(): void
    {
        $this->createUser();
        $this->pending('maria@example.com', QuizAccountTest::result(['quiz_dog_name' => 'Bella']), '-2 days');
        $this->pending('maria@example.com', QuizAccountTest::result(['quiz_dog_name' => 'Rex', 'quiz_breed' => 'Beagle']), '-1 day');
        $this->pending('other@example.com', QuizAccountTest::result(['quiz_dog_name' => 'Nope']));
        $this->pending('maria@example.com', QuizAccountTest::result(['quiz_dog_name' => 'Old']), '-30 days');

        $this->loginViaForm('maria@example.com', 'Password123');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'Bella’s profile is saved to your account.');
        $this->assertSelectorTextContains('body', 'Rex’s profile is saved to your account.');
        $this->em->clear();
        $this->assertSame(['Bella', 'Rex'], array_map(fn (DogProfile $d) => $d->getName(), $this->em->getRepository(DogProfile::class)->findBy([], ['id' => 'ASC'])));
        $this->assertSame(1, $this->em->getRepository(QuizSubmission::class)->count(['email' => 'maria@example.com', 'confirmedAt' => null]), 'only the expired one is left');

        // Signing in again doesn't add them twice.
        $this->client->request('GET', '/logout');
        $this->loginViaForm('maria@example.com', 'Password123');
        $this->em->clear();
        $this->assertSame(2, $this->em->getRepository(DogProfile::class)->count([]));
    }

    public function testSigningOutClearsTheBrowsersStorage(): void
    {
        $this->createUser();
        $this->loginViaForm('maria@example.com', 'Password123');
        $this->client->request('GET', '/logout');
        $this->assertSame('"storage"', $this->client->getResponse()->headers->get('Clear-Site-Data'));
    }

    public function testThePlanNeverWorksAgainstBodyShape(): void
    {
        $user = $this->createUser();
        $facts = static fn (array $q) => Calculators::dogFacts((new DogProfile($user))->fillFromQuiz(QuizAccountTest::result($q), 'en'));

        // Mūza: overweight, eating about 430 kcal; the formula alone said 520.
        $muza = Calculators::planKcal($facts(['quiz_weight_kg' => 20, 'quiz_bcs' => 7, 'quiz_intake_kcal' => 430, 'quiz_activity_band' => 5, 'quiz_ideal_weight_kg' => null, 'quiz_life_stage' => 'adult']));
        $this->assertGreaterThan(430, $muza['calculated']);
        $this->assertSame(['kcal' => 430.0, 'keep' => 'heavy'], ['kcal' => $muza['kcal'], 'keep' => $muza['keep']]);

        // A thin dog eating more than the formula: never less.
        $thin = Calculators::planKcal($facts(['quiz_weight_kg' => 20, 'quiz_bcs' => 3, 'quiz_intake_kcal' => 2000, 'quiz_life_stage' => 'adult']));
        $this->assertSame([2000.0, 'thin'], [$thin['kcal'], $thin['keep']]);

        // Ideal shape but far from the formula: no new amount, check with the vet.
        $gap = Calculators::planKcal($facts(['quiz_weight_kg' => 15, 'quiz_bcs' => 5, 'quiz_intake_kcal' => 400, 'quiz_life_stage' => 'adult']));
        $this->assertSame('vet', $gap['keep']);
        $this->assertStringContainsString('check them with the vet', Calculators::run(['operation' => 'daily_energy'], (new DogProfile($user))->fillFromQuiz(QuizAccountTest::result(['quiz_weight_kg' => 15, 'quiz_bcs' => 5, 'quiz_intake_kcal' => 400, 'quiz_life_stage' => 'adult']), 'en'))['note']);
    }

    public function testPuppiesGetGrowthNotATargetWeight(): void
    {
        $user = $this->createUser();
        $puppy = (new DogProfile($user))->fillFromQuiz(QuizAccountTest::result([
            'quiz_dog_name' => 'Max', 'quiz_breed' => 'Chihuahua', 'quiz_weight_kg' => 0.6, 'quiz_age_years' => 0.2, 'quiz_life_stage' => 'puppy',
            'quiz_adult_weight_kg' => 2.5, 'quiz_ideal_weight_kg' => null, 'quiz_intake_kcal' => null, 'quiz_bcs' => 5,
        ]), 'en');
        $energy = Calculators::run(['operation' => 'daily_energy'], $puppy);
        $this->assertNull($energy['ideal_weight_kg']);
        $this->assertGreaterThan(150, $energy['kcal_per_day'], 'growth energy from the expected adult weight');
        $plan = Calculators::run(['operation' => 'weight_plan'], $puppy);
        $this->assertStringContainsString('no target weight', $plan['note']);
        $this->assertSame(2.5, $plan['expected_adult_kg']);
    }
}
