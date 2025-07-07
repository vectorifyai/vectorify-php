<?php

namespace Vectorify;

use Vectorify\Endpoints\Query;
use Vectorify\Endpoints\Upserts;
use Vectorify\GuzzleRateLimiter\Contracts\StoreInterface;

/**
 * Vectorify SDK entry point
 *
 * Provides access to Vectorify API endpoints with built-in rate limiting,
 * retry logic, and multi-process coordination via cache backends.
 */
final class Vectorify
{
    public Client $client;
    public Upserts $upserts;
    public Query $query;

    /**
     * Create a new Vectorify SDK instance
     *
     * @param string $apiKey Vectorify API key
     * @param int $timeout Request timeout in seconds (default: 30)
     * @param StoreInterface|null $store Optional cache store for shared rate limiting
     *
     * @throws \InvalidArgumentException If apiKey is empty or timeout is invalid
     */
    public function __construct(string $apiKey, int $timeout = 30, ?StoreInterface $store = null)
    {
        $this->client = new Client($apiKey, $timeout, $store);
        $this->upserts = new Upserts($this->client);
        $this->query = new Query($this->client);
    }
}
