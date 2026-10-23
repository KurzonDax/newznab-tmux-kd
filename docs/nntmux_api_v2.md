# NNTmux API v2 Specification

Code-first reference for the JSON API under `/api/v2`.

Primary sources:

- `routes/api.php`
- `app/Http/Controllers/Api/ApiV2Controller.php`
- `app/Services/Api/V2/ApiV2Presenter.php` (search envelope and details response)
- `app/Data/Api/ReleaseData.php` (one search result)
- `app/Data/Api/DetailsData.php` (the details object)
- `app/Data/Api/CategoryData.php` (capabilities categories)
- `app/Services/Api/ApiCapabilitiesService.php` (capabilities payload)
- `app/Services/Api/ApiQueryParameters.php` (`cat`, `group`, `limit`, `offset` parsing)
- `app/Http/Middleware/ThrottleApiRequestsByToken.php` (`apiRateLimit`)

## Base URL

```text
https://<host>/api/v2
```

## Authentication and Rate Limits

- `GET /capabilities` is public.
- All other v2 routes require `api_token`.
- Route-level middleware uses token-aware throttling (`apiRateLimit`) and accepts `api_token` or the legacy `apikey` alias when that middleware is reused.
- The per-user limit is the user's `rate_limit` (60 requests per minute when unset). Throttled responses carry `X-RateLimit-Limit` and `X-RateLimit-Remaining`; a request over the limit gets HTTP `429` with `{"error": "Request limit reached", "retry_after": <seconds>}` and a `Retry-After` header.
- The daily API request quota is the user's role `apirequests`; a user over it gets HTTP `429` `Request limit reached` from the controller.
- `POST /nzbadd` is intentionally exempt from route-level rate limiting and API request quotas, and uploads are not recorded as API usage. Authentication and posting privileges still apply.
- Controller-level auth errors return a JSON error envelope:

```json
{
  "error": "Missing parameter (api_token)"
}
```

Common auth/rate-limit statuses:

| HTTP | Error |
|---:|---|
| 400 | `Missing parameter (api_token)` |
| 401 | `Incorrect user credentials` |
| 403 | `Account suspended` |
| 429 | `Request limit reached` |

## Common Query Parameters

| Parameter | Type | Default | Notes |
|---|---|---:|---|
| `api_token` | string | - | Required except `capabilities`. |
| `id` | string | `""` | Search text/fallback identifier on search endpoints. |
| `limit` | int | `100` | Max rows in page. |
| `offset` | int | `0` | Zero-based pagination offset. |
| `cat` | csv string | `-1` | Category filter; `TV_WEBDL` (5010) is added when `TV_HD` (5040) is requested and the `catwebdl` setting is `0`. |
| `group` | string | `-1` | Usenet group filter (`/search`, `/audio`, `/books`). |
| `maxage` | int | `-1` | Max post age in days. Invalid values return JSON `400`. |
| `minsize` | int | `0` | Min release size in bytes. |
| `maxsize` | int | - | Accepted for compatibility; currently not enforced in query layer. |
| `sort` | string | `posted_desc` | `cat|name|size|files|stats|posted` + `_asc|_desc`. |
| `cursor` | string | - | Cursor pagination on the search endpoints. Send `cursor=` (empty) for the first page, then the returned `pagination.next_cursor`. Cannot be combined with a nonzero `offset`; an invalid, expired (15 minutes by default) or mismatched cursor returns JSON `400`. |

Sorting examples:

- `/api/v2/search?api_token=<token>&id=ubuntu&sort=posted_desc`
- `/api/v2/search?api_token=<token>&id=ubuntu&sort=name_asc`
- `/api/v2/tv?api_token=<token>&id=last+week+tonight&season=2025&ep=11/10&sort=posted_desc`
- `/api/v2/movies?api_token=<token>&imdbid=tt0816692&sort=size_desc`

JSON sorting response snippet (`sort=size_desc`):

```http
HTTP/1.1 200 OK
Content-Type: application/json
{
  "Total": 2,
  "apiCurrent": 0,
  "apiMax": 100,
  "grabCurrent": 0,
  "grabMax": 100,
  "apiOldestTime": "",
  "grabOldestTime": "",
  "results": [
    { "title": "Ubuntu ISO x64", "size": 734003200 },
    { "title": "Ubuntu ISO x86", "size": 367001600 }
  ]
}
```

