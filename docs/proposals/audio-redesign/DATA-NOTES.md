# Audio section: data notes

Facts measured on production read-only on 2026-10-04 (every release of band 3000, its audio tags, track lists,
identifications, previews and spectrograms), unless a line says otherwise. They shaped the design in `SPEC.md`; the query
lab's measurements are in `DATA-CONTRACT.md` and `evidence/`.

## The band

- **3,719 releases**: Video 1,702, Lossless 676, MP3 529, Other 427, Foreign 385. Audiobook (3030) and Podcast (3050)
  hold none, so the Category menu lists five sub-categories today.
- **Misfiled releases.** About 1,650 of the Video and Foreign releases are anime or TV episodes by their names
  (`[Erai-raws]`, `[SubsPlease]`, `S21E04`, ` - 03 [`). 1,614 of them were added between 2026-08-11 and 2026-08-24, before
  the categorisation fixes of 2026-08-19 to 2026-09-09; 33 arrived in September, 23 after the last fix, all anime with an
  audio format word in the name ("[BD 1080p FLAC]", "X265 OPUS"). The maintainer's ruling: no issue, no recategorisation
  (`SPEC.md` 6.1).
- No audio release has a video clip (`releases.videostatus = 1`: none), so the music-video clip dialog
  (`SPEC.md` 5C.1) has nothing to show on production today.

## The old album match (`musicinfo`)

- 204 `musicinfo` rows on production (2026-10-04, the same 204 in the query lab's backup of 2026-09-20), every one with a cover;
  148 audio releases link to one (`musicinfo_id > 0`). Nothing has written the table since #372 retired the iTunes match
  (`INVENTORY.md` 7.1).
- The MusicBrainz proposal's manual audit (`docs/proposals/musicbrainz-audio-enrichment-plan.md`, 2026-08-23) found at
  least 23 of 57 checked releases attached to a different album: the old match cannot be shown as a release's album, and
  its covers cannot stand in for album art.

## The tags of the previewed file (`release_audio_tags`)

- **865 audio releases** have a tag row (874 on the table; 9 belong to releases filed elsewhere): Lossless 540, MP3 288,
  Other 20, Foreign 17; none in Video.
- Of the 865: an album 775, an artist (album artist or performer) 772, a year 761, a genre 680, a track title 754. 87
  rows carry only the preview columns (the file had no tags).
- **Every one of the 865 has a preview and a spectrogram.** Previews: FLAC 572, MP3 292, M4A 1; 30 seconds for 852, 10
  for 9, shorter for 4. Spectrograms are PNG pictures 384 px high and 1,306 to 1,338 px wide (22 files sampled).
- Formats (the tags' `audio_format`, as the format tag shows it): FLAC 485, MP3 288, WavPack 4, MPEG-4 1, none 87.
- **Years** by decade: 1950s 2, 1960s 39, 1970s 54, 1980s 57, 1990s 92, 2000s 103, 2010s 88, 2020s 326; nothing older
  than 1950.
- **Genres**: 135 distinct values as written, e.g. Pop 119, Rock 112, Metal 75, Classic Rock 18, Hard Rock 17, Blues 16,
  and raw tagger values such as "Classique", "Bandes originales de films", "Tipparade", "17th century; 18th century;
  Classical; Electronic". One tag holds several genres joined by ";"; one tag reads "Unknown".
- **Albums**: 746 distinct artist + album pairs among the 775 releases with an album; 29 albums have two releases (a
  FLAC and an MP3 posting, or a repost), none more.
- The newest 50 releases of the band (the list's first page) include 27 with tags.

## Track lists (`release_audio_evidence_tracks`)

- **1,035 audio releases** have a track list in their newest evidence revision, 10,063 tracks, up to 704 on one release
  (a discography). The source is the NZB's file names for most, else an archive listing, release files or the one
  sampled file.
- Titles are mostly file names; a track title tag is stored for the sampled track. Track lengths are stored for every
  track of 177 releases; four releases have tracks on more than one disc. File names often start with the track number
  ("104 - Catapult.flac"), and some pack disc and track into one number (101, 102, … 201).

## MusicBrainz (`release_music_identifications`)

- About 2,700 identification rows on production (the table statistics' estimate). In the band, 48 releases have an accepted one: 21 accepted release groups, 4
  accepted editions (both name a release group) and 23 accepted recordings (no release group). So **25 releases** get the
  MusicBrainz button. Five tag rows carry a MusicBrainz release group id written into the file; the button does not use
  them (an accepted identification is the identity; a file tag is evidence).

## Not stored anywhere

- An album cover that can be trusted (the reason the cover slots show No cover until the MusicBrainz integration stores
  art, `SPEC.md` 6.2).
- A record label for releases after #372 (the reason the Label filter goes).
