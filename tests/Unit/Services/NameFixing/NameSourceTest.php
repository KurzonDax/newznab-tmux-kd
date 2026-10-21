<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NameFixing;

use App\Services\NameFixing\NameSource;
use App\Services\NameFixing\ReleaseUpdateService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NameSourceTest extends TestCase
{
    /** @return iterable<string, array{NameSource}> */
    public static function sources(): iterable
    {
        foreach (NameSource::cases() as $source) {
            yield $source->value => [$source];
        }
    }

    #[DataProvider('sources')]
    public function test_the_stored_name_source_is_the_type_label_without_its_separator(NameSource $source): void
    {
        $this->assertSame(ReleaseUpdateService::nameSource($source->type()), $source->storedName());
    }

    #[DataProvider('sources')]
    public function test_sources_sharing_a_type_share_its_miss_column(NameSource $source): void
    {
        $this->assertSame(NameSource::missColumnForType($source->type()), $source->missColumn());
    }

    public function test_a_type_no_source_has_marks_no_miss(): void
    {
        $this->assertNull(NameSource::missColumnForType('sorter, '));
        $this->assertNull(NameSource::missColumnForType('file matched source: '));
    }
}
