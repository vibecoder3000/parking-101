CREATE DATABASE IF NOT EXISTS parking_101 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE parking_101;

CREATE TABLE IF NOT EXISTS members (
  name VARCHAR(80) NOT NULL PRIMARY KEY,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

INSERT INTO members (name) VALUES
  ('Nadia'), ('Laurence'), ('Lara'), ('Jil'), ('Erik')
ON DUPLICATE KEY UPDATE active = 1;

CREATE TABLE IF NOT EXISTS monthly_plans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  member_name VARCHAR(80) NOT NULL,
  week_start DATE NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_monthly_member_week (member_name, week_start),
  KEY idx_monthly_week (week_start),
  CONSTRAINT fk_monthly_member FOREIGN KEY (member_name) REFERENCES members(name) ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS weekly_registrations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  week_start DATE NOT NULL,
  member_name VARCHAR(80) NOT NULL,
  registered_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_registration (week_start, member_name),
  KEY idx_registration_week (week_start),
  CONSTRAINT fk_registration_member FOREIGN KEY (member_name) REFERENCES members(name) ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS weekly_allocations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  week_start DATE NOT NULL,
  slot_number TINYINT UNSIGNED NOT NULL,
  member_name VARCHAR(80) NOT NULL,
  allocated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_allocation_slot (week_start, slot_number),
  UNIQUE KEY uq_allocation_member (week_start, member_name),
  KEY idx_allocation_member_date (member_name, week_start),
  CONSTRAINT fk_allocation_member FOREIGN KEY (member_name) REFERENCES members(name) ON UPDATE CASCADE,
  CONSTRAINT chk_allocation_slot CHECK (slot_number IN (1, 2))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fob_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  week_start DATE NOT NULL,
  slot_number TINYINT UNSIGNED NOT NULL,
  member_name VARCHAR(80) NOT NULL,
  status ENUM('handed_over', 'returned', 'lost', 'damaged') NOT NULL DEFAULT 'handed_over',
  returned_at DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fob_week_slot (week_start, slot_number),
  KEY idx_fob_member (member_name),
  CONSTRAINT fk_fob_member FOREIGN KEY (member_name) REFERENCES members(name) ON UPDATE CASCADE,
  CONSTRAINT chk_fob_slot CHECK (slot_number IN (1, 2))
) ENGINE=InnoDB;

-- Older databases created the member as 'Jill'. The rename used to sit directly after the
-- INSERT above, where 'Jil' already exists, so it hit the primary key with a duplicate-entry
-- error and aborted the rest of the script on exactly the databases it was meant to repair.
-- It runs last instead, once every table exists, and merges rather than collides:
-- UPDATE IGNORE renames what it can (ON UPDATE CASCADE carries the child rows along), the
-- child updates catch a database that somehow holds both names, and the deletes clear the
-- rows IGNORE skipped because 'Jil' already had that exact week. Re-running is harmless.
UPDATE IGNORE members SET name = 'Jil' WHERE name = 'Jill';
UPDATE IGNORE monthly_plans        SET member_name = 'Jil' WHERE member_name = 'Jill';
UPDATE IGNORE weekly_registrations SET member_name = 'Jil' WHERE member_name = 'Jill';
UPDATE IGNORE weekly_allocations   SET member_name = 'Jil' WHERE member_name = 'Jill';
UPDATE IGNORE fob_log              SET member_name = 'Jil' WHERE member_name = 'Jill';
DELETE FROM monthly_plans        WHERE member_name = 'Jill';
DELETE FROM weekly_registrations WHERE member_name = 'Jill';
DELETE FROM weekly_allocations   WHERE member_name = 'Jill';
DELETE FROM fob_log              WHERE member_name = 'Jill';
DELETE FROM members WHERE name = 'Jill';
