<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Tests\Internal;

use Haskimail\SymfonyMailer\Internal\RedactedResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class RedactedResponseTest extends TestCase
{
    private const SECRET = 's3cr3t-token';

    public function testDelegatesEverything(): void
    {
        $response = $this->response(new JsonMockResponse(['a' => 1], ['http_code' => 200, 'response_headers' => ['X-Test' => 'yes']]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['yes'], $response->getHeaders()['x-test'] ?? null);
        self::assertSame('{"a":1}', $response->getContent());
        self::assertSame(['a' => 1], $response->toArray());
    }

    public function testThrowingIsTheDefaultLikeTheDecoratedResponse(): void
    {
        $response = $this->response(new JsonMockResponse(['error' => 'x'], ['http_code' => 404]));

        self::assertSame(['error' => 'x'], $response->toArray(false));
        self::assertSame('{"error":"x"}', $response->getContent(false));
        self::assertSame([], array_diff_key(['content-type' => 1], $response->getHeaders(false)));

        foreach ([static fn () => $response->getHeaders(), static fn () => $response->getContent(), static fn () => $response->toArray()] as $call) {
            try {
                $call();
                self::fail('A client exception should have been thrown.');
            } catch (ClientException) {
            }
        }
    }

    public function testSecretIsRedactedFromInfo(): void
    {
        $response = $this->response(new JsonMockResponse([], ['debug' => 'Token: '.self::SECRET, 'user_data' => ['nested' => [self::SECRET]]]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Token: [redacted]', $response->getInfo('debug'));
        self::assertSame(200, $response->getInfo('http_code'));

        $all = $response->getInfo();
        self::assertIsArray($all);
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($all));
        self::assertSame('Token: [redacted]', $all['debug'] ?? null);
    }

    public function testCancel(): void
    {
        $response = $this->response(new JsonMockResponse([]));
        $response->cancel();

        self::assertTrue($response->getInfo('canceled'));
    }

    public function testDebugInfoHidesTheSecret(): void
    {
        $response = $this->response(new JsonMockResponse([]));

        self::assertStringNotContainsString(self::SECRET, print_r($response, true));
        self::assertSame('[redacted]', $response->__debugInfo()['secret']);
        self::assertInstanceOf(ResponseInterface::class, $response->__debugInfo()['response']);
    }

    private function response(JsonMockResponse $mock): RedactedResponse
    {
        return new RedactedResponse((new MockHttpClient($mock))->request('GET', 'https://example.com'), self::SECRET);
    }
}
