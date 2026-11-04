<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `{{ text|lesson_text }}` — turns the plain text typed into a lesson slide into
 * safe HTML. Everything is escaped first; then only these are recognised:
 *
 *   **bold**   *italic*   lines starting with "- " (bullets) or "1. " (numbered)
 *   a block whose lines all start with "|" = a table (first row is the header,
 *   "|---|" rows are skipped)
 *   blank line = new paragraph, single line break = <br>
 */
final class LessonTextExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('lesson_text', [$this, 'format'], ['is_safe' => ['html']]),
            new TwigFilter('lesson_inline', [$this, 'inline'], ['is_safe' => ['html']]),
        ];
    }

    public function format(?string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim((string) $text));
        if ($text === '') {
            return '';
        }

        $html = [];
        foreach (preg_split('/\n\s*\n/', $text) as $block) {
            $lines = explode("\n", $block);
            if ($this->isTable($lines)) {
                $html[] = $this->table($lines);
                continue;
            }
            $buffer = [];
            $listType = null;
            $items = [];

            $flushList = function () use (&$html, &$items, &$listType) {
                if ($items) {
                    $html[] = "<{$listType}>" . implode('', array_map(fn ($i) => "<li>{$i}</li>", $items)) . "</{$listType}>";
                }
                $items = [];
                $listType = null;
            };
            $flushPara = function () use (&$html, &$buffer) {
                if ($buffer) {
                    $html[] = '<p>' . implode('<br>', $buffer) . '</p>';
                }
                $buffer = [];
            };

            foreach ($lines as $line) {
                if (preg_match('/^\s*[-•]\s+(.*)$/u', $line, $m)) {
                    $flushPara();
                    if ($listType !== 'ul') { $flushList(); $listType = 'ul'; }
                    $items[] = $this->inline($m[1]);
                } elseif (preg_match('/^\s*\d+[.)]\s+(.*)$/u', $line, $m)) {
                    $flushPara();
                    if ($listType !== 'ol') { $flushList(); $listType = 'ol'; }
                    $items[] = $this->inline($m[1]);
                } else {
                    $flushList();
                    $buffer[] = $this->inline($line);
                }
            }
            $flushList();
            $flushPara();
        }

        return implode("\n", $html);
    }

    public function inline(?string $text): string
    {
        $s = htmlspecialchars(trim((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/\*\*(.+?)\*\*/su', '<strong>$1</strong>', $s);
        $s = preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/su', '<em>$1</em>', $s);
        return $s;
    }

    /** @param string[] $lines */
    private function isTable(array $lines): bool
    {
        foreach ($lines as $line) {
            if (!str_starts_with(trim($line), '|')) {
                return false;
            }
        }
        return \count($lines) >= 2;
    }

    /** @param string[] $lines */
    private function table(array $lines): string
    {
        $rows = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^\|[\s:|-]+\|?$/', $line)) {
                continue;
            }
            $cells = explode('|', trim($line, '|'));
            $rows[] = array_map(fn ($c) => $this->inline($c), $cells);
        }
        $head = array_shift($rows) ?? [];
        $out = '<div class="lp-table"><table><thead><tr>' . implode('', array_map(fn ($c) => "<th>{$c}</th>", $head)) . '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $out .= '<tr>' . implode('', array_map(fn ($i, $c) => '<td data-label="' . strip_tags($head[$i] ?? '') . '">' . $c . '</td>', array_keys($r), $r)) . '</tr>';
        }
        return $out . '</tbody></table></div>';
    }
}
