<?php

namespace App\Controller;

use App\Repository\ProductRepository;
use Doctrine\DBAL\Connection;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_homepage', methods: ['GET'])]
    public function index(ProductRepository $products): Response
    {
        // The home page is the welcome for visitors; logged-in pet parents go to their dog's dashboard.
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_dashboard');
        }
        return $this->render('home/index.html.twig', [
            'products' => $products->findActive(),
        ]);
    }

    /** Roadmap 10: the open method. METHOD_REVIEWED_BY names the vet who reviewed it, once there is one. */
    #[Route('/how-the-bowl-score-works', name: 'app_method', methods: ['GET'])]
    public function method(#[Autowire('%env(default::METHOD_REVIEWED_BY)%')] ?string $reviewedBy): Response
    {
        return $this->render('legal/method.html.twig', ['reviewedBy' => $reviewedBy ?: null]);
    }

    #[Route('/privacy-policy', name: 'app_privacy_policy', methods: ['GET'])]
    public function privacy(Request $request): Response
    {
        $locale = $request->getLocale();
        $localised = "legal/privacy.$locale.html.twig";
        return $this->render($locale !== 'en' && $this->container->get('twig')->getLoader()->exists($localised) ? $localised : 'legal/privacy.html.twig');
    }

    /**
     * The Bowl Score quiz from the ZENOO handoff, served from public/quiz/ in the page's language
     * (content.<locale>.js). English quiz-takers join the main Klaviyo account's list, Lithuanian ones
     * the Lithuanian account's list (if set). Ukrainian and Polish ones join no list; the site emails them.
     */
    #[Route('/quiz/', name: 'app_quiz', methods: ['GET'])]
    public function quiz(
        Request $request,
        #[Autowire('%app.quiz.klaviyo_company_id%')] string $klaviyoCompanyId,
        #[Autowire('%app.quiz.klaviyo_list_id%')] string $klaviyoListId,
        #[Autowire('%app.quiz.klaviyo_lt_company_id%')] ?string $klaviyoLtCompanyId,
        #[Autowire('%app.quiz.klaviyo_lt_list_id%')] ?string $klaviyoLtListId,
        #[Autowire('%app.quiz.klaviyo_uk_company_id%')] ?string $klaviyoUkCompanyId,
        #[Autowire('%app.quiz.klaviyo_uk_list_id%')] ?string $klaviyoUkListId,
        #[Autowire('%app.quiz.klaviyo_pl_company_id%')] ?string $klaviyoPlCompanyId,
        #[Autowire('%app.quiz.klaviyo_pl_list_id%')] ?string $klaviyoPlListId,
        #[Autowire('%app.quiz.send_to_klaviyo%')] bool $sendToKlaviyo,
        \App\Chat\ClaudeClient $claude,
        \App\Catalogue\PlanBuilder $plans,
        CsrfTokenManagerInterface $csrf,
        \App\Repository\DogProfileRepository $dogs,
        \App\Payment\Subscriptions $subscriptions,
        \Symfony\Contracts\Translation\TranslatorInterface $translator,
    ): Response {
        $user = $this->getUser();
        // "Retake the quiz for Max" (?dog=<id>): start from Max's own saved answers, saved to Max (N2, F1).
        $retake = null;
        if ($user instanceof User && ($id = (int) $request->query->get('dog')) > 0) {
            foreach ($dogs->findAllForUser($user) as $d) {
                if ($d->getId() === $id) {
                    // A dog saved before the answers were kept: start from what its result saved.
                    $answers = $d->getQuizAnswers();
                    $retake = ['id' => $d->getId(), 'name' => $d->getName(), 'answers' => isset($answers['name']) ? $answers : \App\Quiz\ScoreInputs::retakeAnswers($d)];
                }
            }
        }
        $locale = \App\Locale::normalize($request->getLocale());
        if (!is_file($this->getParameter('kernel.project_dir') . "/public/quiz/content.$locale.js")) {
            $locale = 'en';
        }
        // A Klaviyo list per language (N8). Ukrainian and Polish only when their lists are set.
        [$company, $list] = match ($locale) {
            'en' => [$klaviyoCompanyId, $klaviyoListId],
            'lt' => [(string) $klaviyoLtCompanyId, (string) $klaviyoLtListId],
            'uk' => [(string) $klaviyoUkCompanyId, (string) $klaviyoUkListId],
            'pl' => [(string) $klaviyoPlCompanyId, (string) $klaviyoPlListId],
            default => ['', ''],
        };

        return $this->render('quiz/index.html.twig', [
            'locale' => $locale,
            'quizConfig' => [
                'locale' => $locale,
                'klaviyoCompanyId' => $company,
                'klaviyoListId' => $list,
                'sendToKlaviyo' => $sendToKlaviyo && $company !== '' && $list !== '',
                // The app's own policy (quiz, account, coach, photos), not the shop's.
                'privacyUrl' => $this->generateUrl('app_privacy_policy', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL),
                // The personal ZENOO plan on the result (roadmap 01), when the catalogue has a food.
                'zenoo' => $plans->forQuiz(),
                // The "data sent to Klaviyo" panel on the result is for local testing only.
                'debug' => (bool) $this->getParameter('kernel.debug'),
                'emailPhotoBase' => 'https://cdn.shopify.com/s/files/1/0863/5265/8779/files/zq-email-',
                'quizVersion' => 'site-1',
                // Quiz → account (QuizAccountController)
                'accountSubmitUrl' => $this->generateUrl('app_quiz_submit'),
                'accountToken' => $csrf->getToken('quiz')->getValue(),
                // One-time "let our AI read the label" in the label step (QuizLabelController).
                'labelScanUrl' => $claude->isConfigured() ? $this->generateUrl('app_quiz_label_scan') : null,
                'user' => $user instanceof User ? ['id' => $user->getId(), 'firstName' => $user->getFirstName(), 'email' => $user->getEmail()] : null,
                'retake' => $retake,
                // The free trial (Subscriptions): the score is free, the rest opens with a month's trial.
                'trial' => $this->trialConfig($subscriptions, $translator, $user instanceof User ? $user : null, $locale, $csrf),
            ],
        ]);
    }

    /** Used by the Docker health check: also proves the database is reachable. */
    #[Route('/health', name: 'app_health_check', methods: ['GET'])]
    public function health(Connection $db): JsonResponse
    {
        try {
            $db->executeQuery('SELECT 1');
        } catch (\Throwable) {
            return new JsonResponse(['status' => 'error', 'database' => 'unreachable'], 503);
        }

        return new JsonResponse(['status' => 'ok']);
    }

    /** What the quiz result needs for the free-trial card, in the quiz's language; null when the paywall is off. */
    private function trialConfig(\App\Payment\Subscriptions $subscriptions, \Symfony\Contracts\Translation\TranslatorInterface $translator, ?User $user, string $locale, CsrfTokenManagerInterface $csrf): ?array
    {
        if (!$subscriptions->isEnabled() || ($user !== null && $subscriptions->hasAccess($user, $this->isGranted('ROLE_ADMIN')))) {
            return null;
        }
        $t = fn (string $m, array $p = []) => $translator->trans($m, $p, null, $locale);
        $days = \App\Payment\Subscriptions::TRIAL_DAYS;
        $price = $subscriptions->monthlyPrice();

        return [
            'url' => $this->generateUrl('app_trial_start'),
            'csrf' => $csrf->getToken('trial')->getValue(),
            'badge' => $subscriptions->showBadge() ? $t('Cyber Monday Special') : null,
            'title' => $t('Your recommendations are ready'),
            'text' => $t('Your recommendations, daily plan and lessons open with the free month.'),
            'start' => $t('Start free trial'),
            'saving' => $t('Saving your result…'),
            'terms' => ($price !== null
                ? $t('Free for %days% days, then €%price% a month. Cancel any time in your account.', ['%days%' => $days, '%price%' => number_format($price, 2, \App\Locale::decimalSeparator($locale), ' ')])
                : $t('Free for %days% days, then a monthly plan. Cancel any time in your account.', ['%days%' => $days]))
                . ' ' . $t('A card is needed to start; nothing is charged during the free month.'),
        ];
    }
}
