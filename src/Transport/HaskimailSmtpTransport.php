<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Transport;

use Haskimail\SymfonyMailer\Header\HaskimailDomainHeader;
use Haskimail\SymfonyMailer\Header\HaskimailMessageIdHeader;
use Haskimail\SymfonyMailer\Header\MessageStreamHeader;
use Haskimail\SymfonyMailer\Header\TemplateHeader;
use Haskimail\SymfonyMailer\Header\TemplateModelHeader;
use Haskimail\SymfonyMailer\Header\TrackLinksHeader;
use Haskimail\SymfonyMailer\Header\TrackOpensHeader;
use Haskimail\SymfonyMailer\Internal\HeaderValue;
use Haskimail\SymfonyMailer\Internal\SmtpHeadersDispatcher;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Header\Headers;

/**
 * Sends emails through the Haskimail SMTP relay (smtp.haskimail.ru, STARTTLS).
 *
 * SMTP credentials are issued per channel (MessageStream): open the channel in the
 * Haskimail dashboard and copy the login and password from its "Settings" tab.
 *
 * @see https://haskimail.ru/developer/user-guide/send-email-with-smtp
 */
final class HaskimailSmtpTransport extends EsmtpTransport
{
    public const DEFAULT_HOST = 'smtp.haskimail.ru';
    public const DEFAULT_PORT = 587;

    public const TAG_HEADER = 'X-HM-Tag';
    public const METADATA_HEADER = 'X-HM-Metadata';
    public const NO_TRACK_OPENS_HEADER = 'X-HM-No-Track-Opens';
    public const NO_TRACK_CLICKS_HEADER = 'X-HM-No-Track-Click';
    public const TRACK_OPENS_HEADER = 'X-HM-TrackOpens';
    public const DOMAIN_HEADER = 'X-HM-Domain';
    public const MESSAGE_ID_HEADER = 'X-HM-MessageID';

    private const COMMANDS_REQUIRING_TLS = ['AUTH', 'MAIL', 'RCPT', 'DATA'];

    private bool $tlsStarted = false;

    public function __construct(
        #[\SensitiveParameter]
        string $username,
        #[\SensitiveParameter]
        string $password,
        ?string $host = null,
        int $port = self::DEFAULT_PORT,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        if ('' === $username || '' === $password) {
            throw new \InvalidArgumentException('The Haskimail SMTP login and password must not be empty.');
        }

        // Port 465 is not offered; on every supported port the connection is upgraded with STARTTLS.
        // The wrapped dispatcher converts the headers after all MessageEvent listeners have run.
        parent::__construct($host ?? self::DEFAULT_HOST, $port, false, new SmtpHeadersDispatcher($dispatcher), $logger);

        $this->setUsername($username);
        $this->setPassword($password);

        // symfony/mailer >= 7.3 enforces TLS natively; executeCommand() below covers older versions.
        if (method_exists($this, 'setRequireTls')) { // @phpstan-ignore function.alreadyNarrowedType
            $this->setRequireTls(true);
        }
    }

    public function start(): void
    {
        try {
            parent::start();
        } catch (\Throwable $e) {
            // The stream of a failed connection is terminated: its TLS state must not leak into the next one.
            $this->tlsStarted = false;

            throw $e;
        }
    }

    public function stop(): void
    {
        parent::stop();

        $this->tlsStarted = false;
    }

    /**
     * Haskimail refuses unencrypted sessions: never send credentials or a message in clear text,
     * even if a man-in-the-middle strips the STARTTLS capability from the server greeting.
     */
    public function executeCommand(string $command, array $codes): string
    {
        $verb = strtoupper((string) strtok($command, " \r\n"));

        // Implicit TLS (port 465) is not offered by Haskimail, so STARTTLS is the only way to encrypt.
        if (!$this->tlsStarted && \in_array($verb, self::COMMANDS_REQUIRING_TLS, true)) {
            throw new TransportException('Haskimail requires an encrypted SMTP connection, but STARTTLS is not available: refusing to send credentials or messages in clear text.');
        }

        $response = parent::executeCommand($command, $codes);

        if ('STARTTLS' === $verb) {
            // EsmtpTransport enables TLS right after this reply or throws, before any other command is sent.
            $this->tlsStarted = true;
        }

        return $response;
    }

    /**
     * Converts Symfony and Haskimail headers to the X-HM-* headers understood by the SMTP relay.
     *
     * @internal
     */
    public static function convertHeaders(Headers $headers): void
    {
        $tag = null;
        $metadata = [];

        foreach (HeaderValue::all($headers) as $name => $header) {
            if ($header instanceof TagHeader) {
                if (null !== $tag) {
                    throw new TransportException('Haskimail only allows a single tag per email.');
                }
                $tag = $header->getValue();
                $headers->remove($name);
                continue;
            }

            if ($header instanceof MetadataHeader) {
                $metadata[$header->getKey()] = $header->getValue();
                $headers->remove($name);
                continue;
            }

            switch ($name) {
                case strtolower(MessageStreamHeader::NAME):
                    throw new TransportException('Haskimail SMTP credentials are bound to a single channel; use the credentials of the target channel or the API transport ("haskimail+api://") to choose a message stream per email.');
                case strtolower(TemplateHeader::NAME):
                case strtolower(TemplateModelHeader::NAME):
                    throw new TransportException('Haskimail templates can only be sent with the API transport ("haskimail+api://").');
                case strtolower(HaskimailDomainHeader::NAME):
                    $headers->remove($name);
                    $headers->addTextHeader(self::DOMAIN_HEADER, HeaderValue::raw($header));
                    break;
                case strtolower(HaskimailMessageIdHeader::NAME):
                    $headers->remove($name);
                    $headers->addTextHeader(self::MESSAGE_ID_HEADER, HeaderValue::raw($header));
                    break;
                case strtolower(TrackOpensHeader::NAME):
                    // The SMTP relay can only enable open tracking via X-HM-TrackOpens (true) or
                    // leave it off with X-HM-No-Track-Opens. Unlike the API, it cannot force open
                    // tracking on when the server setting is off: report that "true" may be ignored.
                    $headers->remove($name);
                    if (HeaderValue::toBool($header)) {
                        $headers->addTextHeader(self::TRACK_OPENS_HEADER, 'true');
                    } else {
                        $headers->addTextHeader(self::NO_TRACK_OPENS_HEADER, 'true');
                    }
                    break;
                case strtolower(TrackLinksHeader::NAME):
                    $headers->remove($name);
                    if (!HeaderValue::toBool($header)) {
                        $headers->addTextHeader(self::NO_TRACK_CLICKS_HEADER, 'true');
                    }
                    break;
            }
        }

        if (null !== $tag) {
            if ($headers->has(self::TAG_HEADER)) {
                throw new TransportException('Haskimail only allows a single tag per email.');
            }
            $headers->addTextHeader(self::TAG_HEADER, $tag);
        }

        if ([] !== $metadata) {
            if ($headers->has(self::METADATA_HEADER)) {
                throw new TransportException(\sprintf('Use either Symfony metadata headers or a raw "%s" header, not both.', self::METADATA_HEADER));
            }
            // Unicode is escaped on purpose: the header stays 7-bit ASCII and is never RFC 2047-encoded.
            $headers->addTextHeader(self::METADATA_HEADER, json_encode((object) $metadata, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        }
    }
}
