<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use App\Models\Category;
use App\Traits\DetectsHashedNames;

/**
 * Utility class for cleaning and normalizing filenames.
 *
 * Extracts filename cleaning logic from NameFixer for better testability
 * and reusability.
 */
class FileNameCleaner
{
    use DetectsHashedNames;

    protected NzbSplitUnwrapper $nzbSplitUnwrapper;

    public function __construct(?NzbSplitUnwrapper $nzbSplitUnwrapper = null)
    {
        $this->nzbSplitUnwrapper = $nzbSplitUnwrapper ?? new NzbSplitUnwrapper;
    }

    /**
     * Generic DVD structure filenames and similarly low-information names.
     *
     * @var list<string>
     */
    private const IGNORABLE_NAME_PATTERNS = [
        '/^(?:audio|video)[._-]?ts$/i',
        '/^vts[._-]?\d{1,2}[._-]?\d{1,2}$/i',
        '/^\d{1,3}$/',
    ];

    /**
     * Archive extension patterns to remove.
     */
    private const ARCHIVE_PATTERNS = [
        '/\.part\d{1,4}\.rar$/i',
        '/\.r\d{2,4}$/i',
        '/\.rar$/i',
        '/\.z\d{2}$/i',
        '/\.zip$/i',
        '/\.7z\.\d{3}$/i',
        '/\.7z$/i',
        '/\.vol\d+[\+\-]\d+\.par2?$/i',
        '/\.par2?$/i',
        '/\.(tar|gz|bz2|xz|lz|lzma|cab|arj|ace|arc)$/i',
        '/\.\d{3}$/i',
    ];

    /**
     * Video file extension pattern.
     */
    private const VIDEO_EXTENSIONS = '/\.(mkv|avi|mp4|m4v|wmv|mpg|mpeg|mov|ts|m2ts|vob|divx|flv|webm|ogv|3gp|asf|rm|rmvb|f4v)$/i';

    /**
     * Audio file extension pattern.
     */
    private const AUDIO_EXTENSIONS = '/\.(mp3|flac|m4a|aac|ogg|wav|wma|ape|opus|mka|ac3|dts|eac3|truehd|mpc|shn|tak|tta|wv)$/i';

    /**
     * Image and misc file extension pattern.
     */
    private const IMAGE_EXTENSIONS = '/\.(nfo|sfv|nzb|srr|srs|jpg|jpeg|png|gif|bmp|tiff?|webp|pdf|txt|diz|md5|sha1|cue|log)$/i';

    /**
     * Ebook file extension pattern.
     */
    private const EBOOK_EXTENSIONS = '/\.(epub|mobi|azw3?|pdf|djvu|cbr|cbz|fb2|lit|prc|opf)$/i';

    /**
     * Game/App file extension pattern.
     */
    private const GAMEAPP_EXTENSIONS = '/\.(iso|bin|cue|mdf|mds|nrg|img|ccd|sub|exe|msi|dmg|pkg|apk|xap|appx|deb|rpm)$/i';

    /**
     * Subtitle file extension pattern.
     */
    private const SUBTITLE_EXTENSIONS = '/\.(srt|sub|idx|ass|ssa|vtt|sup)$/i';

    private const YEAR_SIGNAL = '/\b(19|20)\d{2}\b/';

    private const RESOLUTION_EVIDENCE_TOKEN = '360p|480p|540p|576p|720p|1080[pi]?|2160p|4k|uhd';

    private const SOURCE_EVIDENCE_TOKEN = 'ntsc|pal|dvd(?:r|rip|5|9)?|webrip|web[ ._-]?dl|bluray|blu[ ._-]?ray|bdrip|brrip|hdtv|pdtv|dsr|tvrip|satrip|dthrip|hdrip|remux|ts|cam|r5';

    private const CODEC_EVIDENCE_TOKEN = 'xvid|divx|x264|x265|hevc|h[ .]?264|h[ .]?265|avc|av1';

