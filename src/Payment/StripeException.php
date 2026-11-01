<?php

namespace App\Payment;

class StripeException extends \RuntimeException
{
    /** @param array<string, mixed> $error Stripe's error object (type, code, decline_code…) */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, private readonly array $error = [])
    {
        parent::__construct($message, $code, $previous);
    }

    /** @return array<string, mixed> */
    public function getError(): array
    {
        return $this->error;
    }
}
