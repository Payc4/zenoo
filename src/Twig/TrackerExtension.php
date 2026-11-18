<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** tracker_chart(entries, min, max): a small inline SVG line for a tracker on the dashboard. */
final class TrackerExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('tracker_chart', [$this, 'chart'], ['is_safe' => ['html']])];
    }

    /** @param list<array{d: string, v: float|int}> $entries oldest first */
    public function chart(array $entries, float $min, float $max, string $label = ''): string
    {
        $entries = \array_slice($entries, -12);
        if (\count($entries) < 2) {
            return '';
        }
        $vals = array_column($entries, 'v');
        if ($min >= $max) { // no fixed scale (weight): the data's own range, with a little room
            $min = min($vals) - 0.5;
            $max = max($vals) + 0.5;
        }
        $lo = min($min, min($vals));
        $hi = max($max, max($vals));
        $w = 240;
        $h = 64;
        $pad = 6;
        $n = \count($entries) - 1;
        $pts = [];
        foreach ($entries as $i => $e) {
            $x = $pad + $i / $n * ($w - 2 * $pad);
            $y = $h - $pad - ($hi > $lo ? ($e['v'] - $lo) / ($hi - $lo) : 0.5) * ($h - 2 * $pad);
            $pts[] = [round($x, 1), round($y, 1), $e];
        }
        $path = implode(' ', array_map(fn ($p) => $p[0] . ',' . $p[1], $pts));
        $dots = implode('', array_map(fn ($p) => sprintf('<circle cx="%s" cy="%s" r="3"><title>%s: %s</title></circle>', $p[0], $p[1], htmlspecialchars($p[2]['d']), htmlspecialchars((string) $p[2]['v'])), $pts));
        return sprintf('<svg class="tracker-chart" viewBox="0 0 %d %d" role="img" aria-label="%s"><polyline points="%s" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>%s</svg>', $w, $h, htmlspecialchars($label), $path, $dots);
    }
}
