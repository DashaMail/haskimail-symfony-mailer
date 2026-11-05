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
 * Template variables ("TemplateModel") for a message sent with a {@see TemplateHeader}.
 *
 * Haskimail performs a literal find-and-replace, so the keys must contain the
 * placeholder delimiters exactly as written in the template, e.g. "{{name}}".
 * Use {@see self::fromVariables()} to add the delimiters automatically.
 */
final class TemplateModelHeader extends UnstructuredHeader
{
    public const NAME = 'X-Haskimail-Template-Model';

    /** @var array<string, string> */
    private readonly array $model;

    private bool $locked = false;

    /**
     * @param array<string, string|int|float|\Stringable|null> $model placeholder (with delimiters) => replacement
     */
    public function __construct(array $model)
    {
        $this->model = self::normalize($model);

        parent::__construct(self::NAME, json_encode($this->model, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION));
        $this->locked = true;
    }

    /**
     * Builds a model from bare variable names: ['name' => 'Ivan'] becomes ['{{name}}' => 'Ivan'].
     *
     * @param array<string, string|int|float|\Stringable|null> $variables
     */
    public static function fromVariables(array $variables, string $openingDelimiter = '{{', string $closingDelimiter = '}}'): self
    {
        if ('' === $openingDelimiter || '' === $closingDelimiter) {
            throw new \InvalidArgumentException('Template placeholder delimiters must not be empty.');
        }

        $model = [];
        foreach ($variables as $name => $value) {
            $model[$openingDelimiter.$name.$closingDelimiter] = $value;
        }

        return new self($model);
    }

    /**
     * @return array<string, string>
     */
    public function getModel(): array
    {
        return $this->model;
    }

    public function setValue(string $value): void
    {
        if ($this->locked) {
            throw new \LogicException(\sprintf('The "%s" header is immutable; create a new %s instead.', self::NAME, self::class));
        }

        parent::setValue($value);
    }

    /**
     * @param array<mixed> $model
     *
     * @return array<string, string>
     */
    private static function normalize(array $model): array
    {
        $normalized = [];

        foreach ($model as $placeholder => $value) {
            if (!\is_string($placeholder) || '' === $placeholder) {
                throw new \InvalidArgumentException('Template model keys must be non-empty placeholder strings such as "{{name}}".');
            }

            $normalized[$placeholder] = match (true) {
                null === $value => '',
                \is_string($value) => $value,
                \is_int($value), \is_float($value) => (string) $value,
                $value instanceof \Stringable => (string) $value,
                default => throw new \InvalidArgumentException(\sprintf('The value of template placeholder "%s" must be a string, a number, a Stringable object or null, "%s" given.', $placeholder, get_debug_type($value))),
            };
        }

        return $normalized;
    }
}
