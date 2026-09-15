<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Category;
use App\Models\CategoryRegex;
use App\Models\CollectionRegex;
use App\Models\ReleaseNamingRegex;
use App\Models\UsenetGroup;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service for managing regex patterns for collections, categories, and release naming.
 */
class RegexService
{
    private const int LOCAL_CACHE_TTL_SECONDS = 15 * 60;

    /**
     * The ID of the Regex input string matched or the generic name
     */
    public mixed $matchedRegex;

    /**
     * Name of the current table we are working on.
     */
    public string $tableName;

    /**
     * Cache of regex and their TTL.
     *
     * @var array<string, array{regex: array<int, object>, fetched_at: int}>
     */
    protected array $_regexCache = [];

    private string $collectionRevision = '';

    /**
     * Default category ID
     */
    protected int $_categoriesID = Category::OTHER_MISC;

    /**
     * RegexService constructor.
     *
     * @param  string  $tableName  The table name to work with (collection_regexes, category_regexes, release_naming_regexes)
     */
    public function __construct(string $tableName = '')
    {
        $this->tableName = $tableName;
    }

    /**
     * Add a new regex.
     *
     * @param  array<string, mixed>  $data
     */
    public function addRegex(array $data): bool
    {
        return (bool) DB::insert(
            sprintf(
                'INSERT INTO %s (group_regex, regex, status, description, ordinal%s) VALUES (%s, %s, %d, %s, %d%s)',
                $this->tableName,
                ($this->tableName === 'category_regexes' ? ', categories_id' : ''),
                trim(escapeString($data['group_regex'])),
                trim(escapeString($data['regex'])),
                $data['status'],
                trim(escapeString($data['description'])),
                $data['ordinal'],
                ($this->tableName === 'category_regexes' ? (', '.$data['categories_id']) : '')
            )
        );
    }

    /**
     * Update a regex with new info.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateRegex(array $data): bool
    {
        return (bool) DB::update(
            sprintf(
                'UPDATE %s
                SET group_regex = %s, regex = %s, status = %d, description = %s, ordinal = %d %s
                WHERE id = %d',
                $this->tableName,
                trim(escapeString($data['group_regex'])),
                trim(escapeString($data['regex'])),
                $data['status'],
                trim(escapeString($data['description'])),
                $data['ordinal'],
                ($this->tableName === 'category_regexes' ? (', categories_id = '.$data['categories_id']) : ''),
                $data['id']
            )
        );
    }

    /**
     * Get a single regex using its id.
     *
     * @return array<string, mixed>
     */
    public function getRegexByID(int $id): array
    {
        return (array) Arr::first(DB::select(sprintf('SELECT * FROM %s WHERE id = %d LIMIT 1', $this->tableName, $id)));
    }

    /**
     * Get paginated regex results.
     *
     * @return mixed
     */
    public function getRegex(string $group_regex = '')
    {
        if ($this->tableName === 'collection_regexes') {
            $table = CollectionRegex::class;
        } elseif ($this->tableName === 'category_regexes') {
            $table = CategoryRegex::class;
        } else {
            $table = ReleaseNamingRegex::class;
        }

        $result = $table::query();
        if ($group_regex !== '') {
            $result->where('group_regex', 'like', '%'.$group_regex.'%');
        }
        $result->orderBy('id');

        return $result->paginate(config('nntmux.items_per_page'));
    }

    /**
     * Get the count of regex in the DB.
     *
     * @param  string  $group_regex  Optional, keyword to find a group.
     */
    public function getCount(string $group_regex = ''): int
    {
        $query = DB::select(
            sprintf(
                'SELECT COUNT(id) AS count FROM %s %s',
                $this->tableName,
                $this->_groupQueryString($group_regex)
            )
        );

        return (int) $query[0]->count;
    }

