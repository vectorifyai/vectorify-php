# Vectorify package for PHP

The Vectorify PHP SDK provides a simple and elegant way to interact with the Vectorify API. Ask AI about your data with ease.

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
$success = $vectorify->upsert($upsertObject);

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

$result = $vectorify->query($queryObject);

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
$success = $vectorify->upsert($upsertObject);
```

### Query

Ask questions about your data:

```php
$queryObject = new QueryObject('your question here');
$result = $vectorify->query($queryObject);
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

## Requirements

- PHP 8.2 or higher
- Guzzle HTTP 7.0 or higher

## License

This package is licensed under the MIT License.
