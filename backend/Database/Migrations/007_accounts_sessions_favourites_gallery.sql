-- Sessions, password resets, saved spaces, and the public gallery.
-- user_type and app_user already exist; this only adds what is missing.

CREATE TABLE `user_session` (
  `us_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `us_u_id` bigint unsigned NOT NULL,
  -- sha256 of the JWT. A leaked backup cannot be used to sign in.
  `us_token_hash` char(64) NOT NULL,
  `us_expires_at` datetime NOT NULL,
  `us_revoked_at` datetime DEFAULT NULL,
  `us_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`us_id`),
  UNIQUE KEY `us_token_hash` (`us_token_hash`),
  KEY `idx_session_user` (`us_u_id`),
  CONSTRAINT `fk_session_user` FOREIGN KEY (`us_u_id`) REFERENCES `app_user` (`u_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `password_reset` (
  `pr_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pr_u_id` bigint unsigned NOT NULL,
  -- Hash of the emailed reset token. The raw token is never stored.
  `pr_token_hash` char(64) NOT NULL,
  `pr_expires_at` datetime NOT NULL,
  `pr_used_at` datetime DEFAULT NULL,
  `pr_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`pr_id`),
  UNIQUE KEY `pr_token_hash` (`pr_token_hash`),
  KEY `idx_password_reset_user` (`pr_u_id`),
  CONSTRAINT `fk_password_reset_user` FOREIGN KEY (`pr_u_id`) REFERENCES `app_user` (`u_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `favourite` (
  `f_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `f_u_id` bigint unsigned NOT NULL,
  `f_sp_id` int unsigned NOT NULL,
  `f_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`f_id`),
  UNIQUE KEY `uq_favourite` (`f_u_id`, `f_sp_id`),
  KEY `idx_favourite_space` (`f_sp_id`),
  CONSTRAINT `fk_favourite_user` FOREIGN KEY (`f_u_id`) REFERENCES `app_user` (`u_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_favourite_space` FOREIGN KEY (`f_sp_id`) REFERENCES `space` (`sp_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Curated gallery grid, separate from per-space media.
-- An item can caption a group of spaces, or an event that is not a space.
CREATE TABLE `gallery_item` (
  `g_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `g_title` varchar(150) NOT NULL,
  `g_short_title` varchar(80) DEFAULT NULL,
  `g_space_label` varchar(100) DEFAULT NULL,
  `g_category` enum('villa','venues','grounds','events') NOT NULL,
  `g_url` varchar(500) NOT NULL,
  `g_is_wide` tinyint(1) NOT NULL DEFAULT '0',
  `g_sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `g_is_active` tinyint(1) NOT NULL DEFAULT '1',
  `g_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`g_id`),
  KEY `idx_gallery_category` (`g_category`, `g_sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
