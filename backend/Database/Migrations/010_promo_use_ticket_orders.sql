-- A promo is held by a booking or a ticket order, exactly one of the two.
-- Guest ticket orders have no account, so the user can be empty.
-- Foreign keys do not cascade: MySQL refuses a CHECK on a cascading column.

ALTER TABLE promo_use
    MODIFY COLUMN pu_user_id BIGINT UNSIGNED NULL,
    MODIFY COLUMN pu_booking_id BIGINT UNSIGNED NULL;

ALTER TABLE promo_use
    DROP FOREIGN KEY fk_promo_use_booking;

ALTER TABLE promo_use
    ADD COLUMN pu_to_id BIGINT UNSIGNED NULL AFTER pu_booking_id,
    ADD UNIQUE KEY uq_promo_use_ticket_order (pu_to_id),
    ADD CONSTRAINT chk_promo_use_one_target
        CHECK ((pu_booking_id IS NOT NULL AND pu_to_id IS NULL) OR (pu_booking_id IS NULL AND pu_to_id IS NOT NULL)),
    ADD CONSTRAINT fk_promo_use_booking
        FOREIGN KEY (pu_booking_id) REFERENCES booking (b_id),
    ADD CONSTRAINT fk_promo_use_ticket_order
        FOREIGN KEY (pu_to_id) REFERENCES ticket_order (to_id);
