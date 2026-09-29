-- Partial failures ("some pages have real errors") are no longer reported as "down".
ALTER TABLE check_runs MODIFY status ENUM('ok','warning','degraded','down') NOT NULL;

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

-- Move untouched default configs to the new, slow-site-friendly defaults.
UPDATE checks SET config = '{"expected_status":200,"timeout_seconds":30,"warn_ms":3000,"critical_ms":10000}'
 WHERE type = 'http' AND JSON_EXTRACT(config, '$.timeout_seconds') = 10 AND JSON_EXTRACT(config, '$.warn_ms') = 2000
   AND JSON_EXTRACT(config, '$.critical_ms') = 6000 AND JSON_EXTRACT(config, '$.required_keyword') IS NULL;
UPDATE checks SET config = '{"max_pages":100,"timeout_seconds":30,"slow_ms":8000}'
 WHERE type = 'crawl' AND JSON_EXTRACT(config, '$.max_pages') = 100 AND JSON_EXTRACT(config, '$.timeout_seconds') = 15
   AND JSON_EXTRACT(config, '$.slow_ms') = 5000 AND JSON_EXTRACT(config, '$.forbidden_keywords') IS NULL;
