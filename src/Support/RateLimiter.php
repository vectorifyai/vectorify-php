<?php

namespace Vectorify\Support;

use Exception;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Rate limiter for Vectorify API requests
 *
 * Handles rate limit detection, caching, and progressive delay logic
 * to prevent API rate limit violations across multiple processes.
 */
class RateLimiter
{
    private const MAX_RATE_LIMIT_WAIT = 90;
    private const RATE_LIMIT_THRESHOLD_CRITICAL = 0;
    private const RATE_LIMIT_THRESHOLD_LOW = 2;
    private const RATE_LIMIT_THRESHOLD_MEDIUM = 5;
    private const MAX_PROGRESSIVE_WAIT_HIGH = 30;
    private const MAX_PROGRESSIVE_WAIT_LOW = 10;
    private const CACHE_TTL_BUFFER = 10; // Extra cache time beyond reset

    private ?object $cache;
    private string $cachePrefix;
    private LoggerInterface $logger;

    /**
     * Create a new rate limiter instance
     *
     * @param object|null $cache Cache instance for shared rate limiting
     * @param string $cachePrefix Cache key prefix (default: 'vectorify:api:rate_limit')
     * @param LoggerInterface|null $logger Logger instance for rate limit messages
     */
    public function __construct(
        ?object $cache = null,
        string $cachePrefix = 'vectorify:api:rate_limit',
        ?LoggerInterface $logger = null,
    ) {
        $this->cache = $cache;
        $this->cachePrefix = $cachePrefix;
        $this->logger = $logger ?: $this->createDefaultLogger();
    }

    /**
     * Check rate limits and apply preventive delays if necessary
     */
    public function checkRateLimit(): void
    {
        $rateLimit = $this->getRateLimitFromCache();

        if (! $rateLimit || !isset($rateLimit['remaining'])) {
            return;
        }

        // Be more aggressive - start rate limiting when we have few requests left
        if ($rateLimit['remaining'] > self::RATE_LIMIT_THRESHOLD_LOW) {
            return;
        }

        $resetTime = $rateLimit['reset_time'] ?? time();
        $waitTime = $resetTime - time();

        if ($waitTime <= 0) {
            $this->clearRateLimitCache();

            return;
        }

        // Add progressive delays based on remaining requests
        $delayTime = match (true) {
            $rateLimit['remaining'] <= self::RATE_LIMIT_THRESHOLD_CRITICAL => min($waitTime, self::MAX_RATE_LIMIT_WAIT),
            $rateLimit['remaining'] <= self::RATE_LIMIT_THRESHOLD_LOW => min($waitTime / 2, self::MAX_PROGRESSIVE_WAIT_HIGH),
            $rateLimit['remaining'] <= self::RATE_LIMIT_THRESHOLD_MEDIUM => min($waitTime / 4, self::MAX_PROGRESSIVE_WAIT_LOW),
            default => 0,
        };

        if ($delayTime > 0) {
            $this->logger->info("Rate limit preventive delay: {$delayTime} seconds (remaining: {$rateLimit['remaining']})");

            sleep((int) $delayTime);
        }
    }

    /**
     * Create a default logger instance
     *
     * @return LoggerInterface Default logger instance
     */
    private function createDefaultLogger(): LoggerInterface
    {
        $logger = new Logger('vectorify.rate_limiter');
        $logger->pushHandler(new StreamHandler('php://stderr', Logger::INFO));
        return $logger;
    }

    /**
     * Update rate limit information from API response
     *
     * @param ResponseInterface $response HTTP response with rate limit headers
     */
    public function updateRateLimit(ResponseInterface $response): void
    {
        $remaining = $this->getHeader('X-RateLimit-Remaining', $response);

        if ($remaining === null) {
            return;
        }

        $retryAfter = $this->getHeader('Retry-After', $response);
        $waitTime = $retryAfter ? (int) $retryAfter : self::MAX_RATE_LIMIT_WAIT;

        $rateLimit = [
            'remaining' => (int) $remaining,
            'reset_time' => time() + $waitTime,
            'updated_at' => time(),
        ];

        $this->setRateLimitCache($rateLimit, $waitTime + self::CACHE_TTL_BUFFER);

        $this->logger->debug('Rate limit updated', [
            'remaining' => $rateLimit['remaining'],
            'reset_time' => date('Y-m-d H:i:s', $rateLimit['reset_time']),
        ]);
    }

