<?php

namespace Vectorify;

use Vectorify\Endpoints\Query;
use Vectorify\Endpoints\Upserts;

/**
 * Main Vectorify SDK client
 *
 * Provides access to Vectorify API endpoints with built-in rate limiting,
 * retry logic, and multi-process coordination via cache backends.
 */
class Vectorify
{
    private Client $client;
    private Upserts $upserts;
    private Query $query;

    /**
     * Create a new Vectorify SDK instance
     *
     * @param string $apiKey Vectorify API key
     * @param int $timeout Request timeout in seconds (default: 30)
     * @param object|null $cache Cache instance for shared rate limiting (optional)
     *
     * @throws \InvalidArgumentException If apiKey is empty or timeout is invalid
     */
    public function __construct(string $apiKey, int $timeout = 30, ?object $cache = null)
    {
        $this->client = new Client($apiKey, $timeout, $cache);
        $this->upserts = new Upserts($this->client);
        $this->query = new Query($this->client);
    }

    /**
     * Get the upsert endpoint
     *
     * @return Upserts Result of the upsert operation
     */
    public function upserts(): Upserts
    {
        return $this->upserts;
    }

    /**
     * Get the query endpoint
     *
     * @return Query Result of the query operation
     */
    public function query(): Query
    {
        return $this->query;
    }

    /**
     * Get the underlying HTTP client
     *
     * @return Client The HTTP client with rate limiting capabilities
     */
    public function getClient(): Client
    {
        return $this->client;
    }
}
