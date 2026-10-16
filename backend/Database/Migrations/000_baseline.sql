-- Baseline schema. Recorded as applied on Railway; runs first on an empty database.
-- Do not edit. Add a new numbered migration.

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE `amenity` (
  `a_id` int unsigned NOT NULL AUTO_INCREMENT,
  `a_name` varchar(100) NOT NULL,
  `a_description` text,
  `a_is_active` tinyint(1) NOT NULL DEFAULT '1',
  `a_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`a_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `amenity_booking` (
  `ab_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ab_b_id` bigint unsigned NOT NULL,
  `ab_a_id` int unsigned NOT NULL,
  `ab_quantity` tinyint unsigned NOT NULL DEFAULT '1',
  `ab_price_snapshot` decimal(12,2) NOT NULL,
  `ab_line_total` decimal(12,2) NOT NULL,
  `ab_scheduled_at` datetime DEFAULT NULL,
  `ab_status` enum('pending','confirmed','delivered','completed','cancelled') NOT NULL DEFAULT 'pending',
  `ab_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ab_date_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ab_id`),
  KEY `fk_amenitybooking_amenity` (`ab_a_id`),
  KEY `idx_ab_booking` (`ab_b_id`),
  CONSTRAINT `fk_amenitybooking_amenity` FOREIGN KEY (`ab_a_id`) REFERENCES `amenity` (`a_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_amenitybooking_booking` FOREIGN KEY (`ab_b_id`) REFERENCES `booking` (`b_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_ab_amounts` CHECK (((`ab_price_snapshot` >= 0) and (`ab_line_total` >= 0))),
  CONSTRAINT `chk_ab_quantity` CHECK ((`ab_quantity` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `amenity_rate` (
  `ar_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ar_a_id` int unsigned NOT NULL,
  `ar_price` decimal(12,2) NOT NULL,
  `ar_valid_from` date NOT NULL,
  `ar_valid_to` date DEFAULT NULL,
  `ar_is_active` tinyint(1) NOT NULL DEFAULT '1',
  `ar_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ar_id`),
  KEY `idx_ar_lookup` (`ar_a_id`,`ar_valid_from`,`ar_valid_to`),
  CONSTRAINT `fk_ar_amenity` FOREIGN KEY (`ar_a_id`) REFERENCES `amenity` (`a_id`) ON UPDATE CASCADE,
  CONSTRAINT `chk_ar_period` CHECK (((`ar_valid_to` is null) or (`ar_valid_to` >= `ar_valid_from`))),
  CONSTRAINT `chk_ar_price` CHECK ((`ar_price` >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `app_user` (
  `u_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `u_ut_id` int unsigned NOT NULL,
  `u_name` varchar(80) NOT NULL,
  `u_surname` varchar(80) NOT NULL,
  `u_email` varchar(150) NOT NULL,
  `u_phone` varchar(30) DEFAULT NULL,
  `u_password_hash` varchar(255) NOT NULL,
  `u_is_active` tinyint(1) NOT NULL DEFAULT '1',
  `u_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `u_date_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`u_id`),
  UNIQUE KEY `u_email` (`u_email`),
  KEY `fk_user_type` (`u_ut_id`),
  CONSTRAINT `fk_user_type` FOREIGN KEY (`u_ut_id`) REFERENCES `user_type` (`ut_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `booking` (
  `b_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `b_reference` varchar(20) NOT NULL,
  `b_bt_id` tinyint unsigned NOT NULL,
  `b_u_id` bigint unsigned NOT NULL,
  `b_status` enum('enquiry','quoted','pending_payment','confirmed','completed','cancelled','expired','refunded') NOT NULL DEFAULT 'enquiry',
  `b_subtotal` decimal(12,2) NOT NULL DEFAULT '0.00',
  `b_promo_id` int unsigned DEFAULT NULL,
  `b_discount_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `b_total_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `b_notes` text,
  `b_hold_expires_at` datetime DEFAULT NULL,
  `b_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `b_date_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`b_id`),
  UNIQUE KEY `b_reference` (`b_reference`),
  KEY `fk_booking_type` (`b_bt_id`),
  KEY `idx_booking_status` (`b_status`,`b_date_created`),
  KEY `idx_booking_user` (`b_u_id`),
  KEY `fk_booking_promo` (`b_promo_id`),
  CONSTRAINT `fk_booking_promo` FOREIGN KEY (`b_promo_id`) REFERENCES `promo` (`promo_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_booking_type` FOREIGN KEY (`b_bt_id`) REFERENCES `booking_type` (`bt_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_booking_user` FOREIGN KEY (`b_u_id`) REFERENCES `app_user` (`u_id`) ON UPDATE CASCADE,
  CONSTRAINT `chk_booking_totals` CHECK (((`b_subtotal` >= 0) and (`b_discount_amount` >= 0) and (`b_total_price` >= 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `booking_status_history` (
  `bsh_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `bsh_b_id` bigint unsigned NOT NULL,
  `bsh_from_status` varchar(30) DEFAULT NULL,
  `bsh_to_status` varchar(30) NOT NULL,
  `bsh_changed_by` bigint unsigned DEFAULT NULL,
  `bsh_reason` varchar(255) DEFAULT NULL,
  `bsh_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`bsh_id`),
  KEY `fk_bsh_user` (`bsh_changed_by`),
  KEY `idx_bsh_booking` (`bsh_b_id`,`bsh_date_created`),
  CONSTRAINT `fk_bsh_booking` FOREIGN KEY (`bsh_b_id`) REFERENCES `booking` (`b_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bsh_user` FOREIGN KEY (`bsh_changed_by`) REFERENCES `app_user` (`u_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `booking_type` (
  `bt_id` tinyint unsigned NOT NULL AUTO_INCREMENT,
  `bt_name` varchar(30) NOT NULL,
  `bt_description` varchar(255) DEFAULT NULL,
  `bt_is_active` tinyint(1) NOT NULL DEFAULT '1',
  `bt_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`bt_id`),
  UNIQUE KEY `bt_name` (`bt_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `catering` (
  `c_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `c_b_id` bigint unsigned NOT NULL,
  `c_ct_id` int unsigned DEFAULT NULL,
  `c_meal_type` enum('breakfast','lunch','dinner','all') NOT NULL,
  `c_headcount` smallint unsigned DEFAULT NULL,
  `c_price_snapshot` decimal(12,2) DEFAULT NULL,
  `c_line_total` decimal(12,2) DEFAULT NULL,
  `c_special_requests` text,
  `c_status` enum('pending','confirmed','delivered','completed','cancelled') NOT NULL DEFAULT 'pending',
  `c_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `c_date_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`c_id`),
  KEY `fk_catering_tier` (`c_ct_id`),
  KEY `idx_catering_booking` (`c_b_id`),
  CONSTRAINT `fk_catering_booking` FOREIGN KEY (`c_b_id`) REFERENCES `booking` (`b_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_catering_tier` FOREIGN KEY (`c_ct_id`) REFERENCES `catering_tier` (`ct_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_catering_amount` CHECK ((((`c_price_snapshot` is null) or (`c_price_snapshot` >= 0)) and ((`c_line_total` is null) or (`c_line_total` >= 0)))),
  CONSTRAINT `chk_catering_head` CHECK (((`c_headcount` is null) or (`c_headcount` > 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `catering_tier` (
  `ct_id` int unsigned NOT NULL AUTO_INCREMENT,
  `ct_name` varchar(80) NOT NULL,
  `ct_description` text,
  `ct_min_headcount` smallint unsigned NOT NULL DEFAULT '1',
  `ct_is_active` tinyint(1) NOT NULL DEFAULT '1',
  `ct_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ct_id`),
  UNIQUE KEY `ct_name` (`ct_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `catering_tier_rate` (
  `ctr_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ctr_ct_id` int unsigned NOT NULL,
  `ctr_price_per_head` decimal(12,2) NOT NULL,
  `ctr_valid_from` date NOT NULL,
  `ctr_valid_to` date DEFAULT NULL,
  `ctr_is_active` tinyint(1) NOT NULL DEFAULT '1',
  `ctr_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ctr_id`),
  KEY `idx_ctr_lookup` (`ctr_ct_id`,`ctr_valid_from`,`ctr_valid_to`),
  CONSTRAINT `fk_ctr_tier` FOREIGN KEY (`ctr_ct_id`) REFERENCES `catering_tier` (`ct_id`) ON UPDATE CASCADE,
  CONSTRAINT `chk_ctr_period` CHECK (((`ctr_valid_to` is null) or (`ctr_valid_to` >= `ctr_valid_from`))),
  CONSTRAINT `chk_ctr_price` CHECK ((`ctr_price_per_head` >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `decoration` (
  `d_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `d_b_id` bigint unsigned NOT NULL,
  `d_requests` text NOT NULL,
  `d_status` enum('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending',
  `d_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `d_date_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`d_id`),
  KEY `idx_decoration_booking` (`d_b_id`),
  CONSTRAINT `fk_decoration_booking` FOREIGN KEY (`d_b_id`) REFERENCES `booking` (`b_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `event_booking` (
  `eb_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `eb_b_id` bigint unsigned NOT NULL,
  `eb_v_id` int unsigned NOT NULL,
  `eb_title` varchar(150) DEFAULT NULL,
  `eb_event_date` date NOT NULL,
  `eb_start_time` time NOT NULL,
  `eb_end_time` time NOT NULL,
  `eb_visibility` enum('public','private') NOT NULL,
  `eb_is_venue_hosted` tinyint(1) NOT NULL DEFAULT '0',
  `eb_occasion` varchar(150) DEFAULT NULL,
  `eb_guest_count` int unsigned DEFAULT NULL,
  `eb_capacity` int unsigned DEFAULT NULL,
  `eb_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `eb_date_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`eb_id`),
  UNIQUE KEY `eb_b_id` (`eb_b_id`),
  KEY `idx_event_conflict` (`eb_v_id`,`eb_event_date`,`eb_start_time`,`eb_end_time`),
  KEY `idx_event_public` (`eb_visibility`,`eb_event_date`),
  CONSTRAINT `fk_event_booking` FOREIGN KEY (`eb_b_id`) REFERENCES `booking` (`b_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_event_venue` FOREIGN KEY (`eb_v_id`) REFERENCES `venue` (`v_id`) ON UPDATE CASCADE,
  CONSTRAINT `chk_event_times` CHECK ((`eb_end_time` > `eb_start_time`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `event_enquiry` (
  `ee_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ee_b_id` bigint unsigned NOT NULL,
  `ee_preferred_date` date DEFAULT NULL,
  `ee_alternate_date` date DEFAULT NULL,
  `ee_estimated_guests` int unsigned DEFAULT NULL,
  `ee_occasion` varchar(150) DEFAULT NULL,
  `ee_requirements` text,
  `ee_wants_catering` tinyint(1) NOT NULL DEFAULT '0',
  `ee_wants_decor` tinyint(1) NOT NULL DEFAULT '0',
  `ee_self_catering` tinyint(1) NOT NULL DEFAULT '0',
  `ee_self_decor` tinyint(1) NOT NULL DEFAULT '0',
  `ee_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ee_id`),
  UNIQUE KEY `ee_b_id` (`ee_b_id`),
  CONSTRAINT `fk_enquiry_booking` FOREIGN KEY (`ee_b_id`) REFERENCES `booking` (`b_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `event_space` (
  `es_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `es_eb_id` bigint unsigned NOT NULL,
  `es_sp_id` int unsigned NOT NULL,
  `es_price_snapshot` decimal(12,2) NOT NULL,
  `es_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`es_id`),
  UNIQUE KEY `uq_event_space` (`es_eb_id`,`es_sp_id`),
  KEY `idx_es_space` (`es_sp_id`),
  CONSTRAINT `fk_es_event` FOREIGN KEY (`es_eb_id`) REFERENCES `event_booking` (`eb_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_es_space` FOREIGN KEY (`es_sp_id`) REFERENCES `space` (`sp_id`) ON UPDATE CASCADE,
  CONSTRAINT `chk_es_price` CHECK ((`es_price_snapshot` >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `event_space_allocation` (
  `esa_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `esa_eb_id` bigint unsigned NOT NULL,
  `esa_sp_id` int unsigned NOT NULL,
  `esa_check_in` date NOT NULL,
  `esa_check_out` date NOT NULL,
  `esa_price_snapshot` decimal(12,2) NOT NULL,
  `esa_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`esa_id`),
  KEY `fk_esa_event` (`esa_eb_id`),
  KEY `idx_esa_space` (`esa_sp_id`,`esa_check_in`,`esa_check_out`),
  CONSTRAINT `fk_esa_event` FOREIGN KEY (`esa_eb_id`) REFERENCES `event_booking` (`eb_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_esa_space` FOREIGN KEY (`esa_sp_id`) REFERENCES `space` (`sp_id`) ON UPDATE CASCADE,
  CONSTRAINT `chk_esa_dates` CHECK ((`esa_check_out` > `esa_check_in`)),
  CONSTRAINT `chk_esa_price` CHECK ((`esa_price_snapshot` >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `media` (
  `m_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `m_owner_type` enum('venue','space','event') NOT NULL,
  `m_owner_id` bigint unsigned NOT NULL,
  `m_url` varchar(500) NOT NULL,
  `m_caption` varchar(255) DEFAULT NULL,
  `m_alt_text` varchar(255) DEFAULT NULL,
  `m_is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `m_sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `m_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`m_id`),
  KEY `idx_media_owner` (`m_owner_type`,`m_owner_id`,`m_sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `notification` (
  `n_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `n_u_id` bigint unsigned NOT NULL,
  `n_channel` enum('in_app','email','sms') NOT NULL DEFAULT 'in_app',
  `n_subject` varchar(150) DEFAULT NULL,
  `n_message` text NOT NULL,
  `n_is_read` tinyint(1) NOT NULL DEFAULT '0',
  `n_sent_at` datetime DEFAULT NULL,
  `n_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`n_id`),
  KEY `idx_notification_user` (`n_u_id`,`n_is_read`),
  CONSTRAINT `fk_notification_user` FOREIGN KEY (`n_u_id`) REFERENCES `app_user` (`u_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `payment` (
  `p_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `p_b_id` bigint unsigned DEFAULT NULL,
  `p_to_id` bigint unsigned DEFAULT NULL,
  `p_u_id` bigint unsigned DEFAULT NULL,
  `p_amount` decimal(12,2) NOT NULL,
  `p_currency` char(3) NOT NULL DEFAULT 'ZAR',
  `p_method` enum('payfast','ozow','eft','cash','other') NOT NULL DEFAULT 'payfast',
  `p_merchant_reference` varchar(64) NOT NULL,
  `p_gateway_reference` varchar(64) DEFAULT NULL,
  `p_signature_valid` tinyint(1) DEFAULT NULL,
  `p_itn_payload` json DEFAULT NULL,
  `p_status` enum('pending','processing','paid','failed','cancelled','refunded') NOT NULL DEFAULT 'pending',
  `p_failure_reason` varchar(255) DEFAULT NULL,
  `p_paid_at` datetime DEFAULT NULL,
  `p_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `p_date_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`p_id`),
  UNIQUE KEY `p_merchant_reference` (`p_merchant_reference`),
  UNIQUE KEY `p_gateway_reference` (`p_gateway_reference`),
  KEY `fk_payment_user` (`p_u_id`),
  KEY `idx_payment_booking` (`p_b_id`),
  KEY `idx_payment_order` (`p_to_id`),
  KEY `idx_payment_status` (`p_status`,`p_date_created`),
  CONSTRAINT `fk_payment_booking` FOREIGN KEY (`p_b_id`) REFERENCES `booking` (`b_id`),
  CONSTRAINT `fk_payment_order` FOREIGN KEY (`p_to_id`) REFERENCES `ticket_order` (`to_id`),
  CONSTRAINT `fk_payment_user` FOREIGN KEY (`p_u_id`) REFERENCES `app_user` (`u_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_payment_amount` CHECK ((`p_amount` >= 0)),
  CONSTRAINT `chk_payment_target` CHECK ((((`p_b_id` is not null) and (`p_to_id` is null)) or ((`p_b_id` is null) and (`p_to_id` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `promo` (
  `promo_id` int unsigned NOT NULL AUTO_INCREMENT,
  `promo_code` varchar(30) NOT NULL,
  `promo_description` varchar(255) DEFAULT NULL,
  `promo_type` enum('percentage','fixed') NOT NULL,
  `promo_value` decimal(12,2) NOT NULL,
  `promo_min_spend` decimal(12,2) NOT NULL DEFAULT '0.00',
  `promo_max_discount` decimal(12,2) DEFAULT NULL,
  `promo_usage_limit` int unsigned DEFAULT NULL,
  `promo_usage_count` int unsigned NOT NULL DEFAULT '0',
  `promo_limit_per_user` tinyint unsigned DEFAULT NULL,
  `promo_valid_from` date NOT NULL,
  `promo_valid_to` date NOT NULL,
  `promo_is_active` tinyint(1) NOT NULL DEFAULT '1',
  `promo_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `promo_date_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`promo_id`),
  UNIQUE KEY `promo_code` (`promo_code`),
  CONSTRAINT `chk_promo_period` CHECK ((`promo_valid_to` >= `promo_valid_from`)),
  CONSTRAINT `chk_promo_value` CHECK ((`promo_value` >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `quotation` (
  `q_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `q_b_id` bigint unsigned NOT NULL,
  `q_reference` varchar(20) NOT NULL,
  `q_amount` decimal(12,2) NOT NULL,
  `q_breakdown` text,
  `q_status` enum('sent','accepted','expired','declined','superseded') NOT NULL DEFAULT 'sent',
  `q_valid_until` date DEFAULT NULL,
  `q_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `q_date_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`q_id`),
  UNIQUE KEY `q_reference` (`q_reference`),
  KEY `idx_q_booking` (`q_b_id`,`q_status`),
  CONSTRAINT `fk_quotation_booking` FOREIGN KEY (`q_b_id`) REFERENCES `booking` (`b_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_q_amount` CHECK ((`q_amount` >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `refund` (
  `rf_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rf_p_id` bigint unsigned NOT NULL,
  `rf_amount` decimal(12,2) NOT NULL,
  `rf_reason` varchar(255) DEFAULT NULL,
  `rf_processed_by` bigint unsigned DEFAULT NULL,
  `rf_status` enum('pending','processed','failed') NOT NULL DEFAULT 'pending',
  `rf_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`rf_id`),
  KEY `fk_refund_user` (`rf_processed_by`),
  KEY `idx_refund_payment` (`rf_p_id`),
  CONSTRAINT `fk_refund_payment` FOREIGN KEY (`rf_p_id`) REFERENCES `payment` (`p_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_refund_user` FOREIGN KEY (`rf_processed_by`) REFERENCES `app_user` (`u_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_refund_amount` CHECK ((`rf_amount` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `review` (
  `rev_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rev_b_id` bigint unsigned NOT NULL,
  `rev_rating` tinyint unsigned NOT NULL,
  `rev_comment` text,
  `rev_is_approved` tinyint(1) NOT NULL DEFAULT '0',
  `rev_approved_by` bigint unsigned DEFAULT NULL,
  `rev_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `rev_date_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`rev_id`),
  UNIQUE KEY `rev_b_id` (`rev_b_id`),
  KEY `fk_review_approver` (`rev_approved_by`),
  KEY `idx_review_approved` (`rev_is_approved`,`rev_date_created`),
  CONSTRAINT `fk_review_approver` FOREIGN KEY (`rev_approved_by`) REFERENCES `app_user` (`u_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_review_booking` FOREIGN KEY (`rev_b_id`) REFERENCES `booking` (`b_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_review_rating` CHECK ((`rev_rating` between 1 and 5))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `rsvp` (
  `rsvp_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rsvp_eb_id` bigint unsigned NOT NULL,
  `rsvp_guest_name` varchar(80) NOT NULL,
  `rsvp_guest_surname` varchar(80) NOT NULL,
  `rsvp_guest_email` varchar(150) DEFAULT NULL,
  `rsvp_response` enum('pending','attending','not_attending') NOT NULL DEFAULT 'pending',
  `rsvp_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`rsvp_id`),
  KEY `idx_rsvp_event` (`rsvp_eb_id`,`rsvp_response`),
  CONSTRAINT `fk_rsvp_event` FOREIGN KEY (`rsvp_eb_id`) REFERENCES `event_booking` (`eb_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `space` (
  `sp_id` int unsigned NOT NULL AUTO_INCREMENT,
  `sp_v_id` int unsigned NOT NULL,
  `sp_name` varchar(100) NOT NULL,
  `sp_type` enum('villa','garden','marquee','pangola','pool','hall','other') NOT NULL DEFAULT 'other',
  `sp_description` text,
  `sp_capacity` int unsigned NOT NULL,
  `sp_is_accommodation` tinyint(1) NOT NULL DEFAULT '0',
  `sp_is_hireable` tinyint(1) NOT NULL DEFAULT '1',
  `sp_is_whole_unit` tinyint(1) NOT NULL DEFAULT '0',
  `sp_is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sp_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sp_date_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`sp_id`),
  KEY `fk_space_venue` (`sp_v_id`),
  CONSTRAINT `fk_space_venue` FOREIGN KEY (`sp_v_id`) REFERENCES `venue` (`v_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `space_blackout` (
  `sbk_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sbk_sp_id` int unsigned NOT NULL,
  `sbk_start_date` date NOT NULL,
  `sbk_end_date` date NOT NULL,
  `sbk_reason` varchar(255) DEFAULT NULL,
  `sbk_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`sbk_id`),
  KEY `idx_blackout_lookup` (`sbk_sp_id`,`sbk_start_date`,`sbk_end_date`),
  CONSTRAINT `fk_blackout_space` FOREIGN KEY (`sbk_sp_id`) REFERENCES `space` (`sp_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_blackout_dates` CHECK ((`sbk_end_date` >= `sbk_start_date`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `space_rate` (
  `sr_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sr_sp_id` int unsigned NOT NULL,
  `sr_basis` enum('per_night','per_event','per_hour','per_head') NOT NULL,
  `sr_price` decimal(12,2) NOT NULL,
  `sr_label` varchar(80) DEFAULT NULL,
  `sr_valid_from` date NOT NULL,
  `sr_valid_to` date DEFAULT NULL,
  `sr_is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sr_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`sr_id`),
  KEY `idx_rate_lookup` (`sr_sp_id`,`sr_basis`,`sr_valid_from`,`sr_valid_to`),
  CONSTRAINT `fk_rate_space` FOREIGN KEY (`sr_sp_id`) REFERENCES `space` (`sp_id`) ON UPDATE CASCADE,
  CONSTRAINT `chk_rate_period` CHECK (((`sr_valid_to` is null) or (`sr_valid_to` >= `sr_valid_from`))),
  CONSTRAINT `chk_rate_price` CHECK ((`sr_price` >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `stay_booking` (
  `sb_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `sb_b_id` bigint unsigned NOT NULL,
  `sb_check_in` date NOT NULL,
  `sb_check_out` date NOT NULL,
  `sb_guests` smallint unsigned NOT NULL,
  `sb_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`sb_id`),
  UNIQUE KEY `sb_b_id` (`sb_b_id`),
  KEY `idx_stay_dates` (`sb_check_in`,`sb_check_out`),
  CONSTRAINT `fk_stay_booking` FOREIGN KEY (`sb_b_id`) REFERENCES `booking` (`b_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_stay_dates` CHECK ((`sb_check_out` > `sb_check_in`)),
  CONSTRAINT `chk_stay_guests` CHECK ((`sb_guests` > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `stay_space` (
  `ss_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ss_sb_id` bigint unsigned NOT NULL,
  `ss_sp_id` int unsigned NOT NULL,
  `ss_nights` smallint unsigned NOT NULL,
  `ss_price_snapshot` decimal(12,2) NOT NULL,
  `ss_line_total` decimal(12,2) NOT NULL,
  `ss_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ss_id`),
  UNIQUE KEY `uq_stay_space` (`ss_sb_id`,`ss_sp_id`),
  KEY `idx_ss_space` (`ss_sp_id`),
  CONSTRAINT `fk_ss_space` FOREIGN KEY (`ss_sp_id`) REFERENCES `space` (`sp_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_ss_stay` FOREIGN KEY (`ss_sb_id`) REFERENCES `stay_booking` (`sb_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_ss_amounts` CHECK (((`ss_price_snapshot` >= 0) and (`ss_line_total` >= 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `ticket` (
  `tk_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tk_to_id` bigint unsigned NOT NULL,
  `tk_tt_id` bigint unsigned NOT NULL,
  `tk_code` varchar(40) NOT NULL,
  `tk_price_snapshot` decimal(12,2) NOT NULL,
  `tk_attendee_name` varchar(160) DEFAULT NULL,
  `tk_status` enum('valid','used','cancelled','refunded') NOT NULL DEFAULT 'valid',
  `tk_used_at` datetime DEFAULT NULL,
  `tk_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`tk_id`),
  UNIQUE KEY `tk_code` (`tk_code`),
  KEY `fk_ticket_order` (`tk_to_id`),
  KEY `idx_ticket_type_status` (`tk_tt_id`,`tk_status`),
  CONSTRAINT `fk_ticket_order` FOREIGN KEY (`tk_to_id`) REFERENCES `ticket_order` (`to_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ticket_type` FOREIGN KEY (`tk_tt_id`) REFERENCES `ticket_type` (`tt_id`) ON UPDATE CASCADE,
  CONSTRAINT `chk_tk_price` CHECK ((`tk_price_snapshot` >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `ticket_order` (
  `to_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `to_reference` varchar(20) NOT NULL,
  `to_eb_id` bigint unsigned NOT NULL,
  `to_u_id` bigint unsigned DEFAULT NULL,
  `to_buyer_name` varchar(80) NOT NULL,
  `to_buyer_surname` varchar(80) NOT NULL,
  `to_buyer_email` varchar(150) NOT NULL,
  `to_buyer_phone` varchar(30) DEFAULT NULL,
  `to_status` enum('pending_payment','paid','cancelled','expired','refunded') NOT NULL DEFAULT 'pending_payment',
  `to_subtotal` decimal(12,2) NOT NULL DEFAULT '0.00',
  `to_promo_id` int unsigned DEFAULT NULL,
  `to_discount_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `to_total_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `to_hold_expires_at` datetime DEFAULT NULL,
  `to_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `to_date_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`to_id`),
  UNIQUE KEY `to_reference` (`to_reference`),
  KEY `fk_to_user` (`to_u_id`),
  KEY `idx_to_status` (`to_status`,`to_date_created`),
  KEY `idx_to_event` (`to_eb_id`),
  KEY `fk_to_promo` (`to_promo_id`),
  CONSTRAINT `fk_to_event` FOREIGN KEY (`to_eb_id`) REFERENCES `event_booking` (`eb_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_to_promo` FOREIGN KEY (`to_promo_id`) REFERENCES `promo` (`promo_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_to_user` FOREIGN KEY (`to_u_id`) REFERENCES `app_user` (`u_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_to_totals` CHECK (((`to_subtotal` >= 0) and (`to_discount_amount` >= 0) and (`to_total_price` >= 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `ticket_type` (
  `tt_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tt_eb_id` bigint unsigned NOT NULL,
  `tt_name` varchar(80) NOT NULL,
  `tt_description` varchar(255) DEFAULT NULL,
  `tt_price` decimal(12,2) NOT NULL,
  `tt_quantity_allocated` int unsigned NOT NULL,
  `tt_max_per_order` tinyint unsigned NOT NULL DEFAULT '10',
  `tt_sales_open_at` datetime DEFAULT NULL,
  `tt_sales_close_at` datetime DEFAULT NULL,
  `tt_is_active` tinyint(1) NOT NULL DEFAULT '1',
  `tt_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `tt_date_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tt_id`),
  UNIQUE KEY `uq_tickettype_name` (`tt_eb_id`,`tt_name`),
  KEY `idx_tt_event` (`tt_eb_id`),
  CONSTRAINT `fk_tickettype_event` FOREIGN KEY (`tt_eb_id`) REFERENCES `event_booking` (`eb_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_tt_price` CHECK ((`tt_price` >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `user_type` (
  `ut_id` int unsigned NOT NULL AUTO_INCREMENT,
  `ut_name` varchar(30) NOT NULL,
  `ut_description` varchar(255) DEFAULT NULL,
  `ut_is_active` tinyint(1) NOT NULL DEFAULT '1',
  `ut_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ut_id`),
  UNIQUE KEY `ut_name` (`ut_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `venue` (
  `v_id` int unsigned NOT NULL AUTO_INCREMENT,
  `v_name` varchar(150) NOT NULL,
  `v_description` text,
  `v_location` varchar(255) DEFAULT NULL,
  `v_capacity` int unsigned NOT NULL,
  `v_is_active` tinyint(1) NOT NULL DEFAULT '1',
  `v_date_created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `v_date_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`v_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

SET FOREIGN_KEY_CHECKS = 1;
