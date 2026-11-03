<?php

namespace App\Chat;

/**
 * Multiple-choice question cards shown in the chat: the starting questions
 * (config/chatbot/intro_questions.yaml) and the ones Claude asks with the ask_questions tool.
 *
 * Normalised shape: list<array{question: string, options: list<array{label: string, description: string}>}>
 * A question with no options is answered by typing. Every card also has "Type your answer…" and Skip.
 */
final class Questions
{
    public const MAX_QUESTIONS = 5;
    public const MAX_OPTIONS = 5;

    /** The tool Claude can call to show cards. Described for the model, not for people. */
    public static function toolDefinition(): array
    {
        return [
            'name' => 'ask_questions',
            'description' => 'Show the pet parent 1–4 short questions as tappable cards, each with 2–4 answer options '
                . '(a short label plus an optional one-line description). They can also type their own answer or skip. '
                . 'Use it when you need specific facts to give a good answer (for example the dog\'s age, size, weight, '
                . 'current food or main concern) or when they should choose between a few clear options. '
                . 'Ask only what you really need. Write any short lead-in as normal text first; after calling the tool, '
                . 'stop and wait: their answers arrive as their next message.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'questions' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => 4,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'question' => ['type' => 'string', 'description' => 'The question, one short sentence.'],
                                'options' => [
                                    'type' => 'array',
                                    'maxItems' => 4,
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'label' => ['type' => 'string', 'description' => 'The answer, 1–5 words.'],
                                            'description' => ['type' => 'string', 'description' => 'Optional short explanation.'],
                                        ],
                                        'required' => ['label'],
                                    ],
                                ],
                            ],
                            'required' => ['question', 'options'],
                        ],
                    ],
                ],
                'required' => ['questions'],
            ],
        ];
    }

    /**
     * Cleans up questions from Claude or the YAML file. Returns null when nothing usable is left.
     *
     * @return list<array{question: string, options: list<array{label: string, description: string}>}>|null
     */
    public static function normalise(mixed $raw, int $max = self::MAX_QUESTIONS): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $out = [];
        foreach (array_slice(array_values($raw), 0, $max) as $q) {
            $question = self::clean(is_array($q) ? ($q['question'] ?? '') : '', 200);
            if ($question === '') {
                continue;
            }
            $options = [];
            foreach (array_slice(array_values((array) ($q['options'] ?? [])), 0, self::MAX_OPTIONS) as $o) {
                $label = self::clean(is_array($o) ? ($o['label'] ?? '') : $o, 80);
                if ($label !== '') {
                    $options[] = ['label' => $label, 'description' => self::clean(is_array($o) ? ($o['description'] ?? '') : '', 160)];
                }
            }
            $out[] = ['question' => $question, 'options' => $options];
        }

        return $out ?: null;
    }

    /** How a set of cards is shown to Claude in the chat history (it only sees text). */
    public static function asHistoryText(array $questions): string
    {
        $lines = ['[Shown to the pet parent as question cards:'];
        foreach ($questions as $i => $q) {
            $opts = array_map(fn ($o) => $o['label'] . ($o['description'] !== '' ? ' (' . $o['description'] . ')' : ''), $q['options']);
            $lines[] = ($i + 1) . '. ' . $q['question'] . ($opts ? ' Options: ' . implode('; ', $opts) . '.' : ' (typed answer)');
        }

        return implode("\n", $lines) . ']';
    }

    private static function clean(mixed $s, int $max): string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $s)));

        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
    }
}
