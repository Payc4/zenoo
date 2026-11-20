<?php

namespace App\Chat;

use App\Entity\DogProfile;
use Psr\Log\LoggerInterface;

/**
 * Checks each coach reply against the dog's saved rules (allergies, conditions such as
 * pancreatitis, medicines) before it's kept (F6). The coach already gets these as rules; this is
 * the safety net. A reply that suggests something those rules forbid (chicken treats for a dog
 * allergic to chicken, fatty food after pancreatitis) is rewritten with the smallest change that
 * fixes it, and the pet parent sees that it was corrected.
 *
 * Only runs when the dog has such notes. Never throws: if the check fails, the reply stays.
 */
final class ReplyGuard
{
    public const RULE_TOPICS = ['allergy', 'condition', 'medicine'];

    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return list<string> the dog's notes that every reply must respect */
    public static function rules(?DogProfile $dog): array
    {
        if ($dog === null) {
            return [];
        }
        $out = [];
        foreach ($dog->getNotes() as $n) {
            if (\in_array($n['topic'] ?? '', self::RULE_TOPICS, true) || (($n['topic'] ?? '') === 'health' && preg_match('/allerg|alerg|алерг|intoleran|pancreat|pankreat|панкреат|kasos uždeg/iu', $n['text']))) {
                $out[] = $n['text'];
            }
        }
        return $out;
    }

    /**
     * @return array{ok: bool, problem: string, corrected: string}|null null when there was nothing to check
     */
    public function review(string $reply, ?DogProfile $dog): ?array
    {
        $rules = self::rules($dog);
        if ($rules === [] || trim($reply) === '' || !$this->claude->isConfigured()) {
            return null;
        }
        try {
            $result = $this->claude->stream([
                'model' => $this->claude->fastModel(),
                'max_tokens' => 2500,
                'system' => "You check a dog-nutrition coach's reply before the pet parent sees it. The dog's saved health notes are rules:\n- "
                    . implode("\n- ", array_map(fn ($r) => mb_substr($r, 0, 200), $rules))
                    . "\n\nDoes the reply suggest or approve anything those notes rule out? For example a food, treat or ingredient the dog is allergic or intolerant to, "
                    . "high-fat food or fatty treats for a dog with pancreatitis (now or in the past), or something that clashes with a medicine. "
                    . "Mentioning the allergen to say it should be avoided is fine. If there is a problem, write the corrected reply: the same reply, same language "
                    . "and formatting, with only the problem fixed (swap the suggestion for a safe one, or say it isn't suitable because of the note). Answer only with the review tool.",
                'messages' => [['role' => 'user', 'content' => "Dog: " . ($dog?->getName() ?? 'the dog') . "\n\nReply:\n" . mb_substr($reply, 0, 8000)]],
                'tools' => [[
                    'name' => 'review',
                    'description' => 'Whether the reply respects the notes, and the corrected reply if not.',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'ok' => ['type' => 'boolean'],
                            'problem' => ['type' => 'string', 'description' => 'Short, in the reply\'s language; empty when ok.'],
                            'corrected' => ['type' => 'string', 'description' => 'The whole corrected reply; empty when ok.'],
                        ],
                        'required' => ['ok'],
                    ],
                ]],
                'tool_choice' => ['type' => 'tool', 'name' => 'review'],
            ], static function (): void {});
        } catch (\Throwable $e) {
            $this->logger->warning('Reply check failed; the reply stays as it is', ['exception' => $e]);
            return null;
        }
        foreach ($result['tool_uses'] as $use) {
            $in = (array) ($use['input'] ?? []);
            $ok = (bool) ($in['ok'] ?? true);
            $corrected = trim((string) ($in['corrected'] ?? ''));
            return ['ok' => $ok || $corrected === '', 'problem' => trim((string) ($in['problem'] ?? '')), 'corrected' => $ok ? '' : $corrected];
        }
        return null;
    }
}
