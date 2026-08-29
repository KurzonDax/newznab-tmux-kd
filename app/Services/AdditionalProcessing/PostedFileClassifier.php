<?php

declare(strict_types=1);

namespace App\Services\AdditionalProcessing;

final class PostedFileClassifier
{
    public const string VIDEO_FILE_REGEX = '\\.(AVI|F4V|IFO|M1V|M2TS|M2V|M4V|MKV|MOV|MP4|MPEG|MPG|MPGV|MPV|MTS|OGV|QT|RM|RMVB|TS|VOB|WMV)';

    private const string ARCHIVE_PATTERN = '/(\.(part\d+|[rz]\d+|rar|0+|0*10?|zipr\d{2,3}|zipx?)("|\s*\.rar)*($|[ ")]|-])|"[a-f0-9]{32}\.[1-9]\d{1,2}".*\(\d+\/\d{2,}\)$)/i';

    public static function containsArchiveCandidate(string $subject): bool
    {
        return preg_match(self::ARCHIVE_PATTERN, $subject) === 1;
    }

    /**
     * @param  array<int|string, string>|null  $matches
     */
    public static function matchesTerminalExtension(
        string $subject,
        string $extensionRegex,
        ?array &$matches = null,
    ): bool {
        return preg_match('/'.$extensionRegex.'$/i', self::postedFilename($subject), $matches) === 1;
    }

    public static function isExplicitVideoSample(string $subject, string $videoFileRegex): bool
    {
        return preg_match(
            '/(?:^|[._\-\s])sample'.$videoFileRegex.'$/i',
            self::postedFilename($subject),
        ) === 1;
    }

    private static function postedFilename(string $subject): string
    {
        if (preg_match_all('/"([^"]+)"/', $subject, $quotedFilenames) > 0) {
            return (string) end($quotedFilenames[1]);
        }

        $filename = preg_replace('/\s+yEnc.*$/i', '', $subject) ?? $subject;

        return trim($filename, " \t\n\r\0\x0B\"'");
    }
}
