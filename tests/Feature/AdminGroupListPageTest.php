<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Facades\Categorization;
use App\Http\Middleware\Google2FAMiddleware;
use App\Models\Category;
use App\Models\User;
use App\View\Composers\GlobalDataComposer;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * Server-rendered coverage for the Group List control redesign (#18) and the
 * select-all markup contract the Alpine component depends on (#17).
 *
 * The client-side halves are covered where they can be: the pure selection
 * summary in `tests/js/group-selection.test.js` (Node's built-in runner), and
 * the markup contract here. There is deliberately no browser coverage of the
 * live viewport widths, selection transitions, or the color schemes — the
 * repository has no browser driver, and #17 rules out adding one for this fix.
 * Those checks stay manual.
 */
class AdminGroupListPageTest extends TestCase
{
    use IsolatedSqliteDatabase;

    /**
     * @return array<string, string>
     */
    protected function bootstrapSettings(): array
    {
        return [
            'categorizeforeign' => '0',
            'catwebdl' => '0',
            'title' => 'NNTmux Test',
            'home_link' => '/',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        config([
            'mail.from.address' => 'noreply@example.test',
            'mail.from.name' => 'NNTmux Tests',
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'nntmux.items_per_page' => 2,
        ]);

        Cache::flush();

        $this->createSchema();
        $this->seedSettings();
        $this->seedCategories();
        $this->resetGlobalComposerState();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withoutMiddleware(Google2FAMiddleware::class);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_group_list_renders_the_compact_action_bar(): void
    {
        $this->createGroups(3);

        $response = $this->actingAs($this->admin())->get(route('admin.group-list'));

        $response->assertOk();
        $response->assertSee('Search groups');
        $response->assertSee('aria-label="Search groups"', false);
        $response->assertSee('3</span> groups', false);
        $response->assertSee('Page 1/2');
        $response->assertSee('Maintenance');
        $response->assertSee('Reset All');
        $response->assertSee('Purge All');
    }

    public function test_search_submit_control_is_icon_only(): void
    {
        $this->createGroups(1);

        $response = $this->actingAs($this->admin())->get(route('admin.group-list'));

        $response->assertOk();
        $this->assertMatchesRegularExpression(
            '#<button type="submit"[^>]*aria-label="Search groups"[^>]*>\s*<i class="fas fa-search"[^>]*></i>\s*</button>#',
            (string) $response->getContent(),
            'The search submit button must render the magnifying-glass icon and no label text.'
        );
    }

    public function test_reset_selected_action_is_bound_to_the_live_selection_count(): void
    {
        $this->createGroups(1);

        $response = $this->actingAs($this->admin())->get(route('admin.group-list'));

        $response->assertOk();
        $response->assertSee('x-show="hasSelection"', false);
        $response->assertSee('x-text="selectedCount"', false);
        $response->assertSee(' selected', false);
    }

    public function test_select_all_checkbox_has_an_accessible_name_and_no_competing_model(): void
    {
        $this->createGroups(1);

        $response = $this->actingAs($this->admin())->get(route('admin.group-list'));

        $response->assertOk();
        $response->assertSee('aria-label="Select all groups on this page"', false);
        $response->assertSee('@change="toggleAllCheckboxes()"', false);
        $response->assertDontSee('x-model="allChecked"', false);
    }

    public function test_numbered_pagination_appears_only_below_the_table(): void
    {
        $this->createGroups(5);

        $response = $this->actingAs($this->admin())->get(route('admin.group-list'));

        $response->assertOk();
        $this->assertSame(
            1,
            substr_count($response->getContent(), 'aria-label="Pagination Navigation"'),
            'The page must render exactly one numbered paginator.'
        );
    }

    public function test_pagination_links_preserve_the_group_name_search(): void
    {
        $this->createGroups(3, 'alt.binaries.match');
        $this->createGroups(1, 'alt.binaries.other');

        $response = $this->actingAs($this->admin())
            ->get(route('admin.group-list', ['groupname' => 'match']));

        $response->assertOk();
        $response->assertSee('groupname=match', false);
        $response->assertSee('value="match"', false);
        $response->assertSee('3</span> groups', false);
    }

    public function test_group_list_edit_and_bulk_links_preserve_the_origin_and_filters(): void
    {
        $this->createGroups(8);
        $admin = $this->admin();

        foreach ([
            'admin.group-list' => 'all',
            'admin.group-list-active' => 'active',
            'admin.group-list-inactive' => 'inactive',
        ] as $routeName => $returnTo) {
            $response = $this->actingAs($admin)->get(route($routeName, [
                'groupname' => 'alt.binaries.group',
                'page' => 2,
            ]));

            $response->assertOk();
            $response->assertSee(
                'return_to='.$returnTo.'&amp;groupname=alt.binaries.group&amp;page=2',
                false
            );
            $response->assertSee(route('admin.group-bulk', [
                'return_to' => $returnTo,
                'groupname' => 'alt.binaries.group',
                'page' => 2,
            ]));
        }
    }

    public function test_bulk_group_navigation_preserves_the_origin_and_filters(): void
    {
        $returnUrl = route('admin.group-list-inactive', [
            'groupname' => 'alt.binaries',
            'page' => 2,
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.group-bulk', [
            'return_to' => 'inactive',
            'groupname' => 'alt.binaries',
            'page' => 2,
        ]));

        $response->assertOk();
        $response->assertSee($returnUrl);
        $response->assertSee('name="return_to" value="inactive"', false);
        $response->assertSee('name="groupname" value="alt.binaries"', false);
        $response->assertSee('name="page" value="2"', false);
    }

    public function test_group_lists_render_app_timezone_timestamps_for_the_users_timezone(): void
    {
        $originalTimezone = date_default_timezone_get();
        config([
            'app.timezone' => 'America/Chicago',
            'nntmux.items_per_page' => 10,
        ]);
        date_default_timezone_set('America/Chicago');
        Carbon::setTestNow(Carbon::parse('2026-08-17 12:00:00', 'America/Chicago'));

        try {
            $this->createGroups(4);
            $groupIds = DB::table('usenet_groups')->orderBy('id')->pluck('id');

            DB::table('usenet_groups')->whereIn('id', [$groupIds[0], $groupIds[1]])->update([
                'last_updated' => now(),
            ]);
            DB::table('usenet_groups')->whereIn('id', [$groupIds[2], $groupIds[3]])->update([
                'last_updated' => null,
            ]);

            $admin = $this->admin();
            $admin->forceFill(['timezone' => 'Asia/Tokyo'])->save();

            foreach (['admin.group-list', 'admin.group-list-active', 'admin.group-list-inactive'] as $routeName) {
                $response = $this->actingAs($admin)->get(route($routeName));

                $response->assertOk();
                $response->assertSee('0 seconds ago');
                $response->assertSee('Aug 18, 2026 02:00');
                $response->assertSee('Never');
                $response->assertDontSee('from now');
            }

            $browseResponse = $this->actingAs($admin)->get(route('browsegroup'));

            $browseResponse->assertOk();
            $browseResponse->assertSee('0 seconds ago');
            $browseResponse->assertSee('Aug 18, 2026 02:00');
            $browseResponse->assertSee('Never');
            $browseResponse->assertDontSee('from now');
        } finally {
            Carbon::setTestNow();
            date_default_timezone_set($originalTimezone);
        }
    }

    public function test_zero_result_search_keeps_the_search_controls_editable(): void
    {
        $this->createGroups(2);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.group-list', ['groupname' => 'no-such-group']));

        $response->assertOk();
        $response->assertSee('No matching groups');
        $response->assertSee('Search groups');
        $response->assertSee('value="no-such-group"', false);
        $response->assertSee('Clear search');
        $response->assertSee('0</span> groups', false);
        $response->assertDontSee('Page 1/');
    }

    public function test_empty_group_table_without_a_search_shows_the_add_groups_state(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.group-list'));

        $response->assertOk();
        $response->assertSee('No groups available');
        $response->assertSee('Add Groups');
        $response->assertDontSee('Search groups');
    }

    public function test_reset_selected_groups_rejects_an_empty_payload(): void
    {
        $response = $this->actingAs($this->admin())->post(route('admin.ajax'), [
            'action' => 'reset_selected_groups',
            'group_ids' => '[]',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['success' => false, 'message' => 'No groups specified']);
    }

    public function test_reset_selected_groups_is_closed_to_non_admins(): void
    {
        $user = $this->createUserWithRole('User');
        /** @var Authenticatable $authenticatedUser */
        $authenticatedUser = $user;

        $response = $this->actingAs($authenticatedUser)->post(route('admin.ajax'), [
            'action' => 'reset_selected_groups',
            'group_ids' => '[1]',
        ]);

        $response->assertForbidden();
    }

    public function test_group_list_exposes_edit_selected_values_and_modal(): void
    {
        $this->createGroups(1);

        $response = $this->actingAs($this->admin())->get(route('admin.group-list'));

        $response->assertOk();
        $response->assertSee('Edit <span x-text="selectedCount">0</span> selected', false);
        $response->assertSee('data-backfill-target="1"', false);
        $response->assertSee('data-min-files=""', false);
        $response->assertSee('data-min-size=""', false);
        $response->assertSee('data-active="1"', false);
        $response->assertSee('data-backfill="0"', false);
        $response->assertSee('data-route-obfuscated-names="0"', false);
        $response->assertSee('data-obfuscated-default-root-category-id=""', false);
        $response->assertSee('Edit Selected Groups');
        $response->assertSee('Minimum File Size');
        $response->assertSee('Route Obfuscated Names');
        $response->assertSee('Default Root Category');
        $response->assertSee('<option value="6000">XXX</option>', false);
    }

    public function test_group_list_shows_a_preselected_root_while_routing_is_disabled(): void
    {
        $this->createGroups(1);
        DB::table('usenet_groups')->update([
            'route_obfuscated_names' => false,
            'obfuscated_default_root_categories_id' => Category::MOVIE_ROOT,
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.group-list'));

        $response->assertOk();
        $response->assertSee('Off · Movies');
    }

    public function test_group_list_surfaces_the_forced_root_category(): void
    {
        $this->createGroups(1);
        DB::table('usenet_groups')->update(['forced_root_categories_id' => Category::XXX_ROOT]);

        $response = $this->actingAs($this->admin())->get(route('admin.group-list'));

        $response->assertOk();
        $response->assertSee('Forced Root');
        $response->assertSee('data-forced-root-category-id="6000"', false);
        $response->assertSee('fas fa-lock mr-1', false);
    }

    public function test_group_edit_saves_the_forced_root_category(): void
    {
        $this->createGroups(1);
        $group = DB::table('usenet_groups')->first();

        $response = $this->actingAs($this->admin())->post(route('admin.group-edit'), [
            'action' => 'submit',
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'backfill_target' => 1,
            'first_record' => 0,
            'last_record' => 0,
            'active' => 1,
            'backfill' => 0,
            'minsizetoformrelease' => '',
            'minsizetoformrelease_unit' => 'MB',
            'minfilestoformrelease' => '',
            'route_obfuscated_names' => 0,
            'obfuscated_default_root_categories_id' => null,
            'forced_root_categories_id' => Category::XXX_ROOT,
        ]);

        $response->assertRedirect();
        $this->assertSame(
            Category::XXX_ROOT,
            (int) DB::table('usenet_groups')->where('id', $group->id)->value('forced_root_categories_id')
        );
    }

    public function test_group_edit_rejects_a_forced_root_category_that_is_not_a_root(): void
    {
        $this->createGroups(1);
        $group = DB::table('usenet_groups')->first();

        $response = $this->actingAs($this->admin())->post(route('admin.group-edit'), [
            'action' => 'submit',
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'backfill_target' => 1,
            'first_record' => 0,
            'last_record' => 0,
            'active' => 1,
            'backfill' => 0,
            'minsizetoformrelease' => '',
            'minsizetoformrelease_unit' => 'MB',
            'minfilestoformrelease' => '',
            'route_obfuscated_names' => 0,
            'obfuscated_default_root_categories_id' => null,
            'forced_root_categories_id' => 1234,
        ]);

        $response->assertSessionHasErrors('forced_root_categories_id');
        $this->assertNull(DB::table('usenet_groups')->where('id', $group->id)->value('forced_root_categories_id'));
    }

    public function test_edit_selected_updates_only_submitted_settings_and_returns_rows(): void
    {
        $this->createGroups(2);
        $ids = DB::table('usenet_groups')->orderBy('id')->pluck('id')->all();
        $lastUpdated = DB::table('usenet_groups')->where('id', $ids[0])->value('last_updated');

        $response = $this->actingAs($this->admin())->post(route('admin.ajax'), [
            'action' => 'edit_selected_groups',
            'group_ids' => json_encode($ids),
            'changes' => json_encode([
                'backfill_target' => 30,
                'minsizetoformrelease' => '100M',
                'active' => 0,
            ]),
        ]);

        $response->assertOk()->assertJson([
            'success' => true,
            'updated' => 2,
        ]);
        $response->assertJsonPath('rows.'.$ids[0], fn (string $row): bool => str_contains($row, 'id="grouprow-'.$ids[0].'"'));
        $response->assertJsonPath('rows.'.$ids[1], fn (string $row): bool => str_contains($row, 'id="grouprow-'.$ids[1].'"'));

        foreach ($ids as $id) {
            $group = DB::table('usenet_groups')->where('id', $id)->first();
            $this->assertSame(30, $group->backfill_target);
            $this->assertSame(104857600, $group->minsizetoformrelease);
            $this->assertSame(0, $group->active);
            $this->assertSame(0, $group->backfill, 'An omitted setting must remain untouched.');
            $this->assertNull($group->minfilestoformrelease, 'An omitted release floor must remain untouched.');
        }

        $this->assertSame($lastUpdated, DB::table('usenet_groups')->where('id', $ids[0])->value('last_updated'));
    }

    public function test_ajax_replacement_rows_preserve_the_inactive_list_context(): void
    {
        $this->createGroups(2);
        $groupId = DB::table('usenet_groups')->where('active', false)->value('id');

        $response = $this->actingAs($this->admin())->post(route('admin.ajax'), [
            'action' => 'toggle_group_backfill_status',
            'group_id' => $groupId,
            'backfill_status' => 1,
            'return_to' => 'inactive',
            'groupname' => 'alt.binaries.group',
            'page' => 2,
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $response->assertJsonPath('row', fn (string $row): bool => str_contains(
            $row,
            'return_to=inactive&amp;groupname=alt.binaries.group&amp;page=2'
        ));
    }

    public function test_edit_selected_saves_and_surfaces_obfuscated_name_routing(): void
    {
        $this->createGroups(1);
        $id = DB::table('usenet_groups')->value('id');

        $response = $this->actingAs($this->admin())->post(route('admin.ajax'), [
            'action' => 'edit_selected_groups',
            'group_ids' => json_encode([$id]),
            'changes' => json_encode([
                'route_obfuscated_names' => 1,
                'obfuscated_default_root_categories_id' => Category::XXX_ROOT,
            ]),
        ]);

        $response->assertOk()->assertJson(['success' => true, 'updated' => 1]);
        $response->assertJsonPath('rows.'.$id, fn (string $row): bool => str_contains($row, 'XXX'));

        $group = DB::table('usenet_groups')->where('id', $id)->first();
        $this->assertSame(1, $group->route_obfuscated_names);
        $this->assertSame(Category::XXX_ROOT, $group->obfuscated_default_root_categories_id);
    }

    public function test_edit_selected_normalizes_zero_release_floors_to_null(): void
    {
        $this->createGroups(1);
        $id = DB::table('usenet_groups')->value('id');
        DB::table('usenet_groups')->where('id', $id)->update([
            'minfilestoformrelease' => 5,
            'minsizetoformrelease' => 1024,
        ]);

        $response = $this->actingAs($this->admin())->post(route('admin.ajax'), [
            'action' => 'edit_selected_groups',
            'group_ids' => json_encode([$id]),
            'changes' => json_encode([
                'minfilestoformrelease' => 0,
                'minsizetoformrelease' => '0',
            ]),
        ]);

        $response->assertOk();
        $this->assertNull(DB::table('usenet_groups')->where('id', $id)->value('minfilestoformrelease'));
        $this->assertNull(DB::table('usenet_groups')->where('id', $id)->value('minsizetoformrelease'));
    }

    public function test_edit_selected_rejects_invalid_or_nonsensical_requests(): void
    {
        $this->createGroups(1);
        $id = DB::table('usenet_groups')->value('id');
        $admin = $this->admin();
        DB::table('categories')->insert([
            'id' => 1234,
            'title' => 'Not a root',
            'root_categories_id' => 1,
            'status' => 1,
        ]);

        foreach ([
            ['group_ids' => [$id], 'changes' => []],
            ['group_ids' => [$id], 'changes' => ['backfill_target' => 0]],
            ['group_ids' => [$id], 'changes' => ['backfill_target' => 7301]],
            ['group_ids' => [$id], 'changes' => ['minfilestoformrelease' => 3000000000]],
            ['group_ids' => [$id], 'changes' => ['minsizetoformrelease' => '10K']],
            ['group_ids' => [999999], 'changes' => ['active' => 1]],
            ['group_ids' => [$id], 'changes' => ['description' => 'tampered']],
            ['group_ids' => [$id], 'changes' => ['obfuscated_default_root_categories_id' => 1234]],
            ['group_ids' => [$id], 'changes' => ['route_obfuscated_names' => 1, 'obfuscated_default_root_categories_id' => null]],
        ] as $payload) {
            $response = $this->actingAs($admin)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'X-Requested-With' => 'XMLHttpRequest',
                ])->post(route('admin.ajax'), [
                    'action' => 'edit_selected_groups',
                    'group_ids' => json_encode($payload['group_ids']),
                    'changes' => json_encode($payload['changes']),
                ]);

            $response->assertUnprocessable();
        }
    }

    public function test_edit_selected_is_closed_to_non_admins(): void
    {
        $user = $this->createUserWithRole('User');
        /** @var Authenticatable $authenticatedUser */
        $authenticatedUser = $user;

        $response = $this->actingAs($authenticatedUser)->post(route('admin.ajax'), [
            'action' => 'edit_selected_groups',
            'group_ids' => '[1]',
            'changes' => '{"active":1}',
        ]);

        $response->assertForbidden();
    }

    public function test_single_group_edit_accepts_the_same_file_size_grammar(): void
    {
        $this->createGroups(1);
        $group = DB::table('usenet_groups')->first();

        $response = $this->actingAs($this->admin())->post('/admin/group-edit?action=submit', [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'backfill_target' => 1,
            'first_record' => 0,
            'last_record' => 0,
            'active' => 1,
            'backfill' => 0,
            'minfilestoformrelease' => 0,
            'minsizetoformrelease' => '2.5G',
        ]);

        $response->assertRedirect(route('admin.group-list'));
        $this->assertSame(2684354560, DB::table('usenet_groups')->where('id', $group->id)->value('minsizetoformrelease'));
        $this->assertNull(DB::table('usenet_groups')->where('id', $group->id)->value('minfilestoformrelease'));
    }

    public function test_group_edit_navigation_and_save_return_to_the_origin_list(): void
    {
        $this->createGroups(1);
        $group = DB::table('usenet_groups')->first();
        $admin = $this->admin();

        foreach ([
            'all' => 'admin.group-list',
            'active' => 'admin.group-list-active',
            'inactive' => 'admin.group-list-inactive',
        ] as $returnTo => $routeName) {
            $context = [
                'return_to' => $returnTo,
                'groupname' => 'alt.binaries',
                'page' => 3,
            ];
            $returnUrl = route($routeName, [
                'groupname' => 'alt.binaries',
                'page' => 3,
            ]);

            $editResponse = $this->actingAs($admin)->get(route('admin.group-edit', ['id' => $group->id] + $context));

            $editResponse->assertOk();
            $editResponse->assertSee($returnUrl);
            $editResponse->assertSee('name="return_to" value="'.$returnTo.'"', false);
            $editResponse->assertSee('name="groupname" value="alt.binaries"', false);
            $editResponse->assertSee('name="page" value="3"', false);

            $saveResponse = $this->actingAs($admin)->post(route('admin.group-edit', ['action' => 'submit']), [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'backfill_target' => 1,
                'first_record' => 0,
                'last_record' => 0,
                'active' => 1,
                'backfill' => 0,
                'minfilestoformrelease' => 0,
                'minsizetoformrelease' => 0,
            ] + $context);

            $saveResponse->assertRedirect($returnUrl);
        }
    }

    public function test_single_group_edit_saves_obfuscated_name_routing(): void
    {
        $this->createGroups(1);
        $group = DB::table('usenet_groups')->first();

        $response = $this->actingAs($this->admin())->post('/admin/group-edit?action=submit', [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'backfill_target' => 1,
            'first_record' => 0,
            'last_record' => 0,
            'active' => 1,
            'backfill' => 0,
            'minfilestoformrelease' => 0,
            'minsizetoformrelease' => 0,
            'route_obfuscated_names' => 1,
            'obfuscated_default_root_categories_id' => Category::MOVIE_ROOT,
        ]);

        $response->assertRedirect(route('admin.group-list'));

        $savedGroup = DB::table('usenet_groups')->where('id', $group->id)->first();
        $this->assertSame(1, $savedGroup->route_obfuscated_names);
        $this->assertSame(Category::MOVIE_ROOT, $savedGroup->obfuscated_default_root_categories_id);
    }

    public function test_single_group_edit_exposes_only_root_categories_for_obfuscated_routing(): void
    {
        $this->createGroups(1);
        $group = DB::table('usenet_groups')->first();
        DB::table('categories')->insert([
            'id' => 1234,
            'title' => 'Not a root',
            'root_categories_id' => 1,
            'status' => 1,
        ]);

        $response = $this->actingAs($this->admin())->get(route('admin.group-edit', ['id' => $group->id]));

        $response->assertOk();
        $response->assertSee('Route Obfuscated Names');
        $response->assertSee('Default Root Category');
        $response->assertSee('<option value="2000"', false);
        $response->assertSee('<option value="6000"', false);
        $response->assertDontSee('<option value="1234"', false);
    }

    public function test_single_group_edit_rejects_enabled_obfuscated_routing_without_a_root(): void
    {
        $this->createGroups(1);
        $group = DB::table('usenet_groups')->first();
        $editUrl = route('admin.group-edit', [
            'id' => $group->id,
            'return_to' => 'inactive',
            'groupname' => 'alt.binaries',
            'page' => 2,
        ]);

        $response = $this->actingAs($this->admin())->from($editUrl)
            ->post('/admin/group-edit?action=submit', [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'backfill_target' => 1,
                'first_record' => 0,
                'last_record' => 0,
                'active' => 1,
                'backfill' => 0,
                'minfilestoformrelease' => 0,
                'minsizetoformrelease' => 0,
                'route_obfuscated_names' => 1,
                'obfuscated_default_root_categories_id' => null,
                'return_to' => 'inactive',
                'groupname' => 'alt.binaries',
                'page' => 2,
            ]);

        $response->assertRedirect($editUrl);
        $response->assertSessionHasErrors('obfuscated_default_root_categories_id');

        $editResponse = $this->get($editUrl);

        $editResponse->assertOk();
        $editResponse->assertSee('name="return_to" value="inactive"', false);
        $editResponse->assertSee('name="groupname" value="alt.binaries"', false);
        $editResponse->assertSee('name="page" value="2"', false);
        $this->assertSame(0, DB::table('usenet_groups')->where('id', $group->id)->value('route_obfuscated_names'));
    }

    public function test_group_edit_rejects_an_unrecognized_return_origin(): void
    {
        $this->createGroups(1);
        $group = DB::table('usenet_groups')->first();

        $response = $this->actingAs($this->admin())->post(route('admin.group-edit', ['action' => 'submit']), [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'backfill_target' => 1,
            'first_record' => 0,
            'last_record' => 0,
            'active' => 1,
            'backfill' => 0,
            'minfilestoformrelease' => 0,
            'minsizetoformrelease' => 0,
            'return_to' => 'evil',
            'groupname' => 'alt.binaries',
            'page' => 2,
        ]);

        $response->assertRedirect(route('admin.group-list', [
            'groupname' => 'alt.binaries',
            'page' => 2,
        ]));
    }

    public function test_categorization_uses_the_persisted_group_obfuscated_routing_settings(): void
    {
        $this->createGroups(1);
        $groupId = DB::table('usenet_groups')->value('id');
        DB::table('usenet_groups')->where('id', $groupId)->update([
            'route_obfuscated_names' => true,
            'obfuscated_default_root_categories_id' => Category::XXX_ROOT,
        ]);

        $result = Categorization::categorize($groupId, 'ABCDEFGH1234567890XY', debug: true);

        $this->assertSame(Category::XXX_OTHER, $result['categories_id']);
        $this->assertSame('group_obfuscated_default_root', $result['debug']['matched_by']);
        $this->assertFalse($result['debug']['locked_to_misc']);
    }

    private function admin(): Authenticatable
    {
        /** @var Authenticatable $admin */
        $admin = $this->createUserWithRole('Admin');

        return $admin;
    }

    private function createGroups(int $count, string $prefix = 'alt.binaries.group'): void
    {
        $rows = [];

        for ($index = 1; $index <= $count; $index++) {
            $rows[] = [
                'name' => $prefix.'.'.$index,
                'description' => 'Test group '.$index,
                'first_record_postdate' => '2024-01-01 00:00:00',
                'last_record_postdate' => '2024-06-01 00:00:00',
                'last_updated' => '2024-06-02 00:00:00',
                'active' => $index % 2,
                'backfill' => 0,
                'minfilestoformrelease' => null,
                'minsizetoformrelease' => null,
                'backfill_target' => 1,
                'route_obfuscated_names' => false,
                'obfuscated_default_root_categories_id' => null,
            ];
        }

        DB::table('usenet_groups')->insert($rows);
    }

    private function createSchema(): void
    {
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }

        Schema::create('content', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->default('');
            $table->string('url', 2000)->nullable();
            $table->text('body')->nullable();
            $table->string('metadescription', 1000)->default('');
            $table->string('metakeywords', 1000)->default('');
            $table->integer('contenttype')->default(2);
            $table->integer('status')->default(1);
            $table->integer('ordinal')->nullable();
            $table->integer('role')->default(0);
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->integer('rate_limit')->default(60);
            $table->boolean('isdefault')->default(false);
            $table->unsignedInteger('defaultinvites')->default(0);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('username');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedInteger('roles_id')->default(1);
            $table->integer('rate_limit')->default(60);
            $table->string('api_token')->nullable();
            $table->boolean('verified')->default(true);
            $table->boolean('can_post')->default(true);
            $table->string('theme_preference', 10)->default('light');
            $table->string('timezone', 50)->default('UTC');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('lastlogin')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

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

        Schema::create('root_categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->default('');
            $table->integer('status')->default(1);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->default('');
            $table->unsignedInteger('root_categories_id')->nullable();
            $table->text('description')->nullable();
            $table->integer('status')->default(1);
            $table->timestamps();
        });

        Schema::create('user_excluded_categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('users_id');
            $table->unsignedInteger('categories_id');
        });

        Schema::create('user_activities', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('username');
            $table->string('activity_type', 50);
            $table->text('description');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('first_record_postdate')->nullable();
            $table->string('last_record_postdate')->nullable();
            $table->string('last_updated')->nullable();
            $table->unsignedBigInteger('first_record')->default(0);
            $table->unsignedBigInteger('last_record')->default(0);
            $table->boolean('active')->default(false);
            $table->boolean('backfill')->default(false);
            $table->unsignedInteger('minfilestoformrelease')->nullable();
            $table->unsignedBigInteger('minsizetoformrelease')->nullable();
            $table->unsignedInteger('backfill_target')->default(1);
            $table->boolean('route_obfuscated_names')->default(false);
            $table->unsignedInteger('obfuscated_default_root_categories_id')->nullable();
            $table->unsignedInteger('forced_root_categories_id')->nullable();
        });
    }

    private function seedSettings(): void
    {
        DB::table('settings')->upsert([
            ['name' => 'title', 'value' => 'NNTmux Test'],
            ['name' => 'home_link', 'value' => '/'],
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
        ], ['name'], ['value']);
    }

    private function seedCategories(): void
    {
        DB::table('root_categories')->insert([
            [
                'id' => 1,
                'title' => 'General',
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Category::MOVIE_ROOT,
                'title' => 'Movies',
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Category::XXX_ROOT,
                'title' => 'XXX',
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('categories')->insert([
            'id' => 1,
            'title' => 'General',
            'root_categories_id' => 1,
            'description' => 'General category',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createUserWithRole(string $roleName): User
    {
        $role = Role::query()->firstOrCreate(
            [
                'name' => $roleName,
                'guard_name' => 'web',
            ],
            [
                'rate_limit' => 60,
                'isdefault' => $roleName === 'User',
                'defaultinvites' => 1,
            ]
        );

        /** @var User $user */
        $user = User::withoutEvents(fn () => User::query()->create([
            'username' => strtolower($roleName).'_'.Str::random(8),
            'email' => Str::random(12).'@example.test',
            'password' => bcrypt('password'),
            'roles_id' => $role->id,
            'rate_limit' => 60,
            'api_token' => Str::random(32),
            'verified' => true,
            'email_verified_at' => now(),
            'lastlogin' => now(),
        ]));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user->assignRole($role);

        return $user->fresh();
    }

    private function resetGlobalComposerState(): void
    {
        $reflection = new ReflectionClass(GlobalDataComposer::class);
        $property = $reflection->getProperty('resolvedData');
        $property->setValue(null, null);
    }
}
