<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class AdminRecoveredReleasesTest extends TestCase
{
    use InteractsWithAdminListPages;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage(itemsPerPage: 2);
        Schema::create('usenet_groups', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('guid');
            $table->string('searchname');
            $table->unsignedInteger('groups_id');
            $table->unsignedInteger('categories_id')->default(1);
            $table->unsignedBigInteger('size')->default(1024);
            $table->dateTime('adddate');
            $table->dateTime('postdate');
        });
        (require database_path('migrations/2026_09_07_172435_add_obfuscation_recovery_storage.php'))->up();
        (require database_path('migrations/2026_09_13_002751_add_recovery_frontier_evidence.php'))->up();
        DB::table('usenet_groups')->insert([
            ['id' => 1, 'name' => 'alt.binaries.example'],
            ['id' => 2, 'name' => 'alt.binaries.other'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_only_current_completed_recovery_publications_are_listed_even_after_renaming(): void
    {
        $this->publication(1, 'Later.Renamed.Title');
        $this->release(2, 'Ordinary.Release');
        $this->publication(3, 'Still.Initializing', ['initialization_state' => 'pending']);
        $this->publication(4, 'Stale.Identity', ['guid' => 'old-guid']);
        $this->publication(5, 'Discarded.Release', ['state' => 'duplicate_policy_discarded']);
        $this->publication(6, 'Duplicate.Survivor', ['state' => 'absorbed']);
        $this->publication(7, 'Deleted.Publication', ['deleted_at' => now()]);
        $this->publication(8, 'Missing.Release');
        DB::table('releases')->where('id', 8)->delete();
        $this->publication(9, 'Unfinished.Publication', ['state' => 'materializing']);

        $response = $this->actingAs($this->admin())->get('/admin/recovered-releases');

        $response->assertOk()->assertSee('Later.Renamed.Title')->assertSee('Recovery method');
        foreach (['Ordinary.Release', 'Still.Initializing', 'Stale.Identity', 'Discarded.Release',
            'Duplicate.Survivor', 'Deleted.Publication', 'Missing.Release', 'Unfinished.Publication'] as $excluded) {
            $response->assertDontSee($excluded);
        }
        $response->assertSee('1–1 of 1 recovered releases');
    }

    public function test_group_filter_date_sorts_counts_and_pagination_preserve_the_list(): void
    {
        foreach ([1, 2, 3, 4] as $id) {
            $this->publication($id, 'Recovered.Title.'.$id);
            DB::table('releases')->where('id', $id)->update([
                'groups_id' => $id === 4 ? 2 : 1,
                'adddate' => '2026-09-0'.$id.' 12:00:00',
                'postdate' => '2026-08-0'.(5 - $id).' 12:00:00',
            ]);
        }
        $this->actingAs($this->admin());
        foreach (['added-desc' => [3, 2, 1], 'added-asc' => [1, 2, 3],
            'posted-desc' => [1, 2, 3], 'posted-asc' => [3, 2, 1]] as $sort => $ids) {
            $response = $this->get('/admin/recovered-releases?group=1&sort='.$sort);
            $response->assertOk()->assertSee('1–2 of 3 recovered releases')
                ->assertSeeInOrder(['Recovered.Title.'.$ids[0], 'Recovered.Title.'.$ids[1]])
                ->assertDontSee('Recovered.Title.4');
            $link = $this->pageLink((string) $response->getContent(), 2);
            $this->assertStringContainsString('group=1', $link);
            $this->assertStringContainsString('sort='.$sort, $link);
            $this->get(html_entity_decode($link))->assertOk()
                ->assertSee('Recovered.Title.'.$ids[2])->assertSee('3–3 of 3 recovered releases');
        }
        $this->get('/admin/recovered-releases?group=99')->assertOk()
            ->assertSee('No recovered releases')->assertSee('0–0 of 0 recovered releases');
    }

    public function test_recovery_details_describe_recorded_naming_and_files_without_claiming_full_verification(): void
    {
        $this->publication(1, 'Name.Changed.After.Recovery', [
            'identity_outcome' => 'descriptive_bundle', 'multi_media_inventory' => true,
            'protected_files' => 2, 'planned_files' => 3,
            'canonical_bundle_id' => 1, 'canonical_revision' => 1,
            'enrichment_outcome' => 'cached_evidence_available',
            'sealed_plan' => json_encode(['files' => [
                ['identity' => 'first', 'role' => 'media', 'display_name' => 'First.Episode.mkv'],
                ['identity' => 'second', 'role' => 'media', 'display_name' => 'Second.Episode.mkv'],
                ['identity' => 'index', 'role' => 'index', 'display_name' => 'episodes.par2'],
            ]], JSON_THROW_ON_ERROR),
        ]);
        DB::table('obfuscation_recovery_files')->insert([
            'bundle_id' => 1, 'revision' => 1, 'groups_id' => 1, 'profile' => 'nyuu-media-v1',
            'run_digest' => 'first', 'file_id' => 'first', 'observed_count' => 1, 'start_ms' => 1, 'end_ms' => 2,
            'state' => 'verified', 'enrichment_outcome' => 'media_evidence_available',
        ]);

        $this->actingAs($this->admin())->get('/admin/recovered-releases')->assertOk()
            ->assertSee('Bundle name')->assertSee('Multiple media files')->assertSee('Recovery details')
            ->assertSee('Media information found')->assertSee('1 of 2 media files')
            ->assertSee('2 media files + PAR2 index')->assertSee('First.Episode.mkv')
            ->assertSee('Second.Episode.mkv')->assertSee('episodes.par2')
            ->assertSee('Later processing may have changed the current release name.')
            ->assertSee('The complete payload has not been verified.')
            ->assertDontSee('epoch')->assertDontSee(str_repeat('c', 64));
    }

    private function pageLink(string $html, int $page): string
    {
        preg_match('/href="([^"]*[?&](?:amp;)?page='.$page.'[^"]*)"/', $html, $matches);
        $this->assertNotEmpty($matches, 'Expected a rendered pagination link.');

        return $matches[1];
    }

    public function test_admin_navigation_and_authorization_and_invalid_filters(): void
    {
        $this->actingAs($this->admin())->get('/admin/recovered-releases')->assertOk()
            ->assertSee('href="'.route('admin.recovered-releases').'"', false);
        foreach (['sort' => 'drop table releases', 'group' => '-1', 'page' => '0'] as $key => $value) {
            $this->getJson('/admin/recovered-releases?'.http_build_query([$key => $value]))
                ->assertUnprocessable()->assertJsonValidationErrors($key);
        }
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admins_cannot_access_recovered_releases(string $role): void
    {
        $this->actingAs($this->createUserWithRole($role))->get('/admin/recovered-releases')->assertForbidden();
    }

    /** @return array<string, array{string}> */
    public static function nonAdminRoles(): array
    {
        return ['member' => ['User'], 'moderator' => ['Moderator']];
    }

    public function test_guests_must_log_in(): void
    {
        $this->get('/admin/recovered-releases')->assertRedirect(route('login'));
    }

    public function test_rar_partial_inspection_and_retired_or_absent_details_are_honest(): void
    {
        $this->publication(1, 'Archive.Release', [
            'profile' => 'nyuu-rar-sequential-v1', 'identity_outcome' => 'par2_naming_disabled',
            'canonical_bundle_id' => 1, 'canonical_revision' => 2,
            'protected_files' => 5, 'planned_files' => 6, 'enrichment_outcome' => 'cached_evidence_available',
            'sealed_plan' => json_encode(['files' => [
                ...array_map(fn (int $id): array => ['identity' => 'vol'.$id, 'role' => 'rar_volume', 'display_name' => 'archive.part'.$id.'.rar'], range(1, 5)),
                ['role' => 'index', 'display_name' => 'archive.par2'],
            ]], JSON_THROW_ON_ERROR),
        ]);
        DB::table('obfuscation_recovery_files')->insert([
            'bundle_id' => 1, 'revision' => 2, 'groups_id' => 1, 'profile' => 'nyuu-rar-sequential-v1',
            'run_digest' => 'vol1', 'file_id' => 'vol1', 'observed_count' => 1, 'start_ms' => 1, 'end_ms' => 2,
            'state' => 'verified', 'enrichment_outcome' => 'partial_archive_listing',
        ]);
        $this->actingAs($this->admin())->get('/admin/recovered-releases')->assertOk()
            ->assertSee('Naming disabled')->assertSee('Partial archive listing')
            ->assertDontSee('Media information found')->assertDontSee('Multiple media files')
            ->assertSee('5 RAR volumes + PAR2 index')->assertSee('archive.par2')
            ->assertSee('Show all 5 volume filenames');

        foreach ([
            ['identified', 'enrichment_pending', 'Named by recovery', 'Inspection pending'],
            ['identity_unresolved', 'bounded_evidence_unavailable', 'Name unresolved', 'No information within limits'],
            ['unresolved', null, 'Naming result unavailable', 'Inspection details unavailable'],
        ] as [$naming, $inspection, $namingLabel, $inspectionLabel]) {
            DB::table('obfuscation_recovery_publications')->where('id', 1)->update([
                'identity_outcome' => $naming, 'enrichment_outcome' => $inspection,
                'detail_retired_at' => now(), 'sealed_plan' => '{}',
            ]);
            $this->get('/admin/recovered-releases')->assertOk()->assertSee($namingLabel)->assertSee($inspectionLabel)
                ->assertSee('Recovered file details are no longer available.')->assertDontSee('archive.part1.rar');
        }
    }

    public function test_site_status_keeps_services_and_incidents_without_recovery_processing(): void
    {
        (require database_path('migrations/2026_04_01_000000_create_service_statuses_table.php'))->up();
        (require database_path('migrations/2026_04_01_000001_create_service_incidents_table.php'))->up();
        Schema::drop('obfuscation_recovery_publications');
        $this->actingAs($this->admin())->get(route('admin.status.index'))->assertOk()
            ->assertSee('Service health')->assertSee('API')->assertSee('No incidents yet.')
            ->assertDontSee('Recovery Processing');
    }

    /** @param array<string, mixed> $overrides */
    private function release(int $id, string $name, array $overrides = []): void
    {
        DB::table('releases')->insert(array_replace([
            'id' => $id, 'guid' => 'guid-'.$id, 'searchname' => $name, 'groups_id' => 1,
            'adddate' => '2026-09-08 12:00:00', 'postdate' => '2026-09-01 12:00:00',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function publication(int $id, string $name, array $overrides = []): void
    {
        $this->release($id, $name);
        DB::table('obfuscation_recovery_publications')->insert(array_replace([
            'id' => $id, 'identity' => hash('sha256', 'identity'.$id),
            'index_identity' => hash('sha256', 'index'.$id), 'index_message_id' => '<index'.$id.'@example.test>',
            'set_id' => str_repeat('a', 32), 'plan_digest' => str_repeat('b', 64),
            'collection_projection' => sha1('collection'.$id, true), 'releases_id' => $id, 'guid' => 'guid-'.$id,
            'profile' => 'nyuu-media-v1', 'group_name' => 'alt.binaries.example', 'source_epoch' => 'epoch',
            'state' => 'published', 'initialization_state' => 'complete',
            'ordering_mode' => 'media_inventory', 'inventory_scope' => 'protected_files',
            'protected_files' => 1, 'planned_files' => 2, 'planned_parts' => 2,
            'sealed_plan' => '{}', 'manifest_digest' => str_repeat('c', 64),
        ], $overrides));
    }
}
