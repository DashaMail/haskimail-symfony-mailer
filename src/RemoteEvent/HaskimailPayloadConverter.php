<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\RemoteEvent;

use Symfony\Component\RemoteEvent\Event\Mailer\AbstractMailerEvent;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerDeliveryEvent;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerEngagementEvent;
use Symfony\Component\RemoteEvent\Exception\ParseException;
use Symfony\Component\RemoteEvent\PayloadConverterInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;

/**
 * Converts Haskimail webhook payloads into Symfony RemoteEvents.
 *
 * | Haskimail "event"                        | RemoteEvent                                 |
 * |------------------------------------------|---------------------------------------------|
 * | delivered                                | MailerDeliveryEvent::DELIVERED              |
 * | bounced                                  | MailerDeliveryEvent::BOUNCE (reason = text) |
 * | complained                               | MailerEngagementEvent::SPAM                 |
 * | opened                                   | MailerEngagementEvent::OPEN                 |
 * | clicked                                  | MailerEngagementEvent::CLICK                |
 * | subscriptionchange, SuppressSending=true | MailerEngagementEvent::UNSUBSCRIBE          |
 * | subscriptionchange, SuppressSending=false| RemoteEvent named self::RESUBSCRIBE         |
 *
 * The original payload is always available through RemoteEvent::getPayload()
 * (e.g. "bounce_category", "url_original", "SuppressionReason").
 *
 * @see https://haskimail.ru/developer/webhooks/webhooks-overview
 */
final class HaskimailPayloadConverter implements PayloadConverterInterface
{
    /** Name of the event emitted when an address is removed from the suppression list. */
    public const RESUBSCRIBE = 'resubscribe';

    /** Timezone of the "event_time" field. */
    private const HASKIMAIL_TIMEZONE = 'Europe/Moscow';

    private const DATE_FORMAT = '!Y-m-d H:i:s';

    /**
     * @param array<mixed> $payload
     */
    public function convert(array $payload): RemoteEvent
    {
        $type = strtolower(self::requireString($payload, 'event'));
        $recipient = self::getRecipient($payload);
        $id = self::getEventId($payload, $type, $recipient);

        $event = match ($type) {
            'delivered' => new MailerDeliveryEvent(MailerDeliveryEvent::DELIVERED, $id, $payload),
            'bounced' => self::createBounce($id, $payload),
            'complained' => new MailerEngagementEvent(MailerEngagementEvent::SPAM, $id, $payload),
            'opened' => new MailerEngagementEvent(MailerEngagementEvent::OPEN, $id, $payload),
            'clicked' => new MailerEngagementEvent(MailerEngagementEvent::CLICK, $id, $payload),
            'subscriptionchange' => self::isSuppressed($payload)
                ? new MailerEngagementEvent(MailerEngagementEvent::UNSUBSCRIBE, $id, $payload)
                : new RemoteEvent(self::RESUBSCRIBE, $id, $payload),
            default => throw new ParseException(\sprintf('Unsupported Haskimail event "%s".', $type)),
        };

        if (!$event instanceof AbstractMailerEvent) {
            return $event;
        }

        $event->setRecipientEmail($recipient);
        $event->setDate(self::getDate($payload));
        $event->setMetadata(self::getMetadata($payload));

        $tag = $payload['campaign_id'] ?? null;
        if (\is_string($tag) && '' !== $tag) {
            $event->setTags([$tag]);
        }

        return $event;
    }

    /**
     * @param array<mixed> $payload
     */
    private static function createBounce(string $id, array $payload): MailerDeliveryEvent
    {
        $event = new MailerDeliveryEvent(MailerDeliveryEvent::BOUNCE, $id, $payload);
        $event->setReason(self::getBounceReason($payload));

        return $event;
    }

    /**
     * @param array<mixed> $payload
     */
    private static function getRecipient(array $payload): string
    {
        // Must be the very value covered by the signature (see HaskimailRequestParser).
        $recipient = self::getSignedRecipient($payload);

        if (null === $recipient || '' === trim($recipient)) {
            throw new ParseException('The Haskimail webhook payload has no "recipient".');
        }

        return trim($recipient);
    }

