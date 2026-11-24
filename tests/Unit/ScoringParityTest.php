<?php

namespace App\Tests\Unit;

use App\Quiz\Scoring;
use PHPUnit\Framework\TestCase;

/**
 * The server's copy of the Bowl Score maths (src/Quiz/Scoring.php) must give exactly what the
 * quiz gives (public/quiz/scoring.js): it re-scores dogs after a new weight or body shape.
 */
final class ScoringParityTest extends TestCase
{
    public function testThePhpCopyMatchesTheQuizOnThousandsOfDogs(): void
    {
        $root = \dirname(__DIR__, 2);
        exec('node ' . escapeshellarg($root . '/tests/js/scoring_cases.js') . ' ' . escapeshellarg($root . '/public/quiz/scoring.js') . ' 4000 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $cases = json_decode(implode('', $out), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(4000, $cases);
        $diffs = [];
        foreach ($cases as $i => ['a' => $a, 'r' => $js]) {
            $php = Scoring::evaluate($a);
            foreach (['total', 'band', 'capped', 'kcal', 'targetKcal', 'keep', 'vetCheck', 'idealKg', 'adultKg', 'intakeKcal', 'ratio', 'grams', 'topFixes'] as $k) {
                if (json_encode($js[$k]) !== json_encode($php[$k]) && !(is_numeric($js[$k]) && is_numeric($php[$k]) && abs($js[$k] - $php[$k]) < 1e-9)) {
                    $diffs[] = "#$i $k: js " . json_encode($js[$k]) . ' php ' . json_encode($php[$k]);
                }
            }
            foreach ($js['subs'] as $k => $v) {
                if ($v['score'] !== $php['subs'][$k]['score']) {
                    $diffs[] = "#$i subs.$k: js {$v['score']} php {$php['subs'][$k]['score']}";
                }
            }
            $plan = fn ($p) => $p === null ? null : array_map(fn ($x) => [$x['type'], $x['grams']], $p);
            if ($plan($js['planFeeds']) !== $plan($php['planFeeds'])) {
                $diffs[] = "#$i planFeeds: js " . json_encode($plan($js['planFeeds'])) . ' php ' . json_encode($plan($php['planFeeds']));
            }
            foreach (['proteinG', 'fatG'] as $k) {
                if (($js['targets'][$k] ?? null) !== null && abs($js['targets'][$k][1] - $php['targets'][$k][1]) > 1e-6) {
                    $diffs[] = "#$i targets.$k";
                }
            }
        }
        $this->assertSame([], \array_slice($diffs, 0, 20), \count($diffs) . ' differences');
    }
}
