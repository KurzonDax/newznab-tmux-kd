# Project rules index

The [root policies](../../AGENTS.md) apply throughout the repository. Use this map
to select guidance for the behavior and paths involved; read relevant sections
once and revisit when scope changes. Cross-cutting behavior can require another
entry, but unrelated rules are not prerequisites for a small edit.

| When working on / paths | Reference |
| --- | --- |
| Verification process or repository tooling | [General](general.md), [CI policy](../../docs/agents/ci-policy.md) |
| Tests, fixtures, bootstrap, query schema evidence, `tests/**`, `phpunit.xml`, `database/migrations/**`, MariaDB schema dump | [Testing](testing.md) |
| Audio/additional processing or their dispatch: `app/Services/AdditionalProcessing/**`, `app/Services/AudioProcessing/**`, `app/Services/Runners/PostProcessRunner.php` | [Additional/audio processing](additional-processing.md) |
| API/RSS response shapes or routing: `app/Http/Controllers/Api/**`, `app/Data/Api/**`, `app/Services/Api/**`, `app/Http/Controllers/RssController.php`, `routes/api.php`, `routes/rss.php`, relevant parts of `routes/web.php` and `bootstrap/app.php` | [Frozen API/RSS](api-frozen.md) |
| Manticore queries/schema: `ManticoreSearchDriver`, `ManticoreIndexRegistry`, `CreateManticoreIndexes` | [Search drivers](drivers.md) |
| Release naming: `app/Services/NameFixing/**` | [Name fixing](name-fixing.md) |
| Frontend/CSP/design system: `resources/**`, `vite.config.js`; content ordering: `AdminContentController` | [Resources](resources.md) |
| Release lifecycle, ingestion, TV admission, claims, categorization: relevant `app/Services/**` | [Services](services.md) |
| Numeric settings readers in `app/**` | [Settings values](settings-values.md) |
| Settings declaration/validation/save: `app/Support/Settings/**`, `app/Services/Settings/**`, `AdminSettingsController`, `resources/views/admin/settings/**`, settings test helpers | [Settings hub](settings-hub.md) |
| Tmux layouts/dispatch: `app/Services/Tmux/**`, `PostProcessRunner`, `config/tmux.php` | [Tmux](tmux.md) |
