<?php

namespace App\Twig;

use App\Entity\DogProfile;
use App\Quiz\QuizTexts;
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
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly QuizTexts $texts,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('dog_text', $this->text(...)),
            // A dog saved too early to be re-scored: a retake brings it up to date.
            new TwigFunction('dog_needs_recheck', fn (DogProfile $dog) => $dog->getBowlScore() > 0 && \App\Quiz\ScoreInputs::fromDog($dog) === null),
        ];
    }

    public function text(DogProfile $dog, string $field): string
    {
        $stored = (string) $dog->get($field, '');
        $locale = $this->translator instanceof LocaleAwareInterface ? $this->translator->getLocale() : 'en';
        if ($dog->getLocale() === $locale || ($stored === '' && $field !== 'score_title')) {
            return $stored;
        }
        $c = $this->texts->codes($locale);
        if ($c === null) {
            return $stored;
        }
        // The plan lines, written from the plan's data in the page's language.
        if (str_starts_with($field, 'plan_') && \is_array($plan = $dog->get('plan'))) {
            return (string) ($this->texts->plan($plan, $dog->getName(), $locale)[$field] ?? $stored);
        }
        if ($field === 'score_title') {
            return $c['bands'][$dog->band()] ?? $stored;
        }
        // A re-scored dog keeps which variant each line is ("routine.fixVet"): use the same one.
        if (preg_match('/^fix_(\d)_text$/', $field, $m) && ($code = ((array) $dog->get('fix_codes', []))[(int) $m[1] - 1] ?? null)) {
            return $this->texts->fixText((string) $code, $dog->getName(), $locale, ['kcal' => (string) round((float) $dog->get('kcal_target'))]) ?? $stored;
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
