<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * binaryblacklist.groupname becomes TEXT and loses its index (issue #1065).
 *
 * A poster blocked from the poster page gets a group scope naming every group the poster has
 * releases in. Strict mode is off, so MariaDB cut a scope longer than 255 characters without an
 * error, and the cut pattern no longer compiled: the rule was stored and never applied. A TEXT
 * column cannot keep a full-column index, and nothing needs one. No row is changed, so a rule
 * that was already cut stays cut.
 */
return new class extends Migration
{
    private const string GROUPNAME_INDEX = 'ix_binaryblacklist_groupname';

    public function up(): void
    {
        if (! $this->isMariaDbOrMysql()) {
            return;
        }

        // No migration creates the index, only the schema dump has it: an installation may lack it.
        $dropIndex = $this->hasGroupnameIndex() ? 'DROP INDEX `'.self::GROUPNAME_INDEX.'`, ' : '';
        DB::statement("ALTER TABLE `binaryblacklist` {$dropIndex}MODIFY `groupname` TEXT NULL DEFAULT NULL");
    }

    public function down(): void
    {
        if (! $this->isMariaDbOrMysql()) {
            return;
        }

        $tooLong = (int) DB::selectOne('SELECT COUNT(*) AS rules FROM `binaryblacklist` WHERE CHAR_LENGTH(`groupname`) > 255')->rules;
        if ($tooLong > 0) {
            throw new RuntimeException(
                "binaryblacklist holds {$tooLong} rule(s) whose groupname is longer than 255 characters. With strict mode off "
                .'VARCHAR(255) would cut them without an error. Nothing was changed.'
            );
        }

        DB::statement('ALTER TABLE `binaryblacklist` MODIFY `groupname` VARCHAR(255) NULL DEFAULT NULL, ADD INDEX `'.self::GROUPNAME_INDEX.'` (`groupname`)');
    }

    private function isMariaDbOrMysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function hasGroupnameIndex(): bool
    {
        return DB::select(
            'SELECT 1
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
             LIMIT 1',
            ['binaryblacklist', self::GROUPNAME_INDEX]
        ) !== [];
    }
};
