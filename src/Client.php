<?php

namespace Vectorify;

use Exception;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Vectorify\Support\RateLimiter;

class Client
{
    private const MAX_RETRY_ATTEMPTS = 3;

    private string $baseUrl = 'https://api.vectorify.ai/v1/';
    private string $apiKey;
    private int $timeout;
    private GuzzleClient $httpClient;
    private RateLimiter $rateLimiter;
    private LoggerInterface $logger;

    /**
     * Create a new Vectorify HTTP client
     *
     * @param string $apiKey The Vectorify API key (cannot be empty)
     * @param int $timeout Request timeout in seconds (must be positive)
     * @param object|null $cache Optional cache instance for rate limiting coordination
     * @param LoggerInterface|null $logger Optional logger instance
     *
     * @throws \InvalidArgumentException If apiKey is empty or timeout is not positive
     */
    public function __construct(
        string $apiKey,
        int $timeout = 30,
        ?object $cache = null,
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
        $this->rateLimiter = new RateLimiter($cache, 'vectorify:api:rate_limit', $this->logger);
        $this->httpClient = new GuzzleClient([
            'base_uri' => $this->baseUrl,
            'timeout' => $this->timeout,
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
     * Execute an HTTP request with retry logic and rate limiting
     *
     * @param string $method HTTP method (GET, POST, PUT, PATCH, DELETE)
     * @param string $path API endpoint path
     * @param array $options Additional request options for Guzzle
     *
     * @return ResponseInterface|null Response object or null on failure
     * @throws \Exception On unrecoverable errors after all retries
     */
    public function request(
        string $method,
        string $path,
        array $options = [],
    ): ?ResponseInterface {
        $attempts = 0;
        $maxAttempts = self::MAX_RETRY_ATTEMPTS;

        while ($attempts < $maxAttempts) {
            $attempts++;

            try {
                // Check rate limits before making request
                $this->rateLimiter->checkRateLimit();

                $requestOptions = array_merge([
                    RequestOptions::HEADERS => [
                        'Api-Key' => $this->apiKey,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                ], $options);

                $response = $this->httpClient->request($method, $path, $requestOptions);

                // Update rate limit information from response
                $this->rateLimiter->updateRateLimit($response);

                return $response;

            } catch (GuzzleException $e) {
                $response = null;

                if ($e instanceof RequestException) {
                    $response = $e->getResponse();
                }

                if ($response) {
                    $statusCode = $response->getStatusCode();

                    // Update rate limit information from error response
                    $this->rateLimiter->updateRateLimit($response);

                    // Handle rate limit responses specifically
                    if ($statusCode === 429) {
                        $this->logger->warning('Rate limit exceeded, will retry after waiting', [
                            'status' => $statusCode,
                            'headers' => $response->getHeaders(),
                            'attempt' => $attempts,
                        ]);

                        // Handle rate limit response using RateLimiter
                        $this->rateLimiter->handleRateLimitResponse($response);

                        if ($attempts < $maxAttempts) {
                            continue; // Retry
                        }

                        throw new Exception('Rate limit exceeded after all retries');
                    }

                    // Handle server errors with retry
                    if ($statusCode >= 500) {
                        $this->logger->warning('Server error encountered, will retry', [
                            'status' => $statusCode,
                            'attempt' => $attempts,
                        ]);

                        if ($attempts < $maxAttempts) {
                            $backoffTime = min(pow(2, $attempts - 1), 60);

                            $this->logger->info("Retrying request in {$backoffTime} seconds", [
                                'attempt' => $attempts,
                                'exception' => $e->getMessage(),
                            ]);

                            sleep($backoffTime);

                            continue; // Retry
                        }

                        throw new Exception("Server error after all retries: {$statusCode}");
                    }

                    // For client errors, don't retry
                    if ($statusCode >= 400 && $statusCode < 500) {
                        $this->logger->warning('Client error encountered', [
                            'status' => $statusCode,
                            'response' => $response->getBody()->getContents(),
                        ]);

                        return null;
                    }
                }

                // For other exceptions, retry with exponential backoff
                if ($attempts < $maxAttempts) {
                    $backoffTime = min(pow(2, $attempts - 1), 60);

                    $this->logger->info("Retrying request in {$backoffTime} seconds", [
                        'attempt' => $attempts,
                        'exception' => $e->getMessage(),
                    ]);

                    sleep($backoffTime);

                    continue; // Retry
                }

                throw $e;
            }
        }

        return null;
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
