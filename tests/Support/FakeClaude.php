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

    public static function reset(): void
    {
        self::$reply = 'Hello! **Weigh** the food.';
        self::$fail = false;
        self::$askQuestions = null;
        self::$requests = [];
    }

    public static function respond(array $options): MockResponse
    {
        self::$requests[] = json_decode((string) ($options['body'] ?? '{}'), true);
        if (self::$fail) {
            return new MockResponse(json_encode(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]), ['http_code' => 529]);
        }

        $events = [
            ['message_start', ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 300, 'output_tokens' => 1]]]],
            ['content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]],
        ];
        foreach (str_split(self::$reply, 8) as $piece) {
            $events[] = ['content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $piece]]];
        }
        if (self::$askQuestions !== null) {
            $events[] = ['content_block_start', ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_test', 'name' => 'ask_questions', 'input' => new \stdClass()]]];
            foreach (str_split(json_encode(self::$askQuestions), 30) as $piece) {
                $events[] = ['content_block_delta', ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => $piece]]];
            }
            $events[] = ['content_block_stop', ['type' => 'content_block_stop', 'index' => 1]];
        }
        $events[] = ['message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => self::$askQuestions !== null ? 'tool_use' : 'end_turn'], 'usage' => ['output_tokens' => 42]]];
        $events[] = ['message_stop', ['type' => 'message_stop']];

        $body = '';
        foreach ($events as [$type, $data]) {
            $body .= "event: {$type}\ndata: " . json_encode($data) . "\n\n";
        }

        return new MockResponse($body, ['response_headers' => ['content-type' => 'text/event-stream']]);
    }
}
