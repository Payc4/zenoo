<?php

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/** Stands in for the Claude Messages API in tests: streams $reply as server-sent events. */
final class FakeClaude
{
    public static string $reply = 'Hello! **Weigh** the food.';
    public static bool $fail = false;
    /** @var list<array> request bodies sent to Claude */
    public static array $requests = [];

    public static function reset(): void
    {
        self::$reply = 'Hello! **Weigh** the food.';
        self::$fail = false;
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
        $events[] = ['message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 42]]];
        $events[] = ['message_stop', ['type' => 'message_stop']];

        $body = '';
        foreach ($events as [$type, $data]) {
            $body .= "event: {$type}\ndata: " . json_encode($data) . "\n\n";
        }

        return new MockResponse($body, ['response_headers' => ['content-type' => 'text/event-stream']]);
    }
}
