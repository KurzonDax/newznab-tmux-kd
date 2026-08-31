---
paths:
  - app/Services/Search/Drivers/ManticoreSearchDriver.php
---

# Drivers

## Use one relaxed prefix for fielded Manticore queries
Place `@@relaxed` once at the start of the full query. When the same prepared term targets multiple fields, emit one `@(field1,field2)` selector; repeating relaxed/field clauses after a parenthesized release name can fail with `TOK_FIELDLIMIT` on Manticore 28.4.4.
