<?php

namespace App\Catalogue;

use App\Entity\CatalogueProduct;
use App\Repository\CatalogueProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Copies the main store's products from Shopify's public products.json (no API key needed):
 * titles, prices and pack sizes, and, read from each description, the energy (kcal) and the
 * analytical constituents. Run by app:catalogue:sync and the Sync button in Admin → Catalogue.
 */
final class CatalogueSync
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly EntityManagerInterface $em,
        private readonly CatalogueProductRepository $products,
        #[Autowire('%env(default::SHOPIFY_STORE_URL)%')] private readonly ?string $storeUrl,
    ) {
    }

    public function storeUrl(): string
    {
        return rtrim($this->storeUrl ?: 'https://zenoo.lt', '/');
    }

    /** @return array{found: int, created: int, gone: int, withKcal: int} */
    public function sync(): array
    {
        $now = new \DateTimeImmutable();
        $seen = [];
        $created = 0;
        $withKcal = 0;
        for ($page = 1; $page <= 10; $page++) {
            $response = $this->http->request('GET', $this->storeUrl() . '/products.json', ['query' => ['limit' => 250, 'page' => $page], 'timeout' => 20]);
            $items = $response->toArray()['products'] ?? [];
            foreach ($items as $item) {
                $id = (string) $item['id'];
                $seen[$id] = true;
                $product = $this->products->findOneBy(['shopifyId' => $id]);
                if (!$product) {
                    $product = new CatalogueProduct($id);
                    $this->em->persist($product);
                    ++$created;
                }
                $data = self::parse($item);
                $product->applySync($data, $now);
                $withKcal += $data['kcal'] !== null ? 1 : 0;
            }
            if (\count($items) < 250) {
                break;
            }
        }
        $gone = 0;
        foreach ($this->products->findAll() as $p) {
            if (!isset($seen[$p->getShopifyId()]) && $p->isActive()) {
                $p->markGone();
                ++$gone;
            }
        }
        $this->em->flush();
        return ['found' => \count($seen), 'created' => $created, 'gone' => $gone, 'withKcal' => $withKcal];
    }

    /** One products.json item → what CatalogueProduct::applySync() takes. */
    public static function parse(array $item): array
    {
        $text = self::plainText((string) ($item['body_html'] ?? ''));
        $tags = \is_array($item['tags'] ?? null) ? $item['tags'] : array_filter(array_map('trim', explode(',', (string) ($item['tags'] ?? ''))));
        $title = (string) ($item['title'] ?? '');
        $variants = [];
        foreach ((array) ($item['variants'] ?? []) as $v) {
            $variants[] = [
                'id' => (string) $v['id'],
                'title' => (string) ($v['title'] ?? ''),
                'price' => (float) ($v['price'] ?? 0),
                'packGrams' => self::packGrams((string) ($v['title'] ?? ''), $title, (int) ($v['grams'] ?? 0)),
                'available' => (bool) ($v['available'] ?? true),
            ];
        }
        $haystack = mb_strtolower($title . ' ' . ($item['product_type'] ?? '') . ' ' . implode(' ', $tags));
        $kcal = self::kcalPer100g($text);
        $analysis = self::analysis($text);
        if ($kcal === null && isset($analysis['moisture'])) {
            // No energy value given: estimate it from the analysis (modified Atwater), and say so.
            try {
                $kcal = (float) \App\Chat\Calculators::labelEnergy($analysis)['kcal_per_100g'];
                $analysis['kcal_estimated'] = true;
            } catch (\InvalidArgumentException) {
            }
        }
        return [
            'handle' => (string) ($item['handle'] ?? ''),
            'title' => $title,
            'productType' => (string) ($item['product_type'] ?? ''),
            'tags' => array_values($tags),
            'image' => $item['images'][0]['src'] ?? ($item['image']['src'] ?? null),
            'variants' => $variants,
            'description' => mb_substr($text, 0, 4000),
            'kcal' => $kcal,
            'analysis' => $analysis,
            'kind' => self::guessKind($haystack . ' ' . mb_strtolower(mb_substr($text, 0, 600))),
            'species' => self::guessSpecies($haystack),
        ];
    }

    public static function plainText(string $html): string
    {
        $html = preg_replace('~<(br|/p|/li|/tr|/h\d|/div)\b[^>]*>~i', "\n", $html);
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace(["~[ \t\x{00A0}]+~u", "~\n\s*\n+~u"], [' ', "\n"], $text));
    }

    /** "300 g", "1,5 kg", "6 x 400 g" in the variant or product title; else the weight Shopify has. */
    public static function packGrams(string $variantTitle, string $productTitle, int $shopifyGrams): ?int
    {
        foreach ([$variantTitle, $productTitle] as $t) {
            if (preg_match('~(\d+)\s*[x×]\s*(\d+(?:[.,]\d+)?)\s*(kg|g)\b~iu', $t, $m)) {
                return (int) round((int) $m[1] * self::grams($m[2], $m[3]));
            }
            if (preg_match('~(\d+(?:[.,]\d+)?)\s*(kg|g)\b~iu', $t, $m)) {
                return (int) round(self::grams($m[1], $m[2]));
            }
        }
        return $shopifyGrams > 0 ? $shopifyGrams : null;
    }

    private static function grams(string $n, string $unit): float
    {
        $v = (float) str_replace(',', '.', $n);
        return strtolower($unit) === 'kg' ? $v * 1000 : $v;
    }

    /** Energy per 100 g as fed, from "x kcal/100 g", "x kcal/kg", "x MJ/kg" or "energy … x kcal". */
    public static function kcalPer100g(string $text): ?float
    {
        $num = static fn (string $s) => (float) str_replace([' ', ','], ['', '.'], $s);
        if (preg_match('~(\d[\d ]{0,5}(?:[.,]\d+)?)\s*kcal\s*/\s*100\s*g~iu', $text, $m)) {
            $v = $num($m[1]);
        } elseif (preg_match('~(\d[\d ]{2,6}(?:[.,]\d+)?)\s*kcal\s*/\s*kg~iu', $text, $m)) {
            $v = $num($m[1]) / 10;
        } elseif (preg_match('~(\d+[.,]\d+)\s*MJ\s*/\s*kg~iu', $text, $m)) {
            $v = $num($m[1]) * 239.006 / 10;
        } elseif (preg_match('~(energ|енерг)\w*[^0-9\n]{0,40}(\d[\d ]{1,6}(?:[.,]\d+)?)\s*kcal~iu', $text, $m)) {
            $v = $num($m[2]);
            $v = $v > 1000 ? $v / 10 : $v; // a per-kilo value
        } else {
            return null;
        }
        return $v >= 20 && $v <= 700 ? round($v, 1) : null;
    }

    /** Analytical constituents in % as fed (Lithuanian, English, Polish, Ukrainian wording). */
    public static function analysis(string $text): ?array
    {
        $keys = [
            'protein' => '(?:baltym|protein|białk|biał|білк|протеїн)',
            'fat' => '(?:riebal|fat\b|fat content|tłuszcz|жир)',
            'fibre' => '(?:ląstelien|fib(?:re|er)|włókn|клітковин)',
            'ash' => '(?:pelen|ash|popi[oó]ł|попіл|зол)',
            'moisture' => '(?:drėgm|moisture|wilgo|волог)',
        ];
        $out = [];
        foreach ($keys as $k => $re) {
            if (preg_match('~' . $re . '\w*[^0-9%\n]{0,30}(\d{1,2}(?:[.,]\d+)?)\s*%~iu', $text, $m)) {
                $out[$k] = (float) str_replace(',', '.', $m[1]);
            }
        }
        return isset($out['protein'], $out['fat']) ? $out : null;
    }

    public static function guessKind(string $s): string
    {
        return match (true) {
            (bool) preg_match('~papild(as|ai|ų)\b|supplement|suplement|добавк|aliej|oil\b|probiot|vitamin~u', $s) => 'supplement',
            (bool) preg_match('~skanėst|treat|chew|kramt|przysmak|ласощ~u', $s) => 'treat',
            (bool) preg_match('~topper|papildomas pašaras|complementary|uzupełniaj|додатков~u', $s) => 'topper',
            (bool) preg_match('~visavert|complete|pełnoporcj|повнораціон|pašaras|food|maistas|karma|корм~u', $s) => 'complete',
            default => 'other',
        };
    }

    public static function guessSpecies(string $s): string
    {
        $cat = (bool) preg_match('~\b(kat[eėi]\w*|katin\w*|cats?|kot\w*|кот\w*|кіш\w*)\b~u', $s);
        $dog = (bool) preg_match('~\b(šun\w*|dogs?|ps[aóy]\w*|pies\w*|собак\w*|пс\w*)\b~u', $s);
        return $cat && !$dog ? 'cat' : ($cat && $dog ? 'both' : 'dog');
    }
}
