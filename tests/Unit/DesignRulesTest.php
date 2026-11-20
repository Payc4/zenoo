<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Test report proposal 1: dark text on orange, small labels never orange, 44 px taps, one heading scale. */
final class DesignRulesTest extends TestCase
{
    private function css(): array
    {
        $root = \dirname(__DIR__, 2);
        $files = array_merge(glob("$root/assets/styles/*.css"), ["$root/public/quiz/site.css"]);
        return array_combine(array_map('basename', $files), array_map('file_get_contents', $files));
    }

    public function testNoWhiteTextOnOrange(): void
    {
        foreach ($this->css() as $name => $css) {
            foreach (explode('}', $css) as $rule) {
                if (preg_match('/background:\s*var\(--sun(, *#f7a223)?\)/', $rule)) {
                    $this->assertDoesNotMatchRegularExpression('/[;{]\s*color:\s*(#fff\b|#ffffff|var\(--white\))/i', $rule, "$name: white text on orange in: " . trim($rule));
                }
            }
        }
    }

    public function testSmallLabelsAreNotOrange(): void
    {
        foreach ($this->css() as $name => $css) {
            $this->assertStringNotContainsString('color: var(--sun-700)', $css, $name);
        }
        $app = $this->css()['app.css'];
        $this->assertStringContainsString('--on-sun: #2f3f2e', $app);
        $this->assertStringContainsString('--amber-text: #935a00', $app);
        $this->assertStringContainsString('--ink-muted: #5c6b5b', $app);
    }

    public function testTapTargetsAndHeadingScale(): void
    {
        $app = $this->css()['app.css'];
        $this->assertMatchesRegularExpression('/\.btn \{[^}]*min-height: 52px/', $app);
        $this->assertMatchesRegularExpression('/\.btn-small \{[^}]*min-height: 44px/', $app);
        $this->assertStringContainsString('h2 { font-size: 20px;', $app);
        $this->assertStringContainsString('h3 { font-size: 17px;', $app);
    }
}
