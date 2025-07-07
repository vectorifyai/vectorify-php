<?php

namespace Vectorify\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Vectorify\Support\RateLimiter;

#[CoversClass(RateLimiter::class)]
class RateLimiterTest extends TestCase
{
    #[Test]
    public function constructor_with_cache(): void
    {
        $cache = new class {
            public function get($key) { return null; }
        };

        $rateLimiter = new RateLimiter($cache);

        $this->assertInstanceOf(RateLimiter::class, $rateLimiter);
    }

    #[Test]
    public function constructor_without_cache(): void
    {
        $rateLimiter = new RateLimiter();

        $this->assertInstanceOf(RateLimiter::class, $rateLimiter);
    }

    #[Test]
    public function check_rate_limit_without_cache_data(): void
    {
        $rateLimiter = new RateLimiter();

        // Should not delay when no rate limit data
        $startTime = time();
        $rateLimiter->checkRateLimit();
        $endTime = time();

        $this->assertLessThanOrEqual(1, $endTime - $startTime);
    }

    #[Test]
    public function update_rate_limit_from_response(): void
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

    #[Test]
    public function update_rate_limit_without_headers(): void
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

    #[Test]
    public function handle_rate_limit_response(): void
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