    /**
     * Delete a regex using its id.
     *
     * @throws \Throwable
     */
    public function deleteRegex(int $id): void
    {
        DB::transaction(function () use ($id) {
            DB::delete(sprintf('DELETE FROM %s WHERE id = %d', $this->tableName, $id));
        }, 3);
    }

    /**
     * Test at most the requested number of binaries in one group without changing them.
     *
     * @return array{rows: list<array{binaryID: int, subject: string, match: bool, name: string, new_collection_hash: ?string, old_collection_hash: string}>, tested: int, matched: int}
     */
    public function testCollectionRegex(string $groupName, string $regex, int $limit): array
    {
        $groupID = UsenetGroup::getIDByName($groupName);
        $data = ['rows' => [], 'tested' => 0, 'matched' => 0];
        $lastID = 0;

        while ($data['tested'] < $limit) {
            $batchSize = min(500, $limit - $data['tested']);
            $rows = DB::table('binaries as b')
                ->join('collections as c', 'c.id', '=', 'b.collections_id')
                ->where('c.groups_id', $groupID)
                ->where('b.id', '>', $lastID)
                ->orderBy('b.id')->limit($batchSize)
                ->get(['b.id', 'b.name', 'b.totalparts', 'b.currentparts', 'b.binaryhash', 'c.fromname', 'c.collectionhash']);

            foreach ($rows as $row) {
                $matched = @preg_match($regex, $row->name, $hits) === 1;
                $this->checkTestRegexError();
                ksort($hits);
                $name = '';
                foreach ($hits as $key => $hit) {
                    if (! is_int($key)) {
                        $name .= $hit;
                    }
                }
                $files = preg_match('/[[(\s](\d{1,5})(\/|[\s_]of[\s_]|-)(\d{1,5})[])\s$:]/i', $row->name, $fileCount) ? $fileCount[3] : 0;
                $data['rows'][] = [
                    'binaryID' => (int) $row->id,
                    'subject' => $row->name,
                    'match' => $matched,
                    'name' => $name,
                    'new_collection_hash' => $matched ? sha1($name.$row->fromname.$groupID.$files) : null,
                    'old_collection_hash' => bin2hex($row->collectionhash),
                ];
                $data['tested']++;
                $data['matched'] += (int) $matched;
                $lastID = (int) $row->id;
            }
            if ($rows->count() < $batchSize) {
                break;
            }
        }

        return $data;
    }

    /**
     * Scan releases in ID order, retaining only matches and never hydrating models.
     *
     * @return array{rows: list<array{releaseID: int, oldName: string, newName: string, match: bool}>, tested: int, stopReason: string}
     */
    public function testReleaseNamingRegex(string $groupName, string $regex, int $displayLimit, int $queryLimit): array
    {
        $groupID = UsenetGroup::getIDByName($groupName);
        $data = ['rows' => [], 'tested' => 0, 'stopReason' => 'candidate_limit'];
        $lastID = 0;

        while ($data['tested'] < $queryLimit) {
            $batchSize = min(500, $queryLimit - $data['tested']);
            $rows = DB::table('releases')->where('groups_id', $groupID)
                ->where('id', '>', $lastID)->orderBy('id')->limit($batchSize)
                ->get(['id', 'name', 'searchname']);

            foreach ($rows as $row) {
                $hit = @$this->_matchRegex($regex, $row->name);
                $this->checkTestRegexError();
                $data['tested']++;
                $lastID = (int) $row->id;
                if ($hit) {
                    $data['rows'][] = [
                        'releaseID' => (int) $row->id,
                        'oldName' => $row->searchname,
                        'newName' => $hit,
                        'match' => true,
                    ];
                    if (count($data['rows']) === $displayLimit) {
                        $data['stopReason'] = 'result_limit';

                        return $data;
                    }
                }
            }
            if ($rows->count() < $batchSize) {
                $data['stopReason'] = 'exhausted';
                break;
            }
        }

        return $data;
    }

