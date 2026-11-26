<?php

namespace App\Tests\Functional;

use App\Entity\DogProfile;
use App\Quiz\QuizTexts;
use App\Quiz\ScoreInputs;
use App\Quiz\ScoreRecalculator;
use App\Quiz\Scoring;
use App\Tests\Support\FakeClaude;

/** The Bowl Score and plan are worked out again after a new weight or body shape, with a notice. */
final class RescoreTest extends AppTestCase
{
    /** A 31 kg Labrador, body shape 7 (heavy), on 380 g of dry food: what the quiz saved. */
    private const INPUTS = [
        'diet' => ['pDm' => 28, 'fDm' => 16, 'cDm' => 45, 'kcalPer100g' => null, 'fromLabel' => false],
        'foodMain' => 'dry', 'foodTypes' => ['dry'], 'bcs' => 7, 'breedEnergy' => 4, 'level' => 2, 'stage' => 'adult',
        'appetite' => 'eats', 'signs' => [], 'weightKg' => 31, 'neutered' => true, 'ageYears' => 4, 'adultKg' => null,
        'feeds' => [['type' => 'dry', 'grams' => 380]], 'meals' => '2', 'treats' => 'few',
    ];

    private function dog(array $inputs = self::INPUTS, string $locale = 'en'): DogProfile
    {
        $user = $this->createUser();
        $dog = $this->createDog($user, ['quiz_weight_kg' => $inputs['weightKg'], 'quiz_bcs' => $inputs['bcs']], $locale);
        $dog->setQuizAnswers(['name' => 'Bella', '_inputs' => $inputs]);
        // The quiz was two weeks ago: its weight and body shape are logged then.
        $data = new \ReflectionProperty(DogProfile::class, 'data');
        $d = $data->getValue($dog);
        foreach ($d['log'] as $i => $e) {
            $d['log'][$i]['d'] = (new \DateTimeImmutable('-14 days'))->format('Y-m-d');
        }
        $data->setValue($dog, $d);
        // Start from the score the quiz maths gives for these answers.
        $this->rescorer()->recalculate($dog, 'weight', new \DateTimeImmutable());
        $dog->dismissScoreChange();
        $this->em->flush();
        $this->client->loginUser($user);
        return $dog;
    }

    private function rescorer(): ScoreRecalculator
    {
        return static::getContainer()->get(ScoreRecalculator::class);
    }

    private function reload(): DogProfile
    {
        $this->em->clear();
        return $this->em->getRepository(DogProfile::class)->findOneBy([]);
    }

    /** The data of each server-sent event of this kind. */
    private function events(string $body, string $kind): array
    {
        preg_match_all('/^event: ' . $kind . '\ndata: (.*)$/m', $body, $m);
        return array_map(fn ($j) => json_decode($j, true), $m[1]);
    }

    private function log(string $type, string $value): void
    {
        $token = $this->client->request('GET', '/dashboard')->filter('form[action="/dashboard/tracker"] input[name=_token]')->attr('value');
        $this->client->request('POST', '/dashboard/tracker', ['_token' => $token, 'type' => $type, 'value' => $value]);
    }

    public function testANewWeightOnTodayReScoresAndShowsTheChangeUntilClosed(): void
    {
        $before = $this->dog()->getBowlScore();
        $this->assertSame(Scoring::evaluate(self::INPUTS)['total'], $before);

        // 28.4 kg against an ideal of about 25.8 kg: body shape about 6 now, so a better score.
        $this->log('weight', '28,4');
        $dog = $this->reload();
        $after = $dog->getBowlScore();
        $this->assertGreaterThan($before, $after);
        $this->assertSame(Scoring::evaluate(['weightKg' => 28.4, 'bcs' => 6] + self::INPUTS)['total'], $after);
        $this->assertSame($before, \array_slice($dog->getScoreHistory(), -2, 1)[0]['score'], 'the old score stays in the history');

        $crawler = $this->client->request('GET', '/dashboard');
        $notice = $crawler->filter('.score-change');
        $this->assertCount(1, $notice);
        $this->assertStringContainsString(sprintf('Bowl Score updated: %d → %d (+%d)', $before, $after, $after - $before), $notice->text());
        $this->assertStringContainsString('Worked out again after Bella’s new weight.', $notice->text());
        $this->assertSelectorTextContains('.today-score__ring', (string) $after);
        $this->client->request('GET', '/dashboard/dog');
        $this->assertSelectorExists('.score-change');

        // Undo puts the score back, and the notice goes.
        $token = $crawler->filter('form[action="/dashboard/tracker/undo"] input[name=_token]')->attr('value');
        $this->client->request('POST', '/dashboard/tracker/undo', ['_token' => $token, 'type' => 'weight']);
        $this->assertSame($before, $this->reload()->getBowlScore());
        $this->client->request('GET', '/dashboard');
        $this->assertSelectorNotExists('.score-change');

        // Closing it keeps the new score.
        $this->log('weight', '28.4');
        $crawler = $this->client->request('GET', '/dashboard/dog');
        $this->client->submit($crawler->filter('.score-change form')->form());
        $this->assertResponseRedirects('/dashboard/dog');
        $this->assertNull($this->reload()->getScoreChange());
        $this->assertSame($after, $this->reload()->getBowlScore());
    }

