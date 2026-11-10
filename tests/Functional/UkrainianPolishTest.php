<?php

namespace App\Tests\Functional;

use App\Chat\CourseKnowledge;
use App\Command\ImportCourseCommand;
use App\Course\LessonLayouts;
use App\Entity\Course;
use App\Locale;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Ukrainian and Polish: every part of the site works in them, like Lithuanian (LanguageTest). */
final class UkrainianPolishTest extends AppTestCase
{
    public static function languages(): array
    {
        return ['Ukrainian' => ['uk'], 'Polish' => ['pl']];
    }

    private function t(string $message, string $locale, array $params = []): string
    {
        return static::getContainer()->get(TranslatorInterface::class)->trans($message, $params, null, $locale);
    }

    private function use(string $locale): void
    {
        $this->client->getCookieJar()->set(new Cookie('zenoo_lang', $locale));
    }

    public function testEveryLanguageHasAllItsFiles(): void
    {
        $root = static::getContainer()->getParameter('kernel.project_dir');
        $keys = fn (string $file) => array_keys(Yaml::parseFile("$root/translations/$file"));
        foreach (array_diff(Locale::ALL, ['en']) as $l) {
            $this->assertSame($keys('messages.lt.yaml'), array_values(array_intersect($keys('messages.lt.yaml'), $keys("messages.$l.yaml"))), "messages.$l.yaml has every message");
            $this->assertEqualsCanonicalizing($keys('validators.lt.yaml'), $keys("validators.$l.yaml"));
            foreach (["templates/legal/privacy.$l.html.twig", "public/quiz/content.$l.js", "config/chatbot/intro_questions.$l.yaml", "config/chatbot/checkins.$l.yaml"] as $f) {
                $this->assertFileExists("$root/$f");
            }
            $this->assertArrayHasKey($l, ImportCourseCommand::COURSES);
            $this->assertNotSame(LessonLayouts::ui('en'), LessonLayouts::ui($l), "$l player words");
        }
    }

    public function testBrowserLanguageAndTheLithuanianAddress(): void
    {
        $this->client->request('GET', '/', [], [], ['HTTP_ACCEPT_LANGUAGE' => 'uk-UA,uk;q=0.9,en;q=0.5']);
        $this->assertSelectorExists('html[lang=uk]');
        $this->assertSelectorTextContains('.nav', $this->t('Shop', 'uk'));
        $this->assertSelectorExists('.lang-switch__menu a[aria-current][lang=uk]');
        $this->assertCount(4, $this->client->getCrawler()->filter('.lang-switch__menu a'));

        $this->client->request('GET', '/', [], [], ['HTTP_ACCEPT_LANGUAGE' => 'pl-PL,pl;q=0.9']);
        $this->assertSelectorExists('html[lang=pl]');

        // app.zenoo.lt: Lithuanian, unless the browser asks for Ukrainian or Polish.
        $this->client->request('GET', '/', [], [], ['HTTP_HOST' => 'app.zenoo.lt', 'HTTP_ACCEPT_LANGUAGE' => 'en-GB,en']);
        $this->assertSelectorExists('html[lang=lt]');
        $this->client->request('GET', '/', [], [], ['HTTP_HOST' => 'app.zenoo.lt', 'HTTP_ACCEPT_LANGUAGE' => 'pl-PL,pl;q=0.9,en;q=0.5']);
        $this->assertSelectorExists('html[lang=pl]');
    }

    /** @dataProvider languages */
    public function testFlagAndLinkSwitchToTheLanguage(string $l): void
    {
        $this->client->request('GET', "/language/$l", [], [], ['HTTP_REFERER' => 'http://localhost/shop']);
        $this->assertResponseRedirects('/shop');
        $this->client->followRedirect();
        $this->assertSelectorExists("html[lang=$l]");

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', "/?lang=$l");
        $this->assertSelectorExists("html[lang=$l]");
        $this->assertSame($l, $this->client->getCookieJar()->get('zenoo_lang')?->getValue());
    }

