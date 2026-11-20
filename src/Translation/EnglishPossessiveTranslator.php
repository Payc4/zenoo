<?php

namespace App\Translation;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One English possessive rule everywhere (test report M1): the quiz writes "Žaibas’" and the site
 * wrote "Žaibas’s" from strings like "%dog%’s dashboard". In English, a name ending in s takes only
 * the apostrophe, as in the quiz. Other languages don't use the English possessive.
 */
#[AsDecorator('translator')]
final class EnglishPossessiveTranslator implements TranslatorInterface, TranslatorBagInterface, LocaleAwareInterface, WarmableInterface
{
    public function __construct(#[AutowireDecorated] private readonly TranslatorInterface&TranslatorBagInterface&LocaleAwareInterface $inner)
    {
    }

    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $out = $this->inner->trans($id, $parameters, $domain, $locale);
        if (($locale ?? $this->inner->getLocale()) !== 'en' || !str_contains($out, 's’s')) {
            return $out;
        }
        // Only where a name was put in front of "’s": "Žaibas’s dashboard" → "Žaibas’ dashboard".
        foreach ($parameters as $value) {
            if (\is_string($value) && preg_match('/s$/iu', $value)) {
                $out = str_replace($value . '’s', $value . '’', $out);
            }
        }
        return $out;
    }

    public function getCatalogue(?string $locale = null): MessageCatalogueInterface
    {
        return $this->inner->getCatalogue($locale);
    }

    public function getCatalogues(): array
    {
        return $this->inner->getCatalogues();
    }

    public function setLocale(string $locale): void
    {
        $this->inner->setLocale($locale);
    }

    public function getLocale(): string
    {
        return $this->inner->getLocale();
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        return $this->inner instanceof WarmableInterface ? $this->inner->warmUp($cacheDir, $buildDir) : [];
    }
}
