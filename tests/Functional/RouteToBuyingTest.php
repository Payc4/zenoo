<?php

namespace App\Tests\Functional;

/** Test report step 6: visitor previews (N5), link previews (M6), robots and sitemap (M7), home and sign-up (proposal 7). */
final class RouteToBuyingTest extends AppTestCase
{
    public function testRobotsAndSitemapListPublicPagesOnly(): void
    {
        $this->client->request('GET', '/robots.txt');
        $this->assertResponseIsSuccessful();
        $robots = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Disallow: /dashboard', $robots);
        $this->assertStringContainsString('Sitemap: http://localhost/sitemap.xml', $robots);

        $this->client->request('GET', '/sitemap.xml');
        $this->assertResponseIsSuccessful();
        $xml = $this->client->getResponse()->getContent();
        foreach (['http://localhost/', 'http://localhost/quiz/', 'http://localhost/how-the-bowl-score-works', 'http://localhost/privacy-policy'] as $u) {
            $this->assertStringContainsString("<loc>$u</loc>", $xml);
        }
        $this->assertStringNotContainsString('/dashboard', $xml);
        $this->assertStringNotContainsString('/admin', $xml);
    }

    public function testHomeAndQuizHaveLinkPreviews(): void
    {
        foreach (['/', '/quiz/'] as $page) {
            $crawler = $this->client->request('GET', $page);
            $this->assertSame('http://localhost/og-image.jpg', $crawler->filter('meta[property="og:image"]')->attr('content'), $page);
            $this->assertNotEmpty($crawler->filter('meta[property="og:title"]')->attr('content'));
            $this->assertNotEmpty($crawler->filter('meta[property="og:description"]')->attr('content'));
        }
        $this->assertFileExists(static::getContainer()->getParameter('kernel.project_dir') . '/public/og-image.jpg');
    }

    public function testHomeLeadsWithTheScoreAndOneAction(): void
    {
        $crawler = $this->client->request('GET', '/');
        $this->assertSelectorTextContains('h1', 'Find out if your dog is eating properly');
        $this->assertCount(1, $crawler->filter('.hero .btn'), 'one action');
        $this->assertSame('/quiz/', $crawler->filter('.hero .btn')->attr('href'));
        $this->assertSelectorExists('.hero-result');
        $this->assertStringNotContainsString('order', strtolower($crawler->filter('.hero')->text()), 'no order history pitch');
    }

    public function testSignUpIsAnEmailAndOnePassword(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $this->assertCount(1, $crawler->filter('input[type=password]'));
        $this->assertSelectorExists('input[data-show-password]');
        $this->assertSelectorNotExists('input[name="registration_form[lastName]"]');
        $this->client->submit($crawler->selectButton('Create account')->form([
            'registration_form[email]' => 'new@example.com',
            'registration_form[plainPassword]' => 'Password123',
            'registration_form[agreeTerms]' => '1',
        ]));
        $this->assertResponseRedirects();
        $this->assertSame(1, $this->em->getRepository(\App\Entity\User::class)->count(['email' => 'new@example.com']));
    }
}
