<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Tests\Transport;

use Haskimail\SymfonyMailer\Exception\HaskimailApiException;
use Haskimail\SymfonyMailer\Header\MessageStreamHeader;
use Haskimail\SymfonyMailer\Header\TemplateHeader;
use Haskimail\SymfonyMailer\Header\TemplateModelHeader;
use Haskimail\SymfonyMailer\Header\TrackLinksHeader;
use Haskimail\SymfonyMailer\Header\TrackOpensHeader;
use Haskimail\SymfonyMailer\Mime\HaskimailTemplateEmail;
use Haskimail\SymfonyMailer\Transport\HaskimailApiTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException as HttpClientTransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

final class HaskimailApiTransportTest extends TestCase
{
    private const TOKEN = 'server-token-123';

    /** @var list<array{method: string, url: string, headers: array<string, string>, payload: array<string, mixed>}> */
    private array $requests = [];

    #[DataProvider('provideToString')]
    public function testToString(HaskimailApiTransport $transport, string $expected): void
    {
        self::assertSame($expected, (string) $transport);
    }

    /**
     * @return iterable<string, array{HaskimailApiTransport, string}>
     */
    public static function provideToString(): iterable
    {
        yield 'default host' => [new HaskimailApiTransport(self::TOKEN), 'haskimail+api://api.haskimail.ru'];
        yield 'custom host' => [(new HaskimailApiTransport(self::TOKEN))->setHost('example.com'), 'haskimail+api://example.com'];
        yield 'custom host and port' => [(new HaskimailApiTransport(self::TOKEN))->setHost('example.com')->setPort(8443), 'haskimail+api://example.com:8443'];
        yield 'message stream' => [(new HaskimailApiTransport(self::TOKEN))->setMessageStream('2081'), 'haskimail+api://api.haskimail.ru?message_stream=2081'];
        yield 'message stream is url-encoded' => [(new HaskimailApiTransport(self::TOKEN))->setMessageStream('a&b=c'), 'haskimail+api://api.haskimail.ru?message_stream=a%26b%3Dc'];
    }

    public function testToStringNeverLeaksTheToken(): void
    {
        $transport = (new HaskimailApiTransport(self::TOKEN))->setMessageStream('2081');

        self::assertStringNotContainsString(self::TOKEN, (string) $transport);
    }

    public function testTokenIsRedactedFromHttpDebugOutput(): void
    {
        $debug = "> POST /email HTTP/1.1\r\nX-Haskimail-Server-Token: ".self::TOKEN."\r\n";
        $transport = $this->createTransport(new JsonMockResponse(['MessageID' => 'id', 'ErrorCode' => 0], ['debug' => $debug]));

        $sent = $transport->send((new Email())->from('a@example.com')->to('b@example.com')->text('Hi'));

        self::assertNotNull($sent);
        self::assertStringContainsString('X-Haskimail-Server-Token: [redacted]', $sent->getDebug());
        self::assertStringNotContainsString(self::TOKEN, $sent->getDebug());
    }

