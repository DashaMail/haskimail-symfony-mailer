<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Tests\Integration\App;

use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\RemoteEvent;

final class RecordingConsumer implements ConsumerInterface
{
    /** @var list<RemoteEvent> */
    public array $events = [];

    public function consume(RemoteEvent $event): void
    {
        $this->events[] = $event;
    }
}
