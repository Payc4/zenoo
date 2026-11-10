<?php

namespace App;

/**
 * The site's languages, in the order the flags appear in the header.
 *
 * Adding one: its code here and in config/packages/translation.yaml (enabled_locales), the route
 * requirement in LanguageController, translations/messages.<code>.yaml + validators.<code>.yaml,
 * templates/legal/privacy.<code>.html.twig, public/quiz/content.<code>.js,
 * config/chatbot/{intro_questions,checkins}.<code>.yaml, the course player words in
 * LessonLayouts::ui(), the course in ImportCourseCommand::COURSES, and a flag in base.html.twig.
 */
final class Locale
{
    public const DEFAULT = 'en';

    /** Codes, in the order the flags appear. */
    public const ALL = ['en', 'lt', 'uk', 'pl'];

    /** Language code => its own name (for the flags' labels and the Course studio). */
    public const NAMES = [
        'en' => 'English',
        'lt' => 'Lietuvių',
        'uk' => 'Українська',
        'pl' => 'Polski',
    ];

    /** Language code => its name in English (for the coach's instructions). */
    public const ENGLISH_NAMES = [
        'en' => 'English',
        'lt' => 'Lithuanian',
        'uk' => 'Ukrainian',
        'pl' => 'Polish',
    ];

    /** ICU locale for dates and numbers. */
    private const INTL = ['en' => 'en_GB', 'lt' => 'lt_LT', 'uk' => 'uk_UA', 'pl' => 'pl_PL'];

    /** @return list<string> */
    public static function all(): array
    {
        return self::ALL;
    }

    public static function isSupported(mixed $locale): bool
    {
        return \is_string($locale) && \in_array($locale, self::ALL, true);
    }

    /** The code itself if the site has it, otherwise English. */
    public static function normalize(mixed $locale): string
    {
        return self::isSupported($locale) ? $locale : self::DEFAULT;
    }

    public static function intl(string $locale): string
    {
        return self::INTL[$locale] ?? self::INTL[self::DEFAULT];
    }

    /** Languages that write 1,5 rather than 1.5. */
    public static function decimalSeparator(string $locale): string
    {
        return $locale === 'en' ? '.' : ',';
    }
}
