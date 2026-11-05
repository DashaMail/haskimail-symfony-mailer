<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Tests\Transport;

use Haskimail\SymfonyMailer\Header\MessageStreamHeader;
use Haskimail\SymfonyMailer\Header\TemplateHeader;
use Haskimail\SymfonyMailer\Header\TemplateModelHeader;
use Haskimail\SymfonyMailer\Header\TrackLinksHeader;
use Haskimail\SymfonyMailer\Header\TrackOpensHeader;
use Haskimail\SymfonyMailer\Transport\HaskimailSmtpTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\RawMessage;

final class HaskimailSmtpTransportTest extends TestCase
{
    public function testDefaults(): void
    {
        $transport = new HaskimailSmtpTransport('login', 'secret');

        self::assertSame('smtp://smtp.haskimail.ru:587', (string) $transport);
        self::assertSame('login', $transport->getUsername());
        self::assertSame('secret', $transport->getPassword());
        self::assertStringNotContainsString('secret', (string) $transport);

        // Both accessors only exist in recent symfony/mailer versions.
        if (method_exists($transport, 'isAutoTls')) { // @phpstan-ignore function.alreadyNarrowedType
            self::assertTrue($transport->isAutoTls());
        }
        if (method_exists($transport, 'isTlsRequired')) { // @phpstan-ignore function.alreadyNarrowedType
            self::assertTrue($transport->isTlsRequired());
        }
    }

    public function testCustomHostAndPort(): void
    {
        self::assertSame('smtp://relay.example.com:2525', (string) new HaskimailSmtpTransport('login', 'secret', 'relay.example.com', 2525));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideEmptyCredentials(): iterable
    {
        yield 'empty login' => ['', 'secret'];
        yield 'empty password' => ['login', ''];
    }

    #[DataProvider('provideEmptyCredentials')]
    public function testEmptyCredentialsAreRejected(string $username, string $password): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HaskimailSmtpTransport($username, $password);
    }

    public function testTagAndMetadataAreConverted(): void
    {
        $headers = (new Headers())
            ->add(new TagHeader('orders'))
            ->add(new MetadataHeader('order_id', '1042'))
            ->add(new MetadataHeader('city', 'Москва'))
            ->addTextHeader('X-Custom', 'kept');

        HaskimailSmtpTransport::convertHeaders($headers);

        self::assertSame([
            'X-Custom' => 'kept',
            'X-HM-Tag' => 'orders',
            'X-HM-Metadata' => '{"order_id":"1042","city":"\u041c\u043e\u0441\u043a\u0432\u0430"}',
        ], self::headerValues($headers));
    }

    public function testMetadataSlashesAreNotEscaped(): void
    {
        $headers = (new Headers())->add(new MetadataHeader('url', 'https://example.com/a'));

        HaskimailSmtpTransport::convertHeaders($headers);

        self::assertSame('{"url":"https://example.com/a"}', $headers->getHeaderBody('X-HM-Metadata'));
    }

    public function testNumericMetadataKeysProduceAJsonObject(): void
    {
        $headers = (new Headers())->add(new MetadataHeader('0', 'zero'));

        HaskimailSmtpTransport::convertHeaders($headers);

        self::assertSame('{"0":"zero"}', $headers->getHeaderBody('X-HM-Metadata'));
    }

    public function testOnlyOneTagIsAllowed(): void
    {
        $headers = (new Headers())->add(new TagHeader('a'))->add(new TagHeader('b'));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('single tag');

        HaskimailSmtpTransport::convertHeaders($headers);
    }

    public function testTagConflictsWithRawHeader(): void
    {
        $headers = (new Headers())->add(new TagHeader('a'))->addTextHeader('X-HM-Tag', 'b');

        $this->expectException(TransportException::class);

        HaskimailSmtpTransport::convertHeaders($headers);
    }

    public function testMetadataConflictsWithRawHeader(): void
    {
        $headers = (new Headers())->add(new MetadataHeader('a', 'b'))->addTextHeader('X-HM-Metadata', '{}');

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('not both');

        HaskimailSmtpTransport::convertHeaders($headers);
    }

    public function testRawHaskimailHeadersArePassedThrough(): void
    {
        $headers = (new Headers())->addTextHeader('X-HM-Tag', 'raw')->addTextHeader('X-HM-Metadata', '{"a":"b"}');

        HaskimailSmtpTransport::convertHeaders($headers);

        self::assertSame(['X-HM-Tag' => 'raw', 'X-HM-Metadata' => '{"a":"b"}'], self::headerValues($headers));
    }

    public function testDisabledTrackingIsConverted(): void
    {
        $headers = (new Headers())->add(new TrackOpensHeader(false))->add(new TrackLinksHeader(false));

        HaskimailSmtpTransport::convertHeaders($headers);

        self::assertSame(['X-HM-No-Track-Opens' => 'true', 'X-HM-No-Track-Click' => 'true'], self::headerValues($headers));
    }

    public function testEnabledTrackingFallsBackToTheServerSetting(): void
    {
        $headers = (new Headers())->add(new TrackOpensHeader(true))->addTextHeader(TrackLinksHeader::NAME, 'yes');

        HaskimailSmtpTransport::convertHeaders($headers);

        self::assertSame([], self::headerValues($headers));
    }

    public function testInvalidTrackingValueIsRejected(): void
    {
        $headers = (new Headers())->addTextHeader(TrackOpensHeader::NAME, 'sometimes');

        $this->expectException(TransportException::class);

        HaskimailSmtpTransport::convertHeaders($headers);
    }

    /**
     * @return iterable<string, array{HeaderInterface, string}>
     */
    public static function provideApiOnlyHeaders(): iterable
    {
        yield 'message stream' => [new MessageStreamHeader('2081'), 'bound to a single channel'];
        yield 'template' => [TemplateHeader::id(1), 'API transport'];
        yield 'template model' => [new TemplateModelHeader(['{{a}}' => 'b']), 'API transport'];
    }

    #[DataProvider('provideApiOnlyHeaders')]
    public function testApiOnlyHeadersAreRejected(HeaderInterface $header, string $message): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage($message);

        HaskimailSmtpTransport::convertHeaders((new Headers())->add($header));
    }

    public function testSendNeverMutatesTheOriginalMessage(): void
    {
        $email = (new Email())->from('a@example.com')->to('b@example.com')->text('Hi');
        $email->getHeaders()->add(new TagHeader('orders'))->add(TemplateHeader::id(5));
        $before = $email->getHeaders()->toArray();

        try {
            (new HaskimailSmtpTransport('login', 'secret'))->send($email);
            self::fail('An exception should have been thrown.');
        } catch (TransportException $e) {
            self::assertStringContainsString('API transport', $e->getMessage());
        }

        self::assertSame($before, $email->getHeaders()->toArray());
    }

    public function testRawMessagesArePassedToTheSmtpLayerUnchanged(): void
    {
        // Port 1 on localhost is closed: the connection fails after the message went through send().
        $transport = new HaskimailSmtpTransport('login', 'secret', '127.0.0.1', 1);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Connection could not be established');

        $transport->send(new RawMessage('Subject: raw'), new Envelope(new Address('a@example.com'), [new Address('b@example.com')]));
    }

    /**
     * @return array<string, string>
     */
    private static function headerValues(Headers $headers): array
    {
        $values = [];
        foreach ($headers->all() as $header) {
            self::assertInstanceOf(HeaderInterface::class, $header);
            $values[$header->getName()] = $header->getBodyAsString();
        }

        return $values;
    }
}
