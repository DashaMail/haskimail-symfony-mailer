<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\RemoteEvent;

use Symfony\Component\RemoteEvent\Exception\ParseException;

/**
 * Thrown for an authenticated event whose "event" type is not known yet.
 *
 * Unlike other parse failures (missing fields, invalid dates), this is not a
 * malformed payload: Haskimail started sending a new event type that the SDK does
 * not recognize. The caller should acknowledge it (HTTP 2xx) so Haskimail stops
 * redelivering, instead of answering 4xx and losing the event forever.
 */
final class UnsupportedEventException extends ParseException
{
}
