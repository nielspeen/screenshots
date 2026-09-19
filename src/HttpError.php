<?php

declare(strict_types=1);

namespace Screenshots;

/** An error that is reported to the client as-is. */
final class HttpError extends \RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
