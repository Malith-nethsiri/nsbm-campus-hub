USE nsbm_campus_hub;

ALTER TABLE timetables
    ADD COLUMN class_date DATE NULL AFTER semester,
    ADD INDEX idx_timetables_batch_date (batch, class_date);