Releases are ordered largest-to-smallest because `sort=size_desc`.

> The legacy `Results` (capital R) Fractal field has been replaced by the
> lower-case `results` field. Search responses retain pagination and quota
> metadata in the top-level JSON object. Movie/TV-only fields (`tvdbid`,
> `imdbid`, `season`, …) are omitted when they do not apply to a release.

## Endpoints

## 1) Capabilities

- `GET /capabilities`
- Auth: none

Returns:

- `server`
- `limits`
- `searching`
- `registration`
- `categories`
- `groups`
- `genres`

Field shapes:

- `server`: `title`, `strapline`, `email`, `url`
- `limits`: `max` and `default` (both `100`)
- `registration`: `available` and `open` (`yes` or `no`)
- `searching`: one entry per search type (`search`, `tv-search`, `movie-search`, `audio-search`, `book-search`, `anime-search`) with `available` and `supportedParams`
- `categories`: `id`, `name` and `subcategories` (a map of sub-category ID to title)
- `groups`: active groups with `name`, `description`, `lastupdate`
- `genres`: enabled genres outside Movies and TV with `id`, `name`, `categoryid`

The response is cacheable (`Cache-Control: public, max-age=300`) and carries an `ETag`.

## 2) Search

- `GET /search`
- Auth: required

Behavior:

- If `id` is present: text search.
- If `id` is omitted: browse mode.
- Includes API usage counters via response headers (`X-Api-Current`, `X-Api-Max`, `X-Grab-Current`, `X-Grab-Max`, `X-Api-Oldest-Time`, `X-Grab-Oldest-Time`).

## 3) TV Search

- `GET /tv`
- Auth: required

Identifiers:

- `vid`, `tvdbid`, `traktid`, `rid`, `tvmazeid`, `imdbid`, `tmdbid`

At least one of `id` or an identifier is required; otherwise JSON `400`.

Optional filters:

- `season`, `ep`, `cat`, `maxage`, `minsize`, `sort`, `offset`, `limit`

Daily parsing:

- `season=YYYY` and `ep=MM/DD` infers an airdate query.

## 4) Movie Search

- `GET /movies`
- Auth: required

Identifiers:

- `imdbid`, `tmdbid`, `traktid`

At least one of `id`, a valid `imdbid`, `tmdbid` or `traktid` is required; otherwise JSON `400`.

Optional filters:

- `id`, `cat`, `maxage`, `minsize`, `sort`, `offset`, `limit`

## 5) Audio Search

- `GET /audio`
- Auth: required

Optional:

- `id` (query string). When omitted, the endpoint browses Audio (category `3000`, or `cat` when given). An empty `id=` returns JSON `400`.
- `group`, `cat`, `maxage`, `minsize`, `sort`, `offset`, `limit`

## 6) Book Search

- `GET /books`
- Auth: required

Optional:

- `id` (query string). When omitted, the endpoint browses Books (category `7000`, or `cat` when given). An empty `id=` returns JSON `400`.
- `group`, `cat`, `maxage`, `minsize`, `sort`, `offset`, `limit`

## 7) Anime Search

- `GET /anime`
- Auth: required

Selectors:

- `id` and/or `anidbid` and/or `anilistid`; at least one is required, otherwise JSON `400`.

Optional filters:

- `cat`, `maxage`, `sort`, `offset`, `limit`

## 8) Get NZB

- `GET /getnzb`
- Auth: required
- Requires `id` (GUID); optional `del=1` removes the release from the user's cart.
- A valid GUID returns the NZB file itself (`application/x-nzb`, with `X-DNZB-*` headers) from the same download handler as the web `/getnzb` route. Download-limit and missing-file errors from that handler use the XML error body (`501`, `300`).
- Not found returns HTTP `404` JSON: `{"data": "No such item (the guid you provided has no release in our database)"}`.

## 9) Details

- `GET /details`
- Auth: required
- Requires `id` (GUID)
- Unknown GUID returns HTTP `404` JSON `{"error": "No such item"}`.

## 10) Add NZB

- `POST /nzbadd`
- Auth: required; the user must have posting privileges (`can_post`)
- Content type: `multipart/form-data`

Fields:

| Field | Required | Notes |
|---|---|---|
| `api_token` | yes | API token for a verified, enabled user. |
| `nzb` | yes | Valid `.nzb` file staged for deferred import. |
| `nfo` | no | `.nfo` file with any safe basename, maximum 65,535 bytes. |
| `cat` | no | Echoed as response metadata; it does not affect import categorization. |

