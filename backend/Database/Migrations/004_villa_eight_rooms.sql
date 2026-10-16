-- Villa is 8 rooms that sleep 10. Fixes rooms seeded before that change.
-- An empty database matches nothing; the seed inserts the right values.

UPDATE space SET sp_capacity = 1 WHERE sp_name = 'Mountain Room' AND sp_type = 'room';
UPDATE space SET sp_capacity = 1 WHERE sp_name = 'Pool Room' AND sp_type = 'room';
UPDATE space SET sp_capacity = 2 WHERE sp_name = 'Family Suite' AND sp_type = 'room';

UPDATE stay_booking sb
    JOIN booking b ON b.b_id = sb.sb_b_id
    SET sb.sb_guests = 2
    WHERE b.b_reference IN ('GGG-2026-0391', 'GGG-2026-0423', 'GGG-2026-0438');
