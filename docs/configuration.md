# Configuration

NNTmux is configured in two places:

- **The environment file** (`.env`, copied from `.env.example`) holds deployment settings:
  hosts, credentials, API keys and limits that rarely change. The application reads it only
  through the files in `config/`. After editing `.env` on an install that caches its
  configuration, run `php artisan config:cache` again.
- **The admin settings hub** holds runtime settings stored in the `settings` table. Settings
  are read from the database when they are used, so most changes reach the tmux panes on
  their next cycle without a restart; the few read only when the engine starts say so.

This page describes the settings hub section by section, then the site-level configuration
files that have no other home. The indexing pipeline and its own configuration files are
described in [the indexing pipeline](architecture/indexing-pipeline.md); the external
metadata services in [the provider gateway](architecture/provider-gateway.md).

## The admin settings hub

The hub is at Admin → Settings (`/admin/settings`, Admin role and two-factor authentication
required). It opens on the first section; each section is its own page at
`/admin/settings/<section>`, and the sidebar search finds any setting by its label or help.

Each page is declared by one class in `app/Support/Settings/Sections/`, listed in sidebar order
in `App\Support\Settings\SettingsRegistry::SECTION_PROVIDERS`. The registry is the whitelist of
editable keys. A page is made of cards, and each card saves on its own: a write naming an
unknown key or a key from another card is rejected as a whole, and out-of-range values are
rejected rather than clamped. A few per-root-category toggles (`generate_previews`,
`dynamic_preview_budget`, `generate_clips`, `discard_executables`) write `root_categories`
rather than the settings table.

The defaults below are the values a fresh install seeds
(`database/seeders/SettingsTableSeeder.php`). Where the code falls back to a different value
for a missing, blank or zero value, the table says so.

| Page | Slug | Class |
| --- | --- | --- |
| Website | `website` | `WebsiteSection` |
| Engine & Monitoring | `engine` | `EngineSection` |
| Usenet Ingest | `usenet-ingest` | `UsenetIngestSection` |
| Release Formation | `release-formation` | `ReleaseFormationSection` |
| Post-Processing | `post-processing` | `PostProcessingSection` |
| Metadata Lookups | `metadata-lookups` | `MetadataLookupsSection` |
| Naming & Hygiene | `naming-hygiene` | `NamingHygieneSection` |
| Decision Model | `decision-model` | `DecisionModelSection` |

### Website

Branding, what browse and the feeds may show, and how logins behave. Nothing here changes what
is indexed.

| Setting | Default | Effect |
| --- | --- | --- |
| **Branding & layout** | | |
| `strapline` | `A great usenet indexer` | Shown beside the site name in the header of every public page, and in the API capabilities. |
| `metatitle` | `An indexer` | Appended to every page's title tag. |
| `metadescription` | `A usenet indexing website` | Appended to every page's meta description. |
| `metakeywords` | `usenet,nzbs,cms,community` | Appended to every page's meta keywords. |
| `footer` | `Usenet binary indexer.` | Footer text of every public page. |
| `home_link` | `/` | Where the logo and home links go, as a path relative to the site root. |
| `dereferrer_link` | blank | Prefix put in front of outbound links so the destination does not see the referring page. Blank links out directly. |
| `tandc` | seeded text | Body of the terms page; HTML allowed. |
| **Browse & display** | | |
| `showpasswordedrelease` | 0 (hide) | Whether releases flagged as passworded appear in browse, search, the API and the feeds. Hiding does not delete. |
| `grabstatus` | 1 (on) | Records a grab against the release and the user on every NZB download. Off freezes the download counts. |
| **Sessions & access** | | |
| `single_active_session` | 0 (off) | When on, a new login ends the account's other logins, from the next login onward. |

The Sessions & access card also carries the breach-response panel, which posts to its own
action beside the card form. Who may register is managed on Admin → Registrations.

### Engine & Monitoring

The master switch for the tmux engine, the safety valves and the extra windows.

