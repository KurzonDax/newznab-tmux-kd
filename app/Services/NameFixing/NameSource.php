<?php

declare(strict_types=1);

namespace App\Services\NameFixing;

use InvalidArgumentException;

/**
 * Every source a release name can come from, one case per evidence kind. A case declares what
 * its rename stores and how far its evidence is trusted; nothing else decides those. A candidate
 * that carries a PreDB id is trusted whatever its case ({@see self::trustsPreDbId()}).
 */
enum NameSource: string
{
    case NfoExtract = 'nfo_extract';
    case NfoPreDbMatch = 'nfo_predb_match';
    case NfoPattern = 'nfo_pattern';
    case Par2File = 'par2_file';
    case Par2NzbSplitWrapper = 'par2_nzbsplit_wrapper';
    case Par2PreDbMatch = 'par2_predb_match';
    case FileName = 'file_name';
    case FileNzbSplitWrapper = 'file_nzbsplit_wrapper';
    case FileDescriptiveTitle = 'file_descriptive_title';
    case FilePreDbFilenameMatch = 'file_predb_filename_match';
    case FileRarInfoName = 'file_rarinfo_name';
    case FileRarInfoPreDb = 'file_rarinfo_predb';
    case FileOriginalSubject = 'file_original_subject';
    case FileOriginalSubjectPreDb = 'file_original_subject_predb';
    case FileReleaseInform = 'file_release_inform';
    case XxxFile = 'xxx_file';
    case MediaMovieName = 'media_movie_name';
    case MediaDescriptiveTitle = 'media_descriptive_title';
    case SrrFile = 'srr_file';
    case Crc32Donor = 'crc32_donor';
    case Par2HashDonor = 'par2_hash_donor';
    case UidElection = 'uid_election';
    case SrrdbArchiveCrc = 'srrdb_archive_crc';
    case SrrdbAttach = 'srrdb_attach';
    case PreDbFullText = 'predb_full_text';
    case PreDbFileMatch = 'predb_file_match';
    case PreDbExactAttach = 'predb_exact_attach';
    case SizeMismatch = 'size_mismatch';
    case RecoveryInventory = 'recovery_inventory';
    case AudioTags = 'audio_tags';
    case MusicBrainz = 'musicbrainz';
    case BookTitle = 'book_title';

    /** The source label the CLI prints before the method, and the stored name source's origin. */
    public function type(): string
    {
        return match ($this) {
            self::NfoExtract, self::NfoPreDbMatch, self::NfoPattern => 'NFO, ',
            self::Par2File, self::Par2NzbSplitWrapper, self::Par2PreDbMatch => 'PAR2, ',
            self::FileName, self::FileNzbSplitWrapper, self::FileDescriptiveTitle, self::FilePreDbFilenameMatch,
            self::FileRarInfoName, self::FileRarInfoPreDb, self::FileOriginalSubject, self::FileOriginalSubjectPreDb,
            self::FileReleaseInform => 'Filenames, ',
            self::XxxFile => 'XXX filenames, ',
            self::MediaMovieName, self::MediaDescriptiveTitle => 'Mediainfo, ',
            self::SrrFile => 'SRR, ',
            self::Crc32Donor => 'CRC32, ',
            self::Par2HashDonor => 'PAR2 hash, ',
            self::UidElection => 'UID, ',
            self::SrrdbArchiveCrc, self::SrrdbAttach => 'SRRDB, ',
            self::PreDbFullText => 'PreDB FT Exact, ',
            self::PreDbFileMatch => 'PreDB file match, ',
            self::PreDbExactAttach => 'PreDB exact, ',
            self::SizeMismatch => '',
            self::RecoveryInventory => 'Recovery inventory, ',
            self::AudioTags => 'Audio tags, ',
            self::MusicBrainz => 'MusicBrainz, ',
            self::BookTitle => 'Book title, ',
        };
    }

    /** The `releases.name_source` an accepted rename stores. */
    public function storedName(): ?string
    {
        return match ($this) {
            self::NfoExtract, self::NfoPreDbMatch, self::NfoPattern => 'NFO',
            self::Par2File, self::Par2NzbSplitWrapper, self::Par2PreDbMatch => 'PAR2',
            self::FileName, self::FileNzbSplitWrapper, self::FileDescriptiveTitle, self::FilePreDbFilenameMatch,
            self::FileRarInfoName, self::FileRarInfoPreDb, self::FileOriginalSubject, self::FileOriginalSubjectPreDb,
            self::FileReleaseInform => 'Filenames',
            self::XxxFile => 'XXX filenames',
            self::MediaMovieName, self::MediaDescriptiveTitle => 'Mediainfo',
            self::SrrFile => 'SRR',
            self::Crc32Donor => 'CRC32',
            self::Par2HashDonor => 'PAR2 hash',
            self::UidElection => 'UID',
            self::SrrdbArchiveCrc, self::SrrdbAttach => 'SRRDB',
            self::PreDbFullText => 'PreDB FT Exact',
            self::PreDbFileMatch => 'PreDB file match',
            self::PreDbExactAttach => 'PreDB exact',
            self::SizeMismatch => null,
            self::RecoveryInventory => 'Recovery inventory',
            self::AudioTags => 'Audio tags',
            self::MusicBrainz => 'MusicBrainz',
            self::BookTitle => 'Book title',
        };
    }

