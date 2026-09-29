# Adult data contract: measured reads (2026-09-29)

Scratch schema `adc` on the query lab, built by `01-build.sql`: a copy of every release of the restored production
catalogue (2,359,525; the Adult band 16,007, of which 13,063 are visible under the default password setting), with the
#773 resolution / source, the generated `category_band`, the release names, the picture and clip flags, and exactly the
list indexes production has on master `34ff936cd` (`ix_releases_band_{posted,added,count}` and the #827
`_cat_` / `_res_` / `_src_` indexes). Audio languages are the #828 table shape, copied from the Movies contract's scratch
schema; clips from `release_video_clips`. The pristine `nntmux` schema is only read.

Each query ran three times warm in one session (best time kept, from `SHOW PROFILES`), then once after `FLUSH STATUS`
to read the `Handler_read*` counters ("rows read"). `bench.py` does both. Queries are written as `BandReleaseList`
builds them (`app/Services/Releases/BandReleaseList.php`): the index forced as `releaseIndex()` chooses it, the
password condition of the default setting (`passwordstatus <= 0`), no excluded categories, 50 a page, pages past the
middle read mirrored from the other end.

"Exclude Other" is the Category filter with every Adult sub-category but Other ticked
(6010, 6020, 6030, 6040, 6041, 6042, 6045, 6046, 6050, 6060, 6080, 6090): 6,955 visible releases, under half of the
band, so `releaseIndex()` chooses `ix_releases_band_cat_posted`.

The name search is today's condition (`ReleaseBrowserQuery.php:115-126`, `displayName()` `:133-136`):
`COALESCE(NULLIF(TRIM(display_name), ''), searchname) LIKE '%word%'`, measured here as
`coalesce(display_name, searchname)`; the words are neutral tokens chosen for how many names hold them.

Similar releases (today's query, unchanged) were timed separately on production's search index, read-only: 40 queries
built from random Adult release names, median 1 ms, slowest 2 ms (`SHOW META`, 2026-09-29).

Parser note: `bench.py` first read a GROUP BY query's own result rows as profile lines (a row such as `6999 4 2 1`
looks like one); fixed to read only profile lines. The Movies contract's multi-column queries, re-timed with the fixed
parser on its scratch schema (`q-movies-recheck.tsv`), match their published numbers (per-value counts 80 ms, Audio menu
234 / 227 ms, people search 15 / 176 ms, two-step 16 / 37 ms, wall counts 12 ms).

| Query | ms (best of 3, warm) | rows read | rows returned |
|---|---:|---:|---:|
| list, page 1, posted newest | 0.1 | 156 | 50 |
| list, middle page (worst; mirrored past it) | 1.9 | 10862 | 50 |
| list, last page (mirrored: oldest first, offset 0) | 0.1 | 51 | 50 |
| list, count, no filter | 1.6 | 16008 | 1 |
| list, Added newest, page 1 | 0.1 | 112 | 50 |
| list, Added newest, middle page | 1.7 | 10439 | 50 |
| Exclude Other, page 1 (cat index) | 1.1 | 7204 | 50 |
| Exclude Other, middle page (worst; mirrored past it) | 3.5 | 10654 | 50 |
| Exclude Other, last page (mirrored: oldest first) | 1.1 | 7204 | 50 |
| Exclude Other, count | 1.6 | 16008 | 1 |
| Category x264, page 1 | 0.1 | 50 | 50 |
| Category x264, count | 1.7 | 16008 | 1 |
| Resolution 1080p, page 1 (res index) | 0.1 | 50 | 50 |
| Resolution 1080p, count | 0.5 | 3786 | 1 |
| Exclude Other + 1080p + 95%+, page 1 | 0.1 | 62 | 50 |
| Exclude Other + 1080p + 95%+, count | 0.5 | 3786 | 1 |
| Completion 100% only, page 1 | 0.1 | 223 | 50 |
| Completion 100%, count | 1.8 | 16008 | 1 |
| Audio English, page 1 | 0.2 | 552 | 50 |
| Audio English, count | 5.7 | 29072 | 1 |
| Audio Unknown alone, page 1 (anti-join) | 0.1 | 252 | 50 |
| Audio Unknown alone, count | 5.6 | 29071 | 1 |
| Name search, a common word (1080p), page 1 | 0.6 | 581 | 50 |
| Name search, a common word (1080p), count | 12.2 | 16008 | 1 |
| Name search, a middling word (2016), page 1 | 3.9 | 3617 | 50 |
| Name search, a middling word (2016), count | 12.6 | 16008 | 1 |
| Name search, a word nothing contains, page 1 | 13.8 | 16008 | 0 |
| Name search, a word nothing contains, count | 12.6 | 16008 | 1 |
| Name search (2016) + Exclude Other, page 1 | 5.9 | 7204 | 50 |
| Name search (2016) + Exclude Other, count | 5.6 | 7154 | 1 |
| Name search (1080p), last page (mirrored) | 4.4 | 4562 | 50 |
| Row facts for a page: clip, preview, sample flags (50 ids by primary key) | 0.1 | 151 | 50 |
| Clip seconds for a page (50 ids) | 0.3 | 324 | 50 |
| Menus: the band's counts per category / resolution / source (cached 1 h) | 2.6 | 32192 | 88 |
| Menus: the Audio menu's languages (cached 1 h) | 7.1 | 34700 | 27 |

