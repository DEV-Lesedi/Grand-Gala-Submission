-- A scheduled price change ends the open normal price and records which rate closed it.
-- Cancelling that change reopens it. It stays a normal price, not a season.

ALTER TABLE space_rate
    ADD COLUMN sr_closed_for BIGINT UNSIGNED NULL AFTER sr_is_active,
    ADD CONSTRAINT fk_rate_closed_for FOREIGN KEY (sr_closed_for) REFERENCES space_rate (sr_id) ON DELETE SET NULL;
