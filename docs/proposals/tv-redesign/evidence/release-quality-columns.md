# `resolution` and `source` as columns on `releases`, for every category — measured

This is the measurement behind the final, normalized design in `DATA-CONTRACT.md`.

Run 2026-09-21 on the restored production catalogue at full size (2,359,525 releases; 173,152
TV). `01-simulate.sql` builds `labwork.releases_sim`: one row per release with only the columns
the proposed indexes would contain, plus `labwork.release_tv_episodes_sim` (a child table with
no show id on it). Index behaviour and size equal what they would be on `releases` itself.
Nothing in `nntmux` is touched. Build time 14 s. Warm, best single run, on this Mac.

| Query | Rows read | Time |
|---|---:|---:|
| TV page 1, posted newest, a user hiding two sub-categories | 101 | 0.4 ms |
| TV page 200 | 11,440 | 2.2 ms |
| TV page 200, 4K or 1080p + WEB, added oldest | 13,133 | 1.7 ms |
| TV exact middle page (worst case) | 87,624 | 12.2 ms |
| TV count, no filter | 173,153 | 15.1 ms |
| TV count, 4K or 1080p + WEB | 125,538 | 15.8 ms |
| TV count, SD + HDTV | 223 | 0.4 ms |
| Movies page 200, 1080p (575k movies, 6% with a known resolution) | 508,588 | 60.1 ms |
| Movies count, 1080p | 36,785 | 3.3 ms |
| Newest release per show, top 42 (index-only group-by on `(videos_id, postdate)`) | 17,031 | 5.8 ms |
| Earliest added per show, top 42 | 11,383 | 3.5 ms |
| Shows wall, all six filters + newest-releases-first, page 1, no stored summary | 28,685 | 6.6 ms |
| Shows wall, no filter, newest-releases-first, last page | 39,542 | 8.4 ms |
| Show page season tabs, biggest show, show id not on the child table | 4,336 | 1.3 ms |
| Show page episode rows, biggest season, visibility + size | 5,067 | 3.0 ms |
| Releases of one episode (933) | 3,058 | 3.1 ms |

Index sizes: band/posted 64.6 MB, band/added 64.6 MB, band/count 53.6 MB, videos/posted 46.6 MB,
videos/added 46.6 MB.

Conclusion: the normalized design (no side table, no copied columns, no count table, no stored
per-show aggregates) performs the same as the rejected TV-only side table for TV, and serves
every category. The one weak spot is a selective filter on a very large band in date order
(Movies, 1080p, page 200); that is for the Movies design to solve, with an index led by
`(category_band, resolution, postdate)` or a better resolution hit-rate.
