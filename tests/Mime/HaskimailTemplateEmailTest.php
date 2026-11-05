<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Tests\Mime;

use Haskimail\SymfonyMailer\Header\TemplateHeader;
use Haskimail\SymfonyMailer\Header\TemplateModelHeader;
use Haskimail\SymfonyMailer\Mime\HaskimailTemplateEmail;
use PHPUnit\Framework\TestCase;

final class HaskimailTemplateEmailTest extends TestCase
{
    public function testTemplateByIdIsValidWithoutBody(): void
    {
        $email = (new HaskimailTemplateEmail())->from('a@example.com')->to('b@example.com')->template(42, ['name' => 'Ivan']);

        $email->ensureValidity();

        $template = $email->getHeaders()->get(TemplateHeader::NAME);
        self::assertInstanceOf(TemplateHeader::class, $template);
        self::assertSame(42, $template->getTemplateId());

        $model = $email->getHeaders()->get(TemplateModelHeader::NAME);
        self::assertInstanceOf(TemplateModelHeader::class, $model);
        self::assertSame(['{{name}}' => 'Ivan'], $model->getModel());
        self::assertSame('', $email->getTextBody());
    }

    public function testTemplateByAliasWithCustomDelimiters(): void
    {
        $email = (new HaskimailTemplateEmail())->template('welcome', ['name' => 'Ivan'], '[[', ']]');

        $template = $email->getHeaders()->get(TemplateHeader::NAME);
        self::assertInstanceOf(TemplateHeader::class, $template);
        self::assertSame('welcome', $template->getTemplateAlias());

        $model = $email->getHeaders()->get(TemplateModelHeader::NAME);
        self::assertInstanceOf(TemplateModelHeader::class, $model);
        self::assertSame(['[[name]]' => 'Ivan'], $model->getModel());
    }

    public function testExistingBodyIsKept(): void
    {
        $email = (new HaskimailTemplateEmail())->html('<p>fallback</p>')->template(1);

        self::assertNull($email->getTextBody());
        self::assertSame('<p>fallback</p>', $email->getHtmlBody());
    }

    public function testCallingTemplateTwiceReplacesHeaders(): void
    {
        $email = (new HaskimailTemplateEmail())->template(1, ['a' => '1'])->templateWithRawModel('second', ['%b%' => '2']);

        self::assertCount(1, iterator_to_array($email->getHeaders()->all(strtolower(TemplateHeader::NAME)), false));
        self::assertCount(1, iterator_to_array($email->getHeaders()->all(strtolower(TemplateModelHeader::NAME)), false));

        $model = $email->getHeaders()->get(TemplateModelHeader::NAME);
        self::assertInstanceOf(TemplateModelHeader::class, $model);
        self::assertSame(['%b%' => '2'], $model->getModel());
    }

    public function testInvalidTemplateDoesNotLeaveHalfConfiguredHeaders(): void
    {
        $email = (new HaskimailTemplateEmail())->template(1, ['a' => '1']);

        try {
            $email->template(0);
            self::fail('An exception should have been thrown.');
        } catch (\InvalidArgumentException) {
        }

        $template = $email->getHeaders()->get(TemplateHeader::NAME);
        self::assertInstanceOf(TemplateHeader::class, $template);
        self::assertSame(1, $template->getTemplateId());
    }

    public function testSerializationRoundTrip(): void
    {
        $email = unserialize(serialize((new HaskimailTemplateEmail())->from('a@example.com')->to('b@example.com')->template(9, ['x' => 'y'])));

        self::assertInstanceOf(HaskimailTemplateEmail::class, $email);
        self::assertInstanceOf(TemplateHeader::class, $email->getHeaders()->get(TemplateHeader::NAME));
    }
}
