-- Adminer 4.8.1 MySQL 8.0.30 dump

SET NAMES utf8;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;
SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';

SET NAMES utf8mb4;

DROP TABLE IF EXISTS `author_subject_edges`;
CREATE TABLE `author_subject_edges` (
  `journal_id` int NOT NULL,
  `author_id` bigint NOT NULL,
  `subject_id` bigint NOT NULL,
  `weight` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`journal_id`,`author_id`,`subject_id`),
  KEY `author_id` (`author_id`),
  KEY `subject_id` (`subject_id`),
  KEY `idx_weight` (`journal_id`,`weight`),
  CONSTRAINT `author_subject_edges_ibfk_1` FOREIGN KEY (`journal_id`) REFERENCES `journals` (`id`) ON DELETE CASCADE,
  CONSTRAINT `author_subject_edges_ibfk_2` FOREIGN KEY (`author_id`) REFERENCES `authors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `author_subject_edges_ibfk_3` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


DROP TABLE IF EXISTS `authors`;
CREATE TABLE `authors` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `name_key` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_author_key` (`name_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


DROP TABLE IF EXISTS `coauthor_edges`;
CREATE TABLE `coauthor_edges` (
  `journal_id` int NOT NULL,
  `author_a` bigint NOT NULL,
  `author_b` bigint NOT NULL,
  `weight` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`journal_id`,`author_a`,`author_b`),
  KEY `author_a` (`author_a`),
  KEY `author_b` (`author_b`),
  KEY `idx_weight` (`journal_id`,`weight`),
  CONSTRAINT `coauthor_edges_ibfk_1` FOREIGN KEY (`journal_id`) REFERENCES `journals` (`id`) ON DELETE CASCADE,
  CONSTRAINT `coauthor_edges_ibfk_2` FOREIGN KEY (`author_a`) REFERENCES `authors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `coauthor_edges_ibfk_3` FOREIGN KEY (`author_b`) REFERENCES `authors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


DROP TABLE IF EXISTS `global_stats_summary`;
CREATE TABLE `global_stats_summary` (
  `stat_key` varchar(50) NOT NULL,
  `stat_value` bigint DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`stat_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


DROP TABLE IF EXISTS `harvest_runs`;
CREATE TABLE `harvest_runs` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `journal_id` int NOT NULL,
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` datetime DEFAULT NULL,
  `status` enum('running','ok','error') NOT NULL DEFAULT 'running',
  `message` text,
  `total_seen_all` int NOT NULL DEFAULT '0',
  `total_inserted` int NOT NULL DEFAULT '0',
  `total_updated` int NOT NULL DEFAULT '0',
  `total_skipped_dup_title` int NOT NULL DEFAULT '0',
  `active_count` int NOT NULL DEFAULT '0',
  `deleted_count` int NOT NULL DEFAULT '0',
  `pub_earliest` date DEFAULT NULL,
  `pub_latest` date DEFAULT NULL,
  `doi_present` int NOT NULL DEFAULT '0',
  `unique_authors` int NOT NULL DEFAULT '0',
  `unique_subjects` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_journal_time` (`journal_id`,`started_at`),
  CONSTRAINT `harvest_runs_ibfk_1` FOREIGN KEY (`journal_id`) REFERENCES `journals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


DROP TABLE IF EXISTS `journals`;
CREATE TABLE `journals` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `journal_url` varchar(500) DEFAULT NULL,
  `oai_base_url` varchar(500) NOT NULL,
  `metadata_prefix` varchar(50) NOT NULL DEFAULT 'oai_dc',
  `set_spec` varchar(200) DEFAULT NULL,
  `harvest_freq` enum('daily','weekly','manual') NOT NULL DEFAULT 'daily',
  `rumpunilmu_id` int DEFAULT NULL,
  `publisher` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `last_harvest_at` datetime DEFAULT NULL,
  `last_harvest_status` enum('ok','error') DEFAULT NULL,
  `last_harvest_message` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_oai` (`oai_base_url`),
  KEY `fk_journals_rumpunilmu` (`rumpunilmu_id`),
  CONSTRAINT `fk_journals_rumpunilmu` FOREIGN KEY (`rumpunilmu_id`) REFERENCES `rumpunilmu` (`rumpunilmu_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


DROP TABLE IF EXISTS `network_cache`;
CREATE TABLE `network_cache` (
  `cache_key` varchar(50) NOT NULL,
  `cache_data` json DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`cache_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


DROP TABLE IF EXISTS `oai_records`;
CREATE TABLE `oai_records` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `journal_id` int NOT NULL,
  `oai_identifier` varchar(255) NOT NULL,
  `status` enum('active','deleted') NOT NULL DEFAULT 'active',
  `datestamp` varchar(50) DEFAULT NULL,
  `set_spec` varchar(500) DEFAULT NULL,
  `title` text,
  `title_key` varchar(500) DEFAULT NULL,
  `pub_date` date DEFAULT NULL,
  `pub_year` smallint DEFAULT NULL,
  `pub_month` char(7) DEFAULT NULL,
  `dc_title_json` json DEFAULT NULL,
  `dc_creator_json` json DEFAULT NULL,
  `dc_subject_json` json DEFAULT NULL,
  `dc_description_json` json DEFAULT NULL,
  `dc_publisher_json` json DEFAULT NULL,
  `dc_contributor_json` json DEFAULT NULL,
  `dc_date_json` json DEFAULT NULL,
  `dc_type_json` json DEFAULT NULL,
  `dc_format_json` json DEFAULT NULL,
  `dc_identifier_json` json DEFAULT NULL,
  `dc_source_json` json DEFAULT NULL,
  `dc_language_json` json DEFAULT NULL,
  `dc_relation_json` json DEFAULT NULL,
  `dc_coverage_json` json DEFAULT NULL,
  `dc_rights_json` json DEFAULT NULL,
  `url_best` varchar(500) DEFAULT NULL,
  `doi_best` varchar(255) DEFAULT NULL,
  `publisher_best` varchar(255) DEFAULT NULL,
  `language_best` varchar(50) DEFAULT NULL,
  `raw_record_xml` mediumtext,
  `first_seen_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_harvest_run_id` bigint DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_journal_oai` (`journal_id`,`oai_identifier`),
  UNIQUE KEY `uk_journal_title` (`journal_id`,`title_key`),
  KEY `idx_journal_status` (`journal_id`,`status`),
  KEY `idx_journal_pubdate` (`journal_id`,`pub_date`),
  KEY `idx_journal_pubmonth` (`journal_id`,`pub_month`),
  KEY `idx_journal_doi` (`journal_id`,`doi_best`),
  KEY `idx_journal_year` (`journal_id`,`pub_year`),
  CONSTRAINT `oai_records_ibfk_1` FOREIGN KEY (`journal_id`) REFERENCES `journals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


DROP TABLE IF EXISTS `publishers`;
CREATE TABLE `publishers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


DROP TABLE IF EXISTS `record_authors`;
CREATE TABLE `record_authors` (
  `record_id` bigint NOT NULL,
  `author_id` bigint NOT NULL,
  `author_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`record_id`,`author_id`),
  KEY `idx_author` (`author_id`),
  CONSTRAINT `record_authors_ibfk_1` FOREIGN KEY (`record_id`) REFERENCES `oai_records` (`id`) ON DELETE CASCADE,
  CONSTRAINT `record_authors_ibfk_2` FOREIGN KEY (`author_id`) REFERENCES `authors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


DROP TABLE IF EXISTS `record_subjects`;
CREATE TABLE `record_subjects` (
  `record_id` bigint NOT NULL,
  `subject_id` bigint NOT NULL,
  PRIMARY KEY (`record_id`,`subject_id`),
  KEY `idx_subject` (`subject_id`),
  CONSTRAINT `record_subjects_ibfk_1` FOREIGN KEY (`record_id`) REFERENCES `oai_records` (`id`) ON DELETE CASCADE,
  CONSTRAINT `record_subjects_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


DROP TABLE IF EXISTS `rumpunilmu`;
CREATE TABLE `rumpunilmu` (
  `rumpunilmu_id` int NOT NULL AUTO_INCREMENT,
  `nama_rumpun` varchar(255) NOT NULL,
  PRIMARY KEY (`rumpunilmu_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


DROP TABLE IF EXISTS `subject_edges`;
CREATE TABLE `subject_edges` (
  `journal_id` int NOT NULL,
  `subject_a` bigint NOT NULL,
  `subject_b` bigint NOT NULL,
  `weight` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`journal_id`,`subject_a`,`subject_b`),
  KEY `subject_a` (`subject_a`),
  KEY `subject_b` (`subject_b`),
  KEY `idx_weight` (`journal_id`,`weight`),
  CONSTRAINT `subject_edges_ibfk_1` FOREIGN KEY (`journal_id`) REFERENCES `journals` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subject_edges_ibfk_2` FOREIGN KEY (`subject_a`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subject_edges_ibfk_3` FOREIGN KEY (`subject_b`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


DROP TABLE IF EXISTS `subjects`;
CREATE TABLE `subjects` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `label` varchar(255) NOT NULL,
  `label_key` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_subject_key` (`label_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


-- 2026-04-16 07:01:30