    private const LANGUAGE_EVIDENCE_TOKEN = 'danish|deutsch|dutch|flemish|french|german|hebrew|italian|ita|norwegian|spanish|swedish|swesub|nl[ ._-]?sub|multi|dual';

    private const QUALITY_SOURCE_SIGNAL = '/\b(480p|720p|1080p|2160p|4k|ntsc|pal|dvd(?:r|rip)?|webrip|web[ .-]?dl|bluray|bdrip|hdtv|hdrip|xvid|x264|x265|hevc|h\.?264|ts|cam|r5|proper|repack)\b/i';

    private const MAX_PERSISTED_SEARCH_NAME_LENGTH = 255;

    /**
     * Evidence groups where an incoming token supersedes outgoing evidence.
     *
     * @var array<string, string>
     */
    private const EVIDENCE_TOKEN_PATTERNS = [
        'resolution' => '/\b(?:'.self::RESOLUTION_EVIDENCE_TOKEN.')\b/i',
        'source' => '/\b(?:'.self::SOURCE_EVIDENCE_TOKEN.')\b/i',
        'codec' => '/\b(?:'.self::CODEC_EVIDENCE_TOKEN.')\b/i',
        'language' => '/\b(?:'.self::LANGUAGE_EVIDENCE_TOKEN.')\b/i',
    ];

    private const TV_SIGNAL = '/\bS\d{1,2}(?:[Eex]\d{1,3})?\b/i';

    /**
     * Clean a filename for PreDB matching.
     *
     * @param  string  $fileName  The filename to clean
     * @return string|false The cleaned filename or false if invalid
     */
    public function cleanForMatching(string $fileName): string|false
    {
        // Strip non-printing characters
        $fileName = preg_replace('/[[:^print:]]/', '', $fileName);

        if ($fileName === '' || str_starts_with($fileName, '.')) {
            return false;
        }

        // Extract filename from path
        $fileName = $this->extractFilenameFromPath($fileName);

        $fileName = $this->extractNzbSplitName($fileName) ?? $fileName;

        if ($this->isIgnorableForMatching($fileName)) {
            return false;
        }

        // Remove sample/proof indicators
        $fileName = preg_replace('/[\.\-_](sample|proof|subs?|thumbs?|cover|screens?)[\.\-_]?$/i', '', $fileName);

        // Remove archive extensions
        foreach (self::ARCHIVE_PATTERNS as $pattern) {
            $fileName = preg_replace($pattern, '', $fileName);
        }

        // Remove media extensions
        $fileName = preg_replace(self::VIDEO_EXTENSIONS, '', $fileName);
        $fileName = preg_replace(self::AUDIO_EXTENSIONS, '', $fileName);
        $fileName = preg_replace(self::IMAGE_EXTENSIONS, '', $fileName);
        $fileName = preg_replace(self::EBOOK_EXTENSIONS, '', $fileName);
        $fileName = preg_replace(self::GAMEAPP_EXTENSIONS, '', $fileName);
        $fileName = preg_replace(self::SUBTITLE_EXTENSIONS, '', $fileName);

        // Remove part/volume indicators
        $fileName = preg_replace('/[\.\-_]?(part|vol|cd|dvd|disc|disk)\d*$/i', '', $fileName);

        // Remove leading track numbers
        $fileName = preg_replace('/^\d{1,3}[\.\-_\s]+(?=[A-Za-z])/', '', $fileName);

        // Trim whitespace and punctuation
        $fileName = trim($fileName, " \t\n\r\0\x0B.-_");

        if ($fileName === '' || $this->looksLikeStructuralJunk($fileName)) {
            return false;
        }

        return $fileName;
    }

    /**
     * Extract filename from a path.
     *
     * @param  string  $path  Full path or filename
     * @return string The filename portion
     */
    public function extractFilenameFromPath(string $path): string
    {
        if (preg_match('/[\\\\\/]([^\\\\\/]+)$/', $path, $match)) {
            return $match[1];
        }

        return $path;
    }

