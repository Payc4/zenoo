<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Console\Tester\CommandTester;

/** The EN / LT selector and the Lithuanian site. */
final class LanguageTest extends AppTestCase
{
    private function lt(): void
    {
        $this->client->getCookieJar()->set(new Cookie('zenoo_lang', 'lt'));
    }

    public function testEnglishByDefaultLithuanianFromTheBrowser(): void
    {
        $this->client->request('GET', '/');
        $this->assertSelectorExists('html[lang=en]');
        $this->assertSelectorTextContains('.nav', 'Shop');
        $this->assertSelectorExists('.lang-switch a[aria-current][lang=en]');

        $this->client->request('GET', '/', [], [], ['HTTP_ACCEPT_LANGUAGE' => 'lt-LT,lt;q=0.9,en;q=0.5']);
        $this->assertSelectorExists('html[lang=lt]');
        $this->assertSelectorTextContains('.nav', 'Parduotuvė');
    }

    public function testSelectorRemembersTheChoiceAndGoesBack(): void
    {
        $this->client->request('GET', '/language/lt', [], [], ['HTTP_REFERER' => 'http://localhost/shop?x=1']);
        $this->assertResponseRedirects('/shop?x=1');
        $this->assertResponseHasCookie('zenoo_lang');
        $this->client->followRedirect();
        $this->assertSelectorExists('html[lang=lt]');

        $this->client->request('GET', '/language/en', [], [], ['HTTP_REFERER' => 'https://evil.example/phish']);
        $this->assertResponseRedirects('/', null, 'only back to this site');
        $this->client->request('GET', '/language/de');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testLoggedInChoiceIsSavedAndUsedForEmails(): void
    {
        $this->createUser();
        $this->loginViaForm('maria@example.com', 'Password123');
        $this->client->request('GET', '/language/lt');
        $this->em->clear();
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail('maria@example.com');
        $this->assertSame('lt', $user->getLocale());

        // A new browser: logging in brings the account's language back.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/logout');
        $this->loginViaForm('maria@example.com', 'Password123');
        $this->assertResponseHasCookie('zenoo_lang');
        $this->client->followRedirect();
        $this->assertSelectorExists('html[lang=lt]');
        $this->assertSelectorTextContains('.dash-head h1', 'Mano šuo');

        // Emails go out in the account's language.
        $this->client->request('GET', '/logout');
        $crawler = $this->client->request('GET', '/forgot-password');
        $this->client->submit($crawler->filter('form')->form(['email' => 'maria@example.com']));
        $this->assertEmailHeaderSame($this->getMailerMessage(), 'Subject', 'Atkurkite ZENOO slaptažodį');
        $this->assertEmailHtmlBodyContains($this->getMailerMessage(), 'Pasirinkite naują slaptažodį');
    }

    public function testLanguageChosenBeforeLoginIsKeptAndSaved(): void
    {
        $this->createUser();
        $this->lt();
        $this->loginViaForm('maria@example.com', 'Password123');
        $this->client->followRedirect();
        $this->assertSelectorExists('html[lang=lt]');
        $this->em->clear();
        $this->assertSame('lt', static::getContainer()->get(UserRepository::class)->findOneByEmail('maria@example.com')->getLocale());
    }

    public function testLithuanianPagesAreFullyTranslated(): void
    {
        (new CommandTester((new Application(static::$kernel))->find('app:course:import')))->execute(['--publish' => true]);
        $this->createUser();
        $this->lt();
        $this->client->request('GET', '/login');
        $this->assertSelectorTextContains('h1', 'Sveiki sugrįžę');
        $this->loginViaForm('maria@example.com', 'Password123');

        foreach (['/', '/dashboard', '/account', '/shop', '/courses', '/chat', '/privacy-policy', '/register'] as $url) {
            if ($url === '/register') {
                $this->client->request('GET', '/logout');
            }
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful($url);
            $html = $this->client->getResponse()->getContent();
            $this->assertStringNotContainsString('{{', $html, "$url has no raw Twig");
            $this->assertStringContainsString('<html lang="lt">', $html, $url);
            foreach (['Log out', 'My account', 'Privacy policy', 'Real Food. Healthy Pet.'] as $english) {
                $this->assertStringNotContainsString('>' . $english . '<', $html, "$url: \"$english\" is translated");
            }
        }
    }

    public function testLithuanianCourseQuizAndChat(): void
    {
        (new CommandTester((new Application(static::$kernel))->find('app:course:import')))->execute(['--publish' => true]);
        $this->createUser();
        $this->loginViaForm('maria@example.com', 'Password123');
        $this->lt();

        $crawler = $this->client->request('GET', '/courses');
        $this->assertSame(['ZENOO šunų sveikatos kursas'], $crawler->filter('.cl-course__title')->each(fn ($n) => trim(preg_replace('/\s+(Draft|Juodraštis|LT)\b.*/u', '', $n->text()))));

        $this->client->request('GET', '/quiz/');
        $this->assertStringContainsString('/quiz/content.lt.js', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('"locale":"lt"', $this->client->getResponse()->getContent());
        $this->assertStringContainsString('"sendToKlaviyo":false', $this->client->getResponse()->getContent(), 'no LT Klaviyo account set');

        $crawler = $this->client->request('GET', '/chat');
        $this->assertSame('Kaip vardu jūsų šuo?', json_decode($crawler->filter('#chat-cards')->text(), true)['questions'][0]['question']);
        $this->assertStringContainsString('Praleisti', $crawler->filter('#chat')->attr('data-i18n'));

        $crawler = $this->client->request('GET', '/account');
        $this->assertStringContainsString('narys nuo ' . (new \DateTimeImmutable())->format('Y') . ' m.', $crawler->filter('.account-head p')->text());
    }
}
