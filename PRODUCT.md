# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

People who run their own Usenet indexer and use its web frontend to find and take releases. The maintainer's production instance is single-user (the maintainer), but the project is a public repository that other people install, so screens are designed for any self-hosting owner and their invited users, never sized to one instance's usage.

A second audience, site admins, operates the indexer (groups, backfill, post-processing, settings) on the same chassis.

Confirmed jobs in the **TV section** (maintainer, 2026-09-20):

- Get a specific show: the user already knows the show, finds it, then picks an episode or a whole season.
- Find something new: no show in mind; look around for something worth watching.
- Just see what came in: the latest TV releases, newest first, regardless of show.

Explicitly **not** selected: "catch up on shows I already follow" as a reason to open the TV section.

## Product Purpose

NNTmux downloads NNTP headers, assembles them into releases, enriches them with metadata (TMDB, TVDB, TVMaze, Trakt, IMDB), and serves them for browsing and search. Success for the frontend: a person can tell what a release is, whether it is complete, and whether it is worth taking, then take it.

## Operating Context

- Desktop browser is the primary setting; the collection is very large and mostly machine-named.
- TV data: releases are matched to shows and, where possible, to episodes. Some releases are full-season packs. Some name an episode the metadata tables do not know about yet.
- Releases are taken by downloading an NZB or adding it to the cart/basket.

## Capabilities and Constraints

- Blade + Alpine (CSP build: no inline `style` attributes), Tailwind v4, Font Awesome as the only icon library.
- One accent, coral, on a per-user light/dark axis (maintainer, 2026-09-21: the blue / emerald / violet picker is removed). Views reach it through tokens, never a literal value.
- External API and RSS surfaces are frozen. New release data belongs in the web frontend only.
- The database may change to serve a design, but every query a screen needs is proven against real data before it is built.
- Show details (genres, cast, original language, US rating, running/ended, network) are not stored today; the TV redesign specifies them (`docs/proposals/tv-redesign/DATA-CONTRACT.md`). They are fetched when a show is first matched and refreshed only when a new release arrives for it, never on a schedule: the app is not an authoritative source of show information.
- Domain vocabulary (Completion chip, Preview chip, Discard, Hide) is defined in `CONTEXT.md`.
- Schema rules (maintainer, 2026-09-21): normalize; nothing about releases may be specific to one category; downtime is not a design concern.

## Brand Commitments

- Name: NNTmux.
- Maintainer's stated bar for the frontend (2026-09-20): "Visually appealing, easy to navigate, and easy to absorb the information being shown."
- Rejected, do not preserve or design around: the TV Covers page that shows one tile per episode with season packs repeated under every episode; the v2 "show wall" prototype's look. The full list for TV is `docs/proposals/tv-redesign/SPEC.md` appendix B.

## Evidence on Hand

- A restored copy of production data in a local query lab for proving queries (outside this repository).
- Real cover/poster art exists for matched shows; prototypes that lack it must label stand-in art as synthetic.
- No testimonials, customer names, or usage claims exist; none may be invented.

## Product Principles

1. The releases are the content; the interface makes a machine-generated wall legible.
2. A show is the unit people think in for TV; an episode is something you reach inside a show.
3. Every screen costs the same on page 1 and page 200.
4. Built for anyone who installs it, validated against the maintainer's real data.
