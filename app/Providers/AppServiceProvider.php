<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\AnidbInfo;
use App\Models\AnidbTitle;
use App\Models\BookInfo;
use App\Models\Category;
use App\Models\ConsoleInfo;
use App\Models\GamesInfo;
use App\Models\MovieInfo;
use App\Models\MusicInfo;
use App\Models\Release;
use App\Models\RolePromotion;
use App\Models\RootCategory;
use App\Models\SteamApp;
use App\Models\UsenetGroup;
use App\Models\User;
use App\Models\Video;
use App\Observers\AnidbInfoObserver;
use App\Observers\AnidbTitleObserver;
use App\Observers\BookInfoObserver;
use App\Observers\CategoryObserver;
use App\Observers\ConsoleInfoObserver;
use App\Observers\GamesInfoObserver;
use App\Observers\MovieInfoObserver;
use App\Observers\MusicInfoObserver;
use App\Observers\ReleaseObserver;
use App\Observers\RolePromotionObserver;
use App\Observers\RootCategoryObserver;
use App\Observers\SteamAppObserver;
use App\Observers\UsenetGroupObserver;
use App\Observers\VideoObserver;
use App\Services\MediaInfo\Contracts\MediaInfoSnapshotWriter;
use App\Services\MediaInfo\MediaInfoSnapshotService;
use App\Services\MusicIdentity\AcousticFingerprintCandidates;
use App\Services\MusicIdentity\Contracts\AcousticFingerprintMatcher;
use App\Services\MusicIdentity\Contracts\CandidateGenerator;
use App\Services\MusicIdentity\Contracts\MusicBrainzGateway;
use App\Services\MusicIdentity\Gateways\HttpAcoustIdFingerprintMatcher;
use App\Services\MusicIdentity\Gateways\HttpMusicBrainzGateway;
use App\Services\MusicIdentity\Matching\WholeReleaseAlignmentScorer;
use App\Services\MusicIdentity\MusicCandidateGenerator;
use App\Services\MusicIdentity\MusicIdentityConfiguration;
use App\Services\MusicIdentity\MusicIdentityResolver;
use App\Services\MusicIdentity\Persistence\IdentificationDecisionStore;
use App\Services\MusicIdentity\ReleaseNameAlbumCandidates;
use App\Services\NNTP\NntpProviderPool;
use App\Services\NNTP\NntpTrafficMeter;
use App\Services\ObfuscationRecovery\RecoveryCatalog;
use App\Services\ProviderGateway\ProviderGateway;
use App\Support\ReleaseRepairingContext;
use App\Support\Settings\SettingsRegistry;
use App\Support\SiteViewSettings;
use App\View\Composers\AdminDataComposer;
use App\View\Composers\GlobalDataComposer;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use PragmaRX\Google2FALaravel\Listeners\LoginViaRemember;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useTailwind();

        // Share public-site global data with layouts that need menu/category data.
        // search.* needs direct registration because @section blocks are evaluated
        // before the layout composer runs.
        view()->composer([
            'layouts.main',
            'layouts.guest',
            'search.*',
        ], GlobalDataComposer::class);

        // Admin pages use a leaner composer and do not need public-site menu data.
        view()->composer([
            'layouts.admin',
            'admin.*',
        ], AdminDataComposer::class);

        Gate::define('viewPulse', function (User $user) {
            return $user->hasRole('Admin');
        });
        Event::listen(Login::class, LoginViaRemember::class);
        Http::globalMiddleware(RecoveryCatalog::middleware());
        Event::listen(CacheHit::class, [RecoveryCatalog::class, 'cacheHit']);

        // Register observers
        RolePromotion::observe(RolePromotionObserver::class);
        MovieInfo::observe(MovieInfoObserver::class);
        Video::observe(VideoObserver::class);
        Release::observe(ReleaseObserver::class);
        Category::observe(CategoryObserver::class);
        RootCategory::observe(RootCategoryObserver::class);
        UsenetGroup::observe(UsenetGroupObserver::class);
        MusicInfo::observe(MusicInfoObserver::class);
        BookInfo::observe(BookInfoObserver::class);
        GamesInfo::observe(GamesInfoObserver::class);
        ConsoleInfo::observe(ConsoleInfoObserver::class);
        SteamApp::observe(SteamAppObserver::class);
        AnidbTitle::observe(AnidbTitleObserver::class);
        AnidbInfo::observe(AnidbInfoObserver::class);
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(SiteViewSettings::class);
        $this->app->scoped(ReleaseRepairingContext::class);
        // One pool per process so its circuit-breaker state (and its per-provider
        // connections) survive across the services that share a worker.
        $this->app->singleton(NntpProviderPool::class);
        // One meter per process: it batches every provider read into one write a minute.
        $this->app->singleton(NntpTrafficMeter::class, static fn (): NntpTrafficMeter => new NntpTrafficMeter);
        // The settings hub builds its whole tree from the registry on every render and every
        // save; one instance per request keeps that to a single build.
        $this->app->singleton(SettingsRegistry::class);
        // One gateway per process: its process-scoped pacers and breakers, last outcomes and
        // instance-scoped breakers live on it.
        $this->app->singleton(ProviderGateway::class);
        $this->app->bind(MusicBrainzGateway::class, HttpMusicBrainzGateway::class);
        $this->app->bind(AcousticFingerprintMatcher::class, HttpAcoustIdFingerprintMatcher::class);
        $this->app->bind(CandidateGenerator::class, MusicCandidateGenerator::class);
        $this->app->bind(MediaInfoSnapshotWriter::class, MediaInfoSnapshotService::class);
        $this->app->bind(IdentificationDecisionStore::class, static fn (): IdentificationDecisionStore => new IdentificationDecisionStore(
            candidateAttemptLimit: (int) config('music-identity.candidate_attempt_limit', 5),
        ));
        $this->app->bind(MusicIdentityResolver::class, static fn (Application $app): MusicIdentityResolver => new MusicIdentityResolver(
            candidateGenerator: $app->make(CandidateGenerator::class),
            scorer: new WholeReleaseAlignmentScorer(
                fingerprintDurationToleranceMs: (int) config('music-identity.scoring.fingerprint_duration_tolerance_milliseconds', 10_000),
                fingerprintDurationToleranceRatio: (float) config('music-identity.scoring.fingerprint_duration_tolerance_ratio', 0.1),
            ),
            algorithmVersion: MusicIdentityConfiguration::algorithmVersion(),
            resolverVersion: (string) config('music-identity.resolver_version', 'resolver-v1'),
            normalizerVersion: (string) config('music-identity.normalizer_version', 'normalizer-v1'),
            scorerVersion: (string) config('music-identity.scorer_version', 'whole-release-v1'),
            policyVersion: (string) config('music-identity.policy_version', 'shadow-v1'),
            minimumAlbumScore: (int) config('music-identity.scoring.minimum_album_score', 92),
            minimumRunnerUpMargin: (int) config('music-identity.scoring.minimum_runner_up_margin', 5),
            fingerprintCandidates: $app->make(AcousticFingerprintCandidates::class),
            musicBrainz: $app->make(MusicBrainzGateway::class),
            releaseNameAlbums: $app->make(ReleaseNameAlbumCandidates::class),
        ));
    }
}
