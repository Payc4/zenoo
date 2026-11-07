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
        return $this->render('home/index.html.twig', [
            'products' => $products->findActive(),
        ]);
    }

    #[Route('/privacy-policy', name: 'app_privacy_policy', methods: ['GET'])]
    public function privacy(Request $request): Response
    {
        return $this->render($request->getLocale() === 'lt' ? 'legal/privacy.lt.html.twig' : 'legal/privacy.html.twig');
    }

    /**
     * The Bowl Score quiz from the ZENOO handoff, served from public/quiz/ in the page's language
     * (content.en.js or content.lt.js). Each language has its own Klaviyo account and list.
     */
    #[Route('/quiz/', name: 'app_quiz', methods: ['GET'])]
    public function quiz(
        Request $request,
        #[Autowire('%app.quiz.klaviyo_company_id%')] string $klaviyoCompanyId,
        #[Autowire('%app.quiz.klaviyo_list_id%')] string $klaviyoListId,
        #[Autowire('%app.quiz.klaviyo_lt_company_id%')] ?string $klaviyoLtCompanyId,
        #[Autowire('%app.quiz.klaviyo_lt_list_id%')] ?string $klaviyoLtListId,
        #[Autowire('%app.quiz.send_to_klaviyo%')] bool $sendToKlaviyo,
        CsrfTokenManagerInterface $csrf,
    ): Response {
        $user = $this->getUser();
        $locale = $request->getLocale() === 'lt' ? 'lt' : 'en';
        [$company, $list] = $locale === 'lt' ? [(string) $klaviyoLtCompanyId, (string) $klaviyoLtListId] : [$klaviyoCompanyId, $klaviyoListId];

        return $this->render('quiz/index.html.twig', [
            'locale' => $locale,
            'quizConfig' => [
                'locale' => $locale,
                'klaviyoCompanyId' => $company,
                'klaviyoListId' => $list,
                'sendToKlaviyo' => $sendToKlaviyo && $company !== '' && $list !== '',
                'privacyUrl' => 'https://myzenoo.com/policies/privacy-policy',
                'emailPhotoBase' => 'https://cdn.shopify.com/s/files/1/0863/5265/8779/files/zq-email-',
                'quizVersion' => 'site-1',
                // Quiz → account (QuizAccountController)
                'accountSubmitUrl' => $this->generateUrl('app_quiz_submit'),
                'accountToken' => $csrf->getToken('quiz')->getValue(),
                'user' => $user instanceof User ? ['firstName' => $user->getFirstName(), 'email' => $user->getEmail()] : null,
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
}
