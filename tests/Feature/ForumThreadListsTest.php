<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;
use TeamTeaTime\Forum\Events\UserViewingRecent;
use TeamTeaTime\Forum\Events\UserViewingUnread;
use TeamTeaTime\Forum\Http\Controllers\Blade\ThreadController;
use TeamTeaTime\Forum\Http\Requests\MarkThreadsAsRead;
use TeamTeaTime\Forum\Models\Thread;
use TeamTeaTime\Forum\Support\Access\CategoryAccess;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class ForumThreadListsTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->freezeTime();
        config(['forum.general.pagination.threads' => 2]);

        foreach (['roles', 'permissions'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
            });
        }
        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedInteger('role_id');
            $table->string('model_type');
            $table->unsignedInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->unsignedInteger('permission_id');
            $table->string('model_type');
            $table->unsignedInteger('model_id');
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });

        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedInteger('permission_id');
            $table->unsignedInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('username');
            $table->softDeletes();
            $table->unsignedInteger('roles_id')->nullable();
        });
        Schema::create('forum_categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('parent_id')->nullable();
            $table->unsignedInteger('_lft');
            $table->unsignedInteger('_rgt');
            $table->boolean('is_private')->default(false);
            $table->string('title');
            $table->string('color_light_mode')->default('#123456');
        });
        Schema::create('forum_threads', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('category_id');
            $table->unsignedInteger('author_id');
            $table->string('title');
            $table->boolean('pinned')->default(false);
            $table->boolean('locked')->default(false);
            $table->unsignedInteger('reply_count')->default(0);
            $table->unsignedInteger('last_post_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('forum_posts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('thread_id');
            $table->unsignedInteger('author_id');
            $table->unsignedInteger('sequence')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create(Thread::READERS_TABLE, function (Blueprint $table): void {
            $table->unsignedInteger('thread_id');
            $table->unsignedInteger('user_id');
            $table->timestamps();
            $table->index(['user_id', 'thread_id']);
        });
        DB::table('users')->insert(['id' => 1, 'username' => 'Reader']);
        DB::table('forum_categories')->insert([
            ['id' => 1, 'parent_id' => null, '_lft' => 1, '_rgt' => 2, 'is_private' => false, 'title' => 'Public'],
            ['id' => 2, 'parent_id' => null, '_lft' => 3, '_rgt' => 6, 'is_private' => true, 'title' => 'Private'],
            ['id' => 3, 'parent_id' => 2, '_lft' => 4, '_rgt' => 5, 'is_private' => false, 'title' => 'Private descendant'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_recent_filters_access_before_pagination_and_breaks_timestamp_ties_by_id(): void
    {
        foreach ([1, 2, 3, 1, 1] as $id => $category) {
            $this->thread($id + 1, $category);
        }
        $this->thread(6, 1, ['updated_at' => now()->subDays(10)->toDateTimeString()]);
        $this->thread(7, 1, ['deleted_at' => now()->toDateTimeString()]);

        $page = $this->listing('recent')->getData()['threads'];
        $this->assertInstanceOf(LengthAwarePaginator::class, $page);
        $this->assertSame(3, $page->total());
        $this->assertSame([5, 4], $page->getCollection()->modelKeys());
        $last = $this->listing('recent', ['page' => 2])->getData()['threads'];
        $this->assertSame([1], $last->getCollection()->modelKeys());

        $this->actingAs(User::findOrFail(1));
        $page = $this->listing('recent')->getData()['threads'];
        $this->assertSame(5, $page->total());
        $filtered = $this->listing('recent', ['category_id' => 2, 'search' => 'kept'])->getData()['threads'];
        $this->assertSame([2], $filtered->getCollection()->modelKeys());
        $this->assertStringContainsString('category_id=2', $filtered->url(2));
        $this->assertStringContainsString('search=kept', $filtered->url(2));
    }

    public function test_unread_filters_read_pivots_before_pagination_without_cross_user_leakage(): void
    {
        for ($id = 1; $id <= 6; $id++) {
            $this->thread($id);
        }
        $updated = DB::table('forum_threads')->value('updated_at');
        DB::table(Thread::READERS_TABLE)->insert([
            ['thread_id' => 2, 'user_id' => 1, 'updated_at' => now()->subDays(2)->toDateTimeString()],
            ['thread_id' => 3, 'user_id' => 1, 'updated_at' => $updated],
            ['thread_id' => 4, 'user_id' => 1, 'updated_at' => now()->toDateTimeString()],
            ['thread_id' => 5, 'user_id' => 2, 'updated_at' => now()->toDateTimeString()],
            ['thread_id' => 6, 'user_id' => 1, 'updated_at' => null],
        ]);
        $this->actingAs(User::findOrFail(1));
        $view = $this->listing('unread');
        $page = $view->getData()['threads'];
        $this->assertInstanceOf(LengthAwarePaginator::class, $page);
        $this->assertSame(4, $page->total());
        $this->assertSame([6, 5], $page->getCollection()->modelKeys());
        $this->assertSame([2, 1], $this->listing('unread', ['page' => 2])->getData()['threads']->getCollection()->modelKeys());
        $this->assertSame([
            6 => trans('forum::general.updated'),
            5 => trans('forum::general.unread'),
        ], $view->getData()['threadReadStatuses']);
        $this->assertSame(0, $this->listing('unread', ['category_id' => 2])->getData()['threads']->total());

        auth()->forgetGuards();
        $this->assertSame(0, $this->listing('unread')->getData()['threads']->total());
    }

    public function test_rendering_uses_page_scoped_read_state_and_links_and_events_contain_only_the_page(): void
    {
        for ($id = 1; $id <= 5; $id++) {
            $this->thread($id, 1, ['last_post_id' => $id]);
            DB::table('forum_posts')->insert([
                'id' => $id, 'thread_id' => $id, 'author_id' => 1,
                'created_at' => now()->subHour(), 'updated_at' => now()->subHour(),
            ]);
        }
        DB::table(Thread::READERS_TABLE)->insert(['user_id' => 1, 'thread_id' => 5, 'updated_at' => now()]);
        $this->actingAs(User::findOrFail(1));
        Event::fake([UserViewingRecent::class, UserViewingUnread::class]);
        $this->isolateForumLayout();

        foreach (['recent' => UserViewingRecent::class, 'unread' => UserViewingUnread::class] as $action => $event) {
            $view = $this->listing($action, ['category_id' => 1, 'search' => 'kept']);
            $page = $view->getData()['threads'];
            DB::enableQueryLog();
            $html = $view->render();
            $this->assertCount(0, DB::getQueryLog(), 'Rendering must not lazily load a thread, author, or read pivot.');
            DB::disableQueryLog();
            DB::flushQueryLog();
            $this->assertSame(2, substr_count($html, 'class="lead"'));
            $this->assertStringContainsString('page=2', $html);
            $this->assertStringContainsString('category_id=1', $html);
            $this->assertStringContainsString('search=kept', $html);
            Event::assertDispatched($event, fn ($event): bool => $event->collection->modelKeys() === $page->getCollection()->modelKeys());
        }
    }

    public function test_thresholds_and_pending_threads_preserve_existing_list_semantics(): void
    {
        $this->actingAs(User::findOrFail(1));
        $this->thread(1);
        $this->thread(2, 1, ['updated_at' => now()->subDays(10)->toDateTimeString()]);
        $this->thread(3, 1, ['updated_at' => now()->addHour()->toDateTimeString(), 'approved_at' => null]);
        $this->thread(4, 1, ['approved_at' => now()->addDay()->toDateTimeString()]);
        $this->assertSame(3, $this->listing('recent')->getData()['threads']->total());
        config(['forum.general.old_thread_threshold' => '14 days']);
        $this->assertSame(4, $this->listing('recent')->getData()['threads']->total());
        $this->assertSame(4, $this->listing('unread')->getData()['threads']->total());
        foreach ([false, null] as $threshold) {
            config(['forum.general.old_thread_threshold' => $threshold]);
            $view = $this->listing('recent');
            $this->assertSame([3], $view->getData()['threads']->getCollection()->modelKeys());
            $this->assertSame([], $view->getData()['threadReadStatuses']);
            $this->assertSame(0, $this->listing('unread')->getData()['threads']->total());
        }
    }

    public function test_thread_view_policy_allows_every_accessible_thread_for_guests_members_and_admins(): void
    {
        $this->thread(1);
        $this->thread(2, 2, ['approved_at' => null, 'locked' => true]);
        $this->thread(3, 3);
        $member = User::findOrFail(1);
        $admin = new User;
        $admin->forceFill(['id' => 2]);
        $admin->setRelation('roles', new Collection([
            new Role(['name' => 'Admin', 'guard_name' => 'web']),
        ]));
        foreach ([null, $member, $admin] as $user) {
            $accessible = CategoryAccess::getFilteredIdsFor($user);
            foreach (Thread::whereIn('category_id', $accessible)->get() as $thread) {
                $this->assertTrue(Gate::forUser($user)->allows('view', $thread));
            }
        }
        $this->actingAs($admin);
        $this->assertSame(3, $this->listing('recent')->getData()['threads']->total());
    }

    public function test_mark_as_read_still_marks_all_pages_and_retains_category_scope(): void
    {
        for ($id = 1; $id <= 5; $id++) {
            $this->thread($id);
        }
        $this->thread(6, 2);
        $this->actingAs(User::findOrFail(1));
        $this->assertSame(6, $this->listing('unread')->getData()['threads']->total());
        $request = MarkThreadsAsRead::create('/forum/unread/mark-as-read', 'PATCH', ['category_id' => 1]);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->setUserResolver(fn () => auth()->user());
        $request->validateResolved();
        $response = $this->app->make(ThreadController::class)->markAsRead($request);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([6], $this->listing('unread')->getData()['threads']->getCollection()->modelKeys());

        $request = MarkThreadsAsRead::create('/forum/unread/mark-as-read', 'PATCH');
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->setUserResolver(fn () => auth()->user());
        $request->validateResolved();
        $this->app->make(ThreadController::class)->markAsRead($request);
        $this->assertSame(0, $this->listing('unread')->getData()['threads']->total());
    }

    /**
     * The normal regression is small. Set FORUM_ACCEPTANCE_THREADS=10000 for
     * the explicitly requested 20,000 -> 40,000 thread acceptance fixture.
     */
    public function test_doubling_thread_volume_keeps_hydration_rendering_and_memory_bounded(): void
    {
        $count = (int) (getenv('FORUM_ACCEPTANCE_THREADS') ?: 40);
        $this->assertSame(0, $count % 4);
        config(['forum.general.pagination.threads' => 20]);
        $this->isolateForumLayout();
        $updatedAt = now()->subHour()->toDateTimeString();
        $older = now()->subDays(10)->toDateTimeString();
        $member = User::findOrFail(1);
        $admin = User::findOrFail(1);
        $admin->setRelation('roles', new Collection([
            new Role(['name' => 'Admin', 'guard_name' => 'web']),
        ]));
        $baseline = [];
        $hydrated = [];
        Event::listen('eloquent.retrieved: *', function (string $event, array $models) use (&$hydrated): void {
            $table = $models[0]->getTable();
            $hydrated[$table] = ($hydrated[$table] ?? 0) + 1;
        });

        foreach ([1, 2] as $volume) {
            $rows = [];
            $posts = [];
            for ($offset = 1; $offset <= $count * 2; $offset++) {
                $id = ($volume - 1) * $count * 2 + $offset;
                $rows[] = [
                    'id' => $id, 'category_id' => match ($id % 4) {
                        0, 1 => 1, 2 => 2, 3 => 3
                    },
                    'author_id' => 1, 'title' => 'Thread '.$id, 'last_post_id' => $id,
                    'created_at' => $older, 'updated_at' => $offset <= $count ? $updatedAt : $older,
                    'approved_at' => $older,
                ];
                $posts[] = ['id' => $id, 'thread_id' => $id, 'author_id' => 1, 'created_at' => $older, 'updated_at' => $updatedAt];
                if (count($rows) === 100 || $offset === $count * 2) {
                    DB::table('forum_threads')->insert($rows);
                    DB::table('forum_posts')->insert($posts);
                    $rows = [];
                    $posts = [];
                }
            }
            foreach (['guest' => null, 'member' => $member, 'admin' => $admin] as $role => $user) {
                auth()->forgetGuards();
                if ($user !== null) {
                    $this->actingAs($user);
                }
                foreach (['recent', 'unread'] as $action) {
                    $expectedTotal = $user === null ? ($action === 'recent' ? intdiv($count * $volume, 2) : 0) : $count * $volume;
                    foreach ([1, max(1, (int) ceil($expectedTotal / 20))] as $pageNumber) {
                        $hydrated = [];
                        gc_collect_cycles();
                        memory_reset_peak_usage();
                        $before = memory_get_usage();
                        $view = $this->listing($action, ['page' => $pageNumber]);
                        $page = $view->getData()['threads'];
                        $html = $view->render();
                        $growth = memory_get_peak_usage() - $before;
                        $expectedRows = min(20, $expectedTotal);
                        $this->assertEquals($expectedTotal, $page->total());
                        $this->assertCount($expectedRows, $page);
                        $this->assertSame($expectedRows, substr_count($html, 'class="lead"'));
                        $this->assertLessThanOrEqual(40, $hydrated['forum_threads'] ?? 0);
                        $this->assertLessThanOrEqual(20, $hydrated['forum_posts'] ?? 0);
                        $this->assertLessThanOrEqual(2, $hydrated['users'] ?? 0);
                        $key = $role.$action.($pageNumber === 1 ? 'first' : 'last');
                        if ($volume === 1) {
                            $baseline[$key] = $growth;
                        } else {
                            $this->assertLessThanOrEqual(8 * 1024 * 1024, $growth - ($baseline[$key] ?? 0));
                        }
                        if ($expectedTotal > 0) {
                            $this->assertSame($pageNumber === 1 ? ($volume * 2 - 1) * $count : ($user === null ? 40 : 20), $page->first()->id);
                            $this->assertSame($pageNumber === 1 ? ($volume * 2 - 1) * $count - ($user === null ? 39 : 19) : 1, $page->last()->id);
                        }
                        unset($view, $page, $html);
                    }
                }
            }
        }
    }

    private function isolateForumLayout(): void
    {
        $views = $this->makeTempDirectory('forum-layout');
        mkdir($views.'/layouts');
        file_put_contents($views.'/layouts/main.blade.php', "@yield('forum-content')");
        view()->prependNamespace('forum', $views);
    }

    /** @param array<string, mixed> $query */
    private function listing(string $action, array $query = []): View
    {
        $request = Request::create('/forum/'.$action, 'GET', $query);
        $request->setUserResolver(fn () => auth()->user());
        $this->app->instance('request', $request);

        $route = $this->app['router']->getRoutes()->match($request);
        $route->setContainer($this->app);
        $request->setRouteResolver(fn () => $route);

        return $route->getController()->{$action}($request);
    }

    /** @param array<string, mixed> $attributes */
    private function thread(int $id, int $category = 1, array $attributes = []): void
    {
        DB::table('forum_threads')->insert(array_replace([
            'id' => $id, 'category_id' => $category, 'author_id' => 1,
            'title' => 'Thread '.$id, 'created_at' => now()->subDay()->toDateTimeString(),
            'updated_at' => now()->subHour()->toDateTimeString(),
            'approved_at' => now()->subDay()->toDateTimeString(),
        ], $attributes));
    }
}
