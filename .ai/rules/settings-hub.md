---
paths:
  - 'app/Support/Settings/**'
  - 'app/Http/Controllers/Admin/AdminSettingsController.php'
  - 'app/Services/Settings/**'
  - 'resources/views/admin/settings/**'
  - 'tests/Support/Settings/**'
---

# Admin settings hub

Use these rules when declaring settings or changing their forms, validation, or
save path. For numeric-value interpretation, see [settings values](settings-values.md).

- Declare settings in `app/Support/Settings/Sections/` providers, not Blade files.
  `SettingsRegistry` is the whitelist; its provider list owns pages and ordering.
  Legacy `admin/site-edit` and `admin/tmux-edit` redirect to `admin/settings`.
- A definition owns its label/help, type, options, unit, validation, and eligible
  root IDs. Explicit rules replace type defaults; type guards still apply on top,
  so a picker cannot save an option it does not offer. Reuse existing
  `RepairSettingRules`, `NzbSettingRules`, and `BackfillSettingRules` where applicable.
- Saves are per card through `SettingsCardUpdater`. Unknown or cross-card keys
  reject the entire write, and out-of-range values are rejected rather than clamped.
  Per-root toggles (`generate_previews`, `dynamic_preview_budget`, `generate_clips`,
  `discard_executables`) write `root_categories`, not the settings table.
- `Settings::settingsUpsert()` creates missing settings rows; the legacy
  `settingsUpdate()` method only updates existing rows.
- Reuse `Tests\Support\Settings\InteractsWithSettingsHub`; `currentCardPayload()`
  builds the full card payload so a test can vary one setting.
- A card-adjacent action posting elsewhere belongs in `SettingCard::$asideView`,
  beside the card form. Forms must not nest; the Website breach-response panel is
  an example.
