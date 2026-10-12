<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\Google2FAMiddleware;
use App\Http\Middleware\TrustedDevice2FAMiddleware;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedSqliteDatabase;
use Tests\TestCase;

final class ReleaseViewPreferencesTest extends TestCase
{
    use IsolatedSqliteDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootIsolatedDatabase();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->withoutMiddleware([Google2FAMiddleware::class, TrustedDevice2FAMiddleware::class]);
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('username');
            $table->string('email');
            $table->string('password');
            $table->boolean('verified')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('session_token')->nullable();
            $table->json('view_prefs')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedDatabase();
        parent::tearDown();
    }

    public function test_a_post_carrying_the_old_browser_controls_stores_only_the_sort(): void
    {
        $user = $this->user('old-controls');
        $this->actingAs($user)->postJson('/profile/update-view', [
            'root' => 'movies', 'sort' => 'posted', 'view' => 'cards', 'size' => 'l', 'per' => 24, 'thumbs' => 1,
        ])->assertOk();
        $this->assertSame(['sort' => 'posted'], $user->fresh()->view_prefs['movies']);
    }

    public function test_the_home_page_keeps_its_shelves_under_home_beside_the_list_roots(): void
    {
        $user = $this->user('home');
        $all = ['Following', 'TV', 'Movies', 'Audio', 'Books', 'Console', 'PC', 'Adult', 'Other'];
        $this->actingAs($user)->postJson('/profile/update-view', ['root' => 'movies', 'sort' => 'posted'])->assertOk();

        // an order alone: the default ticks are not stored with it
        $this->postJson('/profile/update-view', ['root' => 'home', 'shelves' => ['Other', 'Following', 'TV']])
            ->assertOk()->assertExactJson(['success' => true, 'preferences' => [
                // a partial order moves only the rows it names, each into a place one of them held
                'shelves' => ['Other', 'Following', 'Movies', 'Audio', 'Books', 'Console', 'PC', 'Adult', 'TV'],
                'ticked' => ['Following', 'TV', 'Movies', 'Audio', 'Books'],
            ]]);
        $this->assertSame(['shelves' => ['Other', 'Following', 'Movies', 'Audio', 'Books', 'Console', 'PC', 'Adult', 'TV']], $user->fresh()->view_prefs['home']);
        // ticks travel with the rows they were chosen among: the shelves not listed keep their own
        $this->postJson('/profile/update-view', ['root' => 'home', 'shelves' => ['Other', 'Following'], 'ticked' => ['Other']])
            ->assertOk()->assertJsonPath('preferences.ticked', ['Other', 'Movies', 'Audio', 'Books', 'TV']);
        // the whole list in a new order with nothing ticked: no shelves is a real choice
        $this->postJson('/profile/update-view', ['root' => 'home', 'shelves' => $all, 'ticked' => []])->assertOk()->assertJsonPath('preferences.ticked', []);

        // only the two lists, with distinct names from the nine, the ticks never without their rows; the list keys are refused for home and the lists for a list root
        foreach ([['ticked' => ['TV']], ['ticked' => []], ['shelves' => $all, 'ticked' => ['Nowhere']], ['shelves' => ['TV', 'TV']], ['shelves' => $all, 'ticked' => 'TV'], ['shelves' => []], ['shelves' => [['TV']]],
            ['sort' => 'added'], ['shows_sort' => 'title'], ['films_sort' => 'title']] as $invalid) {
            $this->postJson('/profile/update-view', ['root' => 'home', ...$invalid])->assertUnprocessable();
        }
        foreach ([['shelves' => ['TV']], ['ticked' => ['TV']]] as $invalid) {
            $this->postJson('/profile/update-view', ['root' => 'movies', ...$invalid])->assertUnprocessable();
        }
        $this->postJson('/profile/update-view', ['root' => 'Home', 'shelves' => $all])->assertUnprocessable();

        $stored = $user->fresh()->view_prefs;
        $this->assertSame(['shelves' => $all, 'ticked' => []], $stored['home']);
        $this->assertSame(['sort' => 'posted'], $stored['movies']);
    }

    public function test_preferences_require_a_verified_web_login(): void
    {
        $this->postJson('/profile/update-view', ['root' => 'movies', 'sort' => 'posted'])->assertUnauthorized();
        $user = $this->user('unverified');
        $user->email_verified_at = null;
        $user->verified = false;
        $user->save();
        $this->actingAs($user)->postJson('/profile/update-view', ['root' => 'movies', 'sort' => 'posted'])->assertForbidden();
    }

    private function user(string $name): User
    {
        $attributes = User::factory()->raw(['username' => $name, 'email' => $name.'@example.test']);
        $id = User::query()->insertGetId(array_intersect_key($attributes, array_flip(Schema::getColumnListing('users'))));

        return User::query()->findOrFail($id);
    }
}
