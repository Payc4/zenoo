<?php

namespace App\Tests\Unit;

use App\Course\CourseDocumentParser;
use App\Course\LessonLayouts;
use App\Twig\LessonTextExtension;
use PHPUnit\Framework\TestCase;

/** The real course document must import cleanly: 30 lessons per language, 9 screens each. */
final class CourseDocumentParserTest extends TestCase
{
    private static array $parsed;

    public static function setUpBeforeClass(): void
    {
        self::$parsed = (new CourseDocumentParser())->parse((string) file_get_contents(__DIR__ . '/../../content/courses/zenoo-dog-wellness.md'));
    }

    public function testEveryLessonHasTheNineScreensInOrder(): void
    {
        foreach (['en', 'lt'] as $lang) {
            $this->assertCount(30, self::$parsed[$lang], $lang);
            foreach (self::$parsed[$lang] as $i => $lesson) {
                $this->assertSame($i + 1, $lesson['number']);
                $this->assertSame(LessonLayouts::STANDARD, array_column($lesson['slides'], 'layout'), "$lang lesson {$lesson['number']}");
            }
        }
    }

    public function testEveryQuestionHasExactlyOneRightAnswerAndFeedback(): void
    {
        foreach (['en', 'lt'] as $lang) {
            foreach (self::$parsed[$lang] as $lesson) {
                $s = array_column($lesson['slides'], 'fields', 'layout');
                $where = "$lang lesson {$lesson['number']}";
                $this->assertSame(1, \count(array_filter(array_column($s['listen']['choices'], 'correct'))), $where);
                $this->assertNotSame('', $s['listen']['feedback'], $where);
                $this->assertNotSame('', $s['listen']['script'], $where);
                $this->assertCount(2, $s['quiz']['questions'], $where);
                foreach ($s['quiz']['questions'] as $q) {
                    $this->assertSame(1, \count(array_filter(array_column($q['choices'], 'correct'))), $where);
                    $this->assertNotSame('', $q['correct'], $where);
                    $this->assertNotSame('', $q['incorrect'], $where);
                }
                foreach (['instructions', 'weak', 'model', 'modelWhy'] as $k) {
                    $this->assertNotSame('', $s['write']['prompts'][0][$k], "$where write.$k");
                }
                $this->assertNotSame('', $s['vet']['vet'], $where);
                $this->assertNotSame('', $s['vet']['action'], $where);
                $this->assertGreaterThanOrEqual(2, \count($s['vet']['takeaways']), $where);
                $this->assertNotSame('', $s['achievement']['badge'], $where);
            }
        }
    }

    public function testLessonOneDetails(): void
    {
        $l = self::$parsed['en'][0];
        $s = array_column($l['slides'], 'fields', 'layout');
        $this->assertSame('The Poop Review', $l['title']);
        $this->assertSame(8, $l['minutes']);
        $this->assertSame('High', $l['vetReview']);
        $this->assertSame("The most honest health report you'll get today", $s['hook']['headline']);
        $this->assertCount(4, $s['hook']['list']);
        $this->assertSame('2', array_values(array_filter($s['quiz']['questions'][0]['choices'], fn ($c) => $c['correct']))[0]['text']);
        $this->assertCount(4, $s['dog']['steps']);
        $this->assertStringContainsString('| Chocolate brown', $s['deeper']['body'], 'the colour table is kept as a table');
        $this->assertSame('For the next 7 days:', $s['vet']['actionTitle']);
        $this->assertSame('Poop Detective', $s['achievement']['badge']);
        $this->assertSame('Day 1 of your 7-day poop log', $s['achievement']['extra']);
        $this->assertStringStartsWith('Not quite.', $s['listen']['incorrect']);
    }

    public function testTeamNotesAreLeftOutAndReported(): void
    {
        $this->assertCount(6, self::$parsed['notes']);
        $json = json_encode([self::$parsed['en'], self::$parsed['lt']], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Agota:', $json);
        $this->assertStringNotContainsString('App note', $json);
        $this->assertStringNotContainsString('\\[', $json);
    }

    public function testSlideIdsStayTheSameBetweenImports(): void
    {
        $again = (new CourseDocumentParser())->parse((string) file_get_contents(__DIR__ . '/../../content/courses/zenoo-dog-wellness.md'));
        $this->assertSame(array_column(self::$parsed['en'][4]['slides'], 'id'), array_column($again['en'][4]['slides'], 'id'));
    }

    public function testLessonTextRendersTablesSafely(): void
    {
        $html = (new LessonTextExtension())->format("Intro\n\n| A | B |\n|---|---|\n| **x** | <script> |");
        $this->assertStringContainsString('<p>Intro</p>', $html);
        $this->assertStringContainsString('<th>A</th>', $html);
        $this->assertStringContainsString('<td data-label="A"><strong>x</strong></td>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('|---', $html);
    }
}
