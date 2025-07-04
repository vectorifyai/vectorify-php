<?php

namespace Vectorify\Endpoints;

use Vectorify\Client;

abstract class Endpoint
{
    public string $path;
    public Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }
}
