<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Enums\PredbSearchStatus;
use App\Models\Category;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class NameFixingQueryService
{
    public const SOURCE_NFO = 'nfo';

    public const SOURCE_FILES = 'files';

    public const SOURCE_SRR = 'srr';

    public const SOURCE_CRC = 'crc';

    public const SOURCE_UID = 'uid';

    public const SOURCE_HASH = 'hash';

    public const SOURCE_PAR2 = 'par2';

    public const SOURCE_XXX = 'xxx';

    public const SOURCE_MEDIA_MOVIE = 'media_movie';

    public const SOURCE_SRRDB = 'srrdb';

    public const BATCH_SIZE = 1000;

    private const TRUSTED_DONOR_PREDICATE = "(r.predb_id > 0 OR COALESCE(NULLIF(r.anidbid, ''), 0) > 0 OR r.is_trusted_name = 1)";

    /**
     * SRRDB never re-derives a name the indexer already trusts. Shared by the
     * windowed candidate query and the standard sweep so the two cannot drift.
     */
    private const SRRDB_TRUST_PREDICATE = 'r.is_trusted_name = 0';

    private ConnectionInterface $database;

    /**
     * @var array<string, string>
     */
    private const STATUS_COLUMNS = [
        self::SOURCE_NFO => 'proc_nfo',
        self::SOURCE_FILES => 'proc_files',
        self::SOURCE_SRR => 'proc_srr',
        self::SOURCE_CRC => 'proc_crc32',
        self::SOURCE_UID => 'proc_uid',
        self::SOURCE_HASH => 'proc_hash16k',
        self::SOURCE_PAR2 => 'proc_par2',
        self::SOURCE_XXX => 'proc_xxx',
        self::SOURCE_MEDIA_MOVIE => 'proc_media_movie',
        self::SOURCE_SRRDB => 'proc_srrdb',
    ];

    /**
     * @var array<string, string>
     */
    private const SOURCE_EXISTS = [
        self::SOURCE_NFO => 'EXISTS (SELECT 1 FROM release_nfos source_nfo WHERE source_nfo.releases_id = r.id)',
        self::SOURCE_FILES => 'EXISTS (SELECT 1 FROM release_files source_file WHERE source_file.releases_id = r.id)',
        self::SOURCE_SRR => "EXISTS (SELECT 1 FROM release_files source_srr WHERE source_srr.releases_id = r.id AND (source_srr.name LIKE '%.srr' OR source_srr.name LIKE '%.srs'))",
        self::SOURCE_CRC => "EXISTS (SELECT 1 FROM release_files source_crc WHERE source_crc.releases_id = r.id AND source_crc.crc32 IS NOT NULL AND source_crc.crc32 != '')",
        self::SOURCE_UID => "EXISTS (SELECT 1 FROM media_infos source_media WHERE source_media.releases_id = r.id AND source_media.unique_id IS NOT NULL AND source_media.unique_id != '')",
        self::SOURCE_HASH => "EXISTS (SELECT 1 FROM par_hashes source_hash WHERE source_hash.releases_id = r.id AND source_hash.hash != '')",
        self::SOURCE_PAR2 => 'r.nzbstatus = 1',
        self::SOURCE_XXX => "EXISTS (SELECT 1 FROM release_files source_xxx WHERE source_xxx.releases_id = r.id AND source_xxx.name LIKE '%SDPORN%')",
        self::SOURCE_MEDIA_MOVIE => "EXISTS (SELECT 1 FROM media_infos source_movie WHERE source_movie.releases_id = r.id AND source_movie.movie_name IS NOT NULL AND source_movie.movie_name != '')",
        self::SOURCE_SRRDB => 'EXISTS (SELECT 1 FROM release_files source_srrdb WHERE source_srrdb.releases_id = r.id AND LENGTH(source_srrdb.crc32) = 8)',
    ];

    public function __construct(?ConnectionInterface $database = null)
    {
        $this->database = $database ?? DB::connection();
    }

    /**
     * @return list<object>
     */
    public function candidateBatch(
        string $source,
        int $time,
        int $categories,
        int $afterId = 0,
        int $limit = self::BATCH_SIZE
    ): array {
        [$where, $bindings] = $this->candidateWhere($source, $time, $categories);
        $sourceColumns = $source === self::SOURCE_SRRDB ? ', r.completion' : '';
        $bindings[] = $afterId;
        $bindings[] = max(1, $limit);

        return $this->database->select(
            "SELECT r.id AS releases_id, r.id, r.name, r.searchname, r.fromname, r.groups_id,
                    r.categories_id, r.size AS relsize, r.guid, r.predb_id, r.nfostatus,
                    r.proc_nfo, r.proc_files, r.proc_par2, r.proc_uid, r.proc_srr,
                    r.proc_hash16k, r.proc_crc32{$sourceColumns}
             FROM releases r
             WHERE {$where}
             AND r.id > ?
             ORDER BY r.id ASC
             LIMIT ?",
            $bindings
        );
    }

    public function countCandidates(string $source, int $time, int $categories): int
    {
        [$where, $bindings] = $this->candidateWhere($source, $time, $categories);
        $rows = $this->database->select(
            "SELECT COUNT(*) AS aggregate FROM releases r WHERE {$where}",
            $bindings
        );

        return (int) ($rows[0]->aggregate ?? 0);
    }

    /**
     * One GUID-partitioned page of the standard sweep's admitted releases.
     *
     * @return list<object>
     */
    public function standardCandidateBatch(string $leftGuid, int $limit): array
    {
        return $this->database->select(
            'SELECT r.id AS releases_id, r.id, r.name, r.searchname, r.fromname, r.guid,
                    r.groups_id, r.categories_id, r.size AS relsize, r.completion, r.predb_id,
                    r.nfostatus, r.is_trusted_name, r.proc_nfo, r.proc_uid, r.proc_files,
                    r.proc_xxx, r.proc_media_movie, r.proc_par2, r.proc_hash16k,
                    r.proc_srr, r.proc_crc32, r.proc_srrdb
             FROM releases r
             WHERE r.leftguid = ?
             AND '.$this->standardSweepPredicate().'
             ORDER BY r.id DESC
             LIMIT ?',
            [$leftGuid, max(1, $limit)]
        );
    }

    /**
     * How many releases the standard sweep would admit across every GUID bucket.
     *
     * This is the tmux Fix Names pane's wake-up gate. It shares
     * {@see self::standardSweepPredicate()} with {@see self::standardCandidateBatch()}
     * so the pane sleeps exactly when the sweep has nothing to do -- the
     * hand-written copy it replaced counted only three of the sweep's sources
     * and let the pane sleep on real UID/SRR/hash/CRC work.
     */
    public function standardCandidateCount(): int
    {
        $rows = $this->database->select(
            'SELECT COUNT(*) AS aggregate FROM releases r WHERE '.$this->standardSweepPredicate()
        );

        return (int) ($rows[0]->aggregate ?? 0);
    }

    /**
     * The single definition of the standard sweep's admission predicate.
     *
     * Admission is per-source readiness only: the release must be unrenamed and
     * carry no PreDB identity, and at least one evidence source must be both
     * ready and unconsumed. Deliberately absent are the cross-source gates and
     * the category restriction that used to sit in front of the OR list -- a
     * pending or failed NFO lookup, an unsettled password inspection, or a typed
     * root category must not hide another source's evidence
     * (docs/architecture/release-lifecycle-gaps.md, G1a/G1b/G31).
     *
     * Sources whose evidence may not exist yet carry their own readiness:
     * NFO requires a stored NFO, PAR2 requires a written NZB, and the dedicated
     * XXX/media-movie terms require their matching file or media-info row. SRRDB
     * also requires an archive CRC, an untrusted name, and an enabled source.
     * These gates keep the pane asleep until the worker can record an honest
     * verdict.
     */
    private function standardSweepPredicate(): string
    {
        $sources = [
            '(r.nfostatus = 1 AND r.proc_nfo = 0)',
            'r.proc_files = 0',
            '(r.proc_xxx = 0 AND '.self::SOURCE_EXISTS[self::SOURCE_XXX].')',
            'r.proc_uid = 0',
            '(r.proc_media_movie = 0 AND '.self::SOURCE_EXISTS[self::SOURCE_MEDIA_MOVIE].')',
            '(r.nzbstatus = 1 AND r.proc_par2 = 0)',
            'r.proc_srr = 0',
            'r.proc_hash16k = 0',
            'r.proc_crc32 = 0',
        ];

        if ($this->srrdbEnabled()) {
            $sources[] = sprintf(
                '(r.proc_srrdb = 0 AND %s AND %s)',
                self::SRRDB_TRUST_PREDICATE,
                self::SOURCE_EXISTS[self::SOURCE_SRRDB]
            );
        }

        return 'r.isrenamed = 0 AND r.predb_id = 0 AND ('.implode(' OR ', $sources).')';
    }

    /**
     * Whether SRRDB is configured at all. The sweep's predicate and the worker
     * leg that settles `proc_srrdb` must agree on this, so both read it here.
     */
    public function srrdbEnabled(): bool
    {
        return (bool) config('nntmux_srrdb.enabled', false);
    }

    /**
     * @param  list<int>  $releaseIds
     * @return list<object>
     */
    public function nfoRows(array $releaseIds): array
    {
        return $this->selectForReleaseIds(
            'SELECT rn.releases_id, UNCOMPRESS(rn.nfo) AS textstring
             FROM release_nfos rn
             WHERE rn.releases_id IN (%s)
             ORDER BY rn.releases_id',
            $releaseIds
        );
    }

    /**
     * @param  list<int>  $releaseIds
     * @return list<object>
     */
    public function fileRows(array $releaseIds, string $source = self::SOURCE_FILES): array
    {
        $sourceColumns = $source === self::SOURCE_SRRDB ? ', rf.size' : '';
        $filter = match ($source) {
            self::SOURCE_FILES => '',
            self::SOURCE_SRR => " AND (rf.name LIKE '%.srr' OR rf.name LIKE '%.srs')",
            self::SOURCE_CRC => " AND rf.crc32 IS NOT NULL AND rf.crc32 != ''",
            self::SOURCE_SRRDB => ' AND LENGTH(rf.crc32) = 8',
            self::SOURCE_XXX => " AND rf.name LIKE '%SDPORN%'",
            default => throw new InvalidArgumentException("Unsupported release-file source [{$source}]."),
        };

        return $this->selectForReleaseIds(
            'SELECT rf.releases_id, rf.name AS textstring, rf.name AS filename, rf.crc32'.$sourceColumns.'
             FROM release_files rf
             WHERE rf.releases_id IN (%s)'.$filter.'
             ORDER BY rf.releases_id, rf.name',
            $releaseIds
        );
    }

    /**
     * @param  list<int>  $releaseIds
     * @return list<object>
     */
    public function mediaRows(array $releaseIds): array
    {
        return $this->selectForReleaseIds(
            'SELECT mi.releases_id, mi.unique_id AS uid, mi.movie_name, mi.file_name
             FROM media_infos mi
             WHERE mi.releases_id IN (%s)
             ORDER BY mi.releases_id',
            $releaseIds
        );
    }

    /**
     * @param  list<int>  $releaseIds
     * @return list<object>
     */
    public function hashRows(array $releaseIds): array
    {
        return $this->selectForReleaseIds(
            'SELECT ph.releases_id, ph.hash
             FROM par_hashes ph
             WHERE ph.releases_id IN (%s)
             ORDER BY ph.releases_id, ph.hash',
            $releaseIds
        );
    }

    /**
     * @param  list<string>  $uniqueIds
     * @return array<string, list<object>>
     */
    public function uidDonors(array $uniqueIds): array
    {
        if ($uniqueIds === []) {
            return [];
        }

        $placeholders = $this->placeholders(count($uniqueIds));
        $bindings = array_merge($uniqueIds, ['nonscene@Ef.net (EF)']);
        $rows = $this->database->select(
            "SELECT mi.unique_id AS match_key, r.id AS releases_id, r.size AS relsize,
                    r.searchname, r.fromname, r.predb_id
             FROM media_infos mi
             INNER JOIN releases r ON r.id = mi.releases_id
             WHERE mi.unique_id IN ({$placeholders})
             AND (".self::TRUSTED_DONOR_PREDICATE.' OR r.fromname = ?)',
            $bindings
        );

        return $this->groupByKey($rows, 'match_key');
    }

    /**
     * @param  list<string>  $hashes
     * @return array<string, list<object>>
     */
    public function hashDonors(array $hashes): array
    {
        return $this->donors(
            'SELECT ph.hash AS match_key, r.id AS releases_id, r.size AS relsize,
                    r.searchname, r.fromname, r.predb_id
             FROM par_hashes ph
             INNER JOIN releases r ON r.id = ph.releases_id
             WHERE ph.hash IN (%s)
             AND '.self::TRUSTED_DONOR_PREDICATE,
            $hashes
        );
    }

    /**
     * @param  list<string>  $crcs
     * @return array<string, list<object>>
     */
    public function crcDonors(array $crcs): array
    {
        return $this->donors(
            'SELECT rf.crc32 AS match_key, r.id AS releases_id, r.size AS relsize,
                    r.searchname, r.fromname, r.predb_id
             FROM release_files rf
             INNER JOIN releases r ON r.id = rf.releases_id
             WHERE rf.crc32 IN (%s)
             AND '.self::TRUSTED_DONOR_PREDICATE,
            $crcs
        );
    }

    /**
     * @return list<object>
     */
    public function predbBatch(int $worker, int $workers, int $limit): array
    {
        $workerCount = max(1, min(16, $workers));
        $workerSlot = max(1, min($workerCount, $worker)) - 1;
        $now = CarbonImmutable::now();

        return $this->database->select(
            'SELECT p.id AS predb_id, p.title, p.source, p.searched
             FROM predb p
             WHERE '.$this->predbEligibilitySql().'
             AND MOD(p.id, ?) = ?
             ORDER BY p.predate ASC, p.id ASC
             LIMIT ?',
            [
                $now->toDateTimeString(),
                $now->subDay()->toDateTimeString(),
                $workerCount,
                $workerSlot,
                max(1, $limit),
            ]
        );
    }

    public function predbCandidateCount(): int
    {
        $now = CarbonImmutable::now();
        $rows = $this->database->select(
            'SELECT COUNT(p.id) AS num FROM predb p WHERE '.$this->predbEligibilitySql(),
            [$now->toDateTimeString(), $now->subDay()->toDateTimeString()],
        );

        return (int) ($rows[0]->num ?? 0);
    }

    private function predbEligibilitySql(): string
    {
        return 'LENGTH(p.title) >= 15
                AND p.title NOT LIKE \'%"%\'
                AND p.title NOT LIKE \'%<%\'
                AND p.title NOT LIKE \'%>%\'
                AND p.title NOT LIKE \'% %\'
                AND p.searched IN ('.implode(',', PredbSearchStatus::retryableValues()).')
                AND (p.next_predb_search_at IS NULL OR p.next_predb_search_at <= ?)
                AND p.predate < ?';
    }

    /**
     * @param  list<int>  $candidateIds
     * @return list<object>
     */
    public function confirmPredbCandidates(array $candidateIds, string $title): array
    {
        if ($candidateIds === []) {
            return [];
        }

        $like = '%'.$this->escapeLike($title).'%';

        return $this->database->select(
            'SELECT r.id AS releases_id, r.name, r.fromname, r.searchname,
                    r.groups_id, r.categories_id
             FROM releases r
             WHERE r.id IN ('.$this->placeholders(count($candidateIds)).')
             AND r.predb_id = 0
             AND (r.name LIKE ? ESCAPE \'\\\\\' OR r.searchname LIKE ? ESCAPE \'\\\\\')
             ORDER BY r.id ASC
             LIMIT 21',
            array_merge($candidateIds, [$like, $like])
        );
    }

    public function countPrefileCandidates(): int
    {
        $rows = $this->database->select(
            'SELECT COUNT(*) AS aggregate
             FROM releases r
             WHERE r.predb_id = 0
             AND r.isrenamed = 0
             AND r.categories_id IN ('.implode(',', Category::OTHERS_GROUP).')
             AND EXISTS (
                SELECT 1 FROM release_files rf
                WHERE rf.releases_id = r.id
                AND rf.name IS NOT NULL
             )'
        );

        return (int) ($rows[0]->aggregate ?? 0);
    }

    /**
     * @return list<object>
     */
    public function prefileCandidateBatch(int $cursor, int $limit, bool $descending): array
    {
        $operator = $descending ? '<' : '>';
        $direction = $descending ? 'DESC' : 'ASC';

        return $this->database->select(
            'SELECT r.id AS releases_id, r.name, r.searchname, r.fromname,
                    r.groups_id, r.categories_id
             FROM releases r
             WHERE r.predb_id = 0
             AND r.isrenamed = 0
             AND r.categories_id IN ('.implode(',', Category::OTHERS_GROUP).")
             AND r.id {$operator} ?
             AND EXISTS (
                SELECT 1 FROM release_files rf
                WHERE rf.releases_id = r.id
                AND rf.name IS NOT NULL
             )
             ORDER BY r.id {$direction}
             LIMIT ?",
            [$cursor, max(1, $limit)]
        );
    }

    /**
     * @param  list<object>  $rows
     * @return array<int, list<object>>
     */
    public function groupByReleaseId(array $rows): array
    {
        return $this->groupByKey($rows, 'releases_id');
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function candidateWhere(string $source, int $time, int $categories): array
    {
        if (! isset(self::SOURCE_EXISTS[$source], self::STATUS_COLUMNS[$source])) {
            throw new InvalidArgumentException("Unsupported name-fixing source [{$source}].");
        }

        $where = ['r.predb_id = 0', '('.self::SOURCE_EXISTS[$source].')'];
        $bindings = [];

        if ($categories !== 3) {
            $statusColumn = self::STATUS_COLUMNS[$source];
            $where[] = '(r.isrenamed = 0 OR r.categories_id IN (?, ?))';
            $bindings[] = Category::OTHER_MISC;
            $bindings[] = Category::OTHER_HASHED;
            $where[] = "r.{$statusColumn} = 0";
        }

        if ($source === self::SOURCE_SRRDB) {
            $where[] = self::SRRDB_TRUST_PREDICATE;
        }

        if ($time === 1) {
            $where[] = 'r.adddate > ?';
            $bindings[] = CarbonImmutable::now()->subHours(6)->toDateTimeString();
        }

        if ($categories === 1) {
            $where[] = 'r.categories_id IN ('.implode(',', Category::OTHERS_GROUP).')';
        }

        return [implode(' AND ', $where), $bindings];
    }

    /**
     * @param  list<int>  $releaseIds
     * @return list<object>
     */
    private function selectForReleaseIds(
        string $sql,
        array $releaseIds,
        int $placeholderGroups = 1
    ): array {
        if ($releaseIds === []) {
            return [];
        }

        $placeholder = $this->placeholders((int) (count($releaseIds) / $placeholderGroups));
        $sql = vsprintf($sql, array_fill(0, $placeholderGroups, $placeholder));

        return $this->database->select($sql, $releaseIds);
    }

    /**
     * @param  list<string>  $values
     * @return array<string, list<object>>
     */
    private function donors(string $sql, array $values): array
    {
        if ($values === []) {
            return [];
        }

        $rows = $this->database->select(
            sprintf($sql, $this->placeholders(count($values))),
            $values
        );

        return $this->groupByKey($rows, 'match_key');
    }

    /**
     * @param  list<object>  $rows
     * @return array<int|string, list<object>>
     */
    private function groupByKey(array $rows, string $key): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$row->{$key}][] = $row;
        }

        return $grouped;
    }

    private function placeholders(int $count): string
    {
        return implode(',', array_fill(0, $count, '?'));
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
