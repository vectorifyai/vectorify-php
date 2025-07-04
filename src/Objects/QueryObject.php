<?php

namespace Vectorify\Objects;

final readonly class QueryObject
{
    public function __construct(
        public string $text,
        public ?array $collections = null,
        public ?int $tenant = null,
        public ?array $identifier = null,
    ) {}

    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'collections' => $this->collections,
            'tenant' => $this->tenant,
            'identifier' => $this->identifier,
        ];
    }

    public function toPayload(): array
    {
        $payload = [
            'text' => $this->text,
        ];

        if ($this->collections !== null) {
            $payload['collections'] = $this->collections;
        }

        if ($this->tenant !== null) {
            $payload['tenant'] = $this->tenant;
        }

        if ($this->identifier !== null) {
            $payload['identifier'] = $this->identifier;
        }

        return $payload;
    }
}