    private function checkTestRegexError(): void
    {
        if (preg_last_error() !== PREG_NO_ERROR) {
            throw ValidationException::withMessages([
                'regex' => 'The regex could not be evaluated: '.preg_last_error_msg(),
            ]);
        }
    }

    /**
     * This will try to find regex in the DB for a group and a usenet subject, attempt to match them and return the matches.
     *
     * @throws \Exception
     */
    public function tryRegex(string $subject, string $groupName): string
    {
        $this->matchedRegex = 0;

        $this->_fetchRegex($groupName);

        $returnString = '';
        // If there are no regex, return and try regex in this file.
        if ($this->_regexCache[$groupName]['regex']) {
            foreach ($this->_regexCache[$groupName]['regex'] as $regex) {
                if ($this->tableName === 'category_regexes') {
                    $this->_categoriesID = $regex->categories_id;
                }

                $returnString = $this->_matchRegex($regex->regex, $subject);
                // If this regex found something, break and return, or else continue trying other regex.
                if ($returnString) {
                    $this->matchedRegex = $regex->id;
                    break;
                }
            }
        }

        return $returnString;
    }

    /**
     * Get the regex from the DB, cache them locally for 15 mins.
     * Cache them also in the cache server, as this script might be terminated.
     */
    protected function _fetchRegex(string $groupName): void
    {
        if ($this->tableName === 'collection_regexes') {
            $revision = (string) Cache::get('collection_regexes_revision', '0');
            if ($revision !== $this->collectionRevision) {
                $this->_regexCache = [];
                $this->collectionRevision = $revision;
            }
        }
        $localEntry = $this->_regexCache[$groupName] ?? null;
        if ($localEntry !== null
            && now()->timestamp < $localEntry['fetched_at'] + self::LOCAL_CACHE_TTL_SECONDS) {
            return;
        }

        // Get all regex from DB which match the current group name. Cache them for 15 minutes. #CACHEDQUERY#
        $sql = sprintf(
            'SELECT r.id, r.regex %s FROM %s r WHERE \'%s\' REGEXP r.group_regex AND r.status = 1 ORDER BY r.ordinal ASC, r.group_regex ASC',
            ($this->tableName === 'category_regexes' ? ', r.categories_id' : ''),
            $this->tableName,
            $groupName
        );

        /** @var array<int, object>|null $regexes */
        $regexes = Cache::get(md5($sql.$this->collectionRevision));
        if ($regexes === null) {
            $regexes = DB::select($sql);
            $expiresAt = now()->addMinutes(config('nntmux.cache_expiry_long'));
            Cache::put(md5($sql.$this->collectionRevision), $regexes, $expiresAt);
        }

        $this->_regexCache[$groupName] = [
            'regex' => $regexes,
            'fetched_at' => now()->timestamp,
        ];
    }

    /**
     * Find matches on a regex taken from the database.
     *
     * Requires at least 1 named captured group.
     *
     * @throws \Exception
     */
    protected function _matchRegex(string $regex, string $subject): string
    {
        $returnString = '';
        if (preg_match($regex, $subject, $hits) && \count($hits) > 0) {
            // Sort the keys, the named key matches will be concatenated in this order.
            ksort($hits);
            foreach ($hits as $key => $value) {
                switch ($this->tableName) {
                    case 'collection_regexes': // Put this at the top since it's the most important for performance.
                    case 'release_naming_regexes':
                        // Ignore non-named capture groups. Only named capture groups are important.
                        if (\is_int($key) || preg_match('#reqid|parts#i', $key)) {
                            continue 2;
                        }
                        $returnString .= $value; // Concatenate the string to return.
                        break;
                    case 'category_regexes':
                        $returnString = (string) $this->_categoriesID; // Regex matched, so return the category ID.
                        break 2;
                }
            }
        }

        return $returnString;
    }

    /**
     * Format part of a query.
     */
    protected function _groupQueryString(string $group_regex): string
    {
        return $group_regex ? ('WHERE group_regex LIKE '.escapeString('%'.$group_regex.'%')) : '';
    }
}
