<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * Coverage for the admin user role history list (#451).
 *
 * The controller applied six filters and then paginated without attaching any of them, so every
 * link was a bare `?page=N`: following one silently returned the unfiltered history with the
 * filter inputs blank.
 *
 * The assertions deliberately read the link the page *renders*. Requesting `?role_id=2&page=2`
 * directly passes even with the bug present, because the defect was never in how a hand-built
 * URL is handled.
 */
class AdminUserRoleHistoryPageTest extends TestCase
{
    use InteractsWithAdminListPages;
    use IsolatedSqliteDatabase;

    private int $memberRoleId;

    private int $adminRoleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->createRoleHistorySchema();
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_pagination_links_carry_every_active_filter(): void
    {
        $admin = $this->admin();
        $subject = $this->subjectUser('filtered_user');
        $this->createHistory($subject, 3, 'promotion');

        $response = $this->actingAs($admin)->get(route('admin.user-role-history', [
            'user_id' => $subject->id,
            'username' => 'filtered_user',
            'role_id' => $this->memberRoleId,
            'change_reason' => 'promotion',
            'date_from' => now()->subYear()->format('Y-m-d'),
            'date_to' => now()->addYear()->format('Y-m-d'),
        ]));

        $response->assertOk();
        $content = (string) $response->getContent();

        $pageTwoLink = $this->pageLink($content, 2);

        $this->assertStringContainsString('user_id='.$subject->id, $pageTwoLink);
        $this->assertStringContainsString('username=filtered_user', $pageTwoLink);
        $this->assertStringContainsString('role_id='.$this->memberRoleId, $pageTwoLink);
        $this->assertStringContainsString('change_reason=promotion', $pageTwoLink);
        $this->assertStringContainsString('date_from=', $pageTwoLink);
        $this->assertStringContainsString('date_to=', $pageTwoLink);
    }

    public function test_pagination_links_carry_exactly_one_page_parameter(): void
    {
        $admin = $this->admin();
        $subject = $this->subjectUser('filtered_user');
        $this->createHistory($subject, 3, 'promotion');

        $firstPage = $this->actingAs($admin)->get(route('admin.user-role-history', [
            'username' => 'filtered_user',
        ]));
        $firstPage->assertOk();

        $pageTwoLink = $this->pageLink((string) $firstPage->getContent(), 2);

        $this->assertSame(1, substr_count($pageTwoLink, 'page='), 'The link must carry one page parameter.');

        // Follow it, then check the link it renders back to page 1 has not accumulated another.
        $secondPage = $this->actingAs($admin)->get(html_entity_decode($pageTwoLink));
        $secondPage->assertOk();

        $backToFirst = $this->pageLink((string) $secondPage->getContent(), 1);

        $this->assertSame(1, substr_count($backToFirst, 'page='), 'Following a link must not add a second page parameter.');
    }

    public function test_following_a_pagination_link_keeps_the_result_set_filtered(): void
    {
        $admin = $this->admin();
        $matching = $this->subjectUser('filtered_user');
        $other = $this->subjectUser('other_user');
        $this->createHistory($matching, 3, 'promotion');
        $this->createHistory($other, 3, 'demotion');

        $firstPage = $this->actingAs($admin)->get(route('admin.user-role-history', [
            'username' => 'filtered_user',
        ]));
        $firstPage->assertOk();

        $pageTwoLink = html_entity_decode($this->pageLink((string) $firstPage->getContent(), 2));

        $secondPage = $this->actingAs($admin)->get($pageTwoLink);

        $secondPage->assertOk();
        $secondPage->assertSee('filtered_user');
        $secondPage->assertDontSee('other_user');
    }

    public function test_the_filter_form_still_renders_the_active_values_on_a_later_page(): void
    {
        $admin = $this->admin();
        $subject = $this->subjectUser('filtered_user');
        $this->createHistory($subject, 3, 'promotion');

        $firstPage = $this->actingAs($admin)->get(route('admin.user-role-history', [
            'username' => 'filtered_user',
            'change_reason' => 'promotion',
        ]));
        $firstPage->assertOk();

        $pageTwoLink = html_entity_decode($this->pageLink((string) $firstPage->getContent(), 2));

        $secondPage = $this->actingAs($admin)->get($pageTwoLink);

        $secondPage->assertOk();
        $secondPage->assertSee('value="filtered_user"', false);
        $secondPage->assertSee('value="promotion"', false);
    }

    public function test_an_unfiltered_listing_still_paginates(): void
    {
        $admin = $this->admin();
        $subject = $this->subjectUser('plain_user');
        $this->createHistory($subject, 3, 'promotion');

        $response = $this->actingAs($admin)->get(route('admin.user-role-history'));

        $response->assertOk();
        $this->assertSame(
            1,
            substr_count((string) $response->getContent(), 'aria-label="Pagination Navigation"'),
            'The role history must render exactly one paginator.'
        );

        $secondPage = $this->actingAs($admin)->get(route('admin.user-role-history', ['page' => 2]));

        $secondPage->assertOk();
        $secondPage->assertSee('plain_user');
    }

    /**
     * The href the rendered paginator points at for one page number.
     */
    private function pageLink(string $html, int $page): string
    {
        preg_match_all('/<a[^>]+href="([^"]+)"[^>]*>\s*'.$page.'\s*<\/a>/', $html, $matches);

        $this->assertNotEmpty($matches[1], 'The page renders no link to page '.$page.'.');

        return $matches[1][0];
    }

    private function subjectUser(string $username): User
    {
        /** @var User $user */
        $user = User::withoutEvents(fn () => User::query()->create([
            'username' => $username,
            'email' => $username.'@example.test',
            'password' => bcrypt('password'),
            'roles_id' => $this->memberRoleId,
            'rate_limit' => 60,
            'verified' => true,
            'email_verified_at' => now(),
        ]));

        return $user;
    }

    private function createHistory(User $user, int $count, string $reason): void
    {
        $rows = [];

        for ($index = 0; $index < $count; $index++) {
            $rows[] = [
                'user_id' => $user->id,
                'old_role_id' => $this->memberRoleId,
                'new_role_id' => $this->adminRoleId,
                'effective_date' => now()->subDays($index)->format('Y-m-d H:i:s'),
                'is_stacked' => false,
                'change_reason' => $reason,
                'changed_by' => null,
                'created_at' => now()->subDays($index)->format('Y-m-d H:i:s'),
                'updated_at' => now()->subDays($index)->format('Y-m-d H:i:s'),
            ];
        }

        DB::table('user_role_history')->insert($rows);
    }

    private function createRoleHistorySchema(): void
    {
        Schema::create('user_role_history', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id')->index();
            $table->integer('old_role_id')->nullable();
            $table->integer('new_role_id');
            $table->dateTime('old_expiry_date')->nullable();
            $table->dateTime('new_expiry_date')->nullable();
            $table->dateTime('effective_date');
            $table->boolean('is_stacked')->default(false);
            $table->string('change_reason')->nullable();
            $table->unsignedInteger('changed_by')->nullable();
            $table->timestamps();
        });

        $this->memberRoleId = (int) Role::query()->create([
            'name' => 'User',
            'guard_name' => 'web',
            'rate_limit' => 60,
            'isdefault' => true,
            'defaultinvites' => 1,
        ])->id;

        $this->adminRoleId = (int) Role::query()->create([
            'name' => 'Admin',
            'guard_name' => 'web',
            'rate_limit' => 60,
            'isdefault' => false,
            'defaultinvites' => 1,
        ])->id;
    }
}
