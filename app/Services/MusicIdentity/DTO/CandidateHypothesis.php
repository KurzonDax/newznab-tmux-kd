<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\DTO;

final readonly class CandidateHypothesis
{
    /** @param list<CandidateSignal> $signals */
    public function __construct(
        public CandidateIdentity $identity,
        public CandidateMetadata $metadata,
        public array $signals,
    ) {}

    public function distinctRecordingSupport(): int
    {
        return count($this->recordingIds());
    }

    public function uniqueRecordingId(): ?string
    {
        $recordingIds = $this->recordingIds();

        return count($recordingIds) === 1 ? $recordingIds[0] : null;
    }

    /** @return list<string> */
    private function recordingIds(): array
    {
        $recordingIds = array_filter(array_map(
            static fn (CandidateSignal $signal): ?string => $signal->identity->recordingId,
            $this->signals,
        ));

        return array_values(array_unique($recordingIds));
    }

    public function independentEvidenceSupport(): int
    {
        return count(array_unique(array_map(
            static fn (CandidateSignal $signal): string => $signal->provenanceFamily,
            $this->signals,
        )));
    }

    public function independentRecordingSupport(): int
    {
        /** @var array<string, list<string>> $recordingsByFamily */
        $recordingsByFamily = [];
        foreach ($this->signals as $signal) {
            if ($signal->identity->recordingId === null) {
                continue;
            }
            $recordingsByFamily[$signal->provenanceFamily][] = $signal->identity->recordingId;
        }

        $recordingToFamily = [];
        $matched = 0;
        foreach (array_keys($recordingsByFamily) as $family) {
            if ($this->matchRecordingFamily($family, $recordingsByFamily, $recordingToFamily, [])) {
                $matched++;
            }
        }

        return $matched;
    }

    /**
     * @param  array<string, list<string>>  $recordingsByFamily
     * @param  array<string, string>  $recordingToFamily
     * @param  array<string, true>  $visitedRecordings
     */
    private function matchRecordingFamily(
        string $family,
        array $recordingsByFamily,
        array &$recordingToFamily,
        array $visitedRecordings,
    ): bool {
        foreach (array_unique($recordingsByFamily[$family]) as $recordingId) {
            if (isset($visitedRecordings[$recordingId])) {
                continue;
            }
            $visitedRecordings[$recordingId] = true;
            if (! isset($recordingToFamily[$recordingId])
                || $this->matchRecordingFamily(
                    $recordingToFamily[$recordingId],
                    $recordingsByFamily,
                    $recordingToFamily,
                    $visitedRecordings,
                )) {
                $recordingToFamily[$recordingId] = $family;

                return true;
            }
        }

        return false;
    }
}