    /**
     * Normalize a candidate title.
     *
     * @param  string  $title  The title to normalize
     * @return string The normalized title
     */
    public function normalizeCandidateTitle(string $title): string
    {
        $t = trim($title);

        $t = $this->extractNzbSplitName($t) ?? $t;

        // Remove common video file extensions
        $t = preg_replace('/\.(mkv|avi|mp4|m4v|mpg|mpeg|wmv|flv|mov|ts|vob|iso|divx)$/i', '', $t) ?? $t;

        // Remove archive, metadata, and common document/file extensions
        $t = preg_replace(
            '/\.(par2?|nfo|sfv|nzb|rar|zip|7z|gz|tar|bz2|xz|r\d{2,3}|\d{3}|pkg|exe|msi|jpe?g|png|gif|bmp|pdf|epub|mobi|azw3?|djvu|cbr|cbz|fb2|lit|prc|opf|txt|log)$/i',
            '',
            $t
        ) ?? $t;

        // Remove common trailing segment markers
        $t = preg_replace('/[.\-_ ](?:part|vol|r)\d+(?:\+\d+)?$/i', '', $t) ?? $t;

        // Collapse multiple spaces/underscores
        $t = preg_replace('/[\s_]+/', ' ', $t) ?? $t;

        // Trim stray punctuation
        return trim($t, " .-_\t\r\n");
    }

    /**
     * Format a candidate for storage in searchname.
     */
    public function formatSearchName(string $title, ?string $normalizedFallback = null): string
    {
        $formatted = trim($title);

        $formatted = $this->extractNzbSplitName($formatted) ?? $formatted;

        // Remove common media/archive extensions while keeping scene separators.
        $formatted = preg_replace(self::VIDEO_EXTENSIONS, '', $formatted) ?? $formatted;
        $formatted = preg_replace(self::AUDIO_EXTENSIONS, '', $formatted) ?? $formatted;
        $formatted = preg_replace(self::IMAGE_EXTENSIONS, '', $formatted) ?? $formatted;
        $formatted = preg_replace(self::EBOOK_EXTENSIONS, '', $formatted) ?? $formatted;
        $formatted = preg_replace(self::GAMEAPP_EXTENSIONS, '', $formatted) ?? $formatted;
        $formatted = preg_replace(self::SUBTITLE_EXTENSIONS, '', $formatted) ?? $formatted;

        foreach (self::ARCHIVE_PATTERNS as $pattern) {
            $formatted = preg_replace($pattern, '', $formatted) ?? $formatted;
        }

        $formatted = preg_replace('/[.\-_ ](?:part|vol|r)\d+(?:\+\d+)?$/i', '', $formatted) ?? $formatted;
        $formatted = trim($formatted, " .-_\t\r\n");

        if ($formatted === '') {
            return $normalizedFallback ?? $this->normalizeCandidateTitle($title);
        }

        if (! $this->looksLikeSceneRelease($formatted)) {
            return $normalizedFallback ?? $this->normalizeCandidateTitle($title);
        }

        $formatted = preg_replace('/[ _]+/', '.', $formatted) ?? $formatted;
        $formatted = preg_replace('/\.{2,}/', '.', $formatted) ?? $formatted;

        return trim($formatted, " .-_\t\r\n");
    }

    /**
     * Check if a title is plausible for a release.
     *
     * @param  string  $title  The title to check
     * @return bool True if the title looks like a valid release name
     */
    public function isPlausibleReleaseTitle(string $title): bool
    {
        $t = trim($title);

        if ($t === '' || strlen($t) < 12) {
            return false;
        }

        $wordCount = preg_match_all('/[A-Za-z0-9]{3,}/', $t);
        if ($wordCount < 2) {
            return false;
        }

        if ($this->looksLikeHashedName($t)) {
            return false;
        }

        // Reject if still ends with segment marker
        if (preg_match('/(?:^|[.\-_ ])(?:part|vol|r)\d+(?:\+\d+)?$/i', $t)) {
            return false;
        }

        // Reject generic installer filenames
        if (preg_match('/^(setup|install|installer|patch|update|crack|keygen)\d*[\s._-]/i', $t)) {
            return false;
        }

        // Check for valid release indicators
        $signals = $this->informationSignals($t);
        $hasXXX = (bool) preg_match('/\bXXX\b/i', $t);

        return $signals['group_suffix']
            || ($signals['tv'] && $signals['quality_source'])
            || ($signals['year'] && ($signals['quality_source'] || $signals['tv']))
            || $hasXXX
            || $signals['quality_source']
            || $signals['tv'];
    }

