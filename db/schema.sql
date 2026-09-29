-- BatSignal database schema (MVP - Phase 1)
CREATE DATABASE IF NOT EXISTS batsignal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE batsignal;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(64) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    email VARCHAR(190) NULL,
    lang VARCHAR(5) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    base_url VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- One site can have several checks: home page, login, an API endpoint, its SSL cert, etc.
CREATE TABLE IF NOT EXISTS checks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    site_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    type ENUM('http','ssl','crawl') NOT NULL,
    url VARCHAR(255) NOT NULL,
    config JSON NULL,                 -- per-type options (expected_status, keywords, thresholds...)
    frequency_minutes INT NOT NULL DEFAULT 5,
    failure_threshold INT NOT NULL DEFAULT 2,   -- consecutive failures before opening an incident
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_run_at DATETIME NULL,
    consecutive_failures INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS check_runs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    check_id INT NOT NULL,
    status ENUM('ok','warning','degraded','down') NOT NULL,
    http_status INT NULL,
    response_time_ms INT NULL,
    reason_code VARCHAR(64) NULL,     -- e.g. ssl_expiring_soon, forbidden_keyword_found
    detail TEXT NULL,
    screenshot_path VARCHAR(255) NULL, -- reserved for Phase 2 (browser worker)
    run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (check_id) REFERENCES checks(id) ON DELETE CASCADE,
    INDEX (check_id, run_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS incidents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    check_id INT NOT NULL,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    reason_code VARCHAR(64) NULL,
    last_error TEXT NULL,
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at DATETIME NULL,
    FOREIGN KEY (check_id) REFERENCES checks(id) ON DELETE CASCADE,
    INDEX (check_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    incident_id INT NOT NULL,
    type ENUM('opened','resolved') NOT NULL,
    email_to VARCHAR(255) NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    success TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (incident_id) REFERENCES incidents(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(64) PRIMARY KEY,
    setting_value TEXT NULL
) ENGINE=InnoDB;

-- Default settings placeholders (edit from the panel's Settings page)
INSERT INTO settings (setting_key, setting_value) VALUES
    ('smtp_host', ''),
    ('smtp_port', '587'),
    ('smtp_secure', 'tls'),
    ('smtp_username', ''),
    ('smtp_password', ''),
    ('smtp_from_email', ''),
    ('smtp_from_name', 'BatSignal'),
    ('notify_email', ''),
    ('mail_lang', 'ca')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
-- Persistent crawl state, so big sites are covered in turns across runs.
CREATE TABLE IF NOT EXISTS crawl_pages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    check_id INT NOT NULL,
    url_hash CHAR(40) NOT NULL,
    url TEXT NOT NULL,
    found_on VARCHAR(1024) NULL,          -- path of the page that links here (NULL = sitemap/start)
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_checked_at DATETIME NULL,
    last_category ENUM('ok','error','broken','slow') NULL,
    last_problem TEXT NULL,               -- Msg JSON
    UNIQUE KEY uq_check_url (check_id, url_hash),
    KEY idx_check_checked (check_id, last_checked_at),
    FOREIGN KEY (check_id) REFERENCES checks(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS site_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    color VARCHAR(16) NOT NULL DEFAULT 'yellow',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS site_group_members (
    group_id INT NOT NULL,
    site_id INT NOT NULL,
    PRIMARY KEY (group_id, site_id),
    FOREIGN KEY (group_id) REFERENCES site_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- Migrations already folded into this schema (bin/migrate.php applies only newer ones).
CREATE TABLE IF NOT EXISTS schema_migrations (
    name VARCHAR(190) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
INSERT IGNORE INTO schema_migrations (name) VALUES ('003_groups_crawl_state.sql');
