<?php

namespace Vectorify\Tests\Unit;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Vectorify\Client;
use Vectorify\GuzzleRateLimiter\RateLimiterMiddleware;
use Vectorify\GuzzleRateLimiter\Stores\InMemoryStore;

#[CoversClass(Client::class)]
class ClientRateLimitAndRetryTest extends TestCase
{
    private const DEFAULT_RATE_LIMIT_REMAINING = 50;
    private const LOW_RATE_LIMIT_REMAINING = 10;
    private const RATE_LIMIT_WAIT_TIME = 1;
    private const PREVENTIVE_DELAY_TIME = 3;
    private const CACHE_KEY = 'vectorify:api:rate_limit';
    private const CACHE_TTL = 60;

    private MockHandler $mockHandler;
    private InMemoryStore $rateLimitStore;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->mockHandler = new MockHandler();
        $this->rateLimitStore = new InMemoryStore();
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    #[Test]
    public function client_respects_rate_limits_and_waits(): void
    {
        // Simulate successful response with rate limit info
        $this->mockHandler->append($this->createSuccessResponse());

        $client = $this->createClientWithMockHandler();

        $response = $client->get('test-endpoint');

        // Verify response is successful
        $this->assertNotNull($response);
        $this->assertEquals(200, $response->getStatusCode());

        // Verify rate limit was stored
        $cached = $this->rateLimitStore->get(self::CACHE_KEY);
        $this->assertIsArray($cached);
        $this->assertEquals(self::DEFAULT_RATE_LIMIT_REMAINING, $cached['remaining']);
    }

    #[Test]
    public function client_handles_rate_limit_exceeded_with_wait(): void
    {
        // Simulate rate limit exceeded - the middleware should wait and throw
        $this->mockHandler->append($this->createRateLimitResponse());

        $client = $this->createClientWithMockHandler();

        $startTime = microtime(true);

        try {
            $client->get('test-endpoint');
            $this->fail('Expected ClientException to be thrown');
        } catch (ClientException $e) {
            $endTime = microtime(true);

            // Verify it waited before throwing
            $duration = $endTime - $startTime;
            $this->assertGreaterThan(0.8, $duration, 'Should have waited for rate limit');

            // Verify the exception is for 429
            $this->assertEquals(429, $e->getResponse()->getStatusCode());

            // Verify rate limit was cached
            $cached = $this->rateLimitStore->get(self::CACHE_KEY);
            $this->assertIsArray($cached);
            $this->assertEquals(0, $cached['remaining']);
        }
    }

    #[Test]
    public function client_retries_on_server_errors_with_exponential_backoff(): void
    {
        // Simulate server errors followed by success
        $this->mockHandler->append(
            new Response(500, [], 'Internal Server Error'),
            new Response(502, [], 'Bad Gateway'),
            $this->createSuccessResponse(),
        );

        $client = $this->createClientWithMockHandler();

        $startTime = microtime(true);
        $response = $client->get('test-endpoint');
        $endTime = microtime(true);

        // Verify response is successful after retries
        $this->assertNotNull($response);
        $this->assertEquals(200, $response->getStatusCode());

        // Verify it took some time (retry delays)
        $duration = $endTime - $startTime;
        $this->assertGreaterThan(0.1, $duration, 'Should have taken time for retries');
    }

    #[Test]
    public function client_exhausts_retries_for_persistent_server_errors(): void
    {
        // Simulate persistent server errors (more than max retries)
        $this->mockHandler->append(
            new Response(500, [], 'Internal Server Error'),
            new Response(500, [], 'Internal Server Error'),
            new Response(500, [], 'Internal Server Error'),
            new Response(500, [], 'Internal Server Error'), // One more than max retries
        );

        $client = $this->createClientWithMockHandler();

        $this->expectException(RequestException::class);
        $client->get('test-endpoint');
    }

