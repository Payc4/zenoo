<?php

namespace App\Course;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Turns a Listen screen's script into speech with ElevenLabs (text to speech), saved as an MP3
 * next to the uploaded recordings (public/uploads/lesson-audio).
 *
 * Settings (Coolify environment variables):
 *   ELEVENLABS_API_KEY        required; an ElevenLabs API key with Text to Speech access
 *   ELEVENLABS_VOICE_ID       the voice for every language (e.g. a clone of Agota, made with her consent)
 *   ELEVENLABS_VOICE_ID_LT / _UK / _PL / _EN   optional: a different voice for one language
 *   ELEVENLABS_MODEL          default eleven_v3 (the model that speaks Lithuanian as well as
 *                             English, Ukrainian and Polish); eleven_multilingual_v2 has no Lithuanian
 */
final class VoiceGenerator
{
    public const AUDIO_DIR = '/public/uploads/lesson-audio';
    private const API = 'https://api.elevenlabs.io/v1/text-to-speech/';
    /** Models that take a language_code (the others detect the language from the text). */
    private const LANGUAGE_CODE_MODELS = ['eleven_v3', 'eleven_flash_v2_5', 'eleven_turbo_v2_5'];

    /** @param array<string, string|null> $voicesByLocale */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $projectDir,
        private readonly ?string $apiKey,
        private readonly ?string $voiceId,
        private readonly array $voicesByLocale,
        private readonly string $model = 'eleven_v3',
    ) {}

    public function isConfigured(?string $locale = null): bool
    {
        return trim((string) $this->apiKey) !== '' && $this->voiceFor($locale ?? 'en') !== null;
    }

    public function model(): string
    {
        return $this->model ?: 'eleven_v3';
    }

    /** The script as it should be spoken: no Markdown, no list markers, no links. */
    public static function speechText(string $script): string
    {
        $t = str_replace("\r\n", "\n", $script);
        $t = preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $t);           // [text](url) → text
        $t = preg_replace('/(\*\*|__|\*|_)(\S(?:.*?\S)?)\1/u', '$2', $t);    // **bold**, *italic*
        $t = preg_replace('/^[ \t]*(?:[-*•]|\d+[.)])[ \t]+/mu', '', $t);           // list markers
        $t = preg_replace('/^#+\s*/mu', '', $t);                             // headings
        $t = preg_replace("/[ \t]+/u", ' ', $t);
        return trim(preg_replace("/\n{3,}/u", "\n\n", $t));
    }

    /**
     * Makes the MP3 and returns its URL (/uploads/lesson-audio/…).
     *
     * @throws \RuntimeException with ElevenLabs' own explanation (bad key, no credits left, …)
     */
    public function generate(string $script, string $locale, string $fileName): string
    {
        $text = self::speechText($script);
        if ($text === '') {
            throw new \RuntimeException('This screen has no script to read.');
        }
        if (!$this->isConfigured($locale)) {
            throw new \RuntimeException('ElevenLabs isn\'t set up: add ELEVENLABS_API_KEY and ELEVENLABS_VOICE_ID in Coolify, then redeploy.');
        }
        $body = ['text' => $text, 'model_id' => $this->model()];
        if (\in_array($this->model(), self::LANGUAGE_CODE_MODELS, true)) {
            $body['language_code'] = $locale;
        }

        try {
            $response = $this->http->request('POST', self::API . rawurlencode((string) $this->voiceFor($locale)) . '?output_format=mp3_44100_128', [
                'headers' => ['xi-api-key' => (string) $this->apiKey, 'Accept' => 'audio/mpeg'],
                'json' => $body,
                'timeout' => 180,
            ]);
            $status = $response->getStatusCode();
            $audio = $response->getContent(false);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Couldn\'t reach ElevenLabs: ' . $e->getMessage(), 0, $e);
        }
        if ($status >= 300 || $audio === '') {
            throw new \RuntimeException('ElevenLabs said: ' . self::errorMessage($audio, $status));
        }

        $dir = $this->projectDir . self::AUDIO_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $name = preg_replace('/[^a-z0-9-]+/', '-', strtolower($fileName)) . '-' . bin2hex(random_bytes(3)) . '.mp3';
        file_put_contents($dir . '/' . $name, $audio);

        return '/uploads/lesson-audio/' . $name;
    }

    private function voiceFor(string $locale): ?string
    {
        $voice = trim((string) ($this->voicesByLocale[$locale] ?? '')) ?: trim((string) $this->voiceId);
        return $voice !== '' ? $voice : null;
    }

    private static function errorMessage(string $body, int $status): string
    {
        $data = json_decode($body, true);
        $detail = $data['detail'] ?? null;
        $message = \is_array($detail) ? ($detail['message'] ?? $detail['status'] ?? null) : $detail;
        $message = \is_string($message) && $message !== '' ? $message : "HTTP $status";
        return match (true) {
            $status === 401 => "$message (check ELEVENLABS_API_KEY)",
            $status === 404 => "$message (check the voice id)",
            default => $message,
        };
    }
}
