<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Tests\Webhook;

/**
 * Payload examples taken from https://haskimail.ru/developer/webhooks/.
 */
final class WebhookFixtures
{
    public const SIGNING_KEY = 'webhook-signing-key';

    /**
     * @return array<string, mixed>
     */
    public static function load(string $name, bool $sign = true): array
    {
        $json = file_get_contents(__DIR__.'/../Fixtures/webhooks/'.$name.'.json');
        if (false === $json) {
            throw new \RuntimeException(\sprintf('Fixture "%s" not found.', $name));
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return $sign ? self::sign($payload) : $payload;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function sign(array $payload, string $key = self::SIGNING_KEY): array
    {
        $recipient = $payload['recipient'] ?? $payload['email'] ?? '';
        $messageId = $payload['message_id'] ?? '';
        \assert(\is_string($recipient) && \is_string($messageId));

        $payload['secret'] = md5($recipient.$messageId.$key);

        return $payload;
    }
}
