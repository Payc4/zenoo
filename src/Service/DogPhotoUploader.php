<?php

namespace App\Service;

use App\Chat\ChatFiles;
use App\Entity\DogProfile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The dog's own photo on the dashboard ("Finish the profile"). Like profile photos, every upload
 * is decoded, turned upright and re-encoded as a square JPEG, so only a clean image file lands on
 * disk, under a random name.
 *
 * Photos live in public/uploads/private/dogs, which nginx refuses (test report M11): the app serves
 * them to the owner (app_dog_photo_file) and, while sharing is on, on the share page (app_share_photo).
 * Photos saved before that, in public/uploads/dogs, are moved across the first time they are used.
 */
class DogPhotoUploader
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    private const SIZE = 600;
    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%kernel.project_dir%/public/uploads/private/dogs')] private readonly string $dir,
        #[Autowire('%kernel.project_dir%/public/uploads/dogs')] private readonly string $legacyDir,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir = '',
    ) {
    }

    /** The picture to show for the dog: its own photo, else the quiz's breed photo, else null. */
    public function displayUrl(DogProfile $dog): ?string
    {
        if ($own = $this->url($dog)) {
            return $own;
        }
        if (preg_match('/zq-email-([a-z0-9-]+)\.jpg$/', (string) $dog->get('breed_photo_url', ''), $m)
            && is_file($this->projectDir . '/public/quiz/assets/photos/' . $m[1] . '.jpg')) {
            return '/quiz/assets/photos/' . $m[1] . '.jpg';
        }
        return null;
    }

    /** The owner's URL for the photo (signed-in route), or null when the dog has none. */
    public function url(DogProfile $dog): ?string
    {
        return $this->path($dog) ? $this->urls->generate('app_dog_photo_file', ['name' => $dog->getPhoto()]) : null;
    }

    /** The photo file on disk, or null. Moves a photo saved before M11 out of the public folder. */
    public function path(DogProfile $dog): ?string
    {
        $name = $dog->getPhoto();
        if (!$name || !$this->valid($name)) {
            return null;
        }
        $file = $this->dir . '/' . $name;
        if (!is_file($file) && is_file($this->legacyDir . '/' . $name)) {
            if ((is_dir($this->dir) || @mkdir($this->dir, 0775, true)) && @rename($this->legacyDir . '/' . $name, $file)) {
                return $file;
            }
            return $this->legacyDir . '/' . $name;
        }
        return is_file($file) ? $file : null;
    }

    /** @throws \InvalidArgumentException with an English message the controller translates */
    public function store(DogProfile $dog, UploadedFile $file): string
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('The upload didn’t finish. Please try again.');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new \InvalidArgumentException('That photo is too large. The limit is 10 MB.');
        }
        if (!\in_array($file->getMimeType(), self::ALLOWED, true)) {
            throw new \InvalidArgumentException('Please choose a JPG, PNG, WebP or GIF image.');
        }
        // Upright and no bigger than needed, then a centred square.
        $upright = ChatFiles::toJpeg((string) file_get_contents($file->getPathname()), 1600);
        $source = $upright ? @imagecreatefromstring($upright) : false;
        if (!$source) {
            throw new \InvalidArgumentException('We couldn’t read that image. Please try another one.');
        }
        $w = imagesx($source);
        $h = imagesy($source);
        $side = min($w, $h);
        $out = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagecopyresampled($out, $source, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), self::SIZE, self::SIZE, $side, $side);

        if (!is_dir($this->dir) && !mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Upload directory is not writable.');
        }
        $name = sprintf('dog_%d_%s.jpg', $dog->getId(), bin2hex(random_bytes(8)));
        if (!imagejpeg($out, $this->dir . '/' . $name, 85)) {
            throw new \RuntimeException('Could not save the image.');
        }
        $this->remove($dog);
        $dog->setPhoto($name);

        return $name;
    }

    public function remove(DogProfile $dog): void
    {
        $name = $dog->getPhoto();
        if ($name && $this->valid($name)) {
            foreach ([$this->dir, $this->legacyDir] as $dir) {
                if (is_file($dir . '/' . $name)) {
                    @unlink($dir . '/' . $name);
                }
            }
        }
        $dog->setPhoto(null);
    }

    public function valid(string $name): bool
    {
        return (bool) preg_match('/^dog_\d+_[a-f0-9]+\.jpg$/', $name);
    }
}
