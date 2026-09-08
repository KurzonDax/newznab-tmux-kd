<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CollectionReconciliation\PostingFile;
use App\Services\CollectionReconciliation\PostingResolver;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reconciliation\Par2Fixture;

class PostingResolverTest extends TestCase
{
    public function test_the_course_set_resolves_all_thirty_three_files_from_seventeen_sources(): void
    {
        [$files, $heads, $bodies] = $this->course();
        $decision = (new PostingResolver)->resolve($files, $heads, $bodies);
        $this->assertCount(33, $decision->accepted);
        $this->assertCount(17, $decision->sources());
        $this->assertSame(100.0, $decision->completion());
        $this->assertSame('Course.Set', $decision->label);
        $this->assertTrue($decision->independentVideos());
    }

    public function test_missing_headers_exclude_members_and_keep_completion_honest(): void
    {
        [$files, $heads, $bodies] = $this->course();
        unset($heads[$files[0]->firstArticle()]);
        $decision = (new PostingResolver)->resolve($files, $heads, $bodies);
        $this->assertCount(32, $decision->accepted);
        $this->assertLessThan(100, $decision->completion());
        $this->assertArrayHasKey('1', $decision->unresolved);
    }

    public function test_identical_reposts_and_duplicate_ordinals_never_become_members(): void
    {
        [$files, $heads, $bodies] = $this->course();
        $first = $files[0];
        $id = '<repost@example.invalid>';
        $repost = new PostingFile('repost', 'repost', $first->subject, $first->group, $first->poster, $first->date, 1,
            [['number' => 1, 'messageid' => $id, 'bytes' => 64]]);
        $heads[$id] = str_replace($first->firstArticle(), $id, $heads[$first->firstArticle()]);
        $probes = [$id => ['length' => 64, 'prefix' => md5(str_repeat(chr(1), 64)), 'full' => md5(str_repeat(chr(1), 64))],
            $first->firstArticle() => ['length' => 64, 'prefix' => md5(str_repeat(chr(1), 64)), 'full' => md5(str_repeat(chr(1), 64))]];
        $decision = (new PostingResolver)->resolve([...$files, $repost], $heads, $bodies, $probes);
        $this->assertCount(32, $decision->accepted);
        $this->assertSame('ambiguous_payload', $decision->unresolved['1']);
        $this->assertSame('ambiguous_payload', $decision->unresolved['repost']);
        $this->assertLessThan(100, $decision->completion());
        $probes[$id]['prefix'] = md5('different bytes');
        $decision = (new PostingResolver)->resolve([...$files, $repost], $heads, $bodies, $probes);
        $this->assertCount(33, $decision->accepted);
    }

    public function test_another_poster_and_a_foreign_par2_set_cannot_be_absorbed(): void
    {
        [$files, $heads, $bodies] = $this->course();
        $heads[$files[0]->firstArticle()] = str_replace('Synthetic Poster A', 'Synthetic Poster B', $heads[$files[0]->firstArticle()]);
        $bodies[$files[32]->firstArticle()] = Par2Fixture::metadata(['unrelated.mkv' => 'other bytes']);
        $decision = (new PostingResolver)->resolve($files, $heads, $bodies);
        $this->assertSame([], $decision->accepted, 'An invalid companion prevents claiming the source containing the base PAR2.');
    }

