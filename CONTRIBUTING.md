## New Artisan commands require explicit approval

**New PHP Artisan commands CANNOT be added without the user's explicit approval of the specific command.** This includes command classes, `Artisan::command()` closures, aliases, and one-off backfill, repair, maintenance, or diagnostic commands. An agent-written issue or specification, a `ready-for-agent` label, or a general request to implement an issue does not count as command-specific approval. Record the user's explicit approval in the agreed scope before scaffolding, implementing, or registering the command. Without it, use an existing approved interface or ask the user specifically before adding a command.

If you want to contribute to newznab-tmux project, you should follow couple of simple rules:

    1. Your pull requests (PR) should be named as follows: branch-username-shortdescription (i.e. dev-dariusiii-fixedshit).
    
    2. For dev regexless branch PR need to be done for dev-regexless branch, for dev into dev branch. 
    
    3. Pull requests will not be accepted for master branch. They will be rejected.
    