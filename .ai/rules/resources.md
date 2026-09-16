---
paths:
  - 'resources/**'
---

# Resources

## New Artisan commands require explicit approval

**New PHP Artisan commands CANNOT be added without the user's explicit approval of the specific command.** This includes command classes, `Artisan::command()` closures, aliases, and one-off backfill, repair, maintenance, or diagnostic commands. An agent-written issue or specification, a `ready-for-agent` label, or a general request to implement an issue does not count as command-specific approval. Record the user's explicit approval in the agreed scope before scaffolding, implementing, or registering the command. Without it, use an existing approved interface or ask the user specifically before adding a command.

## Alpine CSP iframe players
The Alpine CSP evaluator rejects expressions attached to iframe elements, including :src. Create iframe players from the component JavaScript and remove them on close or teardown. Give players tabindex=0 so the shared modal focus loop includes them; verify opening, keyboard focus, and cleanup in a browser.
