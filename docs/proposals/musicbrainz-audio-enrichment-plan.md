# MusicBrainz, AcoustID, and Chromaprint audio identification proposal

Research date: 2026-08-23

## Recommendation

Build a new asynchronous `MusicIdentity` module, with evidence collection embedded in the existing `aud` path and resolution performed by a rewritten `mus` worker. The resolver should treat a MusicBrainz recording, release edition, and release group as different identities; retain complete, versioned evidence; score several candidates locally; and project accepted identity text into the internal search indexes.

The primary product objective is **identification-backed search recall**: an existing audio or generic API query for a canonical artist, album, song, credited track artist, alias, or transliteration should locate the existing release that contains it, even when the posted release name is opaque. Supplemental metadata is out of the primary scope unless it improves candidate discrimination or search. Automatic renaming is optional and must not be required to make an identified release searchable.

The most valuable first feature is **not** AcoustID. It is retaining and matching the ordered audio filenames that NNTmux already sees in NZBs and archive listings. In a production sample, the filenames exposed directly by an NZB were uncommon but almost always multi-track and useful. Archive-only releases were much more common, and `AudioFetcher` already obtains their inner file listing but discards it. Three distinctive track names from two otherwise opaque production releases each identified one MusicBrainz release group uniquely on the private mirror.

Chromaprint and AcoustID are worthwhile as a second-stage signal for obfuscated, mistagged, or ambiguous releases. A fingerprint normally identifies a **recording**, not an album or exact edition. It should therefore contribute one recording match to a whole-release decision; it must never cause an album rename by itself. Production already has an FFmpeg build with the Chromaprint muxer, so the first implementation can avoid a new `fpcalc` runtime dependency while keeping the generator behind an interface.

The existing iTunes matcher should be retired as an identity source before automatic MusicBrainz renaming is enabled. It selects the first search result without structural verification. In a conservative manual audit, at least 23 of the 57 production music releases with a positive `musicinfo_id` were clearly attached to a different album. iTunes may remain an artwork fallback only after another source has established identity.

The target architecture is:

```text
NZB + NNTP audio
      |
      v
aud: route -> fetch/probe -> tags + manifests + optional fingerprint -> preview
                              |
                              v
                    immutable evidence revision
                              |
                              v
mus: exact IDs -> track candidates -> MusicBrainz graph -> AcoustID if needed
                              |
                              v
                  scored, explained decision
                         |
                         v
             internal search-index projection
                         |
                         v
              existing immutable API contracts
```

This keeps NNTP and preview throughput independent of metadata-service health, preserves the exact complement between the `aud` and `add` paths, and lets new scoring versions replay old evidence without downloading the audio again.

## Immutable external API contract

The application's current external API interface is immutable. This is a hard architectural constraint because other applications depend on its exact behavior. Music identification may improve which existing releases match a query and their relevance, but it must not change the API surface through which those searches are made.

The implementation must preserve all of the following:

- existing routes and HTTP methods;
- existing v1 function selectors, including `t=music`, `t=audio`, and `t=search`;
- existing v2 routes, including `/api/v2/audio` and `/api/v2/search`;
- accepted query parameter names, meanings, defaults, validation, and error responses;
- XML and JSON response schemas, element/attribute names, field names, types, and nullability;
- status codes, pagination/cursor contracts, limits, sorting options, category/group/age/size filters, and default-category behavior;
- capability-advertisement output; and
- the meaning of existing release fields, including the returned release title/name.

This prohibition includes changes that appear merely additive. Do not add MusicBrainz IDs, canonical artist/album fields, matched-track context, custom Newznab attributes, or other new response members. Do not replace an opaque posted release title in an API response with a canonical MusicBrainz title. A separate future API version would require its own explicit design and authorization; it is not part of this project.

Search improvement happens entirely behind the contract:

1. Existing v1 `t=music`/`t=audio&q=...` and v2 `/audio?id=...` requests continue to accept and return exactly what they do now.
2. Existing v1 `t=search&q=...` and v2 `/search?id=...` requests continue to accept and return exactly what they do now.
3. Accepted MusicBrainz artist, album, track, credited-artist, alias, and transliteration text is added only to internal search documents.
4. The existing query is evaluated against that additional internal text, then the current release rows are returned through the unchanged presenters.

Changes in result membership and relevance are the intended feature: a song query may begin returning an album posting known to contain that song. Contract semantics are unchanged; search recall is better.

### Current API search limitation

The dedicated audio search currently searches the secondary Music index and maps matching metadata IDs back through `releases.musicinfo_id`. That index contains album `title` and `artist` as its only full-text fields; `musicinfo.tracks` is not indexed. Generic API search uses the primary release index, whose release text search is scoped to `searchname` and `plainsearchname`; it receives no music-identity text.

The search projection must therefore update both internal paths:

- extend the secondary Music search document with accepted album aliases, artist aliases/transliterations, release-track titles, recording aliases, and credited track artists; and
- add equivalent release-scoped internal music text fields to the primary release search document so generic searches with or without a Music category filter can find the same release.

These fields are search-engine implementation details and must not be added to API output documents. A recording-only decision may index only the proven recording title/artist. A release-group decision may index the accepted album identity and complete canonical track list. Suggestive, conflicted, and unresolved candidates must not enter public search results.

### Contract verification

Every implementation PR that touches search or API-adjacent code must prove API compatibility with automated tests:

- golden v1 XML and JSON response-shape tests;
- golden v2 JSON response-shape tests;
- route, method, capability, parameter-validation, error, pagination, sorting, and default-filter regression tests;
- assertions that no response key, XML element/attribute, custom Newznab attribute, type, or existing field meaning changed; and
- search tests showing that an identified artist, album, or track returns the correct existing release through both dedicated audio and generic search while the response schema remains identical.

The project succeeds by returning more correct releases for existing queries, not by expanding the API.

## Evidence from the current application

### Existing processing paths

There are currently two distinct music paths:

1. `aud` is the audio-content path: `PostProcessRunner::processAudio()` -> `postprocess:guid aud` -> `AudioProcessingOrchestrator` -> `AudioReleaseProcessor`. It deliberately has no minimum release size, fetches a bare file head or extracts the first complete audio member of an archive, probes it, stores one `release_audio_tags` row, optionally renames from tags, and creates the preview and spectrogram.
2. `mus` is the legacy album-metadata path: `PostProcessRunner::processMusic()` -> `MusicService::processMusicReleases()`. It parses artist/year from the release name and attaches the first iTunes album or track search result to `musicinfo`.

Important consequences of the current design:

- `AudioSourceSelector` selects the first usable audio file. Only that file's tags and duration are persisted.
- For archives, `AudioFetcher` already lists archive contents in order to select an audio member. The complete listing is thrown away after selection.
- Direct NZB filenames are available before download but are not retained as album evidence.
- CUE, playlist, log, image, and other sidecar names are classified but not used for music identity.
- Embedded MusicBrainz release, artist, recording, and release-group IDs are extracted into the raw tag bag, but nothing resolves them.
- `AudioTagRenamer` uses the sampled track performer rather than album artist, which is unsafe for compilations.
- `mus` only considers categories 3010, 3040, and 3999 and only rows where `musicinfo_id IS NULL`. A name-fixing path writes an empty value that becomes `0`, leaving otherwise eligible releases stranded.
- The legacy year parser stops at 2019. No fuzzy candidate comparison, runner-up margin, track-order verification, or exact-identifier verification occurs.
- `musicinfo.asin` contains an iTunes collection ID, despite its name, and the UI/RSS projection labels its URL as Amazon.
- Dedicated audio API search indexes only `musicinfo.artist` and `musicinfo.title`; it cannot locate an album by a contained track title.
- Generic API search uses release-name text and cannot benefit from attached music identity.

The `aud` path should continue to own content routing, bounded NNTP access, preview generation, and temporary-file lifetime. It should not grow MusicBrainz/AcoustID network calls. The `mus` path is the natural operational lane for asynchronous identity resolution, but its implementation and eligibility should be replaced with evidence state rather than `musicinfo_id` sentinels. The existing `mus` CLI name can remain as a compatibility alias even if the service becomes `MusicIdentityWorker` internally.

### Read-only production snapshot

The production host and database were inspected read-only on 2026-08-23. Database work used direct `SELECT` queries. NZBs were read with direct `file_get_contents()`/`gzdecode()` rather than application services, because the application's NZB parser can repair and rewrite an NZB. No Artisan command was booted, no model was saved, no database statement mutated state, and no production file was modified.

The snapshot is small enough that conservative resolution is operationally practical:

| Observation | Snapshot result | Design implication |
|---|---:|---|
| Releases in Music root | 2,633 | A shadow backfill is inexpensive. |
| Added in the last 30 days | 2,139 | Roughly 71/day; bounded local-mirror lookups are modest. |
| `release_audio_tags` rows | 18 | The dedicated tag/preview feature is new; historical evidence is sparse. |
| Tag rows with album/performer/title | 16/16/16 | Tags are useful when present but currently represent one sampled track only. |
| Tag rows with embedded MusicBrainz IDs | 0 | The exact-ID path is valuable but cannot be the only strategy. |
| Eligible legacy music rows with `musicinfo_id = 0` | 246 | Current eligibility loses real work and should not be reused. |
| Legacy no-match rows with `musicinfo_id = -2` | 649 in eligible categories; 694 in Music root | No-match and operational failure are conflated. |
| Positive Music-root iTunes links | 57 | Existing coverage is very low. |
| Clearly wrong links in conservative manual audit | at least 23/57 | First-result attachment is unsafe for identity or rename. |
| Music-root releases with preview | 1,417 | There is a useful corpus for UI review, but served clips are not necessarily suitable for AcoustID. |

Examples of incorrect current links included *The Beatles – The Blue Box* attached to *Abbey Road*, *Paul Simon* attached to *Graceland*, *Pink Floyd – Echoes* attached to *The Wall*, and a Genesis box attached to a Max Richter album. The problem is systematic rather than an edge case: the query text is broad and the first result is accepted without comparing the release.

The 18 new tag rows also show useful identifiers beyond the currently modeled columns. One Mavis Staples file carried ISRC `USEP42529002`; the private mirror resolved it to the correct recording and then to three editions of *Sad and Beautiful World*. Other rows carried label/catalog text and freedb-style disc IDs. These raw values should be preserved with source and confidence rather than flattened into one album row.

### Production track-list experiment

The newest 200 NZBs in each of categories MP3 (3010), Lossless (3040), and Other (3999) were sampled, for 600 readable NZBs total:

| Signal | Result |
|---|---:|
| Releases containing archives | 466/600 |
| Archive-only releases | 463/600 |
| Releases exposing bare audio filenames in the NZB | 25/600 |
| Exposed releases where every observed case had multiple audio tracks | 25/25 |
| Exposed releases with at least 10 tracks | 15/25 |
| Exposed audio filenames | 268 |
| Filenames that appeared ordered/track-like | 267/268 |
| Filenames with descriptive titles | 239/268 |
| CUE names | 6/600 |
| Playlist names | 6/600 |

Direct NZB track lists are therefore sparse but exceptionally high-value. Archive manifests are the larger opportunity: most posts are archive-only, and the current fetcher already computes a listing for archives it opens.

Three-title intersections against the private mirror produced concrete results:

