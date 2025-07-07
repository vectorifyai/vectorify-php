<?php

namespace Vectorify\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Vectorify\Objects\CollectionObject;
use Vectorify\Tests\TestCase;

#[CoversClass(CollectionObject::class)]
class CollectionObjectTest extends TestCase
{
    #[Test]
    public function can_create_collection_object(): void
    {
        $collection = new CollectionObject('test-slug', ['key' => 'value']);

        $this->assertEquals('test-slug', $collection->slug);
        $this->assertEquals(['key' => 'value'], $collection->metadata);
    }

    #[Test]
    public function can_convert_to_array(): void
    {
        $collection = new CollectionObject('test-slug', ['key' => 'value']);

        $expected = [
            'slug' => 'test-slug',
            'metadata' => ['key' => 'value'],
        ];

        $this->assertEquals($expected, $collection->toArray());
    }

    #[Test]
    public function can_convert_to_payload(): void
    {
        $collection = new CollectionObject('test-slug', ['key' => 'value']);

        $expected = [
            'slug' => 'test-slug',
            'metadata' => ['key' => 'value'],
        ];

        $this->assertEquals($expected, $collection->toPayload());
    }
}
