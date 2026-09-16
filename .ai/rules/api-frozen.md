---
paths:
  - 'app/Http/Controllers/Api/**'
  - 'app/Data/Api/**'
  - 'routes/rss.php'
  - 'app/Http/Controllers/RssController.php'
---

# Frozen API surfaces

## New Artisan commands require explicit approval

**New PHP Artisan commands CANNOT be added without the user's explicit approval of the specific command.** This includes command classes, `Artisan::command()` closures, aliases, and one-off backfill, repair, maintenance, or diagnostic commands. An agent-written issue or specification, a `ready-for-agent` label, or a general request to implement an issue does not count as command-specific approval. Record the user's explicit approval in the agreed scope before scaffolding, implementing, or registering the command. Without it, use an existing approved interface or ask the user specifically before adding a command.

## The external API and RSS surfaces never change
The v1 newznab XML API (`ApiController`), the v2 JSON API (`ApiV2Controller` plus the `app/Data/Api/` DTOs), and the RSS feeds (`routes/rss.php`, `RssController`) are permanently frozen by maintainer decision. Do not add, rename, or remove fields, attributes, or query parameters — additive and non-breaking changes are still changes, and downstream newznab clients depend on the exact current shape. Bug fixes that restore documented existing behavior are allowed; surface expansion is not.

## New release data stays in the web frontend
When a feature exposes release data that is not already published (completion percentage, repair state, display names, and anything like them), scope it to Blade views, view models, and the search/query layer only. Never mirror it into API or RSS output, and do not offer API exposure as an option in a plan or issue brief; treat "should this also appear in the API?" as already answered no.

Recorded 2026-08-27 during triage of #282: "No API changes to this application EVER."
