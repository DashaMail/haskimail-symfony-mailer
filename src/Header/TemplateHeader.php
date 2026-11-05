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
 * Sends the message through a template stored in Haskimail (POST /email/withTemplate).
 *
 * Create it with {@see self::id()} or {@see self::alias()}; the header is immutable afterwards.
 * Template variables go into a {@see TemplateModelHeader}.
 */
final class TemplateHeader extends UnstructuredHeader
{
    public const NAME = 'X-Haskimail-Template';

    private bool $locked = false;

    private function __construct(
        private readonly ?int $templateId,
        private readonly ?string $templateAlias,
    ) {
        parent::__construct(self::NAME, (string) ($templateId ?? $templateAlias));
        $this->locked = true;
    }

    public static function id(int $templateId): self
    {
        if ($templateId <= 0) {
            throw new \InvalidArgumentException(\sprintf('The Haskimail template ID must be a positive integer, got %d.', $templateId));
        }

        return new self($templateId, null);
    }

    public static function alias(string $templateAlias): self
    {
        if ('' === trim($templateAlias)) {
            throw new \InvalidArgumentException('The Haskimail template alias must not be empty.');
        }

        return new self(null, $templateAlias);
    }

    public function getTemplateId(): ?int
    {
        return $this->templateId;
    }

    public function getTemplateAlias(): ?string
    {
        return $this->templateAlias;
    }

    public function setValue(string $value): void
    {
        if ($this->locked) {
            throw new \LogicException(\sprintf('The "%s" header is immutable; create a new one with %s::id() or %s::alias().', self::NAME, self::class, self::class));
        }

        parent::setValue($value);
    }
}
