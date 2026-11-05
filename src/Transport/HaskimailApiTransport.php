<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Transport;

use Haskimail\SymfonyMailer\Exception\HaskimailApiException;
use Haskimail\SymfonyMailer\Header\MessageStreamHeader;
use Haskimail\SymfonyMailer\Header\TemplateHeader;
use Haskimail\SymfonyMailer\Header\TemplateModelHeader;
use Haskimail\SymfonyMailer\Header\TrackLinksHeader;
use Haskimail\SymfonyMailer\Header\TrackOpensHeader;
use Haskimail\SymfonyMailer\Internal\HeaderValue;
use Haskimail\SymfonyMailer\Internal\RedactedResponse;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sends emails through the Haskimail HTTP API (POST /email and POST /email/withTemplate).
 *
 * @see https://haskimail.ru/developer/api/email-api
 */
final class HaskimailApiTransport extends AbstractApiTransport
{
    public const DEFAULT_HOST = 'api.haskimail.ru';

    /**
     * Token accepted by the send endpoints that validates requests without sending anything.
     *
     * @see https://haskimail.ru/developer/user-guide/sandbox-mode
     */
    public const TEST_TOKEN = 'HASKIMAIL_API_TEST';

    /** Headers that are mapped to dedicated payload fields and must not be duplicated in "Headers". */
    private const BYPASSED_HEADERS = [
        'from', 'to', 'cc', 'bcc', 'reply-to', 'sender', 'subject', 'date', 'return-path',
        'content-type', 'content-transfer-encoding', 'mime-version',
    ];

    private const MAX_ERROR_BODY_LENGTH = 512;

    private ?string $messageStream = null;

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $serverToken,
        ?HttpClientInterface $client = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        if ('' === trim($serverToken)) {
            throw new \InvalidArgumentException('The Haskimail server token must not be empty.');
        }

