<?php

namespace App\Controller;

use App\Entity\DogProfile;
use App\Entity\User;
use App\Repository\DogProfileRepository;
use App\Service\DogPhotoUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Roadmap 09: a result card to share. The owner turns sharing on from the dashboard; /s/{code}
 * shows the dog's name, photo and Bowl Score (nothing else) with a picture for social posts, and
 * invites the visitor to take the quiz (tagged utm_source=share, so the funnel shows what sharing
 * brings). "Stop sharing" makes the old link stop working.
 */
final class ShareController extends AbstractController
{
    public function __construct(
        private readonly DogProfileRepository $dogs,
        private readonly EntityManagerInterface $em,
        private readonly DogPhotoUploader $photos,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    #[Route('/dashboard/share', name: 'app_share_toggle', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function toggle(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $dog = $this->dogs->findForUser($user);
        if ($dog && $this->isCsrfTokenValid('share', (string) $request->request->get('_token'))) {
            $request->request->getBoolean('stop') ? $dog->stopSharing() : $dog->startSharing();
            $this->em->flush();
        }
        return $this->redirect($this->generateUrl('app_dog_tab') . '#share-h');
    }

    #[Route('/s/{code}', name: 'app_share', requirements: ['code' => '[a-f0-9]{12}'], methods: ['GET'])]
    public function show(string $code): Response
    {
        $dog = $this->find($code);
        return $this->render('share/show.html.twig', [
            'dog' => $dog,
            'code' => $code,
            'photo' => $this->photoUrl($dog, $code),
        ]);
    }

    #[Route('/s/{code}.png', name: 'app_share_image', requirements: ['code' => '[a-f0-9]{12}'], methods: ['GET'])]
    public function image(string $code): Response
    {
        $dog = $this->find($code);
        $png = $this->render1200x630($dog);
        return new Response($png, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=3600']);
    }

    private function find(string $code): DogProfile
    {
        foreach ($this->dogs->findAll() as $dog) {
            if ($dog->getShareCode() !== null && hash_equals($dog->getShareCode(), $code)) {
                return $dog;
            }
        }
        throw $this->createNotFoundException();
    }

    /** The dog's own photo while sharing is on (M11: the share code is what lets a visitor see it). */
    #[Route('/s/{code}/photo.jpg', name: 'app_share_photo', requirements: ['code' => '[a-f0-9]{12}'], methods: ['GET'])]
    public function photo(string $code): Response
    {
        $file = $this->photos->path($this->find($code));
        if (!$file) {
            throw $this->createNotFoundException();
        }
        $response = new BinaryFileResponse($file, 200, ['Content-Type' => 'image/jpeg', 'X-Content-Type-Options' => 'nosniff']);
        $response->setPublic();
        $response->setMaxAge(3600);
        return $response;
    }

    /** The dog's own photo, else the breed photo from the quiz, else none. */
    private function photoUrl(DogProfile $dog, string $code): ?string
    {
        if ($this->photos->path($dog)) {
            return $this->generateUrl('app_share_photo', ['code' => $code]);
        }
        $breed = $this->breedPhoto($dog);
        return $breed ? '/quiz/assets/photos/breed-' . $breed . '.jpg' : null;
    }

    /** The photo file on disk, for the share image. */
    private function photoFile(DogProfile $dog): ?string
    {
        if ($own = $this->photos->path($dog)) {
            return $own;
        }
        $breed = $this->breedPhoto($dog);
        return $breed ? $this->projectDir . '/public/quiz/assets/photos/breed-' . $breed . '.jpg' : null;
    }

    private function breedPhoto(DogProfile $dog): ?string
    {
        if (preg_match('~breed-([a-z0-9-]+)\.jpg~', (string) $dog->get('breed_photo_url', ''), $m) && is_file($this->projectDir . '/public/quiz/assets/photos/breed-' . $m[1] . '.jpg')) {
            return $m[1];
        }
        return null;
    }

    private function render1200x630(DogProfile $dog): string
    {
        $w = 1200;
        $h = 630;
        $im = imagecreatetruecolor($w, $h);
        $forest = imagecolorallocate($im, 0x44, 0x59, 0x42);
        $sun = imagecolorallocate($im, 0xF7, 0xA2, 0x23);
        $white = imagecolorallocate($im, 255, 255, 255);
        $cream = imagecolorallocate($im, 0xF6, 0xF3, 0xEA);
        imagefill($im, 0, 0, $forest);
        $bold = $this->projectDir . '/resources/fonts/DejaVuSans-Bold.ttf';
        $regular = $this->projectDir . '/resources/fonts/DejaVuSans.ttf';

        // The photo on the right, cropped square, with rounded feel from a cream frame.
        $photo = $this->photoFile($dog);
        $textRight = $w - 60;
        if ($photo && ($src = @imagecreatefromstring((string) file_get_contents($photo)))) {
            $side = min(imagesx($src), imagesy($src));
            $size = 470;
            imagefilledrectangle($im, $w - $size - 70, 70, $w - 50, 70 + $size + 20, $cream);
            imagecopyresampled($im, $src, $w - $size - 60, 80, (int) ((imagesx($src) - $side) / 2), (int) ((imagesy($src) - $side) / 2), $size, $size, $side, $side);
            $textRight = $w - $size - 110;
        }

        $logo = @imagecreatefrompng($this->projectDir . '/public/images/logo-white.png');
        if ($logo) {
            imagecopyresampled($im, $logo, 60, 60, 0, 0, 228, 60, imagesx($logo), imagesy($logo));
        }
        $name = mb_strtoupper($dog->getName());
        $fit = function (string $text, string $font, int $size, int $maxWidth): int {
            while ($size > 20) {
                $box = imagettfbbox($size, 0, $font, $text);
                if ($box[2] - $box[0] <= $maxWidth) {
                    break;
                }
                $size -= 4;
            }
            return $size;
        };
        $maxText = $textRight - 60;
        imagettftext($im, $fit($name, $bold, 64, $maxText), 0, 60, 230, $white, $bold, $name);
        imagettftext($im, 30, 0, 60, 290, $cream, $regular, 'BOWL SCORE');
        imagettftext($im, 150, 0, 52, 470, $sun, $bold, (string) $dog->getBowlScore());
        $box = imagettfbbox(150, 0, $bold, (string) $dog->getBowlScore());
        imagettftext($im, 48, 0, 60 + $box[2] - $box[0] + 16, 470, $white, $bold, '/100');
        imagettftext($im, 24, 0, 60, 560, $cream, $regular, 'app.zenoo.lt');

        ob_start();
        imagepng($im);
        return (string) ob_get_clean();
    }
}
