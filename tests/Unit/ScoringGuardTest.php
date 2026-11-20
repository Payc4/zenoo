<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Runs tests/js/scoring_guard.js over the quiz's own scoring script (needs Node). */
final class ScoringGuardTest extends TestCase
{
    public function testThePlanNeverWorksAgainstBodyShape(): void
    {
        exec('command -v node', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('Node is not installed.');
        }
        $dir = \dirname(__DIR__, 2);
        $json = shell_exec('node ' . escapeshellarg("$dir/tests/js/scoring_guard.js") . ' ' . escapeshellarg("$dir/public/quiz/scoring.js"));
        $result = json_decode((string) $json, true);
        $this->assertGreaterThan(10000, $result['cases']);
        $this->assertSame(0, $result['count'], json_encode($result['problems']));
    }
}
