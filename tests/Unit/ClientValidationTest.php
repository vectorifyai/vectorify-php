<?php

namespace Vectorify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vectorify\Client;

class ClientValidationTest extends TestCase
{
    public function testConstructorValidatesEmptyApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('API key cannot be empty');

        new Client('');
    }

    public function testConstructorValidatesWhitespaceApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('API key cannot be empty');

        new Client('   ');
    }

    public function testConstructorValidatesNegativeTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be positive, got: -1');

        new Client('valid-api-key', -1);
    }

    public function testConstructorValidatesZeroTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be positive, got: 0');

        new Client('valid-api-key', 0);
    }

    public function testConstructorTrimsApiKey(): void
    {
        $client = new Client('  valid-api-key  ', 30);

        // Use reflection to access private property for testing
        $reflection = new \ReflectionClass($client);
        $apiKeyProperty = $reflection->getProperty('apiKey');
        $apiKeyProperty->setAccessible(true);

        $this->assertEquals('valid-api-key', $apiKeyProperty->getValue($client));
    }

    public function testConstructorWithValidParameters(): void
    {
        $client = new Client('valid-api-key', 60);

        $this->assertInstanceOf(Client::class, $client);
    }
}
