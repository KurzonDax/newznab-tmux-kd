# The decision model

NNTmux can ask an optional, self-hosted decision model for a second opinion on decisions the
pipeline is unsure of. It is off by default and sends nothing until an operator configures it.
The code lives in `app/Services/DecisionModel/`; what is asked lives with each use case.

## How a decision is made

The client (`DecisionModelClient`) knows nothing about what is being decided. It posts one
request to the service's `v1/systemone` endpoint: a *state* (what the model is asked about,
such as a release name) and a map of typed *questions* (`Questions/ChoiceQuestion`,
`ScoreQuestion`, `NoulQuestion`), and reads back one answer per question. A use case
(`DecisionUseCase`) builds the state and the questions, may ask follow-up questions from the
answers (at most five requests per decision, `DecisionRunner`), and interprets the answers into
a verdict. Use cases are registered in `app/Providers/DecisionModelServiceProvider.php`.

Requests go through the [provider gateway](provider-gateway.md) entry `decision_model`: 429,
5xx and connection errors are retried with doubling backoff from 250 ms, and after
`DECISION_MODEL_CIRCUIT_BREAKER_FAILURES` consecutive failed tries the breaker stops that
process calling the service until the process ends. Requests are sent one at a time. When the
model cannot be asked, the caller keeps its own answer: categorization never fails because of
the model.

## Modes

Each use case has its own mode setting (`DecisionMode`):

| Mode | Effect |
| --- | --- |
| `off` | The model is not asked. A blank, missing or unknown value is also off. |
| `shadow` | The model is asked and its answer logged; the application's own answer stands. |
| `apply` | The model is asked, its answer logged, and an accepted answer replaces the application's own. |

## Use cases

| Name | Asks | Modes | Setting |
| --- | --- | --- | --- |
| `category_arbitration` | Which root category and subcategory a release belongs to, from its name. | off, shadow, apply | `decision_model_mode` |
| `music_album_check` | Which of the music matcher's candidate albums a release is, or none, from its name and up to 10 file names. | off, shadow | `decision_model_music_album_mode` |

### Category arbitration

`CategoryArbiter` runs at the end of every categorization (`CategorizationPipeline`), after the
categorizers and any forced root. It asks only when the mode is not off, the client is usable,
the release is not locked to Misc or categorized as Other → Hashed, the categorizers'
confidence is below `decision_model_arbitrate_below` (default 0.70), and the name is a readable,
non-hashed title. In apply mode the model's answer is used when its confidence is at or above
`decision_model_apply_threshold` (default 0.80); a confident root with a less confident
subcategory files the release in that root's Other category. The arbiter re-reads these settings
at most once a minute, so a change reaches running processes within a minute.

### Music album check

`MusicAlbumCheck` runs once per music pass (`multiprocessing:postprocess mus`, which the
shelf pane's `ama` pass also runs at its end), after the bucket workers, and only while music
identity is active (MusicBrainz endpoint configured). In shadow mode it puts up to 50 current
matcher decisions that have a titled candidate to the model per pass, newest first, once per
criteria version, including releases matched before the check existed. It changes nothing:
no album link, name, category or cover. A decision whose answer the service rejected outright
is logged with the reason and not asked again; when the service is down, busy or refusing the
key, nothing is logged and the next pass retries.

## Criteria files

Each use case reads its questions and instructions from `config/decision-model/<name>.json`
(`category_arbitration.json`, `music_album_check.json`; folder set by `criteria_path` in
`config/decision-model.php`). Each file carries a `version` string that is logged with every
decision, so answers under different criteria can be compared like for like. Editing a file
without changing its version mixes old and new answers under one version.

## The decision log

Every decision is one row in `decision_model_decisions` (`DecisionLog`): the criteria row
(`decision_model_criteria`, one per use case and version), the subject type and id
(`release` for category arbitration, `release_music_identification` for the album check), the
mode, the state as sent, the answers, the pipeline's own answer, the interpretation, whether
the answer was applied, and a failure reason when the model could not be asked. A category
decision made before its release row exists is linked to the release once it is stored. Both
tables are kept: `config/nntmux-retention.php` lists them as core tables, and there is no admin
screen for them.

## Operator configuration

1. Run a decision service that speaks the System One request shape, reachable from every host
   that runs tmux workers.
2. Set the environment keys (all read by `config/decision-model.php` and present in
   `.env.example`):

   | Key | Default | Meaning |
   | --- | --- | --- |
   | `DECISION_MODEL_ENABLED` | `false` | Nothing is sent while this is false. |
   | `DECISION_MODEL_BASE_URL` | empty | Root address of the service, without a path, for example `https://decision.example.internal`. Nothing is sent while it is empty. |
   | `DECISION_MODEL_API_KEY` | empty | Sent as a bearer token when set. |
   | `DECISION_MODEL_MODEL` | empty | Sent as the request's `model` field when set; omitted when empty. |
   | `DECISION_MODEL_TIMEOUT_SECONDS` | 30 | Request timeout. |
   | `DECISION_MODEL_RETRY_ATTEMPTS` | 3 | Tries per request, counting the first. |
   | `DECISION_MODEL_CIRCUIT_BREAKER_FAILURES` | 3 | Consecutive failed tries after which a process stops calling the service. |

3. Choose a mode per use case in Admin → Settings → Decision Model
   (`/admin/settings/decision-model`). Shadow mode logs answers without changing anything,
   so they can be compared with the pipeline's own answers before apply is chosen.

See [Configuration](../configuration.md#decision-model) for the settings page.
