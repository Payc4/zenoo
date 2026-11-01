<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class SecurityController extends AbstractController
{
    use TargetPathTrait;

    /** One login link per account per minute, so the form can't be used to flood an inbox. */
    private const LINK_COOLDOWN_SECONDS = 60;

    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(Request $request, AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_account');
        }

        // ?next=/shop/test-product sends the pet parent back to that page after logging in.
        $next = (string) $request->query->get('next', '');
        if (self::isSafeLocalPath($next)) {
            $this->saveTargetPath($request->getSession(), 'main', $next);
        }

        return $this->render('auth/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'tab' => $request->query->get('tab') === 'link' ? 'link' : 'password',
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Handled by the logout key on the firewall.');
    }

    #[Route('/login/check', name: 'login_check', methods: ['GET'])]
    public function check(): never
    {
        throw new \LogicException('Handled by the login_link authenticator.');
    }

    #[Route('/login/link', name: 'app_send_login_link', methods: ['POST'])]
    public function sendLoginLink(
        Request $request,
        LoginLinkHandlerInterface $loginLinkHandler,
        EmailService $emails,
        UserRepository $users,
        EntityManagerInterface $em,
        LoggerInterface $logger,
    ): Response {
        if (!$this->isCsrfTokenValid('login-link', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Your session expired. Please try again.');

            return $this->redirectToRoute('app_login', ['tab' => 'link']);
        }

        $email = (string) $request->request->get('email', '');
        // Same message whether or not the account exists, so the form can't be used to find accounts.
        $message = 'If there’s a ZENOO account for that email, a login link is on its way. It works once, for 10 minutes.';
        $user = $email !== '' ? $users->findOneByEmail($email) : null;

        $last = $user?->getLastMagicLinkRequestedAt();
        if ($user && (!$last || $last->getTimestamp() < time() - self::LINK_COOLDOWN_SECONDS)) {
            try {
                // Changing this timestamp invalidates any link sent before (it's part of the signature).
                $user->setLastMagicLinkRequestedAt(new \DateTimeImmutable());
                $em->flush();
                $link = $loginLinkHandler->createLoginLink($user, $request);
                $emails->sendMagicLoginLink($user, $link->getUrl(), $link->getExpiresAt());
            } catch (\Throwable $e) {
                $logger->error('Login link email failed', ['user' => $user->getId(), 'error' => $e->getMessage()]);
            }
        }

        $this->addFlash('success', $message);

        return $this->redirectToRoute('app_login', ['tab' => 'link']);
    }

    public static function isSafeLocalPath(string $path): bool
    {
        return $path !== '' && str_starts_with($path, '/') && !str_starts_with($path, '//') && !str_contains($path, '\\');
    }
}
