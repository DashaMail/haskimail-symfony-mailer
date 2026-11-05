<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Tests\Transport;

use Haskimail\SymfonyMailer\Transport\HaskimailSmtpTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;

/**
 * Talks to a real (fake) SMTP server that does not offer STARTTLS, like a downgrade attack would.
 */
final class HaskimailSmtpTransportConnectionTest extends TestCase
{
    /** @var resource|null */
    private $process;

    private string $log = '';

    private ?string $certificate = null;

    protected function tearDown(): void
    {
        if (\is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        foreach ([$this->log, $this->certificate] as $file) {
            if (null !== $file && '' !== $file && is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testSendsOverStartTls(): void
    {
        $transport = new HaskimailSmtpTransport('login', 'secret-password', '127.0.0.1', $this->startServer(self::certificate()));
        $stream = $transport->getStream();
        self::assertInstanceOf(SocketStream::class, $stream);
        $stream->setStreamOptions(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);

        $email = self::email();
        $email->getHeaders()->add(new TagHeader('orders'))->add(new MetadataHeader('order_id', '1042'));

        $sent = $transport->send($email);
        // An explicit start() on the open connection (warm-up, health checks) must keep the TLS state.
        $transport->start();
        $second = $transport->send(self::email());
        $transport->stop();

        self::assertNotNull($sent);
        self::assertNotNull($second);
        self::assertSame(2, substr_count($this->commands(), 'MAIL FROM'));
        $commands = $this->commands();
        self::assertMatchesRegularExpression('/STARTTLS.*AUTH PLAIN.*MAIL FROM:<a@example.com>.*RCPT TO:<b@example.com>.*DATA/s', $commands);
        self::assertStringContainsString("X-HM-Tag: orders\r\n", $commands);
        self::assertStringContainsString("X-HM-Metadata: {\"order_id\":\"1042\"}\r\n", $commands);
        self::assertStringNotContainsString('X-Tag:', $commands);
    }

    public function testCommandsAreGuardedCaseInsensitively(): void
    {
        $transport = new HaskimailSmtpTransport('login', 'secret');

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('clear text');

        $transport->executeCommand("mail FROM:<a@example.com>\r\n", [250]);
    }

    public function testNeverAuthenticatesOrSendsWithoutTls(): void
    {
        $transport = new HaskimailSmtpTransport('login', 'secret-password', '127.0.0.1', $this->startServer());

        try {
            $transport->send(self::email());
            self::fail('Sending without TLS must fail.');
        } catch (TransportException $e) {
            self::assertMatchesRegularExpression('/TLS/', $e->getMessage());
        }

        $commands = $this->commands();
        self::assertStringContainsString('EHLO', $commands);
        self::assertStringNotContainsString('AUTH', $commands);
        self::assertStringNotContainsString('MAIL FROM', $commands);
    }

    public function testOwnGuardWorksWithoutNativeTlsEnforcement(): void
    {
        // Simulates symfony/mailer < 7.3, where EsmtpTransport::setRequireTls() does not exist.
        $transport = new HaskimailSmtpTransport('login', 'secret-password', '127.0.0.1', $this->startServer());
        if (method_exists($transport, 'setRequireTls')) { // @phpstan-ignore function.alreadyNarrowedType
            $transport->setRequireTls(false);
        }

        try {
            $transport->send(self::email());
            self::fail('Sending without TLS must fail.');
        } catch (TransportException $e) {
            self::assertStringContainsString('refusing to send credentials or messages in clear text', $e->getMessage());
            self::assertStringNotContainsString('secret-password', $e->getMessage());
        }

        self::assertStringNotContainsString('AUTH', $this->commands());
    }

    public function testHeadersAddedByListenersAreConverted(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(MessageEvent::class, static function (MessageEvent $event): void {
            $message = $event->getMessage();
            self::assertInstanceOf(Message::class, $message);
            $message->getHeaders()->add(new TagHeader('from-listener'))->add(new MetadataHeader('k', 'v'));
        });

        $failed = null;
        $dispatcher->addListener(FailedMessageEvent::class, static function (FailedMessageEvent $event) use (&$failed): void {
            $failed = $event->getMessage();
        });

        $email = self::email();
        $before = $email->getHeaders()->toArray();
        $transport = new HaskimailSmtpTransport('login', 'secret', '127.0.0.1', $this->startServer(), $dispatcher);

        try {
            $transport->send($email);
            self::fail('Sending without TLS must fail.');
        } catch (TransportException) {
        }

        self::assertInstanceOf(Message::class, $failed);
        self::assertSame('from-listener', $failed->getHeaders()->getHeaderBody('X-HM-Tag'));
        self::assertSame('{"k":"v"}', $failed->getHeaders()->getHeaderBody('X-HM-Metadata'));
        self::assertFalse($failed->getHeaders()->has('X-Tag'));
        self::assertSame($before, $email->getHeaders()->toArray(), 'The caller\'s message must not be modified.');
    }

    private static function email(): Email
    {
        return (new Email())->from('a@example.com')->to('b@example.com')->subject('Hi')->text('Hello');
    }

    private static function certificate(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        $csrKey = $key; // passed by reference
        $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $csrKey);
        self::assertNotFalse($csr);
        self::assertNotTrue($csr);
        $certificate = openssl_csr_sign($csr, null, $key, 1);
        self::assertNotFalse($certificate);

        self::assertTrue(openssl_x509_export($certificate, $pem));
        self::assertTrue(openssl_pkey_export($key, $privateKey));
        self::assertIsString($pem);
        self::assertIsString($privateKey);

        $path = tempnam(sys_get_temp_dir(), 'hm-cert');
        self::assertIsString($path);
        file_put_contents($path, $pem.$privateKey);

        return $path;
    }

    private function startServer(?string $certificate = null): int
    {
        $log = tempnam(sys_get_temp_dir(), 'hm-smtp');
        self::assertIsString($log);
        $this->log = $log;

        $this->certificate = $certificate;
        $command = [\PHP_BINARY, __DIR__.'/../Fixtures/smtp/fake-smtp-server.php', $this->log];
        if (null !== $certificate) {
            $command[] = $certificate;
        }

        $process = proc_open($command, [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $this->process = $process;

        $port = fgets($pipes[1]);
        self::assertIsString($port);

        return (int) trim($port);
    }

    private function commands(): string
    {
        usleep(50_000);

        return (string) file_get_contents($this->log);
    }
}
