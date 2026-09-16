---
paths:
  - 'app/Services/Search/Drivers/ManticoreSearchDriver.php'
  - 'app/Services/Search/Support/ManticoreIndexRegistry.php'
  - 'app/Console/Commands/CreateManticoreIndexes.php'
---

# Drivers

The [root authorization policy](../../AGENTS.md#scope-and-authorization) applies, including command-specific Artisan approval.

## Use one relaxed prefix for fielded Manticore queries
Place `@@relaxed` once at the start of the full query. When the same prepared term targets multiple fields, emit one `@(field1,field2)` selector; repeating relaxed/field clauses after a parenthesized release name can fail with `TOK_FIELDLIMIT` on Manticore 28.4.4.

## Signed status attributes and schema work

`ManticoreIndexRegistry` owns table schemas. Keep `passwordstatus` and `haspreview`
as `bigint`: Manticore `integer` is unsigned, while these statuses can be `-1`.
Unsigned representations of negative values fail filters such as `passwordstatus <= 1`.

For an authorized schema-maintenance task, distinguish column widening from a full
rebuild. Manticore supports `ALTER TABLE ... MODIFY COLUMN ... bigint` for int→bigint;
that alone does not establish that previously wrapped negative values are repaired.
Check the [engine's schema support](https://manual.manticoresearch.com/Updating_table_schema_and_settings)
and the stored values for the deployed version before selecting a procedure.

The existing `manticore:create-indexes` command accepts repeated `--index` options
with logical names; `--index=releases` selects releases. Bare `--drop` rebuilds all
configured indexes as empty shells. A rebuild requires repopulation of the selected
indexes; coordinate writers and availability for that authorized maintenance scope.
The [28.4.4 upgrade runbook](../../docs/manticore-28-upgrade.md) describes its specific
full-generation rebuild, not a default step for every search edit.
