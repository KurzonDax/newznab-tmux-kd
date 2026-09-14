<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\GdprRequest;
use App\Models\Invitation;
use App\Models\PasswordSecurity;
use App\Notifications\VerifyEmailBranded;
use App\Rules\ValidEmailDomain;
use App\Services\Auth\TwoFactorRecoveryCodes;
use App\Services\InvitationService;
use App\Services\RegistrationStatusService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\InteractsWithReleaseBrowser;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class AccountControllerTest extends TestCase
{
    use InteractsWithAdminListPages;
    use InteractsWithReleaseBrowser;
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->withoutVite();
        $emailDomain = \Mockery::mock(ValidEmailDomain::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $emailDomain->shouldReceive('validateDnsRecords')->andReturn(true);
        $this->app->instance(ValidEmailDomain::class, $emailDomain);
        $this->withoutMiddleware(TrustedDevice2FAMiddleware::class);
        $this->createReleaseSchema();
        Schema::table('users', function (Blueprint $table): void {
            $table->string('verification_token')->nullable();
            $table->string('color_scheme')->default('blue');
            $table->integer('invites')->default(0);
        });
        Schema::create('password_securities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->boolean('google2fa_enable')->default(false);
            $table->string('google2fa_secret');
            $table->json('recovery_codes')->nullable();
            $table->timestamps();
        });
        foreach (['user_downloads', 'user_requests'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('users_id');
                $table->unsignedInteger('releases_id')->nullable();
                $table->timestamp('timestamp');
            });
        }
        foreach (['2026_03_10_000000_create_registration_periods_table', '2026_03_10_000001_create_registration_status_history_table', '2026_04_24_000000_create_passkeys_table', '2026_05_08_154620_add_session_token_to_users_table', '2026_06_10_000000_create_trusted_devices_table', '2026_06_17_000000_create_gdpr_requests_table', '2026_09_14_011408_add_view_prefs_to_users_table'] as $migration) {
            (require database_path('migrations/'.$migration.'.php'))->up();
        }
    }

    protected function tearDown(): void
    {
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_sections_render_shared_controls_and_legacy_pages_redirect_to_the_account(): void
    {
        $user = $this->browserUser();
        $this->actingAs($user);
        foreach (['profile', 'appearance', 'security', 'api', 'downloads', 'privacy', 'invitations'] as $section) {
            $response = $this->get('/account?section='.$section)->assertOk()->assertSee('Account');
            $this->assertStringStartsWith('<!DOCTYPE html>', ltrim($response->getContent()));
            $response->assertSee('account-section-'.$section, false);
        }
        $this->get('/profile?id=999')->assertRedirect('/account');
        $this->get('/profileedit')->assertRedirect('/account');
        $this->get('/profileedit?action=newapikey')->assertRedirect('/account?section=api');
        $this->assertSame($user->api_token, $user->fresh()->api_token);
        $this->get('/account?section[]=security')->assertOk()->assertSee('account-section-profile');
    }

    public function test_profile_card_updates_only_its_fields_and_reverifies_a_changed_email(): void
    {
        Notification::fake();
        $user = $this->browserUser();
        $beforePermissions = $user->getDirectPermissions()->pluck('name')->all();
        $this->actingAs($user)->post('/account/profile', ['username' => 'new_name', 'email' => $user->email, 'timezone' => 'America/Chicago'])
            ->assertRedirect('/account?section=profile');
        $this->assertSame('new_name', $user->fresh()->username);
        $this->assertSame('America/Chicago', $user->fresh()->timezone);
        $this->assertSame($beforePermissions, $user->fresh()->getDirectPermissions()->pluck('name')->all());
        $this->postJson('/account/profile', ['username' => 'new_name', 'email' => 'not an email', 'timezone' => 'Mars/Olympus'])->assertUnprocessable();
        $this->post('/account/profile', ['username' => 'new_name', 'email' => 'changed@example.test', 'timezone' => 'UTC'])->assertRedirect('/login');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmailBranded::class);
    }

    public function test_password_and_other_session_actions_require_the_current_password_and_preserve_this_session(): void
    {
        $user = $this->browserUser();
        $this->actingAs($user)->postJson('/account/password', ['current_password' => 'wrong', 'password' => 'NewPassword!123', 'password_confirmation' => 'NewPassword!123'])->assertUnprocessable();
        $this->post('/account/password', ['current_password' => 'password', 'password' => 'NewPassword!123', 'password_confirmation' => 'NewPassword!123'])->assertRedirect('/account?section=security');
        $this->assertTrue(Hash::check('NewPassword!123', $user->fresh()->password));
        $this->post('/account/sessions', ['current_password' => 'NewPassword!123'])->assertRedirect('/account?section=security');
        $this->get('/account?section=security')->assertOk();
        $this->assertNotEmpty($user->fresh()->session_token);
    }

    public function test_api_rotation_and_usage_are_scoped_to_the_authenticated_user(): void
    {
        $user = $this->browserUser();
        $other = $this->browserUser();
        DB::table('user_requests')->insert([['users_id' => $user->id, 'timestamp' => now()], ['users_id' => $other->id, 'timestamp' => now()], ['users_id' => $user->id, 'timestamp' => now()->subDays(2)]]);
        $response = $this->actingAs($user)->get('/account?section=api')->assertOk()->assertSee('API requests (24 h)')->assertSee('Copy');
        $this->assertSame(1, $response->viewData('apiRequests'));
        $this->post('/account/api-key')->assertRedirect('/account?section=api');
        $this->assertNotSame($user->api_token, $user->fresh()->api_token);
        $this->assertSame($other->api_token, $other->fresh()->api_token);
    }

    public function test_recovery_codes_require_enabled_two_factor_and_are_stored_only_as_hashes(): void
    {
        $user = $this->browserUser();
        $this->actingAs($user)->postJson('/account/recovery-codes', ['current_password' => 'password'])->assertUnprocessable();
        PasswordSecurity::query()->create(['user_id' => $user->id, 'google2fa_enable' => true, 'google2fa_secret' => 'JBSWY3DPEHPK3PXP']);
        $response = $this->post('/account/recovery-codes', ['current_password' => 'password'])->assertRedirect('/account?section=security');
        $codes = session('recovery_codes');
        $this->assertCount(10, $codes);
        $stored = DB::table('password_securities')->where('user_id', $user->id)->value('recovery_codes');
        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, $stored);
        }
        $this->get('/account?section=security')->assertOk()->assertSee($codes[0]);
        $this->get('/account?section=security')->assertOk()->assertDontSee($codes[0]);
    }

    public function test_a_recovery_code_completes_only_its_owners_pending_login_once(): void
    {
        $user = $this->browserUser();
        PasswordSecurity::query()->create(['user_id' => $user->id, 'google2fa_enable' => true, 'google2fa_secret' => 'JBSWY3DPEHPK3PXP']);
        $codes = app(TwoFactorRecoveryCodes::class)->generate($user);
        $this->withSession(['2fa:user:id' => $user->id])->post('/2fa/verify', ['one_time_password' => $codes[0]])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertCount(9, $user->passwordSecurity->fresh()->recovery_codes);
        Auth::logout();
        $this->flushSession();
        $this->withSession(['2fa:user:id' => $user->id])->post('/2fa/verify', ['one_time_password' => $codes[0]])->assertRedirect(route('2fa.verify'));
        $this->assertGuest();
        $this->assertFalse(app(TwoFactorRecoveryCodes::class)->consume($this->browserUser(), $codes[1]));
    }

    public function test_zero_quotas_are_numeric_and_invalid_category_checkboxes_write_nothing(): void
    {
        $user = $this->browserUser();
        $this->actingAs($user)->get('/account?section=api')->assertOk()->assertDontSee('Unlimited')->assertSee('0 / 0');
        $this->postJson('/account/categories', ['viewmovies' => 0])->assertUnprocessable();
        $this->assertTrue($user->fresh()->hasDirectPermission('view movies'));
    }

    public function test_legacy_disable_revokes_recovery_codes_before_reenabling(): void
    {
        $user = $this->browserUser();
        PasswordSecurity::query()->create(['user_id' => $user->id, 'google2fa_enable' => true, 'google2fa_secret' => 'JBSWY3DPEHPK3PXP']);
        $recovery = app(TwoFactorRecoveryCodes::class);
        $codes = $recovery->generate($user);
        $this->actingAs($user)->post('/profile-security/disable-2fa', ['current_password' => 'password'])->assertRedirect('/account?section=security');
        $this->assertNull($user->passwordSecurity->fresh()->recovery_codes);
        $user->passwordSecurity->fresh()->update(['google2fa_enable' => true]);
        $this->assertFalse($recovery->consume($user, $codes[0]));
    }

    public function test_privacy_pagination_keeps_the_account_section_and_policy_starts_with_its_document(): void
    {
        $user = $this->browserUser();
        foreach (range(1, 11) as $index) {
            GdprRequest::query()->create(['user_id' => $user->id, 'type' => 'export', 'status' => 'completed']);
        }
        $response = $this->actingAs($user)->get('/account?section=privacy')->assertOk();
        $this->assertStringContainsString('section=privacy', $response->viewData('gdprRequests')->nextPageUrl());
        $response = $this->get('/privacy-policy')->assertOk();
        $this->assertStringStartsWith('<!DOCTYPE html>', ltrim($response->getContent()));
        $response->assertSee('Privacy Policy');
    }

    public function test_themed_errors_render_without_site_database_or_authentication_queries(): void
    {
        $this->resetGlobalComposerState();
        Cache::flush();
        Schema::drop('settings');
        Schema::drop('content');
        Auth::shouldReceive('check')->never();
        Session::shouldReceive('token')->never();
        foreach (['403', '404', '419', '429', '500', '503'] as $code) {
            $html = view('errors.'.$code, ['exception' => new HttpException((int) $code)])->render();
            $this->assertStringStartsWith('<!DOCTYPE html>', ltrim($html));
            $this->assertStringContainsString('Back to home', $html);
            $this->assertStringContainsString($code, $html);
        }
    }

    public function test_invitation_sections_keep_pagination_and_guest_invitation_links_work(): void
    {
        (require database_path('migrations/2025_01_01_000000_create_invitations_table.php'))->up();
        config(['nntmux.user_roles' => [1 => 'User']]);
        $user = $this->browserUser();
        $user->forceFill(['invites' => 40])->save();
        $status = app(RegistrationStatusService::class)->resolve();
        $this->mock(RegistrationStatusService::class)->shouldReceive('resolve')->andReturn([...$status, 'is_invite_only' => true, 'is_closed' => false, 'is_open' => false]);
        foreach (range(1, 16) as $index) {
            $invitation = Invitation::query()->create(['invited_by' => $user->id, 'token' => hash('sha256', (string) $index), 'email' => 'invited'.$index.'@example.test', 'expires_at' => now()->addDays(7), 'is_active' => true]);
        }
        $response = $this->actingAs($user)->get('/account?section=invitations&status=pending')->assertOk();
        $response->assertSee('section=invitations&amp;status=pending&amp;page=2', false);
        $this->get('/invitations/create')->assertOk()->assertSee('System default')->assertSee('account-section-invitations');
        $this->partialMock(InvitationService::class, function ($mock) use ($user, $invitation): void {
            $mock->shouldReceive('createAndSendInvitation')->once()->with('new@example.test', $user->id, 7, [])->andReturn($invitation);
        });
        $this->post(route('invitations.store'), ['email' => 'new@example.test', 'expiry_days' => 7, 'role' => ''])->assertRedirect(route('invitations.index'))->assertSessionHas('success');
        Auth::logout();
        $this->flushSession();
        $this->resetGlobalComposerState();
        $response = $this->get(route('invitation.show', $invitation->token))->assertOk()->assertSee('Accept invitation')->assertSee($user->username);
        $this->assertStringStartsWith('<!DOCTYPE html>', ltrim($response->getContent()));
    }

    public function test_public_api_help_uses_shared_page_headings_and_key_fields(): void
    {
        $this->actingAs($this->browserUser());
        foreach (['/apihelp', '/apiv2help'] as $url) {
            $response = $this->get($url)->assertOk()->assertSee('public-docs')->assertSee('ui-control ui-field')->assertSee('apikeyInput');
            $this->assertStringStartsWith('<!DOCTYPE html>', ltrim($response->getContent()));
        }
    }
}
