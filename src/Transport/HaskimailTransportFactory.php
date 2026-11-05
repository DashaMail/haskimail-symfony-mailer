<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Transport;

use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Creates Haskimail transports from DSNs:
 *
 *  - haskimail://SERVER_TOKEN@default                  HTTP API (recommended)
 *  - haskimail+api://SERVER_TOKEN@default?message_stream=ID
 *  - haskimail+smtp://LOGIN:PASSWORD@default[:587]     SMTP relay (credentials of one channel)
 */
final class HaskimailTransportFactory extends AbstractTransportFactory
{
    private const SCHEME_DEFAULT = 'haskimail';
    private const SCHEME_API = 'haskimail+api';
    private const SCHEME_SMTP = 'haskimail+smtp';

    public function create(Dsn $dsn): TransportInterface
    {
        $scheme = $dsn->getScheme();
        $host = 'default' === $dsn->getHost() ? null : $dsn->getHost();

        if (self::SCHEME_API === $scheme || self::SCHEME_DEFAULT === $scheme) {
            return (new HaskimailApiTransport($this->getUser($dsn), $this->client, $this->dispatcher, $this->logger))
                ->setHost($host)
                ->setPort($dsn->getPort())
                ->setMessageStream(self::getStringOption($dsn, 'message_stream'));
        }

        if (self::SCHEME_SMTP === $scheme) {
            if (null !== $dsn->getOption('message_stream')) {
                throw new InvalidArgumentException('The "message_stream" option is not supported by "haskimail+smtp": SMTP credentials are already bound to a channel.');
            }

            return new HaskimailSmtpTransport(
                $this->getUser($dsn),
                $this->getPassword($dsn),
                $host,
                $dsn->getPort() ?? HaskimailSmtpTransport::DEFAULT_PORT,
                $this->dispatcher,
                $this->logger,
            );
        }

        throw new UnsupportedSchemeException($dsn, 'haskimail', $this->getSupportedSchemes());
    }

    /**
     * @return list<string>
     */
    protected function getSupportedSchemes(): array
    {
        return [self::SCHEME_DEFAULT, self::SCHEME_API, self::SCHEME_SMTP];
    }

    private static function getStringOption(Dsn $dsn, string $name): ?string
    {
        $value = $dsn->getOption($name);

        if (null === $value || \is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException(\sprintf('The "%s" option of the Haskimail DSN must be a string.', $name));
    }
}
