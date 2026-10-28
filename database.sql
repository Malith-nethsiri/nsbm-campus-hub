CREATE DATABASE IF NOT EXISTS nsbm_campus_hub
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE nsbm_campus_hub;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'organizer', 'student') NOT NULL DEFAULT 'student',
    batch VARCHAR(80) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS clubs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    created_by BIGINT UNSIGNED NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    CONSTRAINT fk_clubs_created_by FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS timetables (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    faculty VARCHAR(120) NOT NULL,
    degree_program VARCHAR(160) NOT NULL,
    year TINYINT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    class_date DATE NULL,
    batch VARCHAR(80) NOT NULL,
    day_of_week ENUM(
        'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'
    ) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    module_name VARCHAR(160) NOT NULL,
    CONSTRAINT chk_timetables_year CHECK (year BETWEEN 1 AND 8),
    CONSTRAINT chk_timetables_semester CHECK (semester BETWEEN 1 AND 3),
    CONSTRAINT chk_timetables_time CHECK (end_time > start_time),
    INDEX idx_timetables_batch_day (batch, day_of_week),
    INDEX idx_timetables_batch_date (batch, class_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS facilities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    facility_name VARCHAR(160) NOT NULL,
    capacity INT UNSIGNED NOT NULL,
    location VARCHAR(255) NOT NULL,
    CONSTRAINT chk_facilities_capacity CHECK (capacity > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    organizer_id BIGINT UNSIGNED NOT NULL,
    event_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    facility_id BIGINT UNSIGNED NULL,
    status ENUM('draft', 'published', 'cancelled', 'completed') NOT NULL DEFAULT 'draft',
    CONSTRAINT fk_events_organizer FOREIGN KEY (organizer_id)
        REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_events_facility FOREIGN KEY (facility_id)
        REFERENCES facilities (id) ON DELETE SET NULL,
    CONSTRAINT chk_events_time CHECK (end_time > start_time),
    INDEX idx_events_date_status (event_date, status),
    INDEX idx_events_organizer (organizer_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS event_target_batches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id BIGINT UNSIGNED NOT NULL,
    batch_name VARCHAR(80) NOT NULL,
    CONSTRAINT fk_event_target_batches_event FOREIGN KEY (event_id)
        REFERENCES events (id) ON DELETE CASCADE,
    UNIQUE KEY uq_event_target_batch (event_id, batch_name)
) ENGINE=InnoDB;

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
