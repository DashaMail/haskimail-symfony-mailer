<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Exception;

use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The Haskimail API rejected a message.
 *
 * Exposes the API "ErrorCode" and "Message" so that applications can react to
 * specific failures (unconfirmed sender, unknown template, invalid stream, ...).
 *
 * @see https://haskimail.ru/developer/api/overview
 */
final class HaskimailApiException extends HttpTransportException
{
    /** A required parameter is missing. */
    public const ERROR_MISSING_PARAMETER = 3;
    /** The account cannot send (blocked, deleted or under moderation). */
    public const ERROR_ACCOUNT_CANNOT_SEND = 4;
    /** Invalid email address in From, To, Cc or Bcc. */
    public const ERROR_INVALID_EMAIL = 6;
    /** Invalid API token, or the token does not match the endpoint. */
    public const ERROR_INVALID_TOKEN = 10;
    /** Object not found, required fields missing or IP not allowed. */
    public const ERROR_NOT_FOUND = 12;
    /** Action forbidden. */
    public const ERROR_FORBIDDEN = 15;
    /** Attachment too large. */
    public const ERROR_ATTACHMENT_TOO_LARGE = 26;
    /** Attachment type not allowed. */
    public const ERROR_ATTACHMENT_TYPE_NOT_ALLOWED = 33;
    /** Sending unavailable (insufficient funds, blocked, disabled). */
    public const ERROR_SENDING_UNAVAILABLE = 35;
    /** Temporary sending error, retry later. */
    public const ERROR_TEMPORARY = 36;
    /** Invalid custom headers. */
    public const ERROR_INVALID_HEADERS = 40;
    /** Sender address not confirmed. */
    public const ERROR_SENDER_NOT_CONFIRMED = 52;
    /** Internal Haskimail error. */
    public const ERROR_INTERNAL = 100;
    /** Invalid batch package. */
    public const ERROR_INVALID_PACKAGE = 300;
    /** Sender not confirmed (account under moderation). */
    public const ERROR_MODERATION_SENDER = 400;
    /** Invalid field value, reported only in test mode (HASKIMAIL_API_TEST). */
    public const ERROR_INVALID_FIELD = 403;
    /** Forbidden attachment type, reported only in test mode (HASKIMAIL_API_TEST). */
    public const ERROR_FORBIDDEN_ATTACHMENT_TYPE = 411;
    /** Recipient not confirmed (account under moderation). */
    public const ERROR_MODERATION_RECIPIENT = 412;
    /** Template not found. */
    public const ERROR_TEMPLATE_NOT_FOUND = 1101;
    /** Message stream not found, archived, or not owned by the server. */
    public const ERROR_INVALID_MESSAGE_STREAM = 1226;
    /** Cannot send through an inbound stream. */
    public const ERROR_INBOUND_STREAM = 1236;

    private const RETRYABLE_ERROR_CODES = [self::ERROR_TEMPORARY, self::ERROR_INTERNAL];

    public function __construct(
        string $message,
        ResponseInterface $response,
        private readonly int $errorCode,
        private readonly string $apiMessage,
        private readonly int $statusCode,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $response, $errorCode, $previous);
    }

    /**
     * The "ErrorCode" returned by the API (0 when the response did not contain one).
     */
    public function getErrorCode(): int
    {
        return $this->errorCode;
    }

    /**
     * The human-readable "Message" returned by the API.
     */
    public function getApiMessage(): string
    {
        return $this->apiMessage;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Whether retrying the same request later may succeed (5xx responses, temporary or internal errors).
     */
    public function isRetryable(): bool
    {
        return $this->statusCode >= 500 || \in_array($this->errorCode, self::RETRYABLE_ERROR_CODES, true);
    }
}