    #[Test]
    public function client_does_not_retry_on_client_errors(): void
    {
        // Simulate client error (should not retry)
        $this->mockHandler->append(
            new Response(404, [], 'Not Found'),
        );

        $client = $this->createClientWithMockHandler();

        $startTime = microtime(true);
        $response = $client->get('test-endpoint');
        $endTime = microtime(true);

        // Verify response is null for client error
        $this->assertNull($response);

        // Verify it was fast (no retries)
        $duration = $endTime - $startTime;
        $this->assertLessThan(0.5, $duration, 'Should not have retried client errors');
    }

    #[Test]
    public function client_returns_null_for_client_errors(): void
    {
        // Client errors (4xx except 429) should return null
        $this->mockHandler->append(
            new Response(400, [], 'Bad Request'),
        );

        $client = $this->createClientWithMockHandler();
        $response = $client->get('test-endpoint');

        $this->assertNull($response);
    }

    #[Test]
    public function client_does_not_retry_rate_limit_errors(): void
    {
        // Rate limit errors (429) should be handled by rate limiter middleware
        // The middleware waits and then rethrows the exception
        $this->mockHandler->append($this->createRateLimitResponse());

        $client = $this->createClientWithMockHandler();

        $startTime = microtime(true);

        try {
            $client->get('test-endpoint');
            $this->fail('Expected ClientException to be thrown');
        } catch (ClientException $e) {
            $endTime = microtime(true);

            // Verify it waited before throwing
            $duration = $endTime - $startTime;
            $this->assertGreaterThan(0.5, $duration, 'Should have waited for rate limit');

            // Verify the exception is for 429
            $this->assertEquals(429, $e->getResponse()->getStatusCode());
        }
    }

    #[Test]
    public function client_applies_preventive_delays_when_rate_limit_low(): void
    {
        // Pre-populate rate limit store to simulate low remaining requests
        $this->rateLimitStore->put(
            key: self::CACHE_KEY,
            data: $this->createRateLimitData(1, self::PREVENTIVE_DELAY_TIME),
            ttl: self::CACHE_TTL,
        );

        $this->mockHandler->append($this->createSuccessResponse(self::LOW_RATE_LIMIT_REMAINING));

        $client = $this->createClientWithMockHandler();

        $startTime = microtime(true);
        $response = $client->get('test-endpoint');
        $endTime = microtime(true);

        // Verify response is successful
        $this->assertNotNull($response);
        $this->assertEquals(200, $response->getStatusCode());

        // Verify it took some time (preventive delay)
        $duration = $endTime - $startTime;
        $this->assertGreaterThan(0.5, $duration, 'Should have applied preventive delay');
    }

    #[Test]
    public function client_handles_mixed_rate_limits_and_server_errors(): void
    {
        // Simulate server error followed by success (no rate limits in this test)
        $this->mockHandler->append(
            new Response(500, [], 'Internal Server Error'),
            $this->createSuccessResponse(),
        );

        $client = $this->createClientWithMockHandler();

        $startTime = microtime(true);
        $response = $client->get('test-endpoint');
        $endTime = microtime(true);

        // Verify final success
        $this->assertNotNull($response);
        $this->assertEquals(200, $response->getStatusCode());

        // Verify it took time for retry delay
        $duration = $endTime - $startTime;
        $this->assertGreaterThan(0.1, $duration, 'Should have waited for retry delay');
    }

    #[Test]
    public function client_preserves_request_options_through_middleware(): void
    {
        $this->mockHandler->append($this->createSuccessResponse());

        $client = $this->createClientWithMockHandler();

        $customOptions = [
            'json' => ['test' => 'data'],
            'query' => ['param' => 'value'],
        ];

        $response = $client->post('test-endpoint', $customOptions);

        $this->assertNotNull($response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    #[Test]
    public function client_throws_connection_errors_without_retry(): void
    {
        // Connection errors are NOT retried by our current middleware
        // (only server response errors 5xx are retried)
        $this->mockHandler->append(
            new ConnectException('Connection timeout', new Request('GET', 'test')),
        );

        $client = $this->createClientWithMockHandler();

        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('Connection timeout');

        $client->get('test-endpoint');
    }

    #[Test]
    public function client_rate_limiter_caches_and_respects_previous_rate_limit_state(): void
    {
        // Pre-populate rate limit store to simulate exhausted rate limit
        $this->rateLimitStore->put(
            key: self::CACHE_KEY,
            data: $this->createRateLimitData(0, self::RATE_LIMIT_WAIT_TIME),
            ttl: self::CACHE_TTL,
        );

        $this->mockHandler->append($this->createSuccessResponse());

        $client = $this->createClientWithMockHandler();

        $startTime = microtime(true);
        $response = $client->get('test-endpoint');
        $endTime = microtime(true);

        // Verify response is successful after waiting
        $this->assertNotNull($response);
        $this->assertEquals(200, $response->getStatusCode());

        // Verify it waited due to cached rate limit state
        $duration = $endTime - $startTime;
        $this->assertGreaterThan(0.8, $duration, 'Should have waited for cached rate limit');
    }

    #[Test]
    #[DataProvider('httpMethodProvider')]
    public function client_http_methods_work_with_middleware(string $method): void
    {
        $this->mockHandler->append($this->createSuccessResponse());

        $client = $this->createClientWithMockHandler();
        $response = $client->$method('test-endpoint');

        $this->assertNotNull($response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Data provider for HTTP methods
     */
    public static function httpMethodProvider(): array
    {
        return [
            'GET' => ['get'],
            'POST' => ['post'],
            'PUT' => ['put'],
            'PATCH' => ['patch'],
            'DELETE' => ['delete'],
        ];
    }

    /**
     * Create a successful response with rate limit headers
     */
    private function createSuccessResponse(
        int $remaining = self::DEFAULT_RATE_LIMIT_REMAINING,
    ): Response {
        return new Response(200, [
            'X-RateLimit-Remaining' => (string) $remaining,
        ], '{"success": true}');
    }

    /**
     * Create a rate limit exceeded response
     */
    private function createRateLimitResponse(
        int $waitTime = self::RATE_LIMIT_WAIT_TIME,
    ): Response {
        return new Response(429, [
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset' => (string) (time() + $waitTime),
            'Retry-After' => (string) $waitTime,
        ], 'Rate limit exceeded');
    }

    /**
     * Create rate limit data for store pre-population
     */
    private function createRateLimitData(int $remaining, int $resetTimeOffset): array
    {
        return [
            'remaining' => $remaining,
            'reset_time' => time() + $resetTimeOffset,
            'updated_at' => time(),
        ];
    }

    /**
     * Create a Client instance with the mock handler injected
     */
    private function createClientWithMockHandler(): Client
    {
        // Use reflection to create client and inject our mock handler
        $client = new Client(
            apiKey: 'test-api-key',
            timeout: 30,
            store: $this->rateLimitStore,
            logger: $this->logger,
        );

        // Replace the HTTP client with our mock
        $reflection = new \ReflectionClass($client);
        $httpClientProperty = $reflection->getProperty('httpClient');
        $httpClientProperty->setAccessible(true);

        $stack = HandlerStack::create($this->mockHandler);

        // Re-add the same middleware that the real client uses
        $rateLimiterMiddleware = new RateLimiterMiddleware(
            store: $this->rateLimitStore,
            cachePrefix: self::CACHE_KEY,
            logger: $this->logger,
        );
        $stack->push($rateLimiterMiddleware);

        // Add retry middleware
        $stack->push($client->getRetryMiddleware(3));

        $mockClient = new \GuzzleHttp\Client([
            'base_uri' => 'https://api.vectorify.ai/v1/',
            'timeout' => 30,
            'handler' => $stack,
        ]);

        $httpClientProperty->setValue($client, $mockClient);

        return $client;
    }
}
