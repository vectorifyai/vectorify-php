<?php

namespace Vectorify;

use Vectorify\Endpoints\Query;
use Vectorify\Endpoints\Upsert;
use Vectorify\Objects\QueryObject;
use Vectorify\Objects\UpsertObject;

/**
 * Main Vectorify SDK client
 *
 * Provides access to Vectorify API endpoints with built-in rate limiting,
 * retry logic, and multi-process coordination via cache backends.
 */
class Vectorify
{
    private Client $client;
    private Upsert $upsert;
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
        $this->upsert = new Upsert($this->client);
        $this->query = new Query($this->client);
    }

    /**
     * Call the upsert endpoint
     *
     * @return bool Result of the upsert operation
     */
    public function upsert(UpsertObject $object): bool
    {
        return $this->upsert->send($object);
    }

    /**
     * Call the query endpoint
     *
     * @return bool|array Result of the query operation
     */
    public function query(QueryObject $object): bool|array
    {
        return $this->query->send($object);
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
