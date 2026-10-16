-- One counter per reference kind. next() locks it and increments.
-- Booking and ticket counters restart each year. Quotations do not.

CREATE TABLE reference_sequence (
    rs_kind VARCHAR(32) NOT NULL,
    rs_year SMALLINT UNSIGNED NOT NULL,
    rs_next INT UNSIGNED NOT NULL,
    PRIMARY KEY (rs_kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO reference_sequence (rs_kind, rs_year, rs_next) VALUES
    ('booking', 2026, 1),
    ('quotation', 2026, 1),
    ('ticket_order', 2026, 1);
