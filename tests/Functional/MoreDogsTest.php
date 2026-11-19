<?php

namespace App\Tests\Functional;

use App\Entity\DogProfile;
use App\Entity\User;
use App\Tests\Support\FakeClaude;

/** Roadmap 07: several dogs in one account. */
final class MoreDogsTest extends AppTestCase
{
    private function household(): User
    {
        $user = $this->createUser();
        $bella = (new DogProfile($user))->fillFromQuiz(QuizAccountTest::result(), 'en');
        $this->em->persist($bella);
        $this->em->flush();
        $this->em->getRepository(DogProfile::class)->saveQuizResult($user, QuizAccountTest::result(['quiz_dog_name' => 'Max', 'quiz_breed' => 'Beagle', 'quiz_bowl_score' => 70]), 'en');
        $this->em->flush();
        $this->client->loginUser($user);
        return $user;
    }

    public function testSwitchingBetweenDogs(): void
    {
        $this->household();
        $crawler = $this->client->request('GET', '/dashboard');
        $this->assertSelectorTextContains('h1', 'Max’s dashboard', 'the newest dog is selected');
        $this->assertSelectorTextContains('.dash-dogs .is-current', 'Max');
        $this->assertSelectorExists('a.dash-add-dog[href="/quiz/?new=1"]');

        $this->client->submit($crawler->filter('.dash-dogs form')->form());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('h1', 'Bella’s dashboard');

        // The chat is about the selected dog, and the coach knows about the other one.
        $crawler = $this->client->request('GET', '/chat');
        $this->assertSelectorTextContains('.chat-dogs strong', 'Bella');
        $csrf = $crawler->filter('#chat')->attr('data-csrf');
        $this->client->request('POST', '/chat/send', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], json_encode(['message' => 'How much should she eat?']));
        $profile = end(FakeClaude::$requests)['system'][1]['text'];
        $this->assertStringContainsString('Name: Bella', $profile);
        $this->assertStringContainsString('Their other dog (they can switch the chat to them at the top of the chat): Max (Beagle', $profile);

        // Switching from the chat comes back to the chat.
        $crawler = $this->client->request('GET', '/chat');
        $this->client->submit($crawler->filter('.chat-dogs form')->form());
        $this->assertResponseRedirects('/chat');
    }

    public function testRemovingADog(): void
    {
        $user = $this->household();
        $crawler = $this->client->request('GET', '/dashboard'); // Max is selected
        $this->client->submit($crawler->filter('form.dash-remove-dog')->form());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'Max has been removed from your account.');
        $this->assertSelectorTextContains('h1', 'Bella’s dashboard');
        $this->assertSelectorNotExists('form.dash-remove-dog', 'the last dog stays');
        $this->em->clear();
        $this->assertCount(1, $this->em->getRepository(DogProfile::class)->findAll());

        // A quiz for a new name adds a dog again.
        $this->em->getRepository(DogProfile::class)->saveQuizResult($this->em->getRepository(User::class)->find($user->getId()), QuizAccountTest::result(['quiz_dog_name' => 'Rex']), 'en');
        $this->em->flush();
        $this->assertCount(2, $this->em->getRepository(DogProfile::class)->findAll());
    }
}
