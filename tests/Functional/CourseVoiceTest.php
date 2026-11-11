<?php

namespace App\Tests\Functional;

use App\Course\VoiceGenerator;
use App\Entity\Course;
use App\Entity\Lesson;
use App\Tests\Support\FakeStripe;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** Lesson voices made with ElevenLabs (src/Course/VoiceGenerator.php, CourseVoices, app:course:voice). */
final class CourseVoiceTest extends AppTestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir') . '/public';
        foreach (FakeStripe::$elevenlabs ? glob($dir . '/uploads/lesson-audio/*-lesson-*.mp3') : [] as $f) {
            if (filemtime($f) >= time() - 120 && str_contains((string) file_get_contents($f), 'ID3fake')) {
                unlink($f);
            }
        }
        parent::tearDown();
    }

    private function command(string $name, array $args = []): CommandTester
    {
        $t = new CommandTester((new Application(static::$kernel))->find($name));
        $t->execute($args);
        return $t;
    }

    private function listen(Lesson $lesson): array
    {
        return array_values(array_filter($lesson->getSlides(), fn ($s) => $s['layout'] === 'listen'))[0];
    }

    private function course(string $locale): Course
    {
        $this->em->clear();
        return $this->em->getRepository(Course::class)->findOneBy(['locale' => $locale]);
    }

    public function testScriptIsSpokenWithoutMarkdown(): void
    {
        $this->assertSame("Hello there.\n\nFirst: shape.\nSecond: colour.", VoiceGenerator::speechText("**Hello** there.\n\n- *First*: shape.\n2. Second: colour."));
    }

    public function testCommandMakesTheMissingVoicesInTheCoursesLanguage(): void
    {
        $this->command('app:course:import');
        $out = $this->command('app:course:voice', ['--dry-run' => true, '--lang' => 'pl'])->getDisplay();
        $this->assertStringContainsString('30 voices', $out);
        $this->assertSame([], FakeStripe::$elevenlabs, 'a dry run calls nothing');

        $t = $this->command('app:course:voice', ['--lang' => 'uk', '--lesson' => '1']);
        $this->assertSame(0, $t->getStatusCode(), $t->getDisplay());
        $this->assertCount(1, FakeStripe::$elevenlabs);
        $call = FakeStripe::$elevenlabs[0];
        $this->assertStringContainsString('/v1/text-to-speech/voice123?output_format=mp3_44100_128', $call['url']);
        $this->assertSame('eleven_v3', $call['body']['model_id']);
        $this->assertSame('uk', $call['body']['language_code']);
        $this->assertStringNotContainsString('**', $call['body']['text']);
        $this->assertContains('xi-api-key: el_test_dummy', $call['headers']);

        $slide = $this->listen($this->course('uk')->getLessons()->first());
        $this->assertMatchesRegularExpression('#^/uploads/lesson-audio/uk-.+-lesson-01-[0-9a-f]{6}\.mp3$#', $slide['fields']['audio']);
        $this->assertTrue($slide['fields']['ai_voice']);
        $this->assertFileExists(static::getContainer()->getParameter('kernel.project_dir') . '/public' . $slide['fields']['audio']);

        // Done once: a second run of the same lesson has nothing to do.
        $this->assertStringContainsString('Nothing to make', $this->command('app:course:voice', ['--lang' => 'uk', '--lesson' => '1'])->getDisplay());
    }

    public function testStudioGeneratesVoicesAndLearnersSeeTheAiNote(): void
    {
        $this->command('app:course:import', ['--publish' => true]);
        $this->createUser('admin@example.com', 'Password123', ['ROLE_ADMIN']);
        $this->loginViaForm('admin@example.com', 'Password123');

        $crawler = $this->client->request('GET', '/admin/course-studio');
        $box = $crawler->filter('.cs-voices')->first();
        $this->assertStringContainsString('30 Listen screens have no audio yet', $box->text());
        $url = $box->attr('data-voice-batch-url-value');
        $token = $box->attr('data-voice-batch-csrf-value');

        // The page asks for one voice at a time until none are left.
        $this->client->request('POST', $url, [], [], ['HTTP_X_CSRF_TOKEN' => $token]);
        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame(29, $data['remaining']);
        $this->assertStringStartsWith('/uploads/lesson-audio/', $data['url']);

        // The lesson editor: a voice for the script on screen.
        $en = $this->course('en');
        $lesson = $en->getLessons()->get(1);
        $crawler = $this->client->request('GET', '/admin/course-studio/lesson/' . $lesson->getId());
        $voiceUrl = $crawler->filter('[data-course-editor-voice-url-value]')->attr('data-course-editor-voice-url-value');
        $this->assertNotEmpty($voiceUrl);
        $this->client->request('POST', $voiceUrl, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], json_encode(['script' => 'Hi, I am Agota.']));
        $this->assertResponseIsSuccessful();
        $this->assertSame('Hi, I am Agota.', end(FakeStripe::$elevenlabs)['body']['text']);

        // ElevenLabs' own explanation comes back when it refuses.
        FakeStripe::$elevenlabsError = 'Invalid API key';
        $this->client->request('POST', $voiceUrl, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], json_encode(['script' => 'Hi']));
        $this->assertResponseStatusCodeSame(502);
        $this->assertStringContainsString('Invalid API key (check ELEVENLABS_API_KEY)', $this->client->getResponse()->getContent());

        // Learners hear it, with a short note that the voice is AI-made.
        $first = $this->course('en')->getLessons()->first();
        $crawler = $this->client->request('GET', '/courses/' . $first->getCourse()->getSlug() . '/lesson/' . $first->getId());
        $this->assertCount(1, $crawler->filter('.lp-audio audio'));
        $this->assertSelectorTextContains('.lp-audio__ai', 'Voice generated with AI.');
    }
}
