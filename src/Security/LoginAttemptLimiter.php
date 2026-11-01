<?php

namespace App\Security;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Slows down password guessing: after MAX_FAILURES wrong passwords for the same email
 * from the same IP, that pair is locked for WINDOW seconds. Counts live in the app cache.
 *
 * (Symfony's built-in login_throttling needs symfony/rate-limiter, which isn't in the lock file.)
 */
class LoginAttemptLimiter
{
    public const MAX_FAILURES = 5;
    public const WINDOW = 900; // 15 minutes

    public function __construct(private readonly CacheItemPoolInterface $cacheApp)
    {
    }

    public function isLocked(string $email, ?string $ip): bool
    {
        return $this->failures($email, $ip) >= self::MAX_FAILURES;
    }

    public function recordFailure(string $email, ?string $ip): void
    {
        $item = $this->cacheApp->getItem($this->key($email, $ip));
        $item->set(((int) $item->get()) + 1);
        $item->expiresAfter(self::WINDOW);
        $this->cacheApp->save($item);
    }

    public function reset(string $email, ?string $ip): void
    {
        $this->cacheApp->deleteItem($this->key($email, $ip));
    }

    private function failures(string $email, ?string $ip): int
    {
        return (int) $this->cacheApp->getItem($this->key($email, $ip))->get();
    }

    private function key(string $email, ?string $ip): string
    {
        return 'login_fail_' . hash('sha256', mb_strtolower(trim($email)) . '|' . $ip);
    }
}