    public function test_missing_tracks_retain_the_original_declared_total(): void
    {
        $files = $heads = $bodies = [];
        $names = ['01-example-show.mp3', '02-example-show.mp3', 'Tracks.par2'];
        $bodies['<track-2@example.invalid>'] = Par2Fixture::metadata([$names[0] => 'a', $names[1] => 'b']);
        foreach ($names as $index => $name) {
            $id = '<track-'.$index.'@example.invalid>';
            $subject = sprintf('[%02d/12] - "%s" yEnc (1/142)', $index + 3, $name);
            $segments = [];
            for ($part = 1; $part <= 142; $part++) {
                $segments[] = ['number' => $part, 'messageid' => $part === 1 ? $id : '<track-'.$index.'-'.$part.'@example.invalid>', 'bytes' => 100];
            }
            $files[] = new PostingFile((string) $index, (string) $index, $subject, 'alt.binaries.boneless', 'Synthetic Poster A', 1767268800, 142, $segments);
            $heads[$id] = "From: Synthetic Poster A\r\nSubject: {$subject}\r\nDate: Thu, 01 Jan 2026 12:00:00 +0000\r\nMessage-ID: {$id}\r\n";
        }
        $decision = (new PostingResolver)->resolve($files, $heads, $bodies);
        $this->assertSame(12, $decision->declaredTotal);
        $this->assertSame(25.0, $decision->completion());
        $this->assertSame([], (new PostingResolver)->resolve(array_slice($files, 0, 2), $heads, [])->accepted);
    }

    public function test_unlisted_text_and_extensionless_members_exclude_their_entire_mixed_source(): void
    {
        foreach (['readme.txt', 'opaque'] as $name) {
            [$files, $heads, $bodies] = $this->course();
            $first = $files[0];
            $subject = str_replace($first->filename, $name, $first->subject);
            $files[0] = new PostingFile('16', $first->fileId, $subject, $first->group, $first->poster, $first->date, 1, $first->segments);
            $heads[$first->firstArticle()] = str_replace($first->subject, $subject, $heads[$first->firstArticle()]);
            $decision = (new PostingResolver)->resolve($files, $heads, $bodies);
            $this->assertSame('unlisted_filename', $decision->unresolved['1']);
            $this->assertNotContains('16', $decision->sources());
            $this->assertNotEmpty($decision->accepted);
            $this->assertLessThan(100, $decision->completion());
        }
    }

    public function test_conflicting_ordinals_and_population_overflow_are_never_silently_truncated(): void
    {
        [$files, $heads, $bodies] = $this->course();
        $first = $files[0];
        $subject = str_replace('[01/33]', '[02/33]', $first->subject);
        $files[0] = new PostingFile($first->sourceId, $first->fileId, $subject, $first->group, $first->poster, $first->date, 1, $first->segments);
        $heads[$first->firstArticle()] = str_replace($first->subject, $subject, $heads[$first->firstArticle()]);
        $decision = (new PostingResolver)->resolve($files, $heads, $bodies);
        $this->assertSame('conflicting_ordinal', $decision->unresolved['1']);
        $this->assertSame('conflicting_ordinal', $decision->unresolved['2']);
        $overflow = array_merge(...array_fill(0, 32, $files));
        $this->assertSame('population_limit', (new PostingResolver)->resolve($overflow, $heads, $bodies)->reason);
    }

    /** @return array{list<PostingFile>, array<string, string>, array<string, string>} */
    private function course(): array
    {
        $payloads = Par2Fixture::course();
        $manifest = Par2Fixture::metadata($payloads);
        $payloads['Course.Set.par2'] = $manifest;
        for ($i = 0; $i < 10; $i++) {
            $payloads[sprintf('Course.Set.vol%03d+001.par2', $i)] = $manifest;
        }
        $files = $heads = $bodies = [];
        $i = 0;
        foreach ($payloads as $name => $data) {
            $i++;
            $id = sprintf('<fixture-%06d@example.invalid>', $i);
            $subject = sprintf('[%02d/33] - "%s" yEnc (1/1)', $i, $name);
            $file = new PostingFile((string) min($i, $i <= 22 ? 16 : 17), (string) $i,
                $subject, 'alt.binaries.boneless', 'Synthetic Poster A', 1767268800, 1,
                [['number' => 1, 'messageid' => $id, 'bytes' => strlen($data)]]);
            $files[] = $file;
            $heads[$id] = "From: Synthetic Poster A\r\nSubject: {$subject}\r\nDate: Thu, 01 Jan 2026 12:00:00 +0000\r\nMessage-ID: {$id}\r\n";
            if ($file->isPar2()) {
                $bodies[$id] = $data;
            }
        }

        return [$files, $heads, $bodies];
    }
}
