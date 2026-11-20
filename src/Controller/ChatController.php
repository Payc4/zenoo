<?php

namespace App\Controller;

use App\Chat\ChatAgent;
use App\Chat\SafetyCheck;
use App\Chat\ChatFiles;
use App\Chat\CheckIns;
use App\Chat\ChatQuota;
use App\Chat\ClaudeClient;
use App\Chat\ClaudeException;
use App\Entity\ChatConversation;
use App\Entity\ChatMessage;
use App\Entity\DogProfile;
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

/**
 * The ZENOO coach (Claude). Every pet has exactly one chat (ChatConversation.dog is unique),
 * created with its first message. A new pet's chat starts with the Bowl Score quiz, which
 * makes the pet's profile. Which pet: ?pet=<id> on the chat's own requests, else the pet
 * selected on the dashboard or in the chat. Daily message limit: ChatQuota (per person).
 * Replies stream to the browser as server-sent events: meta, delta, questions, note, done, error.
 * "questions" carries multiple-choice cards (starting questions or Claude's ask_questions tool);
 * "note" says the food was saved to the dog's profile (Claude's save_food tool).
 *
 * A message can come with up to 3 photos or PDFs (multipart form: message + files[]), stored by
 * ChatFiles and shown back only through attachment(), to their owner and to admins.
 */
#[Route('/chat')]
// Signed in only (security.yaml), except /chat itself, which shows visitors a preview.
class ChatController extends AbstractController
{
    public const MAX_MESSAGE_CHARS = 2000;
    /** The coach opens the chat itself when the last message is at least this old. */
    public const OPENER_AFTER_DAYS = 2;

    public function __construct(
        private readonly ChatConversationRepository $conversations,
        private readonly ChatQuota $quota,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly ChatFiles $files,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
        private readonly \App\Chat\ReplyGuard $guard,
        private readonly \App\Service\DogPhotoUploader $dogPhotos,
    ) {
    }

    #[Route('', name: 'app_chat', methods: ['GET'])]
    public function index(Request $request, ClaudeClient $claude, DogProfileRepository $dogs, CheckIns $checkIns): Response
    {
        if (!$this->getUser() instanceof User) {
            return $this->render('preview/feature.html.twig', ['feature' => 'coach']);
        }
        $user = $this->currentUser();
        $dog = $this->dogFor($request, $user, $dogs);
        if ($dog === null) {
            // No pet yet: the first chat starts with the quiz, which creates the pet's profile.
            return $this->render('chat/new.html.twig', ['dogs' => [], 'hasPets' => false]);
        }
        if ((int) $request->query->get('pet') === $dog->getId() && $user->getActiveDogId() !== $dog->getId()) {
            $user->setActiveDogId($dog->getId());
            $this->em->flush();
        }
        $conversation = $this->conversations->findForDog($dog);
        $messages = $conversation ? $conversation->getMessages()->toArray() : [];

        // Question cards from Claude's last reply, if it ended with some.
        $last = end($messages) ?: null;
        $cards = $last && !$last->isFromUser() ? $last->getQuestions() : null;

        // First step after sign-up: the food label, until the coach knows the food.
        $needsFood = $dog !== null && $dog->getFood() === null && !$messages;

        // Coming back after a while (or a first chat once the food is known): the coach opens
        // the conversation itself (POST /chat/opener). It replaces the check-in cards that day.
        $opener = $claude->isConfigured() && !$request->query->has('ask') && !$request->query->has('photo') && ($cards === null)
            ? $this->openerDue($messages, $dog) : null;

        // Otherwise, every few days: a check-in about the dog (no AI call, see CheckIns).
        $checkin = null;
        if ($cards === null && $dog && !$needsFood && $opener === null) {
            $now = new \DateTimeImmutable();
            $checkin = $checkIns->cardsFor($dog, $request->getLocale(), $now);
            if ($checkin !== null) {
                $dog->markCheckinAsked($now);
                $this->em->flush();
            }
        }

        // The sidebar (like a chat app): one chat per pet, the newest on top, with its last line.
        // Pets without a chat yet sit under "New chat", with "Add a new dog" (the quiz).
        $latest = $this->conversations->latestByDog($user);
        $withChat = $this->conversations->dogIdsWithChat($user);
        $allDogs = $dogs->findAllForUser($user);
        usort($allDogs, fn (DogProfile $a, DogProfile $b) => $a->getId() <=> $b->getId());
        $sideChats = [];
        foreach ($allDogs as $d) {
            if ($d === $dog || \in_array($d->getId(), $withChat, true)) {
                $sideChats[] = ['dog' => $d, 'last' => $latest[$d->getId()] ?? null, 'photo' => $this->dogPhotos->displayUrl($d)];
            }
        }
        // Newest first. The open chat with no messages yet (just started) goes to the top, an
        // emptied one of another pet to the bottom.
        $when = fn (array $c) => $c['last']['at'] ?? new \DateTimeImmutable($c['dog'] === $dog ? '+1 day' : '@0');
        usort($sideChats, fn (array $a, array $b) => [$when($b), $b['last']['id'] ?? 0] <=> [$when($a), $a['last']['id'] ?? 0]);
        $newDogs = array_values(array_filter($allDogs, fn (DogProfile $d) => $d !== $dog && !\in_array($d->getId(), $withChat, true)));

        // "Using Max's profile: Beagle, 15.2 kg, allergic to chicken."
        $facts = array_filter([
            $dog->get('breed') === 'mixed' ? $this->translator->trans('Mixed breed') : $dog->get('breed'),
            ($w = $dog->latestLog('weight')['v'] ?? $dog->get('weight_kg')) ? str_replace('.', \App\Locale::decimalSeparator($request->getLocale()), (string) $w) . ' kg' : null,
            ...\array_slice(\App\Chat\ReplyGuard::rules($dog), 0, 2),
        ]);

        return $this->render('chat/index.html.twig', [
            'usingFacts' => implode(', ', $facts),
            'dog' => $dog,
            'sideChats' => $sideChats,
            'newDogs' => array_map(fn (DogProfile $d) => ['dog' => $d, 'photo' => $this->dogPhotos->displayUrl($d)], $newDogs),
            'messages' => $messages,
            'cards' => $cards,
            'cardsAreIntro' => false,
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
            'openerUrl' => $opener !== null ? $this->generateUrl('app_chat_opener', ['pet' => $dog->getId()]) : null,
            // Starter question from the dashboard (/chat?ask=…), put in the message box.
            'ask' => mb_substr(trim((string) $request->query->get('ask', '')), 0, 300),
        ]);
    }

