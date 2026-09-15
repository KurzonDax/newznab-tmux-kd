<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Releases\ReleaseReportPresentation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReleaseReportPresentationTest extends TestCase
{
    public function test_reports_have_complete_totals_and_independent_private_safe_pages(): void
    {
        Schema::dropIfExists('release_reports');
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('username');
            $table->string('email');
            $table->softDeletes();
        });
        Schema::create('release_reports', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('releases_id');
            $table->string('reason');
            $table->string('status');
            $table->text('description')->nullable();
            $table->text('response')->nullable();
            $table->boolean('response_is_public');
            $table->integer('responded_by')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
        });
        DB::table('users')->insert([
            ['id' => 1, 'username' => 'Public staff', 'email' => 'secret@example.test'],
            ['id' => 2, 'username' => 'Private staff', 'email' => 'private@example.test'],
        ]);
        for ($id = 1; $id <= 60; $id++) {
            DB::table('release_reports')->insert([
                'id' => $id, 'releases_id' => 1, 'reason' => $id <= 30 ? 'spam' : 'fake',
                'status' => $id <= 30 ? 'pending' : 'dismissed', 'description' => "Report {$id}",
                'response' => $id <= 30 ? "Public {$id}" : 'PRIVATE RESPONSE',
                'response_is_public' => $id <= 30, 'responded_by' => $id <= 30 ? 1 : 2,
                'responded_at' => now()->startOfSecond(), 'created_at' => now()->startOfSecond(),
            ]);
        }
        DB::table('release_reports')->where('id', 1)->update(['response' => '']);
        DB::table('release_reports')->where('id', 2)->update(['response' => null]);
        $this->app->instance('request', Request::create('/details/test?reports_page=2&responses_page=1&keep=value'));
        $data = app(ReleaseReportPresentation::class)->forRelease(1, 2, 1);
        $this->assertSame(30, $data['reportCount']);
        $this->assertSame(60, $data['totalReportCount']);
        $this->assertSame('Spam/Advertisement', $data['reportReasons']);
        $this->assertSame('Fake/Malicious Content, Spam/Advertisement', $data['allReportReasons']);
        $reports = $data['originalReportData'];
        $responses = $data['publicReportResponses'];
        $this->assertSame(range(35, 11), $reports->pluck('id')->all());
        $this->assertSame(28, $responses->total());
        $this->assertCount(25, $responses);
        $this->assertSame(['id', 'reason', 'status', 'description', 'created_at'], array_keys($reports->first()->getAttributes()));
        $this->assertSame(['id', 'username'], array_keys($responses->first()->responder->getAttributes()));
        $html = view('details.partials.reports', $data)->render();
        $this->assertStringNotContainsString('PRIVATE RESPONSE', $html);
        $this->assertStringNotContainsString('Private staff', $html);
        $this->assertStringContainsString('responses_page=2', $html);
        $this->assertStringContainsString('keep=value', $reports->url(1));
        $this->assertStringEndsWith('#reports', $reports->url(1));
        $later = app(ReleaseReportPresentation::class)->forRelease(1, 1, 2);
        $this->assertSame([5, 4, 3], $later['publicReportResponses']->pluck('id')->all());
        foreach (['duplicate', 'fake', 'password', 'incomplete', 'wrong_category', 'spam', 'other'] as $reason) {
            foreach (['pending', 'reviewed', 'resolved', 'dismissed'] as $status) {
                foreach (['public', 'private', 'empty', 'null'] as $visibility) {
                    DB::table('release_reports')->insert([
                        'releases_id' => 2, 'reason' => $reason, 'status' => $status,
                        'response_is_public' => $visibility !== 'private',
                        'response' => match ($visibility) {
                            'empty' => '', 'null' => null, default => $visibility
                        },
                        'responded_by' => $visibility === 'private' ? 2 : 1,
                        'responded_at' => now(), 'created_at' => now(),
                    ]);
                }
            }
        }
        $mixed = app(ReleaseReportPresentation::class)->forRelease(2, 1, 2);
        $this->assertSame(112, $mixed['totalReportCount']);
        $this->assertSame(84, $mixed['reportCount']);
        $this->assertSame(28, $mixed['publicReportResponses']->total());
        $this->assertCount(3, $mixed['publicReportResponses']);
        $this->assertSame('Duplicate Release, Fake/Malicious Content, Incomplete/Corrupted, Other, Password Protected, Spam/Advertisement, Wrong Category', $mixed['reportReasons']);
        $this->assertSame($mixed['reportReasons'], $mixed['allReportReasons']);
        $empty = app(ReleaseReportPresentation::class)->forRelease(3, 1, 1);
        $this->assertSame(0, $empty['totalReportCount']);
        $this->assertSame('Unknown', $empty['reportReasons']);
    }
}
