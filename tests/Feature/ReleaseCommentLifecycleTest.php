<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Release;
use App\Models\ReleaseComment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class ReleaseCommentLifecycleTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        DB::statement('CREATE TABLE users (id INTEGER PRIMARY KEY, username VARCHAR(255), deleted_at DATETIME NULL)');
        DB::statement('CREATE TABLE releases (id INTEGER PRIMARY KEY, comments INTEGER DEFAULT 0)');
        DB::statement('CREATE TABLE release_comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT, releases_id INTEGER NOT NULL, text VARCHAR(2000),
            isvisible INTEGER DEFAULT 1, username VARCHAR(255), users_id INTEGER,
            created_at DATETIME, updated_at DATETIME, host VARCHAR(45)
        )');
        DB::table('users')->insert(['id' => 7, 'username' => 'commenter']);
        DB::table('releases')->insert(['id' => 10, 'comments' => 99]);
    }

    protected function tearDown(): void
    {
        DB::disconnect();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_add_delete_and_visible_only_recount_use_release_id(): void
    {
        $commentId = ReleaseComment::addComment(10, 'Visible comment', 7, '127.0.0.1');
        DB::table('release_comments')->insert([
            'releases_id' => 10,
            'text' => 'Hidden comment',
            'isvisible' => 0,
            'username' => 'commenter',
            'users_id' => 7,
        ]);
        ReleaseComment::updateReleaseCommentCount(10);

        $this->assertSame(1, (int) DB::table('releases')->where('id', 10)->value('comments'));
        $this->assertDatabaseHas('release_comments', [
            'id' => $commentId,
            'releases_id' => 10,
            'text' => 'Visible comment',
        ]);

        ReleaseComment::deleteComment($commentId);

        $this->assertSame(0, (int) DB::table('releases')->where('id', 10)->value('comments'));
        $this->assertDatabaseMissing('release_comments', ['id' => $commentId]);
    }

    public function test_details_controller_submission_no_longer_reads_or_passes_gid(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/DetailsController.php'));

        $this->assertIsString($controller);
        $this->assertStringContainsString('ReleaseComment::addComment((int) $data[\'id\']', $controller);
        $this->assertStringNotContainsString("\$data['gid']", $controller);
    }

    public function test_details_paginate_only_visible_comments_and_keep_the_total_on_every_page(): void
    {
        for ($number = 1; $number <= 26; $number++) {
            DB::table('release_comments')->insert([
                'releases_id' => 10,
                'text' => sprintf('Public comment %02d', $number),
                'isvisible' => 1,
                'username' => 'commenter',
                'users_id' => 7,
                'created_at' => now(),
            ]);
        }
        DB::table('release_comments')->insert([
            ['releases_id' => 10, 'text' => 'Hidden moderation text', 'isvisible' => 0],
            ['releases_id' => 20, 'text' => 'Another release comment', 'isvisible' => 1],
        ]);
        ReleaseComment::updateReleaseCommentCount(10);
        request()->query->replace(['tab' => 'comments']);

        $firstPage = ReleaseComment::getComments(10);
        $this->assertInstanceOf(LengthAwarePaginator::class, $firstPage);
        $this->assertCount(25, $firstPage);
        $this->assertSame(26, $firstPage->total());
        $this->assertSame($firstPage->total(), (int) DB::table('releases')->where('id', 10)->value('comments'));
        $this->view('details.partials.comments', ['comments' => $firstPage])
            ->assertSee('Comments (26)')
            ->assertSee('Public comment 26')
            ->assertDontSee('Public comment 01')
            ->assertDontSee('Hidden moderation text')
            ->assertDontSee('Another release comment')
            ->assertSee('tab=comments&amp;comments_page=2#comments', false);

        request()->query->set('comments_page', 2);
        $secondPage = ReleaseComment::getComments(10);
        $this->assertCount(1, $secondPage);
        $this->view('details.partials.comments', ['comments' => $secondPage])
            ->assertSee('Comments (26)')
            ->assertSee('Public comment 01')
            ->assertDontSee('Public comment 26');

        request()->query->set('comments_page', 3);
        $this->view('details.partials.comments', ['comments' => ReleaseComment::getComments(10)])
            ->assertSee('Comments (26)')
            ->assertSee('No comments on this page.')
            ->assertDontSee('No comments yet.')
            ->assertSee('comments_page=1#comments', false);
    }

    public function test_a_release_with_only_hidden_comments_has_an_empty_public_discussion(): void
    {
        DB::table('release_comments')->insert([
            'releases_id' => 10,
            'text' => 'Hidden moderation text',
            'isvisible' => 0,
        ]);

        $this->view('details.partials.comments', [
            'release' => Release::factory()->make(['id' => 10]),
            'comments' => ReleaseComment::getComments(10),
        ])
            ->assertSee('Comments (0)')
            ->assertSee('No comments yet.')
            ->assertDontSee('Hidden moderation text');
    }
}