| Setting | Default | Effect |
| --- | --- | --- |
| **Session** | | |
| `running` | 0 (stopped) | The master switch. Stopping lets every pane finish its current script and leaves them idle; work in flight is not killed. The recovery panes do nothing while it is 0. |
| `tmux_session` | `nntmux` | The tmux session name, no spaces. Stop the engine before changing it, or the running session is orphaned. |
| `niceness` | 19 | `nice` priority for every pane command, -20 to 19. Blank falls back to 19. |
| `monitor_delay` | 30 | Seconds between the monitor's statistics refreshes (the counting queries), and how long `tmux:stop` waits for panes to finish. The monitor loop's own tick is `TMUX_MONITOR_DELAY` (default 10 s, `config/tmux.php`). |
| **Safety valves** | | |
| `collections_kill` | 0 (off) | When the `collections` table holds more rows than this, the monitor stops starting the Backfill pane. |
| `postprocess_kill` | 0 (off) | When pending post-processing work is larger than this, the monitor stops starting the Binaries and Backfill panes. |
| **Layout mode** | | |
| `sequential` | 0 (Full) | 0 opens the full layout with panes in parallel; 1 (Basic) opens only Monitor and Releases in window 0. Any other value runs as Full. |
| **IRC scraper** | | |
| `run_ircscraper` | 0 (off) | Runs `irc:scrape` in window 3. It needs the IRC settings described under [IRC scraper](#irc-scraper). |
| **Monitoring windows** | | |
| `console`, `htop`, `mytop`, `nmon`, `vnstat`, `tcptrack`, `bwmng`, `redis` | 0 (off) | Each opens its own window from window 5 on. The list is read when the engine starts; a tool that is not installed is skipped. |
| `vnstat_args`, `tcptrack_args`, `redis_args` | blank, `-i eth0 port 443`, blank | Passed to the tool verbatim. A blank `redis_args` shows the default stats and memory view. |

The admin help text for the two safety valves says `collections_kill` pauses header and
backfill work and `postprocess_kill` pauses post-processing; the table above gives what the code
does (`app/Services/Tmux/TmuxMonitorService.php`, `app/Services/Tmux/TmuxTaskRunner.php`).
The mismatch is tracked in #1161.

### Usenet Ingest

Header collection, new-group reach, missed-article repair and backfill. Provider hosts, ports
and credentials live in the environment file; see [NNTP providers](architecture/nntp-providers.md).

| Setting | Default | Effect |
| --- | --- | --- |
| **News server connection** | | |
| `nntpretries` | 10 | Connection attempts against a provider, counting the first; below 1, blank or missing means one attempt. The back-off is described in [NNTP providers](architecture/nntp-providers.md#connecting). |
| **Header download** (window 0, Binaries pane) | | |
| `binaries` | 0 (disabled) | Whether the Binaries pane runs. |
| `binarythreads` | 1 | Groups scanned at once, each with its own NNTP connection (1–99). |
| `bins_timer` | 30 | Seconds the pane sleeps after a pass. |
| `maxmssgs` | 20000 | Headers asked for in one request. 0 or blank falls back to 20000. |
| `max_headers_iteration` | 1000000 | Widest article range one pass closes for one group; a group further behind catches up over several passes. |
| `secondary_header_start_hours` | 36 | The first time a secondary provider scans a group, it starts this many hours back. Keep it at least the release delay plus 2 hours. Existing positions are not moved. |
| **New groups** | | |
| `newgroupscanmethod` | 0 (Post count) | Whether a newly activated group starts a number of days back (1) or a number of posts back (0). |
| `newgroupdaystoscan` | 1 | Days back, used with Days back. |
| `newgroupmsgstoscan` | 100000 | Posts back, used with Post count. |
| **Missed-article repair** | | |
| `partrepair` | 1 (on) | Whether each header pass first retries articles recorded in `missed_parts`. |
| `safepartrepair` | 0 (off) | Whether backfill and the safe binaries scripts also record their misses for retry. |
| `maxpartrepair` | 15000 | Missed articles one repair pass asks for. |
| `partrepairmaxtries` | 3 | Retries of one missed article before it is given up and deleted. 0 or blank falls back to 1. |
| **Backfill** (window 0, Backfill pane) | | |
| `backfill` | 0 (disabled) | Whether the Backfill pane runs. |
| `backfill_order` | 2 (Oldest first) | Group order: 1 newest first, 2 oldest first, 3 alphabetical, 4 reverse alphabetical, 5 most posts first, 6 fewest posts first. |
| `backfill_days` | 1 (Days per group) | 1 stops each group at its own backfill target; 2 takes every group back to `safebackfilldate`. |
| `safebackfilldate` | `2012-06-24` | Shared stop date, `YYYY-MM-DD`, parsed strictly; a value that does not parse fails the pass. |
| `disablebackfillgroup` | 0 (off) | Turns a group's backfill flag off when it reaches its stop point. |
| `backfill_qty` | 100000 | Headers one pass takes a single group back. |
| `backfillthreads` | 1 | Groups backfilled at once (1–99), sharing the provider allowance with header collection. |
| `back_timer` | 30 | Seconds the pane sleeps after a pass. |
| `progressive` | 0 (off) | When on, the sleep grows to one second per 500 collections when that exceeds `back_timer`. |

### Release Formation

Turning collections into releases; see
[the indexing pipeline](architecture/indexing-pipeline.md#3-stage-two-collections--releases).
Everything in Formation gates and Retention & cleanup deletes.

| Setting | Default | Effect |
| --- | --- | --- |
| **Releases pane** (window 0) | | |
| `releases` | 0 (disabled) | Whether the Releases pane runs. |
| `releasethreads` | 1 | Groups formed at once (1–99); database-bound. |
| `rel_timer` | 30 | Seconds the pane sleeps after a cycle. |
| **Reconciliation** | | |
| `reconciliation_hourly_mib` | 256 | Collection-reconciliation evidence downloads allowed per UTC hour, shared by all workers. |
| `reconciliation_daily_mib` | 2048 | The same per UTC day. |
| **Obfuscated recovery** | | |
| `obfuscation_recovery_enabled` | 0 (off) | Allows obfuscated-recovery capture and work. |
| `obfuscation_recovery_threads` | 2 | Recovery download slots (1–60), each with at most one provider connection. |
| `obfuscation_recovery_media_candidate_mib` | 20 | Lifetime download ceiling per Media candidate; the code never allows more than 20. |
| `obfuscation_recovery_rar_candidate_mib` | 40 | Lifetime download ceiling per RAR candidate; the code never allows more than 40. |
| **Formation gates** | | |
| `minfilestoformrelease` | 1 | Collections with fewer files are deleted. The group's own minimum also applies and the larger wins; 0 leaves only the group values. |
| `minsizetoformrelease` | 0 (off) | Collections smaller than this are deleted; the stricter of site and group wins, and categories add their own minimum. |
| `maxsizetoformrelease` | 0 (off) | Collections larger than this are deleted. |
| `completionpercent` | 95 | Releases holding a smaller share of their articles are deleted once they have waited `incomplete_release_grace_hours`. 0 turns this off. |
| `incomplete_release_grace_hours` | 72 | How long a release must be indexed before `completionpercent` may delete it. 0 falls back to 72. A release is still kept while a late collection may merge into it or a secondary provider may add headers. |
| `delaytime` | 2 | Hours of the group's posting timeline ingested past a collection's last part before it may form with the files it holds. Paused ingestion pauses the wait. |
| `collection_timeout` | 48 | The same measure, after which a stuck collection is deleted. |
| `crossposttime` | 2 | Hours within which two releases with the same name from the same poster count as one posting, and one is deleted. 0 turns this off. |
| **NZB storage** | | |
| `maxnzbsprocessed` | 1000 | NZB files written per batch before looping. 0 or blank falls back to 1000. |
| `nzbsplitlevel` | 4 | Sub-directory depth, from the leading GUID characters, for new NZB files. 0 stores them flat; blank falls back to 4. Existing files stay reachable after a change. |
| **Retention & cleanup** | | |
| `obfuscation_recovery_retention_hours` | 144 | Captured recovery headers are kept this long from first capture. |
| `partretentionhours` | 72 | How long leftover collections, binaries and parts are kept after leaving formation. 0 or blank falls back to 72. |
| `releaseretentiondays` | 0 (keep) | Releases older than this are deleted with their NZB and images. |
| `miscotherretentionhours` | 0 (keep) | How long releases in Other → Misc are kept. |
| `mischashedretentionhours` | 0 (keep) | How long releases in Other → Hashed are kept. |

Collection reconciliation and obfuscated recovery are described in
[the indexing pipeline](architecture/indexing-pipeline.md#collection-reconciliation) and
[its recovery section](architecture/indexing-pipeline.md#obfuscated-recovery).

### Post-Processing

Window 2, Additional pane: archive inspection, password detection, NFO retrieval, previews and
clips, and audio previews. Its cards are PAR2 sidecars (`par2_sidecar_absorb`, on: combine a
matching repair release with its payload, see
[PAR2 sidecar releases](architecture/indexing-pipeline.md#par2-sidecar-releases)),
Recovered-release inspection, Additional pane, Eligibility, Batching & timeouts, Archive &
password inspection, NFO retrieval, Previews & samples, and Audio previews. Each setting's help
text in the hub describes its effect.

### Metadata Lookups

Window 2, video and shelf panes: which external catalogues are asked and how many releases
each pass takes. Its cards are Video metadata panes, Books, music & games pane, Movies, TV,
Anime, Music identity, Books, Games & console, and Service etiquette (`amazonsleep`, the pause
between requests). Request spacing, retries and breakers for these services are set in
`config/provider-gateway.php`; see [the provider gateway](architecture/provider-gateway.md).

### Naming & Hygiene

Window 1: the Fix names and Remove crap panes, executable discard and two categorization
choices.

| Setting | Default | Effect |
| --- | --- | --- |
| **Fix release names** (window 1, pane 0) | | |
| `fix_names` | 0 (off) | Whether the naming pane runs. Recovered releases are only renamed while it is on. |
| `fixnamethreads` | 1 | Parallel naming workers (1–16). |
| `fixnamesperrun` | 10 | Releases one naming pass checks. |
| `fix_timer` | 30 | Seconds the pane sleeps after its chain. |
| `fix_names_timeout` | 1200 | Wall-clock limit, at least 60 s, for each step of the chain (each `releases:fix-names` level and each sweep). A step that reaches it is killed and the chain moves on. Needs coreutils `timeout` (or `gtimeout`) on the host; without it the pane says the limit is not enforced. |
| `lookuppar2` | 1 (on) | Use validated PAR2 file names, including cached recovered inventories, to name releases. Unset or blank counts as on. |
| `descriptive_title_rename` | 1 (on) | Use a human-written inner video file name as the release name, only when the current name looks obfuscated or hashed. |
| **Remove crap** (window 1, pane 1) | | |
| `fix_crap_opt` | `Disabled` | `All` runs `releases:remove-crap --time=2 --delete` over every class each cycle. `Custom` runs only the classes in `fix_crap`, over the whole index on its first cycle and a 4-hour window afterwards. Every deletion is permanent. |
| `fix_crap` | `0` (none) | The classes Custom runs: `blacklist`, `blfiles`, `codec`, `executable`, `gibberish`, `hashed`, `installbin`, `nzb`, `par2only`, `passworded`, `passwordurl`, `sample`, `scr`, `short`, `size`, `wmv_all`, the types `ReleaseRemoverService` accepts. |
| `crap_timer` | 30 | Seconds the pane sleeps after a sweep. |
| **Executable discard** | | |
| `discard_executable_extensions` | `dll\|exe\|msi\|scr\|com\|bat\|cmd\|pif` | Extensions treated as executable payloads. |
| `discard_executables` | per root | Root categories where a release containing one of those extensions is deleted with its NZB, images and search entry. Stored in `root_categories`. |
| `forced_root_pc_escape` | 0 (off) | In forced-root groups, keep the PC category for releases that categorize as PC software or games with at least 0.85 confidence. |
| **Categorization** | | |
| `categorizeforeign` | 1 (on) | Send non-English movies and TV to the Foreign categories. |
| `catwebdl` | 0 (off) | Send WEB-DL releases to the WEB-DL category instead of HD TV. |

### Decision Model

What the optional self-hosted decision model is asked, and how far its answers reach. The
service itself is configured with the `DECISION_MODEL_*` environment keys; see
[the decision model](architecture/decision-model.md). Nothing is sent while
`DECISION_MODEL_ENABLED` is false or `DECISION_MODEL_BASE_URL` is empty, whatever these
settings say.

| Setting | Default | Effect |
| --- | --- | --- |
| **Category arbitration** | | |
| `decision_model_mode` | `off` | `shadow` asks the model about releases the categorizers were unsure of and logs its answer; `apply` also uses a confident answer. Any unknown value is off. Running processes pick up a change within a minute. |
| `decision_model_apply_threshold` | 0.80 | Model confidence (0 to 1) at or above which its answer is used in apply mode. An invalid value falls back to 0.80. |
| `decision_model_arbitrate_below` | 0.70 | Categorizer confidence (0 to 1) below which a release is sent to the model. An invalid value falls back to 0.70. |
| **Music album check** | | |
| `decision_model_music_album_mode` | `off` | `shadow` asks the model, once per music pass for up to 50 releases, which of the matcher's candidate albums a release is, and logs the answer without changing anything. |

## Site-level configuration files

### Disposable email

`config/disposable-email.php` configures the `propaganistas/laravel-disposable-email` package,
which `App\Rules\ValidEmailDomain` uses to refuse disposable addresses at registration and on
profile email changes. That rule also checks a built-in domain list, suspicious words in the
domain and the domain's MX or A records. The file has no environment keys:

- `source`: the blocklist URL, the disposable-email-domains project's blocklist file on
  GitHub.
- `storage`: the local copy, `storage/framework/disposable_domains.json`.
- `cache`: enabled, default store, key `disposable_email.domains`.

The scheduler runs `php artisan disposable:update` weekly to fetch the list again
(`routes/console.php`).

### IRC scraper

`config/irc_settings.php` configures `irc:scrape` (`--debug` prints the socket traffic), which
the tmux IRC scraper pane runs when `run_ircscraper` is on. It joins its channels and writes
what they announce into the PreDB.

| Key | Environment | Code default | Meaning |
| --- | --- | --- | --- |
| `scrape_irc_server` | `SCRAPE_IRC_SERVER` | `irc.synirc.net` | IRC server. |
| `scrape_irc_port` | `SCRAPE_IRC_PORT` | 6667 | Port; use a TLS port (6697 or 7001, for example) with `SCRAPE_IRC_TLS=true`. |
| `scrape_irc_tls` | `SCRAPE_IRC_TLS` | `false` | Connect over TLS. Certificate verification is off for this connection. |
| `scrape_irc_nickname`, `scrape_irc_realname`, `scrape_irc_username` | `SCRAPE_IRC_USERNAME` | empty | One value for nickname, real name and ident (also the ZNC user name). `irc:scrape` refuses to start while it is empty. |
| `scrape_irc_password` | `SCRAPE_IRC_PASSWORD` | `false` | Not needed on SynIRC; required for ZNC (`username:password` or `username/network:password`). |

`.env.example` lists all five keys with empty values. An empty value in `.env` is used as is,
not replaced by the code default, so set `SCRAPE_IRC_SERVER` and `SCRAPE_IRC_PORT` explicitly.
The channel list (`scrape_irc_channels`, default `#PreNNTmux`), the sources to ignore
(`scrape_irc_source_ignore`) and the optional category and title ignore regexes
(`scrape_irc_category_ignore`, `scrape_irc_title_ignore`) have no environment keys; they are
edited in the file.

### Metadata API keys

`config/nntmux_api.php` holds keys and request settings for services that have no
configuration file of their own. All of them are in `.env.example`.

| Key | Environment | Default | Used by |
| --- | --- | --- | --- |
| `fanarttv_api_key` | `FANARTTV_APIKEY` | empty | Fanart.tv artwork lookups (`FanartTvService`). |
| `fanarttv_timeout` | `FANARTTV_TIMEOUT` | 30 | Fanart.tv request timeout, seconds. |
| `fanarttv_retry_times`, `fanarttv_retry_delay` | `FANARTTV_RETRY_TIMES`, `FANARTTV_RETRY_DELAY` | 3, 100 | Attempts and backoff (ms) for the gateway's `fanarttv` entry. |
| `google_books_api_key` | `GOOGLE_BOOKS_API_KEY` | empty | Google Books lookups. |
| `isbndb_api_key` | `ISBNDB_API_KEY` | empty | ISBNdb lookups. |
| `omdb_api_key` | `OMDB_APIKEY` | empty | OMDb film lookups. |
| `trakttv_api_key` | `TRAKTTV_APIKEY` | empty | Trakt client ID for TV and film metadata. |
| `trakttv_timeout` | `TRAKTTV_TIMEOUT` | 30 | Trakt request timeout, seconds. |
| `trakttv_retry_times`, `trakttv_retry_delay` | `TRAKTTV_RETRY_TIMES`, `TRAKTTV_RETRY_DELAY` | 3, 100 | Attempts and backoff (ms) for the gateway's `trakt` entry. |

### SSL for outbound connections

`config/nntmux_ssl.php` builds the TLS context for Usenet connections made by `NNTPService`
over SSL/TLS (`streamSslContextOptions()` in `app/Extensions/helper/helpers.php`).

| Key | Environment | Default | Meaning |
| --- | --- | --- | --- |
| `ssl_cafile` | `SSL_CAFILE` | empty | CA bundle file. |
| `ssl_capath` | `SSL_CAPATH` | empty | CA certificate directory. |
| `ssl_verify_peer` | `SSL_VERIFY_PEER` | `false` | Verify the server certificate. |
| `ssl_verify_host` | `SSL_VERIFY_HOST` | `false` | Verify the certificate's host name. |
| `ssl_allow_self_signed` | `SSL_ALLOW_SELF_SIGNED` | `true` | Accept self-signed certificates. |

Peer and host verification only take effect when `SSL_CAFILE` or `SSL_CAPATH` is set; with
neither set, the connection is not verified whatever the two verify keys say. The IRC
scraper's TLS connection never verifies.

### Status probes

The public status page (`/status`) and Admin → Site status (`/admin/status`) show services whose
health the scheduler checks every minute with `php artisan nntmux:check-service-health`. A
service with check type `probe` runs one of the probes in `app/Services/StatusProbes/`; its row
in `service_statuses` names the probe. Migrations create the rows for `database`, `redis`,
`search`, `nntp`, `queue`, `disk`, `musicbrainz`, `nntp-headers` and `chromaprint`, plus the
`api`, `http` and `rss` rows. A failing
probe opens an incident, and a passing one resolves it. The `nntp` and `nntp-headers` probes
are described in [NNTP providers](architecture/nntp-providers.md).

`config/status-probes.php` tunes two of them:

| Key | Environment | Default | Meaning |
| --- | --- | --- | --- |
| `disk.mount_points` | `STATUS_PROBE_DISK_MOUNT_POINTS` | `/` | Comma-separated mount points; the one with the least free space is reported. |
| `disk.warning_threshold_gb` | `STATUS_PROBE_DISK_WARNING_THRESHOLD_GB` | 5 | At or below this many GB free the disk probe reports Minor. |
| `disk.critical_threshold_gb` | `STATUS_PROBE_DISK_CRITICAL_THRESHOLD_GB` | 1 | At or below this many GB free it reports Critical. |
| `redis.probe` | `STATUS_PROBE_REDIS` | `true` | Whether the Redis probe runs at all. |
| `redis.only_when_used` | `STATUS_PROBE_REDIS_ONLY_WHEN_USED` | `true` | Skip the Redis check when cache, session, queue and Pulse do not use Redis. |

The three disk keys are not in `.env.example` yet; the defaults apply until they are set.

### GDPR requests

`app/Services/Gdpr/` handles users' data requests; it has no configuration file or environment
keys.

- **Export**: a user requests one from the Privacy Center (`/privacy-center`). It is generated
  at once as a JSON file on the `local` disk under `gdpr/exports/`, downloadable for 7 days.
  A new export cannot be requested while one is still downloadable. The scheduler runs
  `php artisan gdpr:purge-expired-exports` daily at 03:30 to delete expired files; the
  request row is kept as the audit record.
- **Erasure**: a user submits one from the Privacy Center by typing `ERASE`; admin accounts
  cannot. The request waits for an administrator under Admin → GDPR Requests
  (`/admin/gdpr-requests`), who completes or rejects it. The requester is emailed at each step
  and administrators are emailed when an erasure is requested.
- **Retention**: requests are kept as accountability records, payments are kept with direct
  identifiers anonymized where practical, and audit entries (`gdpr_audit_logs`) are kept in
  minimized form (`GdprRetentionService::policy()`).

### Table retention registry

`config/nntmux-retention.php` is not an operator setting: it records, for contributors, how
rows leave every table in `database/schema/mariadb-schema.sql`. `core_tables` lists tables
whose rows are kept on purpose and leave only through a person's action, or never; `owners`
maps each automatically pruned table to the class (or `schedule:<name>` task in
`routes/console.php`) that deletes its rows. Any other table must cascade from a classified
parent. `tests/Feature/RetentionRegistryTest.php` fails when a table is unclassified, so a
migration that adds a table must add it here.
