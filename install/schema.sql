-- Clean Room CMS - Database Schema
-- Based on publicly documented WordPress database structure
-- All code is original; table structure follows public specification

CREATE TABLE IF NOT EXISTS `{prefix}posts` (
    `ID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `post_author` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `post_date` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
    `post_date_gmt` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
    `post_content` LONGTEXT NOT NULL,
    `post_title` TEXT NOT NULL,
    `post_excerpt` TEXT NOT NULL,
    `post_status` VARCHAR(20) NOT NULL DEFAULT 'publish',
    `comment_status` VARCHAR(20) NOT NULL DEFAULT 'open',
    `ping_status` VARCHAR(20) NOT NULL DEFAULT 'open',
    `post_password` VARCHAR(255) NOT NULL DEFAULT '',
    `post_name` VARCHAR(200) NOT NULL DEFAULT '',
    `to_ping` TEXT NOT NULL,
    `pinged` TEXT NOT NULL,
    `post_modified` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
    `post_modified_gmt` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
    `post_content_filtered` LONGTEXT NOT NULL,
    `post_parent` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `guid` VARCHAR(255) NOT NULL DEFAULT '',
    `menu_order` INT NOT NULL DEFAULT 0,
    `post_type` VARCHAR(20) NOT NULL DEFAULT 'post',
    `post_mime_type` VARCHAR(100) NOT NULL DEFAULT '',
    `comment_count` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`ID`),
    KEY `post_name` (`post_name`(191)),
    KEY `type_status_date` (`post_type`, `post_status`, `post_date`, `ID`),
    KEY `post_parent` (`post_parent`),
    KEY `post_author` (`post_author`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}postmeta` (
    `meta_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `post_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `meta_key` VARCHAR(255) DEFAULT NULL,
    `meta_value` LONGTEXT,
    PRIMARY KEY (`meta_id`),
    KEY `post_id` (`post_id`),
    KEY `meta_key` (`meta_key`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}users` (
    `ID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_login` VARCHAR(60) NOT NULL DEFAULT '',
    `user_pass` VARCHAR(255) NOT NULL DEFAULT '',
    `user_nicename` VARCHAR(50) NOT NULL DEFAULT '',
    `user_email` VARCHAR(100) NOT NULL DEFAULT '',
    `user_url` VARCHAR(100) NOT NULL DEFAULT '',
    `user_registered` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
    `user_activation_key` VARCHAR(255) NOT NULL DEFAULT '',
    `user_status` INT NOT NULL DEFAULT 0,
    `display_name` VARCHAR(250) NOT NULL DEFAULT '',
    PRIMARY KEY (`ID`),
    KEY `user_login_key` (`user_login`),
    KEY `user_nicename` (`user_nicename`),
    KEY `user_email` (`user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}usermeta` (
    `umeta_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `meta_key` VARCHAR(255) DEFAULT NULL,
    `meta_value` LONGTEXT,
    PRIMARY KEY (`umeta_id`),
    KEY `user_id` (`user_id`),
    KEY `meta_key` (`meta_key`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}terms` (
    `term_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(200) NOT NULL DEFAULT '',
    `slug` VARCHAR(200) NOT NULL DEFAULT '',
    `term_group` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`term_id`),
    KEY `slug` (`slug`(191)),
    KEY `name` (`name`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}term_taxonomy` (
    `term_taxonomy_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `term_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `taxonomy` VARCHAR(32) NOT NULL DEFAULT '',
    `description` LONGTEXT NOT NULL,
    `parent` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `count` BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (`term_taxonomy_id`),
    UNIQUE KEY `term_id_taxonomy` (`term_id`, `taxonomy`),
    KEY `taxonomy` (`taxonomy`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}term_relationships` (
    `object_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `term_taxonomy_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `term_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`object_id`, `term_taxonomy_id`),
    KEY `term_taxonomy_id` (`term_taxonomy_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}termmeta` (
    `meta_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `term_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `meta_key` VARCHAR(255) DEFAULT NULL,
    `meta_value` LONGTEXT,
    PRIMARY KEY (`meta_id`),
    KEY `term_id` (`term_id`),
    KEY `meta_key` (`meta_key`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}comments` (
    `comment_ID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `comment_post_ID` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `comment_author` TINYTEXT NOT NULL,
    `comment_author_email` VARCHAR(100) NOT NULL DEFAULT '',
    `comment_author_url` VARCHAR(200) NOT NULL DEFAULT '',
    `comment_author_IP` VARCHAR(100) NOT NULL DEFAULT '',
    `comment_date` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
    `comment_date_gmt` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
    `comment_content` TEXT NOT NULL,
    `comment_karma` INT NOT NULL DEFAULT 0,
    `comment_approved` VARCHAR(20) NOT NULL DEFAULT '1',
    `comment_agent` VARCHAR(255) NOT NULL DEFAULT '',
    `comment_type` VARCHAR(20) NOT NULL DEFAULT 'comment',
    `comment_parent` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`comment_ID`),
    KEY `comment_post_ID` (`comment_post_ID`),
    KEY `comment_approved_date_gmt` (`comment_approved`, `comment_date_gmt`),
    KEY `comment_date_gmt` (`comment_date_gmt`),
    KEY `comment_parent` (`comment_parent`),
    KEY `comment_author_email` (`comment_author_email`(10))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}commentmeta` (
    `meta_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `comment_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `meta_key` VARCHAR(255) DEFAULT NULL,
    `meta_value` LONGTEXT,
    PRIMARY KEY (`meta_id`),
    KEY `comment_id` (`comment_id`),
    KEY `meta_key` (`meta_key`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}options` (
    `option_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `option_name` VARCHAR(191) NOT NULL DEFAULT '',
    `option_value` LONGTEXT NOT NULL,
    `autoload` VARCHAR(20) NOT NULL DEFAULT 'yes',
    PRIMARY KEY (`option_id`),
    UNIQUE KEY `option_name` (`option_name`),
    KEY `autoload` (`autoload`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}content_types` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(20) NOT NULL,
    `label` VARCHAR(200) NOT NULL,
    `label_singular` VARCHAR(200) NOT NULL DEFAULT '',
    `description` TEXT,
    `icon` VARCHAR(50) NOT NULL DEFAULT '',
    `public` TINYINT(1) NOT NULL DEFAULT 1,
    `hierarchical` TINYINT(1) NOT NULL DEFAULT 0,
    `show_in_rest` TINYINT(1) NOT NULL DEFAULT 1,
    `rest_base` VARCHAR(100) NOT NULL DEFAULT '',
    `has_archive` TINYINT(1) NOT NULL DEFAULT 1,
    `supports` JSON NOT NULL,
    `exclude_from_search` TINYINT(1) NOT NULL DEFAULT 0,
    `menu_position` INT NOT NULL DEFAULT 25,
    `status` VARCHAR(20) NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}content_taxonomies` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(32) NOT NULL,
    `label` VARCHAR(200) NOT NULL,
    `label_singular` VARCHAR(200) NOT NULL DEFAULT '',
    `description` TEXT,
    `hierarchical` TINYINT(1) NOT NULL DEFAULT 0,
    `public` TINYINT(1) NOT NULL DEFAULT 1,
    `show_in_rest` TINYINT(1) NOT NULL DEFAULT 1,
    `rest_base` VARCHAR(100) NOT NULL DEFAULT '',
    `post_types` JSON NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{prefix}meta_fields` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `label` VARCHAR(200) NOT NULL,
    `description` TEXT,
    `object_type` VARCHAR(20) NOT NULL DEFAULT 'post',
    `post_type` VARCHAR(20) NOT NULL DEFAULT '',
    `field_type` VARCHAR(50) NOT NULL DEFAULT 'text',
    `options` JSON NOT NULL,
    `default_value` TEXT,
    `placeholder` VARCHAR(200) NOT NULL DEFAULT '',
    `required` TINYINT(1) NOT NULL DEFAULT 0,
    `validation` JSON NOT NULL,
    `position` INT NOT NULL DEFAULT 0,
    `group_name` VARCHAR(100) NOT NULL DEFAULT 'Custom Fields',
    `show_in_rest` TINYINT(1) NOT NULL DEFAULT 1,
    `show_in_list` TINYINT(1) NOT NULL DEFAULT 0,
    `searchable` TINYINT(1) NOT NULL DEFAULT 0,
    `status` VARCHAR(20) NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `name_post_type` (`name`, `post_type`, `object_type`),
    KEY `post_type` (`post_type`),
    KEY `object_type` (`object_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Content Builder: field groups (ACF-style)
CREATE TABLE IF NOT EXISTS `{prefix}field_groups` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `label` VARCHAR(200) NOT NULL,
    `description` TEXT,
    `position` INT NOT NULL DEFAULT 0,
    `location_rules` JSON NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