    public function testTheCoachReScoresAfterANewWeightAndSaysSo(): void
    {
        $before = $this->dog()->getBowlScore();
        FakeClaude::$script = [
            ['text' => '', 'tool' => ['name' => 'update_dog_profile', 'input' => ['weight_kg' => 28.4]]],
            ['text' => 'Great progress! Her Bowl Score went up too.'],
        ];
        $csrf = $this->client->request('GET', '/chat')->filter('#chat')->attr('data-csrf');
        $this->client->request('POST', '/chat/send', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], json_encode(['message' => 'She is 28.4 kg now!']));
        $body = (string) $this->client->getInternalResponse()->getContent();
        $after = $this->reload()->getBowlScore();
        $this->assertGreaterThan($before, $after);

        // The coach is told, to mention it; the chat shows it under the reply.
        $toolResult = json_encode(FakeClaude::$requests[1]['messages']);
        $this->assertStringContainsString(sprintf("Bella's Bowl Score was worked out again with this: %d \\u2192 %d", $before, $after), $toolResult);
        $notes = array_filter($this->events($body, 'note'), fn ($d) => ($d['kind'] ?? null) === 'score');
        $this->assertCount(1, $notes);
        $note = array_values($notes)[0];
        $this->assertSame(sprintf('Bella’s Bowl Score: %d → %d (+%d)', $before, $after, $after - $before), explode(' · ', $note['text'])[0]);
        $this->assertSame(1, $note['trend']);
        $this->client->request('GET', '/dashboard');
        $this->assertSelectorTextContains('.score-change', sprintf('%d → %d', $before, $after));
    }

    public function testABodyShapeFromTheCoachCountsToo(): void
    {
        $before = $this->dog()->getBowlScore();
        FakeClaude::$script = [
            ['text' => '', 'tool' => ['name' => 'update_dog_profile', 'input' => ['body_score' => 5]]],
            ['text' => 'An ideal shape, well done.'],
        ];
        $csrf = $this->client->request('GET', '/chat')->filter('#chat')->attr('data-csrf');
        $this->client->request('POST', '/chat/send', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], json_encode(['message' => 'The vet says she is a 5 out of 9 now.']));
        $dog = $this->reload();
        $this->assertSame(5, (int) $dog->latestLog('body')['v']);
        $this->assertGreaterThan($before, $dog->getBowlScore());
        $this->assertSame(['body'], $dog->getScoreChange()['why']);
        $this->client->request('GET', '/dashboard');
        $this->assertSelectorTextContains('.score-change', 'Worked out again after Bella’s new body shape.');
    }

    public function testTheDailyPlanFollows(): void
    {
        // Heavy, and eating far more than her target: keep the amounts and ask the vet.
        $dog = $this->dog();
        $this->assertStringStartsWith('Dry food: about 380 g a day — Our estimate', $dog->get('plan_food'));
        // At an ideal body shape the target is close to what she eats: a new amount, no vet note.
        $this->log('body', '5');
        $dog = $this->reload();
        $this->assertMatchesRegularExpression('/^Dry food: about \d+ g a day$/', (string) $dog->get('plan_food'));
        $this->assertSame('Dry food: about 380 g a day', $dog->getScoreChange()['plan_from']);
        $this->assertSame($dog->get('plan_food'), $dog->getScoreChange()['plan_to']);
        $this->client->request('GET', '/dashboard');
        $this->assertSelectorTextContains('.score-change', 'Daily food: ' . $dog->get('plan_food'));
        $this->assertSelectorTextContains('.today-bowl', $dog->get('plan_food'));
    }

    public function testAWeightOnTheQuizDayCountsAsNewer(): void
    {
        // The quiz today (its weight and body shape logged today), then a new weight the same day.
        $user = $this->createUser();
        $dog = $this->createDog($user, ['quiz_weight_kg' => 31, 'quiz_bcs' => 7]);
        $dog->setQuizAnswers(['_inputs' => self::INPUTS]);
        $this->rescorer()->recalculate($dog, 'weight', new \DateTimeImmutable());
        $dog->dismissScoreChange();
        $this->em->flush();
        $before = $dog->getBowlScore();
        $this->client->loginUser($user);
        $this->log('weight', '28.4');
        $this->assertSame(6, $this->rescorer()->inputs($this->reload(), new \DateTimeImmutable())['bcs'], 'ideal from the quiz’s 31 kg at shape 7');
        $this->assertGreaterThan($before, $this->reload()->getBowlScore());
    }

    public function testTheTargetWeightStaysPutWhenWeightAndShapeAreUpdated(): void
    {
        // 38 kg at 8 of 9: the target is about 29 kg.
        $dog = $this->dog(['weightKg' => 38, 'bcs' => 8] + self::INPUTS);
        $target = (float) $dog->get('ideal_weight_kg');
        $this->assertEqualsWithDelta(29.2, $target, 0.15);

        // A month later the coach hears "36.1 kg, body shape 7 of 9": the target doesn't drift to 30 kg.
        $dog->addLog('weight', 36.1, new \DateTimeImmutable(), 'chat');
        $dog->addLog('body', 7, new \DateTimeImmutable(), 'chat');
        $this->rescorer()->recalculate($dog, 'weight', new \DateTimeImmutable());
        $this->assertSame($target, (float) $dog->get('ideal_weight_kg'));
        $this->assertSame(7, $this->rescorer()->inputs($dog, new \DateTimeImmutable())["bcs"]);

        // A shape two steps away from what the weight says is a real correction: the target follows.
        $dog->addLog('body', 5, new \DateTimeImmutable(), 'owner');
        $this->rescorer()->recalculate($dog, 'body', new \DateTimeImmutable());
        $this->assertEqualsWithDelta(36.1, (float) $dog->get('ideal_weight_kg'), 0.15);
    }

    public function testAPastDayCanBeLogged(): void
    {
        $this->dog();
        $token = $this->client->request('GET', '/dashboard')->filter('form[action="/dashboard/tracker"] input[name=_token]')->attr('value');
        $yesterday = (new \DateTimeImmutable('-1 day'))->format('Y-m-d');
        $this->client->request('POST', '/dashboard/tracker', ['_token' => $token, 'type' => 'weight', 'value' => '30,4', 'date' => $yesterday]);
        $this->assertSame(['d' => $yesterday, 'v' => 30.4], array_intersect_key($this->reload()->latestLog('weight'), ['d' => 1, 'v' => 1]));
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.today-undo', 'Undo');

        // Not in the future, not more than a year back.
        foreach (['+1 day', '-400 days'] as $when) {
            $this->client->request('POST', '/dashboard/tracker', ['_token' => $token, 'type' => 'weight', 'value' => '29', 'date' => (new \DateTimeImmutable($when))->format('Y-m-d')]);
        }
        $this->assertSame(30.4, $this->reload()->latestLog('weight')['v']);

        // The dog tab lists the weigh-ins with their days.
        $this->client->request('GET', '/dashboard/dog');
        $this->assertSelectorTextContains('.weight-history', '30.4 kg');
    }

    public function testWithoutSavedInputsTheScoreStaysAsTheQuizGaveIt(): void
    {
        $user = $this->createUser();
        $dog = $this->createDog($user);
        $score = $dog->getBowlScore();
        $this->client->loginUser($user);
        $this->log('weight', '25');
        $dog = $this->reload();
        $this->assertSame($score, $dog->getBowlScore());
        $this->assertNull($dog->getScoreChange());
        $this->assertSame(25.0, (float) $dog->latestLog('weight')['v'], 'the weight is still logged');
    }

    public function testPuppiesKeepTheirBodyShapeWhenTheyGain(): void
    {
        $puppy = ['stage' => 'puppy', 'weightKg' => 10, 'bcs' => 5, 'ageYears' => 0.4, 'adultKg' => 30] + self::INPUTS;
        $dog = $this->dog($puppy);
        $this->assertSame(5, $this->rescorer()->inputs($dog, new \DateTimeImmutable())['bcs']);
        $this->log('weight', '13');
        $this->assertSame(5, $this->rescorer()->inputs($this->reload(), new \DateTimeImmutable())['bcs'], 'a growing puppy is not "heavier than ideal"');
    }

    public function testTextsAreWrittenInTheQuizLanguageAndShownInThePageLanguage(): void
    {
        $dog = $this->dog(self::INPUTS, 'lt');
        $this->assertStringStartsWith('Sausas maistas', (string) $dog->get('plan_food'));
        $this->assertNotEmpty($dog->get('fix_codes'));
        $english = static::getContainer()->get(QuizTexts::class)->fixText($dog->get('fix_codes')[0], 'Bella', 'en');
        $this->client->request('GET', '/dashboard/dog');
        $this->assertSelectorTextContains('.dog-fixes', mb_substr($english, 0, 30));
    }

    public function testInputsAreCheckedBeforeUse(): void
    {
        $this->assertNotNull(ScoreInputs::clean(self::INPUTS));
        $this->assertNull(ScoreInputs::clean(['bcs' => 12] + self::INPUTS));
        $this->assertNull(ScoreInputs::clean(['foodMain' => 'pizza'] + self::INPUTS));
        $this->assertNull(ScoreInputs::clean(['feeds' => [['type' => 'cake', 'grams' => 1]]] + self::INPUTS));
        $this->assertSame('none', ScoreInputs::clean(['treats' => 'heaps'] + self::INPUTS)['treats']);
    }
}
