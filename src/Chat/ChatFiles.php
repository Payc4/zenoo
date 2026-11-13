<?php

namespace App\Chat;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Photos and PDFs that pet parents send in the coach chat (a food label, a rash, a vet report).
 *
 * Files live on the uploads volume under public/uploads/private/chat/<user id>/ with random
 * names. nginx refuses /uploads/private/ (docker/nginx/default.conf), so they are only served
 * through ChatController::attachment(), to their owner and to admins.
 *
 * Images are decoded and re-encoded as JPEG no larger than MAX_SIDE (what Claude reads best
 * and cheapest), so only a clean image file is ever stored. PDFs are kept as they are after a
 * signature check.
 */
class ChatFiles
{
    public const MAX_FILES = 3;
    public const MAX_IMAGE_BYTES = 10 * 1024 * 1024;
    public const MAX_PDF_BYTES = 8 * 1024 * 1024;
    /** Claude's recommended long side for images; larger only costs more. */
    public const MAX_SIDE = 1568;
    private const IMAGES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        #[Autowire('%kernel.project_dir%/public/uploads/private/chat')] private readonly string $dir,
    ) {
    }

    /**
     * Stores one upload and returns its attachment record.
     *
     * @return array{file: string, type: string, name: string, size: int}
     * @throws \InvalidArgumentException with an English message that the controller translates
     */
    public function store(User $user, UploadedFile $file): array
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('The upload didn’t finish. Please try again.');
        }
        $mime = (string) $file->getMimeType();
        $original = mb_substr(preg_replace('/[^\pL\pN ._-]+/u', '', $file->getClientOriginalName()) ?: 'file', 0, 80);

        if ($mime === 'application/pdf') {
            if ($file->getSize() > self::MAX_PDF_BYTES) {
                throw new \InvalidArgumentException('That file is too large. PDFs can be up to 8 MB.');
            }
            $bytes = (string) file_get_contents($file->getPathname());
            if (!str_starts_with($bytes, '%PDF')) {
                throw new \InvalidArgumentException('Please send a photo (JPG, PNG, WebP) or a PDF.');
            }
            $name = $this->write($user, 'pdf', $bytes);
            return ['file' => $name, 'type' => 'application/pdf', 'name' => $original, 'size' => \strlen($bytes)];
        }

        if (!\in_array($mime, self::IMAGES, true)) {
            throw new \InvalidArgumentException('Please send a photo (JPG, PNG, WebP) or a PDF.');
        }
        if ($file->getSize() > self::MAX_IMAGE_BYTES) {
            throw new \InvalidArgumentException('That photo is too large. The limit is 10 MB.');
        }
        $jpeg = self::toJpeg((string) file_get_contents($file->getPathname()));
        if ($jpeg === null) {
            throw new \InvalidArgumentException('We couldn’t read that image. Please try another one.');
        }
        $name = $this->write($user, 'jpg', $jpeg);
        return ['file' => $name, 'type' => 'image/jpeg', 'name' => $original, 'size' => \strlen($jpeg)];
    }

    /** The stored file's path, or null when the record doesn't point to a file of this user. */
    public function path(User $owner, array $attachment): ?string
    {
        $file = (string) ($attachment['file'] ?? '');
        if (!preg_match('#^\d+/[a-f0-9]{24}\.(jpg|pdf)$#', $file) || !str_starts_with($file, $owner->getId() . '/')) {
            return null;
        }
        $path = $this->dir . '/' . $file;
        return is_file($path) ? $path : null;
    }

    /**
     * The attachment as a Claude content block (image or document), or null if the file is gone.
     *
     * @return array<string, mixed>|null
     */
    public function contentBlock(User $owner, array $attachment): ?array
    {
        $path = $this->path($owner, $attachment);
        if ($path === null) {
            return null;
        }
        $data = base64_encode((string) file_get_contents($path));
        return $attachment['type'] === 'application/pdf'
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $data]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $data]];
    }

    /** Deletes every chat file of this pet parent (chat cleared or account deleted). */
    public function removeAll(User $user): void
    {
        $dir = $this->dir . '/' . $user->getId();
        if ($user->getId() === null || !is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*.{jpg,pdf}', GLOB_BRACE) ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    /**
     * Decodes any image GD can read, turns it upright (camera EXIF), and re-encodes it as a JPEG
     * whose long side is at most $maxSide. Null when it isn't an image.
     */
    public static function toJpeg(string $bytes, int $maxSide = self::MAX_SIDE): ?string
    {
        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            return null;
        }
        if (\function_exists('exif_read_data') && str_starts_with($bytes, "\xFF\xD8")) {
            $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
            $angle = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
            if ($angle !== 0 && ($rotated = imagerotate($src, $angle, 0))) {
                $src = $rotated;
            }
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $maxSide / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagejpeg($out, null, 85);
        return (string) ob_get_clean();
    }

    private function write(User $user, string $ext, string $bytes): string
    {
        $dir = $this->dir . '/' . $user->getId();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Chat upload directory is not writable.');
        }
        $name = $user->getId() . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
        if (file_put_contents($this->dir . '/' . $name, $bytes) === false) {
            throw new \RuntimeException('Could not save the file.');
        }
        return $name;
    }
}
