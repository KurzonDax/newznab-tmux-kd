<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BlacklistConstants;
use App\Http\Middleware\Google2FAMiddleware;
use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\Category;
use App\Models\Release;
use App\Models\RootCategory;
use App\Models\Settings;
use App\Models\User;
use App\Services\BlacklistSweepService;
use App\Services\PosterIdentityBrowserContext;
use App\Services\Releases\ReleaseBrowseService;
use App\Services\Releases\ReleaseRowFacts;
use App\View\Composers\GlobalDataComposer;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\InteractsWithPublicShell;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

/**
 * A poster's posts (GET /poster?name=) in the generic list form and the administrator's blacklist
 * action on it (issue #1032; docs/proposals/generic-release-lists/SPEC.md 5.1, 5.5, 5.8 and 5.9, with
 * corrections 1 and 5: the sweep status attributed to the started run and reporting actual results).
 */
final class PosterIdentityControllerTest extends TestCase
{
    use InteractsWithPublicShell;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();

        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'nntmux.items_per_page' => 2,
            'nntmux.max_pager_results' => 500000,
            'nntmux.cache_expiry_short' => 1,
            'nntmux.cache_expiry_medium' => 1,
        ]);

        $this->registerSqliteFunction(
            'REGEXP',
            static fn (?string $pattern, ?string $value): int => @preg_match('/'.($pattern ?? '').'/i', $value ?? '') === 1 ? 1 : 0,
            2,
        );
        Cache::flush();
        $this->createSchema();
        $this->createPublicShellCountTables();
        $this->resetGlobalComposerState();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withoutMiddleware([
            Google2FAMiddleware::class,
            TrustedDevice2FAMiddleware::class,
        ]);

        Settings::query()->updateOrCreate(['name' => 'showpasswordedrelease'], ['value' => '0']);
        Settings::query()->updateOrCreate(['name' => 'title'], ['value' => 'NNTmux Test']);
        Settings::query()->updateOrCreate(['name' => 'home_link'], ['value' => '/']);

        $root = RootCategory::query()->create(['id' => 2000, 'title' => 'Movies']);
        Category::query()->create(['id' => 2030, 'title' => 'SD', 'root_categories_id' => $root->id]);
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_poster_identity_page_matches_exact_identity_and_paginates_newest_first(): void
    {
        $user = $this->verifiedUser();
        $identity = 'user <user@x.localdomain>';

        $this->release('Newest exact', $identity, '2026-08-03 12:00:00');
        $this->release('Middle exact', $identity, '2026-08-02 12:00:00');
        $this->release('Oldest exact', $identity, '2026-08-01 12:00:00');
        for ($index = 1; $index <= 49; $index++) {
            $this->release('Middle filler '.$index, $identity, '2026-08-02 00:00:00');
        }
        $this->release('Look alike', 'user <user@2.localdomain>', '2026-08-04 12:00:00');
        $this->release('Case variant', 'user <USER@x.localdomain>', '2026-08-05 12:00:00');

        $firstPage = $this->actingAs($user)->get(route('poster-identity', ['name' => $identity]));

        $firstPage->assertOk();
        $firstPage->assertSeeInOrder(['Newest exact', 'Middle exact']);
        $firstPage->assertDontSee('Oldest exact');
        $firstPage->assertDontSee('Look alike');
        $firstPage->assertDontSee('Case variant');
        $firstPage->assertSee('Showing 1–50 of 52 releases')->assertSee('Page 1 of 2');
        $firstPage->assertSee('name=user%20%3Cuser%40x.localdomain%3E', false);
        $firstPage->assertSee('<title>Posts by user &lt;user@x.localdomain&gt;', false);
        // the poster chip is left off a poster's list (SPEC 5.5); the old browser is gone
        $firstPage->assertDontSee('All posts by')->assertDontSee('tv-origin-poster', false)->assertDontSee('x-release-browser', false)->assertDontSee('bg-primary-100', false);
        $firstPage->assertSee('data-preference-root="all"', false);

        $secondPage = $this->actingAs($user)->get(route('poster-identity', ['name' => $identity, 'page' => 2]));
        $secondPage->assertOk();
        $secondPage->assertSee('Oldest exact')->assertSee('Showing 51–52 of 52 releases');
        $secondPage->assertDontSee('Look alike');
        $secondPage->assertDontSee('Case variant');
    }

    public function test_poster_identity_page_respects_category_exclusions_and_renders_empty_state(): void
    {
        $user = $this->verifiedUser();
        DB::table('user_excluded_categories')->insert([
            'users_id' => $user->id,
            'categories_id' => 2030,
        ]);
        $this->release('Excluded release', 'excluded@example.test', '2026-08-03 12:00:00');

        $this->actingAs($user)
            ->get(route('poster-identity', ['name' => 'excluded@example.test']))
            ->assertOk()
            ->assertDontSee('Excluded release')
            ->assertSee('Showing 0 releases')
            ->assertSee('No posts by this poster.');

        $this->actingAs($user)
            ->get(route('poster-identity'))
            ->assertOk()
            ->assertSee('<h1 data-part="page title" class="is-poster">No Posted By identity supplied</h1>', false)
            ->assertSee('Showing 0 releases')
            ->assertDontSee('<table', false)
            ->assertDontSee('tv-list-crumbs', false);
    }

    public function test_poster_identity_page_requires_authentication_and_verification(): void
    {
        $this->get('/poster?name=poster%40example.test')->assertRedirect(route('login'));

        $unverified = $this->user(false);
        $this->actingAs($unverified)
            ->get('/poster?name=poster%40example.test')
            ->assertRedirect(route('verification.notice'));
    }

    public function test_canonical_poster_browser_preserves_the_privileged_blacklist_control(): void
    {
        $admin = $this->verifiedUser('Admin');
        $identity = ' Exact <poster@Host.test> ';
        $this->release('Canonical poster release', $identity, '2026-08-03 12:00:00');

        $response = $this->actingAs($admin)->get(route('browse.all', ['poster' => $identity]))->assertOk();
        $response->assertSee('<title>Posts by '.e($identity), false)->assertSee('Blacklist this poster')->assertSee('data-blacklist', false)
            ->assertSee('Canonical poster release')->assertSee('x-data="tvReleases"', false);
    }

    public function test_only_admins_see_the_poster_identity_blacklist_control(): void
    {
        $identity = 'poster@example.test';
        $this->release('Poster release', $identity, '2026-08-03 12:00:00');

        $user = $this->verifiedUser();
        $this->actingAs($user)
            ->get(route('poster-identity', ['name' => $identity]))
            ->assertOk()
            ->assertDontSee('Blacklist this poster')->assertDontSee('x-data="posterIdentityBlacklist"', false)->assertDontSee('tv-sweep-slot', false);
        $this->actingAs($user)
            ->post(route('admin.poster-identity.blacklist'), ['name' => $identity])
            ->assertForbidden();

        $this->flushSession();
        $this->actingAs($this->verifiedUser('Admin'))
            ->get(route('poster-identity', ['name' => $identity]))
            ->assertOk()
            ->assertSee('Blacklist this poster')->assertSee('<span class="tv-sweep-slot">', false);
    }

    public function test_admin_sees_the_matching_enabled_posted_by_rule_instead_of_an_add_control(): void
    {
        $identity = 'poster@example.test';
        $this->release('Poster release', $identity, '2026-08-03 12:00:00', 'alt.binaries.movies');
        $ruleId = DB::table('binaryblacklist')->insertGetId([
            'groupname' => '^alt\.binaries\.movies$',
            'regex' => 'poster@example\.test',
            'description' => 'Existing broader rule',
            'status' => BlacklistConstants::BLACKLIST_ENABLED,
            'optype' => BlacklistConstants::OPTYPE_BLACKLIST,
            'msgcol' => BlacklistConstants::BLACKLIST_FIELD_FROM,
        ]);

        $this->actingAs($this->verifiedUser('Admin'))
            ->get(route('poster-identity', ['name' => $identity]))
            ->assertOk()
            ->assertSee('<a class="tv-blacklist-link" href="'.route('admin.binaryblacklist-edit', ['id' => $ruleId]).'" data-blacklisted><i class="fas fa-ban" aria-hidden="true"></i>Blacklisted (rule #'.$ruleId.')</a>', false)
            ->assertDontSee('Blacklist this poster')->assertDontSee('x-data="posterIdentityBlacklist"', false);
    }

    public function test_blacklist_confirmation_shows_the_exact_read_only_rule_and_optional_sweep(): void
    {
        $identity = 'poster+tag/user@example.test';
        $this->release('Movie release', $identity, '2026-08-03 12:00:00', 'alt.binaries.movies');
        $this->release('TV release', $identity, '2026-08-02 12:00:00', 'alt.binaries.tv');
        $admin = $this->verifiedUser('Admin');

        $response = $this->actingAs($admin)
            ->get(route('poster-identity', ['name' => $identity]))
            ->assertOk()
            ->assertSee('<h2 id="blacklist-dialog-title" x-bind:data-part="partWhenOpen(\'dialog title\')">Blacklist this poster</h2>', false)
            ->assertSee('Confirm the exact rule that will be saved.')
            ->assertSee('<dd class="is-code">^poster\+tag\/user@example\.test$</dd>', false)
            ->assertSee('Posted By · Type: Black · Status: enabled')
            ->assertSee('<dd class="is-code">^(?:alt\.binaries\.movies|alt\.binaries\.tv)$</dd>', false)
            ->assertSee('Poster identity blocked from poster page by '.$admin->username)
            ->assertSee('Also permanently remove this poster’s 2 existing releases now', false)
            ->assertSee('name="delete_releases"', false)
            ->assertSeeInOrder(['class="tv-dialog-actions"', 'x-on:click="close">Cancel</button>', 'class="tv-details-button is-danger"', 'Confirm blacklist'], false)
            ->assertSee('class="tv-blacklist-button" data-blacklist x-on:click="openConfirmation"', false)
            ->assertDontSee('name="regex"', false)
            ->assertDontSee('name="groupname"', false);

        $dialog = $this->htmlElement($response->getContent(), '//*[@data-modal-dialog]//*[@role="dialog"]');
        $this->assertNotNull($dialog);
        $this->assertSame('tv-dialog is-blacklist', $dialog->getAttribute('class'));
        $this->assertSame('blacklist-dialog-title', $dialog->getAttribute('aria-labelledby'));
        $this->assertTrue($this->hasAlpineDataAncestor($dialog, 'posterIdentityBlacklist'));
        $form = $this->htmlElement($response->getContent(), '//form[@data-blacklist-form]');
        $this->assertNotNull($form);
        $this->assertSame(route('admin.poster-identity.blacklist'), $form->getAttribute('action'));
        $this->assertTrue($this->hasAlpineDataAncestor($form, 'posterIdentityBlacklist'));
        $this->assertTrue($this->hasAlpineDataAncestor($form, 'tvReleases'));
        // the Blacklist button sits in the heading row between the name search and the sort
        $response->assertSeeInOrder(['tv-name-search', 'data-blacklist', '<span class="tv-grow"></span>', 'data-part="sort dropdown"'], false);
        // the sweep slot is reserved in the pager line, empty while nothing was started
        $this->assertMatchesRegularExpression('/<span class="pager-line-status"><span class="tv-sweep-slot">\s*<\/span><\/span>/', (string) $response->getContent());
    }

    public function test_admin_can_create_the_exact_enabled_posted_by_rule_without_starting_a_sweep(): void
    {
        Process::fake(fn () => Process::result(output: getmypid()."\n"));
        $sweeps = new BlacklistSweepService($this->makeTempDirectory('poster-identity-no-sweep'));
        app()->instance(BlacklistSweepService::class, $sweeps);
        $identity = 'poster+tag/user@example.test';
        $this->release('Movie release', $identity, '2026-08-03 12:00:00', 'alt.binaries.movies');
        $this->release('TV release', $identity, '2026-08-02 12:00:00', 'alt.binaries.tv');
        $admin = $this->verifiedUser('Admin');
        $previewToken = $this->blacklistPreviewToken($admin, $identity);

        $response = $this->actingAs($admin)->post(route('admin.poster-identity.blacklist'), [
            'name' => $identity,
            'preview_token' => $previewToken,
        ]);

        $ruleId = (int) DB::table('binaryblacklist')->value('id');
        $response
            ->assertRedirect(route('poster-identity', ['name' => $identity]))
            ->assertSessionHas('success', 'Rule #'.$ruleId.' added · sweep not started')
            ->assertSessionMissing(PosterIdentityBrowserContext::SESSION_KEY);
        $this->assertDatabaseHas('binaryblacklist', [
            'id' => $ruleId,
            'groupname' => '^(?:alt\.binaries\.movies|alt\.binaries\.tv)$',
            'regex' => '^poster\+tag\/user@example\.test$',
            'description' => 'Poster identity blocked from poster page by '.$admin->username,
            'status' => BlacklistConstants::BLACKLIST_ENABLED,
            'optype' => BlacklistConstants::OPTYPE_BLACKLIST,
            'msgcol' => BlacklistConstants::BLACKLIST_FIELD_FROM,
        ]);
        $this->assertFalse($sweeps->status()['running']);
        $this->assertNull($sweeps->status()['current']);
        $this->get(route('poster-identity', ['name' => $identity]))->assertOk()->assertSee('Blacklisted (rule #'.$ruleId.')')->assertDontSee('data-sweep-status', false);
    }

    public function test_tampered_regex_and_group_values_are_rejected(): void
    {
        $identity = 'poster@example.test';
        $this->release('Poster release', $identity, '2026-08-03 12:00:00', 'alt.binaries.movies');

        $admin = $this->verifiedUser('Admin');
        $previewToken = $this->blacklistPreviewToken($admin, $identity);

        $this->actingAs($admin)
            ->postJson(route('admin.poster-identity.blacklist'), [
                'name' => $identity,
                'preview_token' => $previewToken,
                'regex' => '.*',
                'groupname' => '.*',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['regex', 'groupname']);

        $this->assertDatabaseCount('binaryblacklist', 0);

        $this->actingAs($admin)
            ->postJson(route('admin.poster-identity.blacklist'), [
                'name' => $identity,
                'preview_token' => $previewToken.'tampered',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['preview_token']);

        $this->assertDatabaseCount('binaryblacklist', 0);
    }

    public function test_disabled_generated_rule_is_reenabled_instead_of_duplicated(): void
    {
        $identity = 'poster@example.test';
        $this->release('Poster release', $identity, '2026-08-03 12:00:00', 'alt.binaries.movies');
        $ruleId = DB::table('binaryblacklist')->insertGetId([
            'groupname' => '^alt\.binaries\.old$',
            'regex' => '^poster@example\.test$',
            'description' => 'Poster identity blocked from poster page by previous-admin',
            'status' => 0,
            'optype' => BlacklistConstants::OPTYPE_BLACKLIST,
            'msgcol' => BlacklistConstants::BLACKLIST_FIELD_FROM,
        ]);
        $admin = $this->verifiedUser('Admin');
        $previewToken = $this->blacklistPreviewToken($admin, $identity);

        $this->actingAs($admin)
            ->post(route('admin.poster-identity.blacklist'), [
                'name' => $identity,
                'preview_token' => $previewToken,
            ])
            ->assertRedirect(route('poster-identity', ['name' => $identity]));

        $this->assertDatabaseCount('binaryblacklist', 1);
        $this->assertDatabaseHas('binaryblacklist', [
            'id' => $ruleId,
            'status' => BlacklistConstants::BLACKLIST_ENABLED,
            'groupname' => '^(?:alt\.binaries\.movies)$',
            'description' => 'Poster identity blocked from poster page by '.$admin->username,
        ]);
    }

    public function test_repeated_submission_does_not_duplicate_an_enabled_matching_rule(): void
    {
        $identity = 'poster@example.test';
        $this->release('Poster release', $identity, '2026-08-03 12:00:00', 'alt.binaries.movies');
        $admin = $this->verifiedUser('Admin');
        $previewToken = $this->blacklistPreviewToken($admin, $identity);

        $this->actingAs($admin)->post(route('admin.poster-identity.blacklist'), [
            'name' => $identity,
            'preview_token' => $previewToken,
        ]);
        $ruleId = (int) DB::table('binaryblacklist')->value('id');
        $this->actingAs($admin)
            ->post(route('admin.poster-identity.blacklist'), [
                'name' => $identity,
                'preview_token' => $previewToken,
            ])
            ->assertSessionHas('success', 'Rule #'.$ruleId.' added · sweep not started');

        $this->assertDatabaseCount('binaryblacklist', 1);
    }

    public function test_disabled_hand_written_exact_rule_is_not_modified(): void
    {
        $identity = 'poster@example.test';
        $this->release('Poster release', $identity, '2026-08-03 12:00:00', 'alt.binaries.movies');
        $handWrittenRuleId = DB::table('binaryblacklist')->insertGetId([
            'groupname' => '^alt\.binaries\.movies$',
            'regex' => '^poster@example\.test$',
            'description' => 'Hand-written exact rule',
            'status' => 0,
            'optype' => BlacklistConstants::OPTYPE_BLACKLIST,
            'msgcol' => BlacklistConstants::BLACKLIST_FIELD_FROM,
        ]);

        $admin = $this->verifiedUser('Admin');

        $this->actingAs($admin)
            ->post(route('admin.poster-identity.blacklist'), [
                'name' => $identity,
                'preview_token' => $this->blacklistPreviewToken($admin, $identity),
            ])
            ->assertRedirect(route('poster-identity', ['name' => $identity]));

        $this->assertDatabaseCount('binaryblacklist', 2);
        $this->assertDatabaseHas('binaryblacklist', [
            'id' => $handWrittenRuleId,
            'status' => 0,
            'description' => 'Hand-written exact rule',
        ]);
    }

    public function test_checked_confirmation_starts_a_single_rule_delete_sweep_and_shows_its_status_in_the_pager_line_without_a_flash(): void
    {
        Process::fake(fn () => Process::result(output: getmypid()."\n"));
        $sweeps = new BlacklistSweepService($this->makeTempDirectory('poster-identity-sweeps'));
        app()->instance(BlacklistSweepService::class, $sweeps);
        $identity = 'poster@example.test';
        $this->release('Poster release', $identity, '2026-08-03 12:00:00', 'alt.binaries.movies');
        $admin = $this->verifiedUser('Admin');
        $previewToken = $this->blacklistPreviewToken($admin, $identity);

        $response = $this->actingAs($admin)->post(route('admin.poster-identity.blacklist'), [
            'name' => $identity,
            'preview_token' => $previewToken,
            'delete_releases' => '1',
        ]);

        $ruleId = (int) DB::table('binaryblacklist')->value('id');
        $status = $sweeps->status();
        $this->assertTrue($status['running']);
        $this->assertSame('delete', $status['current']['mode']);
        $this->assertSame($ruleId, $status['current']['rule_id']);
        $runId = (string) $status['current']['id'];
        // a started sweep is reported only in the pager slot: no success flash doubles it (correction 5); the run id is kept with the rule and the exact poster
        $response
            ->assertRedirect(route('poster-identity', ['name' => $identity]))
            ->assertSessionMissing('success')
            ->assertSessionHas(PosterIdentityBrowserContext::SESSION_KEY, ['run' => $runId, 'rule' => $ruleId, 'poster' => $identity]);

        $page = $this->get(route('poster-identity', ['name' => $identity]))->assertOk();
        $page->assertSee('Blacklisted (rule #'.$ruleId.')')->assertSee('Poster release')->assertSee('Showing 1–1 of 1 release')
            ->assertDontSee('x-data="blacklistSweep"', false)->assertDontSee('Sweep controls are disabled while this run finishes.')
            ->assertSee('<span class="pager-line-status"><span class="tv-sweep-slot">', false)
            ->assertSee('<b>Rule #'.$ruleId.' added · sweep running.</b> Removing this poster’s releases…', false)
            ->assertDontSee('releases…</b>', false);
        $sweep = $this->htmlElement($page->getContent(), '//*[@data-sweep-status]');
        $this->assertNotNull($sweep);
        $this->assertSame(['running', 'posterSweepStatus', '1', $runId, route('admin.binaryblacklist-sweep.status', ['run' => $runId])],
            [$sweep->getAttribute('data-sweep-status'), $sweep->getAttribute('x-data'), $sweep->getAttribute('data-running'), $sweep->getAttribute('data-run'), $sweep->getAttribute('data-status-url')]);
        // the status sits between the count and Clear all, inside the pager line, before the filter bar and the table are reached
        $page->assertSeeInOrder(['data-part="showing line"', 'data-sweep-status="running"', 'data-clear-all', '<table class="tv-feed is-shelf is-generic"'], false);
        $page->assertSeeInOrder(['class="filter-row tv-bar-list is-shelf"', 'data-sweep-status="running"'], false);
        // the list fragment carries the same status, so a reload after the poll renders the run's outcome
        $this->get(route('poster-identity', ['name' => $identity, '_fragment' => 'list']))->assertOk()->assertSee('data-sweep-status="running"', false)->assertDontSee('tv-filters', false);
        // the poll answers for this run only
        $this->getJson(route('admin.binaryblacklist-sweep.status', ['run' => $runId]))->assertOk()->assertJson(['running' => true, 'available' => true])->assertJsonPath('run.id', $runId)->assertJsonMissingPath('run.log_path');
    }

    public function test_a_finished_sweep_reports_its_actual_removals_and_keeps_a_protected_release(): void
    {
        Process::fake(fn () => Process::result(output: getmypid()."\n"));
        $directory = $this->makeTempDirectory('poster-identity-finished-sweeps');
        $sweeps = new BlacklistSweepService($directory);
        app()->instance(BlacklistSweepService::class, $sweeps);
        $identity = 'poster@example.test';
        // a live additional-processing claim protects a release from automated deletion (ReleaseDeletionProtection); the sweep leaves it
        $protected = $this->release('Protected release', $identity, '2026-08-03 12:00:00', 'alt.binaries.movies');
        DB::table('releases')->where('id', $protected->id)->update(['additional_pp_claim_token' => 'live-claim']);
        $unprotected = $this->release('Unprotected release', $identity, '2026-08-02 12:00:00', 'alt.binaries.movies');
        $admin = $this->verifiedUser('Admin');
        $this->actingAs($admin)->post(route('admin.poster-identity.blacklist'), ['name' => $identity, 'preview_token' => $this->blacklistPreviewToken($admin, $identity), 'delete_releases' => '1']);
        $ruleId = (int) DB::table('binaryblacklist')->value('id');
        $run = $sweeps->status()['current'];
        $log = $directory.'/'.$run['id'].'.log';
        $page = route('poster-identity', ['name' => $identity]);

        // the sweep's real effect: the unprotected release goes, the protected one stays, the log says what was deleted
        DB::table('releases')->where('id', $unprotected->id)->delete();
        file_put_contents($log, 'Deleting: Blacklist ['.$ruleId."]: Unprotected release\nDeleted 1 release(s). This script ran for 2 seconds.\n");
        $sweeps->complete($run['id'], 0);
        $this->getJson(route('admin.binaryblacklist-sweep.status', ['run' => $run['id']]))->assertOk()->assertJson(['running' => false, 'available' => true])->assertJsonPath('run.removed_count', 1);

        $partial = $this->get($page)->assertOk();
        $partial->assertSee('data-sweep-status="partial"', false)->assertSee('data-running="0"', false)
            ->assertSee('<b>Rule #'.$ruleId.' added · sweep finished.</b> 1 release by this poster was removed · 1 release remains.', false)
            ->assertSee('Protected release')->assertDontSee('Unprotected release')->assertSee('Showing 1–1 of 1 release')
            ->assertDontSee('No releases remain');
        // a filtered-empty list with a surviving release never reads as swept
        $this->get(route('poster-identity', ['name' => $identity, 'q' => 'zzz']))->assertOk()->assertSee('No releases match names containing “zzz”.', false)->assertDontSee('No releases remain')
            ->assertSee('data-sweep-status="partial"', false);
        // another viewer's exclusions do not change the count the status reports: the remaining count is the exact poster's, unfiltered
        DB::table('user_excluded_categories')->insert(['users_id' => $admin->id, 'categories_id' => 2030]);
        Cache::flush();
        $this->get($page)->assertOk()->assertSee('Showing 0 releases')->assertSee('No posts by this poster.')->assertSee('1 release remains.')->assertDontSee('No releases remain');
        DB::table('user_excluded_categories')->where('users_id', $admin->id)->delete();
        Cache::flush();

        // a later removal of the protected release, confirmed by the same run's log, is the complete outcome and the only sweep-specific empty line
        DB::table('releases')->where('id', $protected->id)->delete();
        file_put_contents($log, 'Deleting: Blacklist ['.$ruleId."]: Unprotected release\nDeleting: Blacklist [".$ruleId."]: Protected release\nDeleted 2 release(s). This script ran for 3 seconds.\n");
        $complete = $this->get($page)->assertOk();
        $complete->assertSee('data-sweep-status="complete"', false)
            ->assertSee('<b>Rule #'.$ruleId.' added · sweep finished.</b> 2 releases by this poster were removed.', false)
            ->assertSee('<p class="tv-empty" data-empty>No releases remain: the blacklist sweep removed them.</p>', false)
            ->assertDontSee('No posts by this poster.');
        // nothing of the exact poster remains, whatever the list's filters: the sweep line stands even with a search set
        $this->get(route('poster-identity', ['name' => $identity, 'q' => 'zzz']))->assertOk()->assertSee('No releases remain: the blacklist sweep removed them.')->assertDontSee('No releases match');
        $this->get(route('browse.all'))->assertOk()->assertSee('Showing 0 releases')->assertDontSee('No releases remain');

        // a non-zero exit reports failure and only the known removals
        file_put_contents($log, 'Deleting: Blacklist ['.$ruleId."]: Only one\nDeleted 1 release(s).\n");
        $this->release('Survivor', $identity, '2026-08-04 12:00:00', 'alt.binaries.movies');
        $sweeps->complete($run['id'], 1);
        $this->get($page)->assertOk()->assertSee('data-sweep-status="failed"', false)
            ->assertSee('<b>Rule #'.$ruleId.' added · sweep failed.</b> 1 release by this poster was removed before it stopped (exit 1).', false)
            ->assertSee('Survivor')->assertDontSee('No releases remain');

        // run metadata that is gone (pruned) confirms nothing, once; the next open shows no status at all
        unlink($directory.'/'.$run['id'].'.json');
        $this->get($page)->assertOk()->assertSee('data-sweep-status="unavailable"', false)
            ->assertSee('<b>Rule #'.$ruleId.' added · sweep result unavailable.</b> Its run could not be found, so nothing is confirmed.', false)
            ->assertSessionMissing(PosterIdentityBrowserContext::SESSION_KEY);
        $this->get($page)->assertOk()->assertDontSee('data-sweep-status', false)->assertSee('<span class="tv-sweep-slot">', false);
        $this->getJson(route('admin.binaryblacklist-sweep.status', ['run' => $run['id']]))->assertOk()->assertJson(['running' => false, 'available' => false, 'run' => null]);
    }

    public function test_the_sweep_status_follows_the_started_run_only_never_a_later_run(): void
    {
        Process::fake(fn () => Process::result(output: getmypid()."\n"));
        $directory = $this->makeTempDirectory('poster-identity-correlated-sweeps');
        $sweeps = new BlacklistSweepService($directory);
        app()->instance(BlacklistSweepService::class, $sweeps);
        $identity = 'poster@example.test';
        $this->release('Poster release', $identity, '2026-08-03 12:00:00', 'alt.binaries.movies');
        $this->release('Another poster release', 'other@example.test', '2026-08-03 12:00:00', 'alt.binaries.movies');
        $admin = $this->verifiedUser('Admin');
        $this->actingAs($admin)->post(route('admin.poster-identity.blacklist'), ['name' => $identity, 'preview_token' => $this->blacklistPreviewToken($admin, $identity), 'delete_releases' => '1']);
        $ruleId = (int) DB::table('binaryblacklist')->value('id');
        $first = $sweeps->status()['current'];
        $page = route('poster-identity', ['name' => $identity]);

        // the first run finishes, having removed the poster's release
        DB::table('releases')->where('fromname', $identity)->delete();
        file_put_contents($directory.'/'.$first['id'].'.log', "Deleted 1 release(s).\n");
        $sweeps->complete($first['id'], 0);
        // another administrator starts a later run for the same rule, which is still running and reports other counts
        $second = $sweeps->start('delete', $ruleId);
        file_put_contents($directory.'/'.$second['id'].'.log', "Deleting: a\nDeleting: b\nDeleting: c\n");
        $this->assertTrue($sweeps->status()['running']);
        $this->assertSame($second['id'], $sweeps->status()['current']['id']);

        $this->get($page)->assertOk()->assertSee('data-sweep-status="complete"', false)->assertSee('data-run="'.$first['id'].'"', false)
            ->assertSee('1 release by this poster was removed.', false)->assertDontSee('3 releases')->assertDontSee('sweep running')
            ->assertSee('No releases remain: the blacklist sweep removed them.');
        $this->getJson(route('admin.binaryblacklist-sweep.status', ['run' => $first['id']]))->assertOk()->assertJson(['running' => false, 'available' => true])
            ->assertJsonPath('run.id', $first['id'])->assertJsonPath('run.removed_count', 1);
        $this->getJson(route('admin.binaryblacklist-sweep.status', ['run' => $second['id']]))->assertOk()->assertJson(['running' => true])->assertJsonPath('run.removed_count', 3);
        // the global status (the admin page) still names the running run; the poster page never reads it
        $this->getJson(route('admin.binaryblacklist-sweep.status'))->assertOk()->assertJsonPath('current.id', $second['id']);
        // only a run id is accepted: a path, or an unknown id, is unavailable
        foreach (['../../.env', '/etc/passwd', 'not-a-run', '20261009-120000-000000-zzzzzzzz'] as $bad) {
            $this->getJson(route('admin.binaryblacklist-sweep.status', ['run' => $bad]))->assertOk()->assertJson(['running' => false, 'available' => false, 'run' => null]);
        }
        $this->assertNull($sweeps->run('../../.env'));
        // another poster's page shows no status from this session's run
        $this->get(route('poster-identity', ['name' => 'other@example.test']))->assertOk()->assertDontSee('data-sweep-status', false)->assertSee('Another poster release');
    }

    public function test_rule_is_saved_when_another_sweep_holds_the_runner(): void
    {
        Process::fake(fn () => Process::result(output: getmypid()."\n"));
        $sweeps = new BlacklistSweepService($this->makeTempDirectory('poster-identity-locked-sweeps'));
        app()->instance(BlacklistSweepService::class, $sweeps);
        $sweeps->start('dry-run');
        $identity = 'poster@example.test';
        $this->release('Poster release', $identity, '2026-08-03 12:00:00', 'alt.binaries.movies');
        $admin = $this->verifiedUser('Admin');
        $previewToken = $this->blacklistPreviewToken($admin, $identity);

        $response = $this->actingAs($admin)->post(route('admin.poster-identity.blacklist'), [
            'name' => $identity,
            'preview_token' => $previewToken,
            'delete_releases' => '1',
        ]);

        $ruleId = (int) DB::table('binaryblacklist')->value('id');
        $response
            ->assertRedirect(route('poster-identity', ['name' => $identity]))
            ->assertSessionHas('success', 'Rule #'.$ruleId.' added · sweep could not start')
            ->assertSessionMissing(PosterIdentityBrowserContext::SESSION_KEY);
        $this->assertDatabaseHas('binaryblacklist', [
            'id' => $ruleId,
            'status' => BlacklistConstants::BLACKLIST_ENABLED,
        ]);
        $this->assertNull($sweeps->status()['current']['rule_id']);
        $this->get(route('poster-identity', ['name' => $identity]))->assertOk()->assertDontSee('data-sweep-status', false)->assertSee('Blacklisted (rule #'.$ruleId.')');
    }

    public function test_confirmation_saves_the_group_scope_that_was_displayed(): void
    {
        $identity = 'poster@example.test';
        $this->release('Movie release', $identity, '2026-08-03 12:00:00', 'alt.binaries.movies');
        $admin = $this->verifiedUser('Admin');
        $previewToken = $this->blacklistPreviewToken($admin, $identity);

        $this->release('Later TV release', $identity, '2026-08-04 12:00:00', 'alt.binaries.tv');

        $this->actingAs($admin)->post(route('admin.poster-identity.blacklist'), [
            'name' => $identity,
            'preview_token' => $previewToken,
        ])->assertRedirect(route('poster-identity', ['name' => $identity]));

        $this->assertDatabaseHas('binaryblacklist', [
            'groupname' => '^(?:alt\.binaries\.movies)$',
        ]);
    }

    public function test_poster_identity_query_uses_the_composite_index(): void
    {
        $plan = DB::select(
            'EXPLAIN QUERY PLAN SELECT id FROM releases WHERE fromname = ? ORDER BY postdate DESC',
            ['poster@example.test'],
        );

        $this->assertStringContainsString(
            'ix_releases_fromname_postdate',
            implode(' ', array_map(static fn (object $row): string => (string) $row->detail, $plan)),
        );
    }

    public function test_poster_identity_rows_include_spectrogram_availability_without_per_release_queries(): void
    {
        $identity = 'audio-poster@example.test';
        $withSpectrogram = $this->release('Audio with spectrogram', $identity, '2026-08-03 12:00:00');
        $withoutSpectrogram = $this->release('Audio without spectrogram', $identity, '2026-08-02 12:00:00');
        DB::table('release_audio_tags')->insert([
            ['releases_id' => $withSpectrogram->id, 'has_spectrogram' => 1, 'genre' => null],
            ['releases_id' => $withoutSpectrogram->id, 'has_spectrogram' => 0, 'genre' => 'Jazz'],
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $rows = app(ReleaseBrowseService::class)->getPosterIdentityReleases($identity, 20);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([true, false], $rows->map(
            static fn (Release $release): bool => (bool) $release->has_spectrogram,
        )->all());
        $this->assertSame([false, true], $rows->map(
            static fn (Release $release): bool => (bool) $release->has_media_info,
        )->all());

        $audioTagQueries = array_filter(
            $queries,
            static fn (array $query): bool => str_contains(strtolower($query['query']), 'release_audio_tags'),
        );
        $this->assertCount(1, $audioTagQueries);
    }

    public function test_poster_page_supplies_complete_release_rows_with_processing_state(): void
    {
        $user = $this->verifiedUser();
        $release = $this->release('Scene.Name', 'exact <poster@example.test>', '2026-09-12 23:30:00', 'alt.binaries.movies');
        DB::table('releases')->where('id', $release->id)->update([
            'display_name' => 'Scene Name', 'size' => 524288000, 'isrenamed' => 1,
            'nfostatus' => -9, 'passwordstatus' => 0,
        ]);

        $response = $this->actingAs($user)->get(route('poster-identity', ['name' => 'exact <poster@example.test>']));
        $response->assertOk()->assertSee('<td class="tv-num tv-size">500 MB</td>', false);
        $row = $response->viewData('rows')[0] ?? null;
        $this->assertNotNull($row);
        $this->assertSame('Scene Name', $row->name);
        $this->assertSame(['Movies > SD', 'SD', 2030], [$row->categoryPath, $row->category, $row->categoryId]);
        $this->assertSame('500 MB', $row->size);
        $this->assertSame('Sep 12, 2026', $row->postedOn);
        $this->assertSame('alt.binaries.movies', $row->group);
        $this->assertSame('exact <poster@example.test>', $row->uploader);
        // the rows come from the shared loader, which carries the DTO's processing decisions
        foreach ([[-1, 0, null, false], [-8, 0, null, false], [0, -1, null, false], [1, 0, 'active-claim', false], [-10, 0, null, true], [0, 0, null, true]] as [$nfo, $password, $claim, $done]) {
            DB::table('releases')->where('id', $release->id)->update([
                'nfostatus' => $nfo, 'passwordstatus' => $password, 'additional_pp_claim_token' => $claim,
            ]);
            $this->assertSame($done, app(ReleaseRowFacts::class)->load([(int) $release->id])[0]->row_data->pp_done, 'nfo '.$nfo.' password '.$password);
            $this->get(route('poster-identity', ['name' => 'exact <poster@example.test>']))->assertOk()->assertSee('Scene Name');
        }
    }

    public function test_passworded_poster_rows_render_the_loaded_password_fact(): void
    {
        $user = $this->verifiedUser();
        Settings::query()->updateOrCreate(['name' => 'showpasswordedrelease'], ['value' => '1']);
        $release = $this->release('Passworded release', 'locked-poster', '2026-09-12 23:30:00');
        DB::table('releases')->where('id', $release->id)->update(['passwordstatus' => 1]);

        $response = $this->actingAs($user)->get(route('poster-identity', ['name' => 'locked-poster']))->assertOk();
        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $chips = (new DOMXPath($document))->query('//span[@data-chip-variant="password" and normalize-space(.)="Password"]');
        $this->assertSame(1, $chips->length);
    }

    public function test_row_entity_and_basket_state_belong_to_the_current_viewer(): void
    {
        $user = $this->verifiedUser();
        $release = $this->release('Matched movie', 'movie-poster', '2026-09-12 23:30:00', 'alt.binaries.movies');
        Schema::create('movieinfo', function (Blueprint $table): void {
            $table->string('imdbid')->primary();
            $table->string('title');
            $table->integer('year');
        });
        DB::table('movieinfo')->insert(['imdbid' => '1234567', 'title' => 'Example Movie', 'year' => 2026]);
        DB::table('releases')->where('id', $release->id)->update(['imdbid' => '1234567']);
        DB::table('users_releases')->insert(['users_id' => $user->id, 'releases_id' => $release->id]);
        DB::table('user_movies')->insert(['users_id' => $user->id, 'imdbid' => '1234567']);
        $response = $this->actingAs($user)->get(route('poster-identity', ['name' => 'movie-poster']))->assertOk();
        $row = $response->viewData('rows')[0];
        $this->assertSame(['film', 'Example Movie · 2026', 'movies', '1234567', 'Example Movie'], [$row->entityKind, $row->entityLine, $row->followRoot, $row->followId, $row->followTitle]);
        $this->assertNull($row->entityUrl, 'no film page without a stored film');
        $this->assertTrue($row->inCart);
        $this->assertTrue($row->watched);
        $response->assertSee('<span class="tv-game-line" data-entity="film">Example Movie · 2026</span>', false)
            ->assertSee('data-watch-key="movies:1234567" data-watch-title="Example Movie" data-watched="1"', false)
            ->assertSee('data-cart="'.$release->guid.'" aria-pressed="true"', false);

        session()->flush();
        $response = $this->actingAs($this->verifiedUser())->get(route('poster-identity', ['name' => 'movie-poster']))->assertOk();
        $otherRow = $response->viewData('rows')[0];
        $this->assertFalse($otherRow->inCart);
        $this->assertFalse($otherRow->watched);
        $response->assertSee('data-watched="0"', false)->assertSee('aria-pressed="false"', false);
    }

    public function test_anime_rows_use_the_matched_anidb_title_without_inventing_an_adult_entity(): void
    {
        $user = $this->verifiedUser();
        $release = $this->release('Anime.Release', 'anime-poster', '2026-09-12 23:30:00');
        Schema::create('anidb_info', function (Blueprint $table): void {
            $table->integer('anidbid')->primary();
            $table->date('startdate')->nullable();
        });
        Schema::create('anidb_titles', function (Blueprint $table): void {
            $table->integer('anidbid');
            $table->string('type');
            $table->string('lang');
            $table->string('title');
            $table->primary(['anidbid', 'type', 'lang', 'title']);
        });
        DB::table('anidb_info')->insert(['anidbid' => 42, 'startdate' => '2020-03-01']);
        DB::table('anidb_titles')->insert([
            ['anidbid' => 42, 'type' => 'official', 'lang' => 'en', 'title' => 'Example Anime'],
            ['anidbid' => 42, 'type' => 'main', 'lang' => 'x-jat', 'title' => 'Romanized title'],
            ['anidbid' => 42, 'type' => 'official', 'lang' => 'ja', 'title' => 'Native title'],
        ]);
        DB::table('releases')->where('id', $release->id)->update(['anidbid' => 42, 'categories_id' => Category::TV_ANIME]);
        DB::table('root_categories')->insert(['id' => 5000, 'title' => 'TV']);
        DB::table('categories')->insert(['id' => Category::TV_ANIME, 'title' => 'Anime', 'root_categories_id' => 5000]);
        // the viewer may see TV (a root without its permission is hidden from every list)
        $user->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'view tv', 'guard_name' => 'web']));
        Cache::flush();
        // the shared loader names the anime; the generic row prints no entity line for it (SPEC 5.5 names films, shows, albums and games)
        $entity = app(ReleaseRowFacts::class)->load([(int) $release->id])[0]->row_data->entity;
        $this->assertNotNull($entity);
        $this->assertSame('anime', $entity->root);
        $this->assertSame('Example Anime', $entity->title);
        $this->assertSame('2020', $entity->year);
        $response = $this->actingAs($user)->get(route('poster-identity', ['name' => 'anime-poster']))->assertOk();
        $row = $response->viewData('rows')[0];
        $this->assertSame([null, '', null], [$row->entityKind, $row->entityLine, $row->followRoot]);
        $this->assertSame('TV > Anime', $row->categoryPath);
        $response->assertDontSee('data-entity=', false)->assertDontSee('data-watch-picker', false);

        DB::table('releases')->where('id', $release->id)->update(['categories_id' => Category::XXX_ROOT]);
        $this->assertNull(app(ReleaseRowFacts::class)->load([(int) $release->id])[0]->row_data->entity);
        $this->assertSame('', $this->get(route('poster-identity', ['name' => 'anime-poster']))->assertOk()->viewData('rows')[0]->entityLine);
    }

    private function verifiedUser(string $roleName = 'User'): User
    {
        $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $permission = Permission::query()->firstOrCreate(['name' => 'view movies', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        $user = $this->user();
        if ($roleName === 'Admin') {
            DB::table('users')->where('id', $user->id)->update(['roles_id' => 2]);
            $user->roles_id = 2;
        }
        $user->assignRole($role);
        $user->givePermissionTo($permission);

        return $user;
    }

    private function blacklistPreviewToken(User $admin, string $identity): string
    {
        $response = $this->actingAs($admin)->get(route('poster-identity', ['name' => $identity]));
        $response->assertOk();

        $input = $this->htmlElement($response->getContent(), '//input[@name="preview_token"]');
        $this->assertNotNull($input);

        return $input->getAttribute('value');
    }

    private function htmlElement(string $html, string $query): ?DOMElement
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $element = (new DOMXPath($document))->query($query)?->item(0);

        return $element instanceof DOMElement ? $element : null;
    }

    private function hasAlpineDataAncestor(DOMElement $element, string $component): bool
    {
        for ($ancestor = $element->parentElement; $ancestor !== null; $ancestor = $ancestor->parentElement) {
            if ($ancestor->getAttribute('x-data') === $component) {
                return true;
            }
        }

        return false;
    }

    private function release(string $name, string $identity, string $postdate, ?string $groupName = null): Release
    {
        $groupId = $groupName === null
            ? null
            : DB::table('usenet_groups')->where('name', $groupName)->value('id')
                ?? DB::table('usenet_groups')->insertGetId(['name' => $groupName]);
        $id = DB::table('releases')->insertGetId([
            'name' => $name,
            'searchname' => $name,
            'fromname' => $identity,
            'postdate' => $postdate,
            'adddate' => $postdate,
            'guid' => sha1($name.$identity.$postdate),
            'categories_id' => 2030,
            'groups_id' => $groupId,
            'size' => 1024,
            'totalpart' => 1,
        ]);

        return Release::query()->findOrFail($id);
    }

    private function user(bool $verified = true): User
    {
        $id = DB::table('users')->insertGetId([
            'username' => 'poster-user-'.bin2hex(random_bytes(3)),
            'email' => bin2hex(random_bytes(3)).'@example.test',
            'password' => bcrypt('secret'),
            'roles_id' => 1,
            'api_token' => bin2hex(random_bytes(16)),
            'verified' => $verified,
            'can_post' => true,
            'theme_preference' => 'light',
            'email_verified_at' => $verified ? now() : null,
            'lastlogin' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
    }

    private function createSchema(): void
    {
        Schema::create('users_releases', function (Blueprint $table): void {
            $table->integer('users_id');
            $table->integer('releases_id');
            $table->unique(['users_id', 'releases_id']);
        });
        Schema::create('user_movies', function (Blueprint $table): void {
            $table->integer('users_id');
            $table->string('imdbid');
        });

        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }

        Schema::create('roles', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name')->default('web');
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name')->default('web');
            $table->timestamps();
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
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('username');
            $table->string('email');
            $table->string('password');
            $table->unsignedInteger('roles_id')->default(1);
            $table->integer('rate_limit')->default(60);
            $table->string('api_token')->nullable()->unique();
            $table->boolean('verified')->default(true);
            $table->boolean('can_post')->default(true);
            $table->string('theme_preference', 10)->default('light');
            $table->string('session_token')->nullable();
            $table->text('view_prefs')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('lastlogin')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('user_excluded_categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('users_id');
            $table->unsignedInteger('categories_id');
            $table->unique(['users_id', 'categories_id']);
        });
        Schema::create('content', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title')->default('');
            $table->string('url')->nullable();
            $table->text('body')->nullable();
            $table->text('metadescription')->nullable();
            $table->text('metakeywords')->nullable();
            $table->integer('contenttype')->default(1);
            $table->integer('status')->default(1);
            $table->integer('ordinal')->nullable();
            $table->integer('role')->default(0);
        });
        Schema::create('root_categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->integer('status')->default(1);
            $table->timestamps();
        });
        Schema::create('categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->unsignedInteger('root_categories_id')->nullable();
            $table->integer('status')->default(1);
            $table->text('description')->nullable();
        });
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->unique();
        });
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('searchname');
            $table->float('completion')->default(0);
            $table->integer('declaredfiles')->nullable();
            $table->integer('nzbstatus')->default(1);
            $table->string('display_name')->nullable();
            $table->string('fromname')->nullable();
            $table->dateTime('postdate')->nullable();
            $table->dateTime('adddate')->nullable();
            $table->string('guid')->unique();
            $table->unsignedInteger('categories_id');
            $table->unsignedInteger('groups_id')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->integer('totalpart')->default(0);
            $table->integer('passwordstatus')->default(0);
            $table->integer('grabs')->default(0);
            $table->integer('comments')->default(0);
            $table->unsignedInteger('videos_id')->nullable();
            $table->boolean('haspreview')->default(false);
            $table->boolean('jpgstatus')->default(false);
            $table->boolean('nfostatus')->default(false);
            $table->integer('videostatus')->default(0);
            $table->integer('isrenamed')->default(0);
            // the redesigned rows read the resolution and source of every release
            $table->unsignedTinyInteger('resolution')->default(0);
            $table->unsignedTinyInteger('source')->default(0);
            $table->string('additional_pp_claim_token')->nullable();
            $table->string('imdbid')->nullable();
            foreach (['tv_episodes_id', 'musicinfo_id', 'consoleinfo_id', 'gamesinfo_id', 'bookinfo_id', 'anidbid', 'movieinfo_id'] as $column) {
                $table->integer($column)->nullable();
            }
            $table->index(['fromname', 'postdate'], 'ix_releases_fromname_postdate');
        });
        Schema::create('release_audio_tags', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id')->primary();
            $table->string('album')->nullable();
            $table->string('album_performer')->nullable();
            $table->string('performer')->nullable();
            $table->string('genre')->nullable();
            $table->string('recorded_date')->nullable();
            $table->string('track_name')->nullable();
            $table->unsignedSmallInteger('track_position')->nullable();
            $table->unsignedSmallInteger('track_position_total')->nullable();
            $table->string('musicbrainz_album_id')->nullable();
            $table->string('musicbrainz_track_id')->nullable();
            $table->string('audio_format')->nullable();
            $table->unsignedTinyInteger('has_preview')->default(0);
            $table->string('preview_extension')->nullable();
            $table->string('preview_mime')->nullable();
            $table->unsignedSmallInteger('preview_seconds')->nullable();
            $table->unsignedTinyInteger('has_spectrogram')->default(0);
        });
        Schema::create('release_video_clips', function (Blueprint $table): void {
            $table->unsignedInteger('releases_id')->primary();
            $table->string('extension', 8);
            $table->string('mime', 32);
        });
        Schema::create('binaryblacklist', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('groupname');
            $table->text('regex');
            $table->string('description')->nullable();
            $table->integer('status')->default(1);
            $table->integer('optype')->default(1);
            $table->integer('msgcol')->default(1);
            $table->timestamp('last_activity')->nullable();
        });
    }

    private function resetGlobalComposerState(): void
    {
        $property = (new ReflectionClass(GlobalDataComposer::class))->getProperty('resolvedData');
        $property->setValue(null, null);
    }
}
