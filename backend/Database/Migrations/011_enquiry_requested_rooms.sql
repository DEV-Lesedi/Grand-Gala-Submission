-- Rooms a guest picks for an event, one row per room.
-- No rows means the whole villa, or a room count only.
-- Rows go with the enquiry. A room still asked for cannot be deleted.

CREATE TABLE event_enquiry_room (
    eer_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    eer_ee_id BIGINT UNSIGNED NOT NULL,
    eer_sp_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (eer_id),
    UNIQUE KEY uq_enquiry_room (eer_ee_id, eer_sp_id),
    KEY idx_enquiry_room_space (eer_sp_id),
    CONSTRAINT fk_enquiry_room_enquiry
        FOREIGN KEY (eer_ee_id) REFERENCES event_enquiry (ee_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_enquiry_room_space
        FOREIGN KEY (eer_sp_id) REFERENCES space (sp_id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
