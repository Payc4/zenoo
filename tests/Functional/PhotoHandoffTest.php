<?php

namespace App\Tests\Functional;

use App\Entity\PhotoHandoff;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Photos from the phone camera, the webcam, or the phone through a QR code (public/photo-picker.js). */
final class PhotoHandoffTest extends AppTestCase
{
    private const HEADERS = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'ZenooPhoto'];

    private function start(int $max = 1): array
    {
        $this->client->request('POST', '/photo-handoff', [], [], self::HEADERS, json_encode(['max' => $max]));
        $this->assertResponseIsSuccessful();
        return json_decode($this->client->getResponse()->getContent(), true);
    }

    private function photo(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ph') . '.png';
        imagepng(imagecreatetruecolor(400, 300), $path);
        return new UploadedFile($path, 'IMG_0001.png', 'image/png', null, true);
    }

    /** The phone is another browser: no cookies of the computer. */
    private function fromPhone(string $method, string $url, array $files = []): array
    {
        $phone = new \Symfony\Bundle\FrameworkBundle\KernelBrowser($this->client->getKernel());
        $phone->request($method, $url, [], $files);
        return [$phone->getResponse()->getStatusCode(), $phone->getResponse()->getContent()];
    }

    public function testAVisitorSendsALabelPhotoFromTheirPhone(): void
    {
        $h = $this->start();
        $this->assertMatchesRegularExpression('~^http://localhost/p/[A-Za-z0-9_-]{20,40}$~', $h['url']);
        $path = parse_url($h['url'], PHP_URL_PATH);

        [$status, $html] = $this->fromPhone('GET', $path);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('capture="environment"', $html, 'the camera opens straight away');
        $this->assertStringContainsString('Take a photo', $html);

        $this->client->request('GET', $h['poll']);
        $this->assertSame([], json_decode($this->client->getResponse()->getContent(), true)['files']);

        [$status, $body] = $this->fromPhone('POST', $path, ['photo' => $this->photo()]);
        $this->assertSame(200, $status, $body);
        $this->assertTrue(json_decode($body, true)['full']);

        $this->client->request('GET', $h['poll']);
        $files = json_decode($this->client->getResponse()->getContent(), true)['files'];
        $this->assertCount(1, $files);
        $this->client->request('GET', $files[0]);
        $this->assertResponseHeaderSame('Content-Type', 'image/jpeg');
        $this->assertSame([400, 300], \array_slice(getimagesizefromstring($this->client->getInternalResponse()->getContent()), 0, 2));

        // Only the computer that asked can see it; the phone link takes no more photos.
        [$status] = $this->fromPhone('GET', $files[0]);
        $this->assertSame(404, $status);
        [$status] = $this->fromPhone('POST', $path, ['photo' => $this->photo()]);
        $this->assertSame(409, $status);

        // Closing deletes the photo and the link.
        $handoff = $this->em->getRepository(PhotoHandoff::class)->find($h['id']);
        $file = static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/private/handoff/' . $handoff->getFiles()[0]['file'];
        $this->assertFileExists($file);
        $this->client->request('POST', $h['close'], [], [], self::HEADERS, '{}');
        $this->assertFileDoesNotExist($file);
        $this->em->clear();
        $this->assertNull($this->em->getRepository(PhotoHandoff::class)->find($h['id']));
        [$status, $html] = $this->fromPhone('GET', $path);
        $this->assertStringContainsString('This link has ended', $html);
    }

    public function testAMemberSendsUpToThreePhotosForTheChat(): void
    {
        $this->createPetParent();
        $this->loginViaForm('maria@example.com', 'Password123');
        $h = $this->start(5);
        $this->assertSame(3, $h['max'], 'the chat takes 3 at a time');
        $path = parse_url($h['url'], PHP_URL_PATH);
        for ($i = 1; $i <= 3; $i++) {
            [$status, $body] = $this->fromPhone('POST', $path, ['photo' => $this->photo()]);
            $this->assertSame($i === 3, json_decode($body, true)['full']);
        }
        $this->client->request('GET', $h['poll']);
        $this->assertCount(3, json_decode($this->client->getResponse()->getContent(), true)['files']);

        // Another account can't read them.
        $this->client->request('GET', '/logout');
        $this->createPetParent('other@example.com');
        $this->loginViaForm('other@example.com', 'Password123');
        $this->client->request('GET', $h['poll']);
        $this->assertResponseStatusCodeSame(404);
        $this->em->clear();
        foreach ($this->em->getRepository(PhotoHandoff::class)->find($h['id'])->getFiles() as $f) {
            @unlink(static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/private/handoff/' . $f['file']);
        }
    }

    public function testLinksEndAndOnlyThePickerCanStartOne(): void
    {
        $this->client->request('POST', '/photo-handoff', ['max' => 1]);
        $this->assertResponseStatusCodeSame(400, 'a plain form post (another site) is refused');

        $h = $this->start();
        $this->em->createQuery('UPDATE App\Entity\PhotoHandoff h SET h.expiresAt = :past')->setParameter('past', new \DateTimeImmutable('-1 minute'))->execute();
        [$status, $body] = $this->fromPhone('POST', parse_url($h['url'], PHP_URL_PATH), ['photo' => $this->photo()]);
        $this->assertSame(410, $status);
        $this->assertStringContainsString('Make a new QR code', json_decode($body, true)['error']);
        $this->client->request('GET', $h['poll']);
        $this->assertTrue(json_decode($this->client->getResponse()->getContent(), true)['expired']);

        // Starting a new one clears ended ones.
        $this->start();
        $this->em->clear();
        $this->assertNull($this->em->getRepository(PhotoHandoff::class)->find($h['id']));

        [$status] = $this->fromPhone('GET', '/p/notarealtokennotarealtoken');
        $this->assertSame(200, $status);
        $this->client->request('GET', '/robots.txt');
        $this->assertStringContainsString('Disallow: /p/', $this->client->getResponse()->getContent());
    }

    public function testThePhonePageSpeaksTheComputersLanguage(): void
    {
        $this->client->request('GET', '/language/lt');
        $h = $this->start();
        [, $html] = $this->fromPhone('GET', parse_url($h['url'], PHP_URL_PATH));
        $this->assertStringContainsString('Nufotografuokite telefonu', $html);
    }

    public function testEveryPhotoButtonUsesThePicker(): void
    {
        $this->createPetParent();
        $this->loginViaForm('maria@example.com', 'Password123');
        foreach (['/chat', '/dashboard/dog', '/quiz/'] as $page) {
            $crawler = $this->client->request('GET', $page);
            $this->assertSelectorExists('#zn-photo-config', $page);
            $config = json_decode($crawler->filter('#zn-photo-config')->text(), true);
            $this->assertSame('/photo-handoff', $config['createUrl']);
            $this->assertSame('Send from your phone', $config['i18n']['phone']);
        }
        $crawler = $this->client->request('GET', '/chat');
        $this->assertSelectorExists('.chat-onboard__btn[data-attach="label"]', 'the food label prompt opens the camera on phones');
    }
}
