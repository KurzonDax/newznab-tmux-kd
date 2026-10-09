<?php

declare(strict_types=1);

namespace App\Services\MusicIdentity\Persistence;

/**
 * Which MusicBrainz facts shared by every release of an accepted album a decision write changed
 * (issue #313), so the other releases holding them follow once the decision has committed.
 */
final readonly class AcceptedAlbumChanges
{
    public function __construct(
        /** The release group whose genre rows changed. */
        public ?string $releaseGroupId = null,
        /** The MusicBrainz release whose track list changed. */
        public ?string $releaseId = null,
        /** @var list<string> the MusicBrainz artists whose stored name or aliases changed */
        public array $artistIds = [],
    ) {}
}
