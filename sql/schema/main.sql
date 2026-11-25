-- CryptoNoises: Bots + API database (dbMain / dbLog, kSECRET_MYSQL_HOST_MAIN)
-- Use any database name and set it in config/consts-secrets.php.
-- Apply to an empty database: mysql <db_name> < sql/schema/main.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;   -- tables are in alphabetical order, foreign keys are checked at the end

CREATE TABLE IF NOT EXISTS `address` (
  `adid` int unsigned NOT NULL AUTO_INCREMENT,
  `address` varbinary(36) NOT NULL DEFAULT '',
  `address_ci` varchar(36) DEFAULT NULL,
  PRIMARY KEY (`adid`),
  UNIQUE KEY `address` (`address`),
  KEY `address_ci` (`address_ci`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `callback_actions` (
  `callback_auid` varchar(64) NOT NULL DEFAULT '',
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `scope` varchar(40) NOT NULL DEFAULT '',
  `event` varchar(40) NOT NULL DEFAULT '',
  `data` text NOT NULL,
  PRIMARY KEY (`callback_auid`),
  KEY `ts_created` (`ts_created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `config` (
  `cfg_key` varchar(64) NOT NULL DEFAULT '',
  `cfg_value` varchar(1024) NOT NULL DEFAULT '',
  `ts_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`cfg_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `currency_in` (
  `currency_in_id` varchar(16) NOT NULL DEFAULT '',
  `coin_gecko_id` varchar(32) NOT NULL DEFAULT '',
  `name` varchar(32) NOT NULL DEFAULT '',
  `is_enabled` tinyint NOT NULL DEFAULT 1,
  `is_public` tinyint NOT NULL DEFAULT 1,
  `exchange_normalize_mult` bigint unsigned NOT NULL DEFAULT 1,
  `exchange_normalize_div` bigint unsigned NOT NULL DEFAULT 1,
  `order_index` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`currency_in_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `currency_out` (
  `currency_out_id` varchar(16) NOT NULL DEFAULT '',
  `type` enum('crypto','fiat','commodity') DEFAULT NULL,
  `name` varchar(32) NOT NULL DEFAULT '',
  `is_enabled` tinyint NOT NULL DEFAULT 1,
  `is_main` tinyint NOT NULL DEFAULT 0,
  `decimals` tinyint NOT NULL DEFAULT 8,
  `order_index` int DEFAULT NULL,
  `_country` varchar(64) DEFAULT NULL,
  `_currency_name` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`currency_out_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `currency_values` (
  `currency_in_id` varchar(16) NOT NULL DEFAULT '',
  `currency_out_id` varchar(16) NOT NULL DEFAULT '',
  `value` decimal(24,8) NOT NULL,
  `ts_event` timestamp NOT NULL,
  PRIMARY KEY (`currency_in_id`,`currency_out_id`),
  KEY `currency_id` (`currency_out_id`),
  CONSTRAINT `currency_values_ibfk_1` FOREIGN KEY (`currency_in_id`) REFERENCES `currency_in` (`currency_in_id`) ON UPDATE CASCADE,
  CONSTRAINT `currency_values_ibfk_2` FOREIGN KEY (`currency_out_id`) REFERENCES `currency_out` (`currency_out_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `discord_guilds` (
  `guild_id` bigint NOT NULL,
  `guild_name` varchar(255) DEFAULT NULL,
  `member_count` int unsigned DEFAULT NULL,
  `ts_joined` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`guild_id`),
  KEY `ts_joined` (`ts_joined`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `discord_missed_messages` (
  `idx` int unsigned NOT NULL AUTO_INCREMENT,
  `ts_event` timestamp NOT NULL DEFAULT current_timestamp(),
  `type` enum('user','channel') DEFAULT NULL,
  `state` enum('notified','ignored') DEFAULT NULL,
  `platform_user_id` bigint unsigned DEFAULT NULL,
  `channel_id` bigint unsigned DEFAULT NULL,
  `guild_id` bigint unsigned DEFAULT NULL,
  `message` text DEFAULT NULL,
  `notice` varchar(1024) DEFAULT NULL,
  PRIMARY KEY (`idx`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `discord_users` (
  `discord_user_id` bigint unsigned NOT NULL,
  `username` varchar(255) DEFAULT NULL,
  `discriminator` smallint unsigned DEFAULT NULL,
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_last_fixed` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_deactivated` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`discord_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `events_flow` (
  `event_flow_id` int unsigned NOT NULL AUTO_INCREMENT,
  `network_id` enum('?','tezos') NOT NULL DEFAULT '?',
  `network_block` int unsigned NOT NULL,
  `event_type` varchar(32) NOT NULL,
  `entity_uid` bigint NOT NULL,
  `entity_subid` bigint NOT NULL DEFAULT 0,
  `event_details` varchar(32) DEFAULT NULL,
  `ts_event` timestamp NOT NULL DEFAULT current_timestamp(),
  `sender_address` varchar(64) DEFAULT NULL,
  `target_address` varchar(64) DEFAULT NULL,
  `royalty_address` varchar(64) DEFAULT NULL,
  `fee_address` varchar(64) DEFAULT NULL,
  `price` double DEFAULT NULL,
  `revenue` double DEFAULT NULL,
  `royalty` double DEFAULT NULL,
  `fee` double DEFAULT NULL,
  `currency` varchar(16) DEFAULT NULL,
  `currency_address` varchar(64) DEFAULT NULL,
  `currency_address_2nd` varchar(64) DEFAULT NULL,
  `quantity` double unsigned NOT NULL DEFAULT 1,
  `royalty_percent` smallint unsigned DEFAULT NULL,
  `marketplace_address` varchar(64) DEFAULT NULL,
  `token_address` varchar(64) DEFAULT NULL,
  `token_id` varchar(968) DEFAULT NULL,
  `transaction_counter` int unsigned DEFAULT NULL,
  `transaction_hash` mediumtext DEFAULT NULL,
  `transaction_event` timestamp NOT NULL,
  `transaction_entrypoint` varchar(255) DEFAULT NULL,
  `custom_data` text DEFAULT NULL,
  PRIMARY KEY (`event_flow_id`),
  UNIQUE KEY `network_id` (`network_id`,`network_block`,`event_type`,`entity_uid`,`entity_subid`),
  KEY `event_type` (`event_type`),
  KEY `ts_event` (`ts_event`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `events_notifications_discord` (
  `event_notification_id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `owner_adid` int unsigned DEFAULT NULL,
  `owner_address` varchar(64) DEFAULT NULL,
  `event_flow_id` int unsigned NOT NULL,
  `notification_type` varchar(32) NOT NULL DEFAULT '',
  `priority_index` tinyint NOT NULL DEFAULT 3,
  `notify_status` enum('idle','active','done','skipped','error') NOT NULL DEFAULT 'idle',
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `exec_time` decimal(9,3) DEFAULT NULL,
  `task_lifetime` int unsigned DEFAULT NULL,
  `note` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`event_notification_id`),
  KEY `owner_adid` (`owner_adid`),
  KEY `event_flow_id` (`event_flow_id`),
  KEY `user_id` (`user_id`),
  KEY `notify_status` (`notify_status`),
  CONSTRAINT `events_notifications_discord_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
  CONSTRAINT `events_notifications_discord_ibfk_2` FOREIGN KEY (`owner_adid`) REFERENCES `address` (`adid`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `events_notifications_telegram` (
  `event_notification_id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `owner_adid` int unsigned DEFAULT NULL,
  `owner_address` varchar(64) DEFAULT NULL,
  `event_flow_id` int unsigned NOT NULL,
  `notification_type` varchar(32) NOT NULL DEFAULT '',
  `priority_index` tinyint NOT NULL DEFAULT 3,
  `notify_status` enum('idle','active','done','skipped','error') NOT NULL DEFAULT 'idle',
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `exec_time` decimal(9,3) DEFAULT NULL,
  `task_lifetime` int unsigned DEFAULT NULL,
  `note` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`event_notification_id`),
  KEY `owner_adid` (`owner_adid`),
  KEY `event_flow_id` (`event_flow_id`),
  KEY `user_id` (`user_id`),
  KEY `notify_status` (`notify_status`),
  CONSTRAINT `events_notifications_telegram_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
  CONSTRAINT `events_notifications_telegram_ibfk_2` FOREIGN KEY (`owner_adid`) REFERENCES `address` (`adid`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exceptions` (
  `exception_type` varchar(32) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `token_address` varchar(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL DEFAULT '',
  `token_id` varchar(768) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `hits` int unsigned NOT NULL DEFAULT 1,
  `notice` varchar(255) NOT NULL DEFAULT '',
  UNIQUE KEY `unique_key` (`exception_type`,`token_address`,`token_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fixer_domains_expiry` (
  `domain` varbinary(1024) NOT NULL DEFAULT '',
  `hours_scope` smallint NOT NULL,
  `ts_event` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`domain`,`hours_scope`),
  KEY `ts_event` (`ts_event`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fixer_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ts_event` timestamp NULL DEFAULT current_timestamp(),
  `scope` varchar(32) NOT NULL,
  `action` varchar(32) NOT NULL,
  `value` bigint DEFAULT NULL,
  `notice` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `scope` (`scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fixer_tezos_transactions_analyze` (
  `level` int NOT NULL,
  `state` enum('idle','processing','success','error') NOT NULL DEFAULT 'idle',
  `items_count` int unsigned DEFAULT NULL,
  `notice` text DEFAULT NULL,
  `ts_scan_started` timestamp NULL DEFAULT current_timestamp(),
  `ts_scan_finished` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`level`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inform_flow` (
  `inform_flow_id` int unsigned NOT NULL AUTO_INCREMENT,
  `ts_event` timestamp NOT NULL DEFAULT current_timestamp(),
  `title` varchar(255) NOT NULL DEFAULT '',
  `message` text NOT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `notice` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`inform_flow_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inform_notifications_discord` (
  `inform_notification_id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `inform_flow_id` int unsigned NOT NULL,
  `priority_index` tinyint NOT NULL DEFAULT 3,
  `notify_status` enum('idle','active','done','error') NOT NULL DEFAULT 'idle',
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `exec_time` decimal(9,3) DEFAULT NULL,
  `note` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`inform_notification_id`),
  UNIQUE KEY `unique_set` (`user_id`,`inform_flow_id`),
  KEY `user_id` (`user_id`),
  KEY `inform_flow_id` (`inform_flow_id`),
  CONSTRAINT `inform_notifications_discord_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
  CONSTRAINT `inform_notifications_discord_ibfk_2` FOREIGN KEY (`inform_flow_id`) REFERENCES `inform_flow` (`inform_flow_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inform_notifications_telegram` (
  `inform_notification_id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `inform_flow_id` int unsigned NOT NULL,
  `priority_index` tinyint NOT NULL DEFAULT 3,
  `notify_status` enum('idle','active','done','error') NOT NULL DEFAULT 'idle',
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `exec_time` decimal(9,3) DEFAULT NULL,
  `note` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`inform_notification_id`),
  UNIQUE KEY `unique_set` (`user_id`,`inform_flow_id`),
  KEY `user_id` (`user_id`),
  KEY `inform_flow_id` (`inform_flow_id`),
  CONSTRAINT `inform_notifications_telegram_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
  CONSTRAINT `inform_notifications_telegram_ibfk_2` FOREIGN KEY (`inform_flow_id`) REFERENCES `inform_flow` (`inform_flow_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `log_discord_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ts_event` timestamp NOT NULL DEFAULT current_timestamp(),
  `event_scope` varchar(32) NOT NULL DEFAULT '',
  `event_action` varchar(32) DEFAULT NULL,
  `guild_id` bigint DEFAULT NULL,
  `entity_id` bigint DEFAULT NULL,
  `raw_data` mediumtext DEFAULT NULL,
  `raw_data_prev` mediumtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `event_scope` (`event_scope`),
  KEY `event_action` (`event_action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `log_errors` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ts_event` timestamp NOT NULL DEFAULT current_timestamp(),
  `level` enum('fatal','error','warning','notice') DEFAULT NULL,
  `scope` varchar(255) NOT NULL DEFAULT '',
  `action` varchar(255) NOT NULL DEFAULT '',
  `message` varchar(4096) NOT NULL DEFAULT '',
  `data` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `log_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ts_event` timestamp NOT NULL DEFAULT current_timestamp(),
  `event_id` varchar(32) NOT NULL DEFAULT '',
  `platform` enum('discord','telegram') DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `message` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `log_exceptions_hits` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `exception_type` varchar(32) NOT NULL,
  `token_address` varchar(64) NOT NULL DEFAULT '',
  `token_id` varchar(768) DEFAULT NULL,
  `ts_event` timestamp NOT NULL DEFAULT current_timestamp(),
  `notice` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `unique_key` (`exception_type`),
  KEY `token_address` (`token_address`),
  KEY `token_id` (`token_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `log_redirects` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ts_event` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('success','error') DEFAULT NULL,
  `scope` varchar(64) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `token_id` varchar(1024) DEFAULT NULL,
  `redirect_to_url` varchar(255) DEFAULT NULL,
  `notice` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `options_notifier` (
  `user_id` int unsigned NOT NULL DEFAULT 0,
  `wallet_adid` int unsigned NOT NULL DEFAULT 0,
  `notify_income` enum('yes','no') DEFAULT NULL,
  `notify_expense` enum('yes','no') DEFAULT NULL,
  `notify_token_sold` enum('yes','no') DEFAULT NULL,
  `notify_token_purchased` enum('yes','no') DEFAULT NULL,
  `notify_token_royalty` enum('yes','no') DEFAULT NULL,
  `notify_token_offer` enum('yes','no') DEFAULT NULL,
  `notify_token_receive` enum('yes','no') DEFAULT NULL,
  `notify_token_send` enum('yes','no') DEFAULT NULL,
  `notify_token_mint` enum('yes','no') DEFAULT NULL,
  `notify_token_list` enum('yes','no') DEFAULT NULL,
  `notify_token_update_metadata` enum('yes','no') DEFAULT NULL,
  `notify_token_comment` enum('yes','no') DEFAULT NULL,
  `notify_staking` enum('yes','no') DEFAULT NULL,
  `notify_origination` enum('yes','no') DEFAULT NULL,
  `notify_messages` enum('yes','no') DEFAULT NULL,
  `notify_auction` enum('yes','no') DEFAULT NULL,
  `notify_domains` enum('yes','no') DEFAULT NULL,
  `threshold_transaction` bigint unsigned DEFAULT NULL,
  `threshold_offer` bigint unsigned DEFAULT NULL,
  `finalize_reminder_sec` int DEFAULT NULL,
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`,`wallet_adid`),
  KEY `user_id` (`user_id`),
  KEY `wallet_adid` (`wallet_adid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_domains_expiry` (
  `queue_task_id` int unsigned NOT NULL AUTO_INCREMENT,
  `prev_queue_task_id` int unsigned DEFAULT NULL,
  `state` enum('idle','syncing','error','success') NOT NULL DEFAULT 'idle',
  `sync_mode` enum('new','update') DEFAULT NULL,
  `priority` tinyint NOT NULL DEFAULT 5,
  `iteration` int unsigned NOT NULL DEFAULT 0,
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_activate` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `exec_time` decimal(9,3) DEFAULT NULL,
  `last_notice` varchar(1024) DEFAULT NULL,
  `network_id` enum('?','tezos') NOT NULL DEFAULT '?',
  `domain` varchar(256) DEFAULT NULL,
  `ts_expired` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`queue_task_id`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `claim` (`state`,`priority`,`iteration`,`queue_task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_domains_expiry__done` (
  `queue_task_id` int unsigned NOT NULL AUTO_INCREMENT,
  `prev_queue_task_id` int unsigned DEFAULT NULL,
  `state` enum('idle','syncing','error','success') NOT NULL DEFAULT 'idle',
  `sync_mode` enum('new','update') DEFAULT NULL,
  `priority` tinyint NOT NULL DEFAULT 5,
  `iteration` int unsigned NOT NULL DEFAULT 0,
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_activate` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `exec_time` decimal(9,3) DEFAULT NULL,
  `last_notice` varchar(1024) DEFAULT NULL,
  `network_id` enum('?','tezos') NOT NULL DEFAULT '?',
  `domain` varchar(256) DEFAULT NULL,
  `ts_expired` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`queue_task_id`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_domains_expiry__failed` (
  `queue_task_id` int unsigned NOT NULL AUTO_INCREMENT,
  `prev_queue_task_id` int unsigned DEFAULT NULL,
  `state` enum('idle','syncing','error','success') NOT NULL DEFAULT 'idle',
  `sync_mode` enum('new','update') DEFAULT NULL,
  `priority` tinyint NOT NULL DEFAULT 5,
  `iteration` int unsigned NOT NULL DEFAULT 0,
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_activate` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `exec_time` decimal(9,3) DEFAULT NULL,
  `last_notice` varchar(1024) DEFAULT NULL,
  `network_id` enum('?','tezos') NOT NULL DEFAULT '?',
  `domain` varchar(256) DEFAULT NULL,
  `ts_expired` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`queue_task_id`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_wallets_sync` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `wallet_adid` int unsigned NOT NULL,
  `wallet_address` varchar(64) NOT NULL DEFAULT '',
  `priority` tinyint unsigned NOT NULL DEFAULT 3,
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `wallet_adid` (`wallet_adid`),
  KEY `wallet_address` (`wallet_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `user_id` int unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('user') NOT NULL DEFAULT 'user',
  `platform` enum('discord','telegram') DEFAULT NULL,
  `platform_user_id` bigint NOT NULL,
  `platform_channel_id` bigint NOT NULL,
  `platform_guild_id` bigint DEFAULT NULL,
  `status` enum('active','frozen') NOT NULL DEFAULT 'active',
  `username` varchar(255) DEFAULT NULL,
  `discriminator` smallint unsigned DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `first_name` varchar(255) DEFAULT NULL,
  `last_name` varchar(255) DEFAULT NULL,
  `avatar` varchar(1024) DEFAULT NULL,
  `language_code` varchar(32) DEFAULT NULL,
  `verified` tinyint unsigned DEFAULT NULL,
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_notice` varchar(1024) DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `platform` (`platform`,`platform_user_id`,`platform_channel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users_action` (
  `user_id` int unsigned NOT NULL,
  `action_id` varchar(32) DEFAULT NULL,
  `ts_event` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_expired` timestamp NULL DEFAULT NULL,
  `data` text DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `users_action_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users_currency_convert` (
  `user_id` int unsigned NOT NULL,
  `currency_out_id` varchar(16) NOT NULL DEFAULT '',
  PRIMARY KEY (`user_id`,`currency_out_id`),
  KEY `currency_id` (`currency_out_id`),
  CONSTRAINT `users_currency_convert_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `users_currency_convert_ibfk_2` FOREIGN KEY (`currency_out_id`) REFERENCES `currency_out` (`currency_out_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users_currency_track` (
  `user_id` int unsigned NOT NULL,
  `currency_in_id` varchar(16) NOT NULL DEFAULT '',
  `currency_out_id` varchar(16) NOT NULL DEFAULT '',
  PRIMARY KEY (`user_id`,`currency_in_id`,`currency_out_id`),
  KEY `currency_in_id` (`currency_in_id`),
  KEY `currency_out_id` (`currency_out_id`),
  CONSTRAINT `users_currency_track_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `users_currency_track_ibfk_2` FOREIGN KEY (`currency_in_id`) REFERENCES `currency_in` (`currency_in_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `users_currency_track_ibfk_3` FOREIGN KEY (`currency_out_id`) REFERENCES `currency_out` (`currency_out_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users_follow` (
  `user_id` int unsigned NOT NULL,
  `following_adid` int unsigned NOT NULL,
  `following_address` varchar(64) NOT NULL DEFAULT '',
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`following_adid`),
  KEY `users_follow_ibfk_2` (`following_adid`),
  CONSTRAINT `users_follow_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
  CONSTRAINT `users_follow_ibfk_2` FOREIGN KEY (`following_adid`) REFERENCES `address` (`adid`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users_redirects` (
  `user_id` int unsigned NOT NULL,
  `redirect_channel_id` bigint NOT NULL,
  `guild_id` bigint DEFAULT NULL,
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`),
  CONSTRAINT `users_redirects_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users_ref_wallets` (
  `user_id` int unsigned NOT NULL,
  `wallet_adid` int unsigned NOT NULL,
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `wallet_name` varchar(255) NOT NULL DEFAULT '',
  `wallet_icon` varchar(16) NOT NULL DEFAULT 'empty',
  PRIMARY KEY (`user_id`,`wallet_adid`),
  KEY `wallet_adid` (`wallet_adid`),
  CONSTRAINT `users_ref_wallets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
  CONSTRAINT `users_ref_wallets_ibfk_2` FOREIGN KEY (`wallet_adid`) REFERENCES `wallets` (`wallet_adid`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wallets` (
  `wallet_adid` int unsigned NOT NULL,
  `wallet_address` varchar(36) NOT NULL DEFAULT '',
  `balance` bigint DEFAULT NULL,
  `currency` varchar(3) NOT NULL DEFAULT '',
  `status` enum('active','frozen') NOT NULL DEFAULT 'active',
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`wallet_adid`),
  UNIQUE KEY `wallet_address` (`wallet_address`),
  CONSTRAINT `wallets_ibfk_1` FOREIGN KEY (`wallet_adid`) REFERENCES `address` (`adid`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------------------------------------------------
-- Templates for monthly log tables <name>_YYYY_MM (created by commands/run /lifecycle/prepare/tables)
-- -----------------------------------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `template__events_flow` (
  `event_flow_id` int unsigned NOT NULL AUTO_INCREMENT,
  `network_id` enum('?','tezos') NOT NULL DEFAULT '?',
  `network_block` int unsigned NOT NULL,
  `event_type` varchar(32) NOT NULL,
  `entity_uid` bigint NOT NULL,
  `entity_subid` bigint NOT NULL DEFAULT 0,
  `event_details` varchar(32) DEFAULT NULL,
  `ts_event` timestamp NOT NULL DEFAULT current_timestamp(),
  `sender_address` varchar(64) DEFAULT NULL,
  `target_address` varchar(64) DEFAULT NULL,
  `royalty_address` varchar(64) DEFAULT NULL,
  `fee_address` varchar(64) DEFAULT NULL,
  `price` double DEFAULT NULL,
  `revenue` double DEFAULT NULL,
  `royalty` double DEFAULT NULL,
  `fee` double DEFAULT NULL,
  `currency` varchar(16) DEFAULT NULL,
  `currency_address` varchar(64) DEFAULT NULL,
  `currency_address_2nd` varchar(64) DEFAULT NULL,
  `quantity` double unsigned NOT NULL DEFAULT 1,
  `royalty_percent` smallint unsigned DEFAULT NULL,
  `marketplace_address` varchar(64) DEFAULT NULL,
  `token_address` varchar(64) DEFAULT NULL,
  `token_id` varchar(968) DEFAULT NULL,
  `transaction_counter` int unsigned DEFAULT NULL,
  `transaction_hash` mediumtext DEFAULT NULL,
  `transaction_event` timestamp NOT NULL,
  `transaction_entrypoint` varchar(255) DEFAULT NULL,
  `custom_data` text DEFAULT NULL,
  PRIMARY KEY (`event_flow_id`),
  UNIQUE KEY `network_id` (`network_id`,`network_block`,`event_type`,`entity_uid`,`entity_subid`),
  KEY `event_type` (`event_type`),
  KEY `ts_event` (`ts_event`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `template__events_notifications_discord` (
  `event_notification_id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `owner_adid` int unsigned DEFAULT NULL,
  `owner_address` varchar(64) DEFAULT NULL,
  `event_flow_id` int unsigned NOT NULL,
  `notification_type` varchar(32) NOT NULL DEFAULT '',
  `priority_index` tinyint NOT NULL DEFAULT 3,
  `notify_status` enum('idle','active','done','skipped','error') NOT NULL DEFAULT 'idle',
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `exec_time` decimal(9,3) DEFAULT NULL,
  `task_lifetime` int unsigned DEFAULT NULL,
  `note` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`event_notification_id`),
  KEY `owner_adid` (`owner_adid`),
  KEY `event_flow_id` (`event_flow_id`),
  KEY `user_id` (`user_id`),
  KEY `notify_status` (`notify_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `template__events_notifications_telegram` (
  `event_notification_id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `owner_adid` int unsigned DEFAULT NULL,
  `owner_address` varchar(64) DEFAULT NULL,
  `event_flow_id` int unsigned NOT NULL,
  `notification_type` varchar(32) NOT NULL DEFAULT '',
  `priority_index` tinyint NOT NULL DEFAULT 3,
  `notify_status` enum('idle','active','done','skipped','error') NOT NULL DEFAULT 'idle',
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `exec_time` decimal(9,3) DEFAULT NULL,
  `task_lifetime` int unsigned DEFAULT NULL,
  `note` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`event_notification_id`),
  KEY `owner_adid` (`owner_adid`),
  KEY `event_flow_id` (`event_flow_id`),
  KEY `user_id` (`user_id`),
  KEY `notify_status` (`notify_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `template__log_bot_messages_discord` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ts_event` timestamp NOT NULL DEFAULT current_timestamp(),
  `type` enum('message','interaction','response') DEFAULT NULL,
  `status` enum('ok','error') DEFAULT NULL,
  `platform_user_id` bigint unsigned DEFAULT NULL,
  `channel_id` bigint unsigned DEFAULT NULL,
  `guild_id` bigint unsigned DEFAULT NULL,
  `application_id` bigint unsigned DEFAULT NULL,
  `message` varchar(4096) DEFAULT NULL,
  `exec_time` decimal(9,3) DEFAULT NULL,
  `data_request` text DEFAULT NULL,
  `data_response` text DEFAULT NULL,
  `error_message` varchar(4096) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `template__log_bot_messages_telegram` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ts_event` timestamp NOT NULL DEFAULT current_timestamp(),
  `channel_id` bigint DEFAULT NULL,
  `query_type` enum('message','message:edited','callback_query','user_action','api:response','api:send-message') DEFAULT NULL,
  `query_subtype` varchar(32) DEFAULT NULL,
  `status` enum('ok','error') DEFAULT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `from_user_id` bigint DEFAULT NULL,
  `from_username` varchar(255) DEFAULT NULL,
  `from_lang_code` varchar(16) DEFAULT NULL,
  `chat_type` varchar(32) DEFAULT NULL,
  `chat_id` bigint DEFAULT NULL,
  `message_id` bigint DEFAULT NULL,
  `message_pid` bigint DEFAULT NULL,
  `message_text` text DEFAULT NULL,
  `message_data` text DEFAULT NULL,
  `data_request` text DEFAULT NULL,
  `data_response` text DEFAULT NULL,
  `error` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `template__log_events_flow_exec_at` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ts_event` timestamp NOT NULL DEFAULT current_timestamp(),
  `file` varchar(255) DEFAULT NULL,
  `class` varchar(255) DEFAULT NULL,
  `method` varchar(255) DEFAULT NULL,
  `count` int DEFAULT NULL,
  `data` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
