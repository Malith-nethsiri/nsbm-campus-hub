USE nsbm_campus_hub;

CREATE TABLE IF NOT EXISTS event_registrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_event_registrations_event FOREIGN KEY (event_id)
        REFERENCES events (id) ON DELETE CASCADE,
    CONSTRAINT fk_event_registrations_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    UNIQUE KEY uq_event_registration (event_id, user_id),
    INDEX idx_event_registrations_user (user_id)
) ENGINE=InnoDB;
