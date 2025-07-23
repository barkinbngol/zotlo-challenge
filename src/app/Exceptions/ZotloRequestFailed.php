<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class ZotloRequestFailed extends Exception
{
    public function __construct(
        public array $payload = [],
        public array $response = [],
        public int $httpStatus = 500,
        string $message = 'Zotlo request failed'
    ) {
        parent::__construct($message, $httpStatus);
    }
}
