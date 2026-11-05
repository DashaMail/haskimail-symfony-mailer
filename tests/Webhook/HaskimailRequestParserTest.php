<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Tests\Webhook;

use Haskimail\SymfonyMailer\RemoteEvent\HaskimailPayloadConverter;
use Haskimail\SymfonyMailer\Webhook\HaskimailRequestParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerDeliveryEvent;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerEngagementEvent;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

final class HaskimailRequestParserTest extends TestCase
{
    public function testParsesASignedBounce(): void
    {
        $event = (new HaskimailRequestParser())->parse(self::request(WebhookFixtures::load('bounced')), WebhookFixtures::SIGNING_KEY);

        self::assertInstanceOf(MailerDeliveryEvent::class, $event);
        self::assertSame(MailerDeliveryEvent::BOUNCE, $event->getName());
        self::assertSame('a41d7c9e2b6f4e0d8c3b5a1f9e7d2c60', $event->getId());
    }

    public function testSignatureMatchesAnIndependentlyComputedValue(): void
    {
        // printf '%s' 'old-address@example.coma41d7c9e2b6f4e0d8c3b5a1f9e7d2c60webhook-signing-key' | md5sum
        $payload = WebhookFixtures::load('bounced', false);
        $payload['secret'] = '6845c1581167568e7483cae61b705bdf';

        self::assertNotNull((new HaskimailRequestParser())->parse(self::request($payload), 'webhook-signing-key'));
    }

