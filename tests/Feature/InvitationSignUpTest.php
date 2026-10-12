<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Settings;
use App\Models\User;
use App\Rules\ValidEmailDomain;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Support\Admin\InteractsWithAdminListPages;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\Support\ProductionTables;
use Tests\Support\ReadsRenderedMarkup;
use Tests\TestCase;

/**
 * Issue #1057: a sign-up started from an invitation link completes with that invitation.
 *
 * Every test opens the register page the way the link does, with the code in the address, and
 * then posts exactly the fields the rendered form holds. A test that puts the code in the POST
 * by hand proves nothing: the form never sent it.
 */
final class InvitationSignUpTest extends TestCase
{
    use InteractsWithAdminListPages;
    use IsolatedSqliteDatabase;
    use ReadsRenderedMarkup;

    private const CODE = 'invented-invitation-code-0123456789';

    private const PASSWORD = 'Invented-Passw0rd!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        $this->bootAdminListPage();
        $this->withoutVite();
        Mail::fake();
        Notification::fake();
        Event::fake();
        config(['captcha.enabled' => false, 'captcha.recaptcha.enabled' => false, 'captcha.turnstile.enabled' => false]);

        $this->app->instance(UncompromisedVerifier::class, new class implements UncompromisedVerifier
        {
            public function verify($data): bool
            {
                return true;
            }
        });
        $this->app->bind(ValidEmailDomain::class, fn () => new class extends ValidEmailDomain
        {
            protected function validateDnsRecords(string $domain): bool
            {
                return true;
            }
        });

