<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Tests\Transport;

use Haskimail\SymfonyMailer\Transport\HaskimailApiTransport;
use Haskimail\SymfonyMailer\Transport\HaskimailSmtpTransport;
use Haskimail\SymfonyMailer\Transport\HaskimailTransportFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mailer\Exception\IncompleteDsnException;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Dsn;

final class HaskimailTransportFactoryTest extends TestCase
{
    /**
     * @return iterable<string, array{Dsn, bool}>
     */
    public static function provideSupports(): iterable
    {
        yield 'default scheme' => [new Dsn('haskimail', 'default'), true];
        yield 'api' => [new Dsn('haskimail+api', 'default'), true];
        yield 'smtp' => [new Dsn('haskimail+smtp', 'default'), true];
        yield 'smtps is not offered' => [new Dsn('haskimail+smtps', 'default'), false];
        yield 'other provider' => [new Dsn('postmark+api', 'default'), false];
    }

    #[DataProvider('provideSupports')]
    public function testSupports(Dsn $dsn, bool $supported): void
    {
        self::assertSame($supported, self::factory()->supports($dsn));
    }

    /**
     * @return iterable<string, array{string, class-string, string}>
     */
    public static function provideCreate(): iterable
    {
        yield 'default scheme is the API' => ['haskimail://TOKEN@default', HaskimailApiTransport::class, 'haskimail+api://api.haskimail.ru'];
        yield 'api' => ['haskimail+api://TOKEN@default', HaskimailApiTransport::class, 'haskimail+api://api.haskimail.ru'];
        yield 'api with stream' => ['haskimail+api://TOKEN@default?message_stream=2081', HaskimailApiTransport::class, 'haskimail+api://api.haskimail.ru?message_stream=2081'];
        yield 'api with host and port' => ['haskimail+api://TOKEN@example.com:8080', HaskimailApiTransport::class, 'haskimail+api://example.com:8080'];
        yield 'smtp' => ['haskimail+smtp://LOGIN:PASS@default', HaskimailSmtpTransport::class, 'smtp://smtp.haskimail.ru:587'];
        yield 'smtp with port' => ['haskimail+smtp://LOGIN:PASS@default:2525', HaskimailSmtpTransport::class, 'smtp://smtp.haskimail.ru:2525'];
        yield 'smtp with host' => ['haskimail+smtp://LOGIN:PASS@relay.example.com:2525', HaskimailSmtpTransport::class, 'smtp://relay.example.com:2525'];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('provideCreate')]
    public function testCreate(string $dsn, string $class, string $string): void
    {
        $transport = self::factory()->create(Dsn::fromString($dsn));

        self::assertInstanceOf($class, $transport);
        self::assertSame($string, (string) $transport);
    }

    public function testUrlEncodedCredentialsAreDecoded(): void
    {
        $transport = self::factory()->create(Dsn::fromString('haskimail+smtp://my%40login:p%3Ass%2Fw@default'));

        self::assertInstanceOf(HaskimailSmtpTransport::class, $transport);
        self::assertSame('my@login', $transport->getUsername());
        self::assertSame('p:ss/w', $transport->getPassword());
    }

    public function testCreatedThroughTheMailerTransportFacade(): void
    {
        $transport = (new Transport([self::factory()]))->fromString('haskimail://TOKEN@default?message_stream=7');
        self::assertInstanceOf(HaskimailApiTransport::class, $transport);
        self::assertSame('7', $transport->getMessageStream());
    }

    /**
     * @return iterable<string, array{string, class-string<\Throwable>}>
     */
    public static function provideInvalidDsns(): iterable
    {
        yield 'unsupported scheme' => ['haskimail+smtps://LOGIN:PASS@default', UnsupportedSchemeException::class];
        yield 'missing token' => ['haskimail+api://default', IncompleteDsnException::class];
        yield 'missing smtp password' => ['haskimail+smtp://LOGIN@default', IncompleteDsnException::class];
        yield 'message stream on smtp' => ['haskimail+smtp://LOGIN:PASS@default?message_stream=1', InvalidArgumentException::class];
        yield 'array message stream' => ['haskimail+api://TOKEN@default?message_stream[]=1', InvalidArgumentException::class];
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('provideInvalidDsns')]
    public function testInvalidDsns(string $dsn, string $exception): void
    {
        $this->expectException($exception);

        self::factory()->create(Dsn::fromString($dsn));
    }

    public function testUnsupportedSchemeMessageListsSupportedSchemes(): void
    {
        $this->expectExceptionMessage('supported schemes for mailer "haskimail" are: "haskimail", "haskimail+api", "haskimail+smtp"');

        self::factory()->create(new Dsn('haskimail+smtps', 'default', 'a', 'b'));
    }

    private static function factory(): HaskimailTransportFactory
    {
        return new HaskimailTransportFactory(null, new MockHttpClient());
    }
}
