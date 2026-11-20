<?php

namespace App\Twig;

use App\Entity\DogProfile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * A dog's stored quiz result in the interface language (test report N6). The quiz stores its
 * texts in the language it was taken in, and codes alongside (quiz_score_band, quiz_top_fix_N).
 * When the page is in another language, the band title and the "what to do first" lines come from
 * config/quiz_codes.json (built from the quiz's content files: node bin/build-quiz-codes.js).
 *
 * {{ dog_text(dog, 'score_title') }}, {{ dog_text(dog, 'fix_1_text') }}
 */
final class QuizTextExtension extends AbstractExtension
{
    private ?array $codes = null;

    public function __construct(
        private readonly TranslatorInterface $translator,
        #[Autowire('%kernel.project_dir%/config/quiz_codes.json')] private readonly string $file,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('dog_text', $this->text(...))];
    }

    public function text(DogProfile $dog, string $field): string
    {
        $stored = (string) $dog->get($field, '');
        $locale = $this->translator instanceof LocaleAwareInterface ? $this->translator->getLocale() : 'en';
        if ($dog->getLocale() === $locale || ($stored === '' && $field !== 'score_title')) {
            return $stored;
        }
        $this->codes ??= json_decode((string) @file_get_contents($this->file), true) ?: [];
        $c = $this->codes[$locale] ?? null;
        if ($c === null) {
            return $stored;
        }
        if ($field === 'score_title') {
            return $c['bands'][$dog->band()] ?? $stored;
        }
        if (preg_match('/^fix_(\d)_text$/', $field, $m) && ($key = $dog->get('top_fix_' . $m[1])) && isset($c['fixes'][$key])) {
            $name = $dog->getName();
            return strtr($c['fixes'][$key], [
                '{name}' => $name, '{NAME}' => mb_strtoupper($name),
                '{poss}' => $locale === 'en' ? (preg_match('/s$/i', $name) ? $name . '’' : $name . '’s') : $name,
                '{kcal}' => (string) round((float) $dog->get('kcal_target')), '{ideal}' => (string) $dog->get('ideal_weight_kg', ''),
            ]);
        }
        return $stored;
    }
}
