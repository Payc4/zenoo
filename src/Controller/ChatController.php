<?php

namespace App\Controller;

use App\Chat\ChatAgent;
use App\Chat\ChatQuota;
use App\Chat\ClaudeClient;
use App\Chat\ClaudeException;
use App\Entity\ChatConversation;
use App\Entity\ChatMessage;
use App\Entity\User;
use App\Repository\ChatConversationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The ZENOO chatbot (Claude). Each logged-in pet parent has exactly one chat, created with
 * their first message. Daily message limit: ChatQuota.
 * Replies stream to the browser as server-sent events: meta, delta, questions, done, error.
 * "questions" carries multiple-choice cards (starting questions or Claude's ask_questions tool).
 */
#[Route('/chat')]
#[IsGranted('ROLE_USER')]
class ChatController extends AbstractController
{
    public const MAX_MESSAGE_CHARS = 2000;

    public function __construct(
        private readonly ChatConversationRepository $conversations,
        private readonly ChatQuota $quota,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
    }

    #[Route('', name: 'app_chat', methods: ['GET'])]
    public function index(ClaudeClient $claude, ChatAgent $agent): Response
    {
        $user = $this->currentUser();
        $conversation = $this->conversations->findForUser($user);
        $messages = $conversation ? $conversation->getMessages()->toArray() : [];

        // Cards to show: the starting questions in an empty chat, or the ones in Claude's last reply.
        $last = end($messages) ?: null;
        $cards = !$messages ? $agent->introQuestions() : ($last && !$last->isFromUser() ? $last->getQuestions() : null);

        return $this->render('chat/index.html.twig', [
            'messages' => $messages,
            'cards' => $cards,
            'cardsAreIntro' => !$messages && $cards !== null,
            'configured' => $claude->isConfigured(),
            'unlimited' => $this->quota->isUnlimited($user),
            'limit' => $this->quota->dailyLimit(),
            'remaining' => $this->quota->remaining($user),
            'resetLabel' => $this->quota->resetLabel(),
            'maxChars' => self::MAX_MESSAGE_CHARS,
        ]);
    }

    /** Old links from when there were several chats per person. */
    #[Route('/c/{id}', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function legacyConversation(): Response
    {
        return $this->redirectToRoute('app_chat');
    }

    #[Route('/send', name: 'app_chat_send', methods: ['POST'])]
    public function send(Request $request, ClaudeClient $claude, ChatAgent $agent, LoggerInterface $logger): Response
    {
        $user = $this->currentUser();

        if (!$this->isCsrfTokenValid('chat', (string) $request->headers->get('X-CSRF-Token'))) {
            return new JsonResponse(['error' => 'Your session expired. Please reload the page.'], 403);
        }

        $data = json_decode($request->getContent(), true) ?: [];
        $text = trim((string) ($data['message'] ?? ''));
        if ($text === '') {
            return new JsonResponse(['error' => 'Type a message first.'], 400);
        }
        if (mb_strlen($text) > self::MAX_MESSAGE_CHARS) {
            return new JsonResponse(['error' => sprintf('That message is too long (max %d characters).', self::MAX_MESSAGE_CHARS)], 400);
        }
        if (!$claude->isConfigured()) {
            return new JsonResponse(['error' => 'The chat isn’t switched on yet. Please try again later.'], 503);
        }
        if (!$this->quota->consume($user)) {
            return new JsonResponse([
                'error' => sprintf('You’ve used all %d messages for today. You can chat again after %s.', $this->quota->dailyLimit(), $this->quota->resetLabel()),
                'remaining' => 0,
            ], 429);
        }

        // The one chat this pet parent has, started by their first message.
        $conversation = $this->conversations->findForUser($user);
        $isNew = $conversation === null;
        if ($isNew) {
            $conversation = new ChatConversation($user);
            $this->em->persist($conversation);
        }

        $userMessage = new ChatMessage($conversation, ChatMessage::ROLE_USER, $text);
        $this->em->persist($userMessage);
        $conversation->touch();
        $this->em->flush();
        $remaining = $this->quota->isUnlimited($user) ? null : $this->quota->remaining($user);

        // Don't hold the session lock while the reply streams.
        if ($request->hasSession()) {
            $request->getSession()->save();
        }

        $stream = $this->environment !== 'test';
        $quota = $this->quota;
        $response = new StreamedResponse(function () use ($agent, $conversation, $userMessage, $isNew, $remaining, $logger, $stream, $quota, $user) {
            ignore_user_abort(true); // finish and save the reply even if the tab is closed
            if ($stream) {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
            }
            $emit = static function (string $event, array $data) use ($stream): void {
                echo 'event: ' . $event . "\n" . 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
                if ($stream) {
                    @flush();
                }
            };

            $emit('meta', ['remaining' => $remaining]);

            try {
                $result = $agent->reply($conversation, static fn (string $delta) => $emit('delta', ['text' => $delta]));
                $reply = $result['text'];
                if ($reply === '' && !$result['questions']) {
                    $reply = 'Sorry, I couldn’t come up with an answer. Could you rephrase that?';
                }

                $assistant = (new ChatMessage($conversation, ChatMessage::ROLE_ASSISTANT, $reply))
                    ->setQuestions($result['questions'])
                    ->setUsage($result['usage']['input'], $result['usage']['output']);
                $this->em->persist($assistant);
                $conversation->touch();
                $this->em->flush();

                if ($result['questions']) {
                    $emit('questions', ['questions' => $result['questions']]);
                }
                $emit('done', []);
            } catch (\Throwable $e) {
                $logger->error('Chat reply failed', ['exception' => $e]);

                // Roll the turn back: the chat stays consistent and the message doesn't count.
                $quota->refund($user);
                $this->em->remove($userMessage);
                if ($isNew) {
                    $this->em->remove($conversation);
                }
                $this->em->flush();

                $emit('error', [
                    'message' => $this->friendlyError($e),
                    'remaining' => $remaining === null ? null : $remaining + 1,
                ]);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream; charset=utf-8');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /** Empties the chat. The day's message count stays as it is. */
    #[Route('/clear', name: 'app_chat_clear', methods: ['POST'])]
    public function clear(Request $request): Response
    {
        $user = $this->currentUser();
        if ($this->isCsrfTokenValid('chat-clear', (string) $request->request->get('_token'))) {
            $this->conversations->deleteConversationsForUser($user);
        }

        return $this->redirectToRoute('app_chat');
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /** What the pet parent sees; the details go to the log for the admin. */
    private function friendlyError(\Throwable $e): string
    {
        if ($e instanceof ClaudeException) {
            if ($this->isGranted('ROLE_ADMIN')) {
                return match (true) {
                    $e->getCode() === 401 => 'Admin: the Claude API key was rejected. Check ANTHROPIC_API_KEY in Coolify.',
                    $e->getCode() === 404 => 'Admin: the model name wasn’t recognised. Check ANTHROPIC_MODEL.',
                    $e->getCode() === 429 => 'Admin: Claude is rate-limited or the spending limit was reached. Check the Claude Console.',
                    $e->getCode() === 400 && str_contains(strtolower($e->getMessage()), 'credit') => 'Admin: the Claude account is out of credits. Top up in the Claude Console.',
                    default => 'Admin: Claude returned an error: ' . $e->getMessage(),
                };
            }

            return $e->getCode() === 429
                ? 'The chat is very busy right now. Please try again in a minute.'
                : 'The chat isn’t available right now. Please try again in a moment.';
        }

        return 'Something went wrong while answering. Please try again in a moment.';
    }
}
