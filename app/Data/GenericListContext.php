<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Category;

/**
 * Which generic release list is open (docs/proposals/generic-release-lists/SPEC.md 1): All
 * releases, a group's releases, a poster's posts or the Other category, with the exact group
 * name or poster identity (byte for byte: "Bob <bob@home.mex>" is not "bob <bob@home.mex>"),
 * the route the list lives at and the Following scope kept from today's `/browse/all?watching=1`.
 * Every page, filter and Clear all URL of the list carries this identity.
 */
final readonly class GenericListContext
{
    public const string ALL = 'all';

    public const string GROUP = 'group';

    public const string POSTER = 'poster';

    public const string OTHER = 'other';

    /**
     * @param  self::ALL|self::GROUP|self::POSTER|self::OTHER  $kind
     * @param  string  $key  the group name or the poster identity as stored; '' otherwise
     * @param  string  $route  the list's route name
     * @param  'group'|'poster'|'name'|null  $identityKey  the URL key the group or poster travels under
     * @param  bool  $watching  the All list's Following scope (?watching=1)
     */
    public function __construct(
        public string $kind,
        public string $key = '',
        public string $route = 'browse.all',
        public ?string $identityKey = null,
        public bool $watching = false,
    ) {}

    public static function all(bool $watching = false): self
    {
        return new self(self::ALL, watching: $watching);
    }

    public static function group(string $name): self
    {
        return new self(self::GROUP, $name, 'browse.all', 'group');
    }

    /** @param 'poster'|'name' $identityKey `poster` on /browse/all, `name` on /poster */
    public static function poster(string $identity, string $identityKey = 'poster'): self
    {
        return new self(self::POSTER, $identity, $identityKey === 'name' ? 'poster-identity' : 'browse.all', $identityKey);
    }

    public static function other(): self
    {
        return new self(self::OTHER, route: 'browse');
    }

    public function isOther(): bool
    {
        return $this->kind === self::OTHER;
    }

    public function isGroup(): bool
    {
        return $this->kind === self::GROUP;
    }

    public function isPoster(): bool
    {
        return $this->kind === self::POSTER;
    }

    /** The heading (SPEC 5.1); a poster list without an identity reads as today's empty page. */
    public function heading(): string
    {
        return match ($this->kind) {
            self::GROUP => 'Releases in '.$this->key,
            self::POSTER => $this->key === '' ? 'No Posted By identity supplied' : 'Posts by '.$this->key,
            self::OTHER => 'Other releases',
            default => 'All releases',
        };
    }

    /** The list's view-preference root: `other` for Other, `all` for the All, group and poster lists (SPEC 5.2). */
    public function preferenceRoot(): string
    {
        return $this->isOther() ? self::OTHER : self::ALL;
    }

    /** What the empty list reads when nothing is set (SPEC 5.9). */
    public function emptyText(): string
    {
        return match ($this->kind) {
            self::GROUP => 'No releases from this group.',
            self::POSTER => $this->key === '' ? 'No Posted By identity supplied.' : 'No posts by this poster.',
            self::OTHER => 'There are no Other releases yet.',
            default => $this->watching ? 'No releases of the titles you follow.' : 'There are no releases yet.',
        };
    }

    /**
     * The route parameters that keep the list's identity: the group or poster under its key, the
     * Following scope, and Other's route segment.
     *
     * @return array<string, string|int>
     */
    public function routeParameters(): array
    {
        $parameters = [];
        if ($this->isOther()) {
            $parameters['parentCategory'] = self::OTHER;
        }
        if ($this->identityKey !== null && $this->key !== '') {
            $parameters[$this->identityKey] = $this->key;
        }
        if ($this->watching) {
            $parameters['watching'] = 1;
        }

        return $parameters;
    }

    /** The list's own URL (page 1, no filters). */
    public function url(): string
    {
        return route($this->route, $this->routeParameters());
    }

    /**
     * The category ids the Other list reads: Misc and Hashed.
     *
     * @return list<int>
     */
    public static function otherCategories(): array
    {
        return [Category::OTHER_MISC, Category::OTHER_HASHED];
    }

    /**
     * The list a details page was opened from, read from the Referer header: one of these lists
     * on this site, or null (SPEC 6: "All releases" on direct entry).
     */
    public static function fromReferer(?string $referer): ?self
    {
        if ($referer === null || $referer === '') {
            return null;
        }
        $parts = parse_url($referer);
        if (! is_array($parts) || ($parts['host'] ?? null) !== parse_url(url('/'), PHP_URL_HOST)) {
            return null;
        }
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        $base = rtrim((string) parse_url(url('/'), PHP_URL_PATH), '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        parse_str((string) ($parts['query'] ?? ''), $query);
        $string = static fn (string $key): string => is_string($query[$key] ?? null) ? $query[$key] : '';

        return match (true) {
            $path === '/poster' => $string('name') === '' ? null : self::poster($string('name'), 'name'),
            in_array($path, ['/browse/all', '/browse/All'], true) => match (true) {
                $string('poster') !== '' => self::poster($string('poster')),
                $string('group') !== '' => self::group($string('group')),
                default => self::all(($query['watching'] ?? null) === '1'),
            },
            $path === '/browse/other' || str_starts_with($path, '/browse/other/') => self::other(),
            default => null,
        };
    }
}