    /**
     * "New chat": a pet that has no chat yet, or a new pet (the quiz makes its profile).
     * Pets that already have a chat aren't offered: one chat per pet.
     */
    #[Route('/new', name: 'app_chat_new', methods: ['GET'])]
    public function newChat(DogProfileRepository $dogs): Response
    {
        $user = $this->currentUser();
        $withChat = $this->conversations->dogIdsWithChat($user);
        $all = $dogs->findAllForUser($user);
        usort($all, fn (DogProfile $a, DogProfile $b) => $a->getId() <=> $b->getId()); // same order as the chat's tabs

        return $this->render('chat/new.html.twig', [
            'dogs' => array_values(array_filter($all, fn (DogProfile $d) => !\in_array($d->getId(), $withChat, true))),
            'hasPets' => $all !== [],
        ]);
    }

    /**
     * Days since the last message when the coach should open the chat (0 = first chat), or null.
     *
     * @param list<ChatMessage> $messages
     */
    private function openerDue(array $messages, ?DogProfile $dog): ?int
    {
        if ($dog === null) {
            return null; // no quiz yet
        }
        $days = $messages ? (int) floor(((new \DateTimeImmutable())->getTimestamp() - end($messages)->getCreatedAt()->getTimestamp()) / 86400) : 0;
        if ($dog->dueFollowUp(new \DateTimeImmutable()) !== null && (!$messages || !end($messages)->isFromUser())) {
            return $days; // a check-in the coach planned is due: ask it whenever they come
        }
        if ($dog->getFood() === null) {
            return null; // the "snap the food label" step comes first
        }
        if (!$messages) {
            return 0;
        }
        return $days >= self::OPENER_AFTER_DAYS ? $days : null;
    }

