<?php

declare(strict_types=1);

namespace App\Services\ObfuscationRecovery;

final class RecoveryLegacyDownload
{
    public function __construct(private readonly RecoveryHeadIndex $index, private readonly RecoveryHeads $heads,
        private readonly RecoveryEvidence $evidence) {}

    /** @param array<int|string,mixed>|string $messageIds
     * @return array{success:bool,data:?string,groupUnavailable:bool,error:?string,crcFailures:int,crcFailed:bool}
     */
    public function read(object $publication, array|string $messageIds): array
    {
        $result = ['success' => false, 'data' => null, 'groupUnavailable' => false,
            'error' => 'recovery_scoped_reader_required', 'crcFailures' => 0, 'crcFailed' => false];
        if ($publication->state !== 'published' || $publication->deleted_at !== null) {
            return $result;
        }
        $ids = [];
        foreach (is_array($messageIds) ? $messageIds : [$messageIds] as $id) {
            if (! is_string($id)) {
                return $result;
            }
            $ids[] = (new RecoveryIdentity)->messageId($id);
        }
        if ($ids === [$publication->index_message_id]) {
            $cached = $this->evidence->get($publication->index_message_id);
            if ($cached !== null) {
                $result['success'] = true;
                $result['data'] = $cached->data;
                $result['error'] = null;
            }

            return $result;
        }
        if ($ids === [] || count($ids) > 16) {
            return $result;
        }
        foreach ($this->index->forPublication($publication) as $fileId => $records) {
            if (array_diff($ids, array_column($records, 'message_id')) !== []) {
                continue;
            }
            $head = $this->heads->read((int) $publication->releases_id, $fileId);
            if ($head->pending()) {
                $result['error'] = 'recovery_evidence_pending';
            }

            return $result;
        }

        return $result;
    }
}
