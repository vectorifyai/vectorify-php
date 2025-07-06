# Vectorify package for PHP

Vectorify is the end-to-end AI connector for PHP, letting you query and explore your data in natural language in seconds.

To interact with your data, you have four primary methods to choose from:

1. Use the [Chats](https://app.vectorify.ai/) page within our platform (fastest)
2. Embed the [Chatbot](https://docs.vectorify.ai/project/chatbot) into your Laravel app (turn data querying into a product feature)
3. Add the [MCP](https://docs.vectorify.ai/mcp-server) server to ChatGPT, Claude, etc. (use your data anywhere you work)
4. Call the REST [API](https://docs.vectorify.ai/api-reference) endpoints (build custom integrations and workflows)

Unlike text-to-SQL tools that expose your entire database and take 30+ seconds per query, Vectorify uses proven RAG technology to deliver accurate answers in <4 seconds while keeping your database secure. Head to our [blog](https://vectorify.ai/blog/vectorify-laravel-unlock-ai-ready-data-in-60-seconds) to learn more about Vectorify.

This package provides a simple and elegant way to interact with the Vectorify API. Ask AI about your data with ease.

## Requirements

- PHP 8.2 or higher

## Installation

Install the package via Composer:

```bash
composer require vectorifyai/vectorify-php
```

## Quick Start

```php
use Vectorify\Vectorify;
use Vectorify\Objects\CollectionObject;
use Vectorify\Objects\ItemObject;
use Vectorify\Objects\UpsertObject;
use Vectorify\Objects\QueryObject;

// Initialize the client
$vectorify = new Vectorify('your-api-key');

// Create a collection
$collection = new CollectionObject(
    slug: 'invoices',
    metadata: [
        'customer_name' => ['type' => 'string'],
        'status' => [
            'type' => 'enum',
            'options' => ['draft', 'sent', 'paid'],
        ]
    ],
);

// Create items
$items = [
    new ItemObject(
        id: '123',
        data: [
            'customer_name' => 'John Doe',
            'status' => 'draft',
            'amount' => '100',
            'currency' => 'USD',
            'due_date' => '2023-10-01'
        ],
        metadata: [
            'customer_name' => 'John Doe',
            'status' => 'draft'
        ],
        tenant: 987,
        url: 'https://example.com/invoice/123',
    ),
];

// Upsert data
$upsertObject = new UpsertObject($collection, $items);
$success = $vectorify->upserts->create($upsertObject);

if ($success) {
    echo "Data upserted successfully!\n";
}

// Query data
$queryObject = new QueryObject(
    text: 'how many invoices are in draft status?',
    collections: ['invoices'],
    tenant: 987,
    identifier: [
        'id' => '123',
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ]
);

$result = $vectorify->query->send($queryObject);

if ($result !== false) {
    print_r($result);
}
```

## Features

- **Rate Limiting**: Automatic rate limit handling with exponential backoff
- **Retry Logic**: Built-in retry mechanism for failed requests
- **Type Safety**: Fully typed objects for better development experience
- **Error Handling**: Comprehensive error handling and logging
- **PSR-7 Compatible**: Uses PSR-7 HTTP message interfaces

## API Methods

### Upsert

Create or update items in your Vectorify collection:

```php
$upsertObject = new UpsertObject($collection, $items);
$success = $vectorify->upserts->create($upsertObject);
```

### Query

Ask questions about your data:

```php
$queryObject = new QueryObject('your question here');
$result = $vectorify->query->send($queryObject);
```

## Configuration

### Timeout

You can configure the request timeout when initializing the client:

```php
$vectorify = new Vectorify('your-api-key', 60); // 60 seconds timeout
```

### Debug Logging

Enable debug logging by defining a constant:

```php
define('VECTORIFY_DEBUG', true);
```

## Error Handling

The SDK includes comprehensive error handling:

- **Rate Limiting**: Automatically handles 429 responses with appropriate delays
- **Server Errors**: Retries server errors (5xx) with exponential backoff
- **Client Errors**: Returns `null` or `false` for client errors (4xx)
- **Network Errors**: Retries network-related errors

## Changelog

Please see [Releases](../../releases) for more information on what has changed recently.

## Contributing

Pull requests are more than welcome. You must follow the PSR coding standards.

## Security

Please review [our security policy](https://github.com/vectorifyai/laravel-vectorify/security/policy) on how to report security vulnerabilities.

## License

The MIT License (MIT). Please see [LICENSE](LICENSE.md) for more information.
