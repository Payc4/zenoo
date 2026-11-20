<?php

namespace App\Catalogue;

use App\Chat\Calculators;
use App\Entity\CatalogueProduct;
use App\Entity\DogProfile;
use App\Repository\CatalogueProductRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The personal ZENOO plan (roadmap 01): for one dog, a ZENOO food as a topper, half the bowl or the
 * whole bowl, each with grams a day, cost a day and a cart link for a two-week starter. Size-aware:
 * the recommended option is the biggest one that stays within the daily budget, so a big dog gets
 * a topper or half-bowl plan instead of an expensive full diet. All maths from Calculators.
 */
final class PlanBuilder
{
    /** Share of the day's food calories for each option. */
    public const OPTIONS = ['topper' => 0.25, 'half' => 0.5, 'full' => 1.0];
    public const STARTER_DAYS = 14;

    public function __construct(
        private readonly CatalogueProductRepository $products,
        private readonly CatalogueSync $sync,
        #[Autowire('%env(default:plan_budget_default:PLAN_DAILY_BUDGET)%')] private readonly string $dailyBudget,
        #[Autowire('%env(default::SHOPIFY_STARTER_CODE)%')] private readonly ?string $starterCode,
    ) {
    }

    /**
     * @return array{product: CatalogueProduct, kcal_per_day: int, options: array<string, array>, recommended: string, starter_code: ?string, schedule: array, budget: float}|null
     *         null when there's no usable ZENOO food or the dog's weight isn't known
     */
    public function forDog(DogProfile $dog, ?CatalogueProduct $product = null): ?array
    {
        $product ??= $this->mainFood();
        $f = Calculators::dogFacts($dog);
        if ($product === null || !$f['weight_kg']) {
            return null;
        }
        $energy = Calculators::planKcal($f); // never against body shape
        $foodKcal = $energy['kcal'] * (1 - 0.05); // the quiz's "a few treats" allowance
        [$variant, $perGram] = $product->cheapestPerGram();
        $budget = (float) $this->dailyBudget;

        $options = [];
        foreach (self::OPTIONS as $key => $share) {
            $grams = $foodKcal * $share / $product->getKcal100() * 100;
            [$starter, $packs] = self::starter($product, $grams * self::STARTER_DAYS);
            $options[$key] = [
                'share' => $share,
                'grams_per_day' => self::round($grams),
                'cost_per_day' => round($grams * $perGram, 2),
                'cost_per_30_days' => round($grams * $perGram * 30, 2),
                'starter_packs' => $packs,
                'starter_variant' => $starter['title'],
                'starter_price' => round($packs * $starter['price'], 2),
                'cart_url' => $this->cartUrl($starter['id'], $packs),
            ];
        }
        $recommended = 'topper';
        foreach (['full', 'half'] as $key) {
            if ($options[$key]['cost_per_day'] <= $budget) {
                $recommended = $key;
                break;
            }
        }
        return [
            'product' => $product,
            'product_url' => $this->sync->storeUrl() . '/products/' . $product->getHandle(),
            'kcal_per_day' => (int) round($energy['kcal'], -1),
            'options' => $options,
            'recommended' => $recommended,
            'starter_code' => $this->starterCode ?: null,
            'switch_days' => $days = $f['stage'] === 'senior' ? 10 : 7,
            'schedule' => Calculators::run(['operation' => 'switch_schedule', 'days' => $days], null)['schedule'],
            'budget' => $budget,
        ];
    }

    /** The cheapest way to buy at least $grams in one pack size: [variant, how many]. */
    public static function starter(CatalogueProduct $product, float $grams): array
    {
        $best = null;
        foreach ($product->getVariants() as $v) {
            if (!($v['available'] ?? true) || empty($v['packGrams']) || ($v['price'] ?? 0) <= 0) {
                continue;
            }
            $qty = max(1, (int) ceil($grams / $v['packGrams']));
            $cost = $qty * $v['price'];
            if ($best === null || $cost < $best[2] || ($cost === $best[2] && $qty * $v['packGrams'] < $best[1] * $best[0]['packGrams'])) {
                $best = [$v, $qty, $cost];
            }
        }
        return [$best[0], $best[1]];
    }

    /** The ZENOO complete food for dogs with the lowest cost per 1,000 kcal. */
    public function mainFood(): ?CatalogueProduct
    {
        $best = null;
        foreach ($this->products->findUsable() as $p) {
            if ($p->getKind() !== 'complete') {
                continue;
            }
            $per1000 = $p->cheapestPerGram()[1] * 100000 / $p->getKcal100();
            if ($best === null || $per1000 < $best[1]) {
                $best = [$p, $per1000];
            }
        }
        return $best[0] ?? null;
    }

    /** What the quiz needs to show the plan on its result page (same maths, in the browser). */
    public function forQuiz(): ?array
    {
        $p = $this->mainFood();
        if ($p === null) {
            return null;
        }
        [$v] = $p->cheapestPerGram();
        return [
            'title' => $p->getTitle(),
            'url' => $this->sync->storeUrl() . '/products/' . $p->getHandle(),
            'image' => $p->getImage(),
            'kcal100' => $p->getKcal100(),
            'variant' => ['id' => $v['id'], 'title' => $v['title'], 'packGrams' => $v['packGrams'], 'price' => $v['price']],
            'packs' => array_values(array_map(fn ($x) => ['id' => $x['id'], 'packGrams' => $x['packGrams'], 'price' => $x['price']], array_filter($p->getVariants(), fn ($x) => ($x['available'] ?? true) && !empty($x['packGrams']) && $x['price'] > 0))),
            'budget' => (float) $this->dailyBudget,
            'cartBase' => $this->sync->storeUrl() . '/cart/',
            'starterCode' => $this->starterCode ?: null,
            'starterDays' => self::STARTER_DAYS,
        ];
    }

    /** @return list<CatalogueProduct> */
    public function catalogueProducts(): array
    {
        return $this->products->findUsable();
    }

    /** The usable products, compact, for the coach. */
    public function catalogueForCoach(): array
    {
        return array_map(function (CatalogueProduct $p) {
            [$v] = $p->cheapestPerGram();
            return [
                'title' => $p->getTitle(),
                'kind' => $p->getKind(),
                'kcal_per_100g' => $p->getKcal100(),
                'analysis_percent' => array_diff_key((array) $p->getAnalysis(), ['kcal_estimated' => 1]) ?: null,
                'packs' => array_map(fn ($x) => ['size' => $x['title'], 'grams' => $x['packGrams'], 'price_eur' => $x['price'], 'available' => $x['available']], $p->getVariants()),
                'best_value_pack' => $v['title'],
                'note' => $p->getNotes(),
                'url' => $this->sync->storeUrl() . '/products/' . $p->getHandle(),
            ];
        }, $this->products->findUsable());
    }

    /** Shopify cart permalink: straight to checkout with the packs (and the starter code). */
    public function cartUrl(string $variantId, int $qty): string
    {
        $url = $this->sync->storeUrl() . '/cart/' . $variantId . ':' . $qty;
        return $this->starterCode ? $url . '?discount=' . rawurlencode($this->starterCode) : $url;
    }

    private static function round(float $g): int
    {
        return (int) ($g >= 100 ? round($g / 5) * 5 : round($g));
    }
}
