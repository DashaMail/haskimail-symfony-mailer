<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Internal;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Header\UnstructuredHeader;

/**
 * @internal
 */
final class HeaderValue
{
    /**
     * Typed iteration over all headers, keyed by lower-cased header name.
     *
     * @return iterable<string, HeaderInterface>
     */
    public static function all(Headers $headers): iterable
    {
        /** @var iterable<string, HeaderInterface> $all */
        $all = $headers->all();

        return $all;
    }

    /**
     * The unencoded header value: JSON API fields must carry raw UTF-8, never RFC 2047 encoded-words.
     */
    public static function raw(HeaderInterface $header): string
    {
        $value = $header instanceof UnstructuredHeader ? $header->getValue() : $header->getBodyAsString();

        // Raw values are not RFC 2047-encoded, so line breaks would allow header injection downstream.
        if (false !== strpbrk($value, "\r\n\0")) {
            throw new TransportException(\sprintf('The "%s" header value must not contain line breaks or NUL bytes.', $header->getName()));
        }

        return $value;
    }

    /**
     * Reads a boolean flag from a tracking header ("true"/"false", "1"/"0", "yes"/"no", "on"/"off").
     */
    public static function toBool(HeaderInterface $header): bool
    {
        return match (strtolower(trim(self::raw($header)))) {
            'true', '1', 'yes', 'on' => true,
            'false', '0', 'no', 'off' => false,
            default => throw new TransportException(\sprintf('The "%s" header must be "true" or "false".', $header->getName())),
        };
    }
}
