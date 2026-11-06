<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Tests\Header;

use Haskimail\SymfonyMailer\Header\HaskimailDomainHeader;
use Haskimail\SymfonyMailer\Header\HaskimailMessageIdHeader;
use Haskimail\SymfonyMailer\Header\MessageStreamHeader;
use Haskimail\SymfonyMailer\Header\TemplateHeader;
use Haskimail\SymfonyMailer\Header\TemplateModelHeader;
use Haskimail\SymfonyMailer\Header\TrackLinksHeader;
use Haskimail\SymfonyMailer\Header\TrackOpensHeader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HeadersTest extends TestCase
{
    public function testMessageStreamHeader(): void
    {
        $header = new MessageStreamHeader(' 2081 ');

        self::assertSame('X-Haskimail-Message-Stream', $header->getName());
        self::assertSame('2081', $header->getValue());
    }

    public function testMessageStreamHeaderRejectsBlankValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MessageStreamHeader(' ');
    }

    public function testDomainHeader(): void
    {
        $header = new HaskimailDomainHeader(' example.com ');

        self::assertSame('X-Haskimail-Domain', $header->getName());
        self::assertSame('example.com', $header->getValue());
    }

    public function testDomainHeaderRejectsBlankValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HaskimailDomainHeader(' ');
    }

    public function testMessageIdHeader(): void
    {
        $header = new HaskimailMessageIdHeader(' test-123 ');

        self::assertSame('X-Haskimail-MessageID', $header->getName());
        self::assertSame('test-123', $header->getValue());
    }

    public function testMessageIdHeaderRejectsHeaderInjection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HaskimailMessageIdHeader("x\r\nBcc: evil@example.com");
    }

    public function testMessageIdHeaderRejectsBlankValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HaskimailMessageIdHeader(' ');
    }

    public function testTrackingHeaders(): void
    {
        self::assertTrue((new TrackOpensHeader(true))->isEnabled());
        self::assertFalse((new TrackOpensHeader(false))->isEnabled());
        self::assertSame('true', (new TrackLinksHeader(true))->getValue());
        self::assertSame('X-Haskimail-Track-Links', (new TrackLinksHeader(false))->getName());

        $header = new TrackLinksHeader(true);
        $header->setValue('false');
        self::assertFalse($header->isEnabled(), 'The flag always reflects the current header value.');
    }

    public function testTemplateHeaderById(): void
    {
        $header = TemplateHeader::id(4521);

        self::assertSame(4521, $header->getTemplateId());
        self::assertNull($header->getTemplateAlias());
        self::assertSame('4521', $header->getValue());
        self::assertSame('X-Haskimail-Template', $header->getName());
    }

    public function testTemplateHeaderByAlias(): void
    {
        $header = TemplateHeader::alias('welcome');

        self::assertNull($header->getTemplateId());
        self::assertSame('welcome', $header->getTemplateAlias());
        self::assertSame('welcome', $header->getValue());
    }

    /**
     * @return iterable<string, array{\Closure(): mixed}>
     */
    public static function provideInvalidTemplates(): iterable
    {
        yield 'zero id' => [static fn () => TemplateHeader::id(0)];
        yield 'negative id' => [static fn () => TemplateHeader::id(-1)];
        yield 'blank alias' => [static fn () => TemplateHeader::alias('  ')];
    }

    #[DataProvider('provideInvalidTemplates')]
    public function testInvalidTemplates(\Closure $factory): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $factory();
    }

    public function testTemplateHeaderIsImmutable(): void
    {
        $this->expectException(\LogicException::class);

        TemplateHeader::id(1)->setValue('2');
    }

    public function testTemplateHeaderBodyIsImmutable(): void
    {
        $this->expectException(\LogicException::class);

        TemplateHeader::alias('a')->setBody('b');
    }

    public function testTemplateModelHeader(): void
    {
        $header = new TemplateModelHeader([
            '{{name}}' => 'Иван',
            '{{order}}' => 1042,
            '{{total}}' => 99.5,
            '{{empty}}' => null,
            '{{url}}' => new class implements \Stringable {
                public function __toString(): string
                {
                    return 'https://example.com/a/b';
                }
            },
        ]);

        self::assertSame([
            '{{name}}' => 'Иван',
            '{{order}}' => '1042',
            '{{total}}' => '99.5',
            '{{empty}}' => '',
            '{{url}}' => 'https://example.com/a/b',
        ], $header->getModel());
        self::assertSame('{"{{name}}":"Иван","{{order}}":"1042","{{total}}":"99.5","{{empty}}":"","{{url}}":"https://example.com/a/b"}', $header->getValue());
    }

    public function testTemplateModelFromVariables(): void
    {
        self::assertSame(['{{name}}' => 'Ivan'], TemplateModelHeader::fromVariables(['name' => 'Ivan'])->getModel());
        self::assertSame(['%name%' => 'Ivan'], TemplateModelHeader::fromVariables(['name' => 'Ivan'], '%', '%')->getModel());
        self::assertSame([], TemplateModelHeader::fromVariables([])->getModel());
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function provideInvalidModels(): iterable
    {
        yield 'list' => [['a', 'b']];
        yield 'empty key' => [['' => 'x']];
        yield 'bool value' => [['{{a}}' => true]];
        yield 'array value' => [['{{a}}' => ['x']]];
        yield 'object value' => [['{{a}}' => new \stdClass()]];
    }

    /**
     * @param array<mixed> $model
     */
    #[DataProvider('provideInvalidModels')]
    public function testInvalidModels(array $model): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TemplateModelHeader($model); // @phpstan-ignore argument.type
    }

    public function testEmptyDelimitersAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TemplateModelHeader::fromVariables(['a' => 'b'], '', '}}');
    }

    public function testEmptyClosingDelimiterIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TemplateModelHeader::fromVariables(['a' => 'b'], '{{', '');
    }

    public function testTemplateModelHeaderIsImmutable(): void
    {
        $this->expectException(\LogicException::class);

        (new TemplateModelHeader([]))->setValue('{}');
    }

    public function testHeadersSurviveSerialization(): void
    {
        $template = unserialize(serialize(TemplateHeader::id(7)));
        $model = unserialize(serialize(new TemplateModelHeader(['{{a}}' => 'b'])));

        self::assertInstanceOf(TemplateHeader::class, $template);
        self::assertSame(7, $template->getTemplateId());
        self::assertInstanceOf(TemplateModelHeader::class, $model);
        self::assertSame(['{{a}}' => 'b'], $model->getModel());
    }
}