- `Peshawar`, `Calypso Gene`, and `Glue Traps` uniquely converged on Armand Hammer's *Mercy*, even though the release was named only `101 Laraaji.flac`.
- Three tracks from another opaque release uniquely converged on *This Much Remains*.
- A compilation and a Lloyd Cole singles set produced several plausible groups with the same three hits, proving that intersection alone is insufficient. Full order, coverage, artist credits, track count, duration, and the difference from the runner-up must decide acceptance.

This experiment supports ordered whole-release alignment rather than “search the first filename and accept the first result.”

## Domain model

The implementation should use precise terms at its boundary:

| Term | Meaning in this module |
|---|---|
| **Posted release** | The existing NNTmux `Release`, an NZB posting that may contain one or many audio files. |
| **Audio artifact** | A bare posted file or archive member observed in the posting. |
| **Track evidence** | An observation about an artifact: raw filename, normalized title hint, tags, position, duration, identifier, or fingerprint. It is not yet a MusicBrainz track. |
| **Recording identity** | A MusicBrainz recording: one performance/mix/edit that may appear on many releases. |
| **Release track** | The title, artist credit, position, and length of a recording on one MusicBrainz release medium. |
| **Release edition** | One MusicBrainz release in a country/date/format/label/catalog/barcode configuration. |
| **Release group** | The album/single/EP concept across editions. This is the safest canonical target when the exact edition cannot be proved. |
| **Evidence revision** | An immutable snapshot of everything NNTmux observed for one posted release at one time. |
| **Candidate** | A hypothesis connecting the evidence to recordings, a release group, and optionally an edition. |
| **Decision** | An explained result: accepted edition, accepted release group, accepted recording only, needs review, unresolved, conflicted, or retryable error. |
| **Projection** | A reversible application of an accepted decision to `musicinfo`, display metadata, category, or release name. |

The word “track” should always be qualified as observed artifact, MusicBrainz release track, or MusicBrainz recording. Most matching mistakes come from collapsing these layers.

## Proposed module boundary

The deep module should expose one main resolution operation:

```php
interface MusicIdentityResolver
{
    public function resolve(AudioEvidenceSet $evidence): IdentificationDecision;
}

final readonly class IdentificationDecision
{
    /**
     * @param list<RecordingMatch> $recordingMatches
     * @param list<DecisionReason> $reasons
     * @param list<CandidateSummary> $alternatives
     */
    public function __construct(
        public IdentificationStatus $status,
        public int $score,
        public ?string $musicBrainzReleaseGroupId,
        public ?string $musicBrainzReleaseId,
        public array $recordingMatches,
        public array $reasons,
        public array $alternatives,
        public string $algorithmVersion,
    ) {}
}
```

The worker-facing application service is separate:

```php
final class ResolveReleaseMusicIdentity
{
    public function handle(int $releaseId): void;
}
```

`ResolveReleaseMusicIdentity` loads the latest evidence, acquires/renews the work lease, calls the resolver, persists the decision and bounded candidates, and invokes projections allowed by policy. `MusicIdentityResolver` hides:

- query normalization and distinctive-track selection;
- MusicBrainz search, lookup, browse, pagination, and hydration choreography;
- AcoustID lookup and mapping to MusicBrainz recordings;
- candidate aggregation across recordings, release groups, releases, and media;
- sequence alignment and deterministic feature scoring;
- contradiction detection and runner-up separation; and
- provider response normalization and cache use.

The resolver must not mutate a `Release`, attach `musicinfo_id`, download artwork, or rename anything. Those are projection concerns. This makes the scorer testable with fixed evidence/candidate fixtures and prevents provider response shape from leaking across the application.

Substitutable infrastructure boundaries should include:

```php
interface MusicBrainzGateway
{
    public function candidatesFor(RecordingQuery $query): RecordingCandidates;

    public function hydrate(CandidateIdentifiers $identifiers): CandidateMetadata;
}

interface AcousticFingerprintGenerator
{
    public function generate(AudioSource $source): FingerprintEvidence;
}

interface AcousticFingerprintMatcher
{
    public function match(FingerprintEvidence $fingerprint): FingerprintMatches;
}
```

These interfaces are local-substitutable in tests. The actual MusicBrainz mirror is remote-but-owned; AcoustID and the Cover Art Archive are external dependencies and require contract fixtures, timeouts, and failure isolation.

## End-to-end behavior

### 1. Evidence capture in `aud`

Extend the DTOs between `NzbContentParser`, `AudioSourceSelector`, `AudioFetcher`, and `AudioReleaseProcessor` so the processor can commit:

- the original release/search name, category, group, post date, and size;
- every audio-looking NZB filename, with segment count and ordering when known;
- every audio-looking archive member name from the listing already performed;
- small sidecar names and, when cheaply available, parsed CUE/M3U/PLS/EAC-log facts;
- the sampled audio member's complete MediaInfo tag bag, raw and normalized values;
- container/codec, whole-file duration when reliable, decoded duration, and whether the source is complete or truncated;
- embedded MusicBrainz IDs, ISRC, barcode, catalog number, disc/track number, album artist, performer, title, album, date, and disc ID-like values; and
- an optional versioned Chromaprint fingerprint generated before the temporary source is deleted.

Evidence persistence must be local, fast, and independent of preview/spectrogram success. It must not change `AudioRouting`, broaden an NNTP download without an explicit budget, or call MusicBrainz/AcoustID while an NNTP session is held.

Completeness is part of evidence. `archive_manifest_complete`, `source_file_complete`, `source_starts_at_zero`, `whole_duration_reliable`, and `only_one_track_probed` prevent absent data from being scored as a contradiction.

### 2. Asynchronous resolution in `mus`

Replace legacy `MusicService::processMusicReleases()` eligibility with evidence lifecycle state. A work item is eligible when an evidence revision is ready and has no terminal decision for the current algorithm version, or when its retry time has arrived. This includes all audio-routed categories, not a hardcoded subset, and eliminates the `NULL`/`0`/`-2` sentinel problem.

The current `mus` command and tmux pane can remain, but delegate to `ResolveReleaseMusicIdentity`. Reuse the existing bounded fork/bucket model initially. Give identification its own parallelism and request-budget settings once measured; keeping the `mus` alias avoids an unnecessary operational migration.

Resolution order is strongest-to-weakest and stops when an unambiguous structural decision has been reached:

1. Validate embedded release, release-group, recording, track, and artist MBIDs.
2. Resolve exact disc ID/TOC, ISRC, barcode, and label/catalog-number evidence.
3. Parse and rank distinctive observed track-title candidates.
4. Search at most a small configurable number of distinctive tracks, then aggregate recording occurrences by release group and release.
5. Search normalized album/artist/year hints to recover candidates not found by individual recordings.
6. Hydrate only the bounded top candidate groups/editions and compare their complete media structures locally.
7. If still unresolved and eligible fingerprint evidence exists, query AcoustID and add its recording candidates.
8. Re-score the bounded candidate set, persist the explanation and runner-up margin, and emit a decision.

Search scores only retrieve candidates. They are never accepted as confidence probabilities.

### 3. Whole-release candidate scoring

Use sequence alignment rather than exact position-by-position comparison. The alignment must tolerate missing bonus tracks, multi-disc resets, hidden/pregap/data tracks, samples, lexicographic ordering, and a partial observed disc.

Feature families should include:

- exact embedded MBID, disc ID, ISRC, barcode, or label/catalog agreement;
- number of distinct recording identities supporting the same release group;
- normalized and alias-aware release/album title agreement;
- album artist and release-specific artist-credit agreement;
- release-track title agreement, including punctuation/case normalization;
- track order, contiguous subsequences, per-disc position, coverage, and unmatched-track penalties;
- release-track duration first, recording median duration as fallback;
- observed/candidate track counts and medium counts, conditioned on evidence completeness;
- original date versus edition date, country, format, status, and primary/secondary type; and
- best-versus-second structural score margin.

Do not count correlated observations as independent. A title parsed from a filename and a release name generated from that same filename are one provenance family. Three fingerprints of distinct audio files are independent recording evidence; three spelling variations derived from one tag are not.

Hard contradictions include a validated embedded ID pointing elsewhere, incompatible fingerprints, impossible duration/track-count structure when evidence is complete, and a strong artist conflict outside a compilation/featured-artist case. Edition disagreement is not a release-group contradiction.

### 4. Decisions and confidence policy

The numerical score is a deterministic ranking value, not a probability. An initial shadow policy can use the following bands, but production gates must be calibrated on labeled releases:

| Decision band | Initial score | Permitted automatic action |
|---|---:|---|
| Verified | 97–100 | Attach metadata; rename only if the rename gate also passes. |
| Strong | 92–96 | Attach canonical metadata; preserve the current name. |
| Suggestive | 75–91 | Review/shadow result only. |
| Unresolved | below 75 | No identity mutation. |

No score may bypass structural gates. Automatic album acceptance requires at least one of:

- a validated embedded release MBID;
- a unique exact disc-ID match with compatible media structure;
- several distinct recording matches converging on one release group with strong ordered-list agreement; or
- at least two distinct recording matches plus strongly agreeing album artist/title/year evidence.

A single fuzzy text result, ISRC, or AcoustID match may identify one recording. It may improve the sampled-track display, but it cannot select an album or rename the posted release. Exact-edition acceptance additionally requires edition evidence such as disc ID, barcode, catalog number, country/date, or a uniquely compatible medium structure. Otherwise the honest result is an accepted release group with unresolved edition.

Provider errors are not decisions:

- `retryable_error`: timeout, connection failure, HTTP 429, 5xx, or stale/unavailable dependency;
- `unresolved`: provider succeeded but no candidate met the evidence gates;
- `conflicted`: strong evidence points to incompatible identities;
- `needs_review`: one or more plausible candidates remain too close;
- `accepted_recording`, `accepted_release_group`, or `accepted_edition`: explicit identity scope.

### 5. Search, metadata, and rename projections

Search indexing, metadata attachment, and renaming are separate actions with separate thresholds. Search is the required projection; supplemental display metadata and renaming are optional.

The search projection writes accepted identity text into internal search documents without changing a release's stored or API-presented name:

- an accepted recording contributes its canonical title, artist credit, and safe aliases;
- an accepted release group contributes canonical album/artist text, aliases/transliterations, and its accepted track titles/credits; and
- an accepted edition may refine track titles, positions, and edition-specific naming internally.

Only evidence at or above the policy threshold for that identity scope is searchable. A recording acceptance does not authorize indexing an unproven album track list. Search-index updates must use the existing release synchronization infrastructure and invalidate existing search-result caches without changing any external request or response contract.

The metadata projection may attach an accepted release group, canonical artist credit, display title, original date, type, track list, and cover while leaving `releases.name` unchanged. The rename projection must additionally require:

- the verified band and a sufficient runner-up margin;
- no hard contradiction;
- no positive PreDB identity, manually frozen identity, or existing trusted name;
- a stable MusicBrainz artist credit appropriate to the release, not the sampled track performer; and
- an idempotent record of the prior name and fields applied.

For compilations, use MusicBrainz's release artist credit (often Various Artists), not one track's artist. Prefer release-group title and original release date in the generic album name; include edition details only when the edition is accepted. A generated name should become trusted only when the verified rename policy passes. Tag-only renames remain provisional and may be superseded by an accepted MusicBrainz projection.

