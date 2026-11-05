<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Webhook;

use Haskimail\SymfonyMailer\RemoteEvent\HaskimailPayloadConverter;
use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\IpsRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\IsJsonRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\RemoteEvent\Exception\ParseException;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Client\AbstractRequestParser;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

/**
 * Parses Haskimail delivery, bounce, spam complaint, open, click and subscription change webhooks.
 *
 * Every event is authenticated: Haskimail signs it with
 * md5(recipient . message_id . webhook signing key) in the "secret" field. Configure the
 * signing key (Server -> Channel -> Webhooks in the dashboard) as the webhook secret:
 *
 *     framework:
 *         webhook:
 *             routing:
 *                 haskimail:
 *                     service: haskimail.webhook.request_parser
 *                     secret: '%env(HASKIMAIL_WEBHOOK_SECRET)%'
 *
 * Limits of Haskimail's signature scheme (not fixable on the receiving side): only "recipient" and
 * "message_id" are signed, concatenated without a separator, and the event type and time are not.
 * A captured payload can therefore be replayed or turned into another event of the same message.
 * Keep the signing key secret, receive webhooks over HTTPS and make consumers idempotent.
 *
 * @see https://haskimail.ru/developer/webhooks/webhooks-overview
 */
final class HaskimailRequestParser extends AbstractRequestParser
{
    /**
     * @param list<string>|null $allowedIps optional allow-list of source IPs/CIDRs; null disables the check
     */
    public function __construct(
        private readonly HaskimailPayloadConverter $converter = new HaskimailPayloadConverter(),
        private readonly ?array $allowedIps = null,
    ) {
        if ([] === $allowedIps) {
            // IpsRequestMatcher treats an empty list as "allow everything": refuse the ambiguous configuration.
            throw new \InvalidArgumentException('The list of allowed IPs must not be empty; pass null to disable the IP check.');
        }
    }

    protected function getRequestMatcher(): RequestMatcherInterface
    {
        $matchers = [
            new MethodRequestMatcher('POST'),
            new IsJsonRequestMatcher(),
        ];

        if (null !== $this->allowedIps) {
            $matchers[] = new IpsRequestMatcher($this->allowedIps);
        }

        return new ChainRequestMatcher($matchers);
    }

    protected function doParse(Request $request, #[\SensitiveParameter] string $secret): RemoteEvent
    {
        if ('' === $secret) {
            // Fail loudly (HTTP 500): Haskimail retries 5xx responses, so no event is lost while the configuration is fixed.
            throw new \LogicException('The Haskimail webhook signing key is not configured: set the "secret" of the Haskimail webhook routing (framework.webhook.routing.<name>.secret).');
        }

        $payload = $request->toArray();

        if ([] === $payload || array_is_list($payload)) {
            throw new RejectWebhookException(406, 'The Haskimail webhook payload must be a JSON object.');
        }

        self::verifySignature($payload, $secret);

        // The signature is a reusable token: never hand it over to consumers, which may log payloads.
        unset($payload['secret']);

        try {
            return $this->converter->convert($payload);
        } catch (ParseException $e) {
            throw new RejectWebhookException(406, $e->getMessage(), $e);
        }
    }

    /**
     * @param array<mixed> $payload
     */
    private static function verifySignature(array $payload, #[\SensitiveParameter] string $secret): void
    {
        $signature = $payload['secret'] ?? null;
        $recipient = HaskimailPayloadConverter::getSignedRecipient($payload);
        $messageId = $payload['message_id'] ?? '';

        if (\is_int($messageId)) {
            $messageId = (string) $messageId;
        }

        if (!\is_string($signature) || null === $recipient || !\is_string($messageId)) {
            throw new RejectWebhookException(403, 'The Haskimail webhook signature is missing.');
        }

        if (!hash_equals(md5($recipient.$messageId.$secret), strtolower($signature))) {
            throw new RejectWebhookException(403, 'Invalid Haskimail webhook signature.');
        }
    }
}
