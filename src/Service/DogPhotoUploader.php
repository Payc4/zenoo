<?php

namespace App\Service;

use App\Chat\ChatFiles;
use App\Entity\DogProfile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The dog's own photo on the dashboard ("Finish the profile"). Like profile photos, every upload
 * is decoded, turned upright and re-encoded as a square JPEG, so only a clean image file lands in
 * public/uploads/dogs, under a random name.
 */
class DogPhotoUploader
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    private const SIZE = 600;
    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        #[Autowire('%kernel.project_dir%/public/uploads/dogs')] private readonly string $dir,
    ) {
    }

    public function url(DogProfile $dog): ?string
    {
        $name = $dog->getPhoto();
        return $name && $this->valid($name) && is_file($this->dir . '/' . $name) ? '/uploads/dogs/' . $name : null;
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
        if ($name && $this->valid($name) && is_file($this->dir . '/' . $name)) {
            @unlink($this->dir . '/' . $name);
        }
        $dog->setPhoto(null);
    }

    private function valid(string $name): bool
    {
        return (bool) preg_match('/^dog_\d+_[a-f0-9]+\.jpg$/', $name);
    }
}
