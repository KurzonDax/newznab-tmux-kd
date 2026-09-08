<?php

declare(strict_types=1);

namespace Tests\Feature\ObfuscationRecovery;

use App\Services\ObfuscationRecovery\RecoveryArtifacts;
use RuntimeException;
use Tests\TestCase;

final class RecoveryArtifactsTest extends TestCase
{
    public function test_immutable_artifacts_are_streamed_verified_and_reused_by_content(): void
    {
        $store = new RecoveryArtifacts($this->makeTempDirectory('recovery-artifacts'));
        $artifact = $store->put((static function (): iterable {
            yield 'first';
            yield 'second';
        })(), 64);
        $this->assertSame(hash('sha256', 'firstsecond'), $artifact->digest);
        $this->assertSame(11, $artifact->bytes);
        $this->assertSame('firstsecond', implode('', iterator_to_array($store->read($artifact))));
        $this->assertSame($artifact->digest, $store->put(['firstsecond'], 64)->digest);
    }

    public function test_corrupt_artifacts_cannot_be_used_for_publication(): void
    {
        $root = $this->makeTempDirectory('recovery-artifacts');
        $store = new RecoveryArtifacts($root);
        $artifact = $store->put(['evidence'], 64);
        file_put_contents($root.'/'.$artifact->digest, 'corrupt');
        $this->expectException(RuntimeException::class);
        iterator_to_array($store->read($artifact));
    }

    public function test_exceeding_a_stream_limit_leaves_no_partial_artifact(): void
    {
        $root = $this->makeTempDirectory('recovery-artifacts');
        $store = new RecoveryArtifacts($root);
        try {
            $store->put(['small', str_repeat('x', 100)], 10);
            $this->fail('Oversized evidence must not be retained.');
        } catch (RuntimeException) {
            $this->assertSame([], array_values(array_diff(scandir($root), ['.', '..', '.lock', '.journal.sqlite'])));
        }
    }
}
