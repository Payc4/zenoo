<?php

namespace App\Twig;

use App\Locale;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Dates in the page's language: {{ date|local_date('long') }} gives "7 November 2026" in
 * English and "2026 m. lapkričio 7 d." in Lithuanian (likewise Ukrainian and Polish). Styles: day (7 Nov 2026), long,
 * month (November 2026), datetime (7 November 2026, 14:05).
 */
final class LocaleExtension extends AbstractExtension
{
    private const SKELETONS = ['day' => 'yMMMd', 'long' => 'yMMMMd', 'month' => 'yMMMM', 'datetime' => 'yMMMMdHHmm'];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('local_date', $this->format(...))];
    }

    public function getFunctions(): array
    {
        return [
            // {{ locale_name('uk') }} → "Українська"
            new \Twig\TwigFunction('locale_name', fn (string $code) => Locale::NAMES[$code] ?? strtoupper($code)),
            new \Twig\TwigFunction('site_locales', fn () => Locale::NAMES),
            new \Twig\TwigFunction('decimal_sep', fn (?string $code = null) => Locale::decimalSeparator($code ?? ($this->translator instanceof LocaleAwareInterface ? $this->translator->getLocale() : 'en'))),
        ];
    }

    public function format(?\DateTimeInterface $date, string $style = 'long', ?string $locale = null): string
    {
        if ($date === null) {
            return '';
        }
        // The translator follows the page's language, and the email's language while one is rendered.
        $locale ??= $this->translator instanceof LocaleAwareInterface ? $this->translator->getLocale() : 'en';
        $locale = Locale::intl($locale);
        $pattern = (new \IntlDatePatternGenerator($locale))->getBestPattern(self::SKELETONS[$style] ?? self::SKELETONS['long']);
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $date->getTimezone(), null, $pattern);
        return (string) $formatter->format($date);
    }
}
