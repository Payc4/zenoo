<?php

namespace App\Tests\Functional;

use App\Entity\ChatConversation;
use App\Entity\ChatMessage;
use App\Entity\ChatUsage;
use App\Tests\Support\FakeClaude;

/** CHAT_DAILY_LIMIT is 2 in .env.test so the limit is quick to reach. */
class ChatTest extends AppTestCase
{
    private function csrf(): string
    {
        $crawler = $this->client->request('GET', '/chat');
        $this->assertResponseIsSuccessful();

        return (string) $crawler->filter('#chat')->attr('data-csrf');
    }

    /** @return array{0: int, 1: string} status code and the streamed body */
    private function send(string $message, ?string $csrf = null, array $extra = []): array
    {
        $this->client->request('POST', '/chat/send', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf ?? $this->csrf(),
        ], json_encode(['message' => $message] + $extra));

        return [$this->client->getResponse()->getStatusCode(), (string) $this->client->getInternalResponse()->getContent()];
    }

    private function rows(string $class): int
    {
        $this->em->clear();

        return $this->em->getRepository($class)->count([]);
    }

    public function testChatNeedsLogin(): void
    {
        // A visitor sees a preview with two ways in (N5); everything else needs a login.
        $this->client->request('GET', '/chat');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.preview a[href="/quiz/"]');
        $this->assertSelectorExists('.preview a[href="/login"]');
        $this->client->request('GET', '/chat/new');
        $this->assertResponseRedirects('/login');
        $this->client->request('POST', '/chat/send', [], [], ['CONTENT_TYPE' => 'application/json'], '{"message":"hi"}');
        $this->assertSame(0, $this->rows(ChatMessage::class));
        $this->assertCount(0, FakeClaude::$requests);
    }

    public function testChatTabIsInTheMenu(): void
    {
        $this->client->request('GET', '/');
        $this->assertSelectorExists('#site-nav a[href="/chat"]');
    }

    public function testSendStreamsTheReplyAndSavesTheTurn(): void
    {
        $this->createPetParent();
        $this->loginViaForm('maria@example.com', 'Password123');

        [$status, $body] = $this->send('How much should my dog eat?');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('event: meta', $body);
        $this->assertStringNotContainsString('conversationId', $body);
        $this->assertStringContainsString('event: delta', $body);
        $this->assertStringContainsString('event: done', $body);
        $this->assertStringContainsString('"remaining":1', $body);

        $this->assertSame(1, $this->rows(ChatConversation::class));
        $messages = $this->em->getRepository(ChatMessage::class)->findBy([], ['id' => 'ASC']);
        $this->assertSame(['user', 'assistant'], array_map(fn ($m) => $m->getRole(), $messages));
        $this->assertSame('Hello! **Weigh** the food.', $messages[1]->getContent());
        $this->assertSame([300, 42], [$messages[1]->getInputTokens(), $messages[1]->getOutputTokens()]);

        $request = FakeClaude::$requests[0];
        $this->assertSame('claude-opus-5-5', $request['model']);
        $this->assertTrue($request['stream']);
        $this->assertCount(2, $request['system'], 'shared (cached) block + personal block');
        $this->assertSame(['type' => 'ephemeral'], $request['system'][0]['cache_control']);
        $this->assertStringNotContainsString('{{', $request['system'][0]['text'].$request['system'][1]['text'], 'every placeholder is filled in');
        $this->assertStringContainsString('talking with Maria', $request['system'][1]['text'], 'the personal block names the pet parent');
        $this->assertStringContainsString('not started the course', $request['system'][1]['text']);
        $this->assertSame([['role' => 'user', 'content' => 'How much should my dog eat?']], $request['messages']);

        // The chat page shows the reply as formatted, escaped HTML.
        $this->client->request('GET', '/chat');
        $this->assertSelectorTextContains('.chat-answer strong', 'Weigh');
    }

    public function testEachPetHasExactlyOneChat(): void
    {
        $user = $this->createUser();
        $bella = $this->createDog($user);
        $max = $this->createDog($user, ['quiz_dog_name' => 'Max']);
        $user->setActiveDogId($bella->getId()); // the chat follows the selected pet
        $this->em->flush();
        $this->loginViaForm('maria@example.com', 'Password123');
        $this->send('First question');
        // Even if a browser sends an old-style conversation id, it lands in the same chat.
        $this->send('Second question', null, ['conversationId' => 999]);

        $this->assertSame(1, $this->rows(ChatConversation::class));
        $this->assertSame(['user', 'assistant', 'user'], array_column(FakeClaude::$requests[1]['messages'], 'role'), 'the follow-up includes the history');

        $crawler = $this->client->request('GET', '/chat');
        $this->assertCount(4, $crawler->filter('.chat-msg'));
        $this->assertSame(['Bella', '+ New chat'], $crawler->filter('.chat-pets a')->each(fn ($a) => trim($a->text())), 'Max has no chat yet');

        // New chat offers Max (no chat yet), not Bella, plus a new pet through the quiz.
        $crawler = $this->client->request('GET', '/chat/new');
        $this->assertSame(['/chat?pet=' . $max->getId()], $crawler->filter('.chat-new__pet')->each(fn ($a) => $a->attr('href')));
        $this->assertSelectorExists('a[href="/quiz/?new=1&then=chat"]');

        // Max's chat is separate and about Max.
        $crawler = $this->client->request('GET', '/chat?pet=' . $max->getId());
        $this->assertCount(0, $crawler->filter('.chat-msg'));
        $this->assertSelectorTextContains('.chat-pet.is-on', 'Max');
        $this->em->createQuery('DELETE FROM App\Entity\ChatUsage u')->execute(); // the test limit is 2 a day
        $this->client->request('POST', '/chat/send?pet=' . $max->getId(), [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => (string) $crawler->filter('#chat')->attr('data-csrf'),
        ], json_encode(['message' => 'About Max']));
        $this->assertSame(2, $this->rows(ChatConversation::class));
        $this->assertSame([['role' => 'user', 'content' => 'About Max']], FakeClaude::$requests[2]['messages'], 'Bella’s chat isn’t mixed in');
        $this->assertStringContainsString('Name: Max', FakeClaude::$requests[2]['system'][1]['text'], 'the coach gets Max’s profile');

        // Both pets now have a chat: New chat only offers a new pet.
        $crawler = $this->client->request('GET', '/chat/new');
        $this->assertCount(0, $crawler->filter('.chat-new__pet'));
        $this->assertSame(['Bella', 'Max', '+ New chat'], $this->client->request('GET', '/chat')->filter('.chat-pets a')->each(fn ($a) => trim($a->text())));

        $this->client->request('GET', '/chat/c/1');
        $this->assertResponseRedirects('/chat', null, 'old chat links go to the chat');
    }

    public function testNoPetMeansTheQuizFirst(): void
    {
        $this->createUser();
        $this->loginViaForm('maria@example.com', 'Password123');
        $this->client->request('GET', '/chat');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('#chat');
        $this->assertSelectorExists('a[href="/quiz/?new=1&then=chat"]');

        // Sending without a pet (a page left open while the pet was removed) is refused.
        $user = $this->em->getRepository(\App\Entity\User::class)->findOneBy([]);
        $dog = $this->createDog($user);
        $token = (string) $this->client->request('GET', '/chat')->filter('#chat')->attr('data-csrf');
        $this->em->remove($this->em->getRepository(\App\Entity\DogProfile::class)->find($dog->getId()));
        $this->em->flush();
        $this->client->request('POST', '/chat/send', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], '{"message":"hi"}');
        $this->assertResponseStatusCodeSame(400);
        $this->assertSame(0, $this->rows(ChatConversation::class));
        $this->assertCount(0, FakeClaude::$requests);
    }

    public function testDailyLimitAndClearingTheChatDoesNotResetIt(): void
    {
        $this->createPetParent();
        $this->loginViaForm('maria@example.com', 'Password123');
        $this->assertSame(200, $this->send('one')[0]);
        $this->assertSame(200, $this->send('two')[0]);

        [$status, $body] = $this->send('three');
        $this->assertSame(429, $status);
        $this->assertStringContainsString('used all 2 messages for today', json_decode($body, true)['error']);
        $this->assertCount(2, FakeClaude::$requests, 'Claude is not called once the limit is reached');

        $crawler = $this->client->request('GET', '/chat');
        $this->client->submit($crawler->filter('.chat-bar form')->form());
        $this->assertResponseRedirects('/chat?pet=' . $this->em->getRepository(\App\Entity\DogProfile::class)->findOneBy([])->getId());
        $this->assertSame(0, $this->rows(ChatMessage::class), 'Clear chat empties the history');
        $this->assertSame(429, $this->send('four')[0], 'clearing the chat does not give messages back');

        $crawler = $this->client->request('GET', '/chat');
        $this->assertSame('0', trim($crawler->filter('#chat-remaining')->text()));
        $this->assertSelectorExists('#chat-input[disabled]');
    }

    public function testFailedReplyIsRolledBackAndRefunded(): void
    {
        $this->createPetParent();
        $this->loginViaForm('maria@example.com', 'Password123');
        FakeClaude::$fail = true;

        [$status, $body] = $this->send('Hello?');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('event: error', $body);
        $this->assertStringContainsString('isn’t available right now', $body, 'pet parents get a plain message, not API details');
        $this->assertSame(0, $this->rows(ChatMessage::class));
        $this->assertSame(0, $this->rows(ChatConversation::class));
        $this->assertSame(0, $this->em->getRepository(ChatUsage::class)->findOneBy([])?->getMessages() ?? 0);
    }

    public function testAdminsHaveNoLimit(): void
    {
        $this->createPetParent('admin@example.com', 'AdminPass123', ['ROLE_ADMIN']);
        $this->loginViaForm('admin@example.com', 'AdminPass123');
        foreach (['a', 'b', 'c'] as $m) {
            $this->assertSame(200, $this->send($m)[0]);
        }
    }

    public function testChatsArePrivate(): void
    {
        $this->createPetParent();
        $this->createPetParent('jonas@example.com');
        $this->loginViaForm('maria@example.com', 'Password123');
        $this->send('Maria’s question');
        $mariasChat = $this->em->getRepository(ChatConversation::class)->findOneBy([])->getId();

        $this->client->request('GET', '/logout');
        $this->loginViaForm('jonas@example.com', 'Password123');
        $crawler = $this->client->request('GET', '/chat');
        $this->assertCount(0, $crawler->filter('.chat-msg'), 'Jonas sees his own empty chat');

        // Even pointing at Maria's pet, Jonas only reaches his own pet's chat.
        $mariasDog = $this->em->getRepository(ChatConversation::class)->find($mariasChat)->getDog()->getId();
        $crawler = $this->client->request('GET', '/chat?pet=' . $mariasDog);
        $this->assertCount(0, $crawler->filter('.chat-msg'));
        $this->client->request('POST', '/chat/send?pet=' . $mariasDog, [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => (string) $crawler->filter('#chat')->attr('data-csrf'),
        ], json_encode(['message' => 'Jonas’s question', 'conversationId' => $mariasChat]));
        $this->assertSame(2, $this->rows(ChatConversation::class), 'Jonas gets his own chat');
        $this->assertCount(1, FakeClaude::$requests[1]['messages'], 'Maria’s messages are never sent');
    }

    public function testClearChatNeedsCsrfToken(): void
    {
        $this->createPetParent();
        $this->loginViaForm('maria@example.com', 'Password123');
        $this->send('hi');
        $this->client->request('POST', '/chat/clear', ['_token' => 'forged']);
        $this->assertSame(2, $this->rows(ChatMessage::class));
    }

    public function testSendNeedsCsrfTokenAndText(): void
    {
        $this->createPetParent();
        $this->loginViaForm('maria@example.com', 'Password123');
        $this->assertSame(403, $this->send('hi', 'forged')[0]);
        $this->assertSame(400, $this->send('   ')[0]);
        $this->assertSame(400, $this->send(str_repeat('a', 2001))[0]);
        $this->assertCount(0, FakeClaude::$requests);
    }

    public function testRepliesAreEscaped(): void
    {
        FakeClaude::$reply = '<script>alert(1)</script> **ok**';
        $this->createPetParent();
        $this->loginViaForm('maria@example.com', 'Password123');
        $this->send('hi');
        $this->client->request('GET', '/chat');
        $html = $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testDeletingTheAccountRemovesChats(): void
    {
        $this->createPetParent();
        $this->loginViaForm('maria@example.com', 'Password123');
        $this->send('hi');
        $crawler = $this->client->request('GET', '/account');
        $this->client->submit($crawler->filter('form[action="/account/delete"]')->form(['password' => 'Password123']));
        $this->assertSame(0, $this->rows(ChatMessage::class));
        $this->assertSame(0, $this->rows(ChatConversation::class));
        $this->assertSame(0, $this->rows(ChatUsage::class));
    }
}
