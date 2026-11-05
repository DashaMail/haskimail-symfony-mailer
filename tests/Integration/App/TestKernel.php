<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Tests\Integration\App;

use Haskimail\SymfonyMailer\HaskimailMailerBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new HaskimailMailerBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/haskimail-symfony-mailer-tests/'.md5(self::VERSION).'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/haskimail-symfony-mailer-tests/'.md5(self::VERSION).'/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $loader->load(static function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test',
                'test' => true,
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => true],
                'router' => ['utf8' => true],
                'messenger' => ['enabled' => true],
                'remote-event' => ['enabled' => true],
                'webhook' => [
                    'enabled' => true,
                    'routing' => [
                        'haskimail' => [
                            'service' => 'haskimail.webhook.request_parser',
                            'secret' => 'webhook-signing-key',
                        ],
                    ],
                ],
                'mailer' => [
                    'dsn' => 'haskimail+api://SERVER_TOKEN@default?message_stream=2081',
                    'message_bus' => false,
                ],
                'http_client' => [
                    'mock_response_factory' => RecordingHttpClientFactory::class,
                ],
            ]);

            $container->register('logger', NullLogger::class);
            $container->register(RecordingHttpClientFactory::class)->setPublic(true);
            $container->setAlias('test.mailer', 'mailer.mailer')->setPublic(true);
            $container->register('test.haskimail_transport', TransportInterface::class)
                ->setFactory([new Reference('mailer.transport_factory'), 'fromString'])
                ->setArguments(['haskimail://SERVER_TOKEN@default?message_stream=2081'])
                ->setPublic(true);
            $container->register(RecordingConsumer::class)
                ->setPublic(true)
                ->addTag('remote_event.consumer', ['consumer' => 'haskimail']);
        });
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('_webhook_controller', '/webhook/{type}')
            ->controller('webhook.controller::handle')
            ->requirements(['type' => '.+']);
    }
}
