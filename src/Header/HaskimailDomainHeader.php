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
 * Chooses the sending domain used for DKIM signing of a single message ("X-HM-Domain").
 *
 * Only meaningful for the SMTP transport; the REST API picks the domain from the
 * "From" address and cannot be overridden per message.
 */
final class HaskimailDomainHeader extends UnstructuredHeader
{
    public const NAME = 'X-Haskimail-Domain';

    public function __construct(string $domain)
    {
        $domain = trim($domain);

        if ('' === $domain) {
            throw new \InvalidArgumentException('The Haskimail sending domain must not be empty.');
        }

        parent::__construct(self::NAME, $domain);
    }
}
