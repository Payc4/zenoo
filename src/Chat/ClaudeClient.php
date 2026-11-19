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
        #[Autowire('%env(default:chat.fast_model:ANTHROPIC_FAST_MODEL)%')] private readonly string $fastModel = 'claude-haiku-5-5',
        #[Autowire('%env(default::ANTHROPIC_EFFORT)%')] private readonly ?string $effort = null,
    ) {
    }

    /**
     * The model for quick, structured jobs (the safety check, reading a label): fast and cheap,
     * and it can be made to answer with one tool. ANTHROPIC_FAST_MODEL, default Claude Haiku 5.5.
     */
    public function fastModel(): string
    {
        return $this->fastModel;
    }

    /** Models whose thinking can't be switched off (Claude Opus 5.5 and later top models). */
    public static function alwaysThinks(string $model): bool
    {
        return !self::canDisableThinking($model) && (bool) preg_match('/^claude-(opus|sonnet|fable|mythos)-\d/', $model);
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
     * Tool calls (e.g. ask_questions) are collected and returned once complete.
     *
     * @return array{text: string, tool_uses: list<array{id: string, name: string, input: array}>, content: list<array>, stop_reason: ?string, usage: array{input: int, output: int}}
     */
    /**
     * Models that accept thinking: {type: disabled}. Haiku 5.5 does; Opus/Sonnet 5.5 refuse it
     * (400), and older models without thinking ignore the default anyway.
     */
    public static function canDisableThinking(string $model): bool
    {
        return (bool) preg_match('/^claude-haiku-5/', $model) || (bool) preg_match('/^claude-(haiku|sonnet|opus)-4/', $model);
    }

    public function stream(array $payload, callable $onText): array
    {
        // The coach uses ANTHROPIC_MODEL; a caller may ask for the fast model (SafetyCheck, the label reader).
        $payload['model'] = (string) ($payload['model'] ?? $this->model);
        $payload['stream'] = true;
        if (self::alwaysThinks($payload['model'])) {
            // Claude Opus 5.5 always thinks, and thinking counts toward max_tokens: leave it room,
            // or replies end mid-sentence. It can't be made to answer with one forced tool either,
            // so let it choose (the prompts ask for the tool; callers cope without it).
            $payload['max_tokens'] = (int) ($payload['max_tokens'] ?? 1024) + 12000;
            if (\in_array($payload['tool_choice']['type'] ?? null, ['tool', 'any'], true)) {
                $payload['tool_choice'] = ['type' => 'auto'];
            }
            if ($this->effort && $payload['model'] === $this->model && !isset($payload['output_config'])) {
                $payload['output_config'] = ['effort' => $this->effort];
            }
        }
        // Claude Haiku 5.5 thinks before answering unless told not to, and the thinking counts
        // toward max_tokens: a short limit then ends the reply mid-sentence or before any text.
        // The coach and the label reader don't need it, and replies come back faster without.
        if (!isset($payload['thinking']) && self::canDisableThinking($payload['model'])) {
            $payload['thinking'] = ['type' => 'disabled'];
        }

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
            $tools = [];      // index => ['id', 'name', 'json']
            $blocks = [];     // index => content block, in the order the API sent them
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

                        case 'content_block_start':
                            $cb = $data['content_block'] ?? [];
                            $i = (int) $data['index'];
                            if (($cb['type'] ?? '') === 'tool_use') {
                                $tools[$i] = [
                                    'id' => (string) ($cb['id'] ?? ''),
                                    'name' => (string) ($cb['name'] ?? ''),
                                    'json' => '',
                                ];
                                $blocks[$i] = ['type' => 'tool_use'];
                            } elseif (($cb['type'] ?? '') === 'thinking') {
                                $blocks[$i] = ['type' => 'thinking', 'thinking' => (string) ($cb['thinking'] ?? ''), 'signature' => (string) ($cb['signature'] ?? '')];
                            } elseif (($cb['type'] ?? '') === 'redacted_thinking') {
                                $blocks[$i] = ['type' => 'redacted_thinking', 'data' => (string) ($cb['data'] ?? '')];
                            } elseif (($cb['type'] ?? '') === 'text') {
                                $blocks[$i] = ['type' => 'text', 'text' => ''];
                            }
                            break;

                        case 'content_block_delta':
                            $type = $data['delta']['type'] ?? '';
                            $i = (int) $data['index'];
                            if ($type === 'text_delta') {
                                $text .= $data['delta']['text'];
                                if (isset($blocks[$i])) {
                                    $blocks[$i]['text'] .= $data['delta']['text'];
                                }
                                $onText($data['delta']['text']);
                            } elseif ($type === 'input_json_delta' && isset($tools[$i])) {
                                $tools[$i]['json'] .= $data['delta']['partial_json'];
                            } elseif ($type === 'thinking_delta' && isset($blocks[$i])) {
                                $blocks[$i]['thinking'] .= $data['delta']['thinking'];
                            } elseif ($type === 'signature_delta' && isset($blocks[$i])) {
                                $blocks[$i]['signature'] .= $data['delta']['signature'];
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

        ksort($tools);
        $toolUses = [];
        foreach ($tools as $i => $t) {
            $input = $t['json'] !== '' ? json_decode($t['json'], true) : [];
            $toolUses[] = ['id' => $t['id'], 'name' => $t['name'], 'input' => is_array($input) ? $input : []];
            $blocks[$i] = ['type' => 'tool_use', 'id' => $t['id'], 'name' => $t['name'], 'input' => is_array($input) && $input ? $input : new \stdClass()];
        }
        // The assistant turn as the API returned it (thinking blocks with their signatures, text,
        // tool calls): a tool loop must send it back unchanged when the model thinks.
        ksort($blocks);
        $content = array_values(array_filter($blocks, fn ($b) => !($b['type'] === 'text' && trim($b['text']) === '')));

        return ['text' => $text, 'tool_uses' => $toolUses, 'content' => $content, 'stop_reason' => $stopReason, 'usage' => $usage];
    }
}
