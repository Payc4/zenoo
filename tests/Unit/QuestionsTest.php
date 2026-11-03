<?php

namespace App\Tests\Unit;

use App\Chat\Questions;
use PHPUnit\Framework\TestCase;

class QuestionsTest extends TestCase
{
    public function testNormaliseLimitsAndTrims(): void
    {
        $long = str_repeat('a', 300);
        $q = Questions::normalise([
            ['question' => "  Two\n words  ", 'options' => [['label' => 'One', 'description' => $long], 'Plain string', ['label' => 'A'], ['label' => 'B'], ['label' => 'C'], ['label' => 'D']]],
        ]);
        $this->assertSame('Two words', $q[0]['question']);
        $this->assertCount(Questions::MAX_OPTIONS, $q[0]['options']);
        $this->assertSame('Plain string', $q[0]['options'][1]['label']);
        $this->assertSame(160, mb_strlen($q[0]['options'][0]['description']));
        $this->assertNull(Questions::normalise('nope'));
        $this->assertNull(Questions::normalise([['question' => '']]));
    }

    public function testToolDefinitionIsValid(): void
    {
        $tool = Questions::toolDefinition();
        $this->assertSame('ask_questions', $tool['name']);
        $this->assertSame(['questions'], $tool['input_schema']['required']);
        $this->assertSame(4, $tool['input_schema']['properties']['questions']['maxItems']);
    }

    public function testStartingQuestionsFileIsValid(): void
    {
        $data = \Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__, 2) . '/config/chatbot/intro_questions.yaml');
        $q = Questions::normalise($data['questions']);
        $this->assertNotNull($q);
        $this->assertLessThanOrEqual(Questions::MAX_QUESTIONS, count($q));
    }
}
