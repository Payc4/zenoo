<?php

namespace App\Tests\Functional;

use App\Chat\ChatAgent;
use App\Entity\ChatMessage;
use App\Entity\DogProfile;
use App\Entity\User;
use App\Tests\Support\FakeClaude;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Photos and PDFs in the coach chat, the save_food tool, and the "finish the profile" steps. */
final class ChatPhotoTest extends AppTestCase
{
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        $root = static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads';
        foreach (array_merge(glob($root . '/private/chat/*/*') ?: [], glob($root . '/dogs/dog_*') ?: []) as $f) {
            if (filemtime($f) >= time() - 300) {
                @unlink($f);
            }
        }
        parent::tearDown();
    }

    private function image(string $name = 'label.png', int $w = 2400, int $h = 1200): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 120, 40));
        $path = tempnam(sys_get_temp_dir(), 'zq') . '.png';
        imagepng($img, $path);
        $this->tmp[] = $path;
        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private function userWithDog(bool $food = false, array $roles = []): User
    {
        $user = $this->createUser('maria@example.com', 'Password123', $roles);
        $dog = (new DogProfile($user))->fillFromQuiz(QuizAccountTest::result(), 'en');
        if ($food) {
            $dog->setFood('Orijen', 'Original', 'dry', '', 'chat', new \DateTimeImmutable());
        }
        $this->em->persist($dog);
        $this->em->flush();
        $this->client->loginUser($user);
        return $user;
    }

    private function csrf(): string
    {
        return $this->client->request('GET', '/chat')->filter('#chat')->attr('data-csrf');
    }

    private function sendWithFiles(string $csrf, string $text, array $files): string
    {
        $this->client->request('POST', '/chat/send', ['message' => $text], ['files' => $files], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        return (string) $this->client->getInternalResponse()->getContent();
    }

    private function dog(): DogProfile
    {
        $this->em->clear();
        return $this->em->getRepository(DogProfile::class)->findOneBy([]);
    }

    public function testPhotoGoesToClaudeIsStoredPrivatelyAndTheFoodIsSaved(): void
    {
        $this->userWithDog();
        $csrf = $this->csrf();
        FakeClaude::$script = [
            ['text' => 'That looks like **Orijen Six Fish**, a dry food.', 'tool' => ['name' => 'save_food', 'input' => ['brand' => 'Orijen', 'product' => 'Six Fish', 'format' => 'dry', 'notes' => 'protein 38%, fat 18%']]],
            ['text' => 'Saved. It is high in protein; next, weigh each meal.'],
        ];
        $body = $this->sendWithFiles($csrf, 'Is this good?', [$this->image()]);

        // Two requests: the reply, then the follow-up after save_food.
        $this->assertCount(2, FakeClaude::$requests);
        $content = FakeClaude::$requests[0]['messages'][0]['content'];
        $this->assertSame('image', $content[0]['type']);
        $this->assertSame('image/jpeg', $content[0]['source']['media_type']);
        [$w, $h] = getimagesizefromstring(base64_decode($content[0]['source']['data']));
        $this->assertSame([1568, 784], [$w, $h], 'shrunk to Claude\'s size, upright and in proportion');
        $this->assertSame(['type' => 'text', 'text' => 'Is this good?'], $content[1]);
        $this->assertContains('save_food', array_column(FakeClaude::$requests[0]['tools'], 'name'));
        $followUp = FakeClaude::$requests[1]['messages'];
        $this->assertSame('tool_use', end($followUp)['role'] === 'user' ? $followUp[\count($followUp) - 2]['content'][1]['type'] : null);
        $this->assertSame('tool_result', end($followUp)['content'][0]['type']);

        $this->assertStringContainsString('event: note', $body);
        $this->assertStringContainsString('Orijen Six Fish', $body);
        $food = $this->dog()->getFood();
        $this->assertSame(['Orijen', 'Six Fish', 'dry', 'chat'], [$food['brand'], $food['product'], $food['format'], $food['source']]);

        $messages = $this->em->getRepository(ChatMessage::class)->findBy([], ['id' => 'ASC']);
        $this->assertStringContainsString("a dry food.\n\nSaved.", $messages[1]->getContent());
        $file = $messages[0]->getAttachments()[0];
        $this->assertSame(['image/jpeg', 'label.png'], [$file['type'], $file['name']]);

        // Served to its owner only, never straight from /uploads.
        $this->client->request('GET', '/chat/file/' . $messages[0]->getId() . '/0');
        $this->assertResponseIsSuccessful();
        $this->assertSame('image/jpeg', $this->client->getResponse()->headers->get('Content-Type'));
        $this->assertStringContainsString('private', $this->client->getResponse()->headers->get('Cache-Control'));
        $chat = $this->client->request('GET', '/chat');
        $this->assertCount(1, $chat->filter('.chat-files img'));

        $this->client->loginUser($this->createUser('other@example.com'));
        $this->client->request('GET', '/chat/file/' . $messages[0]->getId() . '/0');
        $this->assertResponseStatusCodeSame(404);
        $this->client->loginUser($this->createUser('admin@example.com', 'Password123', ['ROLE_ADMIN']));
        $this->client->request('GET', '/chat/file/' . $messages[0]->getId() . '/0');
        $this->assertResponseIsSuccessful();
        $admin = $this->client->request('GET', '/admin/conversations/' . $messages[0]->getConversation()->getId());
        $this->assertCount(1, $admin->filter('.cv-files img'));
        $this->assertStringContainsString('Food: Orijen Six Fish', $admin->filter('.cv-side')->text());
    }

    public function testOnlyTheNewestPhotosAreSentAgainAndBadFilesAreRefused(): void
    {
        $this->userWithDog(true, ['ROLE_ADMIN']); // no daily message limit
        $csrf = $this->csrf();
        foreach (['one', 'two', 'three'] as $text) {
            $this->sendWithFiles($csrf, $text, [$this->image("$text.png", 300, 300)]);
        }
        $messages = end(FakeClaude::$requests)['messages'];
        $this->assertSame('one' . "\n[Earlier they sent: photo one.png. You can't see it any more; go by what was said about it.]", $messages[0]['content']);
        $this->assertSame(ChatAgent::FILE_MESSAGES, \count(array_filter($messages, fn ($m) => \is_array($m['content']) && $m['content'][0]['type'] === 'image')));

        // A file that isn't a photo or PDF doesn't count as a message.
        $path = tempnam(sys_get_temp_dir(), 'zq');
        file_put_contents($path, 'hello');
        $this->tmp[] = $path;
        $requests = \count(FakeClaude::$requests);
        $this->sendWithFiles($csrf, 'look', [new UploadedFile($path, 'notes.txt', 'text/plain', null, true)]);
        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('Please send a photo', $this->client->getResponse()->getContent());
        $this->assertCount($requests, FakeClaude::$requests);

        // A PDF goes to Claude as a document.
        $pdf = tempnam(sys_get_temp_dir(), 'zq');
        file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $this->tmp[] = $pdf;
        $this->sendWithFiles($csrf, '', [new UploadedFile($pdf, 'vet report.pdf', 'application/pdf', null, true)]);
        $last = end(end(FakeClaude::$requests)['messages']);
        $this->assertSame('document', $last['content'][0]['type']);
        $this->assertSame('(They sent this without a message.)', end($last['content'])['text']);

        // Clearing the chat deletes the files too.
        $dir = static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/private/chat/' . $this->dog()->getUser()->getId();
        $this->assertCount(4, glob($dir . '/*'));
        $crawler = $this->client->request('GET', '/chat');
        $this->client->submit($crawler->filter('form[action="/chat/clear"]')->form());
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testFirstStepsAfterSignUpAskForTheLabelAndAPhoto(): void
    {
        $this->userWithDog();
        $chat = $this->client->request('GET', '/chat');
        $this->assertSelectorExists('#chat-onboard [data-attach]');
        $this->assertSelectorTextContains('#chat-onboard', 'Let’s start with what Bella eats.');
        $this->assertStringContainsString('Food brand: not known yet', static::getContainer()->get(\App\Chat\CourseKnowledge::class)->dog($this->dog()->getUser()));

        $dash = $this->client->request('GET', '/dashboard');
        $this->assertSelectorTextContains('.dash-finish', 'Finish Bella’s profile');
        $this->assertSelectorExists('.dash-coach__photo[href="/chat?photo=1"]');
        $form = $dash->filter('.dash-steps form')->form();
        $form['photo']->upload($this->image('bella.png', 1200, 800)->getPathname());
        $this->client->submit($form);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-success', 'Lovely! The photo is saved.');
        $photo = $this->dog()->getPhoto();
        $this->assertMatchesRegularExpression('/^dog_\d+_[a-f0-9]+\.jpg$/', $photo);
        $this->assertSelectorExists('img.dash-photo[src="/uploads/dogs/' . $photo . '"]');
        $this->assertSame([600, 600], \array_slice(getimagesize(static::getContainer()->getParameter('kernel.project_dir') . '/public/uploads/dogs/' . $photo), 0, 2));

        // Retaking the quiz keeps the photo and the food the coach saved.
        $dog = $this->dog()->setFood('Acana', '', 'dry', '', 'chat', new \DateTimeImmutable());
        $dog->fillFromQuiz(QuizAccountTest::result(), 'en');
        $this->assertSame([$photo, 'Acana'], [$dog->getPhoto(), $dog->getFood()['brand']]);
        $this->em->flush();
        $this->client->request('GET', '/dashboard');
        $this->assertSelectorNotExists('.dash-finish');
        $this->client->request('GET', '/chat');
        $this->assertSelectorNotExists('#chat-onboard');
    }
}
