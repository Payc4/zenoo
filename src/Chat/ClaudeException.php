<?php

namespace App\Chat;

class ClaudeException extends \RuntimeException
{
    public function __construct(string $message, int $code = 0, private readonly ?string $type = null)
    {
        parent::__construct($message, $code);
    }

    public function getType(): ?string
    {
        return $this->type;
    }
}
