<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Internal;

use Haskimail\SymfonyMailer\Transport\HaskimailSmtpTransport;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Message;

/**
 * Event dispatcher used by {@see HaskimailSmtpTransport}: once every MessageEvent listener has run
 * (including the ones that add tags or metadata, such as framework.mailer.headers), the message
 * headers are converted to the X-HM-* format. The message in the event is the transport's own
 * clone, so the caller's message is never modified.
 *
 * @internal
 */
final class SmtpHeadersDispatcher implements EventDispatcherInterface
{
    public function __construct(
        private readonly ?EventDispatcherInterface $dispatcher,
    ) {
    }

    public function dispatch(object $event): object
    {
        if (null !== $this->dispatcher) {
            $event = $this->dispatcher->dispatch($event);
        }

        if ($event instanceof MessageEvent && !$event->isQueued() && !$event->isRejected()) {
            $message = $event->getMessage();
            if ($message instanceof Message) {
                HaskimailSmtpTransport::convertHeaders($message->getHeaders());
            }
        }

        return $event;
    }
}
