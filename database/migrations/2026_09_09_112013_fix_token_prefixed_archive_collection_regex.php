<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Deployment prerequisite for BOTH directions: quiesce all header ingesters and
 * release processors, including long-lived workers. Drain affected CBP through
 * normal processing before retrying, then resume with fresh worker instances.
 * The read guard cannot prevent concurrent ingestion; it is not a worker lock.
 */
return new class extends Migration
{
    private const string PREVIOUS = '/(.+)[\-_ ]{0,3}[\\(\\[]\\d+\\/(?P<match0>\\d+[\\)\\]][\-_ ]{0,3}("|#34;).+?)(\\.part\\d*|\\.rar)?(\\.vol\\d+\\+\\d+\\.par2"|\\.[A-Za-z0-9]{2,4})("|#34;)(.+?)yEnc$/i';

    private const string UPDATED = '~(?J)(?:^([A-Za-z0-9][A-Za-z0-9._-]{0,199}) +\\[[0-9]{1,5}/(?P<match0>[0-9]{1,5}\\] - "(?-i:\\1))(?:\\.part[0-9]{1,5}\\.rar|\\.7z\\.[0-9]{3}|\\.vol[0-9]{1,5}\\+[0-9]{1,5}\\.par2|\\.(?:rar|7z|par2|nfo|sfv|nzb))" yEnc$|(.+)[\-_ ]{0,3}[\\(\\[]\\d+\\/(?P<match0>\\d+[\\)\\]][\-_ ]{0,3}("|#34;).+?)(\\.part\\d*|\\.rar)?(\\.vol\\d+\\+\\d+\\.par2"|\\.[A-Za-z0-9]{2,4})("|#34;)(.+?)yEnc$)~i';

    private const string GUARDED_SUBJECT = '~^([A-Za-z0-9][A-Za-z0-9._-]{0,199}) +\\[[0-9]{1,5}/(?P<match0>[0-9]{1,5}\\] - "(?-i:\\1))(?:\\.part[0-9]{1,5}\\.rar|\\.7z\\.[0-9]{3}|\\.vol[0-9]{1,5}\\+[0-9]{1,5}\\.par2|\\.(?:rar|7z|par2|nfo|sfv|nzb))" yEnc$~i';

    public function up(): void
    {
        $this->replaceStock(self::PREVIOUS, self::UPDATED);
    }

    public function down(): void
    {
        $this->replaceStock(self::UPDATED, self::PREVIOUS);
    }

    private function replaceStock(string $previous, string $updated): void
    {
        if (! $this->stockRow($previous)->exists()) {
            return;
        }

        $this->assertEmptyCohort();

        if ($this->stockRow($previous)->update(['regex' => $updated]) === 1) {
            Cache::forever('collection_regexes_revision', bin2hex(random_bytes(16)));
        }
    }

    private function stockRow(string $regex): Builder
    {
        $query = DB::table('collection_regexes')->where('id', 284)
            ->where('status', 1)->where('ordinal', 55);

        // Stock ownership is byte-exact even with a case-insensitive DB collation.
        foreach (['group_regex' => '^alt\\.binaries\\.erotica$', 'regex' => $regex] as $column => $value) {
            if (DB::getDriverName() === 'sqlite') {
                $query->whereRaw($column.' COLLATE BINARY = ?', [$value]);
            } else {
                $query->whereRaw('CAST('.$column.' AS BINARY) = CAST(? AS BINARY)', [$value]);
            }
        }

        return $query;
    }

    private function assertEmptyCohort(): void
    {
        $collections = DB::table('collections as c')
            ->join('usenet_groups as g', 'g.id', '=', 'c.groups_id')
            ->where('g.name', 'alt.binaries.erotica')
            ->where('c.collection_regexes_id', 284)
            ->select(['c.id', 'c.subject']);

        // No filecheck or releases_id restriction: published-but-not-cleaned rows
        // and every other CBP state still participate in the key transition.
        foreach ($collections->lazyById(200, 'c.id', 'id') as $collection) {
            $subject = (string) $collection->subject;
            $this->assertUnaffected($subject);
            $originalEstablished = $this->isCompleteSubject($subject);

            foreach (DB::table('binaries')->where('collections_id', $collection->id)
                ->select(['id', 'name'])->lazyById(200) as $binary) {
                $fullSubject = (string) $binary->name;
                $this->assertUnaffected($fullSubject);
                if (! $this->isCompleteSubject($fullSubject)) {
                    $this->refuseActivation();
                }
                if ($subject !== '' && str_starts_with($fullSubject, $subject)) {
                    $originalEstablished = true;
                }
            }

            if (! $originalEstablished) {
                $this->refuseActivation();
            }
        }
    }

    private function assertUnaffected(string $subject): void
    {
        if (preg_match(self::GUARDED_SUBJECT, $subject) !== 0) {
            $this->refuseActivation();
        }
    }

    private function isCompleteSubject(string $subject): bool
    {
        return preg_match(self::PREVIOUS, $subject) === 1;
    }

    private function refuseActivation(): never
    {
        throw new RuntimeException(
            'Cannot change stock collection rule 284: an affected in-flight cohort exists '
            .'or its absence cannot be established. Allow normal processing/cleanup to drain '
            .'the cohort and retry with header ingestion and release processors quiesced '
            .'(including long-lived workers). Resume with fresh workers after migration. '
            .'Do not delete, rehash, merge, or rewrite collections, releases, or NZBs to clear this guard.'
        );
    }
};
