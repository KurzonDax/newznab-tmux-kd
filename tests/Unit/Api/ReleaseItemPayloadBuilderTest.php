<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use App\Services\Api\V1\ReleaseItemPayloadBuilder;
use PHPUnit\Framework\TestCase;

final class ReleaseItemPayloadBuilderTest extends TestCase
{
    public function test_info_attribute_links_to_the_v1_nfo_function_with_the_api_key(): void
    {
        $payload = $this->builder()->build($this->release(['nfostatus' => 1]));

        self::assertSame(
            'https://example.test/api/v1/api?t=info&id=release-guid&apikey=abc',
            $payload['attr']['info'],
        );
    }

    public function test_release_without_an_nfo_has_no_info_attribute(): void
    {
        foreach ([0, -1, null] as $nfoStatus) {
            $payload = $this->builder()->build($this->release(['nfostatus' => $nfoStatus]));

            self::assertArrayNotHasKey('info', $payload['attr']);
        }
    }

    private function builder(): ReleaseItemPayloadBuilder
    {
        return new ReleaseItemPayloadBuilder(
            ['token' => 'abc', 'extended' => '1'],
            ['server' => ['url' => 'https://example.test']],
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function release(array $overrides): object
    {
        return (object) array_merge([
            'searchname' => 'Clean Title',
            'guid' => 'release-guid',
            'adddate' => '2026-05-29 12:00:00',
            'category_name' => 'Movies > HD',
            'categories_id' => 2040,
            'size' => 123456789,
        ], $overrides);
    }
}
