<?php

namespace Vectorify\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vectorify\Client;

#[CoversClass(Client::class)]
class ClientValidationTest extends TestCase
{
    #[Test]
    public function constructor_validates_empty_api_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('API key cannot be empty');

        new Client('');
    }

    #[Test]
    public function constructor_validates_whitespace_api_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('API key cannot be empty');

        new Client('   ');
    }

    #[Test]
    public function constructor_validates_negative_timeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be positive, got: -1');

        new Client('valid-api-key', -1);
    }

    #[Test]
    public function constructor_validates_zero_timeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be positive, got: 0');

        new Client('valid-api-key', 0);
    }

    #[Test]
    public function constructor_trims_api_key(): void
    {
        $client = new Client('  valid-api-key  ', 30);

        // Use reflection to access private property for testing
        $reflection = new \ReflectionClass($client);
        $apiKeyProperty = $reflection->getProperty('apiKey');
        $apiKeyProperty->setAccessible(true);

        $this->assertEquals('valid-api-key', $apiKeyProperty->getValue($client));
    }

    #[Test]
    public function constructor_with_valid_parameters(): void
    {
        $client = new Client('valid-api-key', 60);

        $this->assertInstanceOf(Client::class, $client);
    }
}
