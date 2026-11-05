<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Tests\RemoteEvent;

use Haskimail\SymfonyMailer\RemoteEvent\HaskimailPayloadConverter;
use Haskimail\SymfonyMailer\Tests\Webhook\WebhookFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerDeliveryEvent;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerEngagementEvent;
use Symfony\Component\RemoteEvent\Exception\ParseException;
use Symfony\Component\RemoteEvent\RemoteEvent;

final class HaskimailPayloadConverterTest extends TestCase
{
    private HaskimailPayloadConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new HaskimailPayloadConverter();
    }

    public function testBounce(): void
    {
        $payload = WebhookFixtures::load('bounced');
        $event = $this->converter->convert($payload);

        self::assertInstanceOf(MailerDeliveryEvent::class, $event);
        self::assertSame(MailerDeliveryEvent::BOUNCE, $event->getName());
        self::assertSame('a41d7c9e2b6f4e0d8c3b5a1f9e7d2c60', $event->getId());
        self::assertSame('old-address@example.com', $event->getRecipientEmail());
        self::assertSame('550 5.1.1 The email account that you tried to reach does not exist', $event->getReason());
        self::assertSame(['orders'], $event->getTags());
        self::assertSame(['order_id' => '1042'], $event->getMetadata());
        self::assertSame('2026-10-07T11:30:15+00:00', $event->getDate()->format(\DATE_ATOM));
        self::assertSame($payload, $event->getPayload());
        self::assertSame('hrd', $event->getPayload()['bounce_category']);
    }

    /**
     * @return iterable<string, array{string, class-string<RemoteEvent>, string}>
     */
    public static function provideEvents(): iterable
    {
        yield 'delivered' => ['delivered', MailerDeliveryEvent::class, MailerDeliveryEvent::DELIVERED];
        yield 'opened' => ['opened', MailerEngagementEvent::class, MailerEngagementEvent::OPEN];
        yield 'clicked' => ['clicked', MailerEngagementEvent::class, MailerEngagementEvent::CLICK];
        yield 'complained' => ['complained', MailerEngagementEvent::class, MailerEngagementEvent::SPAM];
        yield 'unsubscribed' => ['subscriptionchange', MailerEngagementEvent::class, MailerEngagementEvent::UNSUBSCRIBE];
        yield 'resubscribed' => ['resubscribe', RemoteEvent::class, HaskimailPayloadConverter::RESUBSCRIBE];
    }

    /**
     * @param class-string<RemoteEvent> $class
     */
    #[DataProvider('provideEvents')]
    public function testEventMapping(string $fixture, string $class, string $name): void
    {
        $event = $this->converter->convert(WebhookFixtures::load($fixture));

        self::assertSame($class, $event::class);
        self::assertSame($name, $event->getName());
    }

    public function testDeliveredEventHasNoReason(): void
    {
        $event = $this->converter->convert(WebhookFixtures::load('delivered'));

        self::assertInstanceOf(MailerDeliveryEvent::class, $event);
        self::assertSame('', $event->getReason());
    }

    public function testSpamComplaintExampleFromTheDocs(): void
    {
        $event = $this->converter->convert(WebhookFixtures::load('complained'));

        self::assertInstanceOf(MailerEngagementEvent::class, $event);
        self::assertSame(['a_key' => 'a_value', 'b_key' => 'b_value'], $event->getMetadata());
        self::assertSame(['Test'], $event->getTags());
        // No "event_time_utc": "event_time" is Moscow time (UTC+3).
        self::assertSame('2019-11-05T13:33:54+00:00', $event->getDate()->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
    }

    public function testClickPayloadKeepsUrls(): void
    {
        $event = $this->converter->convert(WebhookFixtures::load('clicked'));

        self::assertSame('https://example.com/order/1042', $event->getPayload()['url_pers']);
    }

    public function testResubscribeEventWithoutMessageIdGetsAStableId(): void
    {
        $payload = WebhookFixtures::load('resubscribe');

        $first = $this->converter->convert($payload);
        $second = $this->converter->convert($payload);

        self::assertMatchesRegularExpression('/^haskimail-[0-9a-f]{64}$/', $first->getId());
        self::assertSame($first->getId(), $second->getId());

        $payload['event_time_utc'] = '2026-10-07 11:30:16';
        self::assertNotSame($first->getId(), $this->converter->convert($payload)->getId());
    }

    public function testDerivedIdsDependOnEveryComponent(): void
    {
        $base = WebhookFixtures::load('resubscribe');
        unset($base['event_time_utc']);
        $id = $this->converter->convert($base)->getId();

        $ids = [$id];
        $ids[] = $this->converter->convert(['SuppressSending' => 'true'] + $base)->getId(); // same type, other mapping: same id
        $ids[] = $this->converter->convert(['event' => 'delivered', 'campaign_id' => ''] + $base)->getId();
        $ids[] = $this->converter->convert(['recipient' => 'other@example.com'] + $base)->getId();
        $ids[] = $this->converter->convert(['event_time' => '2026-10-07 14:30:16'] + $base)->getId();

        self::assertSame($ids[0], $ids[1]);
        self::assertCount(4, array_unique($ids));
        self::assertSame('haskimail-'.hash('sha256', "subscriptionchange\0ivan@example.com\0".'2026-10-07 14:30:15'), $id);
    }

    public function testRecipientIsTrimmed(): void
    {
        $payload = WebhookFixtures::load('delivered');
        $payload['recipient'] = ' ivan@example.com ';

        $event = $this->converter->convert($payload);

        self::assertInstanceOf(MailerDeliveryEvent::class, $event);
        self::assertSame('ivan@example.com', $event->getRecipientEmail());
    }

    public function testBounceReasonPrefersDescription(): void
    {
        $payload = WebhookFixtures::load('bounced');
        $payload['description'] = 'description';
        $payload['error'] = 'error';

        $event = $this->converter->convert($payload);

        self::assertInstanceOf(MailerDeliveryEvent::class, $event);
        self::assertSame('description', $event->getReason());
    }

    public function testManualUnsubscribeWithoutMessageId(): void
    {
        $payload = WebhookFixtures::load('subscriptionchange');
        $payload['message_id'] = '';
        $payload['SuppressionReason'] = 'ManualSuppression';
        unset($payload['custom_vars']);

        $event = $this->converter->convert($payload);

        self::assertInstanceOf(MailerEngagementEvent::class, $event);
        self::assertStringStartsWith('haskimail-', $event->getId());
        self::assertSame([], $event->getMetadata());
    }

    #[DataProvider('provideSuppressSendingValues')]
    public function testSuppressSendingValues(mixed $value, string $expectedName): void
    {
        $payload = WebhookFixtures::load('subscriptionchange');
        $payload['SuppressSending'] = $value;

        self::assertSame($expectedName, $this->converter->convert($payload)->getName());
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideSuppressSendingValues(): iterable
    {
        yield 'string true' => ['true', MailerEngagementEvent::UNSUBSCRIBE];
        yield 'uppercase' => ['TRUE', MailerEngagementEvent::UNSUBSCRIBE];
        yield 'bool true' => [true, MailerEngagementEvent::UNSUBSCRIBE];
        yield 'string false' => ['false', HaskimailPayloadConverter::RESUBSCRIBE];
        yield 'uppercase false' => ['FALSE', HaskimailPayloadConverter::RESUBSCRIBE];
        yield 'bool false' => [false, HaskimailPayloadConverter::RESUBSCRIBE];
    }

    public function testEventNameIsCaseInsensitive(): void
    {
        $payload = WebhookFixtures::load('delivered');
        $payload['event'] = ' Delivered ';

        self::assertSame(MailerDeliveryEvent::DELIVERED, $this->converter->convert($payload)->getName());
    }

    public function testRecipientFallsBackToEmail(): void
    {
        $payload = WebhookFixtures::load('delivered');
        unset($payload['recipient']);

        $event = $this->converter->convert($payload);

        self::assertInstanceOf(MailerDeliveryEvent::class, $event);
        self::assertSame('ivan@example.com', $event->getRecipientEmail());
    }

    public function testBounceReasonFallsBackToError(): void
    {
        $payload = WebhookFixtures::load('bounced');
        $payload['description'] = '';
        $payload['error'] = '421 try later';

        $event = $this->converter->convert($payload);

        self::assertInstanceOf(MailerDeliveryEvent::class, $event);
        self::assertSame('421 try later', $event->getReason());
    }

    public function testBounceWithoutReason(): void
    {
        $payload = WebhookFixtures::load('bounced');
        unset($payload['description'], $payload['error']);

        $event = $this->converter->convert($payload);

        self::assertInstanceOf(MailerDeliveryEvent::class, $event);
        self::assertSame('', $event->getReason());
    }

    public function testEmptyTagIsIgnored(): void
    {
        $payload = WebhookFixtures::load('delivered');
        $payload['campaign_id'] = '';

        $event = $this->converter->convert($payload);

        self::assertInstanceOf(MailerDeliveryEvent::class, $event);
        self::assertSame([], $event->getTags());
    }

    /**
     * @return iterable<string, array{mixed, array<array-key, mixed>}>
     */
    public static function provideCustomVars(): iterable
    {
        yield 'json string' => ['{"a":"1","b":2}', ['a' => '1', 'b' => 2]];
        yield 'object' => [['a' => '1'], ['a' => '1']];
        yield 'empty string' => ['', []];
        yield 'malformed json' => ['{oops', []];
        yield 'json scalar' => ['"text"', []];
        yield 'json list keys become strings' => ['["x"]', ['0' => 'x']];
        yield 'null' => [null, []];
        yield 'number' => [42, []];
    }

    /**
     * @param array<array-key, mixed> $expected
     */
    #[DataProvider('provideCustomVars')]
    public function testCustomVars(mixed $customVars, array $expected): void
    {
        $payload = WebhookFixtures::load('delivered');
        $payload['custom_vars'] = $customVars;

        $event = $this->converter->convert($payload);

        self::assertInstanceOf(MailerDeliveryEvent::class, $event);
        self::assertSame($expected, $event->getMetadata());
    }

    public function testIntegerMessageIdIsAccepted(): void
    {
        $payload = WebhookFixtures::load('delivered');
        $payload['message_id'] = 12345;

        self::assertSame('12345', $this->converter->convert($payload)->getId());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>, string}>
     */
    public static function provideInvalidPayloads(): iterable
    {
        yield 'missing event' => [[], ['event'], 'no "event" field'];
        yield 'non-string event' => [['event' => 5], [], 'no "event" field'];
        yield 'unknown event' => [['event' => 'exploded'], [], 'Unsupported Haskimail event "exploded"'];
        yield 'missing recipient' => [[], ['recipient', 'email'], 'no "recipient"'];
        yield 'blank recipient' => [['recipient' => ' ', 'email' => ''], [], 'no "recipient"'];
        yield 'array message id' => [['message_id' => ['x']], [], '"message_id"'];
        yield 'missing dates' => [[], ['event_time', 'event_time_utc'], 'no "event_time"'];
        yield 'invalid date' => [['event_time_utc' => 'yesterday'], [], 'Invalid Haskimail event date'];
        yield 'overflowing date' => [['event_time_utc' => '2026-13-45 25:61:00'], [], 'Invalid Haskimail event date'];
        yield 'invalid fallback date' => [['event_time' => '07.10.2026'], ['event_time_utc'], 'Invalid Haskimail event date'];
        yield 'invalid suppress flag' => [['event' => 'subscriptionchange', 'SuppressSending' => 'maybe'], [], '"SuppressSending"'];
        yield 'missing suppress flag' => [['event' => 'subscriptionchange'], [], '"SuppressSending"'];
    }

    /**
     * @param array<string, mixed> $overrides
     * @param list<string>         $removed
     */
    #[DataProvider('provideInvalidPayloads')]
    public function testInvalidPayloads(array $overrides, array $removed, string $message): void
    {
        $payload = array_diff_key($overrides + WebhookFixtures::load('delivered'), array_flip($removed));

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage($message);

        $this->converter->convert($payload);
    }

    public function testUtcDateIsPreferredOverLocalTime(): void
    {
        $payload = WebhookFixtures::load('delivered');
        $payload['event_time'] = '2000-01-01 00:00:00';

        self::assertSame('2026-10-07 11:30:15', $this->getDate($payload));
    }

    public function testLocalTimeIsUsedWhenUtcIsEmpty(): void
    {
        $payload = WebhookFixtures::load('delivered');
        $payload['event_time_utc'] = '';

        self::assertSame('2026-10-07 11:30:15', $this->getDate($payload));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function getDate(array $payload): string
    {
        $event = $this->converter->convert($payload);
        self::assertInstanceOf(MailerDeliveryEvent::class, $event);

        return $event->getDate()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
