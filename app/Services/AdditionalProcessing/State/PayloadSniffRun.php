<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing\State;

use App\Services\AdditionalProcessing\DTO\ArchiveCandidate;
use App\Services\Par2Sidecar\SidecarEvidence;

/**
 * What one release's unknown-payload sniffing has gathered so far: the
 * handling it deferred, every article it holds, and how much of the
 * split-archive search's limits it has spent.
 */
final class PayloadSniffRun
{
    /** @var list<array{payload: string, candidate: ArchiveCandidate}> */
    public array $deferredArchives = [];

    /** @var list<string> */
    public array $deferredPar2 = [];

    public readonly SidecarEvidence $sidecarEvidence;

    public bool $firstVideoHandled = false;

    /** Whether the selected candidates held a RAR, ZIP, 7z, Matroska, MP4 or AVI file. */
    public bool $foundContainer = false;

    /**
     * Every successful sniff result, by message-id, so no article is fetched
     * twice while sniffing and inspection run.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $held = [];

    /**
     * Every first segment whose download was tried, held or not.
     *
     * @var array<string, true>
     */
    public array $attempted = [];

    /** Files the split-archive search and last-part lookup have sniffed. */
    public int $searchFiles = 0;

    /** Their estimated first-segment bytes. */
    public int $searchBytes = 0;

    public function __construct()
    {
        $this->sidecarEvidence = new SidecarEvidence;
    }
}
