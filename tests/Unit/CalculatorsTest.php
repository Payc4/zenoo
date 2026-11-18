<?php

namespace App\Tests\Unit;

use App\Chat\Calculators;
use App\Entity\DogProfile;
use App\Entity\User;
use App\Tests\Functional\QuizAccountTest;
use PHPUnit\Framework\TestCase;

final class CalculatorsTest extends TestCase
{
    private function bella(): DogProfile
    {
        $user = (new User())->setEmail('maria@example.com');
        return (new DogProfile($user))->fillFromQuiz(QuizAccountTest::result([
            'quiz_activity_band' => 3, 'quiz_neutered' => 'yes', 'quiz_meals_per_day' => '2', 'quiz_treats' => 'few',
        ]), 'en');
    }

    public function testDailyEnergyMatchesTheQuiz(): void
    {
        // scoring.js gives 1134.41 kcal and 25.83 kg for 31 kg, body score 7, activity 3, neutered adult.
        $r = Calculators::run(['operation' => 'daily_energy'], $this->bella());
        $this->assertSame(1130, $r['kcal_per_day']);
        $this->assertSame(25.8, $r['ideal_weight_kg']);
        $this->assertStringContainsString('slow weight loss', $r['based_on']);
    }

    public function testPortionUsesTheNewerWeightAndTheLabel(): void
    {
        $dog = $this->bella();
        $typical = Calculators::run(['operation' => 'portion'], $dog);
        $this->assertStringContainsString('typical dry food', $typical['energy_value_used']);
        // 1134 kcal less 5% treats = 1078 kcal; 370 kcal/100 g → 291 g → rounded to 290.
        $this->assertSame(290, $typical['grams_per_day']);
        $this->assertSame(145, $typical['grams_per_meal']);

        $topper = Calculators::run(['operation' => 'portion', 'kcal_per_100g' => 160, 'share' => 0.25], $dog);
        $this->assertSame(170, $topper['grams_per_day']);

        $dog->setWeightUpdate(28, new \DateTimeImmutable());
        $this->assertStringStartsWith('28 kg', Calculators::run(['operation' => 'daily_energy'], $dog)['based_on']);
    }

    public function testPlansSchedulesCostsAndLabels(): void
    {
        $plan = Calculators::run(['operation' => 'weight_plan'], $this->bella());
        $this->assertSame(25.8, $plan['target_kg']);
        $this->assertSame([9, 17], $plan['weeks']); // 5.2 kg at 0.62–0.31 kg a week

        $switch = Calculators::run(['operation' => 'switch_schedule', 'days' => 10], null);
        $this->assertSame(['days 1–3', 'days 4–5', 'days 6–8', 'days 9–10'], array_column($switch['schedule'], 'days'));
        $this->assertSame(100, $switch['schedule'][3]['new_food_percent']);

        $cost = Calculators::run(['operation' => 'cost_per_day', 'grams_per_day' => 600, 'pack_grams' => 300, 'pack_price' => 28.75], null);
        $this->assertSame(57.5, $cost['cost_per_day_eur']);
        $this->assertSame(0.5, $cost['days_per_pack']);

        $label = Calculators::run(['operation' => 'label_energy', 'label' => ['protein' => 26, 'fat' => 15, 'fibre' => 3, 'ash' => 7, 'moisture' => 9]], null);
        $this->assertSame(359.0, $label['kcal_per_100g']); // 3.5×26 + 8.5×15 + 3.5×40 carbs = 358.5
        $this->assertArrayHasKey('error', Calculators::run(['operation' => 'label_energy', 'label' => ['protein' => 80, 'fat' => 30]], null));
        $this->assertArrayHasKey('error', Calculators::run(['operation' => 'daily_energy'], null));
    }
}
