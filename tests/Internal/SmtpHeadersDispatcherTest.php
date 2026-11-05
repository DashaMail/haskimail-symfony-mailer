<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Tests\Internal;

use Haskimail\SymfonyMailer\Internal\SmtpHeadersDispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class SmtpHeadersDispatcherTest extends TestCase
{
    public function testConvertsAfterListenersWithoutInnerDispatcher(): void
    {
        $event = self::event();

        self::assertSame($event, (new SmtpHeadersDispatcher(null))->dispatch($event));
        self::assertSame('tag', self::headers($event)->getHeaderBody('X-HM-Tag'));
    }

    public function testConvertsAfterTheInnerDispatcher(): void
    {
        $inner = new EventDispatcher();
        $inner->addListener(MessageEvent::class, static function (MessageEvent $event): void {
            self::headers($event)->add(new TagHeader('late'));
            self::headers($event)->remove('X-Tag');
            self::headers($event)->add(new TagHeader('late'));
        });

        $event = self::event();
        (new SmtpHeadersDispatcher($inner))->dispatch($event);

        self::assertSame('late', self::headers($event)->getHeaderBody('X-HM-Tag'));
    }

    public function testQueuedEventsAreLeftAlone(): void
    {
        $event = self::event(queued: true);

        (new SmtpHeadersDispatcher(null))->dispatch($event);

        self::assertFalse(self::headers($event)->has('X-HM-Tag'));
    }

    public function testRejectedEventsAreLeftAlone(): void
    {
        $event = self::event();
        $event->reject();

        (new SmtpHeadersDispatcher(null))->dispatch($event);

        self::assertFalse(self::headers($event)->has('X-HM-Tag'));
    }

    public function testRawMessagesAndOtherEventsPassThrough(): void
    {
        $raw = new MessageEvent(new RawMessage('raw'), self::envelope(), 'smtp://x');
        $other = new \stdClass();
        $dispatcher = new SmtpHeadersDispatcher(null);

        self::assertSame($raw, $dispatcher->dispatch($raw));
        self::assertSame($other, $dispatcher->dispatch($other));
    }

    private static function event(bool $queued = false): MessageEvent
    {
        $email = (new Email())->from('a@example.com')->to('b@example.com')->text('x');
        $email->getHeaders()->add(new TagHeader('tag'));

        return new MessageEvent($email, self::envelope(), 'smtp://x', $queued);
    }

    private static function envelope(): Envelope
    {
        return new Envelope(new Address('a@example.com'), [new Address('b@example.com')]);
    }

    private static function headers(MessageEvent $event): \Symfony\Component\Mime\Header\Headers
    {
        $message = $event->getMessage();
        self::assertInstanceOf(Email::class, $message);

        return $message->getHeaders();
    }
}
