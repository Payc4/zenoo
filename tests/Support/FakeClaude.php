<?php

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/** Stands in for the Claude Messages API in tests: streams $reply as server-sent events. */
final class FakeClaude
{
    public static string $reply = 'Hello! **Weigh** the food.';
    public static bool $fail = false;
    /** When set, the reply also calls the ask_questions tool with this input. */
    public static ?array $askQuestions = null;
    /** @var list<array> request bodies sent to Claude */
    public static array $requests = [];
    /**
     * Replies for the next requests, in order: {text, tool?: {name, input}}. Once used up, the
     * default reply above is used.
     *
     * stop overrides the stop_reason (e.g. "max_tokens").
     *
     * @var list<array{text: string, tool?: array{name: string, input: array}, stop?: string}>
     */
    public static array $script = [];

    /** What the safety check (SafetyCheck, tool "triage") gets back; its calls go to $triage, not $requests. */
    public static string $redFlag = 'none';
    /** Start each coach reply with a thinking block (with a signature), as Claude Opus 5.5 does. */
    public static bool $thinking = false;
    public static array $triage = [];
    /** The rest of the triage answer: situation ("happening_now" when a flag is set) and health_facts. */
    public static ?string $situation = null;
    public static array $healthFacts = [];
    /** The reply check (ReplyGuard, tool "review"): its calls go to $reviews. */
    public static array $review = ['ok' => true];
    public static array $reviews = [];

    public static function reset(): void
    {
        self::$reply = 'Hello! **Weigh** the food.';
        self::$fail = false;
        self::$askQuestions = null;
        self::$requests = [];
        self::$script = [];
        self::$redFlag = 'none';
        self::$thinking = false;
        self::$triage = [];
        self::$situation = null;
        self::$healthFacts = [];
        self::$review = ['ok' => true];
        self::$reviews = [];
    }

    public static function respond(array $options): MockResponse
    {
        $request = json_decode((string) ($options['body'] ?? '{}'), true);
        if (($request['tool_choice']['name'] ?? null) === 'triage') {
            self::$triage[] = $request;
            return self::stream('', ['name' => 'triage', 'input' => [
                'situation' => self::$situation ?? (self::$redFlag === 'none' ? 'none' : 'happening_now'),
                'red_flag' => self::$redFlag,
                'health_facts' => self::$healthFacts,
            ]], null, false);
        }
        if (($request['tool_choice']['name'] ?? null) === 'review') {
            self::$reviews[] = $request;
            return self::stream('', ['name' => 'review', 'input' => self::$review], null, false);
        }
        self::$requests[] = $request;
        if (self::$fail) {
            return new MockResponse(json_encode(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]), ['http_code' => 529]);
        }

        $reply = self::$reply;
        $tool = self::$askQuestions !== null ? ['name' => 'ask_questions', 'input' => self::$askQuestions] : null;
        $stop = null;
        if (self::$script) {
            $next = array_shift(self::$script);
            $reply = $next['text'];
            $tool = $next['tool'] ?? null;
            $stop = $next['stop'] ?? null;
        }

        return self::stream($reply, $tool, $stop, self::$thinking);
    }

    private static function stream(string $reply, ?array $tool, ?string $stop, bool $thinking = false): MockResponse
    {
        $events = [['message_start', ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 300, 'output_tokens' => 1]]]]];
        $i = 0;
        if ($thinking) {
            $events[] = ['content_block_start', ['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']]];
            $events[] = ['content_block_delta', ['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'Let me think about Bella.']]];
            $events[] = ['content_block_delta', ['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'signature_delta', 'signature' => 'sig-' . \count(self::$requests)]]];
            $events[] = ['content_block_stop', ['type' => 'content_block_stop', 'index' => $i]];
            ++$i;
        }
        $events[] = ['content_block_start', ['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'text', 'text' => '']]];
        foreach ($reply === '' ? [] : mb_str_split($reply, 8) as $piece) {
            $events[] = ['content_block_delta', ['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'text_delta', 'text' => $piece]]];
        }
        if ($tool !== null) {
            ++$i;
            $events[] = ['content_block_start', ['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_' . \count(self::$requests) . '_' . \count(self::$triage), 'name' => $tool['name'], 'input' => new \stdClass()]]];
            foreach (str_split(json_encode($tool['input']), 30) as $piece) {
                $events[] = ['content_block_delta', ['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'input_json_delta', 'partial_json' => $piece]]];
            }
            $events[] = ['content_block_stop', ['type' => 'content_block_stop', 'index' => $i]];
        }
        $events[] = ['message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => $stop ?? ($tool !== null ? 'tool_use' : 'end_turn')], 'usage' => ['output_tokens' => 42]]];
        $events[] = ['message_stop', ['type' => 'message_stop']];

        $body = '';
        foreach ($events as [$type, $data]) {
            $body .= "event: {$type}\ndata: " . json_encode($data) . "\n\n";
        }

        return new MockResponse($body, ['response_headers' => ['content-type' => 'text/event-stream']]);
    }
}
