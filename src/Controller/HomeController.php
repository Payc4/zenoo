<?php

namespace App\Controller;

use App\Repository\ProductRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
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
    public function privacy(): Response
    {
        return $this->render('legal/privacy.html.twig');
    }

    /** The Bowl Score quiz from the ZENOO handoff, served as-is from public/quiz/. */
    #[Route('/quiz/', name: 'app_quiz', methods: ['GET'])]
    public function quiz(
        #[Autowire('%app.quiz.klaviyo_company_id%')] string $klaviyoCompanyId,
        #[Autowire('%app.quiz.klaviyo_list_id%')] string $klaviyoListId,
        #[Autowire('%app.quiz.send_to_klaviyo%')] bool $sendToKlaviyo,
    ): Response {
        return $this->render('quiz/index.html.twig', [
            'quizConfig' => [
                'locale' => 'en',
                'klaviyoCompanyId' => $klaviyoCompanyId,
                'klaviyoListId' => $klaviyoListId,
                'sendToKlaviyo' => $sendToKlaviyo && $klaviyoCompanyId !== '' && $klaviyoListId !== '',
                'privacyUrl' => 'https://myzenoo.com/policies/privacy-policy',
                'emailPhotoBase' => 'https://cdn.shopify.com/s/files/1/0863/5265/8779/files/zq-email-',
                'quizVersion' => 'site-1',
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
