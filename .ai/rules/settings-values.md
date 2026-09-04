---
paths:
  - 'app/**'
---

# Settings values: non-positive, blank, and missing

Applies to every reader of an admin-editable numeric setting — `Settings::settingValue()`, `SettingNumber`, and the config DTOs built from them.

## The fallback turns on what the value means

| Class | Non-positive stored value resolves to | Settled examples |
| --- | --- | --- |
| Batch / chunk width | the coded default | `maxmssgs` → 20000 (#406), `maxnzbsprocessed` → 1000 (#412) |
| Attempt / try budget | 1 | `nntpretries` (#405), `partrepairmaxtries` (#408) |
| Retention window | the seeded default | `partretentionhours` → 72 (#411) |
| Structural value where 0 is legal | 0 is honored; only blank/missing takes the coded default | `nzbsplitlevel` (#413) |

Per-run limits may fail small with `max(1, …)`: substituting a large default would do more work than the operator asked for.

Blank (`''`) and missing (`null`) resolve identically, to the coded default (#407).

## Resolve once, at the boundary

The config DTO or resolver produces the final value; consumers use it raw. A guard at the point of use is either load-bearing — meaning the boundary is broken, fix the boundary — or vestigial: delete it (#412 deleted one deliberately).

## Preferred shape for new readers

A boundary helper with the fallback explicit: `SettingNumber` plus a stated non-positive policy, or a named-argument helper like `BinariesConfig::getPositiveSettingInt($key, $default, whenNonPositive:)`. Existing merged shapes stand; convert a site only when other work touches it.

## What counts as a bug

Two readers of the same setting disagreeing on the fallback (#435 is the shape). A single-reader site that is internally consistent is not.
