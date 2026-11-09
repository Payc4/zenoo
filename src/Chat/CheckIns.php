<?php

namespace App\Chat;

use App\Entity\DogProfile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Chat check-ins: every few days the chat shows one or two short multiple-choice questions about
 * the dog (config/chatbot/checkins.yaml, Lithuanian wording in checkins.lt.yaml). Answers are
 * saved to the DogProfile and listed in the coach's instructions (forCoach()).
 *
 * Deliberately no AI here: which questions to ask is decided by the rules in the YAML file
 * (order of importance, repeat_days), and answers are saved without a chat message, so a
 * check-in costs no API usage. The coach only sees the answers inside the prompt it sends anyway.
 */
final class CheckIns
{
    private ?array $bank = null;
    /** @var array<string, array> */
    private array $localised = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/chatbot/checkins.yaml')] private readonly string $file,
    ) {
    }

    /**
     * The cards to show now, or null: nothing due, or check-ins were shown less than every_days ago.
     *
     * @return list<array{id: string, question: string, options: list<array{label: string, description: string, value: string}>}>|null
     */
    public function cardsFor(DogProfile $dog, string $locale, \DateTimeImmutable $now): ?array
    {
        $bank = $this->bank();
        $asked = $dog->getCheckinAskedAt();
        if ($asked !== null && $asked > $now->modify('-' . $bank['every_days'] . ' days')) {
            return null;
        }
        $words = $this->wording($locale);
        $cards = [];
        foreach ($this->dueIds($dog, $now) as $id) {
            $q = $bank['questions'][$id];
            $w = $words[$id] ?? null;
            $labels = $w['labels'] ?? [];
            $cards[] = [
                'id' => $id,
                'question' => str_replace('%dog%', $dog->getName(), $w['question'] ?? $q['question']),
                'options' => array_map(fn ($value, $label) => [
                    'label' => $labels[$value] ?? $label,
                    'description' => '',
                    'value' => $value,
                ], array_keys($q['options']), $q['options']),
            ];
            if (\count($cards) >= $bank['per_visit']) {
                break;
            }
        }
        return $cards ?: null;
    }

    /** @return list<string> ids of the questions that are due, most important first */
    public function dueIds(DogProfile $dog, \DateTimeImmutable $now): array
    {
        $answers = $dog->getCheckins();
        $due = [];
        foreach ($this->bank()['questions'] as $id => $q) {
            $last = $answers[$id][0]['at'] ?? null;
            if ($last === null || new \DateTimeImmutable($last) <= $now->modify('-' . $q['repeat_days'] . ' days')) {
                $due[] = $id;
            }
        }
        return $due;
    }

    /**
     * Saves answers from the cards: [{id, value?, text?}, …]. Unknown ids, unknown option values
     * and empty answers are ignored. Returns how many were saved.
     */
    public function save(DogProfile $dog, mixed $answers, \DateTimeImmutable $now): int
    {
        $questions = $this->bank()['questions'];
        $saved = 0;
        foreach (\is_array($answers) ? \array_slice($answers, 0, 10) : [] as $a) {
            $id = \is_array($a) ? (string) ($a['id'] ?? '') : '';
            if (!isset($questions[$id])) {
                continue;
            }
            $value = (string) ($a['value'] ?? '');
            if ($value !== '' && !isset($questions[$id]['options'][$value])) {
                continue;
            }
            $text = mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) ($a['text'] ?? '')))), 0, 300);
            if ($value === '' && $text === '') {
                continue;
            }
            $dog->addCheckin($id, $value, $text, $now);
            $saved++;
        }
        return $saved;
    }

    /** The answers as lines for the coach's instructions (English labels, newest first). */
    public function forCoach(DogProfile $dog): string
    {
        $questions = $this->bank()['questions'];
        $lines = [];
        foreach ($dog->getCheckins() as $id => $history) {
            if (!isset($questions[$id]) || !$history) {
                continue;
            }
            $describe = fn (array $h) => trim(($h['value'] !== '' ? ($questions[$id]['options'][$h['value']] ?? $h['value']) : '')
                . ($h['text'] !== '' ? ($h['value'] !== '' ? ' — ' : '') . '"' . $h['text'] . '"' : ''));
            $line = '- ' . $questions[$id]['topic'] . ': ' . $describe($history[0]) . ' (' . $this->day($history[0]['at']) . ')';
            if (isset($history[1]) && $describe($history[1]) !== $describe($history[0])) {
                $line .= '; before: ' . $describe($history[1]) . ' (' . $this->day($history[1]['at']) . ')';
            }
            $lines[] = $line;
        }
        if (!$lines) {
            return '';
        }
        return "What they told the chat check-ins since (newer than the quiz where they differ):\n" . implode("\n", $lines);
    }

    private function day(string $date): string
    {
        return (new \DateTimeImmutable($date))->format('j M Y');
    }

    /** @return array{every_days: int, per_visit: int, questions: array<string, array{repeat_days: int, topic: string, question: string, options: array<string, string>}>} */
    private function bank(): array
    {
        if ($this->bank !== null) {
            return $this->bank;
        }
        $data = is_file($this->file) ? (Yaml::parseFile($this->file) ?? []) : [];
        $questions = [];
        foreach ((array) ($data['questions'] ?? []) as $q) {
            $id = (string) ($q['id'] ?? '');
            $options = [];
            foreach ((array) ($q['options'] ?? []) as $o) {
                if (isset($o['value'], $o['label'])) {
                    $options[(string) $o['value']] = (string) $o['label'];
                }
            }
            if ($id === '' || !isset($q['question']) || !$options) {
                continue;
            }
            $questions[$id] = [
                'repeat_days' => max(1, (int) ($q['repeat_days'] ?? 30)),
                'topic' => (string) ($q['topic'] ?? $q['question']),
                'question' => (string) $q['question'],
                'options' => $options,
            ];
        }
        return $this->bank = [
            'every_days' => max(1, (int) ($data['every_days'] ?? 3)),
            'per_visit' => max(1, min(4, (int) ($data['per_visit'] ?? 2))),
            'questions' => $questions,
        ];
    }

    /** @return array<string, array{question: string, labels: array<string, string>}> */
    private function wording(string $locale): array
    {
        if ($locale === 'en') {
            return [];
        }
        if (!isset($this->localised[$locale])) {
            $file = preg_replace('/\.yaml$/', '.' . $locale . '.yaml', $this->file);
            $out = [];
            foreach ((array) (is_file($file) ? (Yaml::parseFile($file)['questions'] ?? []) : []) as $q) {
                $labels = [];
                foreach ((array) ($q['options'] ?? []) as $o) {
                    if (isset($o['value'], $o['label'])) {
                        $labels[(string) $o['value']] = (string) $o['label'];
                    }
                }
                if (isset($q['id'], $q['question'])) {
                    $out[(string) $q['id']] = ['question' => (string) $q['question'], 'labels' => $labels];
                }
            }
            $this->localised[$locale] = $out;
        }
        return $this->localised[$locale];
    }
}
