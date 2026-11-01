<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Profile photos. Every upload is decoded and re-encoded as a 400×400 JPEG with GD, so
 * whatever the browser sent, only a clean image file ever lands in public/uploads/profiles.
 */
class AvatarUploader
{
    public const MAX_BYTES = 5 * 1024 * 1024;
    private const SIZE = 400;
    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        #[Autowire('%kernel.project_dir%/public/uploads/profiles')] private readonly string $dir,
    ) {
    }

    public function directory(): string
    {
        return $this->dir;
    }

    /** @throws \InvalidArgumentException with a message that can be shown to the pet parent */
    public function store(User $user, UploadedFile $file): string
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('The upload didn’t finish. Please try again.');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new \InvalidArgumentException('That photo is too large. The limit is 5 MB.');
        }
        if (!in_array($file->getMimeType(), self::ALLOWED, true)) {
            throw new \InvalidArgumentException('Please choose a JPG, PNG, WebP or GIF image.');
        }

        $source = @imagecreatefromstring((string) file_get_contents($file->getPathname()));
        if (!$source) {
            throw new \InvalidArgumentException('We couldn’t read that image. Please try another one.');
        }

        // Centre-crop to a square, then scale down.
        $w = imagesx($source);
        $h = imagesy($source);
        $side = min($w, $h);
        $out = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $source, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), self::SIZE, self::SIZE, $side, $side);

        if (!is_dir($this->dir) && !mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Upload directory is not writable.');
        }
        $name = sprintf('avatar_%d_%s.jpg', $user->getId(), bin2hex(random_bytes(6)));
        if (!imagejpeg($out, $this->dir . '/' . $name, 85)) {
            throw new \RuntimeException('Could not save the image.');
        }

        $this->remove($user->getProfileImage());

        return $name;
    }

    public function remove(?string $name): void
    {
        if ($name && preg_match('/^avatar_\d+_[a-f0-9]+\.jpg$/', $name) && is_file($this->dir . '/' . $name)) {
            @unlink($this->dir . '/' . $name);
        }
    }
}
