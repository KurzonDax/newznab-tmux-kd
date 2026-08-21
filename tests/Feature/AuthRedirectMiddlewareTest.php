<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

class AuthRedirectMiddlewareTest extends TestCase
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
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        ]);

        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_guest_is_redirected_to_login_for_auth_middleware_routes(): void
    {
        $this->get(route('verification.notice'))
            ->assertRedirect(route('login'));
    }

    public function test_json_guest_requests_receive_unauthorized_instead_of_html_redirects(): void
    {
        $this->getJson(route('verification.notice'))
            ->assertUnauthorized();
    }

    public function test_authenticated_users_are_redirected_away_from_guest_routes(): void
    {
        $this->actingAs(new GenericUser([
            'id' => 1,
            'email' => 'member@example.test',
            'password' => 'test-password',
        ]))
            ->get(route('login'))
            ->assertRedirect('/');
    }

    public function test_is_verified_alias_uses_laravels_email_verification_middleware(): void
    {
        $middlewareAliases = app(Router::class)->getMiddleware();

        $this->assertSame(EnsureEmailIsVerified::class, $middlewareAliases['isVerified'] ?? null);
    }
}
