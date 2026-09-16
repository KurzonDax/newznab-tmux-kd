# Project Rules Index

## New Artisan commands require explicit approval

**New PHP Artisan commands CANNOT be added without the user's explicit approval of the specific command.** This includes command classes, `Artisan::command()` closures, aliases, and one-off backfill, repair, maintenance, or diagnostic commands. An agent-written issue or specification, a `ready-for-agent` label, or a general request to implement an issue does not count as command-specific approval. Record the user's explicit approval in the agreed scope before scaffolding, implementing, or registering the command. Without it, use an existing approved interface or ask the user specifically before adding a command.

Before planning or editing, find the row whose globs match the file's path and read that rule file.

| Applies to | Rule file |
| --- | --- |
| app/Services/AdditionalProcessing/**, app/Services/AudioProcessing/** | .ai/rules/additional-processing.md |
| app/Http/Controllers/Api/**, app/Data/Api/**, routes/rss.php, app/Http/Controllers/RssController.php | .ai/rules/api-frozen.md |
| app/Services/Search/Drivers/ManticoreSearchDriver.php | .ai/rules/drivers.md |
| ** | .ai/rules/general.md |
| app/Services/NameFixing/** | .ai/rules/name-fixing.md |
| resources/** | .ai/rules/resources.md |
| app/Services/** | .ai/rules/services.md |
| app/** | .ai/rules/settings-values.md |
