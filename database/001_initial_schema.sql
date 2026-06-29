CREATE TABLE IF NOT EXISTS pbe_schema_migrations (
    filename VARCHAR(190) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS pbe_sync_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    trigger_source ENUM('cron', 'manual') NOT NULL,
    status ENUM('queued', 'running', 'succeeded', 'failed', 'skipped')
        NOT NULL DEFAULT 'queued',
    request_token CHAR(36) NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    tournaments_found INT UNSIGNED NOT NULL DEFAULT 0,
    tournaments_created INT UNSIGNED NOT NULL DEFAULT 0,
    tournaments_updated INT UNSIGNED NOT NULL DEFAULT 0,
    tournaments_ignored INT UNSIGNED NOT NULL DEFAULT 0,
    club_entries_updated INT UNSIGNED NOT NULL DEFAULT 0,
    wordpress_events_pushed INT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_pbe_sync_runs_request_token (request_token),
    KEY idx_pbe_sync_runs_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS pbe_event_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_key CHAR(64) NOT NULL,
    title VARCHAR(255) NOT NULL,
    normalized_title VARCHAR(255) NOT NULL,
    department VARCHAR(3) NULL,
    city VARCHAR(120) NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    cadence_kind ENUM('blitz', 'rapide', 'lent', 'inconnu')
        NOT NULL DEFAULT 'inconnu',
    generated_description MEDIUMTEXT NULL,
    distance_km DECIMAL(6,1) NULL,
    wp_event_id BIGINT UNSIGNED NULL,
    payload_hash CHAR(64) NULL,
    last_pushed_at DATETIME NULL,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_pbe_event_groups_group_key (group_key),
    UNIQUE KEY uq_pbe_event_groups_wp_event_id (wp_event_id),
    KEY idx_pbe_event_groups_dates (start_date, end_date),
    KEY idx_pbe_event_groups_cadence (cadence_kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS pbe_tournament_sources (
    ffe_ref INT UNSIGNED NOT NULL,
    group_id BIGINT UNSIGNED NULL,
    department VARCHAR(3) NOT NULL,
    city VARCHAR(120) NOT NULL,
    title VARCHAR(255) NOT NULL,
    normalized_title VARCHAR(255) NOT NULL,
    ffe_url VARCHAR(512) NOT NULL,
    results_url VARCHAR(512) NULL,

    start_date DATE NULL,
    end_date DATE NULL,
    rounds SMALLINT UNSIGNED NULL,
    cadence VARCHAR(255) NULL,
    cadence_kind ENUM('blitz', 'rapide', 'lent', 'inconnu')
        NOT NULL DEFAULT 'inconnu',

    venue VARCHAR(255) NULL,
    address VARCHAR(600) NULL,
    organizer VARCHAR(255) NULL,
    arbiter VARCHAR(255) NULL,
    contact MEDIUMTEXT NULL,

    fee_senior VARCHAR(255) NULL,
    fee_youth VARCHAR(255) NULL,
    announcement MEDIUMTEXT NULL,
    registration_url VARCHAR(512) NULL,

    is_upcoming TINYINT(1) NOT NULL DEFAULT 1,
    is_excluded TINYINT(1) NOT NULL DEFAULT 0,
    exclusion_reason VARCHAR(255) NULL,

    details_hash CHAR(64) NULL,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_detail_sync_at DATETIME NULL,

    PRIMARY KEY (ffe_ref),
    KEY idx_pbe_tournament_sources_group_id (group_id),
    KEY idx_pbe_tournament_sources_upcoming (
        is_upcoming,
        is_excluded,
        start_date
    ),
    KEY idx_pbe_tournament_sources_department (department),
    KEY idx_pbe_tournament_sources_title (normalized_title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS pbe_event_group_sources (
    group_id BIGINT UNSIGNED NOT NULL,
    ffe_ref INT UNSIGNED NOT NULL,
    source_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,

    PRIMARY KEY (group_id, ffe_ref),
    UNIQUE KEY uq_pbe_event_group_sources_ffe_ref (ffe_ref),
    KEY idx_pbe_event_group_sources_group_order (group_id, source_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS pbe_club_registrations (
    ffe_ref INT UNSIGNED NOT NULL,
    display_order SMALLINT UNSIGNED NOT NULL,
    player_name VARCHAR(255) NOT NULL,
    elo INT UNSIGNED NULL,
    club_name VARCHAR(255) NOT NULL,
    detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (ffe_ref, display_order),
    KEY idx_pbe_club_registrations_source (ffe_ref),
    KEY idx_pbe_club_registrations_player (player_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS pbe_location_cache (
    address_hash CHAR(64) NOT NULL,
    original_address VARCHAR(600) NOT NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    route_distance_km DECIMAL(6,1) NULL,
    route_duration_minutes SMALLINT UNSIGNED NULL,
    provider VARCHAR(100) NULL,
    checked_at DATETIME NULL,

    PRIMARY KEY (address_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS pbe_api_nonces (
    nonce CHAR(64) NOT NULL,
    direction ENUM('wp_to_service', 'service_to_wp') NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (nonce),
    KEY idx_pbe_api_nonces_expiration (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


INSERT INTO pbe_schema_migrations (filename)
VALUES ('001_initial_schema.sql')
ON DUPLICATE KEY UPDATE filename = VALUES(filename);