```sql
-- list, page 1, posted newest
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 6000 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50;
-- list, middle page (worst; mirrored past it)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 6000 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50 offset 8000;
-- list, last page (mirrored: oldest first, offset 0)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 6000 and r.passwordstatus <= 0 order by r.postdate asc, r.id asc limit 50;
-- list, count, no filter
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 6000 and r.passwordstatus <= 0;
-- list, Added newest, page 1
select r.id from releases r force index (ix_releases_band_added) where r.category_band = 6000 and r.passwordstatus <= 0 order by r.adddate desc, r.id desc limit 50;
-- list, Added newest, middle page
select r.id from releases r force index (ix_releases_band_added) where r.category_band = 6000 and r.passwordstatus <= 0 order by r.adddate desc, r.id desc limit 50 offset 8000;
-- Exclude Other, page 1 (cat index)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 6000 and r.passwordstatus <= 0 and r.categories_id in (6010,6020,6030,6040,6041,6042,6045,6046,6050,6060,6080,6090) order by r.postdate desc, r.id desc limit 50;
-- Exclude Other, middle page (worst; mirrored past it)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 6000 and r.passwordstatus <= 0 and r.categories_id in (6010,6020,6030,6040,6041,6042,6045,6046,6050,6060,6080,6090) order by r.postdate desc, r.id desc limit 50 offset 3450;
-- Exclude Other, last page (mirrored: oldest first)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 6000 and r.passwordstatus <= 0 and r.categories_id in (6010,6020,6030,6040,6041,6042,6045,6046,6050,6060,6080,6090) order by r.postdate asc, r.id asc limit 50;
-- Exclude Other, count
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 6000 and r.passwordstatus <= 0 and r.categories_id in (6010,6020,6030,6040,6041,6042,6045,6046,6050,6060,6080,6090);
-- Category x264, page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 6000 and r.passwordstatus <= 0 and r.categories_id in (6040) order by r.postdate desc, r.id desc limit 50;
-- Category x264, count
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 6000 and r.passwordstatus <= 0 and r.categories_id in (6040);
-- Resolution 1080p, page 1 (res index)
select r.id from releases r force index (ix_releases_band_res_posted) where r.category_band = 6000 and r.passwordstatus <= 0 and r.resolution in (2) order by r.postdate desc, r.id desc limit 50;
-- Resolution 1080p, count
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 6000 and r.passwordstatus <= 0 and r.resolution in (2);
-- Exclude Other + 1080p + 95%+, page 1
select r.id from releases r force index (ix_releases_band_res_posted) where r.category_band = 6000 and r.passwordstatus <= 0 and r.categories_id in (6010,6020,6030,6040,6041,6042,6045,6046,6050,6060,6080,6090) and r.resolution in (2) and r.completion >= 95 order by r.postdate desc, r.id desc limit 50;
-- Exclude Other + 1080p + 95%+, count
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 6000 and r.passwordstatus <= 0 and r.categories_id in (6010,6020,6030,6040,6041,6042,6045,6046,6050,6060,6080,6090) and r.resolution in (2) and r.completion >= 95;
-- Completion 100% only, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 6000 and r.passwordstatus <= 0 and r.completion >= 100 order by r.postdate desc, r.id desc limit 50;
-- Completion 100%, count
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 6000 and r.passwordstatus <= 0 and r.completion >= 100;
-- Audio English, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 6000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_languages al where al.releases_id = r.id and al.languages_id in (select id from languages where name='English')) order by r.postdate desc, r.id desc limit 50;
-- Audio English, count
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 6000 and r.passwordstatus <= 0 and exists (select 1 from release_audio_languages al where al.releases_id = r.id and al.languages_id in (select id from languages where name='English'));
-- Audio Unknown alone, page 1 (anti-join)
select r.id from releases r force index (ix_releases_band_posted) left join release_audio_languages a on a.releases_id = r.id where r.category_band = 6000 and r.passwordstatus <= 0 and a.releases_id is null order by r.postdate desc, r.id desc limit 50;
-- Audio Unknown alone, count
select count(*) from releases r force index (ix_releases_band_count) left join release_audio_languages a on a.releases_id = r.id where r.category_band = 6000 and r.passwordstatus <= 0 and a.releases_id is null;
-- Name search, a common word (1080p), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 6000 and r.passwordstatus <= 0 and coalesce(r.display_name, r.searchname) like '%1080p%' order by r.postdate desc, r.id desc limit 50;
-- Name search, a common word (1080p), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 6000 and r.passwordstatus <= 0 and coalesce(r.display_name, r.searchname) like '%1080p%';
-- Name search, a middling word (2016), page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 6000 and r.passwordstatus <= 0 and coalesce(r.display_name, r.searchname) like '%2016%' order by r.postdate desc, r.id desc limit 50;
-- Name search, a middling word (2016), count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 6000 and r.passwordstatus <= 0 and coalesce(r.display_name, r.searchname) like '%2016%';
-- Name search, a word nothing contains, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 6000 and r.passwordstatus <= 0 and coalesce(r.display_name, r.searchname) like '%zzqqxx%' order by r.postdate desc, r.id desc limit 50;
-- Name search, a word nothing contains, count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 6000 and r.passwordstatus <= 0 and coalesce(r.display_name, r.searchname) like '%zzqqxx%';
-- Name search (2016) + Exclude Other, page 1
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 6000 and r.passwordstatus <= 0 and r.categories_id in (6010,6020,6030,6040,6041,6042,6045,6046,6050,6060,6080,6090) and coalesce(r.display_name, r.searchname) like '%2016%' order by r.postdate desc, r.id desc limit 50;
-- Name search (2016) + Exclude Other, count
select count(*) from releases r force index (ix_releases_band_cat_posted) where r.category_band = 6000 and r.passwordstatus <= 0 and r.categories_id in (6010,6020,6030,6040,6041,6042,6045,6046,6050,6060,6080,6090) and coalesce(r.display_name, r.searchname) like '%2016%';
-- Name search (1080p), last page (mirrored)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 6000 and r.passwordstatus <= 0 and coalesce(r.display_name, r.searchname) like '%1080p%' order by r.postdate asc, r.id asc limit 50;
-- Row facts for a page: clip, preview, sample flags (50 ids by primary key)
select r.id, r.videostatus, r.haspreview, r.jpgstatus from releases r where r.id in (select id from (select r2.id from releases r2 force index (ix_releases_band_posted) where r2.category_band = 6000 order by r2.postdate desc limit 50) t);
-- Clip seconds for a page (50 ids)
select c.releases_id, c.duration_seconds from release_video_clips c where c.releases_id in (select id from (select r2.id from releases r2 force index (ix_releases_band_posted) where r2.category_band = 6000 and r2.videostatus = 1 order by r2.postdate desc limit 50) t);
-- Menus: the band's counts per category / resolution / source (cached 1 h)
select categories_id, resolution, source, count(*) from releases force index (ix_releases_band_count) where category_band = 6000 group by categories_id, resolution, source;
-- Menus: the Audio menu's languages (cached 1 h)
select a.languages_id, l.name from release_audio_languages a join releases r on r.id = a.releases_id join languages l on l.id = a.languages_id where r.category_band = 6000 group by a.languages_id, l.name;
```

