<?php

declare(strict_types=1);

/*
 * This file is part of the haskimail/symfony-mailer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Haskimail\SymfonyMailer\Tests\Exception;

use Haskimail\SymfonyMailer\Exception\HaskimailApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HaskimailApiExceptionTest extends TestCase
{
    public function testAccessors(): void
    {
        $response = new MockResponse();
        $previous = new \RuntimeException('previous');
        $exception = new HaskimailApiException('Failed', $response, 1101, 'Template not found', 422, $previous);

        self::assertSame('Failed', $exception->getMessage());
        self::assertSame(1101, $exception->getErrorCode());
        self::assertSame(1101, $exception->getCode());
        self::assertSame('Template not found', $exception->getApiMessage());
        self::assertSame(422, $exception->getStatusCode());
        self::assertSame($response, $exception->getResponse());
        self::assertSame($previous, $exception->getPrevious());
    }

    /**
     * @return iterable<string, array{int, int, bool}>
     */
    public static function provideRetryable(): iterable
    {
        yield 'validation error' => [422, HaskimailApiException::ERROR_INVALID_EMAIL, false];
        yield 'unauthorized' => [401, HaskimailApiException::ERROR_INVALID_TOKEN, false];
        yield 'temporary error' => [422, HaskimailApiException::ERROR_TEMPORARY, true];
        yield 'internal error code' => [422, HaskimailApiException::ERROR_INTERNAL, true];
        yield 'server error' => [500, 0, true];
        yield 'unavailable' => [503, 0, true];
        yield 'just below 500' => [499, 0, false];
    }

    #[DataProvider('provideRetryable')]
    public function testIsRetryable(int $status, int $errorCode, bool $retryable): void
    {
        self::assertSame($retryable, (new HaskimailApiException('x', new MockResponse(), $errorCode, '', $status))->isRetryable());
    }
}
