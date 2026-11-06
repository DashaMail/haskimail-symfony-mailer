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
 * Sets the internal Haskimail MessageID ("X-HM-MessageID") for a single message.
 *
 * This ID is returned in the "message_id" field of webhooks and in message logs, so
 * a message sent over SMTP can be linked back to its events. Without it Haskimail
 * generates a random ID that is not exposed by the SMTP handshake.
 */
final class HaskimailMessageIdHeader extends UnstructuredHeader
{
    public const NAME = 'X-Haskimail-MessageID';

    public function __construct(string $messageId)
    {
        $messageId = trim($messageId);

        if ('' === $messageId) {
            throw new \InvalidArgumentException('The Haskimail MessageID must not be empty.');
        }

        if (false !== strpbrk($messageId, "\r\n\0")) {
            throw new \InvalidArgumentException('The Haskimail MessageID must not contain line breaks or NUL bytes.');
        }

        parent::__construct(self::NAME, $messageId);
    }
}
