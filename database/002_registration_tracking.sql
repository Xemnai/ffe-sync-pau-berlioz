ALTER TABLE pbe_tournament_sources
    ADD COLUMN registration_status
        ENUM('unknown', 'unavailable', 'available', 'failed')
        NOT NULL DEFAULT 'unknown'
        AFTER last_detail_sync_at,

    ADD COLUMN last_registration_sync_at DATETIME NULL
        AFTER registration_status,

    ADD COLUMN registration_error VARCHAR(255) NULL
        AFTER last_registration_sync_at;


INSERT INTO pbe_schema_migrations (filename)
VALUES ('002_registration_tracking.sql')
ON DUPLICATE KEY UPDATE filename = VALUES(filename);