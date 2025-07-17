<?php

namespace Vectorify;

use Exception;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\RetryMiddleware;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Vectorify\GuzzleRateLimiter\Contracts\StoreInterface;
use Vectorify\GuzzleRateLimiter\RateLimiterMiddleware;
use Vectorify\GuzzleRateLimiter\Stores\InMemoryStore;

class Client
{
    private const MAX_RETRY_ATTEMPTS = 3;

    private string $baseUrl = 'https://api.vectorify.ai/v1/';
    private string $apiKey;
    private int $timeout;
    private GuzzleClient $httpClient;
    private LoggerInterface $logger;

    /**
     * Create a new Vectorify HTTP client
     *
     * @param string $apiKey The Vectorify API key (cannot be empty)
     * @param int $timeout Request timeout in seconds (must be positive)
     * @param StoreInterface|null $store Optional cache store for rate limiting coordination
     * @param LoggerInterface|null $logger Optional logger instance
     *
     * @throws \InvalidArgumentException If apiKey is empty or timeout is not positive
     */
    public function __construct(
        string $apiKey,
        int $timeout = 30,
        ?StoreInterface $store = null,
        ?LoggerInterface $logger = null,
    ) {
        if (empty(trim($apiKey))) {
            throw new \InvalidArgumentException('API key cannot be empty');
        }

        if ($timeout <= 0) {
            throw new \InvalidArgumentException("Timeout must be positive, got: {$timeout}");
        }

        $this->apiKey = trim($apiKey);
        $this->timeout = $timeout;
        $this->logger = $logger ?: $this->createDefaultLogger();

        // Create handler stack with rate limiting and retry middleware
        $stack = HandlerStack::create();

        // Add rate limiting middleware
        $rateLimitStore = $store ?: new InMemoryStore();
        $rateLimiterMiddleware = new RateLimiterMiddleware(
            $rateLimitStore,
            'vectorify:api:rate_limit',
            $this->logger
        );
        $stack->push($rateLimiterMiddleware);

        // Add retry middleware for server errors
        $stack->push($this->getRetryMiddleware(self::MAX_RETRY_ATTEMPTS));

        $this->httpClient = new GuzzleClient([
            'base_uri' => $this->baseUrl,
            'timeout' => $this->timeout,
            'handler' => $stack,
        ]);
    }

    public function get(string $path, array $options = []): ?ResponseInterface
    {
        return $this->request('GET', $path, $options);
    }

    public function post(string $path, array $options = []): ?ResponseInterface
    {
        return $this->request('POST', $path, $options);
    }

    public function put(string $path, array $options = []): ?ResponseInterface
    {
        return $this->request('PUT', $path, $options);
    }

    public function patch(string $path, array $options = []): ?ResponseInterface
    {
        return $this->request('PATCH', $path, $options);
    }

    public function delete(string $path, array $options = []): ?ResponseInterface
    {
        return $this->request('DELETE', $path, $options);
    }

    /**
     * Execute an HTTP request with automatic retry and rate limiting via middleware
     *
     * @param string $method HTTP method (GET, POST, PUT, PATCH, DELETE)
     * @param string $path API endpoint path
     * @param array $options Additional request options for Guzzle
     *
     * @return ResponseInterface|null Response object or null on client errors
     * @throws \Exception On unrecoverable errors after all retries
     */
    public function request(
        string $method,
        string $path,
        array $options = [],
    ): ?ResponseInterface {
        try {
            $requestOptions = array_merge([
                RequestOptions::HEADERS => [
                    'Api-Key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
            ], $options);

            $response = $this->httpClient->request($method, $path, $requestOptions);

            return $response;

        } catch (GuzzleException $e) {
            // Handle client errors (4xx) by returning null
            if ($e instanceof RequestException && $e->hasResponse()) {
                $response = $e->getResponse();
                $statusCode = $response->getStatusCode();

                if ($statusCode >= 400 && $statusCode < 500 && $statusCode !== 429) {
                    $this->logger->warning('Client error encountered', [
                        'status' => $statusCode,
                        'response' => $response->getBody()->getContents(),
                    ]);

                    return null;
                }
            }

            // Re-throw other exceptions (middleware will handle retries)
            throw $e;
        }
    }

    /**
     * Create retry middleware for server errors
     *
     * @param int $maxRetries Maximum number of retry attempts
     * @return callable Retry middleware function
     */
    private function getRetryMiddleware(int $maxRetries): callable
    {
        $decider = function (
            int $retries,
            RequestInterface $request,
            ResponseInterface $response = null,
        ) use ($maxRetries): bool {
            // Retry on server errors (5xx) but not rate limits (429 is handled by rate limiter)
            return $retries < $maxRetries
                && null !== $response
                && $response->getStatusCode() >= 500;
        };

        $delay = function (int $retries, ResponseInterface $response): int {
            // Exponential backoff for server errors
            return RetryMiddleware::exponentialDelay($retries);
        };

        return Middleware::retry($decider, $delay);
    }

    /**
     * Create a default logger instance
     *
     * @return LoggerInterface Default logger instance
     */
    private function createDefaultLogger(): LoggerInterface
    {
        $logger = new Logger('vectorify.client');
        $logger->pushHandler(new StreamHandler('php://stderr', \Monolog\Level::Info));

        return $logger;
    }
}
