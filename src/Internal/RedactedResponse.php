<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Internal;

use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Decorates an HTTP response so that a secret never appears in its info (notably the "debug" log,
 * which contains the request headers and is copied into SentMessage/HttpTransportException debug output).
 *
 * @internal
 */
final class RedactedResponse implements ResponseInterface
{
    public const REPLACEMENT = '[redacted]';

    public function __construct(
        private readonly ResponseInterface $response,
        #[\SensitiveParameter]
        private readonly string $secret,
    ) {
    }

    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    public function getHeaders(bool $throw = true): array
    {
        return $this->response->getHeaders($throw);
    }

    public function getContent(bool $throw = true): string
    {
        return $this->response->getContent($throw);
    }

    /**
     * @return array<mixed>
     */
    public function toArray(bool $throw = true): array
    {
        return $this->response->toArray($throw);
    }

    public function cancel(): void
    {
        $this->response->cancel();
    }

    public function getInfo(?string $type = null): mixed
    {
        return $this->redact($this->response->getInfo($type));
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['response' => $this->response, 'secret' => self::REPLACEMENT];
    }

    private function redact(mixed $value): mixed
    {
        if (\is_string($value)) {
            return str_replace($this->secret, self::REPLACEMENT, $value);
        }

        if (\is_array($value)) {
            return array_map($this->redact(...), $value);
        }

        return $value;
    }
}
