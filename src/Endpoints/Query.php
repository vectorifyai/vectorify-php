<?php

namespace Vectorify\Endpoints;

use GuzzleHttp\RequestOptions;
use Vectorify\Objects\QueryObject;

class Query extends Endpoint
{
    public string $path = 'query';

    public function send(QueryObject $object): bool|array
    {
        $response = $this->client->post($this->path, [
            RequestOptions::BODY => json_encode($object->toPayload()),
        ]);

        if ($response === null || $response->getStatusCode() !== 201) {
            return false;
        }

        $body = $response->getBody()->getContents();

        return json_decode($body, true) ?: [];
    }
}