Every projection stores a before/after field diff. A reversal may restore a field only if its current value still equals the value the projection wrote, so a later human correction is never overwritten.

## Persistence design

Use a lean evidence ledger rather than either a single JSON blob on `release_audio_tags` or a fully replicated MusicBrainz graph.

### `release_audio_evidence`

One immutable header per evidence revision:

- `id`, `releases_id`, `revision`, `evidence_hash`, `schema_version`;
- source release snapshot and completeness flags;
- raw NZB/archive/sidecar manifest JSON, content-addressed if size warrants;
- collection timestamps.

### `release_audio_evidence_tracks`

One row per observed audio artifact or sampled track:

- evidence ID, source kind, source ordinal/path, raw filename;
- inferred disc/track number and normalized title/artist hints;
- raw tag JSON and normalized album/album-artist/performer/title/date;
- duration and completeness/reliability flags;
- ISRC, MusicBrainz track/recording/release/release-group/artist IDs;
- optional fingerprint, fingerprint hash, algorithm/generator version, whole-file and decoded durations.

The existing one-row `release_audio_tags` record can remain as the preview/display projection during migration. It should not become the multi-track identity store.

### `release_music_identifications`

One current lifecycle record per release/evidence hash/algorithm version:

- state, score/band, accepted identity scope and MBIDs;
- reason/feature contribution JSON and runner-up margin;
- attempt count, lease token/expiry, next attempt, last operational error;
- resolver, normalizer, scorer, and policy versions;
- mirror replication/search freshness and AcoustID lookup timestamps;
- `supersedes_id` and decision timestamps.

### `release_music_candidate_attempts`

Retain a bounded top-N snapshot for audit/calibration:

- identification ID, rank, entity MBIDs and display snapshot;
- normalized feature vector, score contributions, contradictions;
- provider/provenance and response cache keys.

This is enough to explain and rescore decisions without retaining every raw API payload indefinitely. A later algorithm creates a new identification row; it does not rewrite the previous conclusion.

### `musicinfo` compatibility projection

Keep `releases.musicinfo_id` and existing views/API working during rollout, but treat `musicinfo` as a denormalized accepted-metadata projection rather than the identity source. Add internal, explicitly named and uniquely indexed source fields such as:

- `source`;
- `musicbrainz_release_group_id`;
- nullable `musicbrainz_release_id`;
- artist MBID/credit snapshot;
- primary and secondary types;
- country, label, catalog number, barcode, status, and disambiguation.

Do not put a MusicBrainz or iTunes identifier into `asin`. Do not expose the new source fields or change the existing RSS/API representation as part of this project. Hydrate only accepted entities; the private mirror remains the catalog of record.

## Chromaprint and AcoustID plan

### Value

Fingerprinting is most valuable when filenames/tags are obfuscated, wrong, transliterated beyond useful text matching, or common enough to leave several candidates. It also provides a cache key for duplicate audio already seen by this NNTmux instance. It adds less value when a complete ordered track list and strong identifiers already establish the album.

The production environment has FFmpeg 6.1.1 built with `--enable-chromaprint` and `libchromaprint1` 1.5.1, but not the `fpcalc` executable. A read-only test generated a base64 Chromaprint fingerprint from an existing preview. This proves runtime capability, not AcoustID suitability: served previews begin around ten seconds into the source, while `fpcalc` normally analyzes from the beginning, and stream-copied FLAC preview headers can report the original file duration even when the file contains only the preview bytes.

### Initial generation policy

- Generate locally while the fetched source still exists; never fingerprint the served preview for automatic identification.
- Start at time zero and analyze up to the conventional first 120 decoded seconds.
- Persist the fingerprint, its hash, algorithm/generator version, whole-file duration, decoded duration, and completeness flags; never persist PCM.
- Initially enable only for complete archive-extracted tracks. Admit truncated bare-file heads only after corpus validation proves they contain enough beginning-anchored audio and a reliable whole-file duration.
- Generate conditionally when embedded IDs/ISRC are absent, text is obfuscated, or the likely candidate set is ambiguous. A shadow experiment can compare this with universal generation before choosing.
- Use FFmpeg's Chromaprint muxer behind `AcousticFingerprintGenerator`; support `fpcalc -json` as a portable implementation later. Add a startup/status capability probe rather than assuming every FFmpeg package was compiled with Chromaprint.
- Do not expand the initial archive-volume or NNTP segment budget solely to obtain a fingerprint.

The current preview, spectrogram, and fingerprint paths each decode audio differently. Combining decode passes may be an optimization later, but it should not complicate the first correct implementation.

### Lookup policy

- Query AcoustID asynchronously with `POST`, an application client key, duration, and fingerprint.
- Enforce a process-wide maximum of three requests/second, cache by fingerprint algorithm/version/hash and duration, honor retry/backoff, and disclose that derived fingerprint data is sent to a third party.
- Request recording IDs and map them through the private MusicBrainz mirror. Treat AcoustID's score as a provider feature, not a calibrated probability.
- Never submit fingerprints or metadata automatically. Submission is a different, mutating API and is an explicit non-goal.
- A fingerprint match accepts at most a recording. Several independently fingerprinted recordings that converge on one ordered release group can support album acceptance.

Before enabling AcoustID in production, obtain an application key, reconfirm current non-commercial/commercial terms for this deployment, and validate hit rate and false positives on representative complete files. Public self-hosting is not a near-term escape hatch; the official server project describes self-hosting as unsupported and generally not useful without substantial indexing infrastructure.

## Optional enrichment beyond search

The following possibilities are secondary to identification-backed search. Do not implement them merely because MusicBrainz exposes the data; each must either improve matching/search or be separately justified. None may change the immutable external API contract.

1. **Canonical release display:** correct punctuation, capitalization, aliases/transliterations, artist credit, original release date, exact-edition date, country, label, catalog number, barcode, media format, status, and disambiguation.
2. **Track listing and sampled-track identity:** display release-specific titles, positions, credits, and durations; name the previewed recording correctly; do not rewrite the files inside the NZB.
3. **Classification:** album/single/EP plus compilation, live, DJ mix, mixtape/street, remix, soundtrack, spoken word, audiobook, and broadcast secondary types can improve browsing and suggest category corrections. Category changes should be a separate conservative projection.
4. **Classical and composition metadata:** recording-to-work relationships provide composition/movement, ISWC, composer, lyricist, arranger, conductor, orchestra, performers, instruments, language, and work attributes.
5. **Live provenance:** event, place, area, and relationship data can add venue, city, event date, and performers for concert recordings.
6. **Series membership:** identify compilation series, volume order, DJ-mix series, and broadcasts.
7. **Credits:** producer, remixer, mix/mastering engineer, publisher, guest performer, and instrument/vocal relationships.
8. **External corroboration:** typed Discogs, Bandcamp, Wikidata, official, streaming, and purchase URLs can support details pages without inventing URL scraping rules.
9. **Genres and tags:** vote-weighted values can enrich filters after identity is established; they must not prove identity.
10. **Cover art:** cache exact-release front art through `ReleaseImageService`, falling back explicitly to release-group representative art. Back-cover/booklet OCR and perceptual hashes against already-generated candidates are creative later-stage edition tie-breakers; the Cover Art Archive does not provide reverse-image search.
11. **Completeness diagnostics:** compare observed ordered files with the accepted medium to flag missing discs/tracks, unexpected extras, or likely incomplete posts. This should be informational and must not feed deletion without a separately reviewed policy.
12. **Duplicate intelligence:** cache AcoustID and MusicBrainz results by fingerprint/recording/release group to recognize repeat posts, prefer higher-quality encodes, and avoid repeated external calls.
13. **CUE/log leverage:** a complete CUE TOC can produce strong medium/disc evidence; EAC-style logs can corroborate track order, catalog/barcode, and extraction provenance. These files were rare in the direct-NZB sample but can be cheap, precise evidence inside archives.

## Provider and operational design

### Expected code organization

Keep the feature in a focused `app/Services/MusicIdentity/` module rather than expanding `MusicService` or the preview classes:

```text
app/Services/MusicIdentity/
  DTO/                         evidence, candidates, matches, decisions
  Enums/                       lifecycle and accepted identity scope
  Evidence/                    manifest parsing and evidence persistence
  Fingerprinting/              FFmpeg/fpcalc adapter and capability probe
  Gateways/                    MusicBrainz, AcoustID, Cover Art adapters
  Matching/                    normalization, distinctiveness, alignment, scoring
  Projections/                 internal search, musicinfo, and optional guarded rename
  MusicIdentityResolver.php    deep public resolution boundary
  ResolveReleaseMusicIdentity.php
```

Existing classes should change only at their seams:

- `AudioFetchResult` and `AudioFetcher` return observed archive members and completeness facts.
- `AudioReleaseProcessor` commits the evidence revision before temporary-source cleanup.
- `PostProcessRunner::processMusic()` selects evidence-ready work and calls the new application service.
- `MusicService` remains a compatibility/read service while first-result iTunes identification is retired.
- `SecondaryIndexDocuments` gains internal searchable album, artist, track, credited-artist, alias, and transliteration text without adding response fields.
- The release-index schema/projection gains release-scoped internal music search fields; `ReleaseSearchIndexDocument::fields()`, `toReleaseRow()`, and the API presenters must continue to expose only the existing response fields.
- `ReleaseSearchService` continues to accept the current API parameters and return the current presenters' rows while searching the richer internal documents.
- `ReleaseUpdateService` is reached only through the guarded rename projection and no longer clears identity state via an empty `musicinfo_id`.
- `ReleaseImageService` remains the only cover-download/storage boundary.

Create a dedicated configuration file for endpoints, request budgets, timeouts, algorithm/policy versions, thresholds, shadow/application modes, fingerprint policy, cache TTLs, and AcoustID rate limiting. Runtime Admin settings should control enablement, shadow mode, worker parallelism, and optional fingerprinting; deployment-specific endpoints, User-Agent/contact, and the AcoustID client key belong in environment-backed config. Every new environment key must be documented in `.env.example` with a safe default. The initial defaults should capture evidence and run shadow resolution without renaming.

### MusicBrainz mirror

Use `http://10.1.8.50:5000/ws/2/` as the configured primary gateway. The read-only probe verified lookup, browse, exact and fuzzy indexed search, disc-ID lookup, and ISRC lookup. Do not hardcode the address; add a documented `.env.example` key through configuration when implemented.

The mirror home page reported its last replication packet on 2026-08-19, four days behind this research date. Database replication and the search index are separate operational concerns. Record/monitor both:

- HTTP/API availability and latency;
- last replication packet age;
- search-index freshness using an operational check appropriate to the mirror deployment;
- error, timeout, retry, candidate, and cache rates.

Use bounded concurrency, request budgets, URL-safe Lucene construction, strict JSON validation, caching, and circuit breakers even though the private server is not automatically subject to the public one-request-per-second limit. An optional public MusicBrainz fallback should be disabled by default; if enabled, it requires an identifying User-Agent and the public rate limit.

### Cover Art Archive

The local MusicBrainz mirror does not mirror image bytes. Cover Art Archive requests remain external, redirect to Internet Archive assets, and must be optional. Download only after identity acceptance, cache through existing image infrastructure, cap bytes/dimensions/time, validate MIME and decoded image content, and distinguish exact-release art from release-group fallback.

