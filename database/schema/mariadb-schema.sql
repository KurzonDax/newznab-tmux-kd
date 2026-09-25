/*M!999999\- enable the sandbox mode */
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `anidb_info`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `anidb_info` (
  `anidbid` int(10) unsigned NOT NULL COMMENT 'ID of title from AniDB',
  `anilist_id` int(10) unsigned DEFAULT NULL COMMENT 'ID from AniList',
  `mal_id` int(10) unsigned DEFAULT NULL COMMENT 'ID from MyAnimeList',
  `country` char(2) DEFAULT NULL COMMENT 'ISO 3166-1 alpha-2 country code',
  `media_type` varchar(10) DEFAULT NULL COMMENT 'ANIME or MANGA',
  `type` varchar(32) DEFAULT NULL,
  `episodes` int(10) unsigned DEFAULT NULL,
  `duration` int(10) unsigned DEFAULT NULL COMMENT 'Duration in minutes',
  `status` varchar(20) DEFAULT NULL COMMENT 'Media status (FINISHED, RELEASING, etc.)',
  `source` varchar(20) DEFAULT NULL COMMENT 'Original source (MANGA, ORIGINAL, etc.)',
  `hashtag` varchar(255) DEFAULT NULL COMMENT 'AniList hashtag',
  `startdate` date DEFAULT NULL,
  `enddate` date DEFAULT NULL,
  `updated` timestamp NOT NULL DEFAULT current_timestamp(),
  `related` varchar(1024) DEFAULT NULL,
  `similar` varchar(1024) DEFAULT NULL,
  `creators` varchar(1024) DEFAULT NULL,
  `description` mediumtext DEFAULT NULL,
  `rating` varchar(5) DEFAULT NULL,
  `picture` varchar(255) DEFAULT NULL,
  `categories` varchar(1024) DEFAULT NULL,
  `characters` varchar(1024) DEFAULT NULL,
  PRIMARY KEY (`anidbid`),
  KEY `ix_anidb_info_datetime` (`startdate`,`enddate`,`updated`),
  KEY `ix_anidb_info_anilist_id` (`anilist_id`),
  KEY `ix_anidb_info_mal_id` (`mal_id`),
  KEY `ix_anidb_info_country` (`country`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `anidb_titles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `anidb_titles` (
  `anidbid` int(10) unsigned NOT NULL COMMENT 'ID of title from AniDB',
  `type` varchar(25) NOT NULL COMMENT 'type of title.',
  `lang` varchar(25) NOT NULL,
  `title` varchar(255) NOT NULL,
  PRIMARY KEY (`anidbid`,`type`,`lang`,`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `audio_data`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `audio_data` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `releases_id` int(10) unsigned NOT NULL COMMENT 'FK to releases.id',
  `audioid` int(10) unsigned NOT NULL,
  `audioformat` varchar(50) DEFAULT NULL,
  `audiomode` varchar(50) DEFAULT NULL,
  `audiobitratemode` varchar(50) DEFAULT NULL,
  `audiobitrate` varchar(10) DEFAULT NULL,
  `audiochannels` varchar(25) DEFAULT NULL,
  `audiosamplerate` varchar(25) DEFAULT NULL,
  `audiolibrary` varchar(50) DEFAULT NULL,
  `audiolanguage` varchar(50) DEFAULT NULL,
  `audiotitle` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_releaseaudio_releaseid_audioid` (`releases_id`,`audioid`),
  CONSTRAINT `FK_ad_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `binaries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `binaries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `binaryhash` binary(16) NOT NULL,
  `name` varchar(1000) NOT NULL DEFAULT '',
  `collections_id` int(10) unsigned NOT NULL DEFAULT 0,
  `filenumber` int(10) unsigned NOT NULL DEFAULT 0,
  `totalparts` int(10) unsigned NOT NULL DEFAULT 0,
  `currentparts` int(10) unsigned NOT NULL DEFAULT 0,
  `partcheck` tinyint(1) NOT NULL DEFAULT 0,
  `partsize` bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_binaries_collection_hash` (`collections_id`,`binaryhash`),
  KEY `ix_binaries_collection_filenumber` (`collections_id`,`filenumber`),
  KEY `ix_binaries_collection` (`collections_id`),
  KEY `ix_binaries_partcheck` (`partcheck`),
  KEY `ix_binaries_collection_hash` (`collections_id`,`binaryhash`),
  CONSTRAINT `FK_Collections` FOREIGN KEY (`collections_id`) REFERENCES `collections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `binaryblacklist`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `binaryblacklist` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `groupname` varchar(255) DEFAULT NULL,
  `regex` varchar(2000) NOT NULL,
  `msgcol` int(10) unsigned NOT NULL DEFAULT 1,
  `optype` int(10) unsigned NOT NULL DEFAULT 1,
  `status` int(10) unsigned NOT NULL DEFAULT 1,
  `description` varchar(1000) DEFAULT NULL,
  `last_activity` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_binaryblacklist_groupname` (`groupname`),
  KEY `ix_binaryblacklist_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bookinfo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bookinfo` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `author` varchar(255) NOT NULL,
  `asin` varchar(128) DEFAULT NULL,
  `isbn` varchar(128) DEFAULT NULL,
  `ean` varchar(128) DEFAULT NULL,
  `url` varchar(1000) DEFAULT NULL,
  `salesrank` int(10) unsigned DEFAULT NULL,
  `publisher` varchar(255) DEFAULT NULL,
  `publishdate` datetime DEFAULT NULL,
  `pages` varchar(128) DEFAULT NULL,
  `overview` varchar(3000) DEFAULT NULL,
  `genre` varchar(255) NOT NULL,
  `cover` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_bookinfo_asin` (`asin`),
  KEY `ix_bookinfo_isbn` (`isbn`),
  KEY `ix_bookinfo_ean` (`ean`),
  FULLTEXT KEY `ix_bookinfo_author_title_ft` (`author`,`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` longtext NOT NULL,
  `expiration` int(11) NOT NULL,
  UNIQUE KEY `cache_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `root_categories_id` bigint(20) unsigned DEFAULT NULL,
  `status` int(11) NOT NULL DEFAULT 1,
  `description` varchar(255) DEFAULT NULL,
  `minsizetoformrelease` bigint(20) unsigned NOT NULL DEFAULT 0,
  `maxsizetoformrelease` bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_categories_parentid` (`root_categories_id`),
  KEY `ix_categories_status` (`status`),
  CONSTRAINT `fk_root_categories_id` FOREIGN KEY (`root_categories_id`) REFERENCES `root_categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `category_regexes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `category_regexes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group_regex` varchar(255) NOT NULL DEFAULT '' COMMENT 'This is a regex to match against usenet groups',
  `regex` varchar(5000) NOT NULL DEFAULT '' COMMENT 'Regex used to match a release name to categorize it',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=ON 0=OFF',
  `description` varchar(1000) NOT NULL DEFAULT '' COMMENT 'Optional extra details on this regex',
  `ordinal` int(11) NOT NULL DEFAULT 0 COMMENT 'Order to run the regex in',
  `categories_id` smallint(5) unsigned NOT NULL DEFAULT 10 COMMENT 'Which categories id to put the release in',
  PRIMARY KEY (`id`),
  KEY `ix_category_regexes_group_regex` (`group_regex`),
  KEY `ix_category_regexes_status` (`status`),
  KEY `ix_category_regexes_ordinal` (`ordinal`),
  KEY `ix_category_regexes_categories_id` (`categories_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `collection_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `collection_groups` (
  `collections_id` int(10) unsigned NOT NULL,
  `group_name` varchar(255) NOT NULL,
  PRIMARY KEY (`collections_id`,`group_name`),
  CONSTRAINT `fk_collection_groups_collection` FOREIGN KEY (`collections_id`) REFERENCES `collections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `collection_regexes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `collection_regexes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group_regex` varchar(255) NOT NULL DEFAULT '' COMMENT 'This is a regex to match against usenet groups',
  `regex` varchar(5000) NOT NULL DEFAULT '' COMMENT 'Regex used for collection grouping',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=ON 0=OFF',
  `description` varchar(1000) NOT NULL COMMENT 'Optional extra details on this regex',
  `ordinal` int(11) NOT NULL DEFAULT 0 COMMENT 'Order to run the regex in',
  PRIMARY KEY (`id`),
  KEY `ix_collection_regexes_group_regex` (`group_regex`),
  KEY `ix_collection_regexes_status` (`status`),
  KEY `ix_collection_regexes_ordinal` (`ordinal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `collection_sweep_cursors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `collection_sweep_cursors` (
  `scope` varchar(96) NOT NULL,
  `last_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `high_water_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `lease_token` uuid DEFAULT NULL,
  `lease_expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `collections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `collections` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `subject` varchar(255) NOT NULL DEFAULT '',
  `fromname` varchar(255) NOT NULL DEFAULT '',
  `date` datetime DEFAULT NULL,
  `xref` varchar(2000) NOT NULL DEFAULT '',
  `totalfiles` int(10) unsigned NOT NULL DEFAULT 0,
  `declaredfiles` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'Files the [n/N] header token declared; written once, never overwritten',
  `firstarticle` bigint(20) unsigned DEFAULT NULL COMMENT 'Lowest parts.number seen for this collection',
  `lastarticle` bigint(20) unsigned DEFAULT NULL COMMENT 'Highest parts.number seen for this collection',
  `groups_id` int(10) unsigned NOT NULL DEFAULT 0,
  `collectionhash` binary(20) NOT NULL,
  `collection_regexes_id` int(11) NOT NULL DEFAULT 0 COMMENT 'FK to collection_regexes.id',
  `dateadded` datetime DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `last_seen_head_postdate` datetime DEFAULT NULL,
  `last_seen_tail_postdate` datetime DEFAULT NULL,
  `added` timestamp NOT NULL DEFAULT current_timestamp(),
  `filecheck` tinyint(1) NOT NULL DEFAULT 0,
  `filesize` bigint(20) unsigned NOT NULL DEFAULT 0,
  `releases_id` int(11) DEFAULT NULL,
  `noise` char(32) NOT NULL DEFAULT '',
  `absorb_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_collection_collectionhash` (`collectionhash`),
  KEY `fromname` (`fromname`),
  KEY `date` (`date`),
  KEY `groups_id` (`groups_id`),
  KEY `ix_collection_dateadded` (`dateadded`),
  KEY `ix_collection_filecheck` (`filecheck`),
  KEY `ix_collection_releaseid` (`releases_id`),
  KEY `ix_collections_group_filecheck_seen_id` (`groups_id`,`filecheck`,`last_seen_at`,`id`),
  KEY `collections_groups_added_idx` (`groups_id`,`added`),
  KEY `collections_dateadded_idx` (`dateadded`),
  KEY `ix_collections_filecheck_filesize_groups` (`filecheck`,`filesize`,`groups_id`),
  KEY `collections_reconciliation_discovery` (`groups_id`,`declaredfiles`,`date`),
  KEY `collections_admission_window` (`groups_id`,`declaredfiles`,`fromname`,`filecheck`,`date`,`id`),
  KEY `collections_formation_queue` (`groups_id`,`filecheck`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `consoleinfo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `consoleinfo` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `asin` varchar(128) DEFAULT NULL,
  `url` varchar(1000) DEFAULT NULL,
  `salesrank` int(10) unsigned DEFAULT NULL,
  `platform` varchar(255) DEFAULT NULL,
  `publisher` varchar(255) DEFAULT NULL,
  `genres_id` int(11) DEFAULT NULL,
  `esrb` varchar(255) DEFAULT NULL,
  `releasedate` datetime DEFAULT NULL,
  `review` varchar(3000) DEFAULT NULL,
  `cover` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_consoleinfo_asin` (`asin`),
  KEY `ix_consoleinfo_genres_id` (`genres_id`),
  FULLTEXT KEY `ix_consoleinfo_title_platform_ft` (`title`,`platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `content`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `content` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `url` varchar(2000) DEFAULT NULL,
  `body` mediumtext DEFAULT NULL,
  `metadescription` varchar(1000) NOT NULL,
  `metakeywords` varchar(1000) NOT NULL,
  `contenttype` int(11) NOT NULL,
  `status` int(11) NOT NULL,
  `ordinal` int(11) DEFAULT NULL,
  `role` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_status_contenttype_role` (`status`,`contenttype`,`role`),
  KEY `ix_content_type_ordinal` (`contenttype`,`ordinal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `countries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `countries` (
  `iso_3166_2` char(2) NOT NULL,
  `name` varchar(255) NOT NULL,
  `full_name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`iso_3166_2`),
  KEY `countries_name_index` (`name`),
  KEY `countries_full_name_index` (`full_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `database_backups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `database_backups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `kind` varchar(10) NOT NULL,
  `set_id` varchar(255) NOT NULL,
  `path` text DEFAULT NULL,
  `bytes` bigint(20) unsigned DEFAULT NULL,
  `sha256` varchar(64) DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `status` varchar(20) NOT NULL,
  `offsite_status` varchar(20) DEFAULT NULL,
  `offsite_at` timestamp NULL DEFAULT NULL,
  `error` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `database_backups_kind_status_finished_at_index` (`kind`,`status`,`finished_at`),
  KEY `database_backups_set_id_index` (`set_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dnzb_failures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dnzb_failures` (
  `release_id` int(10) unsigned NOT NULL,
  `users_id` int(10) unsigned NOT NULL,
  `failed` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`release_id`,`users_id`),
  KEY `FK_users_df` (`users_id`),
  CONSTRAINT `FK_df_releases` FOREIGN KEY (`release_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `FK_users_df` FOREIGN KEY (`users_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `download_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `download_stats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `searchname` varchar(255) DEFAULT NULL,
  `grabs` int(11) NOT NULL DEFAULT 0,
  `guid` varchar(255) DEFAULT NULL,
  `adddate` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_download_stats_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `connection` mediumtext NOT NULL,
  `queue` mediumtext NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `uuid` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `firewall`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `firewall` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(39) NOT NULL,
  `whitelisted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `firewall_ip_address_unique` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `forum_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `forum_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `accepts_threads` tinyint(1) NOT NULL DEFAULT 0,
  `newest_thread_id` int(10) unsigned DEFAULT NULL,
  `latest_active_thread_id` int(10) unsigned DEFAULT NULL,
  `thread_count` int(11) NOT NULL DEFAULT 0,
  `post_count` int(11) NOT NULL DEFAULT 0,
  `is_private` tinyint(1) NOT NULL DEFAULT 0,
  `thread_approval_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `post_approval_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `_lft` int(10) unsigned NOT NULL DEFAULT 0,
  `_rgt` int(10) unsigned NOT NULL DEFAULT 0,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `color_light_mode` varchar(255) DEFAULT NULL,
  `color_dark_mode` varchar(255) DEFAULT NULL,
  `depth` smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `forum_categories__lft__rgt_parent_id_index` (`_lft`,`_rgt`,`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `forum_posts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `forum_posts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `thread_id` int(10) unsigned NOT NULL,
  `author_id` bigint(20) unsigned NOT NULL,
  `content` mediumtext NOT NULL,
  `post_id` int(10) unsigned DEFAULT NULL,
  `sequence` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `forum_posts_thread_id_index` (`thread_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `forum_threads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `forum_threads` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int(10) unsigned NOT NULL,
  `author_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `pinned` tinyint(1) DEFAULT 0,
  `locked` tinyint(1) DEFAULT 0,
  `first_post_id` int(10) unsigned DEFAULT NULL,
  `last_post_id` int(10) unsigned DEFAULT NULL,
  `reply_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `forum_threads_category_id_index` (`category_id`),
  KEY `ix_forum_threads_author_id` (`author_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `forum_threads_read`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `forum_threads_read` (
  `thread_id` int(10) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  KEY `ix_forum_threads_read_user_thread` (`user_id`,`thread_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `forumpost`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `forumpost` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `forumid` int(11) NOT NULL DEFAULT 1,
  `parentid` int(11) NOT NULL DEFAULT 0,
  `users_id` int(10) unsigned NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` mediumtext NOT NULL,
  `locked` tinyint(1) NOT NULL DEFAULT 0,
  `sticky` tinyint(1) NOT NULL DEFAULT 0,
  `replies` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `parentid` (`parentid`),
  KEY `userid` (`users_id`),
  CONSTRAINT `FK_users_fp` FOREIGN KEY (`users_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `gamesinfo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `gamesinfo` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `asin` varchar(128) DEFAULT NULL,
  `url` varchar(1000) DEFAULT NULL,
  `publisher` varchar(255) DEFAULT NULL,
  `genres_id` int(11) DEFAULT NULL,
  `esrb` varchar(255) DEFAULT NULL,
  `releasedate` datetime DEFAULT NULL,
  `review` varchar(3000) DEFAULT NULL,
  `cover` tinyint(1) NOT NULL DEFAULT 0,
  `backdrop` tinyint(1) NOT NULL DEFAULT 0,
  `trailer` varchar(1000) NOT NULL DEFAULT '',
  `classused` varchar(10) NOT NULL DEFAULT 'steam',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_gamesinfo_asin` (`asin`),
  KEY `ix_gamesinfo_genres_id` (`genres_id`),
  FULLTEXT KEY `ix_title_ft` (`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `gdpr_audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `gdpr_audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `gdpr_request_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `actor_id` bigint(20) unsigned DEFAULT NULL,
  `event` varchar(64) NOT NULL,
  `description` text NOT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_gdpr_audit_request_created` (`gdpr_request_id`,`created_at`),
  KEY `ix_gdpr_audit_user_created` (`user_id`,`created_at`),
  KEY `ix_gdpr_audit_event_created` (`event`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `gdpr_consents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `gdpr_consents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `consent_type` varchar(64) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'granted',
  `policy_version` varchar(64) DEFAULT NULL,
  `consented_at` timestamp NULL DEFAULT NULL,
  `withdrawn_at` timestamp NULL DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent_hash` varchar(64) DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_gdpr_consents_user_type` (`user_id`,`consent_type`),
  KEY `ix_gdpr_consents_type_status` (`consent_type`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `gdpr_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `gdpr_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `requester_username` varchar(255) DEFAULT NULL,
  `requester_email` varchar(255) DEFAULT NULL,
  `type` varchar(32) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `request_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`request_payload`)),
  `response_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`response_payload`)),
  `notes` text DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `export_disk` varchar(255) DEFAULT NULL,
  `export_path` varchar(255) DEFAULT NULL,
  `export_expires_at` timestamp NULL DEFAULT NULL,
  `processed_by` bigint(20) unsigned DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_gdpr_requests_user_type_status` (`user_id`,`type`,`status`),
  KEY `ix_gdpr_requests_status_created` (`status`,`created_at`),
  KEY `ix_gdpr_requests_type_created` (`type`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `genres`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `genres` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `type` int(11) DEFAULT NULL,
  `disabled` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_genres_type_disabled` (`type`,`disabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `grab_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `grab_stats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `username` varchar(255) DEFAULT NULL,
  `grabs` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_grab_stats_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invitations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `token` varchar(64) NOT NULL,
  `email` varchar(255) NOT NULL,
  `invited_by` bigint(20) unsigned NOT NULL,
  `expires_at` timestamp NOT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `used_by` bigint(20) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `metadata` longtext DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invitations_token_unique` (`token`),
  KEY `invitations_invited_by_index` (`invited_by`),
  KEY `invitations_used_by_index` (`used_by`),
  KEY `invitations_token_is_active_index` (`token`,`is_active`),
  KEY `invitations_email_is_active_index` (`email`,`is_active`),
  KEY `invitations_expires_at_index` (`expires_at`),
  KEY `ix_invitations_active_expires` (`is_active`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `logging`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `logging` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `time` datetime DEFAULT NULL,
  `username` varchar(50) DEFAULT NULL,
  `host` varchar(40) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `media_info_probes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `media_info_probes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `releases_id` int(10) unsigned NOT NULL,
  `captured_at` datetime NOT NULL,
  `source_kind` varchar(32) NOT NULL,
  `source_filename` text DEFAULT NULL,
  `source_completeness` varchar(16) NOT NULL,
  `schema_version` smallint(5) unsigned NOT NULL,
  `embedded_title` text DEFAULT NULL,
  `container_format` text DEFAULT NULL,
  `duration_ms` bigint(20) unsigned DEFAULT NULL,
  `overall_bitrate_bps` bigint(20) unsigned DEFAULT NULL,
  `music_tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`music_tags`)),
  `diagnostic_raw` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`diagnostic_raw`)),
  `diagnostic_filtered` tinyint(1) NOT NULL DEFAULT 0,
  `diagnostic_truncated` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `media_info_probes_releases_id_captured_at_index` (`releases_id`,`captured_at`),
  CONSTRAINT `media_info_probes_releases_id_foreign` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `media_info_tracks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `media_info_tracks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `media_info_probe_id` bigint(20) unsigned NOT NULL,
  `type` varchar(16) NOT NULL,
  `track_index` smallint(5) unsigned NOT NULL,
  `source_id` text DEFAULT NULL,
  `stream_order` text DEFAULT NULL,
  `title` text DEFAULT NULL,
  `language` text DEFAULT NULL,
  `format` text DEFAULT NULL,
  `codec` text DEFAULT NULL,
  `is_default` tinyint(1) DEFAULT NULL,
  `is_forced` tinyint(1) DEFAULT NULL,
  `duration_ms` bigint(20) unsigned DEFAULT NULL,
  `bitrate_bps` bigint(20) unsigned DEFAULT NULL,
  `width` int(10) unsigned DEFAULT NULL,
  `height` int(10) unsigned DEFAULT NULL,
  `aspect_ratio` text DEFAULT NULL,
  `frame_rate` decimal(10,3) DEFAULT NULL,
  `profile` text DEFAULT NULL,
  `bit_depth` smallint(5) unsigned DEFAULT NULL,
  `hdr_format` text DEFAULT NULL,
  `color_primaries` text DEFAULT NULL,
  `transfer_characteristics` text DEFAULT NULL,
  `matrix_coefficients` text DEFAULT NULL,
  `channels` smallint(5) unsigned DEFAULT NULL,
  `channel_layout` text DEFAULT NULL,
  `sample_rate_hz` int(10) unsigned DEFAULT NULL,
  `diagnostic_raw` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`diagnostic_raw`)),
  `diagnostic_filtered` tinyint(1) NOT NULL DEFAULT 0,
  `diagnostic_truncated` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `media_info_tracks_media_info_probe_id_type_track_index_unique` (`media_info_probe_id`,`type`,`track_index`),
  CONSTRAINT `media_info_tracks_media_info_probe_id_foreign` FOREIGN KEY (`media_info_probe_id`) REFERENCES `media_info_probes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `media_infos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `media_infos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `releases_id` bigint(20) unsigned NOT NULL,
  `movie_name` varchar(255) DEFAULT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `unique_id` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_media_infos_releases_id` (`releases_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `missed_parts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `missed_parts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `numberid` bigint(20) unsigned NOT NULL,
  `groups_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'FK to groups.id',
  `attempts` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_missed_parts_numberid_groupsid` (`numberid`,`groups_id`),
  KEY `ix_missed_parts_groupid_attempts` (`groups_id`,`attempts`),
  KEY `ix_missed_parts_numberid_groupsid_attempts` (`numberid`,`groups_id`,`attempts`),
  KEY `ix_missed_parts_attempts` (`attempts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `model_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_permissions` (
  `permission_id` int(10) unsigned NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  KEY `model_has_permissions_model_type_model_id_index` (`model_type`,`model_id`),
  CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `model_has_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_roles` (
  `role_id` int(10) unsigned NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  KEY `model_has_roles_model_type_model_id_index` (`model_type`,`model_id`),
  CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `movieinfo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `movieinfo` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `imdbid` varchar(100) NOT NULL,
  `tmdbid` int(10) unsigned NOT NULL DEFAULT 0,
  `traktid` int(10) unsigned NOT NULL DEFAULT 0,
  `title` varchar(255) NOT NULL DEFAULT '',
  `tagline` varchar(1024) NOT NULL DEFAULT '',
  `rating` varchar(4) NOT NULL DEFAULT '',
  `rtrating` varchar(10) NOT NULL DEFAULT '' COMMENT 'RottenTomatoes rating score',
  `plot` varchar(1024) NOT NULL DEFAULT '',
  `year` varchar(4) NOT NULL DEFAULT '',
  `genre` varchar(64) NOT NULL DEFAULT '',
  `type` varchar(32) NOT NULL DEFAULT '',
  `director` varchar(64) NOT NULL DEFAULT '',
  `actors` varchar(2000) NOT NULL DEFAULT '',
  `language` varchar(64) NOT NULL DEFAULT '',
  `cover` tinyint(1) NOT NULL DEFAULT 0,
  `backdrop` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `trailer` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_movieinfo_imdbid` (`imdbid`),
  KEY `ix_movieinfo_title` (`title`),
  KEY `ix_movieinfo_tmdbid` (`tmdbid`),
  KEY `ix_movieinfo_traktid` (`traktid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `musicinfo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `musicinfo` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `asin` varchar(128) DEFAULT NULL,
  `url` varchar(1000) DEFAULT NULL,
  `salesrank` int(10) unsigned DEFAULT NULL,
  `artist` varchar(255) DEFAULT NULL,
  `publisher` varchar(255) DEFAULT NULL,
  `releasedate` datetime DEFAULT NULL,
  `review` varchar(3000) DEFAULT NULL,
  `year` varchar(4) NOT NULL,
  `genres_id` int(11) DEFAULT NULL,
  `tracks` varchar(3000) DEFAULT NULL,
  `cover` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_musicinfo_asin` (`asin`),
  KEY `ix_musicinfo_genres_id` (`genres_id`),
  FULLTEXT KEY `ix_musicinfo_artist_title_ft` (`artist`,`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `networks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `networks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_networks_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_artifacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_artifacts` (
  `digest` char(64) NOT NULL,
  `bytes` bigint(20) unsigned NOT NULL,
  `retained_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`digest`),
  KEY `obfuscation_recovery_artifacts_retained_at_index` (`retained_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `budget_id` bigint(20) unsigned NOT NULL,
  `groups_id` int(10) unsigned NOT NULL DEFAULT 0,
  `profile` varchar(40) NOT NULL DEFAULT 'unknown',
  `request_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `physical_attempt` tinyint(3) unsigned NOT NULL,
  `token` uuid NOT NULL,
  `stage` varchar(24) NOT NULL DEFAULT 'download',
  `provider` varchar(64) DEFAULT NULL,
  `outcome` varchar(64) NOT NULL DEFAULT 'reserved',
  `reserved_bytes` bigint(20) unsigned NOT NULL,
  `debited_bytes` bigint(20) unsigned NOT NULL,
  `decoded_bytes` bigint(20) unsigned DEFAULT NULL,
  `plaintext_bytes` bigint(20) unsigned DEFAULT NULL,
  `encrypted_bytes` bigint(20) unsigned DEFAULT NULL,
  `socket_received_bytes` bigint(20) unsigned DEFAULT NULL,
  `transmitted_bytes` bigint(20) unsigned DEFAULT NULL,
  `connections_opened` int(10) unsigned NOT NULL DEFAULT 0,
  `receive_window` int(10) unsigned DEFAULT NULL,
  `buffered_at_close` int(10) unsigned DEFAULT NULL,
  `elapsed_milliseconds` int(10) unsigned DEFAULT NULL,
  `late_receive_allowance` bigint(20) unsigned NOT NULL DEFAULT 0,
  `cache_hit` tinyint(1) NOT NULL DEFAULT 0,
  `connected_at` timestamp(6) NULL DEFAULT NULL,
  `closed_at` timestamp(6) NULL DEFAULT NULL,
  `settled_at` timestamp(6) NULL DEFAULT NULL,
  `failure_phase` varchar(64) DEFAULT NULL,
  `compacted_at` timestamp(6) NULL DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  `handoff` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`handoff`)),
  `handoff_conflict` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `recovery_physical_attempt` (`budget_id`,`request_digest`,`physical_attempt`),
  UNIQUE KEY `obfuscation_recovery_attempts_token_unique` (`token`),
  KEY `recovery_attempt_expiry` (`outcome`,`created_at`),
  KEY `recovery_attempt_compaction` (`compacted_at`,`created_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_budget_owners`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_budget_owners` (
  `owner_digest` char(64) NOT NULL,
  `root_digest` char(64) NOT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`owner_digest`),
  KEY `obfuscation_recovery_budget_owners_root_digest_index` (`root_digest`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_budgets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_budgets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `owner_digest` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `purpose` varchar(32) NOT NULL,
  `debited_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `recovery_budget_owner` (`owner_digest`,`purpose`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_bundles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_bundles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `detail_retired_at` timestamp NULL DEFAULT NULL,
  `inactive_since` timestamp(6) NULL DEFAULT NULL,
  `owner_digest` char(64) NOT NULL,
  `kind` varchar(16) NOT NULL DEFAULT 'posting',
  `publication_id` bigint(20) unsigned DEFAULT NULL,
  `profile` varchar(40) DEFAULT NULL,
  `groups_id` int(10) unsigned DEFAULT NULL,
  `source_epoch` uuid DEFAULT NULL,
  `capture_generation` bigint(20) unsigned DEFAULT NULL,
  `key_digest` char(64) DEFAULT NULL,
  `revision` bigint(20) unsigned NOT NULL DEFAULT 1,
  `start_ms` bigint(20) unsigned DEFAULT NULL,
  `end_ms` bigint(20) unsigned DEFAULT NULL,
  `membership_changed_at` timestamp(6) NULL DEFAULT NULL,
  `state` varchar(32) NOT NULL DEFAULT 'collecting',
  `reason` varchar(64) DEFAULT NULL,
  `snapshot_digest` char(64) DEFAULT NULL,
  `candidate_runs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`candidate_runs`)),
  `merged_into` bigint(20) unsigned DEFAULT NULL,
  `index_message_id` varchar(255) DEFAULT NULL,
  `index_digest` char(64) DEFAULT NULL,
  `inventory` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`inventory`)),
  `construction_targets` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`construction_targets`)),
  `sealed_plan` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`sealed_plan`)),
  `manifest_verified_at` timestamp(6) NULL DEFAULT NULL,
  `coverage_evidence` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`coverage_evidence`)),
  `claim_token` uuid DEFAULT NULL,
  `claim_expires_at` timestamp(6) NULL DEFAULT NULL,
  `next_action_at` timestamp(6) NULL DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `obfuscation_recovery_bundles_owner_digest_unique` (`owner_digest`),
  KEY `recovery_bundle_due` (`state`,`next_action_at`,`id`),
  KEY `recovery_bundle_window` (`groups_id`,`profile`,`start_ms`),
  KEY `obfuscation_recovery_bundles_publication_id_index` (`publication_id`),
  KEY `obfuscation_recovery_bundles_merged_into_index` (`merged_into`),
  KEY `recovery_frontier_candidates` (`kind`,`state`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_catalog`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_catalog` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `aggregate_digest` char(64) DEFAULT NULL,
  `requests` bigint(20) unsigned NOT NULL DEFAULT 1,
  `unknown_response_sizes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `publication_id` bigint(20) unsigned NOT NULL,
  `provider` varchar(255) NOT NULL,
  `kind` varchar(16) NOT NULL,
  `outcome` varchar(32) NOT NULL,
  `http_status` smallint(5) unsigned DEFAULT NULL,
  `response_bytes` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp(6) NOT NULL,
  `finished_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `obfuscation_recovery_catalog_aggregate_digest_unique` (`aggregate_digest`),
  KEY `recovery_catalog_publication` (`publication_id`,`created_at`),
  KEY `recovery_catalog_compaction` (`aggregate_digest`,`created_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_controls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_controls` (
  `scope` varchar(64) NOT NULL,
  `fingerprint` char(64) NOT NULL,
  `epoch` uuid NOT NULL,
  `generation` bigint(20) unsigned NOT NULL DEFAULT 1,
  `updated_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_coverage`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_coverage` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `scope_digest` char(64) NOT NULL,
  `source_epoch` varchar(64) NOT NULL,
  `groups_id` int(10) unsigned NOT NULL,
  `capture_generation` bigint(20) unsigned NOT NULL,
  `kind` varchar(16) NOT NULL,
  `direction` varchar(16) NOT NULL,
  `first_article` bigint(20) unsigned NOT NULL,
  `last_article` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `recovery_positive_coverage` (`scope_digest`,`kind`,`direction`,`first_article`,`last_article`),
  KEY `recovery_positive_scope` (`source_epoch`,`groups_id`,`capture_generation`,`kind`,`first_article`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_dirty`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_dirty` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `scope_digest` char(64) NOT NULL,
  `source_epoch` varchar(64) NOT NULL,
  `groups_id` int(10) unsigned NOT NULL,
  `capture_generation` bigint(20) unsigned NOT NULL,
  `profile` varchar(40) NOT NULL,
  `partition_value` varchar(64) NOT NULL,
  `bucket` bigint(20) unsigned NOT NULL DEFAULT 0,
  `first_ms` bigint(20) unsigned NOT NULL,
  `last_ms` bigint(20) unsigned NOT NULL,
  `version` bigint(20) unsigned NOT NULL DEFAULT 1,
  `membership_changed_at` timestamp(6) NOT NULL,
  `next_action_at` timestamp(6) NOT NULL,
  `claim_token` uuid DEFAULT NULL,
  `claim_expires_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `recovery_dirty_cell` (`scope_digest`,`bucket`),
  KEY `recovery_dirty_due` (`next_action_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_dispatch`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_dispatch` (
  `scope_digest` char(64) NOT NULL,
  `stage` varchar(24) NOT NULL,
  `last_dispatched_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`scope_digest`),
  KEY `recovery_dispatch_order` (`stage`,`last_dispatched_at`,`scope_digest`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_evidence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_evidence` (
  `message_id_digest` char(64) NOT NULL,
  `message_id` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `prefix_evidence` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`prefix_evidence`)),
  `full_evidence` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`full_evidence`)),
  `fingerprints` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`fingerprints`)),
  `state` varchar(24) NOT NULL DEFAULT 'valid',
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`message_id_digest`),
  KEY `recovery_evidence_expiry` (`created_at`,`message_id_digest`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_expired_headers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_expired_headers` (
  `source_epoch` varchar(64) NOT NULL,
  `groups_id` int(10) unsigned NOT NULL,
  `message_id_digest` char(64) NOT NULL,
  `first_observed_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`source_epoch`,`groups_id`,`message_id_digest`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_files` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bundle_id` bigint(20) unsigned NOT NULL,
  `revision` bigint(20) unsigned NOT NULL,
  `groups_id` int(10) unsigned NOT NULL,
  `profile` varchar(40) NOT NULL,
  `run_digest` char(64) NOT NULL,
  `advertised_total` int(10) unsigned DEFAULT NULL,
  `expected_total` int(10) unsigned DEFAULT NULL,
  `observed_count` int(10) unsigned NOT NULL,
  `start_ms` bigint(20) unsigned NOT NULL,
  `end_ms` bigint(20) unsigned NOT NULL,
  `boundary_first` int(10) unsigned DEFAULT NULL,
  `boundary_last` int(10) unsigned DEFAULT NULL,
  `state` varchar(32) NOT NULL,
  `reason` varchar(64) DEFAULT NULL,
  `role` varchar(16) DEFAULT NULL,
  `membership_digest` char(64) DEFAULT NULL,
  `file_id` char(32) DEFAULT NULL,
  `decoded_bytes` bigint(20) unsigned DEFAULT NULL,
  `file_md5` char(32) DEFAULT NULL,
  `prefix_md5` char(32) DEFAULT NULL,
  `source_filename` blob DEFAULT NULL,
  `display_filename` varchar(240) DEFAULT NULL,
  `declared_format` varchar(24) DEFAULT NULL,
  `observed_format` varchar(24) DEFAULT NULL,
  `archive_ordinal` tinyint(3) unsigned DEFAULT NULL,
  `anchor_evidence` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`anchor_evidence`)),
  `terminal_evidence` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`terminal_evidence`)),
  `contained_observations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`contained_observations`)),
  `identity_evidence` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`identity_evidence`)),
  `media_evidence` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`media_evidence`)),
  `enrichment_outcome` varchar(48) DEFAULT NULL,
  `enrichment_reason` varchar(48) DEFAULT NULL,
  `enrichment_debit` bigint(20) unsigned NOT NULL DEFAULT 0,
  `enrichment_selected_at` timestamp NULL DEFAULT NULL,
  `detail_retired_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `recovery_file_revision` (`bundle_id`,`revision`,`run_digest`),
  KEY `recovery_file_retirement` (`bundle_id`,`detail_retired_at`,`id`),
  KEY `recovery_file_discovery` (`groups_id`,`profile`,`advertised_total`,`start_ms`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_frontier_allowances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_frontier_allowances` (
  `owner_digest` char(64) NOT NULL,
  `normal_bytes` bigint(20) unsigned NOT NULL,
  `history` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`history`)),
  `granted_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`owner_digest`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_frontier_conflicts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_frontier_conflicts` (
  `identity` char(64) NOT NULL,
  `scope_digest` char(64) NOT NULL,
  `kind` varchar(16) NOT NULL,
  `first_article` bigint(20) unsigned NOT NULL,
  `last_article` bigint(20) unsigned NOT NULL,
  `evidence_version` smallint(5) unsigned NOT NULL DEFAULT 1,
  `span_bucket` tinyint(3) unsigned GENERATED ALWAYS AS (case when cast(`last_article` as signed) - cast(`first_article` as signed) < 16 then 0 when cast(`last_article` as signed) - cast(`first_article` as signed) < 256 then 1 when cast(`last_article` as signed) - cast(`first_article` as signed) < 4096 then 2 when cast(`last_article` as signed) - cast(`first_article` as signed) < 65536 then 3 when cast(`last_article` as signed) - cast(`first_article` as signed) < 1048576 then 4 when cast(`last_article` as signed) - cast(`first_article` as signed) < 16777216 then 5 when cast(`last_article` as signed) - cast(`first_article` as signed) < 268435456 then 6 when cast(`last_article` as signed) - cast(`first_article` as signed) < 4294967296 then 7 when cast(`last_article` as signed) - cast(`first_article` as signed) < 68719476736 then 8 when cast(`last_article` as signed) - cast(`first_article` as signed) < 1099511627776 then 9 when cast(`last_article` as signed) - cast(`first_article` as signed) < 17592186044416 then 10 when cast(`last_article` as signed) - cast(`first_article` as signed) < 281474976710656 then 11 when cast(`last_article` as signed) - cast(`first_article` as signed) < 4503599627370496 then 12 when cast(`last_article` as signed) - cast(`first_article` as signed) < 72057594037927936 then 13 when cast(`last_article` as signed) - cast(`first_article` as signed) < 1152921504606846976 then 14 else 15 end) VIRTUAL,
  PRIMARY KEY (`identity`),
  KEY `recovery_frontier_conflict_range` (`scope_digest`,`kind`,`first_article`,`last_article`),
  KEY `recovery_conflict_end` (`scope_digest`,`kind`,`last_article`,`first_article`),
  KEY `recovery_conflict_span` (`scope_digest`,`kind`,`span_bucket`,`first_article`,`last_article`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_frontier_installs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_frontier_installs` (
  `attempt_id` bigint(20) unsigned NOT NULL,
  `request_id` bigint(20) unsigned NOT NULL,
  `work_id` bigint(20) unsigned NOT NULL,
  `claim_token` uuid NOT NULL,
  `installed_at` timestamp(6) NULL DEFAULT NULL,
  `evidence_digest` char(64) DEFAULT NULL,
  PRIMARY KEY (`attempt_id`),
  KEY `recovery_frontier_install_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_frontier_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_frontier_members` (
  `bundle_id` bigint(20) unsigned NOT NULL,
  `revision` bigint(20) unsigned NOT NULL,
  `article_number` bigint(20) unsigned NOT NULL,
  `postdate` datetime NOT NULL,
  `observation_digest` char(64) NOT NULL,
  `embedded_timestamp_ms` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`bundle_id`,`revision`,`article_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_frontier_policy`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_frontier_policy` (
  `policy` varchar(48) NOT NULL,
  `cutover_at` timestamp(6) NOT NULL,
  `last_attempt_id` bigint(20) unsigned NOT NULL,
  `last_request_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`policy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_frontier_progress`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_frontier_progress` (
  `scope` varchar(64) NOT NULL,
  `cursor` bigint(20) unsigned NOT NULL DEFAULT 0,
  `due_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_frontier_ranges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_frontier_ranges` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `identity` char(64) NOT NULL,
  `scope_digest` char(64) NOT NULL,
  `evidence_version` smallint(5) unsigned NOT NULL,
  `first_article` bigint(20) unsigned NOT NULL,
  `last_article` bigint(20) unsigned NOT NULL,
  `head_observed` tinyint(1) NOT NULL,
  `exhaustive` tinyint(1) NOT NULL,
  `points` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`points`)),
  `observed_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `obfuscation_recovery_frontier_ranges_identity_unique` (`identity`),
  KEY `recovery_evidence_range` (`scope_digest`,`evidence_version`,`first_article`,`last_article`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_frontier_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_frontier_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bundle_id` bigint(20) unsigned NOT NULL,
  `budget_owner` char(64) NOT NULL,
  `groups_id` int(10) unsigned NOT NULL,
  `source_epoch` varchar(64) NOT NULL,
  `capture_generation` bigint(20) unsigned NOT NULL,
  `evidence_version` smallint(5) unsigned NOT NULL,
  `requested_first` bigint(20) unsigned NOT NULL,
  `requested_last` bigint(20) unsigned NOT NULL,
  `outcome` varchar(48) NOT NULL DEFAULT 'pending',
  `expires_at` timestamp(6) NOT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  `superseded_by` bigint(20) unsigned DEFAULT NULL,
  `reserved_attempt_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `obfuscation_recovery_frontier_requests_bundle_id_unique` (`bundle_id`),
  KEY `recovery_frontier_spend` (`budget_owner`,`capture_generation`),
  KEY `recovery_frontier_history` (`source_epoch`,`groups_id`,`evidence_version`,`requested_first`,`id`),
  KEY `recovery_frontier_successor` (`superseded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_frontier_targets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_frontier_targets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` bigint(20) unsigned NOT NULL,
  `bundle_id` bigint(20) unsigned NOT NULL,
  `revision` bigint(20) unsigned NOT NULL,
  `plan_digest` char(64) DEFAULT NULL,
  `capture_generation` bigint(20) unsigned NOT NULL,
  `first_article` bigint(20) unsigned NOT NULL,
  `last_article` bigint(20) unsigned NOT NULL,
  `envelope` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`envelope`)),
  `outcome` varchar(48) NOT NULL DEFAULT 'pending',
  `authority_digest` char(64) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `recovery_frontier_target` (`request_id`,`bundle_id`,`revision`,`first_article`,`last_article`,`authority_digest`),
  KEY `recovery_frontier_candidate` (`bundle_id`,`revision`,`outcome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_frontiers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_frontiers` (
  `scope_digest` char(64) NOT NULL,
  `article_number` bigint(20) unsigned NOT NULL,
  `postdate` datetime NOT NULL,
  `head_observed` tinyint(1) NOT NULL,
  `evidence_version` smallint(5) unsigned NOT NULL DEFAULT 1,
  `observation_digest` char(64) DEFAULT NULL,
  PRIMARY KEY (`scope_digest`,`article_number`),
  KEY `recovery_frontier_lookup` (`scope_digest`,`head_observed`,`postdate`,`article_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_gaps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_gaps` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bundle_id` bigint(20) unsigned NOT NULL,
  `groups_id` int(10) unsigned NOT NULL,
  `source_epoch` varchar(64) NOT NULL,
  `capture_generation` bigint(20) unsigned NOT NULL,
  `requested_first` bigint(20) unsigned NOT NULL,
  `requested_last` bigint(20) unsigned NOT NULL,
  `outcome` varchar(48) NOT NULL DEFAULT 'pending',
  `retries` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `expires_at` timestamp(6) NOT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `recovery_gap_range` (`groups_id`,`source_epoch`,`capture_generation`,`requested_first`,`requested_last`),
  UNIQUE KEY `obfuscation_recovery_gaps_bundle_id_unique` (`bundle_id`),
  KEY `recovery_gap_overlap` (`groups_id`,`source_epoch`,`capture_generation`,`requested_first`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_headers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_headers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_epoch` varchar(64) NOT NULL,
  `groups_id` int(10) unsigned NOT NULL,
  `capture_generation` bigint(20) unsigned NOT NULL,
  `message_id` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `source_message_id` blob NOT NULL,
  `message_id_digest` char(64) NOT NULL,
  `article_number` bigint(20) unsigned NOT NULL,
  `raw_subject` blob NOT NULL,
  `parsed_name` blob DEFAULT NULL,
  `poster_identity` blob NOT NULL,
  `source_date` varchar(128) NOT NULL,
  `postdate` datetime NOT NULL,
  `xref` blob DEFAULT NULL,
  `advertised_bytes` bigint(20) unsigned NOT NULL,
  `original_part` int(10) unsigned DEFAULT NULL,
  `advertised_total` int(10) unsigned DEFAULT NULL,
  `embedded_timestamp_ms` bigint(20) unsigned NOT NULL,
  `profile` varchar(40) NOT NULL,
  `key_digest` char(64) NOT NULL,
  `disposition` varchar(48) NOT NULL DEFAULT 'eligible',
  `metadata_conflict` tinyint(1) NOT NULL DEFAULT 0,
  `bundle_id` bigint(20) unsigned DEFAULT NULL,
  `revision` bigint(20) unsigned DEFAULT NULL,
  `capture_token` char(32) DEFAULT NULL,
  `first_observed_at` timestamp(6) NOT NULL,
  `last_observed_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `recovery_header_identity` (`source_epoch`,`groups_id`,`message_id_digest`),
  KEY `recovery_media_discovery` (`source_epoch`,`groups_id`,`advertised_total`,`embedded_timestamp_ms`,`message_id`),
  KEY `recovery_rar_discovery` (`source_epoch`,`groups_id`,`key_digest`,`embedded_timestamp_ms`,`message_id`),
  KEY `recovery_header_expiry` (`first_observed_at`,`id`),
  KEY `recovery_header_membership` (`bundle_id`,`revision`,`id`),
  KEY `recovery_header_article` (`source_epoch`,`groups_id`,`article_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_housekeeping`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_housekeeping` (
  `scope` varchar(32) NOT NULL,
  `after_key` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_index_owners`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_index_owners` (
  `index_digest` char(64) NOT NULL,
  `owner_digest` char(64) NOT NULL,
  `construction_targets` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`construction_targets`)),
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`index_digest`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_metrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_metrics` (
  `series_digest` char(64) NOT NULL,
  `groups_id` int(10) unsigned NOT NULL,
  `profile` varchar(40) NOT NULL,
  `reason` varchar(64) NOT NULL,
  `metric` varchar(40) NOT NULL,
  `value` bigint(20) unsigned NOT NULL DEFAULT 0,
  `updated_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`series_digest`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_provider_backoff`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_provider_backoff` (
  `provider_digest` char(64) NOT NULL,
  `blocked_until` timestamp(6) NOT NULL,
  `reason` varchar(64) NOT NULL,
  `updated_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`provider_digest`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_publications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_publications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `canonical_bundle_id` bigint(20) unsigned DEFAULT NULL,
  `canonical_revision` bigint(20) unsigned DEFAULT NULL,
  `identity` char(64) NOT NULL,
  `index_identity` char(64) NOT NULL,
  `index_message_id` varchar(255) NOT NULL,
  `set_id` char(32) NOT NULL,
  `plan_digest` char(64) NOT NULL,
  `collection_projection` binary(20) NOT NULL,
  `collections_id` int(10) unsigned DEFAULT NULL,
  `releases_id` int(10) unsigned DEFAULT NULL,
  `guid` varchar(40) DEFAULT NULL,
  `profile` varchar(40) NOT NULL,
  `group_name` varchar(255) NOT NULL,
  `source_epoch` varchar(64) NOT NULL,
  `state` varchar(32) NOT NULL DEFAULT 'registered',
  `initialization_state` varchar(32) NOT NULL DEFAULT 'pending',
  `ordering_mode` varchar(48) NOT NULL,
  `inventory_scope` varchar(48) NOT NULL,
  `protected_files` int(10) unsigned NOT NULL,
  `planned_files` int(10) unsigned NOT NULL,
  `planned_parts` bigint(20) unsigned NOT NULL,
  `materialized_parts` bigint(20) unsigned NOT NULL DEFAULT 0,
  `reconciliation_cursor` bigint(20) unsigned NOT NULL DEFAULT 0,
  `cleanup_outcome` varchar(48) DEFAULT NULL,
  `sealed_plan` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`sealed_plan`)),
  `manifest_digest` char(64) NOT NULL,
  `nzb_digest` char(64) DEFAULT NULL,
  `survivor_membership` varchar(48) DEFAULT NULL,
  `survivor_nzb_digest` char(64) DEFAULT NULL,
  `evidence_digest` char(64) DEFAULT NULL,
  `head_membership` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`head_membership`)),
  `enrichment_next_attempt_at` timestamp NULL DEFAULT NULL,
  `identity_outcome` varchar(48) NOT NULL DEFAULT 'unresolved',
  `nfo_outcome` varchar(48) DEFAULT NULL,
  `identity_scope` varchar(48) NOT NULL DEFAULT 'unknown',
  `enrichment_outcome` varchar(48) DEFAULT NULL,
  `multi_media_inventory` tinyint(1) NOT NULL DEFAULT 0,
  `reason` varchar(64) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `detail_retired_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `obfuscation_recovery_publications_identity_unique` (`identity`),
  UNIQUE KEY `obfuscation_recovery_publications_index_identity_unique` (`index_identity`),
  UNIQUE KEY `obfuscation_recovery_publications_collection_projection_unique` (`collection_projection`),
  UNIQUE KEY `obfuscation_recovery_publications_collections_id_unique` (`collections_id`),
  KEY `recovery_publication_state` (`state`,`id`),
  KEY `obfuscation_recovery_publications_canonical_bundle_id_index` (`canonical_bundle_id`),
  KEY `obfuscation_recovery_publications_releases_id_index` (`releases_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_references`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_references` (
  `identity` char(64) NOT NULL,
  `owner_type` varchar(16) NOT NULL,
  `owner_key` varchar(64) NOT NULL,
  `resource_type` varchar(16) NOT NULL,
  `resource_digest` char(64) NOT NULL,
  PRIMARY KEY (`identity`),
  KEY `recovery_reference_owner` (`owner_type`,`owner_key`),
  KEY `recovery_reference_resource` (`resource_type`,`resource_digest`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_identity` char(64) NOT NULL,
  `scope_digest` char(64) NOT NULL,
  `source_epoch` varchar(64) NOT NULL,
  `groups_id` int(10) unsigned NOT NULL,
  `capture_generation` bigint(20) unsigned NOT NULL,
  `profile` varchar(40) NOT NULL,
  `partition_value` varchar(64) NOT NULL,
  `start_ms` bigint(20) unsigned NOT NULL,
  `end_ms` bigint(20) unsigned NOT NULL,
  `observed_count` int(10) unsigned NOT NULL,
  `state` varchar(32) NOT NULL,
  `membership_digest` char(64) NOT NULL,
  `summary` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`summary`)),
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `bundle_dirty` tinyint(1) NOT NULL DEFAULT 1,
  `oldest_observed_at` timestamp(6) NOT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `obfuscation_recovery_runs_run_identity_unique` (`run_identity`),
  KEY `recovery_run_scope` (`scope_digest`,`active`,`start_ms`),
  KEY `recovery_run_components` (`source_epoch`,`groups_id`,`capture_generation`,`profile`,`active`,`start_ms`),
  KEY `recovery_run_due` (`bundle_dirty`,`updated_at`,`id`),
  KEY `recovery_run_expiry` (`oldest_observed_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_scan_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_scan_batches` (
  `scan_id` uuid NOT NULL,
  `context_digest` char(64) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  PRIMARY KEY (`scan_id`),
  KEY `recovery_batch_expiry` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_scan_windows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_scan_windows` (
  `scan_id` uuid NOT NULL,
  `groups_id` int(10) unsigned NOT NULL,
  `source_epoch` varchar(64) NOT NULL,
  `capture_generation` bigint(20) unsigned NOT NULL,
  `requested_first` bigint(20) unsigned NOT NULL,
  `requested_last` bigint(20) unsigned NOT NULL,
  `gap_cursor` bigint(20) unsigned DEFAULT NULL,
  `frontier_last` bigint(20) unsigned DEFAULT NULL,
  `next_gap_at` timestamp(6) NULL DEFAULT NULL,
  `expires_at` timestamp(6) NOT NULL,
  `created_at` timestamp(6) NOT NULL,
  `span_bucket` tinyint(3) unsigned GENERATED ALWAYS AS (case when cast(`requested_last` as signed) - cast(`requested_first` as signed) < 16 then 0 when cast(`requested_last` as signed) - cast(`requested_first` as signed) < 256 then 1 when cast(`requested_last` as signed) - cast(`requested_first` as signed) < 4096 then 2 when cast(`requested_last` as signed) - cast(`requested_first` as signed) < 65536 then 3 when cast(`requested_last` as signed) - cast(`requested_first` as signed) < 1048576 then 4 when cast(`requested_last` as signed) - cast(`requested_first` as signed) < 16777216 then 5 when cast(`requested_last` as signed) - cast(`requested_first` as signed) < 268435456 then 6 when cast(`requested_last` as signed) - cast(`requested_first` as signed) < 4294967296 then 7 when cast(`requested_last` as signed) - cast(`requested_first` as signed) < 68719476736 then 8 when cast(`requested_last` as signed) - cast(`requested_first` as signed) < 1099511627776 then 9 when cast(`requested_last` as signed) - cast(`requested_first` as signed) < 17592186044416 then 10 when cast(`requested_last` as signed) - cast(`requested_first` as signed) < 281474976710656 then 11 when cast(`requested_last` as signed) - cast(`requested_first` as signed) < 4503599627370496 then 12 when cast(`requested_last` as signed) - cast(`requested_first` as signed) < 72057594037927936 then 13 when cast(`requested_last` as signed) - cast(`requested_first` as signed) < 1152921504606846976 then 14 else 15 end) VIRTUAL,
  PRIMARY KEY (`scan_id`),
  KEY `recovery_window_due` (`next_gap_at`,`scan_id`),
  KEY `recovery_window_scope` (`groups_id`,`source_epoch`,`capture_generation`,`requested_first`),
  KEY `recovery_window_span` (`groups_id`,`source_epoch`,`capture_generation`,`span_bucket`,`requested_first`,`requested_last`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_scans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_scans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `scan_id` uuid NOT NULL,
  `groups_id` int(10) unsigned NOT NULL,
  `source_epoch` varchar(64) NOT NULL,
  `capture_generation` bigint(20) unsigned NOT NULL,
  `requested_first` bigint(20) unsigned NOT NULL,
  `requested_last` bigint(20) unsigned NOT NULL,
  `chunk_ordinal` int(10) unsigned NOT NULL,
  `expected_chunks` int(10) unsigned NOT NULL,
  `direction` varchar(16) NOT NULL,
  `capture_outcome` varchar(32) NOT NULL,
  `ordinary_outcome` varchar(32) NOT NULL DEFAULT 'unknown',
  `ordinary_report` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`ordinary_report`)),
  `coverage_verified_at` timestamp(6) NULL DEFAULT NULL,
  `compacted_at` timestamp(6) NULL DEFAULT NULL,
  `returned_articles` bigint(20) unsigned DEFAULT NULL,
  `missing_articles` bigint(20) unsigned DEFAULT NULL,
  `returned_ranges` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`returned_ranges`)),
  `missing_ranges` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`missing_ranges`)),
  `date_points` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`date_points`)),
  `first_postdate` datetime DEFAULT NULL,
  `last_postdate` datetime DEFAULT NULL,
  `earliest_date_article` bigint(20) unsigned DEFAULT NULL,
  `latest_date_article` bigint(20) unsigned DEFAULT NULL,
  `date_order_consistent` tinyint(1) NOT NULL DEFAULT 1,
  `complete` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp(6) NOT NULL,
  `evidence_version` smallint(5) unsigned NOT NULL DEFAULT 1,
  `date_conflicts` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`date_conflicts`)),
  `invalid_date_articles` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`invalid_date_articles`)),
  PRIMARY KEY (`id`),
  UNIQUE KEY `recovery_scan_chunk` (`scan_id`,`chunk_ordinal`),
  KEY `recovery_scan_coverage` (`source_epoch`,`groups_id`,`capture_generation`,`requested_first`,`requested_last`),
  KEY `recovery_scan_expiry` (`created_at`,`id`),
  KEY `recovery_scan_compaction` (`compacted_at`,`created_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_slots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_slots` (
  `id` tinyint(3) unsigned NOT NULL,
  `worker_token` uuid DEFAULT NULL,
  `owner_host` char(64) DEFAULT NULL,
  `owner_pid` int(10) unsigned DEFAULT NULL,
  `owner_started` varchar(32) DEFAULT NULL,
  `acquired_at` timestamp(6) NULL DEFAULT NULL,
  `expires_at` timestamp(6) NULL DEFAULT NULL,
  `attempt_id` bigint(20) unsigned DEFAULT NULL,
  `owner_machine` char(64) DEFAULT NULL,
  `owner_boot` char(64) DEFAULT NULL,
  `owner_namespace` char(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `recovery_one_slot_per_worker` (`owner_host`,`owner_pid`,`owner_started`),
  UNIQUE KEY `obfuscation_recovery_slots_worker_token_unique` (`worker_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_targets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_targets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `publication_id` bigint(20) unsigned NOT NULL,
  `file_id` char(32) NOT NULL,
  `message_id` varchar(255) NOT NULL,
  `request_digest` char(64) NOT NULL,
  `ordinal` tinyint(3) unsigned NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'pending',
  `outcome` varchar(48) DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `recovery_target_identity` (`publication_id`,`request_digest`),
  KEY `recovery_target_file` (`publication_id`,`file_id`),
  KEY `recovery_target_resume` (`outcome`,`status`,`updated_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_traffic`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_traffic` (
  `series_digest` char(64) NOT NULL,
  `day` date NOT NULL,
  `groups_id` int(10) unsigned NOT NULL,
  `profile` varchar(40) NOT NULL,
  `purpose` varchar(32) NOT NULL,
  `provider` varchar(64) NOT NULL,
  `outcome` varchar(64) NOT NULL,
  `failure_phase` varchar(64) NOT NULL,
  `attempts` bigint(20) unsigned NOT NULL DEFAULT 0,
  `unknown_transport_counters` bigint(20) unsigned NOT NULL DEFAULT 0,
  `reserved_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `debited_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `decoded_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `plaintext_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `encrypted_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `socket_received_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `transmitted_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `connections_opened` bigint(20) unsigned NOT NULL DEFAULT 0,
  `elapsed_milliseconds` bigint(20) unsigned NOT NULL DEFAULT 0,
  `late_receive_allowance` bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`series_digest`),
  KEY `recovery_traffic_scope` (`groups_id`,`profile`,`day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obfuscation_recovery_work`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `obfuscation_recovery_work` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bundle_id` bigint(20) unsigned NOT NULL,
  `releases_id` bigint(20) unsigned DEFAULT NULL,
  `revision` bigint(20) unsigned NOT NULL,
  `stage` varchar(24) NOT NULL,
  `purpose` varchar(40) NOT NULL,
  `dispatch_scope` char(64) NOT NULL,
  `request_digest` char(64) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `status` varchar(24) NOT NULL DEFAULT 'pending',
  `result` varchar(48) DEFAULT NULL,
  `due_at` timestamp(6) NOT NULL,
  `claim_token` uuid DEFAULT NULL,
  `claim_expires_at` timestamp(6) NULL DEFAULT NULL,
  `reclaim_after` timestamp(6) NULL DEFAULT NULL,
  `claim_owner_host` char(64) DEFAULT NULL,
  `claim_owner_pid` int(10) unsigned DEFAULT NULL,
  `claim_owner_started` varchar(32) DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  `claim_owner_machine` char(64) DEFAULT NULL,
  `claim_owner_boot` char(64) DEFAULT NULL,
  `claim_owner_namespace` char(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `recovery_logical_request` (`request_digest`,`revision`),
  KEY `recovery_work_due` (`stage`,`status`,`due_at`,`id`),
  KEY `recovery_work_revision` (`bundle_id`,`revision`),
  KEY `recovery_scope_due` (`dispatch_scope`,`status`,`due_at`,`id`),
  KEY `recovery_work_expired` (`status`,`claim_expires_at`,`id`),
  KEY `recovery_work_reclaim` (`status`,`reclaim_after`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `par2_file_descriptors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `par2_file_descriptors` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `releases_id` int(10) unsigned NOT NULL,
  `set_id` char(32) NOT NULL,
  `file_id` char(32) NOT NULL,
  `hash16k` char(32) NOT NULL,
  `full_hash` char(32) DEFAULT NULL,
  `raw_size` bigint(20) unsigned NOT NULL,
  `filename` text NOT NULL,
  `naming_ambiguous` tinyint(1) NOT NULL DEFAULT 0,
  `identity` char(64) NOT NULL,
  `fingerprint` char(64) NOT NULL,
  `origin_release_id` int(10) unsigned NOT NULL,
  `captured_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `par2_file_descriptors_releases_id_identity_unique` (`releases_id`,`identity`),
  KEY `par2_file_descriptors_hash16k_raw_size_index` (`hash16k`,`raw_size`),
  CONSTRAINT `par2_file_descriptors_releases_id_foreign` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `par2_sidecar_inventories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `par2_sidecar_inventories` (
  `releases_id` int(10) unsigned NOT NULL,
  `fingerprint` char(64) NOT NULL,
  `total_files` int(10) unsigned NOT NULL,
  `files` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`files`)),
  `naming_ambiguous` tinyint(1) NOT NULL DEFAULT 0,
  `pure` tinyint(1) NOT NULL,
  `complete` tinyint(1) NOT NULL,
  `reason` varchar(100) DEFAULT NULL,
  `captured_at` timestamp NOT NULL,
  PRIMARY KEY (`releases_id`),
  CONSTRAINT `par2_sidecar_inventories_releases_id_foreign` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `par2_sidecar_operations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `par2_sidecar_operations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `target_id` int(10) unsigned NOT NULL,
  `source_id` int(10) unsigned NOT NULL,
  `target_guid` varchar(40) NOT NULL,
  `source_guid` varchar(40) NOT NULL,
  `leftguid` char(1) NOT NULL,
  `phase` varchar(24) NOT NULL DEFAULT 'selected',
  `reason` varchar(100) DEFAULT NULL,
  `absorb` tinyint(1) NOT NULL,
  `filename` text NOT NULL,
  `target_fingerprint` char(64) NOT NULL,
  `source_fingerprint` char(64) NOT NULL,
  `combined_fingerprint` char(64) DEFAULT NULL,
  `target_xml` longtext NOT NULL,
  `source_xml` longtext NOT NULL,
  `accounting` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`accounting`)),
  `descriptors` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`descriptors`)),
  `hashes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`hashes`)),
  `named_state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`named_state`)),
  `retry_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `par2_sidecar_operations_target_id_unique` (`target_id`),
  UNIQUE KEY `par2_sidecar_operations_source_id_unique` (`source_id`),
  KEY `sidecar_operation_work` (`phase`,`retry_at`,`id`),
  KEY `sidecar_operation_bucket` (`leftguid`,`phase`,`retry_at`,`id`),
  KEY `par2_sidecar_operations_target_id_phase_index` (`target_id`,`phase`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `par_hashes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `par_hashes` (
  `releases_id` int(10) unsigned NOT NULL COMMENT 'FK to releases.id',
  `hash` varchar(32) NOT NULL COMMENT 'hash_16k block of par2',
  PRIMARY KEY (`releases_id`,`hash`),
  KEY `ix_par_hashes_hash_releases_id` (`hash`,`releases_id`),
  CONSTRAINT `FK_ph_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `parts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `parts` (
  `binaries_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `messageid` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  `number` bigint(20) unsigned NOT NULL DEFAULT 0,
  `partnumber` int(10) unsigned NOT NULL DEFAULT 0,
  `size` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`binaries_id`,`partnumber`),
  KEY `ix_parts_number` (`number`),
  CONSTRAINT `FK_binaries` FOREIGN KEY (`binaries_id`) REFERENCES `binaries` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `passkeys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `passkeys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `authenticatable_id` int(10) unsigned NOT NULL,
  `name` text NOT NULL,
  `credential_id` text NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `passkeys_authenticatable_fk` (`authenticatable_id`),
  CONSTRAINT `passkeys_authenticatable_fk` FOREIGN KEY (`authenticatable_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_securities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_securities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `google2fa_enable` tinyint(1) NOT NULL DEFAULT 0,
  `google2fa_secret` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `recovery_codes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`recovery_codes`)),
  PRIMARY KEY (`id`),
  KEY `ix_password_securities_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payload_prefix_hashes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payload_prefix_hashes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `releases_id` int(10) unsigned NOT NULL,
  `nzb_file_index` int(10) unsigned NOT NULL,
  `first_message_id` text NOT NULL,
  `leftguid` char(1) NOT NULL,
  `prefix_hash` char(32) NOT NULL,
  `raw_size` bigint(20) unsigned NOT NULL,
  `decoded_length` bigint(20) unsigned NOT NULL,
  `segment_number` int(10) unsigned NOT NULL,
  `segment_offset` bigint(20) unsigned NOT NULL,
  `observed_segments` int(10) unsigned NOT NULL,
  `declared_segments` int(10) unsigned NOT NULL,
  `segment_numbers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`segment_numbers`)),
  `fingerprint` char(64) NOT NULL,
  `captured_at` timestamp NOT NULL,
  `evaluated_at` timestamp NULL DEFAULT NULL,
  `state` varchar(24) NOT NULL DEFAULT 'pending',
  `reason` varchar(100) DEFAULT NULL,
  `retry_at` timestamp NULL DEFAULT NULL,
  `operation_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payload_prefix_hashes_releases_id_nzb_file_index_unique` (`releases_id`,`nzb_file_index`),
  KEY `payload_prefix_hashes_operation_id_foreign` (`operation_id`),
  KEY `payload_prefix_hashes_prefix_hash_raw_size_index` (`prefix_hash`,`raw_size`),
  KEY `sidecar_prefix_work` (`state`,`retry_at`,`id`),
  KEY `sidecar_prefix_bucket` (`leftguid`,`state`,`retry_at`,`id`),
  KEY `sidecar_prefix_expiry` (`state`,`captured_at`),
  CONSTRAINT `payload_prefix_hashes_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `par2_sidecar_operations` (`id`),
  CONSTRAINT `payload_prefix_hashes_releases_id_foreign` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `username` varchar(255) NOT NULL,
  `item_description` varchar(255) NOT NULL,
  `order_id` varchar(255) NOT NULL,
  `payment_id` varchar(255) NOT NULL,
  `payment_status` varchar(255) NOT NULL,
  `invoice_status` varchar(255) DEFAULT 'Pending',
  `invoice_amount` varchar(255) NOT NULL,
  `payment_method` varchar(255) NOT NULL,
  `payment_value` varchar(255) NOT NULL,
  `webhook_id` varchar(255) NOT NULL,
  `invoice_id` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `paypal_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `paypal_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `users_id` int(11) NOT NULL,
  `transaction_id` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_paypal_payments_users_id` (`users_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `people`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `people` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `tmdb_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_people_tmdb_id` (`tmdb_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` mediumtext DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `poster_renames`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `poster_renames` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `title` varchar(500) NOT NULL,
  `poster` varchar(255) NOT NULL,
  `source` varchar(20) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `poster_title` (`poster`,`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `predb`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `predb` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Primary key',
  `title` varchar(255) NOT NULL DEFAULT '',
  `nfo` varchar(255) DEFAULT NULL,
  `size` varchar(50) DEFAULT NULL,
  `category` varchar(255) DEFAULT NULL,
  `predate` datetime DEFAULT NULL,
  `source` varchar(50) NOT NULL DEFAULT '',
  `requestid` int(10) unsigned NOT NULL DEFAULT 0,
  `groups_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'FK to groups',
  `nuked` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Is this pre nuked? 0 no 2 yes 1 un nuked 3 mod nuked',
  `nukereason` varchar(255) DEFAULT NULL COMMENT 'If this pre is nuked, what is the reason?',
  `files` varchar(50) DEFAULT NULL COMMENT 'How many files does this pre have ?',
  `filename` varchar(255) NOT NULL DEFAULT '',
  `searched` tinyint(1) NOT NULL DEFAULT 0,
  `next_predb_search_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_predb_title` (`title`),
  KEY `ix_predb_requestid` (`requestid`,`groups_id`),
  KEY `ix_predb_nfo` (`nfo`),
  KEY `ix_predb_predate` (`predate`),
  KEY `ix_predb_source` (`source`),
  KEY `ix_predb_searched` (`searched`),
  KEY `ix_predb_searched_predate_id` (`searched`,`predate`,`id`),
  KEY `ix_predb_search_lifecycle` (`searched`,`next_predb_search_at`,`predate`,`id`),
  FULLTEXT KEY `ft_predb_filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `predb_crcs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `predb_crcs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `predb_id` int(10) unsigned NOT NULL COMMENT 'FK to predb.id',
  `crchash` varchar(255) NOT NULL DEFAULT '' COMMENT 'CRC hash',
  `filesize` bigint(20) NOT NULL DEFAULT 0 COMMENT 'Release file size in bytes',
  `filedate` datetime DEFAULT NULL COMMENT 'The file modified date',
  `osohash` varchar(255) NOT NULL DEFAULT '' COMMENT 'OpenSubtitles hash',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `predb_crcs_crchash_filesize_filedate_index` (`crchash`,`filesize`,`filedate`),
  KEY `predb_crcs_osohash_index` (`osohash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `predb_imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `predb_imports` (
  `title` varchar(255) NOT NULL DEFAULT '',
  `nfo` varchar(255) DEFAULT NULL,
  `size` varchar(50) DEFAULT NULL,
  `category` varchar(255) DEFAULT NULL,
  `predate` datetime DEFAULT NULL,
  `source` varchar(50) NOT NULL DEFAULT '',
  `requestid` int(10) unsigned NOT NULL DEFAULT 0,
  `groups_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'FK to groups',
  `nuked` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Is this pre nuked? 0 no 2 yes 1 un nuked 3 mod nuked',
  `nukereason` varchar(255) DEFAULT NULL COMMENT 'If this pre is nuked, what is the reason?',
  `files` varchar(50) DEFAULT NULL COMMENT 'How many files does this pre have ?',
  `filename` varchar(255) NOT NULL DEFAULT '',
  `searched` tinyint(1) NOT NULL DEFAULT 0,
  `groupname` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_aggregates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_aggregates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bucket` int(10) unsigned NOT NULL,
  `period` mediumint(8) unsigned NOT NULL,
  `type` varchar(255) NOT NULL,
  `key` mediumtext NOT NULL,
  `key_hash` binary(16) GENERATED ALWAYS AS (unhex(md5(`key`))) VIRTUAL,
  `aggregate` varchar(255) NOT NULL,
  `value` decimal(20,2) NOT NULL,
  `count` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pulse_aggregates_bucket_period_type_aggregate_key_hash_unique` (`bucket`,`period`,`type`,`aggregate`,`key_hash`),
  KEY `pulse_aggregates_period_bucket_index` (`period`,`bucket`),
  KEY `pulse_aggregates_type_index` (`type`),
  KEY `pulse_aggregates_period_type_aggregate_bucket_index` (`period`,`type`,`aggregate`,`bucket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `timestamp` int(10) unsigned NOT NULL,
  `type` varchar(255) NOT NULL,
  `key` mediumtext NOT NULL,
  `key_hash` binary(16) GENERATED ALWAYS AS (unhex(md5(`key`))) VIRTUAL,
  `value` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pulse_entries_timestamp_index` (`timestamp`),
  KEY `pulse_entries_type_index` (`type`),
  KEY `pulse_entries_key_hash_index` (`key_hash`),
  KEY `pulse_entries_timestamp_type_key_hash_value_index` (`timestamp`,`type`,`key_hash`,`value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pulse_values`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pulse_values` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `timestamp` int(10) unsigned NOT NULL,
  `type` varchar(255) NOT NULL,
  `key` mediumtext NOT NULL,
  `key_hash` binary(16) GENERATED ALWAYS AS (unhex(md5(`key`))) VIRTUAL,
  `value` mediumtext NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pulse_values_type_key_hash_unique` (`type`,`key_hash`),
  KEY `pulse_values_timestamp_index` (`timestamp`),
  KEY `pulse_values_type_index` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reconciled_artifact_operations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reconciled_artifact_operations` (
  `id` uuid NOT NULL,
  `release_id` int(10) unsigned NOT NULL,
  `guid` varchar(40) NOT NULL,
  `kind` varchar(32) NOT NULL,
  `expected_version` bigint(20) unsigned NOT NULL,
  `expected_epoch` bigint(20) unsigned NOT NULL,
  `expected_proof_revision` bigint(20) unsigned NOT NULL,
  `expected_digest` varchar(64) DEFAULT NULL,
  `target_digest` varchar(64) NOT NULL,
  `target_xml` longtext DEFAULT NULL,
  `change_kind` varchar(24) NOT NULL,
  `delta` longtext NOT NULL,
  `updates` longtext NOT NULL,
  `source_revisions` longtext NOT NULL,
  `population` longtext DEFAULT NULL,
  `ownership` longtext DEFAULT NULL,
  `proof` longtext DEFAULT NULL,
  `state` varchar(24) NOT NULL DEFAULT 'prepared',
  `worker` uuid DEFAULT NULL,
  `lease_until` timestamp NULL DEFAULT NULL,
  `result` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `reconciled_artifact_operations_release_id_index` (`release_id`),
  KEY `reconciled_artifact_operations_state_index` (`state`),
  CONSTRAINT `operation_release_fk_e3b0c44298fc` FOREIGN KEY (`release_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reconciled_artifact_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reconciled_artifact_sources` (
  `operation_id` uuid NOT NULL,
  `collection_id` bigint(20) unsigned NOT NULL,
  `revision` varchar(64) NOT NULL,
  `cleanup_pending` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`operation_id`,`collection_id`),
  KEY `reconciled_artifact_sources_collection_id_index` (`collection_id`),
  CONSTRAINT `artifact_source_fk_e3b0c44298fc` FOREIGN KEY (`operation_id`) REFERENCES `reconciled_artifact_operations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reconciled_artifacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reconciled_artifacts` (
  `release_id` int(10) unsigned NOT NULL,
  `guid` varchar(40) NOT NULL,
  `version` bigint(20) unsigned NOT NULL DEFAULT 0,
  `epoch` bigint(20) unsigned NOT NULL DEFAULT 1,
  `proof_revision` bigint(20) unsigned NOT NULL DEFAULT 1,
  `xml` longtext DEFAULT NULL,
  `digest` varchar(64) DEFAULT NULL,
  `pending_operation` uuid DEFAULT NULL,
  `search_pending` tinyint(1) NOT NULL DEFAULT 0,
  `cancelled` tinyint(1) NOT NULL DEFAULT 0,
  `provenance` longtext NOT NULL,
  `discovery_group_id` int(10) unsigned DEFAULT NULL,
  `discovery_count` int(10) unsigned DEFAULT NULL,
  `discovery_postdate` timestamp NULL DEFAULT NULL,
  `discovery_poster` varchar(255) DEFAULT NULL,
  `discovery_base` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`release_id`),
  UNIQUE KEY `reconciled_artifacts_pending_operation_unique` (`pending_operation`),
  KEY `reconciled_artifact_discovery` (`discovery_group_id`,`discovery_count`,`discovery_postdate`),
  KEY `reconciled_artifacts_search_pending_index` (`search_pending`),
  CONSTRAINT `artifact_release_fk_e3b0c44298fc` FOREIGN KEY (`release_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reconciled_posting_inputs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reconciled_posting_inputs` (
  `posting_id` bigint(20) unsigned NOT NULL,
  `release_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`posting_id`,`release_id`),
  KEY `reconciled_posting_inputs_release_id_index` (`release_id`),
  CONSTRAINT `reconciled_posting_inputs_posting_id_foreign` FOREIGN KEY (`posting_id`) REFERENCES `reconciled_postings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `reconciled_posting_inputs_release_id_foreign` FOREIGN KEY (`release_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reconciled_postings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reconciled_postings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `release_id` int(10) unsigned NOT NULL,
  `digest` varchar(64) NOT NULL,
  `source_digest` varchar(64) DEFAULT NULL,
  `budget_id` varchar(64) DEFAULT NULL,
  `review_digest` varchar(64) DEFAULT NULL,
  `state` varchar(24) NOT NULL,
  `independent_videos` tinyint(1) NOT NULL DEFAULT 0,
  `inventory` longtext NOT NULL,
  `decision` longtext NOT NULL,
  `original_nzb` longtext DEFAULT NULL,
  `previous_journal` longtext DEFAULT NULL,
  `artifact_digest` varchar(64) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reconciled_postings_release_id_unique` (`release_id`),
  UNIQUE KEY `reconciled_postings_review_digest_unique` (`review_digest`),
  CONSTRAINT `reconciled_postings_release_id_foreign` FOREIGN KEY (`release_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reconciled_proof_revisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reconciled_proof_revisions` (
  `release_id` int(10) unsigned NOT NULL,
  `revision` bigint(20) unsigned NOT NULL,
  `epoch` bigint(20) unsigned NOT NULL,
  `inventory` longtext NOT NULL,
  `decision` longtext NOT NULL,
  PRIMARY KEY (`release_id`,`revision`),
  CONSTRAINT `proof_release_fk_e3b0c44298fc` FOREIGN KEY (`release_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reconciled_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reconciled_sources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `posting_id` bigint(20) unsigned NOT NULL,
  `collection_hash` varchar(40) NOT NULL,
  `group_id` int(10) unsigned NOT NULL,
  `postdate` timestamp NOT NULL,
  `source_id` varchar(100) NOT NULL,
  `epoch` bigint(20) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reconciled_sources_posting_id_source_id_unique` (`posting_id`,`source_id`),
  KEY `reconciled_source_lookup` (`collection_hash`,`group_id`,`postdate`),
  CONSTRAINT `reconciled_sources_posting_id_foreign` FOREIGN KEY (`posting_id`) REFERENCES `reconciled_postings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reconciliation_admissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reconciliation_admissions` (
  `collection_id` bigint(20) unsigned NOT NULL,
  `decision_id` varchar(64) NOT NULL,
  `revision` varchar(64) NOT NULL,
  `admitted_at` timestamp NOT NULL,
  `expires_at` timestamp NOT NULL,
  `state` varchar(24) NOT NULL DEFAULT 'admitted',
  PRIMARY KEY (`collection_id`),
  KEY `reconciliation_admissions_decision_id_index` (`decision_id`),
  KEY `reconciliation_admissions_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reconciliation_budget_deferrals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reconciliation_budget_deferrals` (
  `bucket` varchar(32) NOT NULL,
  `decision_id` varchar(64) NOT NULL,
  PRIMARY KEY (`bucket`,`decision_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reconciliation_claims`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reconciliation_claims` (
  `collection_id` bigint(20) unsigned NOT NULL,
  `owner` varchar(36) DEFAULT NULL,
  `revision` varchar(64) NOT NULL DEFAULT '',
  `deadline` timestamp NOT NULL,
  `lease_until` timestamp NULL DEFAULT NULL,
  `retry_at` timestamp NULL DEFAULT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `reason` varchar(100) NOT NULL DEFAULT 'pending',
  `release_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`collection_id`),
  KEY `reconciliation_claims_release_id_index` (`release_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reconciliation_decisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reconciliation_decisions` (
  `base_collection_id` bigint(20) unsigned NOT NULL,
  `decision_id` varchar(64) NOT NULL,
  PRIMARY KEY (`base_collection_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reconciliation_evidence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reconciliation_evidence` (
  `key` varchar(64) NOT NULL,
  `response` longtext NOT NULL,
  `expires_at` timestamp NOT NULL,
  PRIMARY KEY (`key`),
  KEY `reconciliation_evidence_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reconciliation_traffic`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reconciliation_traffic` (
  `bucket` varchar(100) NOT NULL,
  `charged` bigint(20) unsigned NOT NULL DEFAULT 0,
  `actual` bigint(20) unsigned NOT NULL DEFAULT 0,
  `requests` bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`bucket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `registration_periods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `registration_periods` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `registration_periods_starts_at_index` (`starts_at`),
  KEY `registration_periods_ends_at_index` (`ends_at`),
  KEY `registration_periods_is_enabled_index` (`is_enabled`),
  KEY `registration_periods_created_by_index` (`created_by`),
  KEY `registration_periods_updated_by_index` (`updated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `registration_status_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `registration_status_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `action` varchar(100) NOT NULL,
  `old_status` tinyint(3) unsigned DEFAULT NULL,
  `new_status` tinyint(3) unsigned DEFAULT NULL,
  `registration_period_id` bigint(20) unsigned DEFAULT NULL,
  `changed_by` bigint(20) unsigned DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `registration_status_history_action_index` (`action`),
  KEY `registration_status_history_registration_period_id_index` (`registration_period_id`),
  KEY `registration_status_history_changed_by_index` (`changed_by`),
  KEY `registration_status_history_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `release_audio_evidence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `release_audio_evidence` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `releases_id` int(10) unsigned NOT NULL COMMENT 'FK to releases.id',
  `revision` int(10) unsigned NOT NULL,
  `evidence_hash` char(64) NOT NULL,
  `schema_version` smallint(5) unsigned NOT NULL,
  `provenance` varchar(32) NOT NULL,
  `release_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`release_snapshot`)),
  `archive_manifest_complete` tinyint(1) DEFAULT NULL,
  `source_file_complete` tinyint(1) DEFAULT NULL,
  `source_starts_at_zero` tinyint(1) DEFAULT NULL,
  `whole_duration_reliable` tinyint(1) DEFAULT NULL,
  `only_one_track_probed` tinyint(1) DEFAULT NULL,
  `nzb_manifest` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`nzb_manifest`)),
  `archive_manifest` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`archive_manifest`)),
  `sidecar_manifest` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`sidecar_manifest`)),
  `captured_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `release_audio_evidence_releases_id_revision_unique` (`releases_id`,`revision`),
  KEY `release_audio_evidence_releases_id_evidence_hash_index` (`releases_id`,`evidence_hash`),
  KEY `release_audio_evidence_provenance_index` (`provenance`),
  CONSTRAINT `release_audio_evidence_releases_id_foreign` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `release_audio_evidence_tracks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `release_audio_evidence_tracks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `release_audio_evidence_id` int(10) unsigned NOT NULL,
  `source_kind` varchar(16) NOT NULL,
  `source_ordinal` int(10) unsigned NOT NULL,
  `source_path` varchar(512) DEFAULT NULL,
  `raw_filename` varchar(512) NOT NULL,
  `segment_count` int(10) unsigned DEFAULT NULL,
  `disc_number` smallint(5) unsigned DEFAULT NULL,
  `track_number` smallint(5) unsigned DEFAULT NULL,
  `normalized_title_hint` varchar(255) DEFAULT NULL,
  `normalized_artist_hint` varchar(255) DEFAULT NULL,
  `raw_tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`raw_tags`)),
  `album` varchar(255) DEFAULT NULL,
  `album_artist` varchar(255) DEFAULT NULL,
  `performer` varchar(255) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `recorded_date` varchar(32) DEFAULT NULL,
  `normalized_album` varchar(255) DEFAULT NULL,
  `normalized_album_artist` varchar(255) DEFAULT NULL,
  `normalized_performer` varchar(255) DEFAULT NULL,
  `normalized_title` varchar(255) DEFAULT NULL,
  `normalized_date` varchar(32) DEFAULT NULL,
  `container` varchar(50) DEFAULT NULL,
  `codec` varchar(50) DEFAULT NULL,
  `whole_duration_seconds` decimal(12,3) DEFAULT NULL,
  `decoded_duration_seconds` decimal(12,3) DEFAULT NULL,
  `source_file_complete` tinyint(1) DEFAULT NULL,
  `source_starts_at_zero` tinyint(1) DEFAULT NULL,
  `whole_duration_reliable` tinyint(1) DEFAULT NULL,
  `isrc` varchar(64) DEFAULT NULL,
  `musicbrainz_track_id` char(36) DEFAULT NULL,
  `musicbrainz_recording_id` char(36) DEFAULT NULL,
  `musicbrainz_release_id` char(36) DEFAULT NULL,
  `musicbrainz_release_group_id` char(36) DEFAULT NULL,
  `musicbrainz_artist_id` char(36) DEFAULT NULL,
  `barcode` varchar(64) DEFAULT NULL,
  `catalog_number` varchar(128) DEFAULT NULL,
  `disc_id_like` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `audio_evidence_track_source` (`release_audio_evidence_id`,`source_kind`,`source_ordinal`),
  KEY `release_audio_evidence_tracks_isrc_index` (`isrc`),
  KEY `audio_evidence_track_recording_id` (`musicbrainz_recording_id`),
  CONSTRAINT `release_audio_evidence_tracks_release_audio_evidence_id_foreign` FOREIGN KEY (`release_audio_evidence_id`) REFERENCES `release_audio_evidence` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `release_audio_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `release_audio_tags` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `releases_id` int(10) unsigned NOT NULL COMMENT 'FK to releases.id',
  `album` varchar(255) DEFAULT NULL,
  `album_performer` varchar(255) DEFAULT NULL,
  `performer` varchar(255) DEFAULT NULL,
  `track_name` varchar(255) DEFAULT NULL,
  `track_position` smallint(5) unsigned DEFAULT NULL,
  `track_position_total` smallint(5) unsigned DEFAULT NULL,
  `genre` varchar(100) DEFAULT NULL,
  `recorded_date` varchar(32) DEFAULT NULL COMMENT 'Raw MediaInfo value: "2019", "2019-04-01", "2019-04-01 00:00:00 UTC"',
  `recorded_year` smallint(5) unsigned DEFAULT NULL,
  `musicbrainz_album_id` char(36) DEFAULT NULL,
  `musicbrainz_artist_id` char(36) DEFAULT NULL,
  `musicbrainz_track_id` char(36) DEFAULT NULL,
  `musicbrainz_release_group_id` char(36) DEFAULT NULL,
  `source_file` varchar(255) DEFAULT NULL,
  `audio_format` varchar(50) DEFAULT NULL,
  `raw_tags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`raw_tags`)),
  `has_preview` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `preview_extension` varchar(8) DEFAULT NULL,
  `preview_mime` varchar(32) DEFAULT NULL,
  `preview_seconds` smallint(5) unsigned DEFAULT NULL,
  `preview_bytes` int(10) unsigned DEFAULT NULL,
  `has_spectrogram` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `release_audio_tags_releases_id_unique` (`releases_id`),
  KEY `release_audio_tags_album_index` (`album`),
  KEY `release_audio_tags_musicbrainz_album_id_index` (`musicbrainz_album_id`),
  CONSTRAINT `release_audio_tags_releases_id_foreign` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `release_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `release_comments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `releases_id` int(10) unsigned NOT NULL COMMENT 'FK to releases.id',
  `text` varchar(2000) NOT NULL DEFAULT '',
  `isvisible` tinyint(1) NOT NULL DEFAULT 1,
  `username` varchar(255) NOT NULL DEFAULT '',
  `users_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `host` varchar(15) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_releasecomment_releases_id` (`releases_id`),
  KEY `ix_releasecomment_userid` (`users_id`),
  CONSTRAINT `FK_rc_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `release_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `release_files` (
  `releases_id` int(10) unsigned NOT NULL COMMENT 'FK to releases.id',
  `name` varchar(255) NOT NULL,
  `size` bigint(20) unsigned NOT NULL DEFAULT 0,
  `crc32` varchar(255) NOT NULL DEFAULT '',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `passworded` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`releases_id`,`name`),
  KEY `ix_release_files_crc32_releases_id` (`crc32`,`releases_id`),
  CONSTRAINT `FK_rf_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `release_imagery_disk_skips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `release_imagery_disk_skips` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `releases_id` int(10) unsigned NOT NULL COMMENT 'FK to releases.id',
  `suppressed` varchar(32) NOT NULL COMMENT 'Imagery artifacts the Free-disk guard suppressed',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `release_imagery_disk_skips_releases_id_unique` (`releases_id`),
  CONSTRAINT `release_imagery_disk_skips_releases_id_foreign` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `release_informs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `release_informs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `relOName` varchar(255) NOT NULL,
  `relPName` varchar(255) NOT NULL,
  `api_token` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `release_music_candidate_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `release_music_candidate_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `release_music_identification_id` bigint(20) unsigned NOT NULL,
  `rank` smallint(5) unsigned NOT NULL,
  `score` smallint(5) unsigned NOT NULL,
  `musicbrainz_recording_id` char(36) DEFAULT NULL,
  `musicbrainz_release_id` char(36) DEFAULT NULL,
  `musicbrainz_release_group_id` char(36) DEFAULT NULL,
  `display_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`display_snapshot`)),
  `feature_vector` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`feature_vector`)),
  `score_contributions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`score_contributions`)),
  `contradictions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`contradictions`)),
  `provenance` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`provenance`)),
  `response_cache_keys` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`response_cache_keys`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `release_music_candidate_rank` (`release_music_identification_id`,`rank`),
  CONSTRAINT `FK_rmca_rmi` FOREIGN KEY (`release_music_identification_id`) REFERENCES `release_music_identifications` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `release_music_identifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `release_music_identifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `releases_id` int(10) unsigned NOT NULL,
  `release_audio_evidence_id` int(10) unsigned NOT NULL,
  `evidence_hash` char(64) NOT NULL,
  `state` varchar(32) NOT NULL,
  `score` smallint(5) unsigned NOT NULL DEFAULT 0,
  `band` varchar(16) NOT NULL,
  `accepted_scope` varchar(32) DEFAULT NULL,
  `musicbrainz_recording_id` char(36) DEFAULT NULL,
  `musicbrainz_release_id` char(36) DEFAULT NULL,
  `musicbrainz_release_group_id` char(36) DEFAULT NULL,
  `reasons` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`reasons`)),
  `feature_contributions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`feature_contributions`)),
  `runner_up_margin` smallint(6) DEFAULT NULL,
  `attempt_count` smallint(5) unsigned NOT NULL DEFAULT 1,
  `lease_token` varchar(64) DEFAULT NULL,
  `lease_expires_at` timestamp NULL DEFAULT NULL,
  `next_attempt_at` timestamp NULL DEFAULT NULL,
  `last_operational_error` text DEFAULT NULL,
  `algorithm_version` varchar(64) NOT NULL,
  `resolver_version` varchar(64) NOT NULL,
  `normalizer_version` varchar(64) NOT NULL,
  `scorer_version` varchar(64) NOT NULL,
  `policy_version` varchar(64) NOT NULL,
  `mirror_replicated_at` timestamp NULL DEFAULT NULL,
  `mirror_searched_at` timestamp NULL DEFAULT NULL,
  `acoustid_looked_up_at` timestamp NULL DEFAULT NULL,
  `supersedes_id` bigint(20) unsigned DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `release_music_identity_version` (`releases_id`,`evidence_hash`,`algorithm_version`),
  KEY `release_music_identity_retry` (`state`,`next_attempt_at`),
  KEY `FK_rmi_rae` (`release_audio_evidence_id`),
  KEY `FK_rmi_supersedes` (`supersedes_id`),
  CONSTRAINT `FK_rmi_rae` FOREIGN KEY (`release_audio_evidence_id`) REFERENCES `release_audio_evidence` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `FK_rmi_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `FK_rmi_supersedes` FOREIGN KEY (`supersedes_id`) REFERENCES `release_music_identifications` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `release_music_synthesis_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `release_music_synthesis_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `releases_id` int(10) unsigned NOT NULL,
  `algorithm_version` varchar(64) NOT NULL,
  `attempt_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `lease_token` varchar(64) DEFAULT NULL,
  `lease_expires_at` timestamp NULL DEFAULT NULL,
  `next_attempt_at` timestamp NULL DEFAULT NULL,
  `last_operational_error` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `release_music_synthesis_version` (`releases_id`,`algorithm_version`),
  KEY `release_music_synthesis_retry` (`next_attempt_at`,`lease_expires_at`),
  CONSTRAINT `release_music_synthesis_attempts_releases_id_foreign` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `release_naming_regexes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `release_naming_regexes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group_regex` varchar(255) NOT NULL DEFAULT '' COMMENT 'This is a regex to match against usenet groups',
  `regex` varchar(5000) NOT NULL DEFAULT '' COMMENT 'Regex used for extracting name from subject',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=ON 0=OFF',
  `description` varchar(1000) NOT NULL DEFAULT '' COMMENT 'Optional extra details on this regex',
  `ordinal` int(11) NOT NULL DEFAULT 0 COMMENT 'Order to run the regex in',
  PRIMARY KEY (`id`),
  KEY `ix_release_naming_regexes_group_regex` (`group_regex`),
  KEY `ix_release_naming_regexes_status` (`status`),
  KEY `ix_release_naming_regexes_ordinal` (`ordinal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `release_nfos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `release_nfos` (
  `releases_id` int(10) unsigned NOT NULL COMMENT 'FK to releases.id',
  `nfo` blob DEFAULT NULL,
  PRIMARY KEY (`releases_id`),
  CONSTRAINT `FK_rn_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `release_nzb_creation_failures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `release_nzb_creation_failures` (
  `releases_id` int(10) unsigned NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL DEFAULT 0,
  `last_error` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`releases_id`),
  CONSTRAINT `FK_rncf_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `release_nzb_passwords`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `release_nzb_passwords` (
  `releases_id` int(10) unsigned NOT NULL,
  `password` varchar(255) NOT NULL,
  PRIMARY KEY (`releases_id`),
  CONSTRAINT `FK_rnp_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `release_regexes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `release_regexes` (
  `releases_id` int(10) unsigned NOT NULL DEFAULT 0,
  `collection_regex_id` int(11) NOT NULL DEFAULT 0,
  `naming_regex_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`releases_id`,`collection_regex_id`,`naming_regex_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `release_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `release_reports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `releases_id` int(10) unsigned NOT NULL,
  `users_id` int(10) unsigned NOT NULL,
  `reason` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `response` text DEFAULT NULL,
  `status` enum('pending','reviewed','resolved','dismissed') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `responded_by` int(10) unsigned DEFAULT NULL,
  `responded_at` timestamp NULL DEFAULT NULL,
  `response_is_public` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `release_reports_users_id_foreign` (`users_id`),
  KEY `release_reports_reviewed_by_foreign` (`reviewed_by`),
  KEY `release_reports_releases_id_status_index` (`releases_id`,`status`),
  KEY `release_reports_status_created_at_index` (`status`,`created_at`),
  KEY `release_reports_responded_by_foreign` (`responded_by`),
  KEY `release_reports_response_lookup_idx` (`releases_id`,`response_is_public`,`responded_at`),
  KEY `ix_release_reports_status_created_admin` (`status`,`created_at`),
  CONSTRAINT `release_reports_releases_id_foreign` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `release_reports_responded_by_foreign` FOREIGN KEY (`responded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `release_reports_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `release_reports_users_id_foreign` FOREIGN KEY (`users_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `release_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `release_stats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `category` varchar(255) NOT NULL,
  `count` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `release_stats_category_index` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `release_subtitles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `release_subtitles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `releases_id` int(10) unsigned NOT NULL COMMENT 'FK to releases.id',
  `subsid` int(10) unsigned NOT NULL,
  `subslanguage` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_releasesubs_releases_id_subsid` (`releases_id`,`subsid`),
  CONSTRAINT `FK_rs_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `release_tv_episodes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `release_tv_episodes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `releases_id` int(10) unsigned NOT NULL,
  `season` smallint(5) unsigned NOT NULL COMMENT '0 = Specials',
  `episode` smallint(5) unsigned DEFAULT NULL COMMENT 'As declared, 0 included; NULL = the whole season',
  PRIMARY KEY (`id`),
  KEY `ix_release_tv_episodes_releases_id` (`releases_id`),
  CONSTRAINT `fk_release_tv_episodes_releases_id` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `release_unique`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `release_unique` (
  `releases_id` int(10) unsigned NOT NULL COMMENT 'FK to releases.id.',
  `uniqueid` varchar(255) NOT NULL COMMENT 'Unique_ID from mediainfo.',
  PRIMARY KEY (`releases_id`,`uniqueid`),
  CONSTRAINT `FK_ru_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `release_video_clips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `release_video_clips` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `releases_id` int(10) unsigned NOT NULL COMMENT 'FK to releases.id',
  `extension` varchar(8) NOT NULL,
  `mime` varchar(32) NOT NULL,
  `duration_seconds` smallint(5) unsigned DEFAULT NULL,
  `bytes` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `release_video_clips_releases_id_unique` (`releases_id`),
  CONSTRAINT `release_video_clips_releases_id_foreign` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `releases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `releases` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '',
  `searchname` varchar(255) NOT NULL DEFAULT '',
  `searchname_normalized` varchar(255) DEFAULT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `totalpart` int(11) DEFAULT 0,
  `declaredfiles` int(10) unsigned DEFAULT NULL COMMENT 'Files the headers declared; null = unresolved (legacy), 0 = no usable declaration',
  `firstarticle` bigint(20) unsigned DEFAULT NULL COMMENT 'Lowest article number the collection held',
  `lastarticle` bigint(20) unsigned DEFAULT NULL COMMENT 'Highest article number the collection held',
  `groups_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'FK to groups.id',
  `size` bigint(20) unsigned NOT NULL DEFAULT 0,
  `postdate` datetime DEFAULT NULL,
  `adddate` datetime DEFAULT NULL,
  `guid` char(40) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `leftguid` char(1) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL COMMENT 'The first letter of the release guid',
  `fromname` varchar(255) DEFAULT NULL,
  `completion` double NOT NULL DEFAULT 0,
  `repair_attempted_at` timestamp NULL DEFAULT NULL COMMENT 'When the repair engine last worked this release (both passes stamp it)',
  `repair_outcome` varchar(16) DEFAULT NULL COMMENT 'retry-pending | repaired | failed | skipped-floor; null = never offered to repair',
  `repair_target_completion` double DEFAULT NULL COMMENT 'Completion target achieved by this repaired verdict; null for other outcomes',
  `repair_evaluated_target_completion` double DEFAULT NULL COMMENT 'Latest completion target evaluated by segment repair',
  `rescan_attempted_at` timestamp NULL DEFAULT NULL COMMENT 'When the header re-scan last worked this release (both passes stamp it)',
  `rescan_outcome` varchar(16) DEFAULT NULL COMMENT 'retry-pending | repaired | failed | skipped-floor | skipped-budget; null = never re-scanned',
  `rescan_target_completion` double DEFAULT NULL COMMENT 'Completion target achieved by this repaired verdict; null for other outcomes',
  `rescan_evaluated_target_completion` double DEFAULT NULL COMMENT 'Latest completion target evaluated by header re-scan',
  `recovery_claimed_at` timestamp NULL DEFAULT NULL COMMENT 'Live lease shared by segment repair and whole-file header re-scan',
  `categories_id` int(11) NOT NULL DEFAULT 10,
  `videos_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'FK to videos.id of the parent series.',
  `tv_episodes_id` int(11) NOT NULL DEFAULT 0 COMMENT 'FK to tv_episodes.id for the episode.',
  `tv_episode_lookup_attempted_at` timestamp NULL DEFAULT NULL,
  `imdbid` varchar(100) DEFAULT NULL,
  `musicinfo_id` int(11) DEFAULT NULL COMMENT 'FK to musicinfo.id',
  `consoleinfo_id` int(11) DEFAULT NULL COMMENT 'FK to consoleinfo.id',
  `gamesinfo_id` int(11) NOT NULL DEFAULT 0,
  `bookinfo_id` int(11) DEFAULT NULL COMMENT 'FK to bookinfo.id',
  `anidbid` int(11) DEFAULT NULL COMMENT 'FK to anidb_titles.anidbid',
  `movieinfo_id` int(11) DEFAULT NULL COMMENT 'FK to movieinfo.id',
  `predb_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'FK to predb.id',
  `grabs` int(10) unsigned NOT NULL DEFAULT 0,
  `comments` int(11) NOT NULL DEFAULT 0,
  `passwordstatus` smallint(6) NOT NULL DEFAULT -1,
  `rarinnerfilecount` int(11) NOT NULL DEFAULT 0,
  `haspreview` tinyint(1) NOT NULL DEFAULT 0,
  `nfostatus` tinyint(1) NOT NULL DEFAULT 0,
  `jpgstatus` tinyint(1) NOT NULL DEFAULT 0,
  `videostatus` tinyint(1) NOT NULL DEFAULT 0,
  `nzbstatus` tinyint(1) NOT NULL DEFAULT 0,
  `nzb_creation_claimed_at` timestamp NULL DEFAULT NULL,
  `nzb_creation_claim_token` char(32) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `iscategorized` tinyint(1) NOT NULL DEFAULT 0,
  `isrenamed` tinyint(1) NOT NULL DEFAULT 0,
  `is_trusted_name` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Current search name came from evidence safe for donor propagation',
  `proc_pp` tinyint(1) NOT NULL DEFAULT 0,
  `proc_par2` tinyint(1) NOT NULL DEFAULT 0,
  `proc_nfo` tinyint(1) NOT NULL DEFAULT 0,
  `proc_files` tinyint(1) NOT NULL DEFAULT 0,
  `proc_xxx` tinyint(4) NOT NULL DEFAULT 0,
  `proc_uid` tinyint(1) NOT NULL DEFAULT 0,
  `proc_media_movie` tinyint(4) NOT NULL DEFAULT 0,
  `proc_srr` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Has the release been srr\nprocessed',
  `proc_hash16k` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Has the release been hash16k\nprocessed',
  `proc_crc32` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Has the release been crc32 processed',
  `proc_srrdb` tinyint(3) unsigned NOT NULL DEFAULT 0 COMMENT 'SRRDB archive-CRC lookup state: 0 pending, 1 processed, 2 ambiguous',
  `pp_timeout_count` tinyint(4) NOT NULL DEFAULT 0 COMMENT 'Number of times this release timed out during additional post-processing',
  `additional_pp_claimed_at` timestamp NULL DEFAULT NULL,
  `additional_pp_claim_token` char(32) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `collectionhash` binary(20) DEFAULT NULL,
  `recovery_claim_token` uuid DEFAULT NULL,
  `imdb_lookup_attempted_at` timestamp NULL DEFAULT NULL,
  `imdb_lookup_attempts` tinyint(3) unsigned DEFAULT NULL,
  `movie_record_lookup_attempted_at` timestamp NULL DEFAULT NULL,
  `movie_record_lookup_attempts` tinyint(3) unsigned DEFAULT NULL,
  `name_direct_work_pending` tinyint(1) GENERATED ALWAYS AS (`nfostatus` = 1 and `proc_nfo` = 0 or `nzbstatus` = 1 and `proc_par2` = 0 or `proc_hash16k` = 0) VIRTUAL,
  `name_evidence_work_pending` tinyint(1) GENERATED ALWAYS AS (`proc_xxx` = 0 or `proc_uid` = 0 or `proc_media_movie` = 0 or `proc_srrdb` = 0 or `proc_files` = 0 or `proc_srr` = 0 or `proc_crc32` = 0) VIRTUAL,
  `resolution` tinyint(3) unsigned NOT NULL DEFAULT 0 COMMENT 'App\\Enums\\ReleaseResolution: measured video size, else the name; 0 unknown',
  `source` tinyint(3) unsigned NOT NULL DEFAULT 0 COMMENT 'App\\Enums\\ReleaseSource: from the name; 0 unknown',
  `category_band` int(11) GENERATED ALWAYS AS (floor(`categories_id` / 1000) * 1000) VIRTUAL COMMENT 'Thousand-band of categories_id: 5000 for every TV category',
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_releases_guid` (`guid`),
  UNIQUE KEY `ux_releases_collectionhash` (`collectionhash`),
  KEY `ix_releases_groupsid` (`groups_id`,`passwordstatus`),
  KEY `ix_releases_leftguid` (`leftguid`,`predb_id`),
  KEY `ix_releases_musicinfo_id` (`musicinfo_id`,`passwordstatus`),
  KEY `ix_releases_nfostatus` (`nfostatus`,`size`),
  KEY `ix_releases_name` (`name`),
  KEY `ix_releases_tv_episodes_id` (`tv_episodes_id`),
  KEY `ix_releases_consoleinfo_id` (`consoleinfo_id`),
  KEY `ix_releases_gamesinfo_id` (`gamesinfo_id`),
  KEY `ix_releases_bookinfo_id` (`bookinfo_id`),
  KEY `ix_releases_anidbid` (`anidbid`),
  KEY `ix_releases_password_categories_postdate` (`passwordstatus`,`categories_id`,`postdate` DESC),
  KEY `ix_releases_adddate` (`adddate`,`categories_id`),
  KEY `ix_releases_grabs` (`grabs`,`categories_id`,`postdate`),
  KEY `ix_releases_movieinfo_cat` (`movieinfo_id`,`categories_id`,`passwordstatus`,`postdate`),
  KEY `ix_releases_videos_categories` (`videos_id`,`categories_id`),
  KEY `ix_releases_imdbid_password_cat_postdate` (`imdbid`,`passwordstatus`,`categories_id`,`postdate` DESC),
  KEY `ix_releases_searchname` (`searchname`),
  KEY `ix_releases_categories_postdate_admin` (`categories_id`,`postdate`),
  KEY `ix_releases_postdate_admin` (`postdate`),
  KEY `ix_releases_adddate_id` (`adddate`,`id`),
  KEY `ix_releases_predb_id` (`predb_id`),
  KEY `ix_releases_size` (`size`),
  KEY `ix_releases_add_pp_claim_queue` (`passwordstatus`,`haspreview`,`nzbstatus`,`leftguid`,`postdate` DESC,`id`,`additional_pp_claimed_at`,`size`),
  KEY `ix_releases_nzb_creation_group_queue` (`nzbstatus`,`groups_id`,`postdate` DESC,`id`,`nzb_creation_claimed_at`),
  KEY `ix_releases_nzb_creation_global_queue` (`nzbstatus`,`postdate` DESC,`id`,`nzb_creation_claimed_at`),
  KEY `ix_releases_fromname_postdate` (`fromname`(191),`postdate` DESC),
  KEY `ix_releases_repair_sweep` (`repair_outcome`,`completion`),
  KEY `ix_releases_repair_retry` (`repair_outcome`,`repair_attempted_at`),
  KEY `ix_releases_rescan_sweep` (`rescan_outcome`,`completion`),
  KEY `ix_releases_rescan_retry` (`rescan_outcome`,`rescan_attempted_at`),
  KEY `ix_releases_recovery_claim` (`recovery_claimed_at`),
  KEY `ix_releases_tv_episode_revisit` (`videos_id`,`tv_episodes_id`,`postdate`,`tv_episode_lookup_attempted_at`),
  KEY `ix_releases_searchname_normalized_size` (`searchname_normalized`,`size`),
  KEY `ix_releases_pp_pending_size` (`passwordstatus`,`haspreview`,`nzbstatus`,`size`) COMMENT 'nntmux:post-processing-candidates:523',
  KEY `ix_releases_pp_declined_size` (`additional_pp_claim_token`,`passwordstatus`,`haspreview`,`nzbstatus`,`size`) COMMENT 'nntmux:post-processing-candidates:523',
  KEY `releases_formation_queue` (`groups_id`,`nzbstatus`,`id`),
  KEY `releases_name_evidence_work` (`name_evidence_work_pending`,`isrenamed`,`predb_id`,`leftguid`,`id`),
  KEY `releases_name_direct_work` (`name_direct_work_pending`,`isrenamed`,`predb_id`,`leftguid`,`id`),
  KEY `ix_releases_band_posted` (`category_band`,`postdate`,`id`,`resolution`,`source`,`categories_id`,`passwordstatus`),
  KEY `ix_releases_band_added` (`category_band`,`adddate`,`id`,`resolution`,`source`,`categories_id`,`passwordstatus`),
  KEY `ix_releases_band_count` (`category_band`,`resolution`,`source`,`categories_id`,`passwordstatus`),
  KEY `ix_releases_videos_posted` (`videos_id`,`postdate`),
  KEY `ix_releases_videos_added` (`videos_id`,`adddate`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `releases_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `releases_groups` (
  `releases_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'FK to releases.id',
  `groups_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'FK to groups.id',
  PRIMARY KEY (`releases_id`,`groups_id`),
  KEY `ix_releases_groups_group_release` (`groups_id`,`releases_id`) COMMENT 'nntmux:post-processing-candidates:523',
  CONSTRAINT `FK_rg_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_expiration_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_expiration_emails` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `users_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `day` tinyint(1) NOT NULL DEFAULT 0,
  `week` tinyint(1) NOT NULL DEFAULT 0,
  `month` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_expiration_emails_users_id_unique` (`users_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_has_permissions` (
  `permission_id` int(10) unsigned NOT NULL,
  `role_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`role_id`),
  KEY `role_has_permissions_role_id_foreign` (`role_id`),
  CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_promotion_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_promotion_stats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `role_promotion_id` bigint(20) unsigned NOT NULL,
  `role_id` int(10) unsigned NOT NULL,
  `days_added` int(11) NOT NULL COMMENT 'Number of days added to role expiry',
  `previous_expiry_date` datetime DEFAULT NULL COMMENT 'Previous role expiry date before promotion',
  `new_expiry_date` datetime DEFAULT NULL COMMENT 'New role expiry date after promotion',
  `applied_at` timestamp NOT NULL COMMENT 'When the promotion was applied',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `role_promotion_stats_user_id_index` (`user_id`),
  KEY `role_promotion_stats_role_promotion_id_index` (`role_promotion_id`),
  KEY `role_promotion_stats_role_id_index` (`role_id`),
  KEY `role_promotion_stats_applied_at_index` (`applied_at`),
  CONSTRAINT `role_promotion_stats_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_promotion_stats_role_promotion_id_foreign` FOREIGN KEY (`role_promotion_id`) REFERENCES `role_promotions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_promotion_stats_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_promotions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_promotions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `applicable_roles` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'JSON array of role IDs this promotion applies to' CHECK (json_valid(`applicable_roles`)),
  `additional_days` int(11) NOT NULL DEFAULT 0 COMMENT 'Additional days added to role expiry',
  `start_date` date DEFAULT NULL COMMENT 'Promotion start date',
  `end_date` date DEFAULT NULL COMMENT 'Promotion end date',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_role_promotions_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_stats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `role` varchar(255) DEFAULT NULL,
  `users` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_role_stats_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `apirequests` int(10) unsigned NOT NULL,
  `rate_limit` int(11) NOT NULL DEFAULT 60,
  `downloadrequests` int(10) unsigned NOT NULL,
  `defaultinvites` int(10) unsigned NOT NULL,
  `isdefault` tinyint(1) NOT NULL DEFAULT 0,
  `donation` int(11) NOT NULL DEFAULT 0,
  `addyears` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `root_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `root_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `status` int(11) NOT NULL DEFAULT 1,
  `discard_executables` tinyint(1) NOT NULL DEFAULT 0,
  `generate_previews` tinyint(1) NOT NULL DEFAULT 1,
  `dynamic_preview_budget` tinyint(1) NOT NULL DEFAULT 0,
  `generate_clips` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_root_categories_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `search_index_failures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `search_index_failures` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `release_id` bigint(20) unsigned NOT NULL,
  `operation` varchar(32) NOT NULL DEFAULT 'upsert',
  `attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `last_error` text DEFAULT NULL,
  `next_attempt_at` timestamp NULL DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `search_index_failures_release_id_unique` (`release_id`),
  KEY `search_index_failures_next_attempt_at_index` (`next_attempt_at`),
  KEY `search_index_failures_resolved_at_index` (`resolved_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_incident_service_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_incident_service_status` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `service_incident_id` bigint(20) unsigned NOT NULL,
  `service_status_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `svc_incident_svc_pair_unique` (`service_incident_id`,`service_status_id`),
  KEY `service_incident_service_status_service_status_id_index` (`service_status_id`),
  CONSTRAINT `service_incident_service_status_service_incident_id_foreign` FOREIGN KEY (`service_incident_id`) REFERENCES `service_incidents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `service_incident_service_status_service_status_id_foreign` FOREIGN KEY (`service_status_id`) REFERENCES `service_statuses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_incidents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_incidents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `status` varchar(255) NOT NULL,
  `impact` varchar(255) NOT NULL,
  `started_at` timestamp NOT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `is_auto` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `service_incidents_status_index` (`status`),
  KEY `service_incidents_impact_index` (`impact`),
  KEY `service_incidents_started_at_index` (`started_at`),
  KEY `service_incidents_resolved_at_index` (`resolved_at`),
  KEY `service_incidents_status_started_at_index` (`status`,`started_at`),
  KEY `service_incidents_created_by_index` (`created_by`),
  KEY `service_incidents_is_auto_index` (`is_auto`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service_statuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service_statuses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `endpoint_url` varchar(255) DEFAULT NULL,
  `check_type` varchar(255) NOT NULL DEFAULT 'http',
  `probe_identifier` varchar(255) DEFAULT NULL,
  `status` varchar(255) NOT NULL,
  `last_checked_at` timestamp NULL DEFAULT NULL,
  `uptime_percentage` decimal(5,2) NOT NULL DEFAULT 100.00,
  `response_time_ms` int(10) unsigned DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_statuses_slug_unique` (`slug`),
  KEY `service_statuses_status_index` (`status`),
  KEY `service_statuses_is_enabled_sort_order_index` (`is_enabled`,`sort_order`),
  KEY `service_statuses_check_type_index` (`check_type`),
  KEY `service_statuses_probe_identifier_index` (`probe_identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `name` varchar(255) NOT NULL DEFAULT '',
  `value` varchar(1000) NOT NULL DEFAULT '',
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `short_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `short_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '',
  `first_record` bigint(20) unsigned NOT NULL DEFAULT 0,
  `last_record` bigint(20) unsigned NOT NULL DEFAULT 0,
  `updated` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_shortgroups_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `signup_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `signup_stats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `month` varchar(255) DEFAULT NULL,
  `sort_date` date DEFAULT NULL,
  `signups` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `signup_stats_sort_date_index` (`sort_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `srrdb_lookups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `srrdb_lookups` (
  `crc32` varchar(8) NOT NULL,
  `status` varchar(20) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `checked_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`crc32`),
  KEY `srrdb_lookups_checked_at_index` (`checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `steam_apps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `steam_apps` (
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT 'Steam application name',
  `appid` int(10) unsigned NOT NULL COMMENT 'Steam application id',
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`),
  KEY `ix_name_appid` (`name`,`appid`),
  FULLTEXT KEY `ix_name_ft` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_metrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_metrics` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `metric_type` varchar(50) NOT NULL,
  `value` decimal(8,2) NOT NULL,
  `recorded_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `system_metrics_metric_type_recorded_at_index` (`metric_type`,`recorded_at`),
  KEY `system_metrics_metric_type_index` (`metric_type`),
  KEY `system_metrics_recorded_at_index` (`recorded_at`),
  KEY `ix_system_metrics_type_recorded` (`metric_type`,`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `telescope_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `telescope_entries` (
  `sequence` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `batch_id` char(36) NOT NULL,
  `family_hash` varchar(255) DEFAULT NULL,
  `should_display_on_index` tinyint(1) NOT NULL DEFAULT 1,
  `type` varchar(20) NOT NULL,
  `content` longtext NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`sequence`),
  UNIQUE KEY `telescope_entries_uuid_unique` (`uuid`),
  KEY `telescope_entries_batch_id_index` (`batch_id`),
  KEY `telescope_entries_type_should_display_on_index_index` (`type`,`should_display_on_index`),
  KEY `telescope_entries_family_hash_index` (`family_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `telescope_entries_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `telescope_entries_tags` (
  `entry_uuid` char(36) NOT NULL,
  `tag` varchar(255) NOT NULL,
  KEY `telescope_entries_tags_entry_uuid_tag_index` (`entry_uuid`,`tag`),
  KEY `telescope_entries_tags_tag_index` (`tag`),
  CONSTRAINT `telescope_entries_tags_entry_uuid_foreign` FOREIGN KEY (`entry_uuid`) REFERENCES `telescope_entries` (`uuid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `telescope_monitoring`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `telescope_monitoring` (
  `tag` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `trusted_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `trusted_devices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `trusted_devices_token_hash_unique` (`token_hash`),
  KEY `trusted_devices_user_id_expires_at_index` (`user_id`,`expires_at`),
  KEY `trusted_devices_expires_at_index` (`expires_at`),
  CONSTRAINT `trusted_devices_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tv_episodes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tv_episodes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `videos_id` int(10) unsigned NOT NULL COMMENT 'FK to videos.id of the parent series.',
  `series` smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT 'Number of series/season.',
  `episode` smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT 'Number of episode within series',
  `se_complete` varchar(10) NOT NULL COMMENT 'String version of Series/Episode as taken from release subject (i.e. S02E21+22).',
  `title` varchar(180) NOT NULL COMMENT 'Title of the episode.',
  `firstaired` date DEFAULT NULL COMMENT 'Date of original airing/release.',
  `summary` mediumtext NOT NULL COMMENT 'Description/summary of the episode.',
  PRIMARY KEY (`id`),
  UNIQUE KEY `videos_id` (`videos_id`,`series`,`episode`,`firstaired`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tv_info`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tv_info` (
  `videos_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'FK to video.id',
  `summary` mediumtext NOT NULL COMMENT 'Description/summary of the show.',
  `publisher` varchar(50) NOT NULL COMMENT 'The channel/network of production/release (ABC, BBC, Showtime, etc.).',
  `localzone` varchar(50) NOT NULL DEFAULT '' COMMENT 'The linux tz style identifier',
  `image` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Does the video have a cover image?',
  `banner` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Does the video have a series banner?',
  `original_language` varchar(8) NOT NULL DEFAULT '' COMMENT 'TMDB original_language (ISO 639-1)',
  `status` tinyint(3) unsigned NOT NULL DEFAULT 0 COMMENT '0 unknown, 1 running, 2 ended',
  `content_rating_us` varchar(8) NOT NULL DEFAULT '' COMMENT 'TMDB US content rating',
  `premiered` date DEFAULT NULL COMMENT 'TMDB first_air_date',
  `networks_id` int(10) unsigned DEFAULT NULL,
  `details_refreshed_at` timestamp NULL DEFAULT NULL COMMENT 'When TMDB details were last fetched; NULL = never',
  PRIMARY KEY (`videos_id`),
  KEY `ix_tv_info_image` (`image`),
  KEY `ix_tv_info_banner` (`banner`),
  KEY `ix_tv_info_premiered` (`premiered`,`videos_id`),
  KEY `tv_info_networks_id_foreign` (`networks_id`),
  CONSTRAINT `tv_info_networks_id_foreign` FOREIGN KEY (`networks_id`) REFERENCES `networks` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `usenet_group_ingested_ranges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `usenet_group_ingested_ranges` (
  `usenet_groups_id` int(10) unsigned NOT NULL,
  `first_record` bigint(20) unsigned NOT NULL,
  `last_record` bigint(20) unsigned NOT NULL,
  `last_record_postdate` datetime DEFAULT NULL,
  PRIMARY KEY (`usenet_groups_id`,`first_record`),
  CONSTRAINT `usenet_group_ingested_ranges_usenet_groups_id_foreign` FOREIGN KEY (`usenet_groups_id`) REFERENCES `usenet_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `usenet_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `usenet_groups` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '',
  `backfill_target` int(11) NOT NULL DEFAULT 1,
  `route_obfuscated_names` tinyint(1) NOT NULL DEFAULT 0,
  `obfuscated_default_root_categories_id` bigint(20) unsigned DEFAULT NULL,
  `forced_root_categories_id` bigint(20) unsigned DEFAULT NULL,
  `first_record` bigint(20) unsigned NOT NULL DEFAULT 0,
  `first_record_postdate` datetime DEFAULT NULL,
  `backfill_settled_at` datetime DEFAULT NULL,
  `last_record` bigint(20) unsigned NOT NULL DEFAULT 0,
  `last_record_postdate` datetime DEFAULT NULL,
  `last_updated` datetime DEFAULT NULL,
  `minfilestoformrelease` int(11) DEFAULT NULL,
  `minsizetoformrelease` bigint(20) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 0,
  `backfill` tinyint(1) NOT NULL DEFAULT 0,
  `description` varchar(255) DEFAULT '',
  `obfuscation_recovery_profile` varchar(16) NOT NULL DEFAULT 'disabled',
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_groups_name` (`name`),
  KEY `active` (`active`),
  KEY `ix_usenet_groups_active_name_admin` (`active`,`name`),
  KEY `usenet_groups_obfuscated_default_root_categories_id_foreign` (`obfuscated_default_root_categories_id`),
  KEY `usenet_groups_forced_root_categories_id_foreign` (`forced_root_categories_id`),
  CONSTRAINT `usenet_groups_forced_root_categories_id_foreign` FOREIGN KEY (`forced_root_categories_id`) REFERENCES `root_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `usenet_groups_obfuscated_default_root_categories_id_foreign` FOREIGN KEY (`obfuscated_default_root_categories_id`) REFERENCES `root_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_activities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `username` varchar(255) NOT NULL,
  `activity_type` varchar(50) NOT NULL,
  `description` text NOT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `is_permanent` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_activities_activity_type_created_at_index` (`activity_type`,`created_at`),
  KEY `user_activities_created_at_index` (`created_at`),
  KEY `ix_user_activities_type_permanent` (`activity_type`,`is_permanent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_activity_stats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_activity_stats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stat_date` date NOT NULL,
  `downloads_count` int(11) NOT NULL DEFAULT 0,
  `api_hits_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_activity_stats_stat_date_unique` (`stat_date`),
  KEY `user_activity_stats_stat_date_index` (`stat_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_activity_stats_hourly`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_activity_stats_hourly` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stat_hour` datetime NOT NULL,
  `downloads_count` int(11) NOT NULL DEFAULT 0,
  `api_hits_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_activity_stats_hourly_stat_hour_unique` (`stat_hour`),
  KEY `user_activity_stats_hourly_stat_hour_index` (`stat_hour`),
  KEY `ix_hourly_stats_hour` (`stat_hour`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_downloads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_downloads` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `users_id` int(10) unsigned NOT NULL,
  `hosthash` varchar(50) NOT NULL DEFAULT '',
  `timestamp` datetime NOT NULL,
  `releases_id` int(10) unsigned NOT NULL COMMENT 'FK to releases.id',
  PRIMARY KEY (`id`),
  KEY `userid` (`users_id`),
  KEY `timestamp` (`timestamp`),
  KEY `ix_user_downloads_users_timestamp` (`users_id`,`timestamp`),
  KEY `ix_user_downloads_releases_id` (`releases_id`),
  CONSTRAINT `FK_users_ud` FOREIGN KEY (`users_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_excluded_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_excluded_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `users_id` int(10) unsigned NOT NULL,
  `categories_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_excluded_categories_users_id_categories_id_unique` (`users_id`,`categories_id`),
  KEY `user_excluded_categories_categories_id_foreign` (`categories_id`),
  KEY `user_excluded_categories_users_id_index` (`users_id`),
  CONSTRAINT `user_excluded_categories_categories_id_foreign` FOREIGN KEY (`categories_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_excluded_categories_users_id_foreign` FOREIGN KEY (`users_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_invitations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `status` enum('pending','successful','canceled','expired') NOT NULL DEFAULT 'pending',
  `valid_till` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_invitations_code_index` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_movies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_movies` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `users_id` int(10) unsigned NOT NULL,
  `imdbid` varchar(100) DEFAULT NULL,
  `categories` varchar(64) DEFAULT NULL COMMENT 'List of categories for user movies',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_usermovies_userid` (`users_id`,`imdbid`),
  CONSTRAINT `FK_users_um` FOREIGN KEY (`users_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `users_id` int(10) unsigned NOT NULL,
  `hosthash` varchar(50) NOT NULL DEFAULT '',
  `request` varchar(255) NOT NULL,
  `timestamp` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `userid` (`users_id`),
  KEY `timestamp` (`timestamp`),
  KEY `ix_user_requests_users_timestamp` (`users_id`,`timestamp`),
  CONSTRAINT `FK_users_urq` FOREIGN KEY (`users_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_role_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_role_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `old_role_id` int(11) DEFAULT NULL,
  `new_role_id` int(11) NOT NULL,
  `old_expiry_date` datetime DEFAULT NULL COMMENT 'Previous role expiry date',
  `new_expiry_date` datetime DEFAULT NULL COMMENT 'New role expiry date',
  `effective_date` datetime NOT NULL COMMENT 'When this role change became active',
  `is_stacked` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Was this role stacked after previous expiry',
  `change_reason` varchar(255) DEFAULT NULL COMMENT 'Reason for role change (upgrade, downgrade, expiry, admin, etc)',
  `changed_by` bigint(20) unsigned DEFAULT NULL COMMENT 'Admin user ID who made the change',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_role_history_user_id_index` (`user_id`),
  KEY `ix_user_role_history_changed_by` (`changed_by`),
  KEY `ix_user_role_history_roles` (`old_role_id`,`new_role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_series`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_series` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `users_id` int(10) unsigned NOT NULL,
  `videos_id` int(11) NOT NULL COMMENT 'FK to videos.id',
  `categories` varchar(64) DEFAULT NULL COMMENT 'List of categories for user tv shows',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_userseries_videos_id` (`users_id`,`videos_id`),
  CONSTRAINT `FK_users_us` FOREIGN KEY (`users_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `firstname` varchar(255) DEFAULT NULL,
  `lastname` varchar(255) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `roles_id` int(11) NOT NULL DEFAULT 1 COMMENT 'FK to roles.id',
  `next_roles_id` int(11) DEFAULT NULL,
  `host` varchar(40) DEFAULT NULL,
  `grabs` int(11) NOT NULL DEFAULT 0,
  `api_token` varchar(64) NOT NULL,
  `resetguid` varchar(50) DEFAULT NULL,
  `lastlogin` datetime DEFAULT NULL,
  `apiaccess` datetime DEFAULT NULL,
  `lastdownload` datetime DEFAULT NULL,
  `invites` int(11) NOT NULL DEFAULT 0,
  `invitedby` int(11) DEFAULT NULL,
  `movieview` int(11) NOT NULL DEFAULT 1,
  `xxxview` int(11) NOT NULL DEFAULT 1,
  `musicview` int(11) NOT NULL DEFAULT 1,
  `consoleview` int(11) NOT NULL DEFAULT 1,
  `bookview` int(11) NOT NULL DEFAULT 1,
  `gameview` int(11) NOT NULL DEFAULT 1,
  `rate_limit` int(11) NOT NULL DEFAULT 60,
  `notes` varchar(255) DEFAULT NULL,
  `theme_preference` varchar(10) NOT NULL DEFAULT 'light',
  `style` varchar(255) DEFAULT NULL,
  `rolechangedate` datetime DEFAULT NULL COMMENT 'When does the role expire',
  `pending_role_start_date` datetime DEFAULT NULL COMMENT 'When the pending role change takes effect',
  `pending_roles_id` int(11) DEFAULT NULL COMMENT 'The role that will be applied after current role expires',
  `next_rolechangedate` datetime DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `session_token` varchar(60) DEFAULT NULL,
  `timezone` varchar(255) DEFAULT NULL,
  `movie_layout` tinyint(4) NOT NULL DEFAULT 2 COMMENT '1=1-column, 2=2-columns',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  `verification_token` varchar(255) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  `view_prefs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`view_prefs`)),
  `can_post` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_users_api_token` (`api_token`),
  KEY `ix_user_roles` (`roles_id`),
  KEY `ix_users_created_at` (`created_at`),
  KEY `ix_users_roles_created` (`roles_id`,`created_at`),
  KEY `ix_users_deleted_at` (`deleted_at`),
  KEY `ix_users_username` (`username`),
  KEY `ix_users_email` (`email`),
  KEY `ix_users_host` (`host`),
  KEY `ix_users_lastlogin` (`lastlogin`),
  KEY `ix_users_apiaccess` (`apiaccess`),
  KEY `ix_users_grabs` (`grabs`),
  KEY `ix_users_rolechangedate` (`rolechangedate`),
  KEY `ix_users_verified` (`verified`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users_releases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_releases` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `users_id` int(10) unsigned NOT NULL,
  `releases_id` int(10) unsigned NOT NULL COMMENT 'FK to releases.id',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_usercart_userrelease` (`users_id`,`releases_id`),
  KEY `FK_ur_releases` (`releases_id`),
  CONSTRAINT `FK_ur_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `FK_users_ur` FOREIGN KEY (`users_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `video_data`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `video_data` (
  `releases_id` int(10) unsigned NOT NULL COMMENT 'FK to releases.id',
  `containerformat` varchar(50) DEFAULT NULL,
  `overallbitrate` varchar(20) DEFAULT NULL,
  `videoduration` varchar(20) DEFAULT NULL,
  `videoformat` varchar(50) DEFAULT NULL,
  `videocodec` varchar(50) DEFAULT NULL,
  `videowidth` int(11) DEFAULT NULL,
  `videoheight` int(11) DEFAULT NULL,
  `videoaspect` varchar(10) DEFAULT NULL,
  `videoframerate` double(7,4) DEFAULT NULL,
  `videolibrary` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`releases_id`),
  CONSTRAINT `FK_vd_releases` FOREIGN KEY (`releases_id`) REFERENCES `releases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `video_genres`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `video_genres` (
  `videos_id` int(10) unsigned NOT NULL,
  `genres_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`genres_id`,`videos_id`),
  KEY `ix_video_genres_video` (`videos_id`),
  CONSTRAINT `fk_video_genres_genres_id` FOREIGN KEY (`genres_id`) REFERENCES `genres` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `video_people`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `video_people` (
  `videos_id` int(10) unsigned NOT NULL,
  `people_id` int(10) unsigned NOT NULL,
  `position` tinyint(3) unsigned NOT NULL COMMENT '0-based cast rank in TMDB order',
  PRIMARY KEY (`people_id`,`videos_id`),
  KEY `ix_video_people_video` (`videos_id`,`position`),
  CONSTRAINT `fk_video_people_people_id` FOREIGN KEY (`people_id`) REFERENCES `people` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `videos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `videos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Show ID to be used in other tables as reference ',
  `type` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0 = TV, 1 = Film, 2 = Anime',
  `title` varchar(180) NOT NULL COMMENT 'Name of the video.',
  `countries_id` char(2) NOT NULL DEFAULT '' COMMENT 'Two character country code (FK to countries table).',
  `started` datetime NOT NULL COMMENT 'Date (UTC) of production''s first airing.',
  `anidb` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'ID number for anidb site',
  `imdb` varchar(100) NOT NULL DEFAULT '0' COMMENT 'ID number for IMDB site (without the ''tt'' prefix).',
  `tmdb` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'ID number for TMDB site.',
  `trakt` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'ID number for TraktTV site.',
  `tvdb` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'ID number for TVDB site',
  `tvmaze` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'ID number for TVMaze site.',
  `tvrage` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'ID number for TVRage site.',
  `source` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Which site did we use for info?',
  PRIMARY KEY (`id`),
  UNIQUE KEY `ix_videos_title` (`title`,`type`,`started`,`countries_id`),
  KEY `ix_videos_type_source` (`type`,`source`),
  KEY `ix_videos_imdb` (`imdb`),
  KEY `ix_videos_tmdb` (`tmdb`),
  KEY `ix_videos_trakt` (`trakt`),
  KEY `ix_videos_tvdb` (`tvdb`),
  KEY `ix_videos_tvmaze` (`tvmaze`),
  KEY `ix_videos_tvrage` (`tvrage`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `videos_aliases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `videos_aliases` (
  `videos_id` int(10) unsigned NOT NULL COMMENT 'FK to videos.id of the parent title.',
  `title` varchar(180) NOT NULL COMMENT 'AKA of the video.',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`videos_id`,`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

/*M!999999\- enable the sandbox mode */
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'2014_01_16_195548_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'2014_02_01_311070_create_firewall_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'2017_11_29_223842_create_countries_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2018_01_17_150719_create_permission_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2018_01_17_154034_create_categories_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2018_01_18_101314_create_category_regexes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2018_01_18_102213_create_collection_regexes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2018_01_18_102716_create_binaryblacklist_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2018_01_18_103104_create_content_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2018_01_18_103520_create_forumpost_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2018_01_18_103816_create_genres_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2018_01_18_104345_create_usenet_groups_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2018_01_18_105455_create_release_naming_regexes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2018_01_18_105834_create_settings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2018_01_20_195500_create_collections_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2018_01_20_195528_create_releases_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2018_01_20_195604_create_anidb_episodes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2018_01_20_195615_create_anidb_info_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2018_01_20_195624_create_anidb_titles_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2018_01_20_195636_create_audio_data_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2018_01_20_195648_create_binaries_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2018_01_20_195703_create_bookinfo_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2018_01_20_195716_create_consoleinfo_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2018_01_20_195728_create_dnzb_failures_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2018_01_20_195739_create_gamesinfo_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2018_01_20_195752_create_invitations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2018_01_20_195801_create_logging_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2018_01_20_195812_create_missed_parts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2018_01_20_195822_create_movieinfo_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2018_01_20_195832_create_musicinfo_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2018_01_20_195915_create_par_hashes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2018_01_20_195925_create_parts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2018_01_20_195934_create_predb_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2018_01_20_195954_create_predb_imports_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2018_01_20_200005_create_release_comments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2018_01_20_200018_create_releases_groups_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2018_01_20_200030_create_release_regexes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2018_01_20_200038_create_release_unique_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2018_01_20_200056_create_release_files_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2018_01_20_200104_create_release_nfos_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2018_01_20_200124_create_release_subtitles_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2018_01_20_200151_create_short_groups_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2018_01_20_200200_create_steam_apps_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2018_01_20_200211_create_tv_episodes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2018_01_20_200218_create_tv_info_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2018_01_20_200237_create_users_releases_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2018_01_20_200248_create_user_downloads_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2018_01_20_200318_create_user_movies_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2018_01_20_200328_create_user_requests_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2018_01_20_200336_create_user_series_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2018_01_20_200346_create_video_data_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2018_01_20_200353_create_videos_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2018_01_20_200403_create_videos_aliases_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2018_04_24_132758_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (59,'2018_08_08_100000_create_telescope_entries_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'2018_09_13_070520_add_verification_to_user_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (61,'2019_02_20_102034_create_failed_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (62,'2019_03_11_234818_create_root_categories_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (63,'2019_03_12_090532_change_categories_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (64,'2019_03_12_093837_add_foreign_categories_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (65,'2019_04_04_130055_update_releases_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (66,'2019_04_04_150842_update_movieinfo_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (67,'2019_04_04_152238_update_user_movies_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (68,'2019_06_14_095012_create_role_expiration_emails_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (69,'2019_08_06_140408_create_invitation_user_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (70,'2019_08_23_132941_change_passwordststatus_releases_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (71,'2019_10_10_231045_create_paypal_payments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (72,'2019_10_15_215953_update_tv_episodes_firstaired_column',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (73,'2019_10_18_205920_add_timestamps_to_videos_aliases',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (74,'2019_12_14_000001_create_personal_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (75,'2019_12_30_190950_update_imdb_column_videos_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (76,'2020_01_07_001831_add_unique_index_to_api_token',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (77,'2020_02_17_213449_add_timezone_column_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (78,'2020_03_07_213224_remove_text_hash',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (79,'2020_07_09_223527_create_release_informs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (80,'2020_08_08_212118_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (81,'2020_09_27_163455_add_uuid_to_failed_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (82,'2020_12_27_214949_create_password_securities_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (83,'2022_02_07_220221_add_timestamps_columns_to_missed_parts',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (84,'2023_06_07_000001_create_pulse_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (85,'2023_07_04_211406_add_next_roles_and_rolechangedate_columns_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (86,'2023_12_08_191845_update_users_table_with_name_column',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (87,'2024_01_06_173518_create_payments_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (88,'2019_08_14_123627_create_poster_renames_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (89,'2019_08_15_145634_add_source_to_releases_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (91,'2024_01_11_203725_create_predb_crcs_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (92,'2024_01_12_193533_alter_filedate_column_predb_crcs_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (93,'2024_01_12_194256_add_back_timestamps_column_to__predb_crcs_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (94,'2024_02_13_234425_add_indexes_to_movieinfo_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (95,'2024_02_25_162628_add_id_column_to_steam_apps_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (96,'2014_05_19_151759_create_forum_table_categories',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (97,'2014_05_19_152425_create_forum_table_threads',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (98,'2014_05_19_152611_create_forum_table_posts',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (99,'2015_04_14_180344_create_forum_table_threads_read',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (100,'2015_07_22_181406_update_forum_table_categories',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (101,'2015_07_22_181409_update_forum_table_threads',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (102,'2015_07_22_181417_update_forum_table_posts',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (103,'2016_05_24_114302_add_defaults_to_forum_table_threads_columns',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (104,'2016_07_09_111441_add_counts_to_categories_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (105,'2016_07_09_122706_add_counts_to_threads_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (106,'2016_07_10_134700_add_sequence_to_posts_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (107,'2018_11_04_211718_update_categories_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (108,'2019_09_07_210904_update_forum_category_booleans',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (109,'2019_09_07_230148_add_color_to_categories',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (110,'2020_03_22_050710_add_thread_ids_to_categories',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (111,'2020_03_22_055827_add_post_id_to_threads',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (112,'2020_12_02_233754_add_first_post_id_to_threads',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (113,'2021_07_31_094750_add_fk_indices',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (114,'2024_03_30_095622_update_forum_category_colors',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (115,'2024_04_27_124202_create_media_infos_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (116,'2024_05_12_192646_add_uuid_column_to_failed_jobs_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (117,'2024_05_25_122046_drop_stored_procedure',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (118,'2024_08_31_084308_add_content_approval_support',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (119,'2024_09_08_151127_create_grab_stats_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (120,'2024_09_08_151135_create_signup_stats_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (121,'2024_09_08_151158_create_role_stats_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (122,'2024_09_08_151214_create_download_stats_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (123,'2024_09_08_151223_create_release_stats_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (124,'2024_09_15_184830_add_xxx_vr_category',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (125,'2024_10_28_092803_drop_columns_from_settings_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (126,'2024_10_28_115551_rename_lookuptvrage_to_lookuptv',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (127,'2024_11_16_114640_add_invoice_status_to_payments_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (128,'2025_01_01_000000_create_invitations_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (129,'2025_01_30_115835_drop_triggers',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (130,'2025_04_10_202844_add_created_at_updated_at_columns',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (131,'2025_05_04_212955_drop_userseed',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (132,'2025_06_06_000000_add_soft_deletes_to_users_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (133,'2025_08_10_195505_fix_invitations_column_names',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (134,'2025_08_14_000001_add_indexes_to_collections_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (135,'2025_08_22_133811_drop_reqidstatus_column',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (136,'2025_08_28_000000_add_onlyfans_category_to_categories_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (137,'2025_10_16_101145_add_dark_mode_to_users_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (138,'2025_10_16_120000_update_dark_mode_to_theme_preference',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (139,'2025_10_20_132950_convert_content_table_to_utf8mb4',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (140,'2025_10_22_000000_create_system_metrics_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (141,'2025_10_22_204937_add_sort_date_to_signup_stats_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (142,'2025_10_23_094253_add_timezone_to_users_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (143,'2025_10_23_100000_add_movie_layout_to_users_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (144,'2025_10_24_000000_create_user_activity_stats_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (145,'2025_10_27_165328_create_user_activities_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (146,'2025_10_29_000000_create_user_activity_stats_hourly_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (147,'2025_11_05_095815_fix_release_files_collation',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (148,'2025_11_05_104146_fix_all_tables_collation_to_utf8mb4',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (149,'2025_11_05_120504_fix_telescope_tables_collation',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (150,'2025_11_28_000000_create_role_promotions_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (151,'2025_11_29_000000_create_role_promotion_stats_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (152,'2025_11_29_120000_add_role_stacking_fields_to_users_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (153,'2025_11_29_120001_create_user_role_history_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (154,'2025_12_04_000000_add_redis_args_to_settings_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (155,'2025_12_05_000000_replace_anidb_with_anilist',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (156,'2025_12_21_000000_add_categories_postdate_index_to_releases_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (157,'2025_12_21_000001_add_covering_index_for_tv_search',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (158,'2025_12_22_000000_add_additional_performance_indexes_to_releases_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (159,'2025_12_22_120000_add_composite_indexes_for_admin_performance',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (160,'2026_01_15_000000_fix_invitations_foreign_key',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (161,'2026_01_15_000001_cleanup_invitations_legacy_columns',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (162,'2026_01_23_150053_create_user_excluded_categories_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (163,'2026_01_26_105835_add_releases_categories_videos_index',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (164,'2026_02_01_000000_create_release_reports_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (165,'2026_02_09_114731_add_deleted_by_to_users_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (166,'2026_02_11_000000_remove_ishashed_and_dehashstatus_columns',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (167,'2026_02_12_000000_add_covering_index_for_movie_browse',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (168,'2026_02_13_000000_drop_xxxinfo_and_releases_xxxinfo_id',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (169,'2026_02_19_000000_add_pp_timeout_count_to_releases_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (170,'2026_03_03_120000_add_color_scheme_to_users_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (171,'2026_03_10_000000_create_registration_periods_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (172,'2026_03_10_000001_create_registration_status_history_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (173,'2026_03_11_120000_add_lastdownload_to_users_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (174,'2026_03_18_000000_replace_vendor_countries_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (175,'2026_03_19_150944_add_depth_to_categories',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (176,'2026_03_31_000000_add_missing_performance_indexes',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (177,'2026_04_01_000000_create_service_statuses_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (178,'2026_04_01_000001_create_service_incidents_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (179,'2026_04_01_120000_add_user_sort_column_indexes',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (180,'2026_04_01_120000_service_incidents_many_services',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (181,'2026_04_01_130000_add_endpoint_url_to_service_statuses',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (182,'2026_04_01_130001_add_is_auto_to_service_incidents',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (183,'2026_04_02_170000_fix_add_depth_to_forum_categories',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (184,'2026_04_09_120000_normalize_padded_imdb_ids',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (185,'2026_04_13_000002_add_probe_fields_to_service_statuses_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (186,'2026_04_24_000000_create_passkeys_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (187,'2026_04_27_000000_add_is_permanent_to_user_activities',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (188,'2026_05_05_123242_add_cbp_query_indexes',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (189,'2026_05_05_124540_phase2_shrink_binaries_binaryhash',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (190,'2026_05_06_093900_restore_cbp_foreign_keys',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (191,'2026_05_08_154620_add_session_token_to_users_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (192,'2026_05_08_180000_drop_nzb_guid_columns',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (193,'2026_05_12_000000_add_ix_releases_searchname',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (194,'2026_06_08_000000_add_response_fields_to_release_reports_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (195,'2026_06_10_000000_create_trusted_devices_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (196,'2026_06_11_000000_create_password_reset_tokens_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (197,'2026_06_11_000001_add_resetguid_to_users_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (198,'2026_06_17_000000_create_gdpr_requests_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (199,'2026_06_17_000000_update_rss_service_status_endpoint_to_health',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (200,'2026_06_17_000001_create_gdpr_consents_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (201,'2026_06_17_000001_neutralize_rss_http_400_false_positive_incidents',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (202,'2026_06_17_000002_create_gdpr_audit_logs_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (203,'2026_07_12_000000_add_additional_postprocessing_claim_fields',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (204,'2026_07_13_000000_add_admin_list_performance_indexes',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (205,'2026_07_13_000000_add_nzb_creation_claim_fields',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (206,'2026_07_15_000000_add_releases_adddate_id_index',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (207,'2026_08_02_000000_create_search_index_failures_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (208,'2026_08_03_000000_prepare_cbp_optimized_storage',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (209,'2026_08_03_000001_finalize_cbp_binary_hash_storage',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (210,'2026_08_04_082439_add_fix_release_name_query_indexes',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (211,'2026_08_10_192527_convert_size_settings_to_bytes',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (212,'2026_08_12_135248_add_single_active_session_setting',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (213,'2026_08_13_001652_normalize_and_optimize_releases_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (214,'2026_08_13_133021_add_isbn_indexes_to_bookinfo_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (215,'2026_08_14_000000_add_discard_executables_support',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (216,'2026_08_14_120000_add_collectionhash_to_releases_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (217,'2026_08_14_121946_optimize_releases_claim_tokens_and_pp_index',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (218,'2026_08_14_200000_add_per_root_preview_generation_toggle',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (219,'2026_08_15_171915_add_obfuscated_name_routing_to_usenet_groups_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (220,'2026_08_16_024113_add_descriptive_title_rename_setting',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (221,'2026_08_16_142055_add_name_trust_to_releases_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (222,'2026_08_16_155110_add_srrdb_name_fixing_support',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (223,'2026_08_17_170310_create_database_backups_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (224,'2026_08_17_171303_add_database_backup_settings',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (225,'2026_08_17_204656_normalize_backfill_settings',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (226,'2026_08_18_011844_add_banner_to_tv_info_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (227,'2026_08_19_120830_add_poster_identity_browse_index_to_releases_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (228,'2026_08_20_120000_add_forced_root_category_to_usenet_groups_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (229,'2026_08_20_150000_add_repair_state_to_releases_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (230,'2026_08_21_090000_create_release_audio_tags_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (231,'2026_08_21_120000_add_declared_files_and_article_anchors',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (232,'2026_08_21_120100_add_rescan_state_to_releases_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (233,'2026_08_21_120200_add_repair_and_rescan_settings',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (234,'2026_08_21_140000_add_audio_postprocessing_settings',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (235,'2026_08_21_180000_add_fix_names_timeout_setting',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (236,'2026_08_22_080000_add_audio_archive_fetch_ceiling_setting',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (237,'2026_08_23_160050_widen_settings_name_column',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (238,'2026_08_24_170559_add_recovery_claim_to_releases_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (239,'2026_08_25_111343_add_name_source_status_columns_to_releases_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (240,'2026_08_25_115031_backfill_predb_attachment_name_state',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (241,'2026_08_25_124728_add_predb_search_lifecycle_to_predb_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (242,'2026_08_25_132542_add_repair_target_completion_to_releases_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (243,'2026_08_25_162248_add_tv_episode_revisit_state_to_releases_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (244,'2026_08_25_171914_add_searchname_normalized_to_releases_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (245,'2026_08_25_213606_delete_collections_referencing_missing_releases',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (246,'2026_08_27_120000_add_per_root_dynamic_preview_budget_toggle',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (247,'2026_08_27_120100_add_dynamic_preview_budget_settings',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (248,'2026_08_27_150000_add_per_root_clip_toggle',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (249,'2026_08_27_150100_create_release_video_clips_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (250,'2026_08_27_210000_add_display_name_to_releases_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (251,'2026_08_28_120000_add_compressed_preview_budget_settings',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (252,'2026_08_28_120000_create_release_imagery_disk_skips_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (253,'2026_08_28_130000_rebuild_release_display_names',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (254,'2026_08_28_150000_add_absorb_attempts_to_collections_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (255,'2026_08_29_170512_add_audio_min_completion_percent_setting',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (256,'2026_08_29_213637_update_boneless_collection_regexes_for_consistent_grouping',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (257,'2026_08_30_204947_create_release_audio_evidence_tables',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (258,'2026_08_30_221253_add_music_identity_settings_and_status_probe',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (259,'2026_08_31_000729_create_release_music_identification_tables',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (260,'2026_08_31_013417_create_release_music_synthesis_attempts_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (261,'2026_08_31_145035_add_forced_root_pc_escape_setting',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (262,'2026_09_04_164905_add_amazonsleep_setting',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (263,'2026_09_05_120000_drop_settings_with_no_reader',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (264,'2026_09_05_212852_add_collection_ingestion_frontiers',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (265,'2026_09_05_213352_create_usenet_group_ingested_ranges_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (266,'2026_09_06_000000_create_media_info_snapshot_tables',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (267,'2026_09_07_172435_add_obfuscation_recovery_storage',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (268,'2026_09_08_121212_fix_numbered_payload_par2_collection_regex',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (269,'2026_09_08_121907_create_collection_reconciliation_tables',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (270,'2026_09_08_205107_create_par2_sidecar_evidence_tables',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (271,'2026_09_09_140000_swap_stock_token_prefixed_archive_collection_regex',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (272,'2026_09_09_140100_add_post_processing_candidate_indexes',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (273,'2026_09_10_105328_add_imdb_lookup_retry_state_to_releases_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (274,'2026_09_10_133215_add_reconciliation_budget_controls',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (275,'2026_09_10_134847_add_reconciliation_admissions',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (276,'2026_09_10_140952_create_reconciled_artifact_operations',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (277,'2026_09_10_175414_add_collections_admission_window_index',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (278,'2026_09_10_224820_create_collection_sweep_cursors_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (279,'2026_09_10_231728_add_name_direct_work_index_to_releases',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (280,'2026_09_11_160000_add_release_formation_queue_indexes',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (281,'2026_09_13_002751_add_recovery_frontier_evidence',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (282,'2026_09_13_120000_fix_numbered_mkv_archive_collection_regex',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (283,'2026_09_13_155226_add_recovery_frontier_repair_allowances',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (284,'2026_09_13_190549_add_recovery_frontier_request_attribution',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (285,'2026_09_14_011408_add_view_prefs_to_users_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (286,'2026_09_14_090154_add_recovery_codes_to_password_securities_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (287,'2026_09_14_110835_add_recovery_handoff_and_process_identity',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (288,'2026_09_14_135356_add_par2_naming_setting',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (289,'2026_09_16_133405_add_movie_record_retry_state_to_releases_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (290,'2026_09_17_170000_add_can_post_to_users_table',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (291,'2026_09_18_120000_bucket_obfuscation_recovery_dirty_marks',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (292,'2026_09_18_180000_add_retries_to_obfuscation_recovery_gaps',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (293,'2026_09_19_000000_gate_release_file_name_sources_on_evidence',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (294,'2026_09_24_000000_add_resolution_and_source_to_releases',11);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (295,'2026_09_24_100000_drop_color_scheme_from_users_table',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (296,'2026_09_24_200000_create_release_tv_episodes_table',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (297,'2026_09_25_000000_add_show_details_to_tv_info',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (298,'2026_09_25_100000_refill_release_tv_episodes',15);
