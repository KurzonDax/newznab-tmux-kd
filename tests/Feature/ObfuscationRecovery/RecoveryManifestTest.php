<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryArtifacts;
use App\Services\ObfuscationRecovery\RecoveryFilePlan;
use App\Services\ObfuscationRecovery\RecoveryFileRole;
use App\Services\ObfuscationRecovery\RecoveryManifest;
use Tests\TestCase;

final class RecoveryManifestTest extends TestCase
{
    public function test_manifest_preserves_source_ordinals_and_all_source_fields_without_body_ordering(): void
    {
        $artifacts = new RecoveryArtifacts($this->makeTempDirectory('manifest'));
        $manifest = new RecoveryManifest($artifacts);
        $files = $this->files();
        $source = fn (RecoveryFilePlan $file): array => $file->role === RecoveryFileRole::Index
            ? [$this->row('index@local', 40, 400)]
            : [$this->row('first@local', 10, 100), $this->row('true-three@local', 20, 200), $this->row('true-two@local', 30, 300)];
        $artifact = $manifest->write($files, 'group.fixture', 'epoch', 1, 1, $source);
        $records = iterator_to_array($manifest->read($artifact));
        $payload = array_values(array_filter($records, fn (array $row): bool => $row['file'] === str_repeat('a', 32)));
        $this->assertSame(['first@local', 'true-three@local', 'true-two@local'], array_column($payload, 'message_id'));
        $this->assertSame([1, 2, 3], array_column($payload, 'ordinal'));
        $this->assertSame([10, 20, 30], array_column($payload, 'article_number'));
        $this->assertSame([100, 200, 300], array_column($payload, 'advertised_bytes'));
        $this->assertSame('source subject', base64_decode($payload[0]['raw_subject']));
        $this->assertSame('group.fixture', $payload[0]['group']);
        $this->assertEquals($artifact, $manifest->write(array_reverse($files), 'group.fixture', 'epoch', 1, 1, $source));
        $changed = $manifest->write($files, 'group.fixture', 'epoch', 1, 1, fn (RecoveryFilePlan $file): array => array_map(static function (object $row): object {
            $row->advertised_bytes++;

            return $row;
        }, $source($file)));
        $this->assertNotSame($artifact->digest, $changed->digest);
    }

    public function test_duplicate_segment_ids_across_files_cannot_be_sealed(): void
    {
        $manifest = new RecoveryManifest(new RecoveryArtifacts($this->makeTempDirectory('manifest')));
        $this->expectExceptionMessage('overlapping_manifest_membership');
        $manifest->write($this->files(), 'group.fixture', 'epoch', 1, 1, fn (RecoveryFilePlan $file): array => $file->role === RecoveryFileRole::Index ? [$this->row('index@local', 40, 400)] :
                [$this->row('index@local', 10, 100), $this->row('two@local', 20, 200), $this->row('three@local', 30, 300)]);
    }

    public function test_manifest_cursor_reads_bounded_verified_blocks_and_detects_corruption_in_the_selected_block(): void
    {
        $root = $this->makeTempDirectory('manifest-pages');
        $artifacts = new RecoveryArtifacts($root);
        $manifest = new RecoveryManifest($artifacts);
        $files = [new RecoveryFilePlan(str_repeat('a', 32), RecoveryFileRole::Media, RecoveryFilePlan::CHUNK_BYTES * 1000 + 1, 1001, 'file.mkv', 'mkv'),
            new RecoveryFilePlan('index@local', RecoveryFileRole::Index, 300, 1, 'recovery.par2', 'par2')];
        $artifact = $manifest->write($files, 'group.fixture', 'epoch', 1, 1, function (RecoveryFilePlan $file): \Generator {
            if ($file->role === RecoveryFileRole::Index) {
                yield $this->row('index@local', 2000, 300);

                return;
            }
            foreach (range(1, 1001) as $i) {
                yield $this->row('part-'.$i.'@local', $i, 1000);
            }
        });
        $first = $manifest->page($artifact, 0);
        $second = $manifest->page($artifact, $first['offset']);
        $third = $manifest->page($artifact, $second['offset']);
        $this->assertCount(500, $first['records']);
        $this->assertCount(500, $second['records']);
        $this->assertCount(2, $third['records']);
        $this->assertSame(501, $second['records'][0]['ordinal']);
        $this->assertSame($artifact->bytes, $third['offset']);
        $stream = fopen($root.'/'.$artifact->digest, 'r+b');
        fseek($stream, $artifact->bytes - 20);
        fwrite($stream, '!');
        fclose($stream);
        $this->assertEquals($first, $manifest->page($artifact, 0));
        $this->expectExceptionMessage('artifact_integrity_failure');
        $manifest->page($artifact, $second['offset']);
    }

    /** @return list<RecoveryFilePlan> */
    private function files(): array
    {
        return [new RecoveryFilePlan(str_repeat('a', 32), RecoveryFileRole::Media, 1500000, 3, 'file.mkv', 'mkv'),
            new RecoveryFilePlan('index@local', RecoveryFileRole::Index, 300, 1, 'recovery.par2', 'par2')];
    }

    private function row(string $id, int $number, int $bytes): object
    {
        return (object) ['message_id' => $id, 'source_message_id' => '<'.$id.'>', 'article_number' => $number,
            'advertised_bytes' => $bytes, 'embedded_timestamp_ms' => $number * 1000, 'source_epoch' => 'epoch',
            'capture_generation' => 1, 'groups_id' => 1, 'raw_subject' => 'source subject', 'poster_identity' => 'poster',
            'source_date' => '2026-01-01T00:00:00Z', 'postdate' => '2026-01-01 00:00:00', 'xref' => '', 'metadata_conflict' => false];
    }
}