### Failure isolation and observability

- Preview processing succeeds even if evidence persistence beyond the local transaction, MusicBrainz, AcoustID, or cover art fails.
- Work claims use expiring leases and idempotent upserts; retries use bounded exponential backoff.
- Cache exact lookup results much longer than search results; include normalized request and provider/version in cache keys.
- Record reason codes and bounded candidate contributions, not only an opaque score.
- Metrics include evidence coverage, exact-ID yield, track-list yield, candidate count, decision band, runner-up margin, operator overturns, false renames, mirror latency/freshness, AcoustID lookup/hit/MBID yield, fingerprint failures, and projection reversals.
- Add a status probe for the private mirror and a separate optional AcoustID capability/status signal. Dependency degradation must never make `/status` or ordinary browsing depend synchronously on an external service.

## Review and calibration

Automatic rename precision should be optimized ahead of recall. The current iTunes results show that false attachment is worse than leaving a release unresolved.

Build a labeled corpus from:

- all 57 current positive iTunes links, including the known incorrect ones;
- the 246 stranded `musicinfo_id = 0` rows;
- a sample of the 694 Music-root legacy no-match rows;
- the 18 releases with current audio tags;
- exposed and archive-only multi-track cases;
- compilations, multi-disc sets, remasters, live recordings, classical releases, foreign scripts, audiobooks/spoken word, singles, and deliberately obfuscated names.

For each resolver version, retain top candidates and feature explanations. Evaluate candidate-retrieval recall separately from acceptance precision. Report exact-edition accuracy, release-group accuracy, recording accuracy, false automatic rename rate, review rate, and unresolved rate. Threshold changes require replaying the frozen corpus and inspecting every newly automatic cohort.

An operator review view should show the current name, observed files/tags/identifiers, aligned candidate track lists, cover/type/date/artist, score contributions, contradictions, runner-up, and actions for metadata only, metadata plus rename, reject, or choose another candidate. Human decisions should be explicit labeled outcomes, not hidden mutations of scoring weights.

Required automated test cohorts include:

- validated and invalid embedded MBIDs;
- unique and ambiguous disc IDs/ISRCs;
- rare ordered tracks, common-title collisions, missing bonus tracks, partial discs, and multi-disc resets;
- compilations with varying performers;
- remaster/original-date ambiguity;
- acoustic match with no MusicBrainz link, several recording links, and duration conflict;
- retryable mirror/AcoustID failures versus genuine no-match;
- stale evidence/algorithm replay and idempotent projection;
- trusted/PreDB/manual name protection and safe reversal; and
- Cover Art fallback/failure independent of identity acceptance.

API-search verification is mandatory and independent of resolver tests:

- the existing dedicated v1/v2 audio requests find an identified release by canonical artist, album, and contained track;
- the existing generic v1/v2 search requests find the same release through internal music text;
- recording-only acceptance exposes only the proven recording/artist text to search;
- ambiguous or rejected candidates add no searchable terms;
- category exclusions, group, age, size, password, sort, limit, offset, and cursor behavior remain unchanged; and
- v1 XML/JSON and v2 JSON response contracts remain unchanged, including the absence of new match-context or MusicBrainz fields.

Unit tests should use fake gateways and frozen normalized responses. Optional integration tests can run against the private mirror but must not be part of CI. All HTTP calls remain mocked in the regular suite.

## Rollout plan

### Phase 0: stop creating new bad state

- Disable first-result iTunes automatic identity attachment.
- Change legacy eligibility so `musicinfo_id = 0` is not stranded while the new evidence state is introduced.
- Separate no-match from retryable failure; stop assigning new meanings to `-2`.
- Preserve existing links as low-confidence legacy evidence for audit, not ground truth.

### Phase 1: evidence and shadow resolution

- Add evidence/identification storage and versioned DTOs.
- Capture direct NZB and existing archive manifests with no expanded download budget.
- Preserve the sampled tag bag and explicit identifier columns.
- Run MusicBrainz exact identifiers and bounded multi-track/text candidate generation in shadow mode.
- Mutate no names, categories, or `musicinfo` links.

### Phase 2: immutable-contract search projection

- Calibrate against the labeled corpus.
- Extend the internal secondary Music index with accepted album, artist, track, credited-artist, alias, and transliteration text.
- Extend the internal primary release index with release-scoped accepted music search text.
- Make both existing dedicated audio and generic API searches use the richer internal index data without adding routes, parameters, fields, attributes, or response semantics.
- Preserve the posted release name and every existing presenter contract.
- Enable recording-only search terms separately from release-group track-list terms according to confidence scope.
- Add golden API contract and search-recall regression tests before activation.

### Phase 3: Chromaprint and AcoustID

- Validate FFmpeg/fpcalc compatibility on complete representative sources.
- Generate conditional fingerprints before source cleanup.
- Run AcoustID lookups only for unresolved/ambiguous cases and measure incremental search yield.
- Consider selectively extracting a second distinctive track only if measured identification gain justifies NNTP/CPU cost.

### Phase 4: backfill and calibration

- Replay existing NZBs, `release_audio_tags`, `release_files`, NFO/CUE/log evidence, and current legacy links without refetching audio first.
- Backfill fingerprints only where a complete source is already available or an explicitly budgeted re-fetch is justified.
- Measure dedicated and generic API recall for artist, album, and track queries while continuously checking contract snapshots.
- Tune acceptance by identity scope rather than changing API behavior.

### Phase 5: optional projections and enrichment

- Consider metadata display, guarded automatic rename, work/credit/live/series enrichment, artwork, and completeness diagnostics only after the search objective is met.
- If renaming is enabled, protect PreDB, trusted, and manual names, audit the cohort, and keep safe reversal data.
- Any external API representation change remains out of scope even if an optional projection is implemented internally or in the first-party web UI.
- Retire the legacy iTunes matching code and sentinel lifecycle after compatibility consumers have migrated.

Each phase should be independently deployable and reversible. Identification-backed search must provide value without changing a release name or any external API contract.

## Design alternatives considered

Three module shapes were compared:

| Alternative | Strength | Main weakness | Decision |
|---|---|---|---|
| Pipeline-embedded resolver using `release_audio_tags` JSON and synchronous calls | Fastest implementation, minimal schema/operations | Lengthens `aud`, loses multi-track structure, makes retries and rescoring awkward | Reject as the target; borrow its incremental rollout. |
| Asynchronous deep resolver with explicit evidence and decision state | Strong isolation, testable scorer, fits the current `mus` lane | Requires several tables and a new lifecycle | **Recommended core.** |
| Full append-only evidence/candidate/decision/application ledger | Maximum auditability, exact algorithm comparison and undo | Too much provider-payload/event machinery for the first release | Borrow immutable evidence, versioned decisions, bounded candidate history, and safe projection reversal. |

The recommended hybrid is intentionally deeper than adding an API call to `MusicService`, but shallower than replicating or event-sourcing the entire MusicBrainz graph.

## Explicit non-goals

- Do not add, remove, rename, reinterpret, or otherwise change any route, method, parameter, default, error, status, XML element/attribute, JSON field/type, capability, pagination behavior, sorting/filtering behavior, custom Newznab attribute, or existing release-field meaning in the external API.
- Do not expose MusicBrainz IDs, canonical metadata, matched-track context, or internal confidence/evidence fields through the existing API.
- Do not write to the MusicBrainz mirror, AcoustID, or production database as part of identification.
- Do not call AcoustID's submission API automatically.
- Do not rewrite or repack files inside an NZB; canonical track titles are display/enrichment data.
- Do not choose an exact MusicBrainz edition when only the release group is supported.
- Do not treat Lucene score, AcoustID score, community tags, or ratings as identity probabilities.
- Do not make release deletion/repair decisions from MusicBrainz completeness diagnostics without a separate design and rollout.
- Do not clone the full MusicBrainz graph into NNTmux; store stable accepted IDs and the display snapshot the application needs.
- Do not add a public MusicBrainz fallback implicitly.

## Implementation gates still requiring measurement

The design is complete, but these thresholds must be empirical rather than guessed:

1. The ordered-track alignment weights, minimum coverage, and runner-up margin.
2. Whether two or three independently matched recordings are sufficient for each release type.
3. AcoustID hit rate and false-match behavior on the actual codecs, partial files, leading silence, remasters, and corrupted posts.
4. Whether beginning-anchored truncated bare-file heads work reliably with the whole-file duration expected by AcoustID.
5. Universal versus conditional fingerprint CPU cost and incremental yield.
6. Normal mirror replication lag, search-index lag, request concurrency, and cache TTLs.
7. Current AcoustID service terms for this deployment and whether an application key may be used at the expected volume.
8. Which optional non-search enrichment fields, if any, justify persistence after the identification-backed search objective is met.

None of these gates requires changing the architecture. They determine policy and rollout settings, which should remain versioned.

## Detailed API research

## Scope and method

This dossier covers the identification and enrichment capabilities exposed by the MusicBrainz Web Service, the Cover Art Archive, AcoustID, and Chromaprint. It is deliberately independent of this repository's current audio pipeline so that its facts and constraints can be used as design inputs elsewhere.

Only first-party documentation, source repositories, specifications, and live services were used. The private MusicBrainz mirror at `http://10.1.8.50:5000` was inspected with read-only `GET` and `HEAD` requests. The production host at `10.1.1.35` was not accessed, and no remote state was changed.

## Executive findings