    /** @dataProvider languages */
    public function testPagesAreFullyTranslated(string $l): void
    {
        (new CommandTester((new Application(static::$kernel))->find('app:course:import')))->execute(['--publish' => true]);
        $this->createUser();
        $this->use($l);
        $this->client->request('GET', '/login');
        $this->assertSelectorTextContains('h1', $this->t('Welcome back', $l));
        $this->loginViaForm('maria@example.com', 'Password123');

        foreach (['/', '/dashboard', '/account', '/shop', '/courses', '/chat', '/privacy-policy', '/register'] as $url) {
            if ($url === '/register') {
                $this->client->request('GET', '/logout');
            }
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful($url);
            $html = $this->client->getResponse()->getContent();
            $this->assertStringNotContainsString('{{', $html, "$url has no raw Twig");
            $this->assertStringContainsString("<html lang=\"$l\">", $html, $url);
            foreach (['Log out', 'My account', 'Privacy policy', 'Real Food. Healthy Pet.'] as $english) {
                $this->assertStringNotContainsString('>' . $english . '<', $html, "$url: \"$english\" is translated");
            }
        }
    }

    /** @dataProvider languages */
    public function testCourseQuizChatAndEmailsInTheLanguage(string $l): void
    {
        (new CommandTester((new Application(static::$kernel))->find('app:course:import')))->execute(['--publish' => true]);
        $user = $this->createUser()->setLocale($l);
        $this->em->flush();
        $this->loginViaForm('maria@example.com', 'Password123');
        $this->use($l);

        // The course in this language only, with its own player words.
        $crawler = $this->client->request('GET', '/courses');
        $titles = $crawler->filter('.cl-course__title')->each(fn ($n) => $n->text());
        $this->assertCount(1, $titles);
        $this->assertStringStartsWith(ImportCourseCommand::COURSES[$l]['title'], $titles[0]);
        $course = $this->em->getRepository(Course::class)->findOneBy(['slug' => ImportCourseCommand::COURSES[$l]['slug']]);
        $this->client->request('GET', '/courses/' . $course->getSlug() . '/lesson/' . $course->getLessons()->first()->getId());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists("html[lang=$l]");
        $this->assertSelectorTextContains('.lp-slide--hook .lp-foot .lp-btn', LessonLayouts::ui($l)['continue']);

        // Quiz copy in this language; no Klaviyo list (the site emails the confirmation link itself).
        $this->client->request('GET', '/quiz/');
        $html = $this->client->getResponse()->getContent();
        $this->assertStringContainsString("/quiz/content.$l.js", $html);
        $this->assertStringContainsString("\"locale\":\"$l\"", $html);
        $this->assertStringContainsString('"sendToKlaviyo":false', $html);

        // Chat: the first intro question and the instruction to answer in this language.
        $crawler = $this->client->request('GET', '/chat');
        $intro = Yaml::parseFile(static::getContainer()->getParameter('kernel.project_dir') . "/config/chatbot/intro_questions.$l.yaml");
        $this->assertSame($intro['questions'][0]['question'], json_decode($crawler->filter('#chat-cards')->text(), true)['questions'][0]['question']);
        $this->assertStringContainsString('reply in ' . Locale::ENGLISH_NAMES[$l], static::getContainer()->get(CourseKnowledge::class)->language($user));

        // Emails (SMTP in tests) in the account's language.
        $this->client->request('GET', '/logout');
        $crawler = $this->client->request('GET', '/forgot-password');
        $this->client->submit($crawler->filter('form')->form(['email' => 'maria@example.com']));
        $subject = $this->t('Reset your ZENOO password', $l);
        $this->assertNotSame('Reset your ZENOO password', $subject);
        $this->assertEmailHeaderSame($this->getMailerMessage(), 'Subject', $subject);
        $this->assertEmailHtmlBodyContains($this->getMailerMessage(), $this->t('Choose a new password', $l));
    }
}
