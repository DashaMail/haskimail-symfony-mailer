<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Header;

use Symfony\Component\Mime\Header\UnstructuredHeader;

/**
 * Routes a single message through a specific Haskimail channel (MessageStream).
 *
 * Takes precedence over the transport-wide "message_stream" DSN option.
 * Only meaningful for the HTTP API transport: SMTP credentials are already bound to a channel.
 */
final class MessageStreamHeader extends UnstructuredHeader
{
    public const NAME = 'X-Haskimail-Message-Stream';

    public function __construct(string $messageStream)
    {
        $messageStream = trim($messageStream);

        if ('' === $messageStream) {
            throw new \InvalidArgumentException('The Haskimail message stream ID must not be empty.');
        }

        parent::__construct(self::NAME, $messageStream);
    }
}