1. MusicBrainz can support much more than a title lookup. Its data model separates the exact release edition, the abstract release group, the release-specific track, the underlying recording, and the musical work. Preserving those distinctions is essential: a release track title and duration may legitimately differ from the canonical recording title and median recording duration. [Release](https://musicbrainz.org/doc/Release), [Release Group](https://musicbrainz.org/doc/Release_Group), [Track](https://musicbrainz.org/doc/Track), [Recording](https://musicbrainz.org/doc/Recording), [Work](https://musicbrainz.org/doc/Work)
2. A list of files can identify an album more reliably than any file name in isolation. MusicBrainz exposes ordered media and track lists, per-track recording IDs, durations, artist credits, disc counts, release dates, labels, catalog numbers, barcodes, disc IDs, and release relationships. MusicBrainz Picard itself clusters files and compares the cluster with a single candidate release, establishing an official precedent for album-level matching. [Web Service](https://musicbrainz.org/doc/MusicBrainz_API), [Picard file retrieval](https://picard-docs.musicbrainz.org/en/latest/usage/retrieve.html), [Picard matching options](https://picard-docs.musicbrainz.org/en/latest/config/options_matching.html)
3. MusicBrainz's Lucene search, including fuzzy terms and duration buckets, is useful for candidate generation. It is not an identity oracle: search scores are relevance scores, and the local mirror returned many materially different recordings with the same score. A safe design must verify a candidate against independent evidence and require a meaningful lead over the runner-up. [MusicBrainz search](https://musicbrainz.org/doc/MusicBrainz_API/Search), [Solr standard query parser](https://solr.apache.org/guide/solr/latest/query-guide/standard-query-parser.html)
4. AcoustID plus Chromaprint adds a strong, filename-independent signal for identifying a near-identical full recording. It normally maps to a MusicBrainz **recording**, not to one exact release edition. Album membership must still be inferred by combining several identified recordings with MusicBrainz release track lists. AcoustID explicitly describes the public service as a full-file service that cannot identify short snippets or noisy phone recordings. [AcoustID Web Service](https://acoustid.org/webservice), [AcoustID FAQ](https://acoustid.org/faq), [Chromaprint](https://github.com/acoustid/chromaprint)
5. The existing 30-second preview concept should not be assumed suitable for public AcoustID lookup. `fpcalc` defaults to analyzing the first 120 seconds while reporting the whole source duration, and AcoustID requires the duration of the whole file. A full file, or a beginning-anchored decode long enough to match documented client behavior while retaining the full duration, needs validation against a representative corpus. Arbitrary middle excerpts are not a documented public-service mode. [fpcalc source](https://github.com/acoustid/chromaprint/blob/master/src/cmd/fpcalc.cpp), [pyacoustid client](https://github.com/beetbox/pyacoustid/blob/master/acoustid.py), [AcoustID Web Service](https://acoustid.org/webservice)
6. The private mirror is functional for lookup, browse, indexed search, fuzzy search, and disc-ID/ISRC resolution. On the research date its home page reported that the last replication packet was received on 2026-08-19, four days behind the research date. Database replication and search-index freshness are separate operational concerns in the official mirror deployment. [Private mirror](http://10.1.8.50:5000/), [musicbrainz-docker](https://github.com/metabrainz/musicbrainz-docker)

## 1. MusicBrainz identity model

MusicBrainz's layers answer different questions. Treating all MBIDs as interchangeable would create incorrect renames and false edition matches.

| Entity | What it represents | Identification or enrichment value |
|---|---|---|
| **Release** | One issuing of a product in a specific country/date/format/label/catalog-number/barcode/packaging configuration, with one or more ordered media. | The correct target when identifying an exact CD, digital edition, reissue, deluxe edition, or regional pressing. [Release](https://musicbrainz.org/doc/Release) |
| **Release group** | The abstract album/single/EP/etc. grouping of related releases. Every release belongs to one release group. | Useful when the album is clear but the exact edition is not; also supplies primary and secondary types. [Release Group](https://musicbrainz.org/doc/Release_Group) |
| **Medium** | A disc, vinyl side/set, digital medium, cassette, or other carrier within a release. | Supplies disc order, format, track count, pregap/data-track structure, titles, and disc IDs where applicable. [Release](https://musicbrainz.org/doc/Release), [Disc ID](https://musicbrainz.org/doc/Disc_ID) |
| **Track** | The release-specific representation of a recording at a position on one medium. It has its own MBID, title, artist credit, number, position, and length. | The correct source for edition-specific file naming and sequence. [Track](https://musicbrainz.org/doc/Track) |
| **Recording** | A distinct audio performance/mix/edit that can appear on many tracks and releases. Its length is derived as the median of linked track lengths. | The best identity for fingerprinting, ISRCs, canonical audio title, performers, remixes, samples, and work links. [Recording](https://musicbrainz.org/doc/Recording) |
| **Work** | The underlying composition or creative work, above individual recordings. | Connects covers and performances and exposes composers, lyricists, arrangers, publishers, languages, attributes, and ISWCs. It must not be used to collapse different recordings into the same audio identity. [Work](https://musicbrainz.org/doc/Work) |
| **Artist credit** | An ordered display credit made from artists plus credited-as names and join phrases. | Preserves names such as “Artist feat. Guest” without inventing string parsing rules and allows both display credit and stable artist MBIDs to be stored. [Artist Credits](https://musicbrainz.org/doc/Artist_Credits) |

A practical metadata model should therefore be able to preserve all of the following at once: release MBID, release-group MBID, medium position, track MBID, track title/credit/length, recording MBID, recording title/credit/median length, and any work MBID. This permits conservative identification now and better edition resolution later.

## 2. MusicBrainz Web Service surface

The current public API is version 2 under `/ws/2/`. XML is the default representation; JSON is selected by the `Accept: application/json` header or `fmt=json`. The three principal read patterns are lookup by MBID, browse through a linked entity, and Lucene search. [MusicBrainz Web Service](https://musicbrainz.org/doc/MusicBrainz_API)

```text
GET /ws/2/{entity}/{mbid}?inc={includes}&fmt=json
GET /ws/2/{result-entity}?{linked-entity}={mbid}&limit={1..100}&offset={n}&fmt=json
GET /ws/2/{entity}?query={lucene-query}&limit={1..100}&offset={n}&fmt=json
```

Lookup includes are explicit. A bare lookup does not necessarily return the linked records, releases, release group, relationships, tags, or identifiers required for identification. Linked collections embedded in a lookup are capped at 25; browse requests are the supported way to retrieve a complete linked set. Browse and search default to 25 results and allow at most 100 per page. Release browse responses can contain at most 500 tracks in a page, so clients must advance by the actual number of releases returned rather than assuming a fixed page size. [MusicBrainz Web Service](https://musicbrainz.org/doc/MusicBrainz_API)

### 2.1 Core and auxiliary resources

The 13 core resources can be looked up by MBID and, except for genre, participate in browse and/or indexed search as documented below. The Web Service also exposes identifier lookups and auxiliary data such as tags, ratings, collections, annotations, and CD stubs. [MusicBrainz Web Service](https://musicbrainz.org/doc/MusicBrainz_API), [MusicBrainz search](https://musicbrainz.org/doc/MusicBrainz_API/Search)

| Resource | API operations relevant to this work | Audio-enrichment value |
|---|---|---|
| `area` | MBID lookup, browse, indexed search | Country, subdivision, city, and other geographic identity; artist origins, release areas, event/place context. |
| `artist` | MBID lookup; browse recordings, releases, release groups, and works; indexed search | Canonical name, sort name, type, life span, country/area, aliases, external identifiers, relationships, tags/genres. |
| `event` | MBID lookup, browse, indexed search | Concert/festival date, performers, set/event relationships, area and venue clues for live recordings. |
| `genre` | MBID lookup and `/genre/all`; no normal Lucene search/browse endpoint | Controlled subset of community tags. Genre remains subjective and vote-counted, so it is enrichment rather than proof of identity. [Genre](https://musicbrainz.org/doc/Genre) |
| `instrument` | MBID lookup, browse, indexed search | Canonical instrument names, aliases, descriptions, and performer-instrument relationship enrichment. |
| `label` | MBID lookup; browse releases; indexed search | Canonical label identity, label code, aliases, area, IPI/ISNI, and release catalog context. |
| `place` | MBID lookup, browse, indexed search | Venue/studio name, address, coordinates, area, type, and relationships for live/studio provenance. |
| `recording` | MBID lookup; browse by artist/release/release group/work/collection; indexed search | Canonical recording identity, duration, video flag, ISRCs, release appearances, works, performers, engineers, remixes, samples. |
| `release` | MBID lookup; broad browse support; indexed search | Exact edition, media and ordered tracks, status, quality, date/country, label/catalog number, barcode, packaging, disc IDs, cover-art state, URL relationships. |
| `release-group` | MBID lookup; browse releases; indexed search | Album-level identity, primary/secondary types, first release date, canonical artist credit, all editions, representative cover. |
| `series` | MBID lookup, browse, indexed search | Ordered branded series and relationships; useful for compilation lines, DJ-mix series, volumes, broadcasts, and release series. |
| `work` | MBID lookup; browse by artist/recording/collection; indexed search | Composition identity, ISWCs, language/type/attributes, composers, lyricists, arrangers, publishers, and recordings of the work. |
| `url` | MBID lookup; exact resource lookup; indexed search | Stable association with Discogs, Bandcamp, streaming services, purchase pages, official pages, Wikidata, and other external evidence. |

The official browse table defines which linked entity keys each result type accepts. For audio matching, the particularly useful traversals are artist→recording/release/release-group/work, label→release, recording→release/release-group/work, release-group→release, release→recording, and work→recording. Collection-based browse is also available for many entities. [MusicBrainz Web Service browse table](https://musicbrainz.org/doc/MusicBrainz_API#Browse)

Search additionally supports `annotation`, `cdstub`, and `tag` indexes even though those are not ordinary core-entity lookups. Annotation search can surface free-text editorial context; CD-stub search can rescue a disc that exists only as a submitted table of contents; tag search enumerates tag names. These sources are weaker than structured identifiers and must be treated as candidate evidence. [MusicBrainz search](https://musicbrainz.org/doc/MusicBrainz_API/Search)

### 2.2 Includes and relationships

Useful common includes include `aliases`, `annotation`, `tags`, `ratings`, `genres`, and entity-specific data such as `artist-credits`, `discids`, `isrcs`, `media`, `recordings`, `release-groups`, and `labels`. `user-tags` and `user-ratings` require an authenticated user context; the aggregate forms carry community values and vote counts. [MusicBrainz Web Service](https://musicbrainz.org/doc/MusicBrainz_API)

Relationship includes are selected by target type, for example `artist-rels`, `work-rels`, `recording-rels`, `release-rels`, `release-group-rels`, `label-rels`, `event-rels`, `place-rels`, `series-rels`, `instrument-rels`, `area-rels`, and `url-rels`. The API also supports `recording-level-rels`, `release-group-level-rels`, and `work-level-rels` where relationship traversal needs to include another layer. [MusicBrainz Web Service](https://musicbrainz.org/doc/MusicBrainz_API), [Relationships](https://musicbrainz.org/doc/Relationships)

Relationships can enrich an identified recording or release with:

- performers and the instruments/vocals they performed;
- composers, lyricists, arrangers, publishers, conductors, producers, remixers, mix engineers, mastering engineers, and other credits;
- sampled, remixed, compilation, live-performance, and other recording relationships;
- event, place, area, series, label, and work context; and
- typed external URLs such as Discogs, streaming, download/purchase, official homepage, Wikidata, and social profiles.

The available relationship types and their direction/cardinality are defined by the relationship registry rather than a fixed client-side list. A client should preserve relationship type IDs and attributes rather than reducing them to untyped strings. [Relationship type browser](https://musicbrainz.org/relationships), [Artist–recording relationships](https://musicbrainz.org/relationships/artist-recording), [Release–URL relationships](https://musicbrainz.org/relationships/release-url)

### 2.3 Aliases, genres, tags, and ratings

Aliases include alternate spellings, localized and transliterated names, historic names, search hints, and deliberate misspellings. They participate in MusicBrainz search and are valuable for normalizing a noisy filename without overwriting the canonical display value prematurely. Locale, primary flag, alias type, begin/end dates, and sort name provide more context than the alias string alone. [Aliases](https://musicbrainz.org/doc/Aliases)

MusicBrainz genres are a curated subset of the broader tag vocabulary, while tags and genres remain subjective community classifications. API responses include vote counts, allowing a confidence-weighted enrichment policy, but neither should be an identity gate. Ratings are likewise opinion data, useful for presentation or ranking after identity has been established rather than for matching. [Genre](https://musicbrainz.org/doc/Genre), [MusicBrainz Web Service](https://musicbrainz.org/doc/MusicBrainz_API)

## 3. Deterministic and near-deterministic identifiers

Identifier lookup endpoints return lists because an identifier can legitimately or erroneously map to more than one entity. Even a strong identifier therefore needs an ambiguity check. [MusicBrainz Web Service non-MBID lookups](https://musicbrainz.org/doc/MusicBrainz_API#Non-MBID_Lookups)

| Signal | API route/search field | Strength and limitation |
|---|---|---|
| MusicBrainz ID | `/{entity}/{mbid}` | Best direct key to a MusicBrainz entity, but entities can be merged and redirects/canonical mappings can change. Store the returned current MBID and tolerate redirects. [Canonical MusicBrainz data](https://musicbrainz.org/doc/Canonical_MusicBrainz_data) |
| ISRC | `/isrc/{isrc}` or recording search field `isrc:` | Identifies a recording, not a composition or release. Different recordings, edits, mixes, and remixes generally need distinct ISRCs, while the same recording across territories should retain its ISRC; omissions, duplicates, and data errors still occur. [ISRC](https://musicbrainz.org/doc/ISRC) |
| ISWC | `/iswc/{iswc}` or work search field `iswc:` | Identifies a musical work, potentially returning multiple MusicBrainz works in conflicting data. It cannot prove that two audio files contain the same recording. [ISWC](https://musicbrainz.org/doc/ISWC) |
| Disc ID / CD TOC | `/discid/{id}`; fuzzy `/discid/-?toc=...`; release search `discids:`/`discidsmedium:` | Computed from a CD table of contents and therefore strong for a particular layout. One disc ID can map to multiple releases; different pressings can have different offsets/IDs, and rare collisions exist. Fuzzy TOC lookup defaults to CD media unless `media-format=all` is supplied. [Disc ID](https://musicbrainz.org/doc/Disc_ID), [Disc ID calculation](https://musicbrainz.org/doc/Disc_ID_Calculation), [Web Service](https://musicbrainz.org/doc/MusicBrainz_API) |
| Barcode | release search field `barcode:` and release lookup | UPC/EAN/GTIN printed for a release. It is edition evidence but missing and reused/incorrect barcodes exist, so combine it with format, country/date, and track list. [Barcode](https://musicbrainz.org/doc/Barcode) |
| Catalog number + label | release search `catno:` and `label:`/`laid:`; release label-info | Often strong edition evidence. Catalog numbers are label-assigned, can be alphanumeric, can contain varying separators, and are not the same as label codes or ASINs. Releases may have multiple label/catalog-number pairs. [Catalog number](https://musicbrainz.org/doc/Release/Catalog_Number) |
| Exact external URL | `/url?resource={exact URL}` or URL search fields | Can connect a source URL or external database page to a MusicBrainz URL entity and its relationships. The non-MBID lookup compares the URL resource text; up to 100 results can be returned. [Web Service non-MBID lookups](https://musicbrainz.org/doc/MusicBrainz_API#Non-MBID_Lookups) |
| Track MBID | release browse/search and lookup | Exact edition-track identity when already embedded in tags, but it is different from the recording MBID. [Track](https://musicbrainz.org/doc/Track) |
| AcoustID | AcoustID `/v2/lookup`, then linked MusicBrainz recording IDs | Strong content-derived candidate for a recording, not an exact release. An AcoustID cluster can have zero, one, or multiple linked MusicBrainz recordings. [AcoustID Web Service](https://acoustid.org/webservice), [AcoustID FAQ](https://acoustid.org/faq) |

A conservative matching ladder is therefore: trusted embedded MBIDs → disc ID/TOC, ISRC, barcode, or label/catalog-number evidence → AcoustID recording candidates → exact metadata/track-list candidates → fuzzy metadata candidates. Every stage should continue to validate the complete release structure instead of accepting the first hit.

## 4. Search and fuzzy candidate generation

MusicBrainz search is built on Lucene. `query` is mandatory; `limit` ranges from 1 to 100 and defaults to 25; `offset` paginates; and `dismax=true` selects a simpler parser while the default parser accepts full Lucene syntax. Reserved Lucene characters must be escaped and the complete expression URL-encoded. [MusicBrainz search](https://musicbrainz.org/doc/MusicBrainz_API/Search)

The standard parser supports fielded terms, phrases, Boolean clauses, ranges, boosts, wildcards, and fuzzy terms with `~`. Fuzzy matching uses Damerau–Levenshtein edit distance. Wildcards and fuzziness should be applied to normalized candidate text rather than interpolated directly from an untrusted filename. [Solr standard query parser](https://solr.apache.org/guide/solr/latest/query-guide/standard-query-parser.html)

Examples of candidate queries, with values escaped and URL-encoded in a real request:

```text
recording:"Smells Like Teen Spirit" AND artist:Nirvana
recording:Smels~ AND recording:Spirt~ AND artist:Nirvana
recording:"Smells Like Teen Spirit" AND artist:Nirvana AND qdur:[145 TO 155]
release:"The Dark Side of the Moon" AND artist:"Pink Floyd"
barcode:077774600125
catno:"CDP 7 46001 2" AND label:"Harvest"
```

`qdur` is the recording duration in two-second quantized units (milliseconds divided by 2000), not seconds. A ±5-second tolerance around a 150-second file therefore corresponds approximately to `qdur:[72 TO 77]`. Exact tolerances should be based on source quality and calibrated data, not hard-coded from this example. [Recording search fields](https://musicbrainz.org/doc/MusicBrainz_API/Search#Recording)

### 4.1 High-value fields by search index

The following are the fields most relevant to audio identification; the official search page contains the complete field tables. [MusicBrainz search field reference](https://musicbrainz.org/doc/MusicBrainz_API/Search)

| Index | Useful fields/signals |
|---|---|
| `recording` | recording title/accented title/aliases, artist names and MBIDs, credited name, ISRC, duration and `qdur`, release title/ID, release-group ID, track number/position, track and release track counts, medium format, date/first-release date, country, status, primary/secondary type, video flag, tags. |
| `release` | release title/aliases, artist/credit, barcode, catalog number, label/label MBID, date/country, media count, total and per-medium track counts, format, disc IDs, language/script, packaging, status, quality, primary/secondary type, release-group ID, ASIN, tags. |
| `release-group` | title/aliases, artist, first-release date, primary/secondary type, release IDs/count, status, tags. |
| `artist` | name/accented name/sort name, aliases and primary aliases, area/country, type, gender, life span, IPI, ISNI, tags. |
| `work` | title/aliases, artist, ISWC, language, type, linked recording/title/count, tags. |
| `label` | name/aliases, label code, country, area, type, IPI/ISNI, release count. |
| `event` | name/aliases, date, performers, place, area, event type. |
| `place` | name/aliases, address, area, coordinates, place type. |
| `series` | name/aliases, series type and linked-entity relationships. |
| `instrument` | name/aliases, description and instrument type. |
| `url` | exact URL/resource ancestry plus relationship target type, target MBID, and relationship type. |
| `annotation` | annotation text plus annotated entity name/type. |
| `cdstub` | title, artist, barcode, disc ID, and track count for unimported CD submissions. |
| `tag` | tag name. |

Search's returned `score` is a relevance score, not a documented match probability. MusicBrainz publishes neither a universal auto-accept threshold nor a guarantee that the best textual score uniquely identifies an entity. The private mirror demonstrated why: multiple edits/releases of the same song received `score: 100` despite widely different durations. Candidate generation and final acceptance must therefore be separate steps. [MusicBrainz search](https://musicbrainz.org/doc/MusicBrainz_API/Search)

### 4.2 Canonical metadata as a local fuzzy-search aid

MusicBrainz publishes a Canonical MusicBrainz Data dataset under CC0, currently generated twice monthly. Its `combined_lookup` data normalizes artist credit and recording names by removing punctuation/whitespace differences and applying Unicode transliteration, and the official documentation explicitly describes building a fuzzy index on this material. This can be a high-throughput local candidate source without placing all fuzzy traffic on the main Web Service. Canonical recording/release mappings can change as data changes, so downstream MBIDs need refresh/redirect handling. [Canonical MusicBrainz data](https://musicbrainz.org/doc/Canonical_MusicBrainz_data)

## 5. Album identification from a list of tracks

MusicBrainz provides the raw structure needed for an ensemble matcher. Picard's documented workflow clusters files as an album and compares the group to candidate releases; its options expose a cluster similarity threshold, a minimum difference between the best and second-best release, file-to-track similarity thresholds, and a duration tolerance that defaults to two seconds. Those settings are evidence that whole-album coherence and runner-up separation are important, not suggested universal values for this application. [Picard file retrieval](https://picard-docs.musicbrainz.org/en/latest/usage/retrieve.html), [Picard matching options](https://picard-docs.musicbrainz.org/en/latest/config/options_matching.html)

### 5.1 Candidate generation opportunities

For each plausible audio file:

1. Extract any embedded MBIDs, ISRC, disc number, track number, title, artist, album, year, barcode, catalog number, and duration.
2. When permitted and available, fingerprint the full audio and ask AcoustID for recording MBID candidates.
3. Search a small set of distinctive title/artist/duration combinations rather than every common interlude title.
4. Accumulate release IDs and release-group IDs shared by the candidate recordings. Recording browse by release is valuable here because lookup-embedded linked lists are capped.
5. Fetch the complete media/track structure for the highest-support release candidates.

The MusicBrainz data that can make a track distinctive includes its recording MBID or ISRC, uncommon normalized title tokens and aliases, artist MBID/credit, duration, explicit track/disc number, and release frequency. A one-minute “Intro” is weak evidence; a fingerprinted recording or rare title/artist pair is strong evidence.

### 5.2 Whole-release verification opportunities

Candidate verification can sequence-align the observed files against every medium of a release and evaluate independent signals:

- exact or alias-normalized title agreement;
- recording/track artist-credit agreement;
- duration difference using the release-track duration first and recording median duration as a fallback;
- observed track and disc positions;
- order agreement and contiguous subsequences;
- total track count, per-medium track count, medium count, and medium format;
- unmatched candidate tracks and unmatched local files;
- release/album title, credited artist, date/year, country, language, and script;
- disc ID/TOC, barcode, label/catalog number, ISRC, and embedded MBIDs;
- primary/secondary release-group type and release status; and
- agreement among independently fingerprinted recordings.

Sequence alignment is preferable to a strict position-by-position comparison because Usenet posts may omit bonus tracks, merge hidden tracks, contain only one disc, add cue/log/artwork files, or sort lexicographically. The final result should preserve why each file aligned and why alternative releases lost.

### 5.3 What album matching can and cannot establish

- Several recordings appearing in the same order can strongly establish a release group and often narrow an exact release.
- Recordings alone may not distinguish two regional digital releases with identical audio and track lists. Country/date/label/catalog number/barcode/cover evidence may be required, and sometimes the honest result is “release group known, exact release ambiguous.”
- AcoustID agreement across several tracks is much stronger than one lookup because an individual recording can occur on hundreds of compilations.
- A MusicBrainz recording title is not automatically the right output filename. Once an exact release is known, the release-specific track title and artist credit are the edition-aware naming source. [Track](https://musicbrainz.org/doc/Track), [Recording](https://musicbrainz.org/doc/Recording)
- A work match groups compositions and covers; it must not be treated as confirmation that the audio performance is the same. [Work](https://musicbrainz.org/doc/Work)

A safe acceptance model should have at least three outcomes: exact release accepted, release group accepted but edition unresolved, and no sufficiently distinct match. Automatic renaming should require both an absolute evidence threshold and adequate separation from the second-best structurally plausible candidate.

## 6. Additional MusicBrainz enrichment opportunities

Once identity is established, MusicBrainz data can improve far more than album and track names:

- **Release classification:** release status, data quality, packaging, country, language/script, primary type, and secondary types can distinguish album, single, EP, broadcast, compilation, DJ mix, live, mixtape/street, remix, soundtrack, spoken word, audiobook, and other cases represented by MusicBrainz's release-group type system. [Release Group](https://musicbrainz.org/doc/Release_Group), [Release](https://musicbrainz.org/doc/Release)
- **Edition provenance:** label MBID/name, catalog number, barcode, country, exact date, media formats, and cover-art details can distinguish pressings and reissues. [Release](https://musicbrainz.org/doc/Release)
- **Credits:** relationships can add featured performers, instruments/vocals, conductors, orchestras, composers, lyricists, arrangers, producers, remixers, mix/mastering engineers, and publishers. [Relationships](https://musicbrainz.org/doc/Relationships)
- **Classical and cover recordings:** work links and work attributes provide composition, movement, key/catalogue information, language, writer credits, and recordings of the same work. [Work](https://musicbrainz.org/doc/Work)
- **Live recordings:** event date, event type, performers, venue/place, city/area, coordinates, and event/recording/release relationships can corroborate bootleg or concert metadata. [Event](https://musicbrainz.org/doc/Event), [Place](https://musicbrainz.org/doc/Place), [Area](https://musicbrainz.org/doc/Area)
- **Series:** ordered series relationships can recover volume or sequence membership for compilation, DJ-mix, broadcast, and other branded lines. [Series](https://musicbrainz.org/doc/Series)
- **External corroboration:** typed URL relations can supply Discogs edition pages, Bandcamp/store pages, official sites, streaming links, and Wikidata identities. [Release–URL relationships](https://musicbrainz.org/relationships/release-url)
- **Localized names:** aliases can produce language-aware display names and robust search variants without losing the canonical MusicBrainz name. [Aliases](https://musicbrainz.org/doc/Aliases)
- **Discovery metadata:** vote-weighted genres/tags and ratings can support filtering and browsing after identity is known. [Genre](https://musicbrainz.org/doc/Genre)
- **Editorial context:** annotations can contain disambiguating notes that structured fields do not capture. Because they are free text, they should be displayed or indexed as supporting evidence rather than parsed as a trusted identifier. [Annotation](https://musicbrainz.org/doc/Annotation)

## 7. Cover Art Archive

Cover Art Archive requests go to `coverartarchive.org`, not to the local MusicBrainz mirror. Its read API supports `GET` and `HEAD` for release metadata, front/back images, specific image IDs, and thumbnails at documented sizes; image requests redirect to files hosted by the Internet Archive. Release-group endpoints return representative art and identify the source release. The JSON listing includes image URLs, 250/500/1200-pixel thumbnail URLs, types, `front`, `back`, comment, approval status, and edit ID. [Cover Art Archive API](https://musicbrainz.org/doc/Cover_Art_Archive/API)

```text
GET|HEAD https://coverartarchive.org/release/{release-mbid}
GET|HEAD https://coverartarchive.org/release/{release-mbid}/front
GET|HEAD https://coverartarchive.org/release/{release-mbid}/back
GET|HEAD https://coverartarchive.org/release/{release-mbid}/{image-id}
GET|HEAD https://coverartarchive.org/release/{release-mbid}/{image-id}-{250|500|1200}
GET|HEAD https://coverartarchive.org/release-group/{release-group-mbid}
GET|HEAD https://coverartarchive.org/release-group/{release-group-mbid}/front
```

A MusicBrainz release lookup can include the `cover-art-archive` summary, including whether front/back art exists and the image count. The image listing is then fetched separately from the Cover Art Archive. [MusicBrainz Web Service](https://musicbrainz.org/doc/MusicBrainz_API), [Cover Art Archive API](https://musicbrainz.org/doc/Cover_Art_Archive/API)

Identification and enrichment uses include:

- front cover as the display asset for an exact release, with release-group representative art only as an explicit fallback;
- back-cover or booklet OCR to corroborate track order, titles, credits, label, catalog number, barcode, and country/date after candidates have been generated;
- perceptual hashes of known candidate art as an edition tie-breaker when a source post contains artwork; and
- exposing booklet, medium, obi, spine, sticker, and other image types as richer release evidence.

MusicBrainz documents cover art as evidence for track lists, credits, catalog numbers, barcodes, and pressing information, but the Cover Art Archive API does **not** offer arbitrary reverse-image search. Any OCR or perceptual-hash matching layer would be local logic around known candidate images. [Cover Art](https://musicbrainz.org/doc/Cover_Art), [Cover Art Archive API](https://musicbrainz.org/doc/Cover_Art_Archive/API)

The official API page currently states that the Cover Art Archive has no rate limit, while also documenting `503` as the response if a rate limit is exceeded. This is a policy that can change; cache metadata and images and handle redirects/retries rather than assuming unlimited throughput. [Cover Art Archive API](https://musicbrainz.org/doc/Cover_Art_Archive/API)

## 8. Private MusicBrainz mirror observations

The following are empirical observations from read-only probes on 2026-08-23, not guarantees of upstream or future mirror behavior.

### 8.1 Availability and freshness

- `GET http://10.1.8.50:5000/` and `HEAD` returned successfully and identified the service as a MusicBrainz mirror.
- The home page reported `Last replication packet received at 2026-08-19T00:25:41Z`, about four days behind the research date. [Private mirror](http://10.1.8.50:5000/)
- Indexed search, lookup, fuzzy Lucene search, disc-ID lookup, and ISRC lookup all returned data.
- A `HEAD` request against one `/ws/2` search returned `400` even though `GET` for the same search worked. The integration should use documented `GET` operations for Web Service data and reserve `HEAD` for services that explicitly support it.

The official server installation documentation states that the MusicBrainz application does not itself impose the public MusicBrainz rate limit; a deployer must add reverse-proxy limiting if desired. Therefore the private mirror is not bound by the upstream one-request-per-second rule unless its local deployment adds such a policy. A client should still use bounded concurrency, timeouts, caching, and backoff. [musicbrainz-server installation](https://github.com/metabrainz/musicbrainz-server/blob/master/INSTALL.md), [MusicBrainz rate limiting](https://musicbrainz.org/doc/MusicBrainz_API/Rate_Limiting)

The official Docker mirror runs database replication and indexed-search infrastructure as distinct components. Search indexes are not inherently updated by ordinary replication unless index rebuilding or live indexing is configured; the project describes live indexing as experimental. Monitor both the latest replication packet and search-index freshness, because a successful lookup does not prove that newly replicated data is searchable. [musicbrainz-docker](https://github.com/metabrainz/musicbrainz-docker)

### 8.2 Representative API results

An exact artist search for Pink Floyd returned the intended artist first with a score of 100, while an exact release-title/artist search for *The Dark Side of the Moon* returned 152 results. This illustrates the difference between recognizing an album concept and selecting one exact edition.

A release lookup for a known *The Dark Side of the Moon* edition with `recordings+artist-credits+labels+release-groups+media+discids+isrcs+genres+tags+url-rels` returned:

- one ordered nine-track medium;
- release-track MBIDs, titles, positions, numbers, lengths, and credits;
- recording MBIDs, titles, median lengths, credits, and ISRCs;
- release group/type, date, country, status, barcode, label, and catalog number;
- tags/genres and typed Discogs/Amazon relationships; and
- a Cover Art Archive summary showing front/back art and 16 images.

The response also showed real differences between release-track and recording fields, confirming that both layers need to be retained.

The documented example disc ID `I5l9cCSFccLKFEKS.7wqSZAorPU-` resolved to five *Nevermind* releases on this mirror. Disc ID therefore sharply narrows candidates but does not necessarily select one edition. [Disc ID](https://musicbrainz.org/doc/Disc_ID)

ISRC `GBAHT1600302` resolved to the expected Dua Lipa recording. In this mirror build, `/isrc/{isrc}?inc=releases` returned the recording without embedded releases, and `release-groups` was rejected for that endpoint; a subsequent `/recording/{mbid}?inc=releases` returned the capped release list. This is an implementation/version quirk worth capturing in contract tests. The portable way to obtain a complete release set remains recording lookup followed by paginated browse where necessary. [MusicBrainz Web Service](https://musicbrainz.org/doc/MusicBrainz_API)

### 8.3 Fuzzy-search probe

The mirror accepted this intentionally misspelled fuzzy query:

```text
recording:Smels~ AND recording:Spirt~ AND artist:Nirvana
```

It returned 487 candidates, with several distinct results scoring 100 and durations ranging from about 181 seconds to 372 seconds or unknown. Adding a bounded `qdur` clause narrowed the set to 74 but still left multiple score-100 candidates. The result supports a two-phase strategy: fuzzy text and duration are effective retrieval features, but ordered album coherence, identifiers, and runner-up separation must decide acceptance.

## 9. Upstream MusicBrainz service limits and data licensing

The public MusicBrainz service asks clients to remain at or below one request per second per source IP on average and to send a meaningful `User-Agent` identifying the application and contact information. The service can return `503` when throttled, and the policy may change. These public limits do not automatically apply to the private server, but they do apply to any fallback to `musicbrainz.org`. [MusicBrainz rate limiting](https://musicbrainz.org/doc/MusicBrainz_API/Rate_Limiting)

MusicBrainz core data is CC0. Supplementary data and the live data feed are CC BY-NC-SA 3.0. The database-download page lists the main MusicBrainz dump and CD stubs as CC0 and various derived/editor/statistics/cover-art-related dumps under CC BY-NC-SA. Any feature that redistributes supplementary fields or packaged data should identify the exact source dataset and honor its license rather than assuming every MusicBrainz-associated field is CC0. [MusicBrainz data licenses](https://musicbrainz.org/doc/About/Data_License), [Database download](https://musicbrainz.org/doc/MusicBrainz_Database/Download)

## 10. AcoustID: capabilities, API, and constraints

### 10.1 What it identifies

Chromaprint is designed to identify near-identical audio efficiently, not to measure general musical similarity. Its documented use cases are full-file identification, duplicate detection, and monitoring audio streams; it deliberately trades some robustness and precision for speed. It should not be expected to declare that a studio original, live cover, remix, remaster with substantial changes, or phone recording is “the same song.” [Chromaprint README](https://github.com/acoustid/chromaprint)

The public AcoustID service is designed for full audio files. Its FAQ states that it cannot identify short snippets or audio captured through a phone microphone with background noise. An AcoustID “track ID” is a cluster of submitted fingerprints and may link to zero, one, or multiple MusicBrainz recording MBIDs. It is not a MusicBrainz release/edition ID. [AcoustID FAQ](https://acoustid.org/faq), [AcoustID Web Service](https://acoustid.org/webservice)

The public statistics page is useful context but not a promised hit rate. On 2026-08-23 it reported 75,715,724 AcoustIDs, 93,424,170 fingerprints, and 21,865,754 links to MusicBrainz recordings; 30.38% of AcoustIDs had a MusicBrainz link, and 91.05% of linked AcoustIDs had exactly one linked recording. These figures change and describe the service corpus, not the probability that any particular local collection will match. [AcoustID statistics](https://acoustid.org/stats)

### 10.2 Lookup API

The public lookup endpoint accepts `GET` or `POST` at `/v2/lookup`. Required parameters are an application client key, the whole-file duration in seconds, and the Chromaprint fingerprint. Optional `meta` values include `recordings`, `recordingids`, `releases`, `releaseids`, `releasegroups`, `releasegroupids`, `tracks`, `compress`, `usermeta`, `sources`, and `isrcs`. JSON is the default response; XML and JSONP are also documented. The service recommends gzipped `POST` for larger fingerprints. [AcoustID Web Service](https://acoustid.org/webservice)

```text
POST https://api.acoustid.org/v2/lookup
client={application-key}
duration={whole-file-duration-seconds}
fingerprint={chromaprint-fingerprint}
meta=recordingids+releaseids+releasegroupids+tracks+isrcs
```

Each match returns an AcoustID UUID and score. Official client code describes score values between 0 and 1, but AcoustID publishes no universal probability interpretation, recommended production threshold, or calibration curve. Thresholds and best-vs-second margins must be calibrated against representative positive and negative audio. [pyacoustid](https://github.com/beetbox/pyacoustid/blob/master/acoustid.py), [AcoustID Web Service](https://acoustid.org/webservice)

The API can also look up by AcoustID track ID and provides `/v2/track/list_by_mbid` for MusicBrainz mappings. The documented lookup API has no multi-fingerprint batch endpoint; batching is documented for submissions, not identification lookups. [AcoustID Web Service](https://acoustid.org/webservice)

Fingerprint lookup sends a compact fingerprint and duration to AcoustID, not the source audio file. This is a useful privacy and bandwidth property, although the fingerprint is still derived data sent to a third party and should be disclosed in deployment/privacy documentation. [AcoustID Web Service](https://acoustid.org/webservice)

### 10.3 Submission is a separate, unnecessary capability

`/v2/submit` accepts an application key, user key, fingerprint, duration, and optional MusicBrainz/text metadata; it supports batches and asynchronous submission status. Identification does not require submission. A read-only enrichment feature should call lookup only unless the operator explicitly opts into contributing fingerprints and metadata under a separately reviewed policy. [AcoustID Web Service](https://acoustid.org/webservice)

### 10.4 Public-service rate and usage terms

The public service requires an application key and limits clients to no more than three requests per second; AcoustID asks high-traffic users to contact the service. The public offering is described for non-commercial/open-source use, while commercial use is offered separately. [AcoustID Web Service](https://acoustid.org/webservice), [AcoustID pricing](https://acoustid.biz/)

On the research date, the official commercial page advertised these mutable tiers: open-source use at three requests/second; €50/month for one million searches; €100/month for 15 million; and €500/month for 150 million, with the first 10,000 searches uncharged for paid plans. Current terms, taxes, SLA, and suitability for the deployment must be reconfirmed before implementation. [AcoustID pricing](https://acoustid.biz/)

At three requests per second, one-track-per-request fingerprint lookup has a hard theoretical ceiling of 259,200 tracks/day before failures, retries, or competing traffic. The absence of a documented batch lookup makes a local result cache keyed by fingerprint algorithm/version, fingerprint, and duration especially valuable.

### 10.5 Public dumps and self-hosting

AcoustID publishes incremental JSON data files for fingerprints, tracks, track–fingerprint links, track–MusicBrainz links, and metadata. The AcoustID database is CC BY-SA 3.0; the MusicBrainz mapping is placed in the public domain. Hosted API terms are separate from dump licensing. [AcoustID database downloads](https://acoustid.org/database), [AcoustID data index](https://data.acoustid.org/)

On 2026-08-23 the public 2026 data index stopped at 2026-07-27, and the official status page reported an ingestion migration around July 27. This may be transient, but a dump-based design must monitor freshness instead of assuming daily updates. [2026 AcoustID data index](https://data.acoustid.org/2026/), [AcoustID status](https://status.acoustid.org/)

The AcoustID server source is MIT-licensed, but its own README says self-hosting is unsupported and probably not useful for most users. The separate `acoustid-index` project is a large in-memory fingerprint index, not a turnkey metadata mirror. Self-hosting would therefore be a substantial search/data-engineering project rather than simply replacing the API base URL. [acoustid-server](https://github.com/acoustid/acoustid-server), [acoustid-index](https://github.com/acoustid/acoustid-index)

AcoustID also documents a separate private-catalog API for recognizing against an uploaded private catalog. That API supports full-track and stream-oriented matching, with stream matches discussed in 10–30-second terms and warnings about false positives/repeated matches. Published availability and pricing are not documented there, and this product should not be confused with the public database lookup API. [AcoustID private API](https://github.com/acoustid/acoustid-priv/blob/master/docs/api.md)

## 11. Chromaprint and `fpcalc` integration facts

### 11.1 Library versus command-line tool

The Chromaprint C library consumes decoded signed 16-bit PCM; it does not decode audio containers/codecs itself. The bundled `fpcalc` command uses FFmpeg for decoding and is the officially recommended command-line route, with JSON output available for machine integration. [Chromaprint README](https://github.com/acoustid/chromaprint), [fpcalc source](https://github.com/acoustid/chromaprint/blob/master/src/cmd/fpcalc.cpp)

Important `fpcalc` options include:

- `-length N`, with a default of 120 seconds and `-length 0` for the whole stream;
- `-json` for structured output;
- `-algorithm 1..5`, currently defaulting to algorithm 2;
- raw/signed fingerprint output controls;
- sample rate, channel, and sample-format inputs for raw streams;
- chunk, overlap, and timestamp modes; and
- files, network/FFmpeg-readable streams, standard input, or multiple inputs.

The default fingerprint analyzes the first 120 seconds but reports the duration of the complete decoded source. AcoustID requires the whole-file duration. This makes a beginning-anchored partial decode potentially compatible with established clients, but arbitrary preview offsets are not documented as equivalent and need empirical validation. [fpcalc source](https://github.com/acoustid/chromaprint/blob/master/src/cmd/fpcalc.cpp), [pyacoustid](https://github.com/beetbox/pyacoustid/blob/master/acoustid.py)

Chunking is a local analysis feature and does not turn the public AcoustID endpoint into a general snippet matcher. Encoded fingerprints are optimized for submission/storage and are not directly comparable; raw fingerprint arrays can be compared, but the README warns that coarse similarity hashes are not fully reliable. [Chromaprint README](https://github.com/acoustid/chromaprint)

Chromaprint's documented silence threshold should not be customized for fingerprints intended for AcoustID. FFTW-backed contexts must be allocated/freed in a non-reentrant region; that restriction does not apply to the FFmpeg or vDSP FFT backends. [Chromaprint README](https://github.com/acoustid/chromaprint)

`fpcalc` may emit a fingerprint after a decoder error while returning exit status 3; `-ignore-errors` changes that outcome to success. A robust caller must inspect the exit status, output validity, reported duration, and stderr policy rather than treating any fingerprint-shaped output as success. [fpcalc source](https://github.com/acoustid/chromaprint/blob/master/src/cmd/fpcalc.cpp)

### 11.2 Version and security

The official download page and repository releases listed Chromaprint 1.6.1 as current on the research date. Its release notes include a fix for a heap buffer overflow when decoding malformed externally supplied fingerprints. Pin an actively maintained release and treat any externally stored fingerprint as untrusted input. [Chromaprint downloads](https://acoustid.org/chromaprint), [Chromaprint releases](https://github.com/acoustid/chromaprint/releases)

### 11.3 Licensing and binary-distribution constraints

Chromaprint's own code is MIT-licensed, but its source distribution includes FFmpeg-derived code under LGPL 2.1 and the project's license file says the distribution as a whole is considered LGPL 2.1. Builds using FFTW3 become GPL according to the project README; the FFmpeg or platform vDSP backends avoid that FFTW-specific GPL consequence, while KissFFT is permissive but slower. [Chromaprint license](https://github.com/acoustid/chromaprint/blob/master/LICENSE.md), [Chromaprint README](https://github.com/acoustid/chromaprint)

`fpcalc` links FFmpeg. FFmpeg is LGPL 2.1-or-later by default, can become GPL when GPL components/options are enabled, and a build using `--enable-nonfree` is not redistributable. Packaging must inspect the exact Chromaprint and FFmpeg build configuration rather than relying only on project names. [FFmpeg legal information](https://ffmpeg.org/legal.html)

## 12. Suggested validation questions before committing to an implementation

The official services do not answer several deployment-specific questions. A bounded corpus experiment should measure them rather than filling the gaps with assumptions:

1. What fraction of representative complete audio files returns any AcoustID result, any MusicBrainz recording ID, and the correct recording ID?
2. How do AcoustID score, best-vs-second margin, codec, bitrate, leading silence, remastering, and source corruption affect false accepts and false rejects?
3. Does `fpcalc`'s first-120-second default perform as well as full-length fingerprinting on the actual corpus, and what is the minimum safe beginning-anchored decode? The public service documents no universal minimum duration.
4. How often can multiple identified recordings resolve an exact release versus only a release group?
5. Which combination of ordered title, artist, duration, track-count, disc-count, and missing-track penalties reliably separates editions?
6. How often are embedded ISRCs, barcodes, catalog numbers, disc IDs, or MBIDs present and trustworthy?
7. How stale are the private mirror's replication and search index in normal operation, and what is the desired fallback when either lags?
8. Does the exact intended `fpcalc` binary satisfy codec coverage, resource, security, and redistribution requirements?
9. Are the deployment and resulting enriched-data uses compatible with current AcoustID service terms and the licenses of every MusicBrainz/AcoustID dataset consumed?

The experiment should retain candidate lists and feature-level explanations, not only final labels. That makes threshold calibration auditable and exposes whether mistakes come from search retrieval, metadata quality, fingerprint ambiguity, album alignment, or edition selection.

## 13. Primary-source index

- [MusicBrainz Web Service](https://musicbrainz.org/doc/MusicBrainz_API)
- [MusicBrainz search](https://musicbrainz.org/doc/MusicBrainz_API/Search)
- [MusicBrainz rate limiting](https://musicbrainz.org/doc/MusicBrainz_API/Rate_Limiting)
- [MusicBrainz relationships](https://musicbrainz.org/doc/Relationships)
- [MusicBrainz data licenses](https://musicbrainz.org/doc/About/Data_License)
- [MusicBrainz database downloads](https://musicbrainz.org/doc/MusicBrainz_Database/Download)
- [Canonical MusicBrainz data](https://musicbrainz.org/doc/Canonical_MusicBrainz_data)
- [MusicBrainz server installation](https://github.com/metabrainz/musicbrainz-server/blob/master/INSTALL.md)
- [Official MusicBrainz Docker mirror](https://github.com/metabrainz/musicbrainz-docker)
- [Cover Art Archive API](https://musicbrainz.org/doc/Cover_Art_Archive/API)
- [Picard file retrieval](https://picard-docs.musicbrainz.org/en/latest/usage/retrieve.html)
- [Picard matching options](https://picard-docs.musicbrainz.org/en/latest/config/options_matching.html)
- [AcoustID Web Service](https://acoustid.org/webservice)
- [AcoustID FAQ](https://acoustid.org/faq)
- [AcoustID database downloads](https://acoustid.org/database)
- [Chromaprint source and documentation](https://github.com/acoustid/chromaprint)
- [FFmpeg legal information](https://ffmpeg.org/legal.html)
