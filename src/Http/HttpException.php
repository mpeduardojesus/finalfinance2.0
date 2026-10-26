<?php
declare(strict_types=1);

namespace App\Http;

final class HttpException extends \RuntimeException
{
    /** @param array<string,string> $details */
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message, $status);
    }
}
