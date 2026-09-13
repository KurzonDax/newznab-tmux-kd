# Prototype files

`nntmux-prototype.html` is the assembled, self-contained prototype (open it in a browser). It is built from:

- `proto-base.css` + `proto-app.css` — styling (tokens, type scale, controls, chips, tables, cards, covers, modals)
- `proto-app-data.js` — sample catalogues (movies, series, albums, games, console, books)
- `proto-app.js` — router, state, renderers and event handling

`review.html` is the review of the current front end (findings, must-fix list, option mockups, decisions).

`test-harness.js` and `test-entity.js` are scripted click-throughs; see `../13-rollout-and-acceptance.md` for how to run them headlessly.

Rebuild after editing the sources:

```bash
{ printf '<title>NNTmux Prototype</title>\n<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap">\n<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/js/all.min.js" crossorigin="anonymous"></script>\n<style>\n'; cat proto-base.css proto-app.css; printf '\n</style>\n<div id="app"></div>\n<script>\n'; cat proto-app-data.js; printf '\n</script>\n<script>\n'; cat proto-app.js; printf '\n</script>\n'; } > nntmux-prototype.html
```
