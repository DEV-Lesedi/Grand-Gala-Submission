-- A villa room is a space of type room, pointing at its villa.
-- A villa booking or blackout blocks its rooms; a room booking blocks the villa.

ALTER TABLE space
    MODIFY COLUMN sp_type ENUM('villa', 'room', 'garden', 'marquee', 'pangola', 'pool', 'hall', 'other') NOT NULL DEFAULT 'other',
    ADD COLUMN sp_parent_id INT UNSIGNED NULL AFTER sp_v_id,
    ADD CONSTRAINT fk_space_parent FOREIGN KEY (sp_parent_id) REFERENCES space (sp_id) ON UPDATE CASCADE;