    /**
     * The status columns an accepted rename with status updates writes.
     *
     * @return array<string, int>
     */
    public function statusColumns(): array
    {
        $renamed = ['isrenamed' => 1, 'iscategorized' => 1];

        return match ($this) {
            self::NfoExtract, self::NfoPreDbMatch, self::NfoPattern => [...$renamed, 'proc_nfo' => 1],
            self::Par2File, self::Par2NzbSplitWrapper, self::Par2PreDbMatch, self::RecoveryInventory => [...$renamed, 'proc_par2' => 1],
            self::FileName, self::FileNzbSplitWrapper, self::FileDescriptiveTitle, self::FilePreDbFilenameMatch,
            self::FileRarInfoName, self::FileRarInfoPreDb, self::FileOriginalSubject, self::FileOriginalSubjectPreDb,
            self::FileReleaseInform => [...$renamed, 'proc_files' => 1],
            self::XxxFile => [...$renamed, 'proc_xxx' => 1],
            self::MediaMovieName, self::MediaDescriptiveTitle => [...$renamed, 'proc_media_movie' => 1],
            self::SrrFile => [...$renamed, 'proc_srr' => 1],
            self::Crc32Donor => [...$renamed, 'proc_crc32' => 1],
            self::Par2HashDonor => [...$renamed, 'proc_hash16k' => 1],
            self::UidElection => [...$renamed, 'proc_uid' => 1],
            self::SrrdbArchiveCrc, self::SrrdbAttach => [...$renamed, 'proc_srrdb' => 1],
            self::AudioTags, self::MusicBrainz => [...$renamed, 'proc_pp' => 1],
            self::PreDbFullText, self::PreDbExactAttach, self::BookTitle => $renamed,
            self::PreDbFileMatch, self::SizeMismatch => [],
        };
    }

    /** The processing column a source's sweep marks when it finds no name. */
    public function missColumn(): ?string
    {
        return match ($this) {
            self::NfoExtract, self::NfoPreDbMatch, self::NfoPattern => 'proc_nfo',
            self::Par2File, self::Par2NzbSplitWrapper, self::Par2PreDbMatch => 'proc_par2',
            self::FileName, self::FileNzbSplitWrapper, self::FileDescriptiveTitle, self::FilePreDbFilenameMatch,
            self::FileRarInfoName, self::FileRarInfoPreDb, self::FileOriginalSubject, self::FileOriginalSubjectPreDb,
            self::FileReleaseInform => 'proc_files',
            self::XxxFile => 'proc_xxx',
            self::MediaMovieName, self::MediaDescriptiveTitle => 'proc_media_movie',
            self::SrrFile => 'proc_srr',
            self::Crc32Donor => 'proc_crc32',
            self::Par2HashDonor => 'proc_hash16k',
            self::UidElection => 'proc_uid',
            self::SrrdbArchiveCrc, self::SrrdbAttach, self::PreDbFullText, self::PreDbFileMatch, self::PreDbExactAttach,
            self::SizeMismatch, self::RecoveryInventory, self::AudioTags, self::MusicBrainz, self::BookTitle => null,
        };
    }

    /** Whether the evidence is strong enough to accept a title that does not look like a release name. */
    public function skipsPlausibility(): bool
    {
        return match ($this) {
            self::NfoPreDbMatch, self::Par2PreDbMatch, self::FilePreDbFilenameMatch, self::FileRarInfoPreDb,
            self::FileOriginalSubjectPreDb, self::SrrFile, self::Crc32Donor, self::Par2HashDonor, self::UidElection,
            self::SrrdbArchiveCrc, self::SrrdbAttach, self::PreDbFullText, self::PreDbFileMatch, self::PreDbExactAttach => true,
            default => false,
        };
    }

