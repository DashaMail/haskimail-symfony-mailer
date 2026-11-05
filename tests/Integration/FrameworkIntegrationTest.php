<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Tests\Integration;

use Haskimail\SymfonyMailer\Tests\Integration\App\RecordingConsumer;
use Haskimail\SymfonyMailer\Tests\Integration\App\RecordingHttpClientFactory;
use Haskimail\SymfonyMailer\Tests\Integration\App\TestKernel;
use Haskimail\SymfonyMailer\Tests\Webhook\WebhookFixtures;
use Haskimail\SymfonyMailer\Transport\HaskimailApiTransport;
use Haskimail\SymfonyMailer\Webhook\HaskimailRequestParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerDeliveryEvent;

/**
 * Boots a real Symfony application with HaskimailMailerBundle and FrameworkBundle.
 */
final class FrameworkIntegrationTest extends TestCase
{
    private TestKernel $kernel;

    protected function setUp(): void
    {
        $this->kernel = new TestKernel('test', true);
        (new Filesystem())->remove($this->kernel->getCacheDir());
        $this->kernel->boot();
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
        (new Filesystem())->remove(\dirname($this->kernel->getCacheDir()));
        restore_exception_handler();
    }

    public function testTheTransportFactoryIsRegisteredWithTheMailer(): void
    {
        $transport = $this->kernel->getContainer()->get('test.haskimail_transport');

        self::assertInstanceOf(HaskimailApiTransport::class, $transport);
        self::assertSame('haskimail+api://api.haskimail.ru?message_stream=2081', (string) $transport);
    }

    public function testSendingThroughTheMailer(): void
    {
        $mailer = $this->service('test.mailer');
        self::assertInstanceOf(MailerInterface::class, $mailer);

        $mailer->send((new Email())->from('shop@example.com')->to('ivan@example.com')->subject('Hi')->text('Hello'));

        $factory = $this->service(RecordingHttpClientFactory::class);
        self::assertInstanceOf(RecordingHttpClientFactory::class, $factory);
        self::assertCount(1, $factory->requests);
        self::assertSame('https://api.haskimail.ru/email', $factory->requests[0]['url']);

        $body = $factory->requests[0]['options']['body'] ?? null;
        self::assertIsString($body);
        self::assertSame(
            ['From' => 'shop@example.com', 'To' => 'ivan@example.com', 'MessageStream' => '2081', 'Subject' => 'Hi', 'TextBody' => 'Hello'],
            json_decode($body, true, 512, \JSON_THROW_ON_ERROR),
        );
    }

    public function testWebhookServicesAreRegistered(): void
    {
        self::assertInstanceOf(HaskimailRequestParser::class, $this->service('haskimail.webhook.request_parser'));
        self::assertInstanceOf(HaskimailRequestParser::class, $this->service(HaskimailRequestParser::class));
    }

    public function testSignedWebhookReachesTheConsumer(): void
    {
        $response = $this->kernel->handle(self::webhookRequest(WebhookFixtures::load('bounced')));

        self::assertSame(202, $response->getStatusCode());

        $consumer = $this->service(RecordingConsumer::class);
        self::assertInstanceOf(RecordingConsumer::class, $consumer);
        self::assertCount(1, $consumer->events);
        self::assertInstanceOf(MailerDeliveryEvent::class, $consumer->events[0]);
        self::assertSame(MailerDeliveryEvent::BOUNCE, $consumer->events[0]->getName());
    }

    public function testForgedWebhookIsRejected(): void
    {
        $response = $this->kernel->handle(self::webhookRequest(WebhookFixtures::sign(WebhookFixtures::load('bounced', false), 'wrong-key')));

        self::assertSame(403, $response->getStatusCode());

        $consumer = $this->service(RecordingConsumer::class);
        self::assertInstanceOf(RecordingConsumer::class, $consumer);
        self::assertSame([], $consumer->events);
    }

    private function service(string $id): object
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        $service = $container->get($id);
        self::assertIsObject($service);

        return $service;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function webhookRequest(array $payload): Request
    {
        return Request::create('/webhook/haskimail', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload, \JSON_THROW_ON_ERROR));
    }
}
