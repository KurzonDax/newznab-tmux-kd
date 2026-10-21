<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Models\Category;
use App\Services\AdditionalProcessing\PostedFileClassifier;
use App\Services\ReleaseCleaningService;
use Closure;

/**
 * Decides whether a candidate renames a release, the name it writes and how far that name is
 * trusted. It reads only what it is given: the caller loads the recovery verdict and the
 * release's stored files.
 */
final readonly class NamePolicy
{
    private const string EPISODE_TOKEN_REGEX = '/(?<![A-Za-z0-9])S(\d{1,4})[._ -]?E(\d{1,4})(?!\d)/i';

    public function __construct(private FileNameCleaner $fileNameCleaner = new FileNameCleaner) {}

    /**
     * Whether the source's evidence skips plausibility, and its trusted-donor value. A candidate
     * carrying a PreDB id is always a trusted donor that skips plausibility, unless its source
     * declines PreDB trust.
     *
     * @return array{bool, TrustedDonor}
     */
    public static function trust(NameSource $source, int $preDbId): array
    {
        if ($preDbId > 0 && $source->trustsPreDbId()) {
            return [true, TrustedDonor::Trusted];
        }

        return [$source->skipsPlausibility(), $source->trustedDonor()];
    }

    /**
     * @param  object  $release  the release as the caller read it
     * @param  bool  $recoveryAllowed  the recovery identity policy's verdict on renaming the release
     * @param  bool  $alreadyRenamed  whether this service instance already renamed the release
     * @param  ?Closure(): array<string, int>  $storedFiles  the release's stored file names and sizes, loaded on demand
     */
    public function decide(
        object $release,
        NameCandidate $candidate,
        bool $recoveryAllowed = true,
        bool $alreadyRenamed = false,
        ?Closure $storedFiles = null,
    ): NameDecision {
        [$skipsPlausibility, $trust] = self::trust($candidate->source, $candidate->preDbId);
        $guards = $candidate->source->guards();
        if ($guards === []) {
            return NameDecision::accept($candidate->title, $skipsPlausibility, $trust);
        }

        $applies = static fn (NameGuard $guard): bool => in_array($guard, $guards, true);
        if (! $recoveryAllowed) {
            return NameDecision::reject(NameRejection::RecoveryRefused);
        }
        if ($alreadyRenamed && $applies(NameGuard::OncePerRelease)) {
            return NameDecision::reject(NameRejection::AlreadyRenamed);
        }

        $name = $candidate->title;
        // Download-site advertising is never a name, whatever source or PreDB id presents it.
        if ($applies(NameGuard::Promotional) && $this->fileNameCleaner->isPromotionalTitle($name)) {
            return NameDecision::reject(NameRejection::Promotional);
        }

        $descriptiveMediaTitle = $candidate->source->isDescriptiveMediaTitle();
        if ($descriptiveMediaTitle
            && (! $candidate->descriptiveTitle
                || ! $this->fileNameCleaner->isDescriptiveContainerTitle($name)
                || $this->fileNameCleaner->isReadableReleaseTitle((string) $release->searchname)
                || $this->fileNameCleaner->isDescriptiveContainerTitle((string) $release->searchname))) {
            return NameDecision::reject(NameRejection::DescriptiveMediaTitle);
        }

        $newTitle = $this->finalizeCandidate($release, $name, $candidate->source, $descriptiveMediaTitle);
        if ($applies(NameGuard::Promotional) && $this->fileNameCleaner->isPromotionalTitle($newTitle)) {
            return NameDecision::reject(NameRejection::Promotional, $newTitle);
        }

        $categoryId = (int) ($release->categories_id ?? $release->categoryid ?? 0);
        $replacesXxxPostingLabel = $candidate->descriptiveTitle
            && $this->replacesXxxPostingLabel($release, $name, $candidate->source, $categoryId);

        // An episode's title is not the name of a release that holds more than that episode.
        if ($applies(NameGuard::Episode) && ! $replacesXxxPostingLabel && $this->namesOneEpisodeOfLargerRelease($release, $newTitle, $storedFiles)) {
            return NameDecision::reject(NameRejection::EpisodeOfLargerRelease, $newTitle);
        }

        $currentNameObfuscated = $this->fileNameCleaner->currentNameLooksObfuscated(
            (string) $release->searchname,
            $categoryId,
            isset($release->matchedBy)
                ? (string) $release->matchedBy
                : (isset($release->matched_by) ? (string) $release->matched_by : null),
        );
        $acceptedDescriptiveTitle = $replacesXxxPostingLabel
            || ($candidate->descriptiveTitle
                && ($descriptiveMediaTitle || $this->fileNameCleaner->isDescriptiveTitle($name))
                && $currentNameObfuscated);

        if ($applies(NameGuard::Plausibility)
            && ! $skipsPlausibility
            && ! $acceptedDescriptiveTitle
            && ! $this->fileNameCleaner->isPlausibleReleaseTitle($newTitle)) {
            return NameDecision::reject(NameRejection::Implausible, $newTitle);
        }

        if ($applies(NameGuard::LessInformative)
            && ! $skipsPlausibility
            && ! $acceptedDescriptiveTitle
            && ! $currentNameObfuscated
            && $this->fileNameCleaner->isLessInformativeThan($newTitle, (string) $release->searchname)) {
            return NameDecision::reject(NameRejection::LessInformative, $newTitle);
        }

        if ($applies(NameGuard::SameName) && strtolower($newTitle) === strtolower((string) $release->searchname)) {
            return NameDecision::reject(NameRejection::SameName, $newTitle);
        }

        return NameDecision::accept($newTitle, $skipsPlausibility, $trust);
    }

    /** Carry evidence tokens the current name or the posted subject holds over to the candidate. */
    public function preserveEvidence(object $release, string $candidate): string
    {
        return $this->fileNameCleaner->preserveEvidenceTokens(
            $candidate,
            (string) $release->searchname,
            (string) ($release->name ?? ''),
        );
    }

    private function finalizeCandidate(object $release, string $name, NameSource $source, bool $descriptiveMediaTitle): string
    {
        $cleanedName = $descriptiveMediaTitle ? trim($name) : (new ReleaseCleaningService)->fixerCleaner($name);
        $normalizedName = $this->fileNameCleaner->normalizeCandidateTitle($cleanedName);
        $candidate = $descriptiveMediaTitle
            ? $cleanedName
            : $this->fileNameCleaner->formatSearchName($cleanedName, $normalizedName);

        if ($source->type() === 'PAR2, ') {
            $candidate = ucwords($candidate);
            if (preg_match('/(.+?)\.[a-z0-9]{2,3}(PAR2)?$/i', $name, $hit)) {
                $candidate = $hit[1];
            }
        }

        if (! $descriptiveMediaTitle) {
            $pathParts = explode('\\', $candidate);
            $candidate = preg_replace(['/^[=_.:\s-]+/', '/[=_.:\s-]+$/'], '', $pathParts[0]) ?? $pathParts[0];
        }

        return $this->preserveEvidence($release, substr($candidate, 0, 299));
    }

    /**
     * Under XXX, a descriptive video filename may replace an opaque posting label. The release
     * may hold several videos, so neither the episode guard nor a size share applies to it.
     */
    private function replacesXxxPostingLabel(object $release, string $name, NameSource $source, int $categoryId): bool
    {
        return $source->isDescriptiveFileTitle()
            && Category::rootCategoryFor($categoryId) === Category::XXX_ROOT
            && $this->fileNameCleaner->isDescriptiveTitle($name)
            && $this->fileNameCleaner->isUnresolvedPostingLabel((string) $release->searchname);
    }

    /**
     * An episode title stands for a whole release only when the release is that one episode:
     * no known inner file names another episode, and the episode's own video is not the
     * smaller part of the release. Without such evidence the candidate is judged as before.
     *
     * @param  ?Closure(): array<string, int>  $storedFiles
     */
    private function namesOneEpisodeOfLargerRelease(object $release, string $title, ?Closure $storedFiles): bool
    {
        $episode = $this->soleEpisode($title);
        if ($episode === null) {
            return false;
        }

        $sizes = $storedFiles === null ? [] : $storedFiles();
        // Files the archive inspector has listed but not yet stored.
        foreach ($release->knownFiles ?? [] as $file) {
            $sizes[(string) $file['name']] = (int) ($file['size'] ?? 0);
        }

        $episodeSize = 0;
        foreach ($sizes as $fileName => $size) {
            $fileName = (string) $fileName;
            if (! ArchiveNamingPath::eligible($fileName)
                || ! preg_match_all(self::EPISODE_TOKEN_REGEX, $fileName, $named, PREG_SET_ORDER)) {
                continue;
            }
            $episodes = array_map(static fn (array $hit): string => (int) $hit[1].'x'.(int) $hit[2], $named);
            if (! in_array($episode, $episodes, true)) {
                return true;
            }
            if (preg_match('/'.PostedFileClassifier::VIDEO_FILE_REGEX.'$/i', $fileName)
                && ! preg_match('~(?:^|[\\\\/._ -])sample(?:[\\\\/._ -]|$)~i', $fileName)) {
                $episodeSize += $size;
            }
        }

        return $episodeSize > 0 && $episodeSize * 2 < (int) ($release->relsize ?? $release->size ?? 0);
    }

    /** The one episode a title names, or null when it names none, several, or a range. */
    private function soleEpisode(string $title): ?string
    {
        if (! preg_match_all(self::EPISODE_TOKEN_REGEX, $title, $named, PREG_SET_ORDER)
            || preg_match('/(?<![A-Za-z0-9])S\d{1,4}[._ -]?E\d{1,4}(?:[._ -]?E\d|\s?-\s?E?\d{1,3}(?![\dpi])|\s?[&+])/i', $title)) {
            return null;
        }
        $episodes = array_unique(array_map(static fn (array $hit): string => (int) $hit[1].'x'.(int) $hit[2], $named));

        return count($episodes) === 1 ? $episodes[0] : null;
    }
}
