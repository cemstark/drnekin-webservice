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
  insurance_type ENUM('kasko','trafik','filo','ucretli') NOT NULL DEFAULT 'kasko',
  repair_status VARCHAR(120) NOT NULL DEFAULT 'Belirtilmedi',
  mini_repair_has TINYINT(1) NOT NULL DEFAULT 0,
  mini_repair_part VARCHAR(180) NOT NULL DEFAULT '',
  service_entry_date DATE NOT NULL,
  service_exit_date DATE NULL,
  policy_start_date DATE NULL,
  policy_end_date DATE NULL,
  policy_reminder_sent_at DATETIME NULL,
  service_month CHAR(7) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY service_records_record_no_unique (record_no),
  UNIQUE KEY service_records_vehicle_entry_customer_unique (plate, service_entry_date, customer_name),
  KEY service_records_month_index (service_month),
  KEY service_records_plate_index (plate),
  KEY service_records_status_index (repair_status),
  KEY service_records_insurance_type_index (insurance_type),
  KEY service_records_policy_end_index (policy_end_date)
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

CREATE TABLE IF NOT EXISTS service_attachments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  record_id INT UNSIGNED NOT NULL,
  category ENUM('avukat','ruhsat','kaza','police','fotograf','diger') NOT NULL DEFAULT 'diger',
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  file_size INT UNSIGNED NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  uploaded_by INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY service_attachments_record_index (record_id),
  KEY service_attachments_category_index (category),
  CONSTRAINT service_attachments_record_fk FOREIGN KEY (record_id) REFERENCES service_records(id) ON DELETE CASCADE,
  CONSTRAINT service_attachments_user_fk FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
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

CREATE TABLE IF NOT EXISTS cron_runs (
  job_key VARCHAR(60) NOT NULL,
  last_run_date DATE NOT NULL,
  last_run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_status VARCHAR(20) NOT NULL DEFAULT 'ok',
  last_payload TEXT NULL,
  PRIMARY KEY (job_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
