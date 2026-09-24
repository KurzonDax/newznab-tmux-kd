# Are the approved show page and the details page's episode table cheap at production size? — measured

> Superseded in part: this run used an earlier release-to-episode table. The final design stores the
> season and episode a release declares (`DATA-CONTRACT.md` 2.2), measured in `release-quality-columns.md`.

Experiment, not a proposal. Run 2026-09-21 against the query lab (restored production
catalogue). Read-only on `nntmux`; uses `labwork.tv_membership` (the stored release→episode
links from an earlier lab experiment) and `labwork.tv_list` (stored resolution / source from
an earlier lab experiment). 
## Verdict in plain language

Yes. With the release→episode links and the per-release resolution / source stored, every
query the show page and the details page make reads a few hundred to a few thousand rows and
returns in **0.3 to 2.3 ms**, on the worst cases in the catalogue.

| Worst case used | Query | Rows read | Time |
|---|---|---:|---:|
| Biggest season: one show, season 5, 945 releases, one episode has 933 | season tabs | 344 | 0.4 ms |
| | episode rows (count, resolutions present, size range) | 3,848 | 2.3 ms |
| | same, Resolution 4K or 1080p + Source WEB | 3,734 | 2.1 ms |
| Biggest show: one show, 38 seasons, 1,416 releases | season tabs | 1,834 | 0.7 ms |
| | episode rows | 256 | 0.5 ms |
| Season with most episodes: one show, season 20, 118 episodes | season tabs | 3,092 | 1.0 ms |
| | episode rows | 1,899 | 1.1 ms |
| Episode with 933 releases | open the episode / details-page table, sorted | 2,800 | 2.0 ms |
| Episode with 31 releases | open the episode / details-page table, sorted | 94 | 0.5 ms |
| Any release | which episode does it belong to | 3 | 0.3 ms |

## Findings for the spec

1. **The size range needs `size` on the narrow per-release table** (or the join to `releases`
   used here, which is what the 3,848 rows above include). Either is cheap.
2. **The 933-release episode is bad data.** One episode holds 932 unrelated
   releases that were all renamed to one episode's name (a name-fixing fault, not a matching
   one). The next biggest episode has 31 releases, so an unpaged episode table is fine.
3. **Whole-season packs are rare in stored memberships** (15 releases catalogue-wide), so the
   packs section costs nothing; how a pack is recognised is a matching question for the spec.