    public function testUppercaseSignatureIsAccepted(): void
    {
        $payload = WebhookFixtures::load('bounced');
        self::assertIsString($payload['secret']);
        $payload['secret'] = strtoupper($payload['secret']);

        self::assertNotNull((new HaskimailRequestParser())->parse(self::request($payload), WebhookFixtures::SIGNING_KEY));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAllFixtures(): iterable
    {
        foreach (['delivered', 'bounced', 'opened', 'clicked', 'complained', 'subscriptionchange', 'resubscribe'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('provideAllFixtures')]
    public function testEveryDocumentedEventIsAccepted(string $fixture): void
    {
        $event = (new HaskimailRequestParser())->parse(self::request(WebhookFixtures::load($fixture)), WebhookFixtures::SIGNING_KEY);

        self::assertNotNull($event);
    }

    public function testEventWithoutMessageIdIsSignedWithEmptyMessageId(): void
    {
        $payload = WebhookFixtures::load('resubscribe');
        self::assertSame('', $payload['message_id']);
        self::assertSame(md5('ivan@example.com'.WebhookFixtures::SIGNING_KEY), $payload['secret']);

        self::assertNotNull((new HaskimailRequestParser())->parse(self::request($payload), WebhookFixtures::SIGNING_KEY));
    }

    public function testSignatureFallsBackToEmailField(): void
    {
        $payload = WebhookFixtures::load('delivered', false);
        unset($payload['recipient']);

        $event = (new HaskimailRequestParser())->parse(self::request(WebhookFixtures::sign($payload)), WebhookFixtures::SIGNING_KEY);

        self::assertInstanceOf(MailerDeliveryEvent::class, $event);
        self::assertSame('ivan@example.com', $event->getRecipientEmail());
    }

    public function testIntegerMessageIdIsSignedAsString(): void
    {
        $payload = WebhookFixtures::load('delivered', false);
        $payload['message_id'] = 42;
        $payload['secret'] = md5('ivan@example.com42'.WebhookFixtures::SIGNING_KEY);

        self::assertNotNull((new HaskimailRequestParser())->parse(self::request($payload), WebhookFixtures::SIGNING_KEY));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function provideForgedPayloads(): iterable
    {
        yield 'random signature' => [['secret' => md5('random')], []];
        yield 'tampered recipient' => [['recipient' => 'attacker@example.com'], []];
        yield 'tampered message id' => [['message_id' => 'other'], []];
        yield 'missing signature' => [[], ['secret']];
        yield 'empty signature' => [['secret' => ''], []];
        yield 'non-string signature' => [['secret' => 123], []];
        yield 'non-string recipient' => [['recipient' => ['x']], []];
        yield 'non-string message id' => [['message_id' => 1.5], []];
    }

    /**
     * @param array<string, mixed> $overrides
     * @param list<string>         $removed
     */
    #[DataProvider('provideForgedPayloads')]
    public function testForgedPayloadsAreRejectedWith403(array $overrides, array $removed): void
    {
        $payload = array_diff_key($overrides + WebhookFixtures::load('delivered'), array_flip($removed));

        try {
            (new HaskimailRequestParser())->parse(self::request($payload), WebhookFixtures::SIGNING_KEY);
            self::fail('The webhook should have been rejected.');
        } catch (RejectWebhookException $e) {
            self::assertSame(403, $e->getStatusCode());
        }
    }

    public function testPayloadSignedWithAnotherKeyIsRejected(): void
    {
        $payload = WebhookFixtures::sign(WebhookFixtures::load('delivered', false), 'another-key');

        $this->expectException(RejectWebhookException::class);

        (new HaskimailRequestParser())->parse(self::request($payload), WebhookFixtures::SIGNING_KEY);
    }

    public function testMissingSecretConfigurationFailsLoudly(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('signing key is not configured');

        (new HaskimailRequestParser())->parse(self::request(WebhookFixtures::load('delivered')), '');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideNonMatchingRequests(): iterable
    {
        yield 'GET' => ['GET', true];
        yield 'PUT' => ['PUT', true];
        yield 'body is not JSON' => ['POST', false];
    }

    #[DataProvider('provideNonMatchingRequests')]
    public function testNonMatchingRequestsAreRejected(string $method, bool $json): void
    {
        // Except for the tested property, the request is a correctly signed webhook.
        $body = $json ? json_encode(WebhookFixtures::load('delivered'), \JSON_THROW_ON_ERROR) : 'event=delivered';
        $request = Request::create('/webhook/haskimail', $method, server: ['CONTENT_TYPE' => 'application/json'], content: $body);

        try {
            (new HaskimailRequestParser())->parse($request, WebhookFixtures::SIGNING_KEY);
            self::fail('The webhook should have been rejected.');
        } catch (RejectWebhookException $e) {
            self::assertSame(406, $e->getStatusCode());
            self::assertSame('Request does not match.', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMalformedBodies(): iterable
    {
        yield 'invalid json' => ['{"event":'];
        yield 'json list' => ['[{"event":"delivered"}]'];
        yield 'empty object' => ['{}'];
        yield 'json string' => ['"delivered"'];
    }

    #[DataProvider('provideMalformedBodies')]
    public function testMalformedBodiesAreRejectedWith406(string $body): void
    {
        try {
            (new HaskimailRequestParser())->parse(self::rawRequest($body), WebhookFixtures::SIGNING_KEY);
            self::fail('The webhook should have been rejected.');
        } catch (RejectWebhookException $e) {
            self::assertSame(406, $e->getStatusCode());
        }
    }

    public function testValidSignatureButUnsupportedEventIsRejectedWith406(): void
    {
        $payload = WebhookFixtures::load('delivered');
        $payload['event'] = 'teleported';

        try {
            (new HaskimailRequestParser())->parse(self::request($payload), WebhookFixtures::SIGNING_KEY);
            self::fail('The webhook should have been rejected.');
        } catch (RejectWebhookException $e) {
            self::assertSame(406, $e->getStatusCode());
            self::assertStringContainsString('teleported', $e->getMessage());
        }
    }

    public function testSignatureIsRemovedFromThePayloadGivenToConsumers(): void
    {
        $event = (new HaskimailRequestParser())->parse(self::request(WebhookFixtures::load('bounced')), WebhookFixtures::SIGNING_KEY);

        self::assertInstanceOf(RemoteEvent::class, $event);
        self::assertArrayNotHasKey('secret', $event->getPayload());
        self::assertSame('hrd', $event->getPayload()['bounce_category']);
    }

    public function testBlankSignedRecipientCannotBorrowTheEmailField(): void
    {
        $payload = WebhookFixtures::load('delivered', false);
        $payload['recipient'] = ' ';
        $payload['email'] = 'victim@example.com';

        try {
            (new HaskimailRequestParser())->parse(self::request(WebhookFixtures::sign($payload)), WebhookFixtures::SIGNING_KEY);
            self::fail('The webhook should have been rejected.');
        } catch (RejectWebhookException $e) {
            self::assertSame(406, $e->getStatusCode());
        }
    }

    public function testEmptyAllowListIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HaskimailRequestParser(allowedIps: []);
    }

    public function testNullRecipientFallsBackToEmail(): void
    {
        $payload = WebhookFixtures::load('delivered', false);
        $payload['recipient'] = null;

        $event = (new HaskimailRequestParser())->parse(self::request(WebhookFixtures::sign($payload)), WebhookFixtures::SIGNING_KEY);

        self::assertInstanceOf(MailerDeliveryEvent::class, $event);
        self::assertSame('ivan@example.com', $event->getRecipientEmail());
    }

    public function testOldEventsAreAccepted(): void
    {
        // The documented example dates from 2019; event times are not signed, so they are not used for security.
        self::assertNotNull((new HaskimailRequestParser())->parse(self::request(WebhookFixtures::load('complained')), WebhookFixtures::SIGNING_KEY));
    }

    public function testIpAllowList(): void
    {
        $parser = new HaskimailRequestParser(new HaskimailPayloadConverter(), ['192.0.2.0/24']);
        $payload = WebhookFixtures::load('opened');

        $event = $parser->parse(self::request($payload, '192.0.2.10'), WebhookFixtures::SIGNING_KEY);
        self::assertInstanceOf(MailerEngagementEvent::class, $event);

        $this->expectException(RejectWebhookException::class);
        $parser->parse(self::request($payload, '198.51.100.1'), WebhookFixtures::SIGNING_KEY);
    }

    public function testResponses(): void
    {
        $parser = new HaskimailRequestParser();

        self::assertSame(202, $parser->createSuccessfulResponse()->getStatusCode());
        self::assertSame(406, $parser->createRejectedResponse('nope')->getStatusCode());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function request(array $payload, string $ip = '127.0.0.1'): Request
    {
        return self::rawRequest(json_encode($payload, \JSON_THROW_ON_ERROR), $ip);
    }

    private static function rawRequest(string $body, string $ip = '127.0.0.1'): Request
    {
        return Request::create('/webhook/haskimail', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $ip,
        ], content: $body);
    }
}
