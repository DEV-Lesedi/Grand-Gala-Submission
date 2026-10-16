-- Quotation lines, ticket-order lines, and a public event's description and image.

CREATE TABLE quotation_item (
    qi_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    qi_q_id BIGINT UNSIGNED NOT NULL,
    qi_position SMALLINT UNSIGNED NOT NULL,
    qi_label VARCHAR(255) NOT NULL,
    qi_quantity DECIMAL(12,2) NOT NULL,
    qi_unit_price DECIMAL(12,2) NOT NULL,
    PRIMARY KEY (qi_id),
    UNIQUE KEY uq_qi_position (qi_q_id, qi_position),
    CONSTRAINT fk_qi_quote FOREIGN KEY (qi_q_id) REFERENCES quotation (q_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT chk_qi_values CHECK (qi_position > 0 AND qi_quantity >= 0 AND qi_unit_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE ticket_order_item (
    toi_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    toi_to_id BIGINT UNSIGNED NOT NULL,
    toi_tt_id BIGINT UNSIGNED NOT NULL,
    toi_quantity INT UNSIGNED NOT NULL,
    toi_unit_price DECIMAL(12,2) NOT NULL,
    PRIMARY KEY (toi_id),
    UNIQUE KEY uq_order_tier (toi_to_id, toi_tt_id),
    CONSTRAINT fk_toi_order FOREIGN KEY (toi_to_id) REFERENCES ticket_order (to_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_toi_tier FOREIGN KEY (toi_tt_id) REFERENCES ticket_type (tt_id) ON UPDATE CASCADE,
    CONSTRAINT chk_toi_quantity CHECK (toi_quantity > 0),
    CONSTRAINT chk_toi_price CHECK (toi_unit_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE event_booking
    ADD COLUMN eb_description TEXT NULL AFTER eb_title,
    ADD COLUMN eb_image_url VARCHAR(255) NULL AFTER eb_description;
