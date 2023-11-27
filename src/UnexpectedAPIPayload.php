<?php

declare(strict_types=1);

namespace Darwin;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use UnexpectedValueException;

final class UnexpectedAPIPayload extends UnexpectedValueException implements DarwinError
{
    public function __construct(
        public readonly RequestInterface $request,
        public readonly ResponseInterface $response,
        string $message,
        int $code = 0,
        Throwable|null $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