    #[Route('/opener', name: 'app_chat_opener', methods: ['POST'])]
    public function opener(Request $request, ClaudeClient $claude, ChatAgent $agent, DogProfileRepository $dogs, LoggerInterface $logger): Response
    {
        $user = $this->currentUser();
        if (!$this->isCsrfTokenValid('chat', (string) $request->headers->get('X-CSRF-Token'))) {
            return new JsonResponse(['error' => 'csrf'], 403);
        }
        $dog = $this->dogFor($request, $user, $dogs);
        $conversation = $dog ? $this->conversations->findForDog($dog) : null;
        $days = $claude->isConfigured() ? $this->openerDue($conversation ? $conversation->getMessages()->toArray() : [], $dog) : null;
        $followUp = $dog?->dueFollowUp(new \DateTimeImmutable());
        if ($days === null) {
            return new Response('', 204);
        }
        $isNew = $conversation === null;
        $conversation ??= $this->conversations->findOrCreateForDog($dog);
        if ($request->hasSession()) {
            $request->getSession()->save();
        }

        $stream = $this->environment !== 'test';
        $response = new StreamedResponse(function () use ($agent, $conversation, $isNew, $days, $dog, $followUp, $logger, $stream) {
            ignore_user_abort(true);
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
            try {
                $result = $agent->opener($conversation, $days, static fn (string $d) => $emit('delta', ['text' => $d]), $followUp);
                if ($result['text'] === '' && !$result['questions']) {
                    throw new \RuntimeException('Empty opener');
                }
                $message = (new ChatMessage($conversation, ChatMessage::ROLE_ASSISTANT, $result['text']))
                    ->setQuestions($result['questions'])
                    ->setUsage($result['usage']['input'], $result['usage']['output']);
                $this->em->persist($message);
                $conversation->touch();
                if ($followUp !== null) {
                    $dog->markFollowUp($followUp['id'], null, true); // asked: done
                }
                $this->em->flush();
                if ($result['questions']) {
                    $emit('questions', ['questions' => $result['questions']]);
                }
                $emit('done', []);
            } catch (\Throwable $e) {
                // Not worth bothering the pet parent: the chat simply opens without it.
                $logger->warning('Chat opener failed', ['exception' => $e]);
                if ($isNew && $conversation->getMessages()->isEmpty()) {
                    $this->em->remove($conversation);
                    $this->em->flush();
                }
                $emit('error', ['message' => '']);
            }
        });
        $response->headers->set('Content-Type', 'text/event-stream; charset=utf-8');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('X-Accel-Buffering', 'no');
        return $response;
    }

    /** Old links from when there were several chats per person. */
    #[Route('/c/{id}', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function legacyConversation(): Response
    {
        return $this->redirectToRoute('app_chat');
    }