    /**
     * The recipient as signed by Haskimail: the "recipient" field, or "email" when "recipient" is absent.
     *
     * @internal
     *
     * @param array<mixed> $payload
     */
    public static function getSignedRecipient(array $payload): ?string
    {
        // A present (even blank) "recipient" wins: only a missing or null one falls back to "email".
        $recipient = $payload['recipient'] ?? $payload['email'] ?? null;

        return \is_string($recipient) ? $recipient : null;
    }

    /**
     * @param array<mixed> $payload
     */
    private static function getEventId(array $payload, string $type, string $recipient): string
    {
        $messageId = $payload['message_id'] ?? '';
        if (\is_int($messageId)) {
            $messageId = (string) $messageId;
        }
        if (!\is_string($messageId)) {
            throw new ParseException('The "message_id" field of the Haskimail webhook payload must be a string.');
        }
        if ('' !== $messageId) {
            return $messageId;
        }

        // Events not tied to a message (manual suppression changes): derive a stable ID for de-duplication.
        $time = $payload['event_time_utc'] ?? $payload['event_time'] ?? '';

        return 'haskimail-'.hash('sha256', $type."\0".$recipient."\0".(\is_string($time) ? $time : ''));
    }

    /**
     * The moment the event happened ("event_time_utc", or "event_time" in Moscow time).
     *
     * @param array<mixed> $payload
     */
    private static function getDate(array $payload): \DateTimeImmutable
    {
        if (\is_string($payload['event_time_utc'] ?? null) && '' !== $payload['event_time_utc']) {
            return self::parseDate($payload['event_time_utc'], 'UTC');
        }

        if (\is_string($payload['event_time'] ?? null) && '' !== $payload['event_time']) {
            return self::parseDate($payload['event_time'], self::HASKIMAIL_TIMEZONE);
        }

        throw new ParseException('The Haskimail webhook payload has no "event_time".');
    }

    private static function parseDate(string $value, string $timezone): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat(self::DATE_FORMAT, $value, new \DateTimeZone($timezone));

        // Reject overflowing values such as "2026-13-45 25:61:00" that PHP would silently roll over.
        if (false === $date || $date->format('Y-m-d H:i:s') !== $value) {
            throw new ParseException(\sprintf('Invalid Haskimail event date "%s".', $value));
        }

        return $date;
    }

    /**
     * "custom_vars" holds the message Metadata as a JSON string (or, in some payloads, as an object).
     *
     * @param array<mixed> $payload
     *
     * @return array<mixed>
     */
    private static function getMetadata(array $payload): array
    {
        $vars = $payload['custom_vars'] ?? null;

        if (\is_string($vars) && '' !== trim($vars)) {
            try {
                $vars = json_decode($vars, true, 32, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                // Metadata is informative only: a malformed value must not make the whole event fail.
                return [];
            }
        }

        return \is_array($vars) ? $vars : [];
    }

    /**
     * @param array<mixed> $payload
     */
    private static function isSuppressed(array $payload): bool
    {
        $value = $payload['SuppressSending'] ?? null;

        return match (true) {
            \is_bool($value) => $value,
            \is_string($value) && 'true' === strtolower($value) => true,
            \is_string($value) && 'false' === strtolower($value) => false,
            default => throw new ParseException('The "SuppressSending" field of a Haskimail "subscriptionchange" event must be "true" or "false".'),
        };
    }

    /**
     * @param array<mixed> $payload
     */
    private static function getBounceReason(array $payload): string
    {
        foreach (['description', 'error'] as $key) {
            if (\is_string($payload[$key] ?? null) && '' !== $payload[$key]) {
                return $payload[$key];
            }
        }

        return '';
    }

    /**
     * @param array<mixed> $payload
     */
    private static function requireString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (!\is_string($value) || '' === trim($value)) {
            throw new ParseException(\sprintf('The Haskimail webhook payload has no "%s" field.', $key));
        }

        return trim($value);
    }
}
