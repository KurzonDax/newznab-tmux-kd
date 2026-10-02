<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The hourly listing canary's measurements, and the `nntp-headers` status probe that reads them.
 *
 * A provider can keep accepting connections while its XOVER listing leaves posts out, so the
 * canary compares what each provider lists for the same hours of the same group.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nntp_listing_coverage')) {
            Schema::create('nntp_listing_coverage', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->dateTime('measured_at')->comment('Start of the canary run; shared by every row of one run');
                $table->unsignedInteger('groups_id')->comment('usenet_groups.id of the measured group');
                $table->string('sample_provider', 64)->comment('Provider NAME whose listing was sampled');
                $table->string('reference_provider', 64)->comment('Provider NAME whose listing was searched');
                $table->dateTime('sample_time')->comment('Post time the sample starts at');
                $table->unsignedInteger('sampled')->comment('Multi-segment sample lines kept');
                $table->unsignedInteger('found')->comment('Sample lines whose Message-ID the reference lists');
                $table->index('measured_at', 'ix_nntp_listing_coverage_measured_at');
                $table->foreign('groups_id')->references('id')->on('usenet_groups')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('service_statuses') || ! Schema::hasColumn('service_statuses', 'probe_identifier')) {
            return;
        }

        $now = now();
        DB::table('service_statuses')->updateOrInsert(
            ['slug' => 'nntp-headers'],
            [
                'name' => 'NNTP headers',
                'endpoint_url' => null,
                'check_type' => 'probe',
                'probe_identifier' => 'nntp-headers',
                'status' => 'operational',
                'is_enabled' => true,
                'sort_order' => (int) DB::table('service_statuses')->max('sort_order') + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('nntp_listing_coverage');

        if (Schema::hasTable('service_statuses')) {
            DB::table('service_statuses')->where('slug', 'nntp-headers')->delete();
        }
    }
};
