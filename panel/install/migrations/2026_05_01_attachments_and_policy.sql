-- 2026-05-01: Poliçe tarihleri + ek belge tablosu

ALTER TABLE service_records
  ADD COLUMN policy_start_date DATE NULL AFTER service_exit_date,
  ADD COLUMN policy_end_date DATE NULL AFTER policy_start_date,
  ADD COLUMN policy_reminder_sent_at DATETIME NULL AFTER policy_end_date,
  ADD INDEX service_records_policy_end_index (policy_end_date);

CREATE TABLE IF NOT EXISTS service_attachments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  record_id INT UNSIGNED NOT NULL,
  category ENUM('avukat','ruhsat','kaza','police','fotograf','diger') NOT NULL DEFAULT 'diger',
  original_name VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  file_size INT UNSIGNED NOT NULL,
  file_data MEDIUMBLOB NOT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  uploaded_by INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY service_attachments_record_index (record_id),
  KEY service_attachments_category_index (category),
  CONSTRAINT service_attachments_record_fk FOREIGN KEY (record_id) REFERENCES service_records(id) ON DELETE CASCADE,
  CONSTRAINT service_attachments_user_fk FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
