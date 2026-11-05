<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Mime;

use Haskimail\SymfonyMailer\Header\TemplateHeader;
use Haskimail\SymfonyMailer\Header\TemplateModelHeader;
use Symfony\Component\Mime\Email;

/**
 * An Email whose subject and body come from a template stored in Haskimail.
 *
 *     $email = (new HaskimailTemplateEmail())
 *         ->from('shop@example.com')
 *         ->to('ivan@example.com')
 *         ->template(4521, ['name' => 'Ivan', 'order' => '1042']);
 *
 * Symfony Mime refuses to send an email without any body, so a placeholder text part is
 * set automatically; the Haskimail API transport ignores it and renders the stored template.
 */
class HaskimailTemplateEmail extends Email
{
    /**
     * @param int|string                                       $template  template ID (int) or alias (string)
     * @param array<string, string|int|float|\Stringable|null> $variables bare variable names, wrapped in the delimiters
     *
     * @return $this
     */
    public function template(int|string $template, array $variables = [], string $openingDelimiter = '{{', string $closingDelimiter = '}}'): static
    {
        return $this->applyTemplate($template, TemplateModelHeader::fromVariables($variables, $openingDelimiter, $closingDelimiter));
    }

    /**
     * Same as {@see self::template()}, but the model keys are used verbatim (they must include the delimiters).
     *
     * @param array<string, string|int|float|\Stringable|null> $model
     *
     * @return $this
     */
    public function templateWithRawModel(int|string $template, array $model): static
    {
        return $this->applyTemplate($template, new TemplateModelHeader($model));
    }

    /**
     * @return $this
     */
    private function applyTemplate(int|string $template, TemplateModelHeader $model): static
    {
        $templateHeader = \is_int($template) ? TemplateHeader::id($template) : TemplateHeader::alias($template);

        $headers = $this->getHeaders();
        $headers->remove(TemplateHeader::NAME);
        $headers->remove(TemplateModelHeader::NAME);
        $headers->add($templateHeader);
        $headers->add($model);

        if (null === $this->getTextBody() && null === $this->getHtmlBody()) {
            $this->text('');
        }

        return $this;
    }
}
