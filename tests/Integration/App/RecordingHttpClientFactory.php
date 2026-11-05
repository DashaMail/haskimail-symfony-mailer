<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Tests\Integration\App;

use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Mock response factory for framework.http_client that records outgoing requests.
 */
final class RecordingHttpClientFactory
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public array $requests = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): MockResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

        return new JsonMockResponse([
            'To' => 'ivan@example.com',
            'SubmittedAt' => '2026-10-07 14:30:12',
            'MessageID' => 'integration-message-id',
            'ErrorCode' => 0,
            'Message' => 'OK',
        ]);
    }
}
