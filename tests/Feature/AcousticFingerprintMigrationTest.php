<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProductionTables;
use Tests\TestCase;

final class AcousticFingerprintMigrationTest extends TestCase
{
    private const array COLUMNS = [
        'fingerprint',
        'fingerprint_hash',
        'fingerprint_algorithm',
        'fingerprint_generator_version',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge();
        DB::reconnect();

        ProductionTables::fromAuthority()->create('releases', ['id']);
        $this->migration('*_create_release_audio_evidence_tables.php')->up();
        ProductionTables::fromAuthority()->create('service_statuses');
    }

    #[Test]
    public function it_adds_the_fingerprint_columns_and_the_status_probe_entry_and_rolls_back_only_its_own(): void
    {
        DB::table('service_statuses')->insert([
            'name' => 'MusicBrainz', 'slug' => 'musicbrainz', 'check_type' => 'probe',
            'probe_identifier' => 'musicbrainz', 'status' => 'operational', 'is_enabled' => true, 'sort_order' => 4,
        ]);

        $this->migration('*_add_acoustic_fingerprints_to_release_audio_evidence_tracks.php')->up();

        $this->assertTrue(Schema::hasColumns('release_audio_evidence_tracks', self::COLUMNS));
        $this->assertDatabaseHas('service_statuses', [
            'slug' => 'chromaprint',
            'name' => 'Chromaprint',
            'check_type' => 'probe',
            'probe_identifier' => 'chromaprint',
            'is_enabled' => true,
            'sort_order' => 5,
        ]);

        $this->migration('*_add_acoustic_fingerprints_to_release_audio_evidence_tracks.php')->down();

        foreach (self::COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('release_audio_evidence_tracks', $column));
        }
        $this->assertDatabaseMissing('service_statuses', ['slug' => 'chromaprint']);
        $this->assertDatabaseHas('service_statuses', ['slug' => 'musicbrainz']);
    }

    private function migration(string $pattern): Migration
    {
        $paths = glob(database_path('migrations/'.$pattern)) ?: [];
        $this->assertCount(1, $paths);

        /** @var Migration $migration */
        $migration = require $paths[0];

        return $migration;
    }
}