## Added after the adversarial review (2026-09-29)

### The Audio case "a language and Unknown" (NOT EXISTS), the name search with today's exact condition, and the worst unmirrored page

| Query | ms (best of 3, warm) | rows read | rows returned |
|---|---:|---:|---:|
| Audio English + Unknown, page 1 | 0.3 | 333 | 50 |
| Audio English + Unknown, count | 19.5 | 40402 | 1 |
| Name search (today's exact condition), a common token, page 1 | 0.6 | 581 | 50 |
| Name search (today's exact condition), a common token, count | 16.7 | 16008 | 1 |
| Name search (today's exact condition), a middling token, page 1 | 3.5 | 3617 | 50 |
| Name search (today's exact condition), a middling token, count | 15.7 | 16008 | 1 |
| Name search (today's exact condition), a token no name contains, page 1 | 16.0 | 16008 | 0 |
| Name search (today's exact condition), a token no name contains, count | 15.4 | 16008 | 1 |
| list, worst page (offset 6,500: the last before mirroring) | 1.4 | 9357 | 50 |

```sql
-- Audio English + Unknown, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 6000 and r.passwordstatus <= 0 and (exists (select 1 from release_audio_languages al where al.releases_id = r.id and al.languages_id in (select id from languages where name='English')) or not exists (select 1 from release_audio_languages an where an.releases_id = r.id)) order by r.postdate desc, r.id desc limit 50;
-- Audio English + Unknown, count
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 6000 and r.passwordstatus <= 0 and (exists (select 1 from release_audio_languages al where al.releases_id = r.id and al.languages_id in (select id from languages where name='English')) or not exists (select 1 from release_audio_languages an where an.releases_id = r.id));
-- Name search (today's exact condition), a common token, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 6000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%1080p%' escape '!' order by r.postdate desc, r.id desc limit 50;
-- Name search (today's exact condition), a common token, count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 6000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%1080p%' escape '!';
-- Name search (today's exact condition), a middling token, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 6000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%2016%' escape '!' order by r.postdate desc, r.id desc limit 50;
-- Name search (today's exact condition), a middling token, count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 6000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%2016%' escape '!';
-- Name search (today's exact condition), a token no name contains, page 1
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 6000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!' order by r.postdate desc, r.id desc limit 50;
-- Name search (today's exact condition), a token no name contains, count
select count(*) from releases r force index (ix_releases_band_posted) where r.category_band = 6000 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!';
-- list, worst page (offset 6,500: the last before mirroring)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 6000 and r.passwordstatus <= 0 order by r.postdate desc, r.id desc limit 50 offset 6500;
```

### The worst name search on the untouched full-width `nntmux` rows (77 columns), through the admin index on `categories_id, postdate`

| Query | ms (best of 3, warm) | rows read | rows returned |
|---|---:|---:|---:|
| Name search on the full-width production rows (77 columns), no match, page 1 | 17.0 | 16008 | 0 |
| Name search on the full-width production rows, no match, count | 16.6 | 16008 | 1 |

```sql
-- Name search on the full-width production rows (77 columns), no match, page 1
select r.id from releases r force index (ix_releases_categories_postdate_admin) where r.categories_id between 6000 and 6999 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!' order by r.postdate desc, r.id desc limit 50;
-- Name search on the full-width production rows, no match, count
select count(*) from releases r force index (ix_releases_categories_postdate_admin) where r.categories_id between 6000 and 6999 and r.passwordstatus <= 0 and coalesce(nullif(trim(r.display_name), ''), r.searchname) like '%zzqqxx%' escape '!';
```

### "Exclude Other" on the Movies and TV lists (same scratch copy; every band is in it)

| Query | ms (best of 3, warm) | rows read | rows returned |
|---|---:|---:|---:|
| Movies Exclude Other, page 1 (cat index) | 8.0 | 55995 | 50 |
| Movies Exclude Other, page 20 (cat index) | 10.0 | 56945 | 50 |
| Movies Exclude Other, page 1 (band index) | 0.1 | 50 | 50 |
| Movies Exclude Other, count | 56.6 | 575110 | 1 |
| TV Exclude Other, page 1 (cat index) | 20.7 | 171778 | 50 |
| TV Exclude Other, page 20 (cat index) | 21.0 | 172728 | 50 |
| TV Exclude Other, page 1 (band index) | 0.1 | 50 | 50 |
| TV Exclude Other, count | 17.9 | 173153 | 1 |

```sql
-- Movies Exclude Other, page 1 (cat index)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 2000 and r.passwordstatus <= 0 and r.categories_id in (2010,2030,2040,2045,2050,2060,2070,2080,2090) order by r.postdate desc, r.id desc limit 50;
-- Movies Exclude Other, page 20 (cat index)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 2000 and r.passwordstatus <= 0 and r.categories_id in (2010,2030,2040,2045,2050,2060,2070,2080,2090) order by r.postdate desc, r.id desc limit 50 offset 950;
-- Movies Exclude Other, page 1 (band index)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 2000 and r.passwordstatus <= 0 and r.categories_id in (2010,2030,2040,2045,2050,2060,2070,2080,2090) order by r.postdate desc, r.id desc limit 50;
-- Movies Exclude Other, count
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 2000 and r.passwordstatus <= 0 and r.categories_id in (2010,2030,2040,2045,2050,2060,2070,2080,2090);
-- TV Exclude Other, page 1 (cat index)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 5000 and r.passwordstatus <= 0 and r.categories_id in (5010,5020,5030,5040,5045,5060,5070,5080,5090) order by r.postdate desc, r.id desc limit 50;
-- TV Exclude Other, page 20 (cat index)
select r.id from releases r force index (ix_releases_band_cat_posted) where r.category_band = 5000 and r.passwordstatus <= 0 and r.categories_id in (5010,5020,5030,5040,5045,5060,5070,5080,5090) order by r.postdate desc, r.id desc limit 50 offset 950;
-- TV Exclude Other, page 1 (band index)
select r.id from releases r force index (ix_releases_band_posted) where r.category_band = 5000 and r.passwordstatus <= 0 and r.categories_id in (5010,5020,5030,5040,5045,5060,5070,5080,5090) order by r.postdate desc, r.id desc limit 50;
-- TV Exclude Other, count
select count(*) from releases r force index (ix_releases_band_count) where r.category_band = 5000 and r.passwordstatus <= 0 and r.categories_id in (5010,5020,5030,5040,5045,5060,5070,5080,5090);
```

Similar releases: `similar-bench.py` (production's search index, read-only; SHOW META's time, the index's own part).