    /**
     * Determine whether a candidate loses information carried by a plausible current name.
     */
    public function isLessInformativeThan(string $candidate, string $currentName): bool
    {
        $current = $this->informationProfile($currentName);
        if (! $current['plausible']) {
            return false;
        }

        $replacement = $this->informationProfile($candidate);
        foreach (['year', 'quality_source', 'tv'] as $signal) {
            if ($current[$signal] && ! $replacement[$signal]) {
                return true;
            }
        }

        return ! $replacement['year']
            && ! $replacement['quality_source']
            && ! $replacement['tv']
            && $replacement['tokens'] < $current['tokens'];
    }

    /**
     * Preserve bounded categorization evidence that a replacement name omits.
     */
    public function preserveEvidenceTokens(string $replacement, string ...$outgoingNames): string
    {
        $preservedTokens = [];

        foreach (self::EVIDENCE_TOKEN_PATTERNS as $evidenceGroup => $pattern) {
            $replacementTokens = $this->matchingEvidenceTokens($pattern, $replacement);

            if ($evidenceGroup !== 'language') {
                if ($replacementTokens !== []) {
                    continue;
                }

                foreach ($outgoingNames as $outgoingName) {
                    $outgoingTokens = $this->matchingEvidenceTokens($pattern, $outgoingName);
                    if ($outgoingTokens === []) {
                        continue;
                    }

                    $preservedTokens[] = $outgoingTokens[0];

                    break;
                }

                continue;
            }

            $knownTokens = [];
            foreach ($replacementTokens as $replacementToken) {
                $knownTokens[$this->normalizeEvidenceToken($replacementToken)] = true;
            }

            foreach ($outgoingNames as $outgoingName) {
                foreach ($this->matchingEvidenceTokens($pattern, $outgoingName) as $outgoingToken) {
                    $normalizedToken = $this->normalizeEvidenceToken($outgoingToken);
                    if (isset($knownTokens[$normalizedToken])) {
                        continue;
                    }

                    $knownTokens[$normalizedToken] = true;
                    $preservedTokens[] = $outgoingToken;
                }
            }
        }

        if ($preservedTokens === []) {
            return $replacement;
        }

        $trimmedReplacement = rtrim($replacement);
        $maxEvidenceLength = self::MAX_PERSISTED_SEARCH_NAME_LENGTH - ($trimmedReplacement === '' ? 0 : 1);
        $boundedTokens = [];
        $evidenceLength = 0;

        foreach ($preservedTokens as $preservedToken) {
            $separatorLength = $boundedTokens === [] ? 0 : 1;
            $candidateLength = $evidenceLength + $separatorLength + mb_strlen($preservedToken);
            if ($candidateLength > $maxEvidenceLength) {
                continue;
            }

            $boundedTokens[] = $preservedToken;
            $evidenceLength = $candidateLength;
        }

        $evidenceSuffix = implode(' ', $boundedTokens);
        $separatorLength = $trimmedReplacement === '' || $evidenceSuffix === '' ? 0 : 1;
        $replacementLength = self::MAX_PERSISTED_SEARCH_NAME_LENGTH - $separatorLength - mb_strlen($evidenceSuffix);
        $boundedReplacement = rtrim(mb_substr($trimmedReplacement, 0, $replacementLength));

        return implode(' ', array_filter([$boundedReplacement, $evidenceSuffix], static fn (string $part): bool => $part !== ''));
    }

