<?php

namespace App\Controller;

use App\Chat\ChatAgent;
use App\Chat\ChatFiles;
use App\Chat\CheckIns;
use App\Chat\ChatQuota;
use App\Chat\ClaudeClient;
use App\Chat\ClaudeException;
use App\Entity\ChatConversation;
use App\Entity\ChatMessage;
use App\Entity\User;
use App\Repository\ChatConversationRepository;
use App\Repository\DogProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The ZENOO chatbot (Claude). Each logged-in pet parent has exactly one chat, created with
 * their first message. Daily message limit: ChatQuota.
 * Replies stream to the browser as server-sent events: meta, delta, questions, note, done, error.
 * "questions" carries multiple-choice cards (starting questions or Claude's ask_questions tool);
 * "note" says the food was saved to the dog's profile (Claude's save_food tool).
 *
 * A message can come with up to 3 photos or PDFs (multipart form: message + files[]), stored by
 * ChatFiles and shown back only through attachment(), to their owner and to admins.
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
        private readonly TranslatorInterface $translator,
        private readonly ChatFiles $files,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
    ) {
    }

    #[Route('', name: 'app_chat', methods: ['GET'])]
    public function index(Request $request, ClaudeClient $claude, ChatAgent $agent, DogProfileRepository $dogs, CheckIns $checkIns): Response
    {
        $user = $this->currentUser();
        $conversation = $this->conversations->findForUser($user);
        $messages = $conversation ? $conversation->getMessages()->toArray() : [];
        $dog = $dogs->findForUser($user);

        // Cards to show: the starting questions in an empty chat (not needed when the quiz
        // already told us about the dog), or the ones in Claude's last reply.
        $last = end($messages) ?: null;
        $cards = !$messages ? ($dog ? null : $agent->introQuestions($request->getLocale())) : ($last && !$last->isFromUser() ? $last->getQuestions() : null);

        // First step after sign-up: the food label, until the coach knows the food.
        $needsFood = $dog !== null && $dog->getFood() === null && !$messages;

        // Otherwise, every few days: a check-in about the dog (no AI call, see CheckIns).
        $checkin = null;
        if ($cards === null && $dog && !$needsFood) {
            $now = new \DateTimeImmutable();
            $checkin = $checkIns->cardsFor($dog, $request->getLocale(), $now);
            if ($checkin !== null) {
                $dog->markCheckinAsked($now);
                $this->em->flush();
            }
        }

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
            'maxFiles' => ChatFiles::MAX_FILES,
            'dog' => $dog,
            'needsFood' => $needsFood,
            // From the dashboard's "Show the coach a photo" (/chat?photo=1).
            'attachHint' => $request->query->has('photo'),
            'checkin' => $checkin,
            // Starter question from the dashboard (/chat?ask=…), put in the message box.
            'ask' => mb_substr(trim((string) $request->query->get('ask', '')), 0, 300),
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
            return new JsonResponse(['error' => $this->translator->trans('Your session expired. Please reload the page.')], 403);
        }

        // JSON ({message}), or a form with photos/PDFs (message + files[]).
        $uploads = array_values(array_filter((array) $request->files->get('files', []), fn ($f) => $f instanceof UploadedFile));
        $data = $request->request->has('message') || $uploads ? ['message' => $request->request->get('message', '')] : (json_decode($request->getContent(), true) ?: []);
        $text = trim((string) ($data['message'] ?? ''));
        if ($text === '' && !$uploads) {
            return new JsonResponse(['error' => $this->translator->trans('Type a message first.')], 400);
        }
        if (\count($uploads) > ChatFiles::MAX_FILES) {
            return new JsonResponse(['error' => $this->translator->trans('You can send up to %n% files at a time.', ['%n%' => ChatFiles::MAX_FILES])], 400);
        }
        if (mb_strlen($text) > self::MAX_MESSAGE_CHARS) {
            return new JsonResponse(['error' => $this->translator->trans('That message is too long (max %max% characters).', ['%max%' => self::MAX_MESSAGE_CHARS])], 400);
        }
        if (!$claude->isConfigured()) {
            return new JsonResponse(['error' => $this->translator->trans('The chat isn’t switched on yet. Please try again later.')], 503);
        }
        // Store the files first: a bad file is reported before the message counts.
        $attachments = [];
        try {
            foreach ($uploads as $upload) {
                $attachments[] = $this->files->store($user, $upload);
            }
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $this->translator->trans($e->getMessage())], 400);
        }
        if (!$this->quota->consume($user)) {
            return new JsonResponse([
                'error' => $this->translator->trans('You’ve used all %limit% messages for today. You can chat again after %time%.', ['%limit%' => $this->quota->dailyLimit(), '%time%' => $this->translator->trans($this->quota->resetLabel())]),
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

        $userMessage = (new ChatMessage($conversation, ChatMessage::ROLE_USER, $text))->setAttachments($attachments);
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
        $translator = $this->translator;
        $response = new StreamedResponse(function () use ($agent, $conversation, $userMessage, $isNew, $remaining, $logger, $stream, $quota, $user, $translator) {
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

            $emit('meta', ['remaining' => $remaining, 'files' => $this->fileUrls($userMessage)]);

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
                if ($result['food']) {
                    $emit('note', ['text' => $translator->trans('Saved to your dog’s profile: %food%', ['%food%' => trim($result['food']['brand'] . ' ' . $result['food']['product'])])]);
                }
                $emit('done', []);
            } catch (\Throwable $e) {
                $logger->error('Chat reply failed', ['exception' => $e]);

                // Roll the turn back: the chat stays consistent and the message doesn't count.
                $quota->refund($user);
                foreach ($userMessage->getAttachments() as $a) {
                    if ($path = $this->files->path($user, $a)) {
                        @unlink($path);
                    }
                }
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

    /**
     * Saves check-in answers to the dog profile. No chat message and no AI call: the answers
     * reach the coach through the dog profile in its instructions.
     */
    #[Route('/checkin', name: 'app_chat_checkin', methods: ['POST'])]
    public function checkin(Request $request, DogProfileRepository $dogs, CheckIns $checkIns): JsonResponse
    {
        $user = $this->currentUser();
        if (!$this->isCsrfTokenValid('chat', (string) $request->headers->get('X-CSRF-Token'))) {
            return new JsonResponse(['error' => $this->translator->trans('Your session expired. Please reload the page.')], 403);
        }
        $dog = $dogs->findForUser($user);
        if (!$dog) {
            return new JsonResponse(['error' => $this->translator->trans('Take the quiz first, so we know your dog.')], 400);
        }
        $data = json_decode($request->getContent(), true) ?: [];
        $saved = $checkIns->save($dog, $data['answers'] ?? null, new \DateTimeImmutable());
        $this->em->flush();

        return new JsonResponse([
            'saved' => $saved,
            'message' => $saved ? $this->translator->trans('Thanks! Saved to %dog%’s profile, so the coach knows.', ['%dog%' => $dog->getName()]) : null,
        ]);
    }

    /** A photo or PDF sent in the chat, for its owner and for admins (reading testers' chats). */
    #[Route('/file/{id}/{index}', name: 'app_chat_file', requirements: ['id' => '\d+', 'index' => '\d'], methods: ['GET'])]
    public function attachment(ChatMessage $message, int $index): Response
    {
        $owner = $message->getUser();
        if ($owner !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createNotFoundException();
        }
        $attachment = $message->getAttachments()[$index] ?? null;
        $path = $attachment ? $this->files->path($owner, $attachment) : null;
        if ($path === null) {
            throw $this->createNotFoundException();
        }
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $attachment['type']);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $attachment['type'] === 'application/pdf' ? 'file.pdf' : 'photo.jpg');
        $response->setPrivate();
        $response->setMaxAge(3600);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    /** @return list<array{url: string, type: string, name: string}> */
    private function fileUrls(ChatMessage $m): array
    {
        $out = [];
        foreach ($m->getAttachments() as $i => $a) {
            $out[] = ['url' => $this->generateUrl('app_chat_file', ['id' => $m->getId(), 'index' => $i]), 'type' => $a['type'], 'name' => $a['name']];
        }
        return $out;
    }

    /** Empties the chat. The day's message count stays as it is. */
    #[Route('/clear', name: 'app_chat_clear', methods: ['POST'])]
    public function clear(Request $request): Response
    {
        $user = $this->currentUser();
        if ($this->isCsrfTokenValid('chat-clear', (string) $request->request->get('_token'))) {
            $this->conversations->deleteConversationsForUser($user);
            $this->files->removeAll($user);
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

            return $this->translator->trans($e->getCode() === 429
                ? 'The chat is very busy right now. Please try again in a minute.'
                : 'The chat isn’t available right now. Please try again in a moment.');
        }

        return $this->translator->trans('Something went wrong while answering. Please try again in a moment.');
    }
}
