-- Guest request on the enquiry; agreed plan on the quotation. Accept uses the quotation.
-- event_enquiry: times and the accommodation request.
-- event_enquiry_space: spaces the guest asked for.
-- quotation: agreed date and times (NULL on older rows).
-- quotation_space: spaces agreed for the event.
-- quotation_room: rooms or villa, each with check-in and check-out.
-- quotation_item.qi_sp_id: space a line prices. Accept freezes that amount.

ALTER TABLE event_enquiry
    ADD COLUMN ee_start_time TIME NULL AFTER ee_alternate_date,
    ADD COLUMN ee_end_time TIME NULL AFTER ee_start_time,
    ADD COLUMN ee_accommodation_sp_id INT UNSIGNED NULL AFTER ee_self_decor,
    ADD COLUMN ee_accommodation_check_in DATE NULL AFTER ee_accommodation_sp_id,
    ADD COLUMN ee_accommodation_check_out DATE NULL AFTER ee_accommodation_check_in,
    ADD COLUMN ee_accommodation_rooms TINYINT UNSIGNED NULL AFTER ee_accommodation_check_out,
    ADD COLUMN ee_accommodation_guests SMALLINT UNSIGNED NULL AFTER ee_accommodation_rooms,
    ADD CONSTRAINT fk_ee_accommodation_space FOREIGN KEY (ee_accommodation_sp_id) REFERENCES space (sp_id) ON UPDATE CASCADE;

CREATE TABLE event_enquiry_space (
    ees_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ees_ee_id BIGINT UNSIGNED NOT NULL,
    ees_sp_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (ees_id),
    UNIQUE KEY uq_ees (ees_ee_id, ees_sp_id),
    CONSTRAINT fk_ees_enquiry FOREIGN KEY (ees_ee_id) REFERENCES event_enquiry (ee_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_ees_space FOREIGN KEY (ees_sp_id) REFERENCES space (sp_id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE quotation
    ADD COLUMN q_event_date DATE NULL AFTER q_valid_until,
    ADD COLUMN q_start_time TIME NULL AFTER q_event_date,
    ADD COLUMN q_end_time TIME NULL AFTER q_start_time;

CREATE TABLE quotation_space (
    qs_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    qs_q_id BIGINT UNSIGNED NOT NULL,
    qs_sp_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (qs_id),
    UNIQUE KEY uq_qs (qs_q_id, qs_sp_id),
    CONSTRAINT fk_qs_quote FOREIGN KEY (qs_q_id) REFERENCES quotation (q_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_qs_space FOREIGN KEY (qs_sp_id) REFERENCES space (sp_id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE quotation_room (
    qr_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    qr_q_id BIGINT UNSIGNED NOT NULL,
    qr_sp_id INT UNSIGNED NOT NULL,
    qr_check_in DATE NOT NULL,
    qr_check_out DATE NOT NULL,
    PRIMARY KEY (qr_id),
    UNIQUE KEY uq_qr (qr_q_id, qr_sp_id),
    CONSTRAINT fk_qr_quote FOREIGN KEY (qr_q_id) REFERENCES quotation (q_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_qr_space FOREIGN KEY (qr_sp_id) REFERENCES space (sp_id) ON UPDATE CASCADE,
    CONSTRAINT chk_qr_dates CHECK (qr_check_out > qr_check_in)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE quotation_item
    ADD COLUMN qi_sp_id INT UNSIGNED NULL AFTER qi_q_id,
    ADD CONSTRAINT fk_qi_space FOREIGN KEY (qi_sp_id) REFERENCES space (sp_id) ON UPDATE CASCADE;