    /**
     * @return list<string>
     */
    private function matchingEvidenceTokens(string $pattern, string $name): array
    {
        if (preg_match_all($pattern, $name, $matches) < 1) {
            return [];
        }

        return $matches[0];
    }

    private function normalizeEvidenceToken(string $token): string
    {
        return strtolower((string) preg_replace('/[ ._-]+/', '', $token));
    }

    /**
     * Identify the short scene-group abbreviation names targeted by the one-time repair.
     */
    public function isAbbreviationStub(string $name): bool
    {
        $profile = $this->informationProfile($name);

        return $profile['plausible']
            && $profile['group_suffix']
            && ! $profile['year']
            && ! $profile['quality_source']
            && ! $profile['tv']
            && $profile['tokens'] <= 3;
    }

    /**
     * @return array{plausible: bool, group_suffix: bool, year: bool, quality_source: bool, tv: bool, tokens: int}
     */
    private function informationProfile(string $name): array
    {
        $normalized = $this->normalizeCandidateTitle($name);

        return [
            'plausible' => $this->isPlausibleReleaseTitle($normalized),
            ...$this->informationSignals($normalized),
        ];
    }

    /**
     * @return array{group_suffix: bool, year: bool, quality_source: bool, tv: bool, tokens: int}
     */
    private function informationSignals(string $name): array
    {
        return [
            'group_suffix' => (bool) preg_match('/[-.][A-Za-z0-9]{2,}$/', $name),
            'year' => (bool) preg_match(self::YEAR_SIGNAL, $name),
            'quality_source' => (bool) preg_match(self::QUALITY_SOURCE_SIGNAL, $name),
            'tv' => (bool) preg_match(self::TV_SIGNAL, $name),
            'tokens' => preg_match_all('/[A-Za-z0-9]{2,}/', $name),
        ];
    }

    /**
     * Check if a string looks like a hashed/obfuscated name.
     *
     * @param  string  $title  The title to check
     * @return bool True if the title appears to be hashed/obfuscated
     */
    public function looksLikeHashedName(string $title): bool
    {
        return $this->isHashedOrGibberish($title);
    }

    /**
     * Determine whether a video filename carries a usable Descriptive Title.
     */
    public function isDescriptiveTitle(string $filename): bool
    {
        $basename = $this->extractFilenameFromPath($filename);
        if (! preg_match(self::VIDEO_EXTENSIONS, $basename)) {
            return false;
        }

        $title = trim((string) pathinfo($basename, PATHINFO_FILENAME), " \t\n\r\0\x0B.-_");
        if (strlen($title) < 4 || preg_match('/^\d+$/', $title)) {
            return false;
        }

        if (preg_match('/^(?:video|movie|film|clip|sample|preview|trailer|output|untitled|new)(?:[\s._-]*\d+)?$/i', $title)) {
            return false;
        }

        if ($this->looksLikeStructuralJunk($title)
            || preg_match('/(?:^|[\s._-])(?:sample|proof|thumbs?)(?:$|[\s._-])/i', $title)
            || preg_match('/(?:^|[\s._-])(?:part|cd|vol|disc)[\s._-]*\d+$/i', $title)) {
            return false;
        }

        return ! $this->looksLikeHashedName($title);
    }

    /**
     * Determine whether the current release name permits a Descriptive Title rename.
     */
    public function currentNameLooksObfuscated(string $searchName, int $categoryId, ?string $matchedBy = null): bool
    {
        if (in_array($categoryId, [Category::OTHER_HASHED, Category::OTHER_MISC], true)) {
            return true;
        }

        if ($matchedBy !== null && preg_match('/^(?:hash|obfuscated|gibberish)_/', $matchedBy)) {
            return true;
        }

        return $this->looksLikeHashedName($searchName);
    }

