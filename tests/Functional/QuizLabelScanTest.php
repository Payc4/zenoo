<?php

namespace App\Tests\Functional;

use App\Entity\DogProfile;
use App\Tests\Support\FakeClaude;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** The one free "let our AI read the label" try in the Bowl Score quiz. */
final class QuizLabelScanTest extends AppTestCase
{
    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->get('cache.app')->clear();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function config(string $path = '/quiz/'): array
    {
        $this->client->request('GET', $path);
        preg_match('/window\.ZQ_CONFIG = (\{.*?\});/s', $this->client->getResponse()->getContent(), $m);
        return json_decode($m[1], true);
    }

    private function photo(): UploadedFile
    {
        $img = imagecreatetruecolor(1600, 900);
        imagefill($img, 0, 0, imagecolorallocate($img, 240, 240, 230));
        $path = tempnam(sys_get_temp_dir(), 'zq') . '.jpg';
        imagejpeg($img, $path);
        $this->tmp[] = $path;
        return new UploadedFile($path, 'label.jpg', 'image/jpeg', null, true);
    }

    private function scan(string $token, string $locale = 'en'): array
    {
        $this->client->request('POST', '/quiz/label-scan', ['locale' => $locale], ['photo' => $this->photo()], ['HTTP_X_CSRF_TOKEN' => $token]);
        return [$this->client->getResponse()->getStatusCode(), json_decode($this->client->getResponse()->getContent(), true)];
    }

    private function reading(array $input): void
    {
        FakeClaude::$script[] = ['text' => '', 'tool' => ['name' => 'label_reading', 'input' => $input]];
    }

    public function testTheLabelIsReadAndTheTriesAreLimited(): void
    {
        $cfg = $this->config();
        $this->assertSame('/quiz/label-scan', $cfg['labelScanUrl']);

        $this->reading(['readable' => true, 'brand' => 'Orijen', 'product' => 'Original', 'format' => 'dry',
            'protein' => 38, 'fat' => 18, 'fibre' => 4, 'ash' => 8.5, 'moisture' => 12, 'take' => 'Lots of protein, few carbs.']);
        [$code, $data] = $this->scan($cfg['accountToken'], 'lt');
        $this->assertSame(200, $code);
        $this->assertTrue($data['readable']);
        $this->assertSame('Orijen', $data['brand']);
        $this->assertSame(['p' => 38, 'f' => 18, 'fi' => 4, 'a' => 8.5, 'm' => 12], $data['values']);
        $this->assertSame(1, $data['triesLeft']);

        // The photo goes to Claude as an image with a forced tool; the take is asked for in the quiz language.
        $req = FakeClaude::$requests[0];
        $this->assertSame('image', $req['messages'][0]['content'][0]['type']);
        $this->assertSame(['type' => 'tool', 'name' => 'label_reading'], $req['tool_choice']);
        $this->assertStringContainsString('Write "take" in Lithuanian', $req['system']);

        // Numbers that can't be right are dropped, not passed on.
        $this->reading(['readable' => false, 'protein' => 250, 'fat' => 'lots', 'take' => 'I can only see the front of the bag.']);
        [$code, $data] = $this->scan($cfg['accountToken']);
        $this->assertSame(200, $code);
        $this->assertFalse($data['readable']);
        $this->assertSame([], $data['values']);
        $this->assertSame(0, $data['triesLeft']);

        // Third try: refused without calling Claude.
        [$code, $data] = $this->scan($cfg['accountToken']);
        $this->assertSame(429, $code);
        $this->assertTrue($data['limit']);
        $this->assertCount(2, FakeClaude::$requests);
    }

    public function testBadTokenAndBadFileAreRefused(): void
    {
        $cfg = $this->config();
        [$code] = $this->scan('wrong');
        $this->assertSame(403, $code);

        $path = tempnam(sys_get_temp_dir(), 'zq') . '.txt';
        file_put_contents($path, 'not an image');
        $this->tmp[] = $path;
        $this->client->request('POST', '/quiz/label-scan', [], ['photo' => new UploadedFile($path, 'a.txt', 'text/plain', null, true)], ['HTTP_X_CSRF_TOKEN' => $cfg['accountToken']]);
        $this->assertResponseStatusCodeSame(400);
        $this->assertCount(0, FakeClaude::$requests);
    }

    public function testTheBrandFromTheQuizReachesTheDogProfile(): void
    {
        $user = $this->createUser();
        $this->client->loginUser($user);
        $cfg = $this->config();
        $this->client->request('POST', '/quiz/submit', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $cfg['accountToken']], json_encode([
            'email' => 'maria@example.com', 'first_name' => 'Maria', 'locale' => 'en',
            'properties' => QuizAccountTest::result(['quiz_label_scanned' => true, 'quiz_food_brand' => 'Orijen', 'quiz_food_product' => 'Original', 'quiz_food_format' => 'dry']),
        ]));
        $this->assertResponseIsSuccessful();
        $dog = $this->em->getRepository(DogProfile::class)->findOneBy(['user' => $user]);
        $this->assertSame('Orijen', $dog->getFood()['brand']);
        $this->assertSame('Original', $dog->getFood()['product']);

        // With the food known, the chat doesn't open with the "snap the food label" step.
        $this->client->request('GET', '/chat');
        $this->assertSelectorNotExists('#chat-onboard');
    }
}
