<?php

declare(strict_types=1);

namespace Tests\Support\ObfuscationRecovery;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesRecoveryCbpSchema
{
    private function createRecoveryCbpSchema(): void
    {
        Schema::create('collections', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('subject');
            $table->string('fromname');
            $table->dateTime('date');
            $table->text('xref');
            $table->unsignedInteger('groups_id');
            $table->unsignedInteger('totalfiles');
            $table->unsignedInteger('declaredfiles')->default(0);
            $table->binary('collectionhash', 20, true)->unique();
            $table->unsignedInteger('collection_regexes_id');
            $table->dateTime('dateadded');
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('last_seen_head_postdate')->nullable();
            $table->dateTime('last_seen_tail_postdate')->nullable();
            $table->string('noise', 64)->default('');
            $table->unsignedBigInteger('filesize')->default(0);
            $table->unsignedTinyInteger('filecheck')->default(0);
            $table->unsignedInteger('releases_id')->nullable();
        });
        Schema::create('collection_groups', function (Blueprint $table): void {
            $table->unsignedInteger('collections_id');
            $table->string('group_name');
            $table->unique(['collections_id', 'group_name']);
        });
        Schema::create('binaries', function (Blueprint $table): void {
            $table->increments('id');
            $table->binary('binaryhash', 16, true);
            $table->string('name');
            $table->unsignedInteger('collections_id');
            $table->unsignedInteger('totalparts');
            $table->unsignedInteger('currentparts');
            $table->unsignedInteger('filenumber');
            $table->unsignedBigInteger('partsize');
            $table->unsignedTinyInteger('partcheck')->default(0);
            $table->unique(['binaryhash', 'collections_id']);
        });
        Schema::create('parts', function (Blueprint $table): void {
            $table->unsignedInteger('binaries_id');
            $table->unsignedBigInteger('number');
            $table->string('messageid')->charset('ascii')->collation(Schema::getConnection()->getDriverName() === 'sqlite' ? 'BINARY' : 'ascii_bin');
            $table->unsignedInteger('partnumber');
            $table->unsignedInteger('size');
            $table->primary(['binaries_id', 'partnumber']);
        });
    }
}
