<?php

namespace App\Chat;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal streaming client for the Claude Messages API (no SDK needed).
 * Configure with ANTHROPIC_API_KEY and, optionally, ANTHROPIC_MODEL.
 */
class ClaudeClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(default::ANTHROPIC_API_KEY)%')] private readonly ?string $apiKey,
        #[Autowire('%env(default:chat.default_model:ANTHROPIC_MODEL)%')] private readonly string $model,
        #[Autowire('%env(default:chat.default_base_url:ANTHROPIC_BASE_URL)%')] private readonly string $baseUrl,
    ) {
    }

    public function isConfigured(): bool
    {
        return (string) $this->apiKey !== '';
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * Sends one streamed request and calls $onText for every text fragment as it arrives.
     *
     * @return array{text: string, stop_reason: ?string, usage: array{input: int, output: int}}
     */
    public function stream(array $payload, callable $onText): array
    {
        $payload['model'] = $this->model;
        $payload['stream'] = true;

        try {
            $response = $this->http->request('POST', rtrim($this->baseUrl, '/') . '/v1/messages', [
                'headers' => [
                    'x-api-key' => (string) $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                    'accept' => 'text/event-stream',
                ],
                'json' => $payload,
                'timeout' => 60,
                'max_duration' => 180,
            ]);

            $status = $response->getStatusCode();
            if ($status >= 400) {
                $data = json_decode($response->getContent(false), true);
                throw new ClaudeException($data['error']['message'] ?? ('HTTP ' . $status), $status, $data['error']['type'] ?? null);
            }

            $text = '';
            $stopReason = null;
            $usage = ['input' => 0, 'output' => 0];
            $buffer = '';

            foreach ($this->http->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    continue;
                }
                $buffer .= $chunk->getContent();

                // Server-sent events are separated by a blank line.
                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $raw = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);

                    $data = null;
                    foreach (explode("\n", $raw) as $line) {
                        if (str_starts_with($line, 'data:')) {
                            $data = json_decode(trim(substr($line, 5)), true);
                        }
                    }
                    if (!is_array($data)) {
                        continue;
                    }

                    switch ($data['type'] ?? '') {
                        case 'message_start':
                            $u = $data['message']['usage'] ?? [];
                            $usage['input'] = (int) ($u['input_tokens'] ?? 0)
                                + (int) ($u['cache_read_input_tokens'] ?? 0)
                                + (int) ($u['cache_creation_input_tokens'] ?? 0);
                            $usage['output'] = (int) ($u['output_tokens'] ?? 0);
                            break;

                        case 'content_block_delta':
                            if (($data['delta']['type'] ?? '') === 'text_delta') {
                                $text .= $data['delta']['text'];
                                $onText($data['delta']['text']);
                            }
                            break;

                        case 'message_delta':
                            $stopReason = $data['delta']['stop_reason'] ?? $stopReason;
                            if (isset($data['usage']['output_tokens'])) {
                                $usage['output'] = (int) $data['usage']['output_tokens'];
                            }
                            break;

                        case 'error':
                            throw new ClaudeException($data['error']['message'] ?? 'Stream error', 500, $data['error']['type'] ?? null);
                    }
                }
            }
        } catch (TransportExceptionInterface $e) {
            throw new ClaudeException('Could not reach Claude: ' . $e->getMessage(), 503);
        }

        return ['text' => $text, 'stop_reason' => $stopReason, 'usage' => $usage];
    }
}