A user without posting privileges gets HTTP `403`; a missing `nzb` file gets HTTP `400`; a rejected upload (unsafe filename, invalid NZB, empty or oversized NFO, name clash, write failure) returns `{"error": "..."}` with HTTP `400`, `409` or `500`.

When `nfo` is supplied, its basename does not need to match the NZB basename.
The request is atomic: both files are validated before staging in an isolated
upload directory, and a partial write is rolled back.

NZB-only example:

```bash
curl -X POST -F "api_token=<token>" -F "nzb=@Release.nzb" https://<host>/api/v2/nzbadd
```

Paired example:

```bash
curl -X POST -F "api_token=<token>" -F "cat=5040" -F "nzb=@Release.nzb" -F "nfo=@scene-info.nfo" https://<host>/api/v2/nzbadd
```

Successful staging returns HTTP `201`:

```json
{
  "success": true,
  "status": "staged",
  "name": "Release",
  "category": "5040",
  "files": {
    "nzb": { "filename": "Release.nzb", "type": "nzb" },
    "nfo": { "filename": "scene-info.nfo", "type": "nfo" }
  }
}
```

Staging does not synchronously create a release. Import the NZB files first,
then import their paired NFO files using the manifest identity recorded by the
NZB importer:

```bash
php artisan nntmux:import-nzbs --folder=/path/to/NZB_UPLOAD_FOLDER
php artisan nntmux:import-nfos --folder=/path/to/NZB_UPLOAD_FOLDER
```

The NFO importer replaces an existing NFO for the resolved release. Add
`--delete` to remove successfully imported NFO payloads or `--delete-failed`
to remove payloads that cannot be linked. NZB-only responses set `files.nfo`
to `null`.

## Response Models

### Search Envelope (`/search`, `/tv`, `/movies`, `/audio`, `/books`, `/anime`)

```json
{
  "Total": 123,
  "apiCurrent": 2,
  "apiMax": 1000,
  "grabCurrent": 1,
  "grabMax": 100,
  "apiOldestTime": "Wed, 20 Nov 2024 12:00:00 +0000",
  "grabOldestTime": "",
  "results": []
}
```

When the request carries `cursor`, the envelope also has
`"pagination": {"next_cursor": "<cursor or null>", "has_more": true|false}`.

Each `results` item (`ReleaseData`):

| Field | Notes |
|---|---|
| `title` | Release search name. |
| `details` | `<site>/details/<guid>` |
| `url` | `<site>/getnzb?id=<guid>.nzb&r=<api_token>` |
| `category`, `category_name` | Category ID and display name. |
| `added`, `usenetdate` | RSS-format dates (added to the index, posted to Usenet). |
| `size`, `files` | Bytes and part count. |
| `grabs`, `comments` | `null` when zero. |
| `password` | Release password status. |
| `imdbid`, `tmdbid`, `traktid` | Movie and TV releases only; `null` when zero. |
| `episode_title`, `season`, `episode`, `tvairdate`, `tvdbid`, `tvrageid`, `tvmazeid` | TV releases only; IDs are `null` when zero. |

### Details Object (`/details`)

Returns a single release object (not envelope). Download field name is `link` (not `url`).

`DetailsData` carries the same base fields as a search result, except that `link`
replaces `url` and `grabs` and `comments` are returned as stored. Movie releases
add `imdbid`; TV releases add `imdbid`, `tmdbid`, `traktid`, `tvairdate`,
`tvdbid`, `tvrageid` and `tvmazeid`.

## Error Response Conventions

- Missing token: JSON `400`
- Invalid token: JSON `401`
- Disabled account: JSON `403`
- Invalid `maxage`: JSON `400`
- Invalid `sort`: JSON `400`
- Missing required endpoint parameter (`id`, etc.): JSON `400`
- Invalid cursor, or `cursor` with a nonzero `offset`: JSON `400`
- Over the rate limit or the daily request quota: JSON `429`
- Missing GUID in `/getnzb`: JSON `404`

## Unsupported in v2

The following are intentionally not part of v2 JSON API:

- `register`
- `user`
- `comments`
- `commentadd`
- `cartadd`
- `cartdel`

## Postman Collection

- `docs/postman/nntmux_api_v2.postman_collection.json`
