<?php

namespace App\Controller;

use App\Entity\Purchase;
use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\ChatConversationRepository;
use App\Repository\DogProfileRepository;
use App\Repository\LessonProgressRepository;
use App\Repository\QuizSubmissionRepository;
use App\Service\AvatarUploader;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Everything a signed-in pet parent can see and change about their own account. */
#[Route('/account')]
#[IsGranted('ROLE_USER')]
class AccountController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly TranslatorInterface $translator)
    {
    }

    #[Route('', name: 'app_account', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('account/index.html.twig', ['user' => $this->currentUser()]);
    }

    #[Route('/details', name: 'app_account_details', methods: ['POST'])]
    public function details(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('account-details', (string) $request->request->get('_csrf_token'))) {
            return $this->back('error', 'Your session expired. Please try again.');
        }

        $first = trim((string) $request->request->get('firstName', ''));
        $last = trim((string) $request->request->get('lastName', ''));
        if ($first === '' || mb_strlen($first) > 100 || mb_strlen($last) > 100) {
            return $this->back('error', 'Please enter a first name (up to 100 characters).', 'details');
        }

        $this->currentUser()->setFirstName($first)->setLastName($last !== '' ? $last : null);
        $this->em->flush();

        return $this->back('success', 'Your name is saved.', 'details');
    }

    #[Route('/photo', name: 'app_account_photo', methods: ['POST'])]
    public function photo(Request $request, AvatarUploader $avatars): Response
    {
        if (!$this->isCsrfTokenValid('account-photo', (string) $request->request->get('_csrf_token'))) {
            return $this->back('error', 'Your session expired. Please try again.', 'details');
        }

        $user = $this->currentUser();
        if ($request->request->has('remove')) {
            $avatars->remove($user->getProfileImage());
            $user->setProfileImage(null);
            $this->em->flush();

            return $this->back('success', 'Your photo is removed.', 'details');
        }

        $file = $request->files->get('photo');
        if (!$file) {
            return $this->back('error', 'Please choose a photo first.', 'details');
        }
        try {
            $user->setProfileImage($avatars->store($user, $file));
        } catch (\InvalidArgumentException $e) {
            return $this->back('error', $e->getMessage(), 'details');
        }
        $this->em->flush();

        return $this->back('success', 'Your new photo is saved.', 'details');
    }

    #[Route('/password', name: 'app_account_password', methods: ['POST'])]
    public function password(Request $request, UserPasswordHasherInterface $hasher, Security $security, EmailService $emails, LoggerInterface $logger): Response
    {
        if (!$this->isCsrfTokenValid('account-password', (string) $request->request->get('_csrf_token'))) {
            return $this->back('error', 'Your session expired. Please try again.', 'password');
        }

        $user = $this->currentUser();
        $current = (string) $request->request->get('currentPassword', '');
        $new = (string) $request->request->get('newPassword', '');

        // Accounts made from the quiz have no password of their own yet: nothing to check.
        if ($user->hasPassword() && !$hasher->isPasswordValid($user, $current)) {
            return $this->back('error', 'Your current password isn’t right.', 'password');
        }
        if (strlen($new) < RegistrationFormType::MIN_PASSWORD) {
            return $this->back('error', $this->translator->trans('Your new password needs at least %n% characters.', ['%n%' => RegistrationFormType::MIN_PASSWORD]), 'password');
        }
        if ($new !== (string) $request->request->get('confirmPassword', '')) {
            return $this->back('error', 'The two new passwords don’t match.', 'password');
        }

        $firstPassword = !$user->hasPassword();
        $user->setPassword($hasher->hashPassword($user, $new))->setHasPassword(true);
        $this->em->flush();
        // Remember-me cookies are signed with the password hash, so other devices are now logged out.
        // Log this browser in again with a fresh session.
        $security->login($user, 'App\Security\LoginFormAuthenticator', 'main');

        if ($firstPassword) {
            return $this->back('success', 'Your password is set. You can now log in with it.', 'password');
        }
        try {
            $emails->sendPasswordChangedNotice($user);
        } catch (\Throwable $e) {
            $logger->error('Password-changed email failed', ['user' => $user->getId(), 'error' => $e->getMessage()]);
        }

        return $this->back('success', 'Your password is changed. Other devices have been logged out.', 'password');
    }

    #[Route('/orders/{id}', name: 'app_account_order', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function order(int $id): Response
    {
        $purchase = $this->em->getRepository(Purchase::class)->find($id);
        if (!$purchase || $purchase->getUser() !== $this->currentUser()) {
            throw $this->createNotFoundException();
        }

        return $this->render('account/order.html.twig', ['purchase' => $purchase]);
    }

    #[Route('/delete', name: 'app_account_delete', methods: ['POST'])]
    public function delete(Request $request, UserPasswordHasherInterface $hasher, Security $security, AvatarUploader $avatars, ChatConversationRepository $chats, LessonProgressRepository $lessonProgress, DogProfileRepository $dogs, QuizSubmissionRepository $quizSubmissions): Response
    {
        if (!$this->isCsrfTokenValid('account-delete', (string) $request->request->get('_csrf_token'))) {
            return $this->back('error', 'Your session expired. Please try again.', 'delete');
        }

        $user = $this->currentUser();
        if ($user->isAdmin()) {
            return $this->back('error', 'Admin accounts can’t be deleted here.', 'delete');
        }
        // Quiz-made accounts without a password confirm by typing DELETE instead.
        $confirmed = $user->hasPassword()
            ? $hasher->isPasswordValid($user, (string) $request->request->get('password', ''))
            : mb_strtoupper(trim((string) $request->request->get('password', ''))) === 'DELETE';
        if (!$confirmed) {
            return $this->back('error', 'That password isn’t right, so nothing was deleted.', 'delete');
        }

        $avatars->remove($user->getProfileImage());
        $chats->deleteAllForUser($user);
        $lessonProgress->deleteAllForUser($user);
        $dogs->deleteForUser($user);
        $quizSubmissions->deleteForEmail($user->getEmail());
        $security->logout(false);
        foreach ($user->getPurchases() as $purchase) {
            $this->em->remove($purchase);
        }
        $this->em->remove($user);
        $this->em->flush();

        $this->addFlash('success', 'Your ZENOO account and its data are deleted. Payment records stay with Stripe, as the law requires.');

        return $this->redirectToRoute('app_homepage');
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function back(string $type, string $message, string $section = ''): Response
    {
        $this->addFlash($type, $message);

        return $this->redirect($this->generateUrl('app_account') . ($section !== '' ? '#' . $section : ''));
    }
}
