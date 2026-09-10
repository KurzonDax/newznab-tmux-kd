<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CollectionReconciliation\PostingFile;
use App\Services\CollectionReconciliation\PostingHypotheses;
use App\Services\CollectionReconciliation\PostingResolver;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reconciliation\Par2Fixture;

class PostingHypothesesTest extends TestCase
{
    public function test_two_bases_cannot_win_shared_payload_by_iteration_order(): void
    {
        [$files, $heads, $metadata] = $this->fixture(['shared.mkv', 'A.par2', 'B.par2']);
        foreach ([$files, array_reverse($files), [$files[1], $files[0], $files[2]]] as $ordered) {
            $decisions = (new PostingHypotheses)->resolve($ordered,
                static fn (array $hypothesis) => (new PostingResolver)->resolve($hypothesis, $heads, $metadata));
            $this->assertCount(2, $decisions);
            foreach ($decisions as $decision) {
                $this->assertSame([], $decision->accepted);
                $this->assertSame('competing_hypothesis', $decision->reason);
            }
        }
    }

    public function test_disjoint_proved_sets_can_share_a_discovery_population(): void
    {
        [$files, $heads, $metadata] = $this->fixture(['a.mkv', 'A.par2', 'b.mkv', 'B.par2']);
        foreach ([$files, array_reverse($files)] as $ordered) {
            $decisions = (new PostingHypotheses)->resolve($ordered,
                static fn (array $hypothesis) => (new PostingResolver)->resolve($hypothesis, $heads, $metadata));
            $this->assertCount(2, $decisions);
            foreach ($decisions as $decision) {
                $this->assertTrue($decision->complete());
            }
        }
    }

    /** @param list<string> $names */
    private function fixture(array $names): array
    {
        $files = $heads = $metadata = [];
        foreach ($names as $index => $name) {
            $id = '<'.$index.'@example.invalid>';
            $isPar2 = str_ends_with($name, '.par2');
            $subject = sprintf('[%02d/02] - "%s" yEnc (1/1)', $isPar2 ? 2 : 1, $name);
            $files[] = new PostingFile((string) $index, (string) $index, $subject, 'example.group', 'Synthetic Poster', 1767268800, 1,
                [['number' => 1, 'messageid' => $id, 'bytes' => 5]]);
            $heads[$id] = "From: Synthetic Poster\r\nSubject: {$subject}\r\nDate: Thu, 01 Jan 2026 12:00:00 +0000\r\nMessage-ID: {$id}\r\n";
            if ($isPar2) {
                $payload = in_array('shared.mkv', $names, true) ? 'shared.mkv' : strtolower($name[0]).'.mkv';
                $metadata[$id] = Par2Fixture::metadata([$payload => 'video']);
            }
        }

        return [$files, $heads, $metadata];
    }
}
