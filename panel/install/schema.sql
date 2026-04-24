CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(80) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  full_name VARCHAR(160) NOT NULL DEFAULT '',
  role VARCHAR(40) NOT NULL DEFAULT 'admin',
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY users_username_unique (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_records (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  record_no VARCHAR(80) NOT NULL,
  plate VARCHAR(40) NOT NULL,
  customer_name VARCHAR(180) NOT NULL,
  insurance_company VARCHAR(180) NOT NULL DEFAULT '',
  repair_status VARCHAR(120) NOT NULL DEFAULT 'Belirtilmedi',
  mini_repair_has TINYINT(1) NOT NULL DEFAULT 0,
  mini_repair_part VARCHAR(180) NOT NULL DEFAULT '',
  service_entry_date DATE NOT NULL,
  service_exit_date DATE NULL,
  service_month CHAR(7) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY service_records_record_no_unique (record_no),
  KEY service_records_month_index (service_month),
  KEY service_records_plate_index (plate),
  KEY service_records_status_index (repair_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_name VARCHAR(255) NOT NULL,
  status VARCHAR(20) NOT NULL,
  imported_count INT UNSIGNED NOT NULL DEFAULT 0,
  skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
  error_summary TEXT NULL,
  duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY import_logs_created_at_index (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pending_excel_updates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  record_no VARCHAR(80) NOT NULL,
  fields_json JSON NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  error_message TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  applied_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY pending_excel_updates_status_index (status),
  KEY pending_excel_updates_record_no_index (record_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
