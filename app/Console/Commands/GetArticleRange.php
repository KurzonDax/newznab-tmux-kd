<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\HeaderScanDirection;
use App\Models\Settings;
use App\Models\UsenetGroup;
use App\Services\Binaries\BinariesService;
use App\Services\NNTP\NNTPService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GetArticleRange extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'articles:get-range
                            {mode : Mode: binaries or backfill}
                            {group : Group name}
                            {first : First article number}
                            {last : Last article number}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get a range of article headers for a group';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $mode = $this->argument('mode');
        $groupName = $this->argument('group');
        $firstArticle = (int) $this->argument('first');
        $lastArticle = (int) $this->argument('last');

        if (! \in_array($mode, ['binaries', 'backfill'], true)) {
            $this->error('Mode must be either "binaries" or "backfill".');

            return self::FAILURE;
        }

        try {
            $nntp = $this->getNntp();
            $groupMySQL = UsenetGroup::getByName($groupName)->toArray();

            if ($groupMySQL === null) {
                $this->error("Group not found: {$groupName}");

                return self::FAILURE;
            }

            if (NNTPService::isError($nntp->selectGroup($groupMySQL['name']))
                && NNTPService::isError($nntp->dataError($nntp, $groupMySQL['name']))) {
                return self::FAILURE;
            }

            $binaries = app(BinariesService::class);
            $binaries->setNntp($nntp);
            $return = $binaries->scan(
                $groupMySQL,
                $firstArticle,
                $lastArticle,
                $mode === 'backfill' ? HeaderScanDirection::Tail : HeaderScanDirection::Head,
                ((int) Settings::settingValue('safepartrepair') === 1 ? 'update' : 'backfill')
            );

            if ($binaries->lastScanWasRejected()) {
                return self::FAILURE;
            }
            if (empty($return) && $mode === 'backfill') {
                return self::SUCCESS;
            }

            $this->updateGroupRecords($mode, $groupMySQL, $return, $firstArticle, $lastArticle);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error($e->getTraceAsString());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Update group records based on mode.
     *
     * @param  array<string, mixed>  $groupMySQL
     * @param  array<string, mixed>  $return
     */
    private function updateGroupRecords(string $mode, array $groupMySQL, array $return, int $first, int $last): void
    {
        switch ($mode) {
            case 'binaries':
                $date = $return['lastArticleDate'] ?? null;
                $unixTime = $date === null ? null : (is_numeric($date) ? (int) $date : (int) strtotime($date));
                UsenetGroup::advanceLastRecordContiguously((int) $groupMySQL['id'], $first, $last, $unixTime);

                return;

            case 'backfill':
                if ($return['firstArticleNumber'] >= $groupMySQL['first_record']) {
                    return;
                }
                $unixTime = is_numeric($return['firstArticleDate'])
                    ? $return['firstArticleDate']
                    : strtotime($return['firstArticleDate']);
                UsenetGroup::rewindFirstRecord(
                    (int) $groupMySQL['id'],
                    (int) $return['firstArticleNumber'],
                    (int) $unixTime
                );

                return;

            default:
                return;
        }

    }

    /**
     * Get NNTP connection.
     */
    private function getNntp(): NNTPService
    {
        $nntp = app(NNTPService::class);

        $connectResult = $nntp->doConnect();

        if ($connectResult !== true) {
            $errorMessage = 'Unable to connect to usenet.';
            if (NNTPService::isError($connectResult)) {
                $errorMessage .= ' Error: '.$connectResult->getMessage();
            }
            throw new \Exception($errorMessage);
        }

        return $nntp;
    }
}