    /**
     * Clean filename for title matching against PreDB.
     *
     * @param  string  $filename  The filename to clean
     * @return string The cleaned filename
     */
    public function cleanForTitleMatch(string $filename): string
    {
        $filename = $this->extractNzbSplitName($filename) ?? $filename;

        // Remove file extension
        $clean = preg_replace('/\.(mkv|avi|mp4|m4v|wmv|mpg|mpeg|mov|ts|m2ts|vob|divx|flv|nfo|sfv|nzb|srr|srs|rar|r\d{2,4}|zip|7z|par2?|vol\d+[\+\-]\d+|\d{3})$/i', '', $filename);

        // Remove part/volume indicators
        $clean = preg_replace('/[\.\-_]?(part|vol|cd|dvd|disc|disk)\d+$/i', '', $clean);

        // Remove sample/proof indicators
        $clean = preg_replace('/[\.\-_](sample|proof|subs?)$/i', '', $clean);

        return trim($clean, " \t\n\r\0\x0B.-_");
    }

    /**
     * Normalize quality indicators in filenames.
     */
    public function normalizeQualityIndicators(string $fileName): string
    {
        $qualityMap = [
            '/\.4k$/i' => '.2160p',
            '/\.fullhd$/i' => '.1080p',
            '/\.hd$/i' => '.720p',
            '/\.int$/i' => '.INTERNAL',
            '/\.internal$/i' => '.INTERNAL',
        ];

        foreach ($qualityMap as $pattern => $replacement) {
            $fileName = preg_replace($pattern, $replacement, $fileName);
        }

        return $fileName;
    }

    /**
     * Check if a filename looks like a scene release.
     *
     * @param  string  $filename  The filename to check
     * @return bool True if it appears to be a scene release
     */
    public function looksLikeSceneRelease(string $filename): bool
    {
        $filename = $this->extractNzbSplitName($filename) ?? $filename;

        $baseName = preg_replace('/\.[a-z0-9]{2,4}$/i', '', $filename);

        // Check for group suffix
        if (! preg_match('/\-[A-Za-z0-9]{2,15}$/', $baseName)) {
            return false;
        }

        // Check for word separation
        if (! preg_match('/[._-]/', $baseName)) {
            return false;
        }

        // Check for common scene tags
        $sceneTags = [
            '720p', '1080p', '2160p', '4k',
            'x264', 'x265', 'hevc', 'xvid', 'divx',
            'bluray', 'bdrip', 'dvdrip', 'hdtv', 'webrip', 'web-dl', 'webdl',
            'aac', 'ac3', 'dts', 'flac', 'mp3',
            'proper', 'repack', 'internal', 'retail',
            'pal', 'ntsc', 'multi', 'dual',
        ];

        $baseNameLower = strtolower($baseName);
        foreach ($sceneTags as $tag) {
            if (str_contains($baseNameLower, $tag)) {
                return true;
            }
        }

        // Check for TV episode patterns
        if (preg_match('/s\d{1,2}e\d{1,3}/i', $baseName)) {
            return true;
        }

        // Check for year pattern
        if (preg_match('/[._-](19|20)\d{2}[._-]/i', $baseName)) {
            return true;
        }

        return false;
    }

    public function extractNzbSplitName(string $value): ?string
    {
        return $this->nzbSplitUnwrapper->unwrap($value);
    }

    /**
     * Ignore junk inputs that should never drive PreDB matches.
     */
    protected function isIgnorableForMatching(string $fileName): bool
    {
        if (preg_match('/\.url$/i', $fileName)) {
            return true;
        }

        return $this->looksLikeStructuralJunk((string) pathinfo($fileName, PATHINFO_FILENAME));
    }

    /**
     * Detect low-information DVD structure names and numeric image files.
     */
    protected function looksLikeStructuralJunk(string $fileName): bool
    {
        $normalized = strtolower(trim($fileName, " \t\n\r\0\x0B.-_"));

        if ($normalized === '') {
            return true;
        }

        foreach (self::IGNORABLE_NAME_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return true;
            }
        }

        return false;
    }
}
