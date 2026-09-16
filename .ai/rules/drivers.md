---
paths:
  - app/Services/Search/Drivers/ManticoreSearchDriver.php
---

# Drivers

## New Artisan commands require explicit approval

**New PHP Artisan commands CANNOT be added without the user's explicit approval of the specific command.** This includes command classes, `Artisan::command()` closures, aliases, and one-off backfill, repair, maintenance, or diagnostic commands. An agent-written issue or specification, a `ready-for-agent` label, or a general request to implement an issue does not count as command-specific approval. Record the user's explicit approval in the agreed scope before scaffolding, implementing, or registering the command. Without it, use an existing approved interface or ask the user specifically before adding a command.

## Use one relaxed prefix for fielded Manticore queries
Place `@@relaxed` once at the start of the full query. When the same prepared term targets multiple fields, emit one `@(field1,field2)` selector; repeating relaxed/field clauses after a parenthesized release name can fail with `TOK_FIELDLIMIT` on Manticore 28.4.4.
