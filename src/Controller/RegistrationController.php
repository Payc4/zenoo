<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Security\LoginFormAuthenticator;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $hasher,
        Security $security,
        EntityManagerInterface $em,
        EmailService $emails,
        LoggerInterface $logger,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_account');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($hasher->hashPassword($user, (string) $form->get('plainPassword')->getData()));
            $em->persist($user);
            $em->flush();

            try {
                $emails->sendWelcomeEmail($user);
            } catch (\Throwable $e) {
                $logger->error('Welcome email failed', ['user' => $user->getId(), 'error' => $e->getMessage()]);
            }

            $this->addFlash('success', sprintf('Welcome to ZENOO, %s! Your account is ready.', $user->getDisplayName()));

            // Logs the new account in and follows any saved ?next= page (e.g. the product they wanted).
            return $security->login($user, LoginFormAuthenticator::class, 'main');
        }

        return $this->render('auth/register.html.twig', [
            'registrationForm' => $form,
        ], new Response(null, $form->isSubmitted() ? 422 : 200));
    }
}