    /**
     * Handle rate limit response (429 status)
     *
     * @param ResponseInterface $response HTTP response with rate limit information
     */
    public function handleRateLimitResponse(ResponseInterface $response): void
    {
        $retryAfter = $this->getHeader('Retry-After', $response);
        $waitTime = $retryAfter ? (int) $retryAfter : self::MAX_RATE_LIMIT_WAIT;

        // Update rate limit cache to reflect we've hit the limit
        $rateLimit = [
            'remaining' => 0,
            'reset_time' => time() + $waitTime,
            'updated_at' => time(),
        ];

        $this->setRateLimitCache($rateLimit, $waitTime + self::CACHE_TTL_BUFFER);

        $this->logger->info("Rate limit hit, waiting {$waitTime} seconds before retry");

        sleep(min($waitTime, self::MAX_RATE_LIMIT_WAIT));
    }

    /**
     * Get rate limit data from cache
     *
     * @return array|null Rate limit data or null if not available
     */
    private function getRateLimitFromCache(): ?array
    {
        if (! $this->cache) {
            return null;
        }

        try {
            // Try different cache implementations
            if (method_exists($this->cache, 'get')) {
                return $this->cache->get($this->cachePrefix);
            } elseif (method_exists($this->cache, 'fetch')) {
                return $this->cache->fetch($this->cachePrefix);
            }
        } catch (Exception $e) {
            // Silent failure - rate limiting will fall back to per-process
        }

        return null;
    }

    /**
     * Store rate limit data in cache
     *
     * @param array $rateLimit Rate limit data
     * @param int $ttl Time to live in seconds
     */
    private function setRateLimitCache(array $rateLimit, int $ttl): void
    {
        if (! $this->cache) {
            return;
        }

        try {
            // Try different cache implementations
            if (method_exists($this->cache, 'put')) {
                // Laravel cache - use DateTime instead of now() helper
                $expiry = new \DateTime();
                $expiry->add(new \DateInterval("PT{$ttl}S"));
                $this->cache->put($this->cachePrefix, $rateLimit, $expiry);
            } elseif (method_exists($this->cache, 'set')) {
                // PSR-6/PSR-16 cache or Redis
                $this->cache->set($this->cachePrefix, $rateLimit, $ttl);
            } elseif (method_exists($this->cache, 'save')) {
                // Doctrine cache
                $this->cache->save($this->cachePrefix, $rateLimit, $ttl);
            }
        } catch (Exception $e) {
            // Silent failure - rate limiting will fall back to per-process
        }
    }

    /**
     * Clear rate limit data from cache
     */
    private function clearRateLimitCache(): void
    {
        if (! $this->cache) {
            return;
        }

        try {
            // Try different cache implementations
            if (method_exists($this->cache, 'forget')) {
                // Laravel cache
                $this->cache->forget($this->cachePrefix);
            } elseif (method_exists($this->cache, 'delete')) {
                // PSR-6/PSR-16 cache or Redis
                $this->cache->delete($this->cachePrefix);
            } elseif (method_exists($this->cache, 'deleteItem')) {
                // PSR-6 specific method
                $this->cache->deleteItem($this->cachePrefix);
            }
        } catch (Exception $e) {
            // Silent failure
        }
    }

    /**
     * Extract header value from HTTP response
     *
     * @param string $name Header name
     * @param ResponseInterface $response HTTP response
     * @return string|null Header value or null if not found
     */
    private function getHeader(string $name, ResponseInterface $response): ?string
    {
        $headers = $response->getHeaders();

        // Try exact match first
        if (isset($headers[$name])) {
            return is_array($headers[$name]) ? $headers[$name][0] : $headers[$name];
        }

        // Try lowercase match
        $lowerName = strtolower($name);
        if (isset($headers[$lowerName])) {
            return is_array($headers[$lowerName]) ? $headers[$lowerName][0] : $headers[$lowerName];
        }

        return null;
    }
}
