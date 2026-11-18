<?php

namespace App\Controller\Admin;

use App\Chat\CheckIns;
use App\Entity\ChatConversation;
use App\Entity\ChatMessage;
use App\Repository\DogProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Coach conversations, read-only: what testers (Agota, vets) and pet parents asked the AI coach
 * and what it answered, with their dog's profile and check-in answers beside it. For improving
 * config/chatbot/system_prompt.md. "Download as text" gives one conversation as plain text.
 */
#[Route('/admin/conversations')]
#[IsGranted('ROLE_ADMIN')]
final class ConversationsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DogProfileRepository $dogs,
        private readonly CheckIns $checkIns,
    ) {
    }

    #[Route('', name: 'admin_conversations', methods: ['GET'])]
    public function index(): Response
    {
        $rows = $this->em->createQuery(
            'SELECT c AS conversation, u.email AS email, u.firstName AS firstName,
                    COUNT(m.id) AS messages,
                    SUM(CASE WHEN m.role = :user THEN 1 ELSE 0 END) AS questions,
                    SUM(COALESCE(m.inputTokens, 0)) AS inputTokens, SUM(COALESCE(m.outputTokens, 0)) AS outputTokens,
                    SUM(CASE WHEN m.flag IS NOT NULL THEN 1 ELSE 0 END) AS alerts,
                    SUM(CASE WHEN m.review IS NOT NULL THEN 1 ELSE 0 END) AS reviews
             FROM ' . ChatConversation::class . ' c JOIN c.user u LEFT JOIN c.messages m
             GROUP BY c.id, u.email, u.firstName
             ORDER BY c.updatedAt DESC'
        )->setParameter('user', ChatMessage::ROLE_USER)->setMaxResults(500)->getResult();

        $dogs = [];
        foreach ($this->dogs->findAll() as $dog) {
            $dogs[$dog->getUser()->getId()] = $dog;
        }

        return $this->render('admin/conversations/index.html.twig', ['rows' => $rows, 'dogs' => $dogs]);
    }

    /**
     * Roadmap 07, "a vet reviews a sample": random coach replies from the last 30 days with the
     * question before each, plus every safety alert and every reply already marked. The vet (or
     * anyone on the team) writes a note on a reply; notes show here and in the conversation.
     */
    #[Route('/sample', name: 'admin_conversations_sample', methods: ['GET'])]
    public function sample(Request $request): Response
    {
        $n = max(5, min(50, $request->query->getInt('n', 20)));
        $recent = $this->em->createQuery('SELECT m FROM ' . ChatMessage::class . ' m WHERE m.role = :bot AND m.createdAt > :since ORDER BY m.id DESC')
            ->setParameter('bot', ChatMessage::ROLE_ASSISTANT)->setParameter('since', new \DateTimeImmutable('-30 days'))
            ->setMaxResults(1000)->getResult();
        $marked = array_values(array_filter($recent, fn (ChatMessage $m) => $m->getFlag() !== null || $m->getReview() !== null));
        $rest = array_values(array_filter($recent, fn (ChatMessage $m) => $m->getFlag() === null && $m->getReview() === null));
        shuffle($rest);
        $pick = fn (ChatMessage $m) => ['reply' => $m, 'question' => $this->questionBefore($m)];

        return $this->render('admin/conversations/sample.html.twig', [
            'random' => array_map($pick, \array_slice($rest, 0, $n)),
            'marked' => array_map($pick, $marked),
            'n' => $n,
        ]);
    }

    /** Saves (or clears) a review note on one coach reply. */
    #[Route('/message/{id}/review', name: 'admin_message_review', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function review(ChatMessage $message, Request $request): Response
    {
        if ($this->isCsrfTokenValid('review' . $message->getId(), (string) $request->request->get('_token'))) {
            $message->setReview((string) $request->request->get('note', ''));
            $this->em->flush();
        }
        $back = (string) $request->request->get('back', '');
        return $this->redirect(str_starts_with($back, '/admin/') ? $back : $this->generateUrl('admin_conversation', ['id' => $message->getConversation()->getId()]));
    }

    private function questionBefore(ChatMessage $reply): ?ChatMessage
    {
        $before = null;
        foreach ($reply->getConversation()->getMessages() as $m) {
            if ($m === $reply) {
                return $before;
            }
            if ($m->isFromUser()) {
                $before = $m;
            }
        }
        return $before;
    }

    #[Route('/{id}', name: 'admin_conversation', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(ChatConversation $conversation): Response
    {
        $dog = $this->dogs->findForUser($conversation->getUser());

        return $this->render('admin/conversations/show.html.twig', [
            'conversation' => $conversation,
            'dog' => $dog,
            'checkins' => $dog ? $this->checkIns->forCoach($dog) : '',
        ]);
    }

    #[Route('/{id}.txt', name: 'admin_conversation_text', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function text(ChatConversation $conversation): Response
    {
        $user = $conversation->getUser();
        $out = ['Conversation #' . $conversation->getId() . ' with ' . $user->getEmail() . ' (' . $user->getLocale() . ')', ''];
        foreach ($conversation->getMessages() as $m) {
            $out[] = '[' . $m->getCreatedAt()->format('Y-m-d H:i') . '] ' . ($m->isFromUser() ? 'PET PARENT' : 'COACH') . ':';
            foreach ($m->getAttachments() as $a) {
                $out[] = '  (' . ($a['type'] === 'application/pdf' ? 'PDF' : 'photo') . ') ' . $a['name'];
            }
            $out[] = $m->getContent();
            foreach ($m->getQuestions() ?? [] as $q) {
                $out[] = '  (card) ' . $q['question'] . ($q['options'] ? ' — ' . implode(' / ', array_column($q['options'], 'label')) : '');
            }
            $out[] = '';
        }

        return new Response(implode("\n", $out), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="zenoo-conversation-' . $conversation->getId() . '.txt"',
        ]);
    }
}
