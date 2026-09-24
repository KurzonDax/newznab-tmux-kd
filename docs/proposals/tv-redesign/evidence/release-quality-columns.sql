-- Simulates `resolution` and `source` as columns ON `releases`, for EVERY category, plus a
-- generated root-category column, by building a stand-in with one row per release (all 2.36M)
-- carrying only the columns the proposed indexes would contain. Index behaviour and size are
-- the same as they would be on `releases` itself. Nothing in `nntmux` is touched.
DROP TABLE IF EXISTS labwork.releases_sim;
CREATE TABLE labwork.releases_sim (
  id INT UNSIGNED NOT NULL PRIMARY KEY,
  categories_id INT NOT NULL,
  root_categories_id INT AS (FLOOR(categories_id / 1000) * 1000) PERSISTENT,
  passwordstatus TINYINT NOT NULL,
  postdate DATETIME NOT NULL,
  adddate DATETIME NOT NULL,
  videos_id INT UNSIGNED NOT NULL,
  resolution TINYINT UNSIGNED NOT NULL,
  source TINYINT UNSIGNED NOT NULL
) ENGINE=InnoDB;
INSERT INTO labwork.releases_sim (id, categories_id, passwordstatus, postdate, adddate, videos_id, resolution, source)
SELECT r.id, r.categories_id, r.passwordstatus, r.postdate, r.adddate, r.videos_id,
  CASE
    WHEN COALESCE(p.w, v.w) >= 3800 OR COALESCE(p.h, v.h) >= 2100 THEN 1
    WHEN COALESCE(p.w, v.w) >= 1900 OR COALESCE(p.h, v.h) >= 1000 THEN 2
    WHEN COALESCE(p.w, v.w) >= 1280 OR COALESCE(p.h, v.h) >= 720 THEN 3
    WHEN COALESCE(p.w, v.w) > 0 THEN 4
    WHEN r.searchname REGEXP '(^|[^0-9a-z])2160[pi]([^0-9a-z]|$)' THEN 1
    WHEN r.searchname REGEXP '(^|[^0-9a-z])1080[pi]([^0-9a-z]|$)' THEN 2
    WHEN r.searchname REGEXP '(^|[^0-9a-z])720[pi]([^0-9a-z]|$)' THEN 3
    WHEN r.searchname REGEXP '(^|[^0-9a-z])(576|480)[pi]([^0-9a-z]|$)' THEN 4
    ELSE 0 END,
  CASE
    WHEN r.searchname REGEXP '(^|[^0-9a-z])remux([^0-9a-z]|$)' THEN 5
    WHEN r.searchname REGEXP '(^|[^0-9a-z])(web[ ._-]?dl|webrip|web)([^0-9a-z]|$)' THEN 1
    WHEN r.searchname REGEXP '(^|[^0-9a-z])(blu-?ray|bdrip|brrip)([^0-9a-z]|$)' THEN 2
    WHEN r.searchname REGEXP '(^|[^0-9a-z])(dvdrip|dvd)([^0-9a-z]|$)' THEN 3
    WHEN r.searchname REGEXP '(^|[^0-9a-z])(hdtv|pdtv|sdtv|dsr|tvrip)([^0-9a-z]|$)' THEN 4
    ELSE 0 END
FROM nntmux.releases r
LEFT JOIN (SELECT releases_id, videowidth w, videoheight h FROM nntmux.video_data WHERE videowidth > 0) v ON v.releases_id = r.id
LEFT JOIN (SELECT pr.releases_id, MAX(t.width) w, MAX(t.height) h FROM nntmux.media_info_probes pr JOIN nntmux.media_info_tracks t ON t.media_info_probe_id = pr.id AND t.type = 'video' AND t.width > 0 GROUP BY pr.releases_id) p ON p.releases_id = r.id;
ALTER TABLE labwork.releases_sim
  ADD INDEX ix_root_posted (root_categories_id, postdate, id, resolution, source, categories_id, passwordstatus),
  ADD INDEX ix_root_added  (root_categories_id, adddate,  id, resolution, source, categories_id, passwordstatus),
  ADD INDEX ix_root_count  (root_categories_id, resolution, source, categories_id, passwordstatus),
  ADD INDEX ix_video_posted (videos_id, postdate),
  ADD INDEX ix_video_added  (videos_id, adddate);
ANALYZE TABLE labwork.releases_sim;
