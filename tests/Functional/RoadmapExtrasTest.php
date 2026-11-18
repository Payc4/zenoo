<?php

namespace App\Tests\Functional;

use App\Entity\DogProfile;
use App\Entity\QuizSubmission;

/** Roadmap 08 (installable app), 09 (sharing), 10 (open method), 11 (vet report), 12 (leads). */
final class RoadmapExtrasTest extends AppTestCase
{
    private function bella(): DogProfile
    {
        $user = $this->createUser();
        $dog = (new DogProfile($user))->fillFromQuiz(QuizAccountTest::result(), 'en');
        $dog->addNote('health', 'Itchy paws since spring', new \DateTimeImmutable());
        $this->em->persist($dog);
        $this->em->flush();
        $this->client->loginUser($user);
        return $dog;
    }

    public function testTheSiteIsInstallable(): void
    {
        $html = $this->client->request('GET', '/')->html();
        $this->assertStringContainsString('<link rel="manifest" href="/manifest.webmanifest">', $html);
        $this->assertStringContainsString('/pwa.js', $html);
        $root = dirname(__DIR__, 2) . '/public';
        $manifest = json_decode((string) file_get_contents($root . '/manifest.webmanifest'), true);
        $this->assertSame('standalone', $manifest['display']);
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists($root . $icon['src']);
        }
        $this->assertFileExists($root . '/offline.html');
        $this->assertStringNotContainsString('/dashboard', (string) file_get_contents($root . '/sw.js'), 'the service worker never caches personal pages');
    }

    public function testTheOpenMethodPage(): void
    {
        $this->client->request('GET', '/how-the-bowl-score-works');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'How the Bowl Score works');
        $this->assertSelectorTextContains('.method-table', 'Protein');
        $this->assertSelectorTextContains('main', 'the highest possible score is 69');
        $this->assertSelectorExists('footer a[href="/how-the-bowl-score-works"]');
    }

    public function testTheVetReport(): void
    {
        $dog = $this->bella();
        $dog->addLog('stool', 4, new \DateTimeImmutable('-3 days'));
        $dog->addLog('stool', 2, new \DateTimeImmutable());
        $this->em->flush();
        $this->client->request('GET', '/dashboard/vet-report');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Bella');
        $this->assertSelectorTextContains('main', 'Labrador Retriever');
        $this->assertSelectorTextContains('main', 'Bowl Score: 52/100');
        $this->assertSelectorTextContains('main', 'Itchy paws since spring');
        $this->assertSelectorTextContains('main', 'Stool (1–7)');
        $this->assertSelectorTextContains('main', 'not a medical record');
    }

    public function testSharingTheScore(): void
    {
        $this->bella();
        $crawler = $this->client->request('GET', '/dashboard');
        $this->client->submit($crawler->filter('.dash-share form')->form());
        $crawler = $this->client->followRedirect();
        $link = $crawler->filter('.dash-share input[readonly]')->attr('value');
        $this->assertMatchesRegularExpression('~/s/[a-f0-9]{12}$~', $link);
        $path = parse_url($link, PHP_URL_PATH);

        $this->client->request('GET', '/logout');
        $crawler = $this->client->request('GET', $path);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Bella: 52/100');
        $this->assertStringContainsString($path . '.png', $crawler->filter('meta[property="og:image"]')->attr('content'));
        $this->assertSelectorExists('a[href*="utm_source=share"]');
        $this->assertSelectorTextNotContains('main', 'maria@example.com');

        $this->client->request('GET', $path . '.png');
        $this->assertResponseHeaderSame('Content-Type', 'image/png');
        $size = getimagesizefromstring($this->client->getInternalResponse()->getContent());
        $this->assertSame([1200, 630], [$size[0], $size[1]]);

        // Stop sharing: the link no longer works.
        $this->em->clear();
        $dog = $this->em->getRepository(DogProfile::class)->findOneBy([]);
        $this->client->loginUser($dog->getUser());
        $crawler = $this->client->request('GET', '/dashboard');
        $this->client->submit($crawler->filter('.dash-share form')->form());
        $this->client->request('GET', $path);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testLeadsPageAndCsv(): void
    {
        $this->bella();
        $this->em->persist(new QuizSubmission('lead@example.com', 'Ona', 'lt', QuizAccountTest::result(['quiz_dog_name' => 'Rex', 'quiz_bowl_score' => 38]) + ['_attribution' => ['first' => ['source' => 'facebook', 'medium' => 'cpc']]], bin2hex(random_bytes(32)), 'x'));
        $this->em->flush();
        $this->client->loginUser($this->createUser('admin@example.com', 'Password123', ['ROLE_ADMIN']));
        $this->client->request('GET', '/admin/leads');
        $this->assertSelectorTextContains('main', 'lead@example.com');
        $this->assertSelectorTextContains('main', 'facebook / cpc');
        $this->assertSelectorTextContains('main', 'Not confirmed');
        $this->client->request('GET', '/admin/leads?format=csv');
        $csv = $this->client->getInternalResponse()->getContent();
        $this->assertStringContainsString('lead,lead@example.com,Ona,lt,Rex', $csv);
        $this->assertStringContainsString('account,maria@example.com', $csv);
    }
}
