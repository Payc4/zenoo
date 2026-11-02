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
 * The ZENOO chatbot (Claude). Logged-in pet parents only, with a daily message limit (ChatQuota).
 * Replies stream to the browser as server-sent events: meta, delta, done, error.
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
    #[Route('/c/{id}', name: 'app_chat_conversation', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function index(ClaudeClient $claude, ?int $id = null): Response
    {
        $user = $this->currentUser();
        $conversation = null;
        if ($id !== null) {
            $conversation = $this->conversations->findOneForUser($id, $user);
            if (!$conversation) {
                return $this->redirectToRoute('app_chat');
            }
        }

        return $this->render('chat/index.html.twig', [
            'conversation' => $conversation,
            'messages' => $conversation ? $conversation->getMessages() : [],
            'conversations' => $this->conversations->findRecentForUser($user),
            'configured' => $claude->isConfigured(),
            'unlimited' => $this->quota->isUnlimited($user),
            'limit' => $this->quota->dailyLimit(),
            'remaining' => $this->quota->remaining($user),
            'resetLabel' => $this->quota->resetLabel(),
            'maxChars' => self::MAX_MESSAGE_CHARS,
        ]);
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

        $isNew = false;
        $conversation = null;
        if (!empty($data['conversationId'])) {
            $conversation = $this->conversations->findOneForUser((int) $data['conversationId'], $user);
        }
        if (!$conversation) {
            $conversation = (new ChatConversation($user))->setTitle($text);
            $this->em->persist($conversation);
            $isNew = true;
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

            $emit('meta', [
                'conversationId' => $conversation->getId(),
                'title' => $conversation->getTitle(),
                'url' => $this->generateUrl('app_chat_conversation', ['id' => $conversation->getId()]),
                'isNew' => $isNew,
                'remaining' => $remaining,
            ]);

            try {
                $result = $agent->reply($conversation, static fn (string $delta) => $emit('delta', ['text' => $delta]));
                $reply = $result['text'] !== '' ? $result['text'] : 'Sorry, I couldn’t come up with an answer. Could you rephrase that?';

                $assistant = (new ChatMessage($conversation, ChatMessage::ROLE_ASSISTANT, $reply))
                    ->setUsage($result['usage']['input'], $result['usage']['output']);
                $this->em->persist($assistant);
                $conversation->touch();
                $this->em->flush();

                $emit('done', []);
            } catch (\Throwable $e) {
                $logger->error('Chat reply failed', ['exception' => $e]);

                // Roll the turn back: the conversation stays consistent and the message doesn't count.
                $quota->refund($user);
                $this->em->remove($userMessage);
                if ($isNew) {
                    $this->em->remove($conversation);
                }
                $this->em->flush();

                $emit('error', [
                    'message' => $this->friendlyError($e),
                    'removedConversation' => $isNew,
                    'remaining' => $remaining === null ? null : $remaining + 1,
                ]);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream; charset=utf-8');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    #[Route('/c/{id}/delete', name: 'app_chat_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $conversation = $this->conversations->findOneForUser($id, $this->currentUser());
        if ($conversation && $this->isCsrfTokenValid('chat-delete-' . $id, (string) $request->request->get('_token'))) {
            $this->em->remove($conversation);
            $this->em->flush();
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
        $admin = $this->isGranted('ROLE_ADMIN');
        if ($e instanceof ClaudeException) {
            if ($admin) {
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
