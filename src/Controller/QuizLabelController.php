<?php

namespace App\Controller;

use App\Chat\ChatFiles;
use App\Chat\ClaudeClient;
use App\Locale;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The quiz's one-time "let our AI read the label" try-out: a photo of the food label goes to
 * Claude, which reads the brand and the analytical constituents (filled into the label step, so
 * they count towards the Bowl Score) and adds a one-line take. The photo isn't stored.
 *
 * Anyone can take the quiz, so it is limited: TRIES_PER_SESSION per visitor and TRIES_PER_IP a day.
 */
final class QuizLabelController extends AbstractController
{
    public const TRIES_PER_SESSION = 2;
    public const TRIES_PER_IP = 6;

    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly CacheItemPoolInterface $cache,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/quiz/label-scan', name: 'app_quiz_label_scan', methods: ['POST'])]
    public function scan(Request $request): JsonResponse
    {
        $locale = Locale::normalize($request->request->get('locale', $request->getLocale()));
        $t = fn (string $m) => $this->translator->trans($m, [], null, $locale);
        if (!$this->isCsrfTokenValid('quiz', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => $t('Your session expired. Please reload the page.')], 403);
        }
        if (!$this->claude->isConfigured()) {
            return $this->json(['error' => $t('Reading labels isn’t available right now. Please type the numbers in.')], 503);
        }
        $file = $request->files->get('photo');
        if (!$file instanceof UploadedFile || !$file->isValid() || !str_starts_with((string) $file->getMimeType(), 'image/') || $file->getSize() > ChatFiles::MAX_IMAGE_BYTES) {
            return $this->json(['error' => $t('Please send a photo (JPG, PNG, WebP) or a PDF.')], 400);
        }

        // Limits: per visitor (session) and per IP address and day.
        $session = $request->getSession();
        $tries = (int) $session->get('quiz_label_tries', 0);
        $ipItem = $this->cache->getItem('quiz_label_' . date('Ymd') . '_' . hash('sha256', (string) $request->getClientIp()));
        $ipTries = (int) ($ipItem->isHit() ? $ipItem->get() : 0);
        if ($tries >= self::TRIES_PER_SESSION || $ipTries >= self::TRIES_PER_IP) {
            return $this->json(['error' => $t('That was your free try. Type the numbers in, and after the quiz the coach can read labels for you any time.'), 'limit' => true], 429);
        }
        $session->set('quiz_label_tries', $tries + 1);
        $ipItem->set($ipTries + 1)->expiresAfter(86400);
        $this->cache->save($ipItem);

        $jpeg = ChatFiles::toJpeg((string) file_get_contents($file->getPathname()));
        if ($jpeg === null) {
            return $this->json(['error' => $t('We couldn’t read that image. Please try another one.')], 400);
        }

        try {
            $result = $this->claude->stream([
                'max_tokens' => 600,
                'system' => self::PROMPT . "\n\nWrite \"take\" in " . Locale::ENGLISH_NAMES[$locale] . '.',
                'messages' => [['role' => 'user', 'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($jpeg)]],
                    ['type' => 'text', 'text' => 'Read this dog food label.'],
                ]]],
                'tools' => [self::tool()],
                'tool_choice' => ['type' => 'tool', 'name' => 'label_reading'],
            ], static fn () => null);
        } catch (\Throwable $e) {
            $this->logger->error('Quiz label scan failed', ['exception' => $e]);
            return $this->json(['error' => $t('Reading labels isn’t available right now. Please type the numbers in.')], 503);
        }
        $this->logger->info('Quiz label scan', ['tokens_in' => $result['usage']['input'], 'tokens_out' => $result['usage']['output']]);

        $r = [];
        foreach ($result['tool_uses'] as $use) {
            if ($use['name'] === 'label_reading') {
                $r = $use['input'];
            }
        }
        $num = static function ($v): ?float {
            return is_numeric($v) && $v >= 0 && $v <= 100 ? round((float) $v, 1) : null;
        };
        $text = static fn ($v, int $n) => mb_substr(trim(strip_tags((string) $v)), 0, $n);
        $values = ['p' => $num($r['protein'] ?? null), 'f' => $num($r['fat'] ?? null), 'fi' => $num($r['fibre'] ?? null), 'a' => $num($r['ash'] ?? null), 'm' => $num($r['moisture'] ?? null)];

        return $this->json([
            'ok' => true,
            'readable' => (bool) ($r['readable'] ?? false),
            'brand' => $text($r['brand'] ?? '', 80),
            'product' => $text($r['product'] ?? '', 120),
            'format' => \in_array($r['format'] ?? '', ['dry', 'wet', 'raw', 'freeze-dried', 'fresh', 'home-cooked', 'other'], true) ? $r['format'] : '',
            'values' => array_filter($values, fn ($v) => $v !== null),
            'take' => $text($r['take'] ?? '', 300),
            'triesLeft' => self::TRIES_PER_SESSION - $tries - 1,
        ]);
    }

    private const PROMPT = <<<'TXT'
You read photos of dog food labels and packaging for the ZENOO Bowl Score quiz.
Report only what you can actually read; never guess numbers. If a value isn't visible, leave it out.
"Analytical constituents" (or "guaranteed analysis") lists protein, fat (oils and fats), fibre (crude fibre), ash (crude ash, inorganic matter) and moisture in %. Use minimums or maximums as printed.
readable is true when you could read at least protein and fat.
take: one or two short, friendly sentences for the dog's owner about what stands out (e.g. a high protein share, a lot of carbs once you subtract everything listed, "complementary" meaning it isn't a full diet). No health claims, never say a food prevents or treats anything, don't name or criticise other brands, no shaming. If the photo isn't a food label, say so kindly.
TXT;

    private static function tool(): array
    {
        $pct = ['type' => 'number', 'description' => '% as printed'];
        return [
            'name' => 'label_reading',
            'description' => 'What the label says.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'readable' => ['type' => 'boolean'],
                    'brand' => ['type' => 'string'],
                    'product' => ['type' => 'string'],
                    'format' => ['type' => 'string', 'enum' => ['dry', 'wet', 'raw', 'freeze-dried', 'fresh', 'home-cooked', 'other']],
                    'protein' => $pct, 'fat' => $pct, 'fibre' => $pct, 'ash' => $pct, 'moisture' => $pct,
                    'take' => ['type' => 'string'],
                ],
                'required' => ['readable', 'take'],
            ],
        ];
    }
}
