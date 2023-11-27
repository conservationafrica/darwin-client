<?php

declare(strict_types=1);

namespace Darwin;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

use function sprintf;

final class RequestFailed extends RuntimeException implements DarwinError
{
    public function __construct(
        public readonly RequestInterface $request,
        public readonly ResponseInterface|null $response,
        string $message,
        int $code,
        Throwable|null $previous,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function withErrorResult(
        RequestInterface $request,
        ResponseInterface $response,
        string $message,
        int $code,
    ): self {
        return new self(
            $request,
            $response,
            sprintf(
                'The request to "%s" failed with message "%s"',
                (string) $request->getUri(),
                $message,
            ),
            $code,
            null,
        );
    }

    public static function becauseOfAnIoError(RequestInterface $request, Throwable $e): self
    {
        return new self(
            $request,
            null,
            sprintf(
                'The request to "%s" failed because of an i/o error',
                (string) $request->getUri(),
            ),
            0,
            $e,
        );
    }

    public static function becauseTheResponseBodyWasNotValidJson(
        RequestInterface $request,
        ResponseInterface $response,
        Throwable $e,
    ): self {
        return new self(
            $request,
            $response,
            sprintf(
                'The response received from %s %s returned an invalid response body that could not be decoded',
                $request->getMethod(),
                (string) $request->getUri(),
            ),
            0,
            $e,
        );
    }

    public static function becauseItWasA404(RequestInterface $request, ResponseInterface $response): self
    {
        return new self(
            $request,
            $response,
            sprintf(
                'The request to "%s" resulted in a 404 error',
                (string) $request->getUri(),
            ),
            404,
            null,
        );
    }
}
