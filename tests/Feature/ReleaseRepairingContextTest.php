<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Settings;
use App\Services\NNTP\NntpProviderPool;
use App\Support\ReleaseRepairingContext;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithSecondaryProviders;
use Tests\Support\ProductionTables;
use Tests\TestCase;

/**
 * The provider half of "still repairing": a secondary provider that may still be reading the
 * post holds the label, and the context reads its cursors once per request whatever is rendered.
 */
final class ReleaseRepairingContextTest extends TestCase
{
    use InteractsWithSecondaryProviders;

    private const string POSTED = '2026-10-01 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC']);
        $tables = ProductionTables::fromAuthority();
        $tables->create('settings', ['name', 'value']);
        $tables->create('usenet_groups', ['id', 'name', 'active']);
        $tables->create('usenet_group_provider_cursors');
        DB::table('usenet_groups')->insert([
            ['id' => 1, 'name' => 'alt.binaries.active', 'active' => 1],
            ['id' => 2, 'name' => 'alt.binaries.retired', 'active' => 0],
        ]);
        $this->configureProviders([
            ['position' => 1, 'name' => 'primary', 'host' => 'news.example.invalid'],
            ['position' => 2, 'name' => 'super', 'host' => 'super.example.invalid'],
            ['position' => 3, 'name' => 'spare', 'host' => 'spare.example.invalid', 'enabled' => false],
        ]);
    }

    protected function tearDown(): void
    {
        NntpProviderPool::forgetConfiguredProviders();
        parent::tearDown();
    }

    public function test_a_secondary_position_before_the_post_plus_delaytime_holds_the_label_and_one_after_does_not(): void
    {
        $this->secondaryPosition(1, '2026-10-01 11:00:00');
        $this->assertTrue($this->context()->secondaryStillReading(1, self::POSTED), 'One hour after the post, inside the default two-hour window.');

        $this->secondaryPosition(1, '2026-10-01 13:00:00');
        $this->assertFalse($this->context()->secondaryStillReading(1, self::POSTED), 'Three hours after the post, past the window.');

        $this->secondaryPosition(1, '2026-10-01 12:00:00');
        $this->assertFalse($this->context()->secondaryStillReading(1, self::POSTED), 'Exactly at the limit is past it.');
    }

    public function test_delaytime_is_read_as_release_formation_reads_it(): void
    {
        $this->secondaryPosition(1, '2026-10-01 11:00:00');
        $this->assertSame(2, $this->context()->delayHours(), 'Missing resolves to 2.');

        Settings::query()->insert(['name' => 'delaytime', 'value' => '']);
        $this->assertSame(2, $this->context()->delayHours(), 'Blank resolves to 2.');
        $this->assertTrue($this->context()->secondaryStillReading(1, self::POSTED), 'Blank: one hour after the post counts.');
        $this->secondaryPosition(1, '2026-10-01 13:00:00');
        $this->assertFalse($this->context()->secondaryStillReading(1, self::POSTED), 'Blank: three hours after does not.');
        $this->secondaryPosition(1, '2026-10-01 11:00:00');

        Settings::query()->where('name', 'delaytime')->update(['value' => '0']);
        $this->assertSame(0, $this->context()->delayHours(), 'A stored 0 is honoured.');
        $this->assertFalse($this->context()->secondaryStillReading(1, self::POSTED));

        Settings::query()->where('name', 'delaytime')->update(['value' => '12']);
        $this->secondaryPosition(1, '2026-10-01 21:59:59');
        $this->assertTrue($this->context()->secondaryStillReading(1, self::POSTED));
    }

    public function test_only_an_enabled_secondary_at_its_current_host_in_an_active_group_counts(): void
    {
        $this->secondaryPosition(1, '2026-10-01 11:00:00', host: 'old.example.invalid');
        $this->assertFalse($this->context()->secondaryStillReading(1, self::POSTED), 'A cursor left at a previous host.');

        $this->secondaryPosition(1, '2026-10-01 11:00:00', provider: 'spare', host: 'spare.example.invalid');
        $this->assertFalse($this->context()->secondaryStillReading(1, self::POSTED), 'A disabled provider.');

        $this->secondaryPosition(1, '2026-10-01 11:00:00', provider: 'primary', host: 'news.example.invalid');
        $this->assertFalse($this->context()->secondaryStillReading(1, self::POSTED), 'Provider 1 keeps its position elsewhere.');

        $this->secondaryPosition(2, '2026-10-01 11:00:00');
        $this->assertFalse($this->context()->secondaryStillReading(2, self::POSTED), 'A deactivated group: its cursor stops moving forever.');

        $this->secondaryPosition(1, null);
        $this->assertFalse($this->context()->secondaryStillReading(1, self::POSTED), 'A position without a date is not earlier than anything.');

        $this->secondaryPosition(1, '2026-10-01 11:00:00');
        $this->assertTrue($this->context()->secondaryStillReading(1, self::POSTED));
        $this->assertFalse($this->context()->secondaryStillReading(3, self::POSTED), 'A group with no cursor.');
        $this->assertFalse($this->context()->secondaryStillReading(null, self::POSTED));
        $this->assertFalse($this->context()->secondaryStillReading(1, null));
    }

    public function test_a_stalled_cursor_in_an_active_group_still_counts(): void
    {
        DB::table('usenet_group_provider_cursors')->insert(['usenet_groups_id' => 1, 'provider' => 'super', 'provider_host' => 'super.example.invalid',
            'last_record' => 10, 'last_record_postdate' => '2026-10-01 11:00:00', 'last_advanced_at' => '2026-09-01 00:00:00']);

        $this->assertTrue($this->context()->secondaryStillReading(1, self::POSTED));
    }

    public function test_the_cursors_are_read_once_per_request_and_never_without_a_secondary_provider(): void
    {
        $this->secondaryPosition(1, '2026-10-01 11:00:00');
        $queries = $this->recordRepairReads();
        $context = $this->context();
        foreach (range(1, 50) as $group) {
            $context->secondaryStillReading($group, self::POSTED);
            $context->target();
        }
        $this->assertSame(['cursors' => 1, 'completionpercent' => 1, 'delaytime' => 1], $queries());

        $this->configureProviders([['position' => 1, 'name' => 'primary', 'host' => 'news.example.invalid']]);
        $queries = $this->recordRepairReads();
        $this->assertFalse($this->context()->secondaryStillReading(1, self::POSTED));
        $this->assertSame(['cursors' => 0, 'completionpercent' => 0, 'delaytime' => 0], $queries());
    }

    public function test_the_context_is_container_scoped_so_the_next_request_reads_afresh(): void
    {
        $this->secondaryPosition(1, '2026-10-01 11:00:00');
        $first = app(ReleaseRepairingContext::class);
        $this->assertSame($first, app(ReleaseRepairingContext::class));
        $this->assertTrue($first->secondaryStillReading(1, self::POSTED));

        $this->secondaryPosition(1, '2026-10-01 13:00:00');
        $this->assertTrue($first->secondaryStillReading(1, self::POSTED), 'Held for the rest of the request.');
        $this->app->forgetScopedInstances();
        $this->assertFalse(app(ReleaseRepairingContext::class)->secondaryStillReading(1, self::POSTED));
    }

    /** A fresh request's context. */
    private function context(): ReleaseRepairingContext
    {
        $this->app->forgetScopedInstances();

        return app(ReleaseRepairingContext::class);
    }
}
