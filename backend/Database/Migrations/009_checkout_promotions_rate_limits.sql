-- Checkout data: price snapshots, promo holds, and rate limits.
-- promo stores which kinds it covers and who changed it.
-- refund stores PayFast's reference and who asked.

ALTER TABLE promo
    ADD COLUMN promo_applies_to JSON NULL AFTER promo_type;

UPDATE promo
SET promo_applies_to = JSON_ARRAY('stay', 'event', 'tickets')
WHERE promo_applies_to IS NULL;

ALTER TABLE promo
    MODIFY COLUMN promo_applies_to JSON NOT NULL;

ALTER TABLE promo
    ADD COLUMN promo_created_by BIGINT UNSIGNED NULL AFTER promo_is_active,
    ADD COLUMN promo_updated_by BIGINT UNSIGNED NULL AFTER promo_created_by,
    ADD CONSTRAINT fk_promo_created_by
        FOREIGN KEY (promo_created_by) REFERENCES app_user (u_id) ON DELETE SET NULL ON UPDATE CASCADE,
    ADD CONSTRAINT fk_promo_updated_by
        FOREIGN KEY (promo_updated_by) REFERENCES app_user (u_id) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE refund
    ADD COLUMN rf_gateway_reference VARCHAR(64) NULL AFTER rf_reason,
    ADD COLUMN rf_requested_by BIGINT UNSIGNED NULL AFTER rf_gateway_reference,
    ADD KEY idx_refund_requested_by (rf_requested_by),
    ADD CONSTRAINT fk_refund_requested_by
        FOREIGN KEY (rf_requested_by) REFERENCES app_user (u_id) ON DELETE SET NULL ON UPDATE CASCADE,
    ADD UNIQUE KEY uq_refund_gateway_reference (rf_gateway_reference);

CREATE TABLE booking_line (
    bl_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bl_b_id BIGINT UNSIGNED NOT NULL,
    bl_kind ENUM('space', 'amenity', 'catering', 'other') NOT NULL,
    bl_label VARCHAR(160) NOT NULL,
    bl_quantity DECIMAL(10,2) NOT NULL,
    bl_unit_price DECIMAL(12,2) NOT NULL,
    bl_line_total DECIMAL(12,2) NOT NULL,
    bl_metadata JSON DEFAULT NULL,
    bl_created_by BIGINT UNSIGNED DEFAULT NULL,
    bl_date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (bl_id),
    KEY idx_booking_line_booking (bl_b_id),
    KEY idx_booking_line_created_by (bl_created_by),
    CONSTRAINT fk_booking_line_booking
        FOREIGN KEY (bl_b_id) REFERENCES booking (b_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_booking_line_created_by
        FOREIGN KEY (bl_created_by) REFERENCES app_user (u_id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_booking_line_amounts
        CHECK (bl_quantity > 0 AND bl_unit_price >= 0 AND bl_line_total >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE promo_use (
    pu_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pu_promo_id INT UNSIGNED NOT NULL,
    pu_user_id BIGINT UNSIGNED NOT NULL,
    pu_booking_id BIGINT UNSIGNED NOT NULL,
    pu_status ENUM('reserved', 'used', 'released') NOT NULL DEFAULT 'reserved',
    pu_date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    pu_date_updated TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (pu_id),
    UNIQUE KEY uq_promo_use_booking (pu_booking_id),
    KEY idx_promo_use_limits (pu_promo_id, pu_status, pu_user_id),
    CONSTRAINT fk_promo_use_promo
        FOREIGN KEY (pu_promo_id) REFERENCES promo (promo_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_promo_use_user
        FOREIGN KEY (pu_user_id) REFERENCES app_user (u_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_promo_use_booking
        FOREIGN KEY (pu_booking_id) REFERENCES booking (b_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE api_rate_limit (
    rl_bucket CHAR(64) NOT NULL,
    rl_window_started_at DATETIME NOT NULL,
    rl_hits INT UNSIGNED NOT NULL,
    PRIMARY KEY (rl_bucket),
    KEY idx_rate_limit_window (rl_window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