        parent::__construct($client, $dispatcher, $logger);
    }

    /**
     * Sets the default channel (MessageStream ID) used when a message has no {@see MessageStreamHeader}.
     *
     * @return $this
     */
    public function setMessageStream(?string $messageStream): static
    {
        $messageStream = null === $messageStream ? null : trim($messageStream);
        $this->messageStream = '' === $messageStream ? null : $messageStream;

        return $this;
    }

    public function getMessageStream(): ?string
    {
        return $this->messageStream;
    }

    public function __toString(): string
    {
        $dsn = \sprintf('haskimail+api://%s', $this->getEndpoint());

        if (null !== $this->messageStream) {
            $dsn .= '?message_stream='.rawurlencode($this->messageStream);
        }

        return $dsn;
    }

    /**
     * Keeps the server token out of var_dump()/print_r() output.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['endpoint' => $this->getEndpoint(), 'messageStream' => $this->messageStream, 'serverToken' => '[redacted]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \BadMethodCallException('Cannot serialize '.self::class.': it holds a secret token.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \BadMethodCallException('Cannot unserialize '.self::class.'.');
    }

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        $payload = $this->getPayload($email, $envelope);
        $path = isset($payload['TemplateId']) || isset($payload['TemplateAlias']) ? '/email/withTemplate' : '/email';

        $client = $this->client ?? throw new \LogicException('The HTTP client is not initialized.');

        try {
            // The wrapper strips the token from the HTTP debug log that Symfony attaches to
            // SentMessage::getDebug() and HttpTransportException::getDebug().
            $response = new RedactedResponse($client->request('POST', 'https://'.$this->getEndpoint().$path, [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Haskimail-Server-Token' => $this->serverToken,
                ],
                'json' => $payload,
            ]), $this->serverToken);
        } catch (TransportExceptionInterface $e) {
            throw new TransportException('Could not reach the remote Haskimail server.', 0, $e);
        }

        try {
            $statusCode = $response->getStatusCode();
            $result = $response->toArray(false);
        } catch (DecodingExceptionInterface $e) {
            $statusCode = $response->getStatusCode(); // already received: decoding happens after the headers

            throw new HaskimailApiException(\sprintf('Unable to send an email: the Haskimail API returned an unexpected response (HTTP %d)%s: %s', $statusCode, self::statusHint($statusCode), self::excerpt($response)), $response, 0, '', $statusCode, $e);
        } catch (TransportExceptionInterface $e) {
            throw new HttpTransportException('Could not reach the remote Haskimail server.', $response, 0, $e);
        }

        $errorCode = self::readErrorCode($result);
        $apiMessage = \is_string($result['Message'] ?? null) ? $result['Message'] : '';

        if ($statusCode < 200 || $statusCode >= 300 || 0 !== $errorCode) {
            throw new HaskimailApiException(\sprintf('Unable to send an email: %s (code %d, HTTP %d)%s.', '' !== $apiMessage ? $apiMessage : 'unknown error', $errorCode, $statusCode, self::statusHint($statusCode)), $response, $errorCode, $apiMessage, $statusCode);
        }

        $messageId = $result['MessageID'] ?? null;
        if (!\is_string($messageId) || '' === $messageId) {
            throw new HttpTransportException('Unable to send an email: the Haskimail API response does not contain a "MessageID".', $response);
        }

        // When a message has several recipients Haskimail returns one ID per recipient, comma-separated.
        $sentMessage->setMessageId($messageId);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function getPayload(Email $email, Envelope $envelope): array
    {
        // Only addresses that are part of the envelope may receive the message: when the envelope
        // recipients are overridden (e.g. framework.mailer.envelope.recipients in development),
        // Cc and Bcc recipients that are not in the envelope must not be contacted.
        $cc = self::onlyEnvelopeRecipients($email->getCc(), $envelope);
        $bcc = self::onlyEnvelopeRecipients($email->getBcc(), $envelope);

        if (\count($email->getFrom()) > 1) {
            throw new TransportException('Haskimail supports a single "From" address per email.');
        }

        $payload = [
            // The visible From header; the envelope sender (bounce address) is managed by Haskimail.
            'From' => self::formatAddress($email->getFrom()[0] ?? $envelope->getSender()),
            'To' => self::formatAddresses(self::excludeAddresses($envelope->getRecipients(), [...$cc, ...$bcc])),
        ];

        if ([] !== $cc) {
            $payload['Cc'] = self::formatAddresses($cc);
        }
        if ([] !== $bcc) {
            $payload['Bcc'] = self::formatAddresses($bcc);
        }
        if ([] !== $replyTo = $email->getReplyTo()) {
            $payload['ReplyTo'] = self::formatAddresses($replyTo);
        }

        $template = null;
        $templateModel = null;
        $metadata = [];
        $customHeaders = [];

        foreach (HeaderValue::all($email->getHeaders()) as $name => $header) {
            switch ($name) {
                case strtolower(MessageStreamHeader::NAME):
                    $payload['MessageStream'] = self::requireNonEmpty($header, 'message stream');
                    continue 2;
                case strtolower(TemplateHeader::NAME):
                    $template = $header;
                    continue 2;
                case strtolower(TemplateModelHeader::NAME):
                    $templateModel = $header;
                    continue 2;
                case strtolower(TrackOpensHeader::NAME):
                    $payload['TrackOpens'] = HeaderValue::toBool($header);
                    continue 2;
                case strtolower(TrackLinksHeader::NAME):
                    $payload['TrackLinks'] = HeaderValue::toBool($header);
                    continue 2;
            }

            if ($header instanceof TagHeader) {
                if (isset($payload['Tag'])) {
                    throw new TransportException('Haskimail only allows a single tag per email.');
                }
                $payload['Tag'] = $header->getValue();
                continue;
            }

            if ($header instanceof MetadataHeader) {
                $metadata[$header->getKey()] = $header->getValue();
                continue;
            }

            if (\in_array($name, self::BYPASSED_HEADERS, true)) {
                continue;
            }

            $customHeaders[] = ['Name' => $header->getName(), 'Value' => HeaderValue::raw($header)];
        }

        if (!isset($payload['MessageStream']) && null !== $this->messageStream) {
            $payload['MessageStream'] = $this->messageStream;
        }

        // Bodies may be non-rewindable streams: read each of them exactly once.
        $html = self::bodyToString($email->getHtmlBody(), $email->getHtmlCharset());

        if (null !== $template) {
            $payload += self::getTemplateFields($template, $templateModel);

            // The template provides the subject unless one is explicitly set.
            if (null !== ($subject = $email->getSubject()) && '' !== $subject) {
                $payload['Subject'] = $subject;
            }
        } else {
            if (null !== $templateModel) {
                throw new TransportException(\sprintf('The "%s" header requires a "%s" header.', TemplateModelHeader::NAME, TemplateHeader::NAME));
            }

            if (null !== ($subject = $email->getSubject())) {
                $payload['Subject'] = $subject;
            }
            if (null !== ($text = self::bodyToString($email->getTextBody(), $email->getTextCharset()))) {
                $payload['TextBody'] = $text;
            }
            if (null !== $html) {
                $payload['HtmlBody'] = $html;
            }
        }

        if ([] !== $metadata) {
            $payload['Metadata'] = (object) $metadata;
        }
        if ([] !== $customHeaders) {
            $payload['Headers'] = $customHeaders;
        }
        if ([] !== $attachments = self::getAttachments($email, null === $template ? $html : null)) {
            $payload['Attachments'] = $attachments;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private static function getTemplateFields(HeaderInterface $template, ?HeaderInterface $templateModel): array
    {
        if ($template instanceof TemplateHeader) {
            $fields = null !== $template->getTemplateId()
                ? ['TemplateId' => $template->getTemplateId()]
                : ['TemplateAlias' => $template->getTemplateAlias()];
        } else {
            // Plain text header: digits are treated as an ID, anything else as an alias.
            $value = self::requireNonEmpty($template, 'template');
            $id = ctype_digit($value) ? (int) $value : 0;
            $fields = $id > 0 && (string) $id === $value ? ['TemplateId' => $id] : ['TemplateAlias' => $value];
        }

        if (null !== $templateModel) {
            $model = $templateModel instanceof TemplateModelHeader ? $templateModel->getModel() : self::decodeTemplateModel($templateModel);
            if ([] !== $model) {
                $fields['TemplateModel'] = $model;
            }
        }

        return $fields;
    }

    /**
     * @return array<string, string>
     */
    private static function decodeTemplateModel(HeaderInterface $header): array
    {
        try {
            $model = json_decode(HeaderValue::raw($header), true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TransportException(\sprintf('The "%s" header must contain a JSON object.', TemplateModelHeader::NAME), 0, $e);
        }

        if (!\is_array($model)) {
            throw new TransportException(\sprintf('The "%s" header must contain a JSON object.', TemplateModelHeader::NAME));
        }

        try {
            /** @var array<string, string|int|float|null> $model */
            return (new TemplateModelHeader($model))->getModel();
        } catch (\InvalidArgumentException $e) {
            throw new TransportException(\sprintf('Invalid "%s" header: %s', TemplateModelHeader::NAME, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @return list<array{Name: string, Content: string, ContentType: string, ContentID?: string}>
     */
    private static function getAttachments(Email $email, ?string $html): array
    {
        $referencedCids = null !== $html ? self::getReferencedContentIds($html) : [];
        $attachments = [];

        foreach ($email->getAttachments() as $attachment) {
            $name = $attachment->getFilename() ?? $attachment->getName();
            $contentId = self::resolveContentId($attachment, $referencedCids);

            $item = [
                'Name' => $name ?? ($contentId ?? 'attachment'),
                'Content' => base64_encode($attachment->getBody()),
                'ContentType' => $attachment->getMediaType().'/'.$attachment->getMediaSubtype(),
            ];

            if (null !== $contentId) {
                $item['ContentID'] = 'cid:'.$contentId;
            }

            $attachments[] = $item;
        }

        return $attachments;
    }

    /**
     * Returns the Content-ID an inline attachment must have so that the raw HTML body
     * (which Haskimail receives untouched) can reference it with "cid:...".
     *
     * @param list<string> $referencedCids
     */
    private static function resolveContentId(DataPart $attachment, array $referencedCids): ?string
    {
        foreach ([$attachment->getName(), $attachment->getFilename()] as $candidate) {
            if (null !== $candidate && \in_array($candidate, $referencedCids, true)) {
                return $candidate;
            }
        }

        if ($attachment->hasContentId() && \in_array($attachment->getContentId(), $referencedCids, true)) {
            return $attachment->getContentId();
        }

        if ('inline' === $attachment->getPreparedHeaders()->getHeaderBody('Content-Disposition')) {
            return $attachment->getContentId();
        }

        return null;
    }

    /**
     * Mirrors the detection performed by Symfony\Component\Mime\Email when it builds a MIME body.
     *
     * @return list<string>
     */
    private static function getReferencedContentIds(string $html): array
    {
        if (!str_contains($html, 'cid:')) {
            return [];
        }

        $names = [];
        foreach ([
            '/<img\s+[^>]*src\s*=\s*(?:([\'"])cid:(.+?)\\1|cid:([^>\s]+))/i',
            '/<\w+\s+[^>]*background\s*=\s*(?:([\'"])cid:(.+?)\\1|cid:([^>\s]+))/i',
        ] as $regex) {
            preg_match_all($regex, $html, $matches);
            // Group 2 holds quoted references (src="cid:x"), group 3 unquoted ones (src=cid:x).
            array_push($names, ...$matches[2], ...$matches[3]);
        }

        return array_values(array_filter($names, static fn (string $name): bool => '' !== $name));
    }

    /**
     * @param resource|string|null $body
     */
    private static function bodyToString(mixed $body, ?string $charset): ?string
    {
        if (\is_resource($body)) {
            $meta = stream_get_meta_data($body);
            if (true === $meta['seekable']) {
                rewind($body);
            }
            $body = stream_get_contents($body);
            if (false === $body) {
                throw new TransportException('Unable to read the email body stream.');
            }
        }

        if (!\is_string($body)) {
            return null;
        }

        if (null !== $charset && 'utf-8' !== strtolower($charset) && 'utf8' !== strtolower($charset)) {
            try {
                $converted = mb_convert_encoding($body, 'UTF-8', $charset);
            } catch (\ValueError $e) {
                throw new TransportException(\sprintf('Unable to convert the email body from "%s" to UTF-8.', $charset), 0, $e);
            }
            if (!\is_string($converted)) {
                throw new TransportException(\sprintf('Unable to convert the email body from "%s" to UTF-8.', $charset));
            }
            $body = $converted;
        }

        return $body;
    }

    /**
     * @param array<Address> $addresses
     *
     * @return list<Address>
     */
    private static function onlyEnvelopeRecipients(array $addresses, Envelope $envelope): array
    {
        $allowed = array_map(static fn (Address $a): string => strtolower($a->getAddress()), $envelope->getRecipients());

        return array_values(array_filter($addresses, static fn (Address $a): bool => \in_array(strtolower($a->getAddress()), $allowed, true)));
    }

    /**
     * @param array<Address> $addresses
     * @param array<Address> $excluded
     *
     * @return list<Address>
     */
    private static function excludeAddresses(array $addresses, array $excluded): array
    {
        $excluded = array_map(static fn (Address $a): string => strtolower($a->getAddress()), $excluded);

        return array_values(array_filter($addresses, static fn (Address $a): bool => !\in_array(strtolower($a->getAddress()), $excluded, true)));
    }

    /**
     * @param iterable<Address> $addresses
     */
    private static function formatAddresses(iterable $addresses): string
    {
        $formatted = [];
        foreach ($addresses as $address) {
            $formatted[] = self::formatAddress($address);
        }

        return implode(', ', $formatted);
    }

    /**
     * Formats an address as "Display Name <local@domain>" with raw UTF-8 names, as the API expects.
     */
    private static function formatAddress(Address $address): string
    {
        $email = $address->getEncodedAddress();
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $address->getName()));

        if ('' === $name) {
            return $email;
        }

        if (1 === preg_match('/[()<>\[\]:;@\\\\,."]/', $name)) {
            $name = '"'.addcslashes($name, '"\\').'"';
        }

        return $name.' <'.$email.'>';
    }

    private static function requireNonEmpty(HeaderInterface $header, string $label): string
    {
        $value = trim(HeaderValue::raw($header));
        if ('' === $value) {
            throw new TransportException(\sprintf('The Haskimail %s header "%s" must not be empty.', $label, $header->getName()));
        }

        return $value;
    }

    /**
     * @param array<mixed> $result
     */
    private static function readErrorCode(array $result): int
    {
        $code = $result['ErrorCode'] ?? null;

        return is_numeric($code) ? (int) $code : 0;
    }

    private static function statusHint(int $statusCode): string
    {
        return match ($statusCode) {
            401 => ' - check the server token (X-Haskimail-Server-Token)',
            403 => ' - the request IP address is not allowed by the account security settings',
            413 => ' - the request is larger than 50 MB',
            default => '',
        };
    }

    private static function excerpt(ResponseInterface $response): string
    {
        try {
            $content = $response->getContent(false);
        } catch (HttpClientExceptionInterface) {
            return '(unreadable body)';
        }

        $content = trim($content);
        if ('' === $content) {
            return '(empty body)';
        }

        return mb_strlen($content) > self::MAX_ERROR_BODY_LENGTH
            ? mb_substr($content, 0, self::MAX_ERROR_BODY_LENGTH).'...'
            : $content;
    }

    private function getEndpoint(): string
    {
        $host = null !== $this->host && '' !== $this->host ? $this->host : self::DEFAULT_HOST;

        return ($this->port ?? 0) > 0 ? $host.':'.$this->port : $host;
    }
}