        foreach (['registration_periods', 'invitations'] as $table) {
            ProductionTables::fromAuthority()->create($table);
        }
        Role::query()->create(['name' => 'User', 'guard_name' => 'web', 'isdefault' => true, 'defaultinvites' => 1, 'rate_limit' => 60]);
    }

    protected function tearDown(): void
    {
        $this->resetGlobalComposerState();
        $this->tearDownAdminListPage();
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function linkParameters(): iterable
    {
        yield 'the mailed link (token)' => ['token'];
        yield 'the admin page link (invitation)' => ['invitation'];
        yield 'invitecode' => ['invitecode'];
    }

    #[DataProvider('linkParameters')]
    public function test_invite_only_sign_up_from_an_invitation_link_creates_the_account_and_uses_the_invitation(string $parameter): void
    {
        $this->registrationStatus(Settings::REGISTER_STATUS_INVITE);
        $this->invitation('invitee@example.test');

        $fields = $this->registerFormFields('/register?'.$parameter.'='.self::CODE);
        $this->assertSame(['_token', 'action', 'invitecode', 'username', 'email', 'password', 'password_confirmation', 'terms'], array_keys($fields));
        $this->assertSame(self::CODE, $fields['invitecode']);
        $this->assertSame('invitee@example.test', $fields['email'], 'the invited address is filled in');

        $this->post(route('register.post'), $this->filledIn($fields, 'inviteduser', 'invitee@example.test'))
            ->assertRedirect(route('login'))->assertSessionHasNoErrors();

        $user = User::query()->where('username', 'inviteduser')->first();
        $this->assertNotNull($user);
        $this->assertSame('invitee@example.test', $user->email);
        $invitation = DB::table('invitations')->where('token', self::CODE)->first();
        $this->assertNotNull($invitation->used_at);
        $this->assertSame((int) $user->id, (int) $invitation->used_by);
        $this->assertSame(0, (int) $invitation->is_active);
    }

    public function test_invite_only_sign_up_that_fails_validation_comes_back_to_a_form_that_still_carries_the_code(): void
    {
        $this->registrationStatus(Settings::REGISTER_STATUS_INVITE);
        $this->invitation('invitee@example.test');
        $address = '/register?token='.self::CODE;

        $fields = $this->registerFormFields($address);
        $rejected = $this->post(route('register.post'), [...$this->filledIn($fields, 'inviteduser', 'invitee@example.test'), 'password_confirmation' => 'A-Different-Passw0rd!']);

        $rejected->assertRedirect(url($address))->assertSessionHasErrors('password');
        $this->assertSame(0, User::query()->where('username', 'inviteduser')->count());

        $again = $this->registerFormFields($address);
        $this->assertSame(self::CODE, $again['invitecode'] ?? null);
        $this->assertSame('inviteduser', $again['username'], 'the form comes back as the visitor left it');

        // the second submit completes with the invitation
        $this->post(route('register.post'), $this->filledIn($again, 'inviteduser', 'invitee@example.test'))->assertRedirect(route('login'));
        $this->assertSame((int) User::query()->where('username', 'inviteduser')->value('id'), (int) DB::table('invitations')->where('token', self::CODE)->value('used_by'));
    }

    public function test_open_mode_sign_up_from_an_invitation_link_uses_the_invitation_and_applies_its_role(): void
    {
        $this->registrationStatus(Settings::REGISTER_STATUS_OPEN);
        $friend = Role::query()->create(['name' => 'Invented Friend', 'guard_name' => 'web', 'isdefault' => false, 'defaultinvites' => 0, 'rate_limit' => 60]);
        $this->invitation('invitee@example.test', ['role' => (int) $friend->id]);

        $fields = $this->registerFormFields('/register?token='.self::CODE);
        $this->assertSame(self::CODE, $fields['invitecode'] ?? null);

        $this->post(route('register.post'), $this->filledIn($fields, 'inviteduser', 'invitee@example.test'))->assertRedirect(route('login'));

        $user = User::query()->where('username', 'inviteduser')->first();
        $this->assertNotNull($user);
        $this->assertSame((int) $friend->id, (int) $user->roles_id);
        $this->assertSame(['Invented Friend'], $user->getRoleNames()->all());
        $invitation = DB::table('invitations')->where('token', self::CODE)->first();
        $this->assertNotNull($invitation->used_at);
        $this->assertSame((int) $user->id, (int) $invitation->used_by);
        $this->assertSame(0, (int) $invitation->is_active);
    }

    public function test_the_register_form_has_no_invitation_field_without_a_code(): void
    {
        $this->registrationStatus(Settings::REGISTER_STATUS_OPEN);
        $this->invitation('invitee@example.test');

        $fields = $this->registerFormFields('/register');
        $this->assertSame(['_token', 'action', 'username', 'email', 'password', 'password_confirmation', 'terms'], array_keys($fields));

        $this->post(route('register.post'), $this->filledIn($fields, 'plainuser', 'plain@example.test'))->assertRedirect(route('login'));
        $this->assertSame(1, User::query()->where('username', 'plainuser')->count());
        $this->assertNull(DB::table('invitations')->where('token', self::CODE)->value('used_at'), 'an unrelated sign-up leaves the invitation unused');
    }

    public function test_the_register_page_is_handed_the_code_and_no_unread_query_string(): void
    {
        $this->registrationStatus(Settings::REGISTER_STATUS_OPEN);

        $this->get('/register?token='.self::CODE)->assertOk()->assertViewHas('invitecode', self::CODE)->assertViewMissing('invite_code_query');
    }

    private function registrationStatus(int $status): void
    {
        DB::table('settings')->upsert([['name' => 'registerstatus', 'value' => (string) $status]], ['name'], ['value']);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function invitation(string $email, ?array $metadata = null): void
    {
        DB::table('invitations')->insert([
            'token' => self::CODE, 'email' => $email, 'invited_by' => 1, 'expires_at' => now()->addDay(), 'is_active' => 1,
            'metadata' => $metadata === null ? null : json_encode($metadata), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Open the register page and read the fields its sign-up form would send, in document order.
     *
     * @return array<string, string>
     */
    private function registerFormFields(string $address): array
    {
        $xpath = $this->xpath((string) $this->get($address)->assertOk()->getContent());
        $forms = $this->elements($xpath, '//form[@action="'.route('register.post').'"]');
        $this->assertCount(1, $forms, 'the sign-up form is shown');
        $this->assertSame('POST', strtoupper($forms[0]->getAttribute('method')));

        $fields = [];
        foreach ($this->elements($xpath, './/input[@name]', $forms[0]) as $input) {
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
            if ($input->getAttribute('name') === 'invitecode') {
                $this->assertSame('hidden', $input->getAttribute('type'));
            }
        }

        return $fields;
    }

    /**
     * The form's own fields, with the ones a visitor types filled in. Nothing is added.
     *
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    private function filledIn(array $fields, string $username, string $email): array
    {
        return [...$fields, 'username' => $username, 'email' => $email, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'terms' => 'on'];
    }
}