    #[Route('/send', name: 'app_chat_send', methods: ['POST'])]
    public function send(Request $request, ClaudeClient $claude, ChatAgent $agent, SafetyCheck $safety, DogProfileRepository $dogs, \App\Tracking\Funnel $funnel, LoggerInterface $logger, \App\Twig\LocaleExtension $dates): Response
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
        // Every chat belongs to a pet; a pet's profile comes from the quiz.
        $dog = $this->dogFor($request, $user, $dogs);
        if ($dog === null) {
            return new JsonResponse(['error' => $this->translator->trans('Take the quiz first, so we know your dog.')], 400);
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

        // This pet's one chat, started by the first message.
        $conversation = $this->conversations->findForDog($dog);
        $isNew = $conversation === null;
        $conversation ??= $this->conversations->findOrCreateForDog($dog);

        $funnel->record('chat_message', \App\Tracking\Attribution::label($user->getAttribution()));
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
        $locale = $request->getLocale();
        $earlier = null;
        foreach (array_reverse($conversation->getMessages()->toArray()) as $m) {
            if ($m->isFromUser() && $m !== $userMessage) {
                $earlier = $m->getContent();
                break;
            }
        }
        $guard = $this->guard;
        $response = new StreamedResponse(function () use ($agent, $safety, $guard, $dog, $locale, $earlier, $text, $conversation, $userMessage, $isNew, $remaining, $logger, $stream, $quota, $user, $translator, $dates) {
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

            // Safety first: a red flag gets the approved "call your vet now" reply and nothing else.
            // It doesn't use up a message. The same check picks out lasting health facts (conditions,
            // past ones too, allergies, medicines), saved to the dog's notes before the coach answers (F6).
            $triage = $safety->triage($text, $dog?->getName(), $earlier);
            if ($dog && $triage['facts']) {
                $saved = [];
                foreach ($triage['facts'] as $f) {
                    if ($dog->addNote($f['kind'], $f['text'], new \DateTimeImmutable(), null, 'chat')) {
                        $saved[] = $f['text'];
                    }
                }
                if ($saved) {
                    $this->em->flush();
                    $emit('note', ['text' => $translator->trans('Saved to %dog%’s notes: %facts%', ['%dog%' => $dog->getName(), '%facts%' => implode('; ', $saved)])]);
                }
            }
            if ($flag = $triage['flag']) {
                $reply = SafetyCheck::reply($flag, $locale, $dog?->getName());
                $emit('delta', ['text' => $reply]);
                $this->em->persist((new ChatMessage($conversation, ChatMessage::ROLE_ASSISTANT, $reply))->setFlag('red_flag:' . $flag));
                $conversation->touch();
                $this->em->flush();
                $quota->refund($user);
                $emit('done', ['remaining' => $remaining === null ? null : $remaining + 1, 'urgent' => true]);
                return;
            }

            try {
                $result = $agent->reply($conversation, static fn (string $delta) => $emit('delta', ['text' => $delta]));
                $reply = $result['text'];
                if ($reply === '' && !$result['questions']) {
                    // Treated like a failed call below: nothing is saved and the message doesn't count.
                    throw new \RuntimeException('Empty reply from Claude (stop_reason: ' . ($result['stop_reason'] ?? 'none') . ')');
                }
                // The safety net for the dog's rules (allergies, conditions, medicines): a reply that
                // goes against them is replaced with the corrected one, and that's said (F6).
                $corrected = null;
                if ($dog && ($review = $guard->review($reply, $dog)) && !$review['ok']) {
                    $logger->warning('Coach reply corrected against the dog\'s notes', ['problem' => $review['problem']]);
                    $reply = $review['corrected'];
                    $corrected = $review['problem'];
                    $emit('replace', ['text' => $reply]);
                }
                if (($result['stop_reason'] ?? null) === 'max_tokens') {
                    $logger->warning('Chat reply hit max_tokens and was trimmed', ['usage' => $result['usage']]);
                }

                $assistant = (new ChatMessage($conversation, ChatMessage::ROLE_ASSISTANT, $reply))
                    ->setQuestions($result['questions'])
                    ->setUsage($result['usage']['input'], $result['usage']['output']);
                if ($corrected !== null) {
                    $assistant->setFlag('corrected');
                }
                $this->em->persist($assistant);
                $conversation->touch();
                $this->em->flush();

                if ($result['questions']) {
                    $emit('questions', ['questions' => $result['questions']]);
                }
                if ($result['food']) {
                    $emit('note', ['text' => $translator->trans('Saved to your dog’s profile: %food%', ['%food%' => trim($result['food']['brand'] . ' ' . $result['food']['product'])])]);
                }
                if ($result['followup'] ?? null) {
                    $emit('note', ['text' => $translator->trans('The coach will check in on %date%.', ['%date%' => $dates->format(new \DateTimeImmutable($result['followup']['due']), 'day', $locale)])]);
                }
                if ($result['remembered'] ?? []) {
                    $emit('note', ['text' => $translator->trans('Saved to %dog%’s notes: %facts%', ['%dog%' => $dog?->getName() ?? '', '%facts%' => implode('; ', $result['remembered'])])]);
                }
                if ($corrected !== null) {
                    $emit('note', ['text' => $translator->trans('Corrected to respect %dog%’s notes: %problem%', ['%dog%' => $dog?->getName() ?? '', '%problem%' => $corrected])]);
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
        $dog = $this->dogFor($request, $user, $dogs);
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

    /** Empties this pet's chat (the chat itself stays: one per pet). The day's message count stays as it is. */
    #[Route('/clear', name: 'app_chat_clear', methods: ['POST'])]
    public function clear(Request $request, DogProfileRepository $dogs): Response
    {
        $user = $this->currentUser();
        $dog = $this->dogFor($request, $user, $dogs);
        $conversation = $dog ? $this->conversations->findForDog($dog) : null;
        if ($conversation && $this->isCsrfTokenValid('chat-clear', (string) $request->request->get('_token'))) {
            $this->files->removeForMessages($user, $conversation->getMessages()->toArray());
            $this->conversations->clearMessages($conversation);
        }

        return $this->redirectToRoute('app_chat', $dog ? ['pet' => $dog->getId()] : []);
    }

    /** The pet a request is about: ?pet=<id> (one of theirs), else the selected pet, else none. */
    private function dogFor(Request $request, User $user, DogProfileRepository $dogs): ?DogProfile
    {
        $id = (int) ($request->query->get('pet') ?: $request->request->get('pet'));
        if ($id > 0) {
            foreach ($dogs->findAllForUser($user) as $d) {
                if ($d->getId() === $id) {
                    return $d;
                }
            }
        }

        return $dogs->findForUser($user);
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
