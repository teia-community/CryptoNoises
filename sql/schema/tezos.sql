-- CryptoNoises: Tezos indexer database (dbTezos, kSECRET_TEZOS_MYSQL_HOST_MAIN)
-- Use any database name and set it in config/consts-secrets.php.
-- Apply to an empty database: mysql <db_name> < sql/schema/tezos.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;   -- tables are in alphabetical order, foreign keys are checked at the end

-- Account-level operations that are not transactions: delegations, staking (stake / unstake / finalize), bakers
-- changing their staking parameters, and originations. Together they are ~0.2% of the transaction volume (174 vs
-- 75,119 per 10k levels near the head), so one plain table holds them, without the sharding the transaction log
-- needs. `id` is TzKT's operation id, unique across operation kinds.
CREATE TABLE IF NOT EXISTS `account_operations` (
  `id` bigint unsigned NOT NULL,
  `type` enum('delegation','staking','set_delegate_parameters','origination') NOT NULL,
  `level` int unsigned NOT NULL,
  `timestamp` timestamp NOT NULL,
  `hash` varchar(64) NOT NULL DEFAULT '',
  `counter` int unsigned NOT NULL DEFAULT 0,
  `status` varchar(16) NOT NULL DEFAULT 'applied',
  -- who signed it
  `sender_adid` int unsigned NOT NULL,
  -- whose operation it is: the delegator, the staker (not always the sender: anyone may finalize for a staker),
  -- the baker changing its parameters, or whoever originated (the initiator when a contract did it for them)
  `account_adid` int unsigned NOT NULL,
  -- delegation: the new delegate (NULL = undelegated); staking: the baker; set_delegate_parameters: the baker
  `baker_adid` int unsigned DEFAULT NULL,
  -- delegation only: the previous delegate
  `prev_baker_adid` int unsigned DEFAULT NULL,
  -- origination only: the originated contract
  `contract_adid` int unsigned DEFAULT NULL,
  -- staking only: stake | unstake | finalize
  `action` varchar(16) DEFAULT NULL,
  -- mutez. delegation: the delegated balance; staking: the amount moved; origination: the initial balance
  `amount` bigint NOT NULL DEFAULT 0,
  `requested_amount` bigint DEFAULT NULL,
  -- set_delegate_parameters only, in the protocol's fixed-point units:
  -- limit in millionths (5000000 = 5x the baker's own stake), edge in billionths (150000000 = 15%)
  `limit_of_staking_over_baking` bigint unsigned DEFAULT NULL,
  `edge_of_baking_over_staking` bigint unsigned DEFAULT NULL,
  `activation_cycle` int unsigned DEFAULT NULL,
  -- TzKT aliases of the counterparties as of the operation ("Finn", a baker's name). Notifications name these,
  -- and bakers or a finalizing service are never queued for the profile sync, so the profile cache rarely has them
  `sender_alias` varchar(255) DEFAULT NULL,
  `baker_alias` varchar(255) DEFAULT NULL,
  `prev_baker_alias` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `level` (`level`),
  KEY `account` (`account_adid`,`level`),
  KEY `baker` (`baker_adid`,`level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `address` (
  `adid` int unsigned NOT NULL AUTO_INCREMENT,
  `address_type` enum('wallet','contract','fake') DEFAULT NULL,
  `address` varbinary(36) NOT NULL DEFAULT '',
  `address_ci` varchar(36) DEFAULT NULL,
  `notice` varchar(1024) DEFAULT NULL,
  PRIMARY KEY (`adid`),
  UNIQUE KEY `address` (`address`),
  KEY `address_ci` (`address_ci`),
  KEY `address_type` (`address_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `address_by_creators` (
  `creator_name` varchar(255) NOT NULL,
  `address` varbinary(36) NOT NULL DEFAULT '',
  `hits` int unsigned NOT NULL DEFAULT 0,
  `notice` varchar(1024) DEFAULT NULL,
  PRIMARY KEY (`creator_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bigmap_data_basic` (
  `bigmap` int unsigned NOT NULL,
  `exkey` bigint NOT NULL,
  `value` mediumtext DEFAULT NULL,
  `state` enum('active','removed') NOT NULL DEFAULT 'active',
  `first_level` int unsigned NOT NULL,
  `last_level` int unsigned NOT NULL,
  `updates` int unsigned NOT NULL,
  PRIMARY KEY (`bigmap`,`exkey`),
  KEY `exkey` (`exkey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bigmap_data_ledger` (
  `bigmap` int unsigned NOT NULL,
  `exkey_token_address` bigint DEFAULT NULL,
  `exkey_token_id` bigint NOT NULL,
  `exkey_address` bigint NOT NULL,
  `value_num` double DEFAULT NULL,
  `value` mediumtext DEFAULT NULL,
  `state` enum('active','removed') NOT NULL DEFAULT 'active',
  `first_level` int unsigned NOT NULL,
  `last_level` int unsigned NOT NULL,
  `updates` int unsigned NOT NULL,
  PRIMARY KEY (`bigmap`,`exkey_address`,`exkey_token_id`),
  KEY `exkey_address` (`exkey_address`),
  KEY `exkey_token_id` (`exkey_token_id`),
  KEY `exkey_token_address` (`exkey_token_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bigmap_data_ledger_light` (
  `bigmap` int unsigned NOT NULL,
  `exkey_token_address` bigint DEFAULT NULL,
  `exkey_token_id` bigint NOT NULL,
  `value` bigint NOT NULL,
  `state` enum('active','removed') NOT NULL DEFAULT 'active',
  `first_level` int unsigned NOT NULL,
  `last_level` int unsigned NOT NULL,
  `updates` int unsigned NOT NULL,
  PRIMARY KEY (`bigmap`,`exkey_token_id`),
  KEY `exkey_token_id` (`exkey_token_id`),
  KEY `value` (`value`),
  KEY `exkey_token_address` (`exkey_token_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bigmap_data_market_deals` (
  `bigmap` int unsigned NOT NULL,
  `exkey` bigint NOT NULL,
  `is_active` enum('no','yes') DEFAULT NULL,
  `ts_active_until` timestamp NULL DEFAULT NULL,
  `exkey_token_address` bigint DEFAULT NULL,
  `exkey_token_id` bigint DEFAULT NULL,
  `exkey_token_artist` bigint DEFAULT NULL,
  `exkey_token_owner` bigint DEFAULT NULL,
  `objkt_amount` bigint unsigned DEFAULT NULL,
  `objkt_price_xtz` bigint unsigned DEFAULT NULL,
  `objkt_royalties` int unsigned DEFAULT NULL,
  `ts_deal_expired` timestamp NULL DEFAULT NULL,
  `value` mediumtext DEFAULT NULL,
  `state` enum('active','removed') NOT NULL DEFAULT 'active',
  `first_level` int unsigned NOT NULL,
  `last_level` int unsigned NOT NULL,
  `updates` int unsigned NOT NULL,
  PRIMARY KEY (`bigmap`,`exkey`),
  KEY `exkey` (`exkey`),
  KEY `exkey_token_address` (`exkey_token_address`),
  KEY `exkey_token_id` (`exkey_token_id`),
  KEY `exkey_token_owner` (`exkey_token_owner`),
  KEY `is_active` (`is_active`),
  KEY `ts_active_until` (`ts_active_until`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bigmap_data_market_offers` (
  `bigmap` int unsigned NOT NULL,
  `exkey` bigint NOT NULL,
  `is_active` enum('no','yes') DEFAULT NULL,
  `exkey_token_address` bigint DEFAULT NULL,
  `exkey_token_id` bigint DEFAULT NULL,
  `exkey_token_buyer` bigint DEFAULT NULL,
  `objkt_price_xtz` bigint unsigned DEFAULT NULL,
  `value` mediumtext DEFAULT NULL,
  `state` enum('active','removed') NOT NULL DEFAULT 'active',
  `first_level` int unsigned NOT NULL,
  `last_level` int unsigned NOT NULL,
  `updates` int unsigned NOT NULL,
  PRIMARY KEY (`bigmap`,`exkey`),
  KEY `exkey` (`exkey`),
  KEY `exkey_token_address` (`exkey_token_address`),
  KEY `exkey_token_id` (`exkey_token_id`),
  KEY `exkey_token_owner` (`exkey_token_buyer`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bigmap_data_metadata` (
  `bigmap` int unsigned NOT NULL,
  `exkey` bigint NOT NULL,
  `value` mediumtext DEFAULT NULL,
  `state` enum('active','removed') NOT NULL DEFAULT 'active',
  `first_level` int unsigned NOT NULL,
  `last_level` int unsigned NOT NULL,
  `updates` int unsigned NOT NULL,
  PRIMARY KEY (`bigmap`,`exkey`),
  KEY `exkey` (`exkey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bigmap_data_operators` (
  `bigmap` int unsigned NOT NULL,
  `exkey_operator` bigint NOT NULL,
  `exkey_owner` bigint NOT NULL,
  `exkey_token_id` bigint NOT NULL,
  `value` mediumtext DEFAULT NULL,
  `state` enum('active','removed') NOT NULL DEFAULT 'active',
  `first_level` int unsigned NOT NULL,
  `last_level` int unsigned NOT NULL,
  `updates` int unsigned NOT NULL,
  PRIMARY KEY (`bigmap`,`exkey_operator`,`exkey_owner`,`exkey_token_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bigmap_data_supply` (
  `bigmap` int unsigned NOT NULL,
  `exkey` bigint NOT NULL,
  `value_num` double DEFAULT NULL,
  `value` mediumtext DEFAULT NULL,
  `state` enum('active','removed') NOT NULL DEFAULT 'active',
  `first_level` int unsigned NOT NULL,
  `last_level` int unsigned NOT NULL,
  `updates` int unsigned NOT NULL,
  PRIMARY KEY (`bigmap`,`exkey`),
  KEY `exkey` (`exkey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bigmap_data_token_metadata` (
  `bigmap` int unsigned NOT NULL,
  `exkey` bigint NOT NULL,
  `value` mediumtext DEFAULT NULL,
  `state` enum('active','removed') NOT NULL DEFAULT 'active',
  `first_level` int unsigned NOT NULL,
  `last_level` int unsigned NOT NULL,
  `updates` int unsigned NOT NULL,
  PRIMARY KEY (`bigmap`,`exkey`),
  KEY `exkey` (`exkey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bigmap_keys` (
  `exkey_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varbinary(520) NOT NULL DEFAULT '',
  `key_extra` varbinary(32768) DEFAULT NULL,
  PRIMARY KEY (`exkey_id`),
  UNIQUE KEY `key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Address ids start at 2000000000: BigmapKeyCodec::kADDRESS_KEY_ID_MIN tells them apart from bigmap_keys ids
CREATE TABLE IF NOT EXISTS `bigmap_keys_address` (
  `exkey_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `address` varbinary(36) NOT NULL DEFAULT '',
  PRIMARY KEY (`exkey_id`),
  UNIQUE KEY `key` (`address`)
) ENGINE=InnoDB AUTO_INCREMENT=2000000000 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bigmaps` (
  `bigmap` int unsigned NOT NULL,
  `type` enum('basic','ledger','ledger-light','operators','market-deals','market-offers','metadata','token-metadata','supply') DEFAULT NULL,
  `state` enum('active','removed') NOT NULL DEFAULT 'active',
  `contract` varchar(36) NOT NULL,
  `alias` varchar(255) DEFAULT NULL,
  `path` varchar(255) NOT NULL,
  `first_level` int unsigned NOT NULL,
  `last_level` int unsigned DEFAULT NULL,
  `tags` text DEFAULT NULL,
  `key_type` text DEFAULT NULL,
  `value_type` text DEFAULT NULL,
  `fieldkey_token_id` varchar(16) DEFAULT NULL,
  `fieldkey_address` varchar(16) DEFAULT NULL,
  `fieldkey_address2` varchar(16) DEFAULT NULL,
  PRIMARY KEY (`bigmap`),
  KEY `type` (`type`),
  KEY `contract` (`contract`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `blocks` (
  `level` int unsigned NOT NULL AUTO_INCREMENT,
  `hash` varchar(64) NOT NULL DEFAULT '',
  `timestamp` timestamp NOT NULL,
  PRIMARY KEY (`level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `config` (
  `cfg_key` varchar(64) NOT NULL DEFAULT '',
  `cfg_value` varchar(1024) DEFAULT NULL,
  `ts_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`cfg_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contracts` (
  `contract_adid` int NOT NULL,
  `contract_address` varbinary(256) NOT NULL DEFAULT '',
  `bigmap` int unsigned DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `name_custom` varchar(255) DEFAULT NULL,
  `token_link` varchar(255) DEFAULT NULL,
  `collection_link` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `author_address` varchar(36) DEFAULT NULL,
  `authors` text DEFAULT NULL,
  `homepage` varchar(255) DEFAULT NULL,
  `image_uri` varchar(255) DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `image_url_custom` varchar(255) DEFAULT NULL,
  `interfaces` text DEFAULT NULL,
  `metadata_uri` varchar(255) DEFAULT NULL,
  `metadata_state` enum('idle','syncing','success','error') NOT NULL DEFAULT 'idle',
  `tokens_count` int unsigned DEFAULT NULL,
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `error_msg` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`contract_adid`),
  UNIQUE KEY `contract_address` (`contract_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `domains_expiry` (
  `domain` varbinary(1024) NOT NULL DEFAULT '',
  `state` enum('active','removed') NOT NULL DEFAULT 'active',
  `ts_expired` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`domain`),
  KEY `ts_expired` (`ts_expired`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `domains_records` (
  `domain` varbinary(1024) NOT NULL DEFAULT '',
  `address` varchar(36) DEFAULT NULL,
  `owner` varbinary(36) NOT NULL DEFAULT '',
  `level` tinyint unsigned DEFAULT NULL,
  `expiry_key` varbinary(1024) DEFAULT NULL,
  `tzip12_token_id` bigint unsigned DEFAULT NULL,
  `data` mediumtext DEFAULT NULL,
  `internal_data` mediumtext DEFAULT NULL,
  `state` enum('active','removed') NOT NULL DEFAULT 'active',
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_updated` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`domain`),
  UNIQUE KEY `tzip12_token_id` (`tzip12_token_id`),
  KEY `owner` (`owner`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `domains_records_reverse` (
  `address` varchar(36) NOT NULL DEFAULT '',
  `domain` varbinary(1024) DEFAULT NULL,
  `owner` varchar(36) NOT NULL DEFAULT '',
  `internal_data` mediumtext DEFAULT NULL,
  `state` enum('active','removed') NOT NULL DEFAULT 'active',
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_updated` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per token: Token_Events publishes the event only when its INSERT IGNORE adds the row
CREATE TABLE IF NOT EXISTS `events_token_mint` (
  `event_id` int unsigned NOT NULL AUTO_INCREMENT,
  `contract_adid` int unsigned NOT NULL,
  `token_unid` bigint NOT NULL,
  `ts_event` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`event_id`),
  UNIQUE KEY `token` (`contract_adid`,`token_unid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per token: Token_Events publishes the event only when its INSERT IGNORE adds the row
CREATE TABLE IF NOT EXISTS `events_token_updates` (
  `event_id` int unsigned NOT NULL AUTO_INCREMENT,
  `contract_adid` int unsigned NOT NULL,
  `token_unid` bigint NOT NULL,
  `ts_event` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`event_id`),
  UNIQUE KEY `token` (`contract_adid`,`token_unid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cursor of `run-tezos /blockchain/account-operations/sync-new` (account_operations), same shape as the others
CREATE TABLE IF NOT EXISTS `fixer_account_operations_sync` (
  `level` int unsigned NOT NULL,
  `state` enum('idle','processing','success','error') NOT NULL DEFAULT 'idle',
  `items_count` int unsigned DEFAULT NULL,
  `notice` text DEFAULT NULL,
  `ts_scan_started` timestamp NULL DEFAULT current_timestamp(),
  `ts_scan_finished` timestamp NULL DEFAULT NULL,
  `items_count_prev` int unsigned DEFAULT NULL,
  PRIMARY KEY (`level`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fixer_bigmap_updates_analyze` (
  `level` int unsigned NOT NULL,
  `state` enum('idle','processing','success','error') NOT NULL DEFAULT 'idle',
  `items_count` int unsigned DEFAULT NULL,
  `notice` text DEFAULT NULL,
  `ts_scan_started` timestamp NULL DEFAULT current_timestamp(),
  `ts_scan_finished` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`level`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fixer_bigmap_updates_sync` (
  `level` int unsigned NOT NULL,
  `state` enum('idle','processing','success','error') NOT NULL DEFAULT 'idle',
  `items_count` int unsigned DEFAULT NULL,
  `notice` text DEFAULT NULL,
  `ts_scan_started` timestamp NULL DEFAULT current_timestamp(),
  `ts_scan_finished` timestamp NULL DEFAULT NULL,
  `items_count_check1` int unsigned DEFAULT NULL,
  `items_count_check2` int unsigned DEFAULT NULL,
  `items_count_check3` int unsigned DEFAULT NULL,
  PRIMARY KEY (`level`),
  KEY `state` (`state`),
  KEY `items_count_check1` (`items_count_check1`),
  KEY `items_count_check2` (`items_count_check2`),
  KEY `items_count_check3` (`items_count_check3`)
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

CREATE TABLE IF NOT EXISTS `fixer_thrash` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ts_event` timestamp NULL DEFAULT current_timestamp(),
  `scope` varchar(64) NOT NULL,
  `level` int unsigned NOT NULL,
  `state` enum('idle','processing','success','error') NOT NULL DEFAULT 'idle',
  `items_count` int unsigned DEFAULT NULL,
  `notice` text DEFAULT NULL,
  `ts_scan_started` timestamp NULL DEFAULT NULL,
  `ts_scan_finished` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fixer_transactions_sync` (
  `level` int unsigned NOT NULL,
  `state` enum('idle','processing','success','error') NOT NULL DEFAULT 'idle',
  `items_count` int unsigned DEFAULT NULL,
  `notice` text DEFAULT NULL,
  `ts_scan_started` timestamp NULL DEFAULT current_timestamp(),
  `ts_scan_finished` timestamp NULL DEFAULT NULL,
  `items_count_prev` int unsigned DEFAULT NULL,
  PRIMARY KEY (`level`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ipfs_media` (
  `ipfs_uri` varbinary(1024) NOT NULL DEFAULT '',
  `media_type` enum('image','animation','video','not-supported','undefined') DEFAULT NULL,
  `width` int unsigned DEFAULT NULL,
  `height` int unsigned DEFAULT NULL,
  `filesize` int unsigned DEFAULT NULL,
  `mimetype` varchar(32) DEFAULT NULL,
  `has_thumbnail` tinyint unsigned DEFAULT NULL,
  `has_animation` tinyint unsigned DEFAULT NULL,
  `has_movieclip` tinyint unsigned DEFAULT NULL,
  `storage_filepath` varchar(255) DEFAULT NULL,
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`ipfs_uri`),
  KEY `media_type` (`media_type`),
  KEY `has_thumbnail` (`has_thumbnail`),
  KEY `has_animation` (`has_animation`),
  KEY `has_movieclip` (`has_movieclip`),
  KEY `ts_created` (`ts_created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ipfs_media_teasers` (
  `ipfs_uri` varbinary(1024) NOT NULL DEFAULT '',
  `media_type` enum('image','animation','video','not-supported','undefined') DEFAULT NULL,
  `width` int unsigned DEFAULT NULL,
  `height` int unsigned DEFAULT NULL,
  `filesize` int unsigned DEFAULT NULL,
  `mimetype` varchar(32) DEFAULT NULL,
  `has_thumbnail` tinyint unsigned DEFAULT NULL,
  `has_animation` tinyint unsigned DEFAULT NULL,
  `has_movieclip` tinyint unsigned DEFAULT NULL,
  `storage_filepath` varchar(255) DEFAULT NULL,
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ipfs_uri`),
  KEY `ts_created` (`ts_created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mimetypes` (
  `mimetype_id` int unsigned NOT NULL AUTO_INCREMENT,
  `mimetype` varchar(32) NOT NULL DEFAULT '',
  `preview_type` enum('image','animation','video','not-supported') DEFAULT NULL,
  `notice` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`mimetype_id`),
  UNIQUE KEY `mimetype` (`mimetype`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `profiles_tzkt` (
  `profile_adid` int unsigned NOT NULL,
  `profile_address` varchar(36) NOT NULL DEFAULT '',
  `type` varchar(32) DEFAULT NULL,
  `kind` varchar(32) DEFAULT NULL,
  `alias` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `telegram` varchar(255) DEFAULT NULL,
  `twitter` varchar(255) DEFAULT NULL,
  `facebook` varchar(255) DEFAULT NULL,
  `instagram` varchar(255) DEFAULT NULL,
  `github` varchar(255) DEFAULT NULL,
  `website` varchar(4096) DEFAULT NULL,
  `ts_created` timestamp NULL DEFAULT current_timestamp(),
  `ts_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`profile_adid`),
  KEY `profile_address` (`profile_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `profiles_tzprofiles` (
  `profile_adid` int unsigned NOT NULL,
  `profile_address` varchar(36) NOT NULL DEFAULT '',
  `alias` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `discord_name` varchar(255) DEFAULT NULL,
  `twitter` varchar(255) DEFAULT NULL,
  `github` varchar(255) DEFAULT NULL,
  `website` varchar(4096) DEFAULT NULL,
  `logo_url` varchar(4096) DEFAULT NULL,
  `ethereum_address` varchar(255) DEFAULT NULL,
  `dns` varchar(255) DEFAULT NULL,
  `ts_created` timestamp NULL DEFAULT current_timestamp(),
  `ts_updated` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`profile_adid`),
  KEY `profile_address` (`profile_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `profiles_tzprofiles_contracts` (
  `contract_adid` int unsigned NOT NULL,
  `contract_address` varchar(36) NOT NULL DEFAULT '',
  `profile_adid` int unsigned NOT NULL,
  `profile_address` varchar(36) NOT NULL DEFAULT '',
  `ts_created` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`contract_adid`,`profile_adid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_contracts` (
  `queue_task_id` int unsigned NOT NULL AUTO_INCREMENT,
  `prev_queue_task_id` int unsigned DEFAULT NULL,
  `state` enum('idle','syncing','error','success') NOT NULL DEFAULT 'idle',
  `sync_mode` enum('new','update') DEFAULT NULL,
  `priority` tinyint NOT NULL DEFAULT 3,
  `iteration` int unsigned NOT NULL DEFAULT 0,
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_activate` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `exec_time` decimal(9,3) DEFAULT NULL,
  `last_notice` varchar(1024) DEFAULT NULL,
  `bigmap` int unsigned DEFAULT NULL,
  `contract_adid` int unsigned NOT NULL,
  `metadata_uri` varchar(255) NOT NULL,
  PRIMARY KEY (`queue_task_id`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `claim` (`state`,`priority`,`iteration`,`queue_task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_contracts__done` (
  `queue_task_id` int unsigned NOT NULL AUTO_INCREMENT,
  `prev_queue_task_id` int unsigned DEFAULT NULL,
  `state` enum('idle','syncing','error','success') NOT NULL DEFAULT 'idle',
  `sync_mode` enum('new','update') DEFAULT NULL,
  `priority` tinyint NOT NULL DEFAULT 3,
  `iteration` int unsigned NOT NULL DEFAULT 0,
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_activate` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `exec_time` decimal(9,3) DEFAULT NULL,
  `last_notice` varchar(1024) DEFAULT NULL,
  `bigmap` int unsigned DEFAULT NULL,
  `contract_adid` int unsigned NOT NULL,
  `metadata_uri` varchar(255) NOT NULL,
  PRIMARY KEY (`queue_task_id`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_contracts__failed` (
  `queue_task_id` int unsigned NOT NULL AUTO_INCREMENT,
  `prev_queue_task_id` int unsigned DEFAULT NULL,
  `state` enum('idle','syncing','error','success') NOT NULL DEFAULT 'idle',
  `sync_mode` enum('new','update') DEFAULT NULL,
  `priority` tinyint NOT NULL DEFAULT 3,
  `iteration` int unsigned NOT NULL DEFAULT 0,
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_activate` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `exec_time` decimal(9,3) DEFAULT NULL,
  `last_notice` varchar(1024) DEFAULT NULL,
  `bigmap` int unsigned DEFAULT NULL,
  `contract_adid` int unsigned NOT NULL,
  `metadata_uri` varchar(255) NOT NULL,
  PRIMARY KEY (`queue_task_id`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_ipfs_primary` (
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
  `media_uri` varchar(255) DEFAULT NULL,
  `contract_adid` int unsigned DEFAULT NULL,
  `token_unid` bigint DEFAULT NULL,
  PRIMARY KEY (`queue_task_id`),
  UNIQUE KEY `media_uri` (`media_uri`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `claim` (`state`,`priority`,`iteration`,`queue_task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_ipfs_primary__done` (
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
  `media_uri` varchar(255) DEFAULT NULL,
  `contract_adid` int unsigned DEFAULT NULL,
  `token_unid` bigint DEFAULT NULL,
  PRIMARY KEY (`queue_task_id`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_ipfs_primary__failed` (
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
  `media_uri` varchar(255) DEFAULT NULL,
  `contract_adid` int unsigned DEFAULT NULL,
  `token_unid` bigint DEFAULT NULL,
  PRIMARY KEY (`queue_task_id`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_ipfs_secondary` (
  `queue_task_id` int unsigned NOT NULL AUTO_INCREMENT,
  `prev_queue_task_id` int unsigned DEFAULT NULL,
  `state` enum('idle','syncing','error','success') NOT NULL DEFAULT 'idle',
  `sync_mode` enum('new','update') DEFAULT NULL,
  `priority` tinyint NOT NULL DEFAULT 5,
  `iteration` int unsigned NOT NULL DEFAULT 0,
  `ts_created` timestamp NOT NULL DEFAULT '2021-01-01 00:00:00',
  `ts_activate` timestamp NOT NULL DEFAULT current_timestamp(),
  `ts_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `exec_time` decimal(9,3) DEFAULT NULL,
  `last_notice` varchar(1024) DEFAULT NULL,
  `media_uri` varchar(255) DEFAULT NULL,
  `contract_adid` int unsigned DEFAULT NULL,
  `token_unid` bigint DEFAULT NULL,
  PRIMARY KEY (`queue_task_id`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `claim` (`state`,`priority`,`iteration`,`queue_task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_ipfs_secondary__done` (
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
  `media_uri` varchar(255) DEFAULT NULL,
  `contract_adid` int unsigned DEFAULT NULL,
  `token_unid` bigint DEFAULT NULL,
  PRIMARY KEY (`queue_task_id`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_ipfs_secondary__failed` (
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
  `media_uri` varchar(255) DEFAULT NULL,
  `contract_adid` int unsigned DEFAULT NULL,
  `token_unid` bigint DEFAULT NULL,
  PRIMARY KEY (`queue_task_id`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_profiles` (
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
  `profile_type` enum('tzkt','tzprofiles') DEFAULT NULL,
  `source_adid` int unsigned NOT NULL,
  PRIMARY KEY (`queue_task_id`),
  UNIQUE KEY `profile_type` (`profile_type`,`source_adid`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `claim` (`state`,`priority`,`iteration`,`queue_task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_profiles__done` (
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
  `profile_type` enum('tzkt','tzprofiles') DEFAULT NULL,
  `source_adid` int unsigned NOT NULL,
  PRIMARY KEY (`queue_task_id`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_profiles__failed` (
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
  `profile_type` enum('tzkt','tzprofiles') DEFAULT NULL,
  `source_adid` int unsigned NOT NULL,
  PRIMARY KEY (`queue_task_id`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_tokens_detected` (
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
  `bigmap` int unsigned NOT NULL,
  `bigmap_exkey` bigint NOT NULL,
  `level` int unsigned NOT NULL,
  PRIMARY KEY (`queue_task_id`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `claim` (`state`,`priority`,`iteration`,`queue_task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_tokens_detected__done` (
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
  `bigmap` int unsigned NOT NULL,
  `bigmap_exkey` bigint NOT NULL,
  `level` int unsigned NOT NULL,
  PRIMARY KEY (`queue_task_id`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_tokens_detected__failed` (
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
  `bigmap` int unsigned NOT NULL,
  `bigmap_exkey` bigint NOT NULL,
  `level` int unsigned NOT NULL,
  PRIMARY KEY (`queue_task_id`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_tokens_events` (
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
  `contract_adid` int unsigned NOT NULL,
  `token_unid` bigint NOT NULL,
  `scope` enum('api','dev') DEFAULT NULL,
  `event_type` enum('mint','update') DEFAULT NULL,
  PRIMARY KEY (`queue_task_id`),
  UNIQUE KEY `unique_record` (`contract_adid`,`token_unid`,`scope`,`event_type`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `claim` (`state`,`priority`,`iteration`,`queue_task_id`),
  KEY `scope` (`scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_tokens_events__done` (
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
  `contract_adid` int unsigned NOT NULL,
  `token_unid` bigint NOT NULL,
  `scope` enum('api','dev') DEFAULT NULL,
  `event_type` enum('mint','update') DEFAULT NULL,
  PRIMARY KEY (`queue_task_id`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `state` (`state`),
  KEY `scope` (`scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_tokens_events__failed` (
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
  `contract_adid` int unsigned NOT NULL,
  `token_unid` bigint NOT NULL,
  `scope` enum('api','dev') DEFAULT NULL,
  `event_type` enum('mint','update') DEFAULT NULL,
  PRIMARY KEY (`queue_task_id`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `state` (`state`),
  KEY `scope` (`scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_tokens_metadata` (
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
  `contract_adid` int unsigned NOT NULL,
  `token_unid` bigint NOT NULL,
  PRIMARY KEY (`queue_task_id`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `claim` (`state`,`priority`,`iteration`,`queue_task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_tokens_metadata__done` (
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
  `contract_adid` int unsigned NOT NULL,
  `token_unid` bigint NOT NULL,
  PRIMARY KEY (`queue_task_id`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_tokens_metadata__failed` (
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
  `contract_adid` int unsigned NOT NULL,
  `token_unid` bigint NOT NULL,
  PRIMARY KEY (`queue_task_id`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_tokens_metadata_merge` (
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
  `contract_adid` int unsigned NOT NULL,
  `token_unid` bigint NOT NULL,
  PRIMARY KEY (`queue_task_id`),
  UNIQUE KEY `contract_adid` (`contract_adid`,`token_unid`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `claim` (`state`,`priority`,`iteration`,`queue_task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_tokens_metadata_merge__done` (
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
  `contract_adid` int unsigned NOT NULL,
  `token_unid` bigint NOT NULL,
  PRIMARY KEY (`queue_task_id`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `queue_tokens_metadata_merge__failed` (
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
  `contract_adid` int unsigned NOT NULL,
  `token_unid` bigint NOT NULL,
  PRIMARY KEY (`queue_task_id`),
  KEY `ts_activate` (`ts_activate`),
  KEY `iteration` (`iteration`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `template__bigmap_updates_0xxxxx` (
  `level` int unsigned NOT NULL,
  `id` int unsigned NOT NULL,
  `timestamp` timestamp NOT NULL,
  `bigmap` int unsigned NOT NULL,
  `path` varchar(255) DEFAULT NULL,
  `action` enum('allocate','add_key','update_key','remove_key','remove') NOT NULL,
  `content_key` longtext DEFAULT NULL,
  `content_value` longtext DEFAULT NULL,
  PRIMARY KEY (`level`,`id`),
  KEY `bigmap` (`bigmap`),
  KEY `id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `template__transactions_0xxxx` (
  `level` int unsigned NOT NULL,
  `id` bigint unsigned NOT NULL,
  `counter` int unsigned NOT NULL,
  `timestamp` timestamp NOT NULL,
  `hash` varchar(64) DEFAULT NULL,
  `initiator_adid` int unsigned DEFAULT NULL,
  `sender_adid` int unsigned DEFAULT NULL,
  `target_adid` int unsigned DEFAULT NULL,
  `initiator_address` varchar(64) DEFAULT NULL,
  `sender_address` varchar(64) DEFAULT NULL,
  `target_address` varchar(64) DEFAULT NULL,
  `token_transfers_count` int DEFAULT NULL,
  `gasLimit` int unsigned NOT NULL,
  `gasUsed` int unsigned NOT NULL,
  `storageLimit` int unsigned NOT NULL,
  `storageUsed` int unsigned NOT NULL,
  `bakerFee` bigint unsigned NOT NULL,
  `storageFee` bigint unsigned NOT NULL,
  `allocationFee` bigint unsigned NOT NULL,
  `amount` bigint unsigned NOT NULL,
  `status` enum('applied','failed','backtracked','skipped') DEFAULT NULL,
  `hasInternals` tinyint unsigned NOT NULL,
  `nonce` int DEFAULT NULL,
  `entrypoint` varchar(255) DEFAULT NULL,
  `parameter` longtext DEFAULT NULL,
  `errors` text DEFAULT NULL,
  `undefined_fields` text DEFAULT NULL,
  PRIMARY KEY (`level`,`id`),
  KEY `counter` (`counter`),
  KEY `sender_adid` (`sender_adid`),
  KEY `target_adid` (`target_adid`),
  KEY `hash` (`hash`(10)),
  KEY `id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `token_tags` (
  `token_tag_id` int unsigned NOT NULL AUTO_INCREMENT,
  `tag` varbinary(128) NOT NULL DEFAULT '',
  PRIMARY KEY (`token_tag_id`),
  UNIQUE KEY `tag` (`tag`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `token_unids` (
  `token_unid` bigint unsigned NOT NULL AUTO_INCREMENT,
  `token_value` varbinary(3072) NOT NULL DEFAULT '',
  PRIMARY KEY (`token_unid`),
  UNIQUE KEY `token_value` (`token_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tokens` (
  `contract_adid` int unsigned NOT NULL,
  `contract_address` varchar(36) NOT NULL,
  `token_unid` bigint NOT NULL,
  `bigmap` int DEFAULT NULL,
  `bigmap_exkey` bigint DEFAULT NULL,
  `level_last` int unsigned NOT NULL,
  `minter_adid` int unsigned DEFAULT NULL,
  `minter_address` varchar(36) DEFAULT NULL,
  `creator_adid` int unsigned DEFAULT NULL,
  `creator_address` varchar(36) DEFAULT NULL,
  `token_type` enum('nft','coin','domain') DEFAULT NULL,
  `name` varchar(1024) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `symbol` varchar(64) DEFAULT NULL,
  `decimals` smallint unsigned DEFAULT NULL,
  `should_prefer_symbol` tinyint unsigned DEFAULT NULL,
  `is_boolean_amount` tinyint unsigned DEFAULT NULL,
  `is_transferable` tinyint unsigned DEFAULT NULL,
  `has_creators` smallint unsigned DEFAULT NULL,
  `has_tags` smallint unsigned DEFAULT NULL,
  `has_attributes` smallint unsigned DEFAULT NULL,
  `thumbnail_uri` varchar(255) DEFAULT NULL,
  `display_uri` varchar(255) DEFAULT NULL,
  `artifact_uri` varchar(255) DEFAULT NULL,
  `rights` varchar(255) DEFAULT NULL,
  `edition_index` int unsigned DEFAULT NULL,
  `editions` int unsigned DEFAULT NULL,
  `royalties_fixed` int unsigned DEFAULT NULL,
  `version` varchar(16) DEFAULT NULL,
  `collection_group` varchar(16) DEFAULT NULL,
  `collection_id` bigint DEFAULT NULL,
  `metadata_state` enum('idle','syncing','success','error','empty') DEFAULT NULL,
  `metadata_uri` varchar(255) DEFAULT NULL,
  `preview_state` enum('idle','syncing','success','error','empty','teaser') DEFAULT NULL,
  `preview_uri` varchar(255) DEFAULT NULL,
  `error_msg` text DEFAULT NULL,
  `ts_last_save` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`contract_adid`,`token_unid`),
  KEY `token_unid` (`token_unid`),
  KEY `minter_adid` (`minter_adid`),
  KEY `creator_adid` (`creator_adid`),
  KEY `metadata_state` (`metadata_state`),
  KEY `preview_state` (`preview_state`),
  KEY `level_last` (`level_last`),
  KEY `ts_last_save` (`ts_last_save`),
  KEY `bigmap` (`bigmap`),
  KEY `metadata_uri` (`metadata_uri`(16))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tokens_append` (
  `contract_adid` int unsigned NOT NULL,
  `token_unid` bigint NOT NULL,
  `minter_adid` int unsigned DEFAULT NULL,
  `minter_address` varchar(36) DEFAULT NULL,
  `creator_adid` int unsigned DEFAULT NULL,
  `creator_address` varchar(36) DEFAULT NULL,
  `token_type` enum('nft','coin') DEFAULT NULL,
  `edition_index` int unsigned DEFAULT NULL,
  `editions` int unsigned DEFAULT NULL,
  `royalties_fixed` int unsigned DEFAULT NULL,
  `collection_group` varchar(16) DEFAULT NULL,
  `collection_id` bigint DEFAULT NULL,
  PRIMARY KEY (`contract_adid`,`token_unid`),
  KEY `token_unid` (`token_unid`),
  KEY `creator_adid` (`creator_adid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tokens_attributes` (
  `attribute_idx` int unsigned NOT NULL AUTO_INCREMENT,
  `contract_adid` int unsigned NOT NULL,
  `token_unid` bigint NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `value` varchar(255) DEFAULT NULL,
  `type` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`attribute_idx`),
  KEY `contract_adid` (`contract_adid`,`token_unid`),
  KEY `token_unid` (`token_unid`),
  CONSTRAINT `tokens_attributes_ibfk_1` FOREIGN KEY (`contract_adid`, `token_unid`) REFERENCES `tokens` (`contract_adid`, `token_unid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tokens_creators` (
  `contract_adid` int unsigned NOT NULL,
  `token_unid` bigint NOT NULL,
  `creator_adid` int unsigned NOT NULL,
  PRIMARY KEY (`contract_adid`,`token_unid`,`creator_adid`),
  KEY `creator_adid` (`creator_adid`),
  KEY `token_unid` (`token_unid`),
  CONSTRAINT `tokens_creators_ibfk_1` FOREIGN KEY (`contract_adid`, `token_unid`) REFERENCES `tokens` (`contract_adid`, `token_unid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tokens_formats` (
  `format_idx` int unsigned NOT NULL AUTO_INCREMENT,
  `contract_adid` int unsigned NOT NULL,
  `token_unid` bigint NOT NULL,
  `uri` varchar(255) NOT NULL DEFAULT '',
  `filename` varchar(255) DEFAULT NULL,
  `filesize` int unsigned DEFAULT NULL,
  `mimetype` varchar(64) DEFAULT NULL,
  `duration` varchar(32) DEFAULT NULL,
  `dimension_value` varchar(32) DEFAULT NULL,
  `dimension_unit` varchar(32) DEFAULT NULL,
  `data_rate_value` varchar(32) DEFAULT NULL,
  `data_rate_unit` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`format_idx`),
  KEY `contract_adid` (`contract_adid`,`token_unid`),
  KEY `token_unid` (`token_unid`),
  CONSTRAINT `tokens_formats_ibfk_1` FOREIGN KEY (`contract_adid`, `token_unid`) REFERENCES `tokens` (`contract_adid`, `token_unid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tokens_ref_collection` (
  `contract_adid` int unsigned NOT NULL,
  `token_unid` bigint NOT NULL,
  `collection_group` varchar(16) DEFAULT NULL,
  `collection_id` bigint DEFAULT NULL,
  PRIMARY KEY (`contract_adid`,`token_unid`),
  KEY `collection_id` (`collection_id`),
  CONSTRAINT `tokens_ref_collection_ibfk_1` FOREIGN KEY (`contract_adid`, `token_unid`) REFERENCES `tokens` (`contract_adid`, `token_unid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tokens_ref_mimetypes` (
  `contract_adid` int unsigned NOT NULL,
  `token_unid` bigint NOT NULL,
  `mimetype_id` int unsigned NOT NULL,
  PRIMARY KEY (`contract_adid`,`token_unid`),
  KEY `token_unid` (`token_unid`),
  KEY `mimetype_id` (`mimetype_id`),
  CONSTRAINT `tokens_ref_mimetypes_ibfk_1` FOREIGN KEY (`contract_adid`, `token_unid`) REFERENCES `tokens` (`contract_adid`, `token_unid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `tokens_ref_mimetypes_ibfk_2` FOREIGN KEY (`mimetype_id`) REFERENCES `mimetypes` (`mimetype_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tokens_ref_tags` (
  `contract_adid` int unsigned NOT NULL,
  `token_unid` bigint NOT NULL,
  `token_tag_id` int unsigned NOT NULL,
  PRIMARY KEY (`contract_adid`,`token_unid`,`token_tag_id`),
  KEY `token_unid` (`token_unid`),
  KEY `token_tag_id` (`token_tag_id`),
  CONSTRAINT `tokens_ref_tags_ibfk_1` FOREIGN KEY (`contract_adid`, `token_unid`) REFERENCES `tokens` (`contract_adid`, `token_unid`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `tokens_ref_tags_ibfk_2` FOREIGN KEY (`token_tag_id`) REFERENCES `token_tags` (`token_tag_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tokens_royalties` (
  `contract_adid` int unsigned NOT NULL,
  `token_unid` bigint NOT NULL,
  `royalty_adid` int unsigned NOT NULL,
  `shares` int NOT NULL,
  `decimals` tinyint unsigned NOT NULL,
  PRIMARY KEY (`contract_adid`,`token_unid`,`royalty_adid`),
  KEY `royalty_adid` (`royalty_adid`),
  KEY `token_unid` (`token_unid`),
  CONSTRAINT `tokens_royalties_ibfk_1` FOREIGN KEY (`contract_adid`, `token_unid`) REFERENCES `tokens` (`contract_adid`, `token_unid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tokens_undefined_keys` (
  `contract_adid` int unsigned NOT NULL,
  `token_unid` bigint NOT NULL,
  `key` varbinary(128) NOT NULL,
  `value_json` mediumtext DEFAULT NULL,
  `ts_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`contract_adid`,`token_unid`,`key`),
  KEY `ts_created` (`ts_created`),
  KEY `key` (`key`),
  KEY `token_unid` (`token_unid`),
  CONSTRAINT `tokens_undefined_keys_ibfk_1` FOREIGN KEY (`contract_adid`, `token_unid`) REFERENCES `tokens` (`contract_adid`, `token_unid`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `transactions_entrypoints` (
  `target_address` varbinary(256) NOT NULL DEFAULT '',
  `entrypoint` varbinary(128) NOT NULL DEFAULT '',
  `level_min` int unsigned NOT NULL,
  `level_max` int unsigned NOT NULL,
  `calls_count` int unsigned NOT NULL,
  PRIMARY KEY (`target_address`,`entrypoint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------------------------------------------------
-- Helper functions for manual queries: address <-> internal adid (table `address`)
-- -----------------------------------------------------------------------------------------------------------------------

DROP FUNCTION IF EXISTS ADDR2ADID;
DROP FUNCTION IF EXISTS ADID2ADDR;

DELIMITER $$

CREATE FUNCTION ADDR2ADID(in_addr VARCHAR(64))
    RETURNS INT
    DETERMINISTIC
    READS SQL DATA
BEGIN
    DECLARE res INT;
    SELECT adid INTO res FROM address WHERE address = in_addr;
    RETURN res;
END$$

CREATE FUNCTION ADID2ADDR(in_adid INT)
    RETURNS VARCHAR(64)
    DETERMINISTIC
    READS SQL DATA
BEGIN
    DECLARE res VARCHAR(64);
    SELECT address INTO res FROM address WHERE adid = in_adid;
    RETURN res;
END$$

DELIMITER ;

SET FOREIGN_KEY_CHECKS = 1;
