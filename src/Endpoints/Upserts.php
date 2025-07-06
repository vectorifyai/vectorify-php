<?php

namespace Vectorify\Endpoints;

use GuzzleHttp\RequestOptions;
use Vectorify\Objects\UpsertObject;

class Upserts extends Endpoint
{
    public string $path = 'upserts';

    public function create(UpsertObject $object): bool
    {
        $response = $this->client->post($this->path, [
            RequestOptions::BODY => json_encode($object->toPayload()),
        ]);

        return $response !== null && $response->getStatusCode() === 201;
    }

    public function list(): array
    {
        $response = $this->client->get($this->path);

        if ($response === null || $response->getStatusCode() !== 200) {
            return [];
        }

        $body = $response->getBody()->getContents();

        return json_decode($body, true)['data'] ?: [];
    }

    public function fetch(string $id): ?array
    {
        $response = $this->client->get("{$this->path}/{$id}");

        if ($response === null || $response->getStatusCode() !== 200) {
            return null;
        }

        $body = $response->getBody()->getContents();

        return json_decode($body, true)['data'] ?: null;
    }
}
