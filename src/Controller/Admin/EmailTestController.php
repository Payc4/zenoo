<?php

namespace App\Controller\Admin;

use App\Entity\Product;
use App\Entity\Purchase;
use App\Entity\QuizSubmission;
use App\Entity\User;
use App\Service\EmailService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sends one of each site email, with sample data, to the signed-in admin. Shows the design, and in
 * Klaviyo mode it creates every "ZENOO …" metric, which Klaviyo needs before a flow can start from it.
 * Nothing is saved: the order, quiz and dog in these emails are samples.
 */
#[IsGranted('ROLE_ADMIN')]
final class EmailTestController extends AbstractController
{
    public const LOCALES = ['en', 'lt', 'uk', 'pl'];

    #[Route('/admin/email-test', name: 'admin_email_test', methods: ['GET', 'POST'])]
    public function index(Request $request, EmailService $emails): Response
    {
        /** @var User $admin */
        $admin = $this->getUser();
        $locale = \in_array($request->request->get('locale'), self::LOCALES, true) ? (string) $request->request->get('locale') : 'en';
        $results = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('email-test', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $results = $this->sendAll($emails, $admin, $locale);
        }

        return $this->render('admin/email_test.html.twig', [
            'to' => $admin->getEmail(),
            'locale' => $locale,
            'locales' => self::LOCALES,
            'deliveries' => array_combine(self::LOCALES, array_map($emails->deliveryFor(...), self::LOCALES)),
            'configured' => EmailService::deliveryConfigured(),
            'events' => EmailService::EVENTS,
            'results' => $results,
        ]);
    }

    /** @return array<string, string|null> event => null when sent, or the error */
    private function sendAll(EmailService $emails, User $admin, string $locale): array
    {
        // A detached copy in the chosen language: the admin's own account is never changed.
        $user = clone $admin;
        $user->setLocale($locale);
        $app = rtrim((string) $this->getParameter('app.url'), '/');
        $dog = 'Luna';

        $product = (new Product())->setName('ZENOO sample order')->setSlug('sample')->setPrice('9.99');
        $purchase = (new Purchase())->setUser($user)->setProduct($product)->setAmount('9.99')->setCurrency('eur')
            ->setStatus(Purchase::STATUS_COMPLETED)->setTestMode(true)->setStripeSessionId('cs_test_sample');
        (new \ReflectionProperty(Purchase::class, 'id'))->setValue($purchase, 1);

        $submission = new QuizSubmission($admin->getEmail(), $admin->getFirstName(), $locale, ['quiz_dog_name' => $dog, 'quiz_bowl_score' => 62], 'sample', 'sample');

        $plan = [
            'dog' => $dog, 'score' => 62, 'kcal' => 640,
            'fixes' => ['Weigh the food with a kitchen scale for two weeks.', 'Swap the chew sticks for two small carrot pieces a day.', 'Add one 20-minute sniff walk on rest days.'],
            'food' => '150 g dry food a day', 'meals' => '2 meals a day', 'zenoo' => null,
        ];
        $d = ['%dog%' => $dog];
        $sends = [
            'welcome' => fn () => $emails->sendWelcomeEmail($user),
            'order' => fn () => $emails->sendPurchaseConfirmation($purchase),
            'reset' => fn () => $emails->sendPasswordResetEmail($user, $app . '/reset-password/sample-link'),
            'login' => fn () => $emails->sendMagicLoginLink($user, $app . '/login/check?sample=1', new \DateTimeImmutable('+10 minutes')),
            'changed' => fn () => $emails->sendPasswordChangedNotice($user),
            'quiz' => fn () => $emails->sendQuizConfirmation($submission, $app . '/quiz/confirm/sample-link'),
            'plan' => fn () => $emails->sendPlanEmail($user, $plan, ['dashboard' => $app . '/dashboard', 'plan' => $app . '/dashboard/dog']),
            'followup' => fn () => $emails->sendCoachFollowUp($user, $dog, 'Two weeks in: what does Luna weigh now?', $app . '/chat', $app . '/account'),
            'series' => fn () => $emails->sendSeriesEmail($user, $dog, 3, [
                'subject' => ['A 5-minute lesson for %dog%', $d],
                'heading' => ['A 5-minute lesson for %dog%', $d],
                'text' => ['The ZENOO course has 30 short lessons on feeding, with a voice to listen to. Start with the first one: it explains what’s really in the bowl and why it matters for %dog%.', $d],
                'button' => 'Start the first lesson',
            ], $app . '/courses', $app . '/account'),
        ];

        $results = [];
        foreach ($sends as $key => $send) {
            try {
                $send();
                $results[EmailService::EVENTS[$key] ?? $key] = null;
            } catch (\Throwable $e) {
                $results[EmailService::EVENTS[$key] ?? $key] = $e->getMessage();
            }
        }

        return $results;
    }
}
