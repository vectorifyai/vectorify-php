<?php

namespace Vectorify\Tests\Unit;

use Vectorify\Objects\CollectionObject;
use Vectorify\Tests\TestCase;

class CollectionObjectTest extends TestCase
{
    public function test_can_create_collection_object(): void
    {
        $collection = new CollectionObject('test-slug', ['key' => 'value']);

        $this->assertEquals('test-slug', $collection->slug);
        $this->assertEquals(['key' => 'value'], $collection->metadata);
    }

    public function test_can_convert_to_array(): void
    {
        $collection = new CollectionObject('test-slug', ['key' => 'value']);

        $expected = [
            'slug' => 'test-slug',
            'metadata' => ['key' => 'value'],
        ];

        $this->assertEquals($expected, $collection->toArray());
    }

    public function test_can_convert_to_payload(): void
    {
        $collection = new CollectionObject('test-slug', ['key' => 'value']);

        $expected = [
            'slug' => 'test-slug',
            'metadata' => ['key' => 'value'],
        ];

        $this->assertEquals($expected, $collection->toPayload());
    }
}
