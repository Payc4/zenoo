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
        $this->assertSelectorTextContains('.chat-pet.is-on', 'Bella');
        $csrf = $crawler->filter('#chat')->attr('data-csrf');
        $this->client->request('POST', '/chat/send', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], json_encode(['message' => 'How much should she eat?']));
        $profile = end(FakeClaude::$requests)['system'][1]['text'];
        $this->assertStringContainsString('Name: Bella', $profile);
        $this->assertStringContainsString('Their other dog (each pet has its own chat; they switch pets at the top of the chat): Max (Beagle', $profile);

        // Max's chat: opening it from the chat selects Max.
        $crawler = $this->client->request('GET', '/chat');
        $this->client->click($crawler->filter('.chat-pet--new')->link());
        $this->client->click($this->client->getCrawler()->filter('.chat-new__pet')->link());
        $this->assertSelectorTextContains('.chat-pet.is-on', 'Max');
        $this->assertSelectorTextContains('.chat-empty h1', 'Max');
        $this->client->request('GET', '/dashboard');
        $this->assertSelectorTextContains('h1', 'Max’s dashboard');
    }

    public function testRemovingADog(): void
    {
        $user = $this->household();
        // Max and Bella each have a chat.
        foreach ($this->em->getRepository(DogProfile::class)->findAll() as $d) {
            $chat = new \App\Entity\ChatConversation($d->getUser(), $d);
            $this->em->persist($chat);
            $this->em->persist(new \App\Entity\ChatMessage($chat, 'user', 'About ' . $d->getName()));
        }
        $this->em->flush();
        $crawler = $this->client->request('GET', '/dashboard'); // Max is selected
        $this->client->submit($crawler->filter('form.dash-remove-dog')->form());
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'Max has been removed from your account.');
        $this->assertSelectorTextContains('h1', 'Bella’s dashboard');
        $this->assertSelectorNotExists('form.dash-remove-dog', 'the last dog stays');
        $this->em->clear();
        $this->assertCount(1, $this->em->getRepository(DogProfile::class)->findAll());
        $chats = $this->em->getRepository(\App\Entity\ChatConversation::class)->findAll();
        $this->assertSame(['Bella'], array_map(fn ($c) => $c->getDog()->getName(), $chats), 'Max’s chat went with Max');
        $this->assertSame(['About Bella'], array_map(fn ($m) => $m->getContent(), $this->em->getRepository(\App\Entity\ChatMessage::class)->findAll()));

        // A quiz for a new name adds a dog again.
        $this->em->getRepository(DogProfile::class)->saveQuizResult($this->em->getRepository(User::class)->find($user->getId()), QuizAccountTest::result(['quiz_dog_name' => 'Rex']), 'en');
        $this->em->flush();
        $this->assertCount(2, $this->em->getRepository(DogProfile::class)->findAll());
    }
}
