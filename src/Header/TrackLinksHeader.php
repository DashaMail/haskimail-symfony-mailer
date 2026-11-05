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
 * Overrides the server-level link tracking setting for a single message ("TrackLinks").
 */
final class TrackLinksHeader extends UnstructuredHeader
{
    public const NAME = 'X-Haskimail-Track-Links';

    public function __construct(bool $enabled)
    {
        parent::__construct(self::NAME, $enabled ? 'true' : 'false');
    }

    public function isEnabled(): bool
    {
        return 'true' === $this->getValue();
    }
}
