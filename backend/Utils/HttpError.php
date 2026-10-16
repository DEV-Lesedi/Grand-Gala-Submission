<?php

declare(strict_types=1);

namespace GrandGala\Utils;

use RuntimeException;

// Thrown to the caller. index.php sends { "error": "..." } with this status.
class HttpError extends RuntimeException
{
    private int $status;

    public function __construct(int $status, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
    }

    public function status(): int
    {
        return $this->status;
    }
}
