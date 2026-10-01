<?php
declare(strict_types=1);

namespace Shugoi;

class ShugoiError extends \RuntimeException
{
    public readonly string $errorCode;

    public function __construct(
        string $errorCode,
        string $message = '',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
    }
}
