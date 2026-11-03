<?php

namespace App\Tests\Functional;

use App\Entity\ChatMessage;
use App\Tests\Support\FakeClaude;

/** Question cards: the starting questions and the ones Claude asks with ask_questions. */
class ChatQuestionsTest extends AppTestCase
{
    private const CARDS = ['questions' => [
        ['question' => 'How much does your dog weigh?', 'options' => [['label' => 'Under 5 kg', 'description' => 'Toy'], ['label' => 'Over 25 kg']]],
        ['question' => 'How active is your dog?', 'options' => [['label' => 'Normal'], ['label' => 'Very active']]],
    ]];

    private function send(string $message): string
    {
        $crawler = $this->client->request('GET', '/chat');
        $this->client->request('POST', '/chat/send', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => (string) $crawler->filter('#chat')->attr('data-csrf'),
        ], json_encode(['message' => $message]));

        return (string) $this->client->getInternalResponse()->getContent();
    }

    /** @return array{questions: array, intro: bool}|null the cards the chat page will open */
    private function pageCards(): ?array
    {
        $crawler = $this->client->request('GET', '/chat');
        $node = $crawler->filter('#chat-cards');

        return $node->count() ? json_decode($node->text(), true) : null;
    }

    public function testEmptyChatShowsTheStartingQuestions(): void
    {
        $this->createUser();
        $this->loginViaForm('maria@example.com', 'Password123');

        $cards = $this->pageCards();
        $this->assertTrue($cards['intro']);
        $this->assertSame("What's your dog's name?", $cards['questions'][0]['question']);
        $this->assertSame([], $cards['questions'][0]['options'], 'the name is typed');
        $this->assertSame(['label' => 'Puppy', 'description' => 'Under 1 year'], $cards['questions'][1]['options'][0]);
        $this->assertSelectorExists('#chat-suggest[hidden]');

        // The answers arrive as one ordinary message; after that there are no starting questions.
        $this->send("A bit about my dog:\nWhat's your dog's name? — Luna");
        $this->assertNull($this->pageCards());
        $this->assertSelectorTextContains('.chat-bubble', 'Luna');
    }

    public function testClaudeCanAskWithCards(): void
    {
        FakeClaude::$askQuestions = self::CARDS;
        FakeClaude::$reply = 'Two quick questions:';
        $this->createUser();
        $this->loginViaForm('maria@example.com', 'Password123');

        $body = $this->send('How much should my dog eat?');
        $this->assertStringContainsString('event: questions', $body);
        $this->assertSame('ask_questions', FakeClaude::$requests[0]['tools'][0]['name'], 'the tool is offered on every reply');

        $reply = $this->em->getRepository(ChatMessage::class)->findOneBy(['role' => 'assistant']);
        $this->assertSame('Two quick questions:', $reply->getContent());
        $this->assertSame('How active is your dog?', $reply->getQuestions()[1]['question']);
        $this->assertSame(['label' => 'Over 25 kg', 'description' => ''], $reply->getQuestions()[0]['options'][1]);

        // Unanswered cards open again when the page is reloaded.
        $cards = $this->pageCards();
        $this->assertFalse($cards['intro']);
        $this->assertCount(2, $cards['questions']);
        $this->assertSelectorCount(2, '.chat-asked li');

        // Answering: Claude sees its own earlier cards as text, then the answers.
        FakeClaude::$askQuestions = null;
        $this->send("How much does your dog weigh? — Over 25 kg\nHow active is your dog? — Normal");
        $history = FakeClaude::$requests[1]['messages'];
        $this->assertSame(['user', 'assistant', 'user'], array_column($history, 'role'));
        $this->assertStringContainsString('[Shown to the pet parent as question cards:', $history[1]['content']);
        $this->assertStringContainsString('1. How much does your dog weigh? Options: Under 5 kg (Toy); Over 25 kg.', $history[1]['content']);
        $this->assertNull($this->pageCards(), 'answered cards are not shown again');
    }

    public function testCardsWithoutTextStillMakeAReply(): void
    {
        FakeClaude::$askQuestions = self::CARDS;
        FakeClaude::$reply = '';
        $this->createUser();
        $this->loginViaForm('maria@example.com', 'Password123');
        $this->send('How much should my dog eat?');

        $reply = $this->em->getRepository(ChatMessage::class)->findOneBy(['role' => 'assistant']);
        $this->assertSame('', $reply->getContent());
        $this->assertCount(2, $reply->getQuestions());
        $this->client->request('GET', '/chat');
        $this->assertSelectorTextContains('.chat-msg--bot .chat-answer', 'I have a few quick questions for you.');
    }

    public function testCardsFromClaudeAreCleanedUp(): void
    {
        FakeClaude::$askQuestions = ['questions' => [
            ['question' => '<b>Size?</b>', 'options' => [['label' => '<script>x</script>Big'], ['label' => ''], ['label' => 'Small']]],
            ['question' => '', 'options' => []],
            ['question' => 'Q3', 'options' => []], ['question' => 'Q4', 'options' => []], ['question' => 'Q5', 'options' => []], ['question' => 'Q6', 'options' => []],
        ]];
        $this->createUser();
        $this->loginViaForm('maria@example.com', 'Password123');
        $this->send('How much should my dog eat?');

        $q = $this->em->getRepository(ChatMessage::class)->findOneBy(['role' => 'assistant'])->getQuestions();
        $this->assertSame('Size?', $q[0]['question']);
        $this->assertSame(['xBig', 'Small'], array_column($q[0]['options'], 'label'), 'tags stripped, empty options dropped');
        $this->assertSame(['Size?', 'Q3', 'Q4'], array_column($q, 'question'), 'empty questions dropped, at most 4 taken');
    }
}