    public function testTokenIsRedactedFromExceptionDebugOutput(): void
    {
        $debug = 'X-Haskimail-Server-Token: '.self::TOKEN;
        $transport = $this->createTransport(new JsonMockResponse(['ErrorCode' => 6, 'Message' => 'Bad'], ['http_code' => 422, 'debug' => $debug]));

        try {
            $transport->send((new Email())->from('a@example.com')->to('b@example.com')->text('Hi'));
            self::fail('An exception should have been thrown.');
        } catch (HaskimailApiException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getDebug());
            self::assertStringNotContainsString(self::TOKEN, (string) json_encode($e->getResponse()->getInfo()));
            self::assertSame(422, $e->getResponse()->getStatusCode());
            self::assertSame(['ErrorCode' => 6, 'Message' => 'Bad'], $e->getResponse()->toArray(false));
        }
    }

    public function testTokenIsHiddenFromDumpsAndSerialization(): void
    {
        $transport = new HaskimailApiTransport(self::TOKEN);

        self::assertStringNotContainsString(self::TOKEN, print_r($transport, true));
        self::assertSame(['endpoint' => 'api.haskimail.ru', 'messageStream' => null, 'serverToken' => '[redacted]'], $transport->__debugInfo());

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot serialize Haskimail\SymfonyMailer\Transport\HaskimailApiTransport: it holds a secret token.');
        serialize($transport);
    }

    public function testUnserializationIsRefused(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Cannot unserialize Haskimail\SymfonyMailer\Transport\HaskimailApiTransport.');

        unserialize('O:'.\strlen(HaskimailApiTransport::class).':"'.HaskimailApiTransport::class.'":0:{}');
    }

    public function testEmptyTokenIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HaskimailApiTransport('  ');
    }

    public function testSetMessageStreamNormalizesBlankValues(): void
    {
        $transport = new HaskimailApiTransport(self::TOKEN);

        self::assertNull($transport->setMessageStream('  ')->getMessageStream());
        self::assertSame('2081', $transport->setMessageStream(' 2081 ')->getMessageStream());
        self::assertNull($transport->setMessageStream(null)->getMessageStream());
    }

    public function testSendsAFullyFeaturedEmail(): void
    {
        $transport = $this->createTransport()->setMessageStream('2081');

        $email = (new Email())
            ->from(new Address('shop@example.com', 'Магазин'))
            ->to(new Address('ivan@example.com', 'Иван'), 'anna@example.com')
            ->cc('cc@example.com')
            ->bcc('bcc@example.com')
            ->replyTo('support@example.com')
            ->subject('Ваш заказ №1042 оформлен')
            ->text('Здравствуйте! Заказ №1042 оформлен.')
            ->html('<p>Здравствуйте! Заказ №1042 оформлен.</p>');
        $email->getHeaders()
            ->add(new TagHeader('orders'))
            ->add(new MetadataHeader('order_id', '1042'))
            ->add(new MetadataHeader('customer', '77'))
            ->add(new TrackOpensHeader(true))
            ->add(new TrackLinksHeader(false))
            ->addTextHeader('X-Order-Id', '1042');

        $sent = $transport->send($email);

        self::assertNotNull($sent);
        self::assertSame('6f1c0b2d9e8a4f7b3c5d2e1a0b9c8d7e', $sent->getMessageId());

        self::assertCount(1, $this->requests);
        $request = $this->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://api.haskimail.ru/email', $request['url']);
        self::assertSame(self::TOKEN, $request['headers']['x-haskimail-server-token']);
        self::assertSame('application/json', $request['headers']['accept']);
        self::assertStringStartsWith('application/json', $request['headers']['content-type']);

        self::assertSame([
            'From' => 'Магазин <shop@example.com>',
            'To' => 'Иван <ivan@example.com>, anna@example.com',
            'Cc' => 'cc@example.com',
            'Bcc' => 'bcc@example.com',
            'ReplyTo' => 'support@example.com',
            'Tag' => 'orders',
            'TrackOpens' => true,
            'TrackLinks' => false,
            'MessageStream' => '2081',
            'Subject' => 'Ваш заказ №1042 оформлен',
            'TextBody' => 'Здравствуйте! Заказ №1042 оформлен.',
            'HtmlBody' => '<p>Здравствуйте! Заказ №1042 оформлен.</p>',
            'Metadata' => ['order_id' => '1042', 'customer' => '77'],
            'Headers' => [['Name' => 'X-Order-Id', 'Value' => '1042']],
        ], $request['payload']);
    }

    public function testMinimalPayloadOmitsEmptyFields(): void
    {
        $this->createTransport()->send((new Email())->from('a@example.com')->to('b@example.com')->text('Hi'));

        self::assertSame([
            'From' => 'a@example.com',
            'To' => 'b@example.com',
            'TextBody' => 'Hi',
        ], $this->requests[0]['payload']);
    }

    public function testFromHeaderIsUsedAndEnvelopeDecidesRecipients(): void
    {
        $email = (new Email())->from('header@example.com')->sender('agent@example.com')->to('header-to@example.com')->text('Hi');
        $email->getHeaders()->addPathHeader('Return-Path', 'bounces@example.com');
        $envelope = new Envelope(new Address('envelope@example.com'), [new Address('real@example.com')]);

        $this->createTransport()->send($email, $envelope);

        self::assertSame('header@example.com', $this->requests[0]['payload']['From']);
        self::assertSame('real@example.com', $this->requests[0]['payload']['To']);
    }

    public function testEnvelopeOverrideNeverLeaksToCcOrBcc(): void
    {
        // e.g. framework.mailer.envelope.recipients: ['dev@example.com'] in development
        $email = (new Email())->from('a@example.com')->to('customer@example.com')->cc('cc@customer.com', 'dev@example.com')->bcc('bcc@customer.com')->text('Hi');

        $this->createTransport()->send($email, new Envelope(new Address('a@example.com'), [new Address('DEV@example.com')]));

        $payload = $this->requests[0]['payload'];
        self::assertSame('', $payload['To']);
        self::assertSame('dev@example.com', $payload['Cc']);
        self::assertArrayNotHasKey('Bcc', $payload);
        self::assertStringNotContainsString('customer.com', json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    public function testMultipleFromAddressesAreRefused(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('single "From" address');

        $this->createTransport()->send((new Email())->from('a@example.com', 'b@example.com')->sender('a@example.com')->to('c@example.com')->text('Hi'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInjectedValues(): iterable
    {
        yield 'CRLF' => ["abc\r\nBcc: evil@example.com"];
        yield 'LF' => ["abc\nBcc: evil@example.com"];
        yield 'NUL' => ["abc\0"];
    }

    #[DataProvider('provideInjectedValues')]
    public function testHeaderInjectionIsRefused(string $value): void
    {
        $email = (new Email())->from('a@example.com')->to('b@example.com')->text('Hi');
        $email->getHeaders()->addTextHeader('X-Ref', $value);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('must not contain line breaks');

        $this->createTransport()->send($email);
    }

    public function testMessageWithoutFromFallsBackToTheEnvelopeSender(): void
    {
        $email = (new Email())->to('b@example.com')->text('Hi');
        $email->getHeaders()->addMailboxHeader('Sender', 'sender@example.com');

        $this->createTransport()->send($email);

        self::assertSame('sender@example.com', $this->requests[0]['payload']['From']);
    }

    public function testCcAndBccAreNotDuplicatedInTo(): void
    {
        $email = (new Email())->from('a@example.com')->to('to@example.com')->cc('cc@example.com')->bcc('bcc@example.com')->text('Hi');

        $this->createTransport()->send($email);

        self::assertSame('to@example.com', $this->requests[0]['payload']['To']);
    }

    #[DataProvider('provideDisplayNames')]
    public function testDisplayNamesAreQuotedWhenRequired(string $name, string $expected): void
    {
        $this->createTransport()->send((new Email())->from(new Address('shop@example.com', $name))->to('b@example.com')->text('Hi'));

        self::assertSame($expected, $this->requests[0]['payload']['From']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideDisplayNames(): iterable
    {
        yield 'plain' => ['Shop', 'Shop <shop@example.com>'];
        yield 'unicode is kept raw' => ['Магазин Хаски', 'Магазин Хаски <shop@example.com>'];
        yield 'comma is quoted' => ['Doe, John', '"Doe, John" <shop@example.com>'];
        yield 'quotes are escaped' => ['The "Best" Shop', '"The \"Best\" Shop" <shop@example.com>'];
        yield 'backslash is escaped' => ['A\B', '"A\\\\B" <shop@example.com>'];
        yield 'angle brackets are quoted' => ['evil <x@y.z>', '"evil <x@y.z>" <shop@example.com>'];
        yield 'control characters are removed' => ["Shop\tName", 'Shop Name <shop@example.com>'];
    }

    public function testInternationalizedDomainIsPunycoded(): void
    {
        $this->createTransport()->send((new Email())->from('info@магазин.рф')->to('b@example.com')->text('Hi'));

        self::assertSame('info@xn--80aairftm.xn--p1ai', $this->requests[0]['payload']['From']);
    }

    public function testMessageStreamHeaderOverridesTransportDefault(): void
    {
        $email = (new Email())->from('a@example.com')->to('b@example.com')->text('Hi');
        $email->getHeaders()->add(new MessageStreamHeader('broadcast-1'));

        $this->createTransport()->setMessageStream('2081')->send($email);

        self::assertSame('broadcast-1', $this->requests[0]['payload']['MessageStream']);
        self::assertArrayNotHasKey('Headers', $this->requests[0]['payload']);
    }

    public function testPlainTextHaskimailHeadersAreSupported(): void
    {
        $email = (new Email())->from('a@example.com')->to('b@example.com')->text('Hi');
        $email->getHeaders()
            ->addTextHeader('X-Haskimail-Message-Stream', '777')
            ->addTextHeader('X-Haskimail-Track-Opens', 'false')
            ->addTextHeader('X-Haskimail-Track-Links', '1');

        $this->createTransport()->send($email);

        $payload = $this->requests[0]['payload'];
        self::assertSame('777', $payload['MessageStream']);
        self::assertFalse($payload['TrackOpens']);
        self::assertTrue($payload['TrackLinks']);
        self::assertArrayNotHasKey('Headers', $payload);
    }

    public function testInvalidTrackingHeaderValueIsRejected(): void
    {
        $email = (new Email())->from('a@example.com')->to('b@example.com')->text('Hi');
        $email->getHeaders()->addTextHeader('X-Haskimail-Track-Opens', 'maybe');

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('must be "true" or "false"');

        $this->createTransport()->send($email);
    }

    public function testEmptyMessageStreamHeaderIsRejected(): void
    {
        $email = (new Email())->from('a@example.com')->to('b@example.com')->text('Hi');
        $email->getHeaders()->addTextHeader('X-Haskimail-Message-Stream', ' ');

        $this->expectException(TransportException::class);

        $this->createTransport()->send($email);
    }

    public function testOnlyOneTagIsAllowed(): void
    {
        $email = (new Email())->from('a@example.com')->to('b@example.com')->text('Hi');
        $email->getHeaders()->add(new TagHeader('one'))->add(new TagHeader('two'));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('single tag');

        $this->createTransport()->send($email);
    }

    public function testMetadataWithNumericKeysIsSentAsAnObject(): void
    {
        $email = (new Email())->from('a@example.com')->to('b@example.com')->text('Hi');
        $email->getHeaders()->add(new MetadataHeader('0', 'zero'));

        $this->createTransport()->send($email);

        self::assertStringContainsString('"Metadata":{"0":"zero"}', $this->lastRawBody);
    }

    public function testInternalHeadersAreNotForwarded(): void
    {
        $email = (new Email())->from('a@example.com')->sender('sender@example.com')->to('b@example.com')->text('Hi')->date(new \DateTimeImmutable());
        $email->getHeaders()->addIdHeader('Message-ID', 'my-id-123@example.com');

        $this->createTransport()->send($email);

        self::assertSame([['Name' => 'Message-ID', 'Value' => '<my-id-123@example.com>']], $this->requests[0]['payload']['Headers']);
    }

    public function testAttachmentsAndInlineImages(): void
    {
        $email = (new Email())
            ->from('a@example.com')
            ->to('b@example.com')
            ->html('<p><img src="cid:logo"></p><table background="cid:bg.png"></table>')
            ->attach('%PDF-1.4 binary', 'invoice.pdf', 'application/pdf')
            ->embed('PNGDATA', 'logo', 'image/png')
            ->embed('BGDATA', 'bg.png', 'image/png')
            ->addPart((new DataPart('INLINE', 'unreferenced.gif', 'image/gif'))->asInline()->setContentId('fixed@example.com'));

        $this->createTransport()->send($email);

        self::assertSame([
            ['Name' => 'invoice.pdf', 'Content' => base64_encode('%PDF-1.4 binary'), 'ContentType' => 'application/pdf'],
            ['Name' => 'logo', 'Content' => base64_encode('PNGDATA'), 'ContentType' => 'image/png', 'ContentID' => 'cid:logo'],
            ['Name' => 'bg.png', 'Content' => base64_encode('BGDATA'), 'ContentType' => 'image/png', 'ContentID' => 'cid:bg.png'],
            ['Name' => 'unreferenced.gif', 'Content' => base64_encode('INLINE'), 'ContentType' => 'image/gif', 'ContentID' => 'cid:fixed@example.com'],
        ], $this->requests[0]['payload']['Attachments']);
    }

    public function testUnquotedInlineReference(): void
    {
        $email = (new Email())->from('a@example.com')->to('b@example.com')
            ->html('<img src=cid:logo alt="">')
            ->embed('PNG', 'logo', 'image/png');

        $this->createTransport()->send($email);

        self::assertSame('cid:logo', $this->attachment(0)['ContentID'] ?? null);
    }

    public function testAttachmentNamesFallBack(): void
    {
        $email = (new Email())->from('a@example.com')->to('b@example.com')->text('x')
            ->addPart(new DataPart('data'))
            ->addPart((new DataPart('img', null, 'image/png'))->asInline()->setContentId('only-cid@example.com'));

        $this->createTransport()->send($email);

        self::assertSame('attachment', $this->attachment(0)['Name'] ?? null);
        self::assertSame('application/octet-stream', $this->attachment(0)['ContentType'] ?? null);
        self::assertArrayNotHasKey('ContentID', $this->attachment(0));
        self::assertSame('only-cid@example.com', $this->attachment(1)['Name'] ?? null);
    }

    public function testInlineImageReferencedByExplicitContentId(): void
    {
        $part = (new DataPart('PNG', 'logo.png', 'image/png'))->setContentId('logo123@example.com');
        $email = (new Email())->from('a@example.com')->to('b@example.com')
            ->html('<img src="cid:logo123@example.com">')
            ->addPart($part);

        $this->createTransport()->send($email);

        self::assertSame('cid:logo123@example.com', $this->attachment(0)['ContentID'] ?? null);
    }

    public function testInlineReferencesStayCorrectAfterTheMessageWasRendered(): void
    {
        $email = (new Email())->from('a@example.com')->to('b@example.com')->html('<img src="cid:logo">')->embed('PNG', 'logo', 'image/png');
        // Rendering (e.g. by a logger or the profiler) assigns a random Content-ID to the part.
        $email->toString();

        $this->createTransport()->send($email);

        self::assertSame('cid:logo', $this->attachment(0)['ContentID'] ?? null);
        self::assertSame('<img src="cid:logo">', $this->requests[0]['payload']['HtmlBody']);
    }

    public function testNonRewindableHtmlStreamIsReadOnce(): void
    {
        $html = popen('printf \'<img src="cid:logo">\'', 'r');
        self::assertIsResource($html);

        try {
            $this->createTransport()->send((new Email())->from('a@example.com')->to('b@example.com')->html($html)->embed('PNG', 'logo', 'image/png'));
        } finally {
            pclose($html);
        }

        self::assertSame('<img src="cid:logo">', $this->requests[0]['payload']['HtmlBody']);
        self::assertSame('cid:logo', $this->attachment(0)['ContentID'] ?? null);
    }

    public function testNonAsciiValuesAreSentRaw(): void
    {
        $email = (new Email())->from('a@example.com')->to('b@example.com')->text('x');
        $email->getHeaders()
            ->addTextHeader('X-City', 'Москва')
            ->addTextHeader(TemplateHeader::NAME, 'приветствие')
            ->addTextHeader(TemplateModelHeader::NAME, '{"{{имя}}":"Иван"}')
            ->addTextHeader(MessageStreamHeader::NAME, 'поток');

        $this->createTransport()->send($email);

        $payload = $this->requests[0]['payload'];
        self::assertSame([['Name' => 'X-City', 'Value' => 'Москва']], $payload['Headers']);
        self::assertSame('приветствие', $payload['TemplateAlias']);
        self::assertSame(['{{имя}}' => 'Иван'], $payload['TemplateModel']);
        self::assertSame('поток', $payload['MessageStream']);
    }

    public function testFileAttachmentIsRead(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hm');
        self::assertIsString($path);
        file_put_contents($path, 'file-content');

        try {
            $this->createTransport()->send((new Email())->from('a@example.com')->to('b@example.com')->text('Hi')->attachFromPath($path, 'report.txt', 'text/plain'));
        } finally {
            unlink($path);
        }

        self::assertSame(base64_encode('file-content'), $this->attachment(0)['Content'] ?? null);
    }

    public function testStreamBodiesAreRead(): void
    {
        $text = fopen('php://memory', 'r+');
        self::assertIsResource($text);
        fwrite($text, 'Streamed text');

        $this->createTransport()->send((new Email())->from('a@example.com')->to('b@example.com')->text($text));

        self::assertSame('Streamed text', $this->requests[0]['payload']['TextBody']);
    }

    public function testNonUtf8BodiesAreConverted(): void
    {
        $latin1 = mb_convert_encoding('Привет', 'Windows-1251', 'UTF-8');

        $this->createTransport()->send((new Email())->from('a@example.com')->to('b@example.com')->text($latin1, 'windows-1251'));

        self::assertSame('Привет', $this->requests[0]['payload']['TextBody']);
    }

    public function testUnknownCharsetIsReported(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Unable to convert the email body from "x-unknown" to UTF-8.');

        $this->createTransport()->send((new Email())->from('a@example.com')->to('b@example.com')->text('Hi', 'x-unknown'));
    }

    public function testUtf8AliasIsNotConverted(): void
    {
        $this->createTransport()->send((new Email())->from('a@example.com')->to('b@example.com')->text('Привет', 'UTF8'));

        self::assertSame('Привет', $this->requests[0]['payload']['TextBody']);
    }

    public function testTemplateById(): void
    {
        $email = (new HaskimailTemplateEmail())
            ->from('shop@example.com')
            ->to('ivan@example.com')
            ->template(4521, ['name' => 'Иван', 'order' => 1042]);
        $email->getHeaders()->add(new TagHeader('orders'))->add(new MessageStreamHeader('2081'));

        $this->createTransport()->send($email);

        self::assertSame('https://api.haskimail.ru/email/withTemplate', $this->requests[0]['url']);
        self::assertSame([
            'From' => 'shop@example.com',
            'To' => 'ivan@example.com',
            'Tag' => 'orders',
            'MessageStream' => '2081',
            'TemplateId' => 4521,
            'TemplateModel' => ['{{name}}' => 'Иван', '{{order}}' => '1042'],
        ], $this->requests[0]['payload']);
    }

    public function testTemplateByAliasWithSubjectOverrideAndAttachment(): void
    {
        $email = (new HaskimailTemplateEmail())
            ->from('shop@example.com')
            ->to('ivan@example.com')
            ->subject('Custom subject')
            ->attach('data', 'file.txt', 'text/plain')
            ->templateWithRawModel('welcome', ['%NAME%' => 'Ivan']);

        $this->createTransport()->send($email);

        $payload = $this->requests[0]['payload'];
        self::assertSame('welcome', $payload['TemplateAlias']);
        self::assertSame(['%NAME%' => 'Ivan'], $payload['TemplateModel']);
        self::assertSame('Custom subject', $payload['Subject']);
        self::assertArrayNotHasKey('TextBody', $payload);
        self::assertArrayNotHasKey('HtmlBody', $payload);
        self::assertSame('file.txt', $this->attachment(0)['Name'] ?? null);
    }

    public function testNumericAliasStaysAnAlias(): void
    {
        $email = (new Email())->from('a@example.com')->to('b@example.com')->text('x');
        $email->getHeaders()->add(TemplateHeader::alias('2024'));

        $this->createTransport()->send($email);

        self::assertSame('2024', $this->requests[0]['payload']['TemplateAlias']);
        self::assertArrayNotHasKey('TemplateId', $this->requests[0]['payload']);
    }

    public function testTemplateWithEmptyModelOmitsTemplateModel(): void
    {
        $email = (new HaskimailTemplateEmail())->from('a@example.com')->to('b@example.com')->template(1);

        $this->createTransport()->send($email);

        self::assertArrayNotHasKey('TemplateModel', $this->requests[0]['payload']);
    }

    public function testTemplateHeadersOnAPlainEmail(): void
    {
        $email = (new Email())->from('a@example.com')->to('b@example.com')->text('ignored');
        $email->getHeaders()
            ->addTextHeader(TemplateHeader::NAME, '42')
            ->addTextHeader(TemplateModelHeader::NAME, '{"{{a}}":"b"}');

        $this->createTransport()->send($email);

        self::assertSame(42, $this->requests[0]['payload']['TemplateId']);
        self::assertSame(['{{a}}' => 'b'], $this->requests[0]['payload']['TemplateModel']);
        self::assertArrayNotHasKey('TextBody', $this->requests[0]['payload']);
    }

    #[DataProvider('providePlainTemplateValues')]
    public function testPlainTemplateHeaderDistinguishesIdsFromAliases(string $value, string $field, int|string $expected): void
    {
        $email = (new Email())->from('a@example.com')->to('b@example.com')->text('x');
        $email->getHeaders()->addTextHeader(TemplateHeader::NAME, $value);

        $this->createTransport()->send($email);

        self::assertSame($expected, $this->requests[0]['payload'][$field]);
    }

    /**
     * @return iterable<string, array{string, string, int|string}>
     */
    public static function providePlainTemplateValues(): iterable
    {
        yield 'digits' => ['123', 'TemplateId', 123];
        yield 'one' => ['1', 'TemplateId', 1];
        yield 'zero is an alias' => ['0', 'TemplateAlias', '0'];
        yield 'zeros are an alias' => ['000', 'TemplateAlias', '000'];
        yield 'name' => ['welcome-email', 'TemplateAlias', 'welcome-email'];
        yield 'negative is an alias' => ['-5', 'TemplateAlias', '-5'];
        yield 'non-canonical number is an alias' => ['0042', 'TemplateAlias', '0042'];
        yield 'overflowing number is an alias' => ['99999999999999999999999', 'TemplateAlias', '99999999999999999999999'];
    }

    #[DataProvider('provideInvalidPlainTemplateModels')]
    public function testInvalidPlainTemplateModelIsRejected(string $model): void
    {
        $email = (new Email())->from('a@example.com')->to('b@example.com')->text('x');
        $email->getHeaders()->addTextHeader(TemplateHeader::NAME, '1')->addTextHeader(TemplateModelHeader::NAME, $model);

        $this->expectException(TransportException::class);

        $this->createTransport()->send($email);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidPlainTemplateModels(): iterable
    {
        yield 'not json' => ['{oops'];
        yield 'scalar' => ['"text"'];
        yield 'nested value' => ['{"{{a}}":{"b":1}}'];
        yield 'list' => ['["a"]'];
    }

    public function testTemplateModelWithoutTemplateIsRejected(): void
    {
        $email = (new Email())->from('a@example.com')->to('b@example.com')->text('x');
        $email->getHeaders()->add(new TemplateModelHeader(['{{a}}' => 'b']));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('requires a "X-Haskimail-Template" header');

        $this->createTransport()->send($email);
    }

    public function testMultipleRecipientIdsAreKept(): void
    {
        $transport = $this->createTransport(new JsonMockResponse([
            'To' => 'a@example.com, b@example.com',
            'SubmittedAt' => '2026-10-07 14:30:12',
            'MessageID' => 'id-1,id-2',
            'ErrorCode' => 0,
            'Message' => 'OK',
        ]));

        $sent = $transport->send((new Email())->from('x@example.com')->to('a@example.com', 'b@example.com')->text('Hi'));

        self::assertSame('id-1,id-2', $sent?->getMessageId());
    }

    public function testApiErrorIsReported(): void
    {
        $transport = $this->createTransport(new JsonMockResponse(['ErrorCode' => 6, 'Message' => 'Некорректный email-адрес получателя'], ['http_code' => 422]));

        try {
            $transport->send((new Email())->from('a@example.com')->to('b@example.com')->text('Hi'));
            self::fail('An exception should have been thrown.');
        } catch (HaskimailApiException $e) {
            self::assertSame('Unable to send an email: Некорректный email-адрес получателя (code 6, HTTP 422).', $e->getMessage());
            self::assertSame(6, $e->getErrorCode());
            self::assertSame(6, $e->getCode());
            self::assertSame(422, $e->getStatusCode());
            self::assertSame('Некорректный email-адрес получателя', $e->getApiMessage());
            self::assertFalse($e->isRetryable());
            self::assertSame(422, $e->getResponse()->getStatusCode());
        }
    }

    public function testErrorCodeInSuccessfulResponseIsReported(): void
    {
        $transport = $this->createTransport(new JsonMockResponse(['ErrorCode' => '1226', 'Message' => "MessageStream doesn't match api key", 'MessageID' => null]));

        $this->expectException(HaskimailApiException::class);
        $this->expectExceptionCode(1226);

        $transport->send((new Email())->from('a@example.com')->to('b@example.com')->text('Hi'));
    }

    public function testUnauthorizedResponseWithoutJsonGivesAHint(): void
    {
        $transport = $this->createTransport(new MockResponse('Unauthorized', ['http_code' => 401]));

        try {
            $transport->send((new Email())->from('a@example.com')->to('b@example.com')->text('Hi'));
            self::fail('An exception should have been thrown.');
        } catch (HaskimailApiException $e) {
            self::assertSame('Unable to send an email: the Haskimail API returned an unexpected response (HTTP 401) - check the server token (X-Haskimail-Server-Token): Unauthorized', $e->getMessage());
            self::assertSame(0, $e->getErrorCode());
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    #[DataProvider('provideStatusHints')]
    public function testStatusHints(int $status, string $hint): void
    {
        $transport = $this->createTransport(new JsonMockResponse(['ErrorCode' => 12, 'Message' => 'Denied'], ['http_code' => $status]));

        $this->expectException(HaskimailApiException::class);
        $this->expectExceptionMessage($hint);

        $transport->send((new Email())->from('a@example.com')->to('b@example.com')->text('Hi'));
    }

    /**
     * @return iterable<int, array{int, string}>
     */
    public static function provideStatusHints(): iterable
    {
        yield [403, 'IP address is not allowed'];
        yield [413, 'larger than 50 MB'];
    }

    public function testLongInvalidBodiesAreTruncated(): void
    {
        $transport = $this->createTransport(new MockResponse(str_repeat('x', 5000), ['http_code' => 502]));

        try {
            $transport->send((new Email())->from('a@example.com')->to('b@example.com')->text('Hi'));
            self::fail('An exception should have been thrown.');
        } catch (HaskimailApiException $e) {
            self::assertStringEndsWith(str_repeat('x', 512).'...', $e->getMessage());
            self::assertTrue($e->isRetryable());
        }
    }

    public function testEmptyInvalidBody(): void
    {
        $transport = $this->createTransport(new MockResponse('', ['http_code' => 503]));

        $this->expectException(HaskimailApiException::class);
        $this->expectExceptionMessage('(empty body)');

        $transport->send((new Email())->from('a@example.com')->to('b@example.com')->text('Hi'));
    }

    public function testFloatErrorCodeIsAnError(): void
    {
        $transport = $this->createTransport(new JsonMockResponse(['MessageID' => 'abc', 'ErrorCode' => 6.0]));

        $this->expectException(HaskimailApiException::class);
        $this->expectExceptionCode(6);

        $transport->send((new Email())->from('a@example.com')->to('b@example.com')->text('Hi'));
    }

    public function testResponseWithoutErrorCodeIsASuccess(): void
    {
        $transport = $this->createTransport(new JsonMockResponse(['MessageID' => 'abc']));

        self::assertSame('abc', $transport->send((new Email())->from('a@example.com')->to('b@example.com')->text('Hi'))?->getMessageId());
    }

    public function testNon2xxStatusIsAnErrorEvenWithoutErrorCode(): void
    {
        $transport = $this->createTransport(new JsonMockResponse(['MessageID' => 'abc', 'ErrorCode' => 0], ['http_code' => 300]));

        $this->expectException(HaskimailApiException::class);
        $this->expectExceptionMessage('(code 0, HTTP 300)');

        $transport->send((new Email())->from('a@example.com')->to('b@example.com')->text('Hi'));
    }

    public function testWhitespaceOnlyInvalidBody(): void
    {
        $transport = $this->createTransport(new MockResponse("  \n\t ", ['http_code' => 502]));

        $this->expectException(HaskimailApiException::class);
        $this->expectExceptionMessage('(empty body)');

        $transport->send((new Email())->from('a@example.com')->to('b@example.com')->text('Hi'));
    }

    public function testMultibyteBodiesAreTruncatedByCharacters(): void
    {
        $transport = $this->createTransport(new MockResponse(' a'.str_repeat('я', 512), ['http_code' => 502]));

        try {
            $transport->send((new Email())->from('a@example.com')->to('b@example.com')->text('Hi'));
            self::fail('An exception should have been thrown.');
        } catch (HaskimailApiException $e) {
            self::assertStringEndsWith(': a'.str_repeat('я', 511).'...', $e->getMessage());
        }
    }

    public function testBodyOfExactlyTheLimitIsNotTruncated(): void
    {
        $transport = $this->createTransport(new MockResponse(str_repeat('я', 512), ['http_code' => 502]));

        try {
            $transport->send((new Email())->from('a@example.com')->to('b@example.com')->text('Hi'));
            self::fail('An exception should have been thrown.');
        } catch (HaskimailApiException $e) {
            self::assertStringEndsWith(': '.str_repeat('я', 512), $e->getMessage());
        }
    }

    public function testMissingMessageIdIsAnError(): void
    {
        $transport = $this->createTransport(new JsonMockResponse(['ErrorCode' => 0, 'Message' => 'OK']));

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('does not contain a "MessageID"');

        $transport->send((new Email())->from('a@example.com')->to('b@example.com')->text('Hi'));
    }

    public function testErrorWithoutMessage(): void
    {
        $transport = $this->createTransport(new JsonMockResponse(['ErrorCode' => 'n/a'], ['http_code' => 500]));

        try {
            $transport->send((new Email())->from('a@example.com')->to('b@example.com')->text('Hi'));
            self::fail('An exception should have been thrown.');
        } catch (HaskimailApiException $e) {
            self::assertSame('Unable to send an email: unknown error (code 0, HTTP 500).', $e->getMessage());
            self::assertTrue($e->isRetryable());
        }
    }

    public function testNetworkErrorIsWrapped(): void
    {
        $client = new MockHttpClient(static fn () => throw new HttpClientTransportException('Connection refused'));
        $transport = new HaskimailApiTransport(self::TOKEN, $client);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Could not reach the remote Haskimail server.');

        $transport->send((new Email())->from('a@example.com')->to('b@example.com')->text('Hi'));
    }

    public function testNetworkErrorBeforeHeadersIsWrapped(): void
    {
        $transport = $this->createTransport(new MockResponse('', ['error' => 'Could not resolve host']));

        try {
            $transport->send((new Email())->from('a@example.com')->to('b@example.com')->text('Hi'));
            self::fail('An exception should have been thrown.');
        } catch (HttpTransportException $e) {
            self::assertSame('Could not reach the remote Haskimail server.', $e->getMessage());
            self::assertSame(0, $e->getCode());
            self::assertInstanceOf(\Throwable::class, $e->getPrevious());
        }
    }

    public function testNetworkErrorWhileReadingTheBodyIsWrapped(): void
    {
        $transport = $this->createTransport(new MockResponse((static function (): \Generator {
            yield '{"ErrorCode":';
            throw new HttpClientTransportException('Connection reset');
        })()));

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('Could not reach the remote Haskimail server.');

        $transport->send((new Email())->from('a@example.com')->to('b@example.com')->text('Hi'));
    }

    public function testCustomHostAndPort(): void
    {
        $transport = $this->createTransport()->setHost('localhost')->setPort(8080);

        $transport->send((new Email())->from('a@example.com')->to('b@example.com')->text('Hi'));

        self::assertSame('https://localhost:8080/email', $this->requests[0]['url']);
    }

    public function testZeroPortIsIgnored(): void
    {
        $transport = $this->createTransport()->setPort(0);

        $transport->send((new Email())->from('a@example.com')->to('b@example.com')->text('Hi'));

        self::assertSame('https://api.haskimail.ru/email', $this->requests[0]['url']);
    }

    public function testTheOriginalEmailIsNotModified(): void
    {
        $email = (new Email())->from('a@example.com')->to('b@example.com')->text('Hi');
        $email->getHeaders()->add(new TagHeader('t'))->add(new MessageStreamHeader('1'));
        $before = $email->getHeaders()->toArray();

        $this->createTransport()->send($email);

        self::assertSame($before, $email->getHeaders()->toArray());
    }

    private string $lastRawBody = '';

    /**
     * @return array<string, mixed>
     */
    private function attachment(int $index): array
    {
        $attachments = $this->requests[0]['payload']['Attachments'] ?? null;
        self::assertIsArray($attachments);
        self::assertIsArray($attachments[$index] ?? null);

        /** @var array<string, mixed> */
        return $attachments[$index];
    }

    private function createTransport(?MockResponse $response = null): HaskimailApiTransport
    {
        $response ??= new JsonMockResponse([
            'To' => 'ivan@example.com',
            'SubmittedAt' => '2026-10-07 14:30:12',
            'MessageID' => '6f1c0b2d9e8a4f7b3c5d2e1a0b9c8d7e',
            'ErrorCode' => 0,
            'Message' => 'OK',
        ]);

        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $headers = [];
            self::assertIsArray($options['headers'] ?? null);
            foreach ($options['headers'] as $line) {
                self::assertIsString($line);
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower($name)] = trim($value);
            }

            self::assertIsString($options['body'] ?? null);
            $this->lastRawBody = $options['body'];
            $payload = json_decode($options['body'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);

            /** @var array<string, mixed> $payload */
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'payload' => $payload];

            return $response;
        });

        return new HaskimailApiTransport(self::TOKEN, $client);
    }
}
