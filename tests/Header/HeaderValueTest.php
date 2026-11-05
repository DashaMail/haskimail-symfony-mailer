<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Tests\Header;

use Haskimail\SymfonyMailer\Header\TrackLinksHeader;
use Haskimail\SymfonyMailer\Header\TrackOpensHeader;
use Haskimail\SymfonyMailer\Internal\HeaderValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Header\UnstructuredHeader;

final class HeaderValueTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideValues(): iterable
    {
        foreach (['true', '1', 'yes', 'on', ' TRUE ', 'On'] as $value) {
            yield 'true: '.$value => [$value, true];
        }
        foreach (['false', '0', 'no', 'off', ' FALSE ', 'Off'] as $value) {
            yield 'false: '.$value => [$value, false];
        }
    }

    #[DataProvider('provideValues')]
    public function testToBool(string $value, bool $expected): void
    {
        self::assertSame($expected, HeaderValue::toBool(new UnstructuredHeader('X-Haskimail-Track-Opens', $value)));
    }

    public function testTypedHeaders(): void
    {
        self::assertTrue(HeaderValue::toBool(new TrackOpensHeader(true)));
        self::assertFalse(HeaderValue::toBool(new TrackLinksHeader(false)));
    }

    public function testInvalidValue(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('The "X-Test" header must be "true" or "false".');

        HeaderValue::toBool(new UnstructuredHeader('X-Test', 'enabled'));
    }
}