    /** Whether an accepted name makes the release a trusted donor, for a candidate without a PreDB id. */
    public function trustedDonor(): TrustedDonor
    {
        return match ($this) {
            self::NfoExtract, self::NfoPreDbMatch, self::NfoPattern, self::Par2File, self::Par2NzbSplitWrapper,
            self::Par2PreDbMatch, self::FileNzbSplitWrapper, self::FilePreDbFilenameMatch, self::FileRarInfoName,
            self::FileRarInfoPreDb, self::FileOriginalSubjectPreDb, self::SrrFile, self::Crc32Donor, self::Par2HashDonor,
            self::UidElection, self::SrrdbArchiveCrc, self::SrrdbAttach, self::PreDbFullText, self::PreDbFileMatch,
            self::PreDbExactAttach, self::AudioTags, self::MusicBrainz => TrustedDonor::Trusted,
            self::FileName, self::FileDescriptiveTitle, self::FileOriginalSubject, self::FileReleaseInform, self::XxxFile,
            self::MediaMovieName, self::MediaDescriptiveTitle, self::SizeMismatch, self::RecoveryInventory,
            self::BookTitle => TrustedDonor::Untrusted,
        };
    }

    /** Whether a PreDB id on the candidate trusts it; a descriptive container title stays untrusted. */
    public function trustsPreDbId(): bool
    {
        return $this !== self::MediaDescriptiveTitle;
    }

    /**
     * The checks a candidate from this source must pass. Tag, MusicBrainz, book-title, recovery
     * inventory and attached PreDB/SRRDB names are written as their writers built them.
     *
     * @return list<NameGuard>
     */
    public function guards(): array
    {
        return match ($this) {
            self::SrrdbAttach, self::PreDbExactAttach, self::RecoveryInventory, self::AudioTags, self::MusicBrainz,
            self::BookTitle => [],
            default => NameGuard::all(),
        };
    }

    public function isDescriptiveMediaTitle(): bool
    {
        return $this === self::MediaDescriptiveTitle;
    }

    /** A descriptive video file name, from stored files or an archive listing. */
    public function isDescriptiveFileTitle(): bool
    {
        return $this === self::FileDescriptiveTitle;
    }

    /**
     * The status columns of the sources a rename type label belongs to.
     *
     * @return array<string, int>
     */
    public static function statusColumnsForType(string $type): array
    {
        return self::firstOfType($type)?->statusColumns() ?? [];
    }

    /** The processing column of the sources a rename type label belongs to. */
    public static function missColumnForType(string $type): ?string
    {
        return self::firstOfType($type)?->missColumn();
    }

    /**
     * The source of a rename passed as the legacy (type, method) labels.
     *
     * @throws InvalidArgumentException for a pair no call site passes
     */
    public static function fromLabels(string $type, string $method): self
    {
        return match ($type) {
            'NFO, ' => match (true) {
                $method === 'preDB: Match' => self::NfoPreDbMatch,
                str_starts_with($method, 'nfoCheck: ') => self::NfoExtract,
                default => self::NfoPattern,
            },
            'PAR2, ' => match ($method) {
                'preDB: Match' => self::Par2PreDbMatch,
                'fileCheck: NZBSPLIT wrapper' => self::Par2NzbSplitWrapper,
                default => self::Par2File,
            },
            'Filenames, ' => match ($method) {
                'PreDb: Filename match', 'preDB: Match' => self::FilePreDbFilenameMatch,
                'fileCheck: NZBSPLIT wrapper', 'NZBSPLIT wrapper' => self::FileNzbSplitWrapper,
                'fileCheck: Descriptive title', 'RarInfo: Descriptive title' => self::FileDescriptiveTitle,
                'RarInfo FileName Match' => self::FileRarInfoName,
                'RarInfo PreDB Match' => self::FileRarInfoPreDb,
                'Original subject repair' => self::FileOriginalSubject,
                'preDB: Restored original subject' => self::FileOriginalSubjectPreDb,
                'Release Inform API' => self::FileReleaseInform,
                default => self::FileName,
            },
            'XXX filenames, ' => self::XxxFile,
            'Mediainfo, ' => $method === ReleaseUpdateService::DESCRIPTIVE_MEDIA_TITLE_METHOD ? self::MediaDescriptiveTitle : self::MediaMovieName,
            'SRR, ' => self::SrrFile,
            'CRC32, ' => self::Crc32Donor,
            'PAR2 hash, ' => self::Par2HashDonor,
            'UID, ' => self::UidElection,
            'SRRDB, ' => $method === 'Verified archive CRC match' ? self::SrrdbAttach : self::SrrdbArchiveCrc,
            'PreDB FT Exact, ' => self::PreDbFullText,
            'PreDB file match, ' => self::PreDbFileMatch,
            'PreDB exact, ' => self::PreDbExactAttach,
            '' => self::SizeMismatch,
            'Recovery inventory, ' => self::RecoveryInventory,
            'Audio tags, ' => self::AudioTags,
            'MusicBrainz, ' => self::MusicBrainz,
            'Book title, ' => self::BookTitle,
            default => throw new InvalidArgumentException("Unregistered name source type [{$type}]."),
        };
    }

    private static function firstOfType(string $type): ?self
    {
        foreach (self::cases() as $source) {
            if ($source->type() === $type) {
                return $source;
            }
        }

        return null;
    }
}
