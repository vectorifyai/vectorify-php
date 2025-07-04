<?php

namespace Vectorify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Vectorify\Support\RateLimiter;

class RateLimiterTest extends TestCase
{
    public function testConstructorWithCache(): void
    {
        $cache = new class {
            public function get($key) { return null; }
        };

        $rateLimiter = new RateLimiter($cache);

        $this->assertInstanceOf(RateLimiter::class, $rateLimiter);
    }

    public function testConstructorWithoutCache(): void
    {
        $rateLimiter = new RateLimiter();

        $this->assertInstanceOf(RateLimiter::class, $rateLimiter);
    }

    public function testCheckRateLimitWithoutCacheData(): void
    {
        $rateLimiter = new RateLimiter();

        // Should not delay when no rate limit data
        $startTime = time();
        $rateLimiter->checkRateLimit();
        $endTime = time();

        $this->assertLessThanOrEqual(1, $endTime - $startTime);
    }

    public function testUpdateRateLimitFromResponse(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getHeaders')->willReturn([
            'X-RateLimit-Remaining' => ['10'],
            'Retry-After' => ['60']
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
               ->method('debug')
               ->with('Rate limit updated', $this->isType('array'));

        $rateLimiter = new RateLimiter(null, 'vectorify:api:rate_limit', $logger);
        $rateLimiter->updateRateLimit($response);
    }

    public function testUpdateRateLimitWithoutHeaders(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getHeaders')->willReturn([]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('debug');

        $rateLimiter = new RateLimiter(null, 'vectorify:api:rate_limit', $logger);
        $rateLimiter->updateRateLimit($response);

        // Should not log anything when no rate limit headers present
        $this->assertTrue(true); // Just ensure no exception was thrown
    }

    public function testHandleRateLimitResponse(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getHeaders')->willReturn([
            'Retry-After' => ['5'] // Short wait for testing
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
               ->method('info')
               ->with($this->stringContains('Rate limit hit, waiting 5 seconds'));

        $rateLimiter = new RateLimiter(null, 'vectorify:api:rate_limit', $logger);

        $startTime = time();
        $rateLimiter->handleRateLimitResponse($response);
        $endTime = time();

        // Should have waited approximately 5 seconds
        $this->assertGreaterThanOrEqual(4, $endTime - $startTime);
        $this->assertLessThanOrEqual(6, $endTime - $startTime);
    }
}
