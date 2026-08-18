-- migrations/001_add_visits_soft_delete.sql
-- Purpose: Add soft-delete columns to visits so records are not permanently removed.
-- Run this in phpMyAdmin on the target database after taking a backup.

ALTER TABLE `visits`
  ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `deleted_at` DATETIME NULL,
  ADD INDEX `idx_visits_is_deleted` (`is_deleted`);

-- Rollback (run only if you are sure):
-- ALTER TABLE `visits`
--   DROP INDEX `idx_visits_is_deleted`,
--   DROP COLUMN `deleted_at`,
--   DROP COLUMN `is_deleted`;
