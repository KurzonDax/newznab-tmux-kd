---
paths:
  - 'app/Services/Tmux/**'
  - 'app/Services/Runners/PostProcessRunner.php'
  - 'config/tmux.php'
---

# Tmux scheduling

Use these rules for layout, task dispatch, or post-processing throughput changes.

- Stored sequential mode `1` selects basic; every other value selects full,
  including legacy `2`. Both layouts include IRC and Recovery windows in addition
  to the three processing windows. Optional monitoring windows follow them.
- Route work through `TmuxPaneRole` and the pane manager. Numeric window/pane
  addresses are legacy fallbacks, not the ownership model.
- `multiprocessing:postprocess` dispatches through `PostProcessRunner`. Additional
  processing can run directly with one process; parallel additional workers use
  `postprocess:guid` worker mode. Hot GUID buckets can be shared across workers,
  with claims protecting releases. A worker can drain several bounded batches;
  `maxaddprocessed` limits each batch, not a bucket's entire cycle.
- Additional children always stream output. `nntmux.stream_fork_output` controls
  streaming for other types. Read current batching bounds in `PostProcessRunner`
  and thread/audio defaults in the settings providers/seeder instead of copying
  tunable inventories into instructions.
- Audio `aud` is distinct from additional `add`; preserve the shared candidate and
  claim rules in [additional/audio processing](additional-processing.md).
  `update:postprocess` is the direct interface; inspect its accepted types rather
  than assuming multiprocessing aliases also work there.
