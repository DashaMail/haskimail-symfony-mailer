<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer;

use Haskimail\SymfonyMailer\RemoteEvent\HaskimailPayloadConverter;
use Haskimail\SymfonyMailer\Transport\HaskimailTransportFactory;
use Haskimail\SymfonyMailer\Webhook\HaskimailRequestParser;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Webhook\Client\AbstractRequestParser;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Registers the Haskimail transport factory (so "haskimail://" DSNs work in framework.mailer)
 * and, when symfony/webhook is installed, the "haskimail.webhook.request_parser" service.
 */
final class HaskimailMailerBundle extends AbstractBundle
{
    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $services->set('haskimail.transport_factory', HaskimailTransportFactory::class)
            ->args([
                service('event_dispatcher')->nullOnInvalid(),
                service('http_client')->nullOnInvalid(),
                service('logger')->nullOnInvalid(),
            ])
            ->tag('mailer.transport_factory')
            ->tag('monolog.logger', ['channel' => 'mailer']);

        if (!class_exists(AbstractRequestParser::class)) {
            return;
        }

        $services->set('haskimail.webhook.payload_converter', HaskimailPayloadConverter::class);
        $services->alias(HaskimailPayloadConverter::class, 'haskimail.webhook.payload_converter');

        $services->set('haskimail.webhook.request_parser', HaskimailRequestParser::class)
            ->args([service('haskimail.webhook.payload_converter')]);
        $services->alias(HaskimailRequestParser::class, 'haskimail.webhook.request_parser');
    }
}
