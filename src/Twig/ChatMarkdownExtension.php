<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `{{ text|chat_markdown }}`: safe HTML for chatbot replies. Everything is escaped first;
 * then only **bold**, *italic*, "- " bullets, "1. " numbered lists, "# headings" (shown bold),
 * paragraphs and line breaks are recognised. Mirrors render() in assets/chat.js.
 */
final class ChatMarkdownExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [new TwigFilter('chat_markdown', [$this, 'format'], ['is_safe' => ['html']])];
    }

    public function format(?string $markdown): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $markdown);
        $text = (string) preg_replace('/^\s{0,3}#{1,6}\s+(.+?)\s*#*\s*$/mu', '**$1**', $text);
        $text = trim((string) preg_replace('/^(\s*)\*\s+/mu', '$1- ', $text));
        if ($text === '') {
            return '';
        }

        $html = [];
        foreach (preg_split('/\n\s*\n/', $text) as $block) {
            $buffer = [];
            $listType = null;
            $items = [];
            $flushList = function () use (&$html, &$items, &$listType): void {
                if ($items) {
                    $html[] = "<{$listType}>" . implode('', array_map(fn ($i) => "<li>{$i}</li>", $items)) . "</{$listType}>";
                }
                $items = [];
                $listType = null;
            };
            $flushPara = function () use (&$html, &$buffer): void {
                if ($buffer) {
                    $html[] = '<p>' . implode('<br>', $buffer) . '</p>';
                }
                $buffer = [];
            };
            foreach (explode("\n", $block) as $line) {
                if (preg_match('/^\s*[-•]\s+(.*)$/u', $line, $m)) {
                    $flushPara();
                    if ($listType !== 'ul') {
                        $flushList();
                        $listType = 'ul';
                    }
                    $items[] = $this->inline($m[1]);
                } elseif (preg_match('/^\s*\d+[.)]\s+(.*)$/u', $line, $m)) {
                    $flushPara();
                    if ($listType !== 'ol') {
                        $flushList();
                        $listType = 'ol';
                    }
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

    private function inline(string $text): string
    {
        $s = htmlspecialchars(trim($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = (string) preg_replace('/\*\*(.+?)\*\*/su', '<strong>$1</strong>', $s);

        return (string) preg_replace('/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/su', '<em>$1</em>', $s);
    }
}
