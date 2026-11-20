<?php

namespace App\Controller;

use App\Chat\ChatFiles;
use App\Entity\PhotoHandoff;
use App\Entity\User;
use App\Locale;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Send a photo from your phone" (public/photo-picker.js):
 *
 *  1. The computer asks for a hand-off (POST /photo-handoff) and shows its link as a QR code.
 *  2. The phone opens the link (GET /p/{token}): a page with a big "Take a photo" button that
 *     opens the camera, and sends each photo (POST /p/{token}).
 *  3. The computer checks every couple of seconds (GET /photo-handoff/{id}), fetches the photos
 *     and puts them where the person was (chat message box, dog photo, label scan), then closes
 *     the hand-off (POST /photo-handoff/{id}/close), which deletes them.
 *
 * Only the computer that asked can read the photos: it is the owner (signed-in user, or the
 * browser session for visitors taking the quiz). The phone needs nothing but the link, which
 * ends after PhotoHandoff::TTL_MINUTES and takes at most 3 photos.
 */
final class PhotoHandoffController extends AbstractController
{
    private const MAX_ACTIVE = 5;
    private const MAX_UPLOAD_BYTES = 15 * 1024 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        #[Autowire('%kernel.project_dir%/public/uploads/private/handoff')] private readonly string $dir,
    ) {
    }

    #[Route('/photo-handoff', name: 'app_handoff_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->sameSite($request)) {
            return $this->json(['error' => 'Please reload the page and try again.'], 400);
        }
        $this->purge();
        $owner = $this->owner($request, true);
        $repo = $this->em->getRepository(PhotoHandoff::class);
        $active = $repo->findBy(['owner' => $owner], ['id' => 'DESC']);
        foreach (\array_slice($active, self::MAX_ACTIVE - 1) as $old) {
            $this->remove($old);
        }

        $token = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        $max = (int) (json_decode((string) $request->getContent(), true)['max'] ?? 1);
        $handoff = new PhotoHandoff($token, $owner, $max, $request->getLocale());
        $this->em->persist($handoff);
        $this->em->flush();

        return $this->json([
            'id' => $handoff->getId(),
            'url' => $this->generateUrl('app_handoff_phone', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL),
            'poll' => $this->generateUrl('app_handoff_poll', ['id' => $handoff->getId()]),
            'close' => $this->generateUrl('app_handoff_close', ['id' => $handoff->getId()]),
            'expiresIn' => PhotoHandoff::TTL_MINUTES * 60,
            'max' => $handoff->getMaxFiles(),
        ]);
    }

    #[Route('/photo-handoff/{id}', name: 'app_handoff_poll', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function poll(Request $request, int $id): JsonResponse
    {
        $handoff = $this->mine($request, $id);
        $files = [];
        foreach ($handoff->getFiles() as $i => $f) {
            $files[] = $this->generateUrl('app_handoff_file', ['id' => $id, 'index' => $i]);
        }
        return new JsonResponse(['files' => $files, 'expired' => $handoff->isExpired(), 'full' => $handoff->isFull()], 200, ['Cache-Control' => 'no-store']);
    }

    #[Route('/photo-handoff/{id}/file/{index}', name: 'app_handoff_file', requirements: ['id' => '\d+', 'index' => '\d'], methods: ['GET'])]
    public function photo(Request $request, int $id, int $index): Response
    {
        $f = $this->mine($request, $id)->getFiles()[$index] ?? null;
        if (!$f || !is_file($path = $this->dir . '/' . $f['file'])) {
            throw $this->createNotFoundException();
        }
        return new BinaryFileResponse($path, 200, ['Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    #[Route('/photo-handoff/{id}/close', name: 'app_handoff_close', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function close(Request $request, int $id): JsonResponse
    {
        if ($this->sameSite($request)) {
            $this->remove($this->mine($request, $id));
            $this->em->flush();
        }
        return $this->json(['ok' => true]);
    }

    /** The phone's page: take photos, send them to the computer. */
    #[Route('/p/{token}', name: 'app_handoff_phone', requirements: ['token' => '[A-Za-z0-9_-]{20,40}'], methods: ['GET'])]
    public function phone(Request $request, string $token): Response
    {
        $handoff = $this->byToken($token);
        $this->useLocale($request, $handoff?->getLocale());
        $response = $this->render('handoff/phone.html.twig', [
            'handoff' => $handoff && !$handoff->isExpired() ? $handoff : null,
            'sent' => $handoff ? \count($handoff->getFiles()) : 0,
            'uploadUrl' => $this->generateUrl('app_handoff_upload', ['token' => $token]),
        ]);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');
        return $response;
    }

    #[Route('/p/{token}', name: 'app_handoff_upload', requirements: ['token' => '[A-Za-z0-9_-]{20,40}'], methods: ['POST'])]
    public function upload(Request $request, string $token): JsonResponse
    {
        $handoff = $this->byToken($token);
        $this->useLocale($request, $handoff?->getLocale());
        $fail = fn (string $message, int $status = 400) => $this->json(['error' => $this->translator->trans($message)], $status);
        if (!$handoff || $handoff->isExpired()) {
            return $fail('This link has ended. Make a new QR code on your computer.', 410);
        }
        if ($handoff->isFull()) {
            return $fail('That’s all the photos this link takes.', 409);
        }
        $file = $request->files->get('photo');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $fail('The upload didn’t finish. Please try again.');
        }
        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            return $fail('That photo is too large. The limit is 10 MB.');
        }
        $jpeg = ChatFiles::toJpeg((string) file_get_contents($file->getPathname()));
        if ($jpeg === null) {
            return $fail('We couldn’t read that image. Please try another one.');
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            return $fail('Something went wrong. Please try again.', 500);
        }
        $name = 'h' . $handoff->getId() . '_' . bin2hex(random_bytes(8)) . '.jpg';
        file_put_contents($this->dir . '/' . $name, $jpeg);
        [$w, $h] = getimagesizefromstring($jpeg) ?: [0, 0];
        $handoff->addFile($name, (int) $w, (int) $h);
        $this->em->flush();

        return $this->json(['ok' => true, 'sent' => \count($handoff->getFiles()), 'max' => $handoff->getMaxFiles(), 'full' => $handoff->isFull()]);
    }

    /**
     * Starting and closing need the picker's own header and a JSON body: a form or a link on
     * another site can't send those (the browser would ask first, and this app allows no other
     * origins), so no CSRF token, and no session just for visiting the quiz.
     */
    private function sameSite(Request $request): bool
    {
        return $request->headers->get('X-Requested-With') === 'ZenooPhoto'
            && str_starts_with((string) $request->headers->get('Content-Type'), 'application/json');
    }

    private function owner(Request $request, bool $start = false): string
    {
        $user = $this->getUser();
        if ($user instanceof User) {
            return 'u:' . $user->getId();
        }
        $session = $request->getSession();
        if ($start && !$session->has('photo_handoff')) {
            $session->set('photo_handoff', true); // keeps the session (and its id) for the visitor
        }
        return 's:' . hash('sha256', $session->getId());
    }

    private function mine(Request $request, int $id): PhotoHandoff
    {
        $handoff = $this->em->find(PhotoHandoff::class, $id);
        if (!$handoff || !hash_equals($handoff->getOwner(), $this->owner($request))) {
            throw $this->createNotFoundException();
        }
        return $handoff;
    }

    private function byToken(string $token): ?PhotoHandoff
    {
        return $this->em->getRepository(PhotoHandoff::class)->findOneBy(['tokenHash' => PhotoHandoff::hash($token)]);
    }

    private function useLocale(Request $request, ?string $locale): void
    {
        if ($locale && \in_array($locale, Locale::ALL, true)) {
            $request->setLocale($locale);
            if ($this->translator instanceof LocaleAwareInterface) {
                $this->translator->setLocale($locale);
            }
        }
    }

    /** Deletes ended hand-offs and their photos (a few at a time, whenever a new one starts). */
    private function purge(): void
    {
        $old = $this->em->createQuery('SELECT h FROM App\Entity\PhotoHandoff h WHERE h.expiresAt < :now')
            ->setParameter('now', new \DateTimeImmutable())->setMaxResults(50)->getResult();
        foreach ($old as $h) {
            $this->remove($h);
        }
        $this->em->flush();
    }

    private function remove(PhotoHandoff $handoff): void
    {
        foreach ($handoff->getFiles() as $f) {
            if (preg_match('/^h\d+_[a-f0-9]+\.jpg$/', $f['file']) && is_file($this->dir . '/' . $f['file'])) {
                @unlink($this->dir . '/' . $f['file']);
            }
        }
        $this->em->remove($handoff);
    }
}
