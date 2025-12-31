<?php

namespace iRacingPHP\Exceptions;

class AuthorizationFailedException extends RequestFailedException
{
    private int $statusCode;
    private ?string $responseBody;
    private array $responseHeaders;

    public function __construct(
        string $message = "Authorization failed",
        int $statusCode = 401,
        ?string $responseBody = null,
        array $responseHeaders = [],
        \Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
        $this->responseHeaders = $responseHeaders;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    public function getResponseHeaders(): array
    {
        return $this->responseHeaders;
    }
}
