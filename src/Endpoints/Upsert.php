<?php

namespace Vectorify\Endpoints;

use GuzzleHttp\RequestOptions;
use Vectorify\Objects\UpsertObject;

class Upsert extends Endpoint
{
    public string $path = 'upserts';

    public function send(UpsertObject $object): bool
    {
        $response = $this->client->post($this->path, [
            RequestOptions::BODY => json_encode($object->toPayload()),
        ]);

        return $response !== null && $response->getStatusCode() === 201;
    }
}
