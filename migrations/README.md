# Migrations

This folder contains SQL migration files intended to be run manually via phpMyAdmin (or another MySQL client).

Important: Always BACKUP your database before running migrations. Test on a staging copy first.

Files:

- 001_add_visits_soft_delete.sql
  - Adds `is_deleted` (TINYINT) and `deleted_at` (DATETIME) to the `visits` table and an index on `is_deleted`.
  - Purpose: enable soft-delete semantics so clinical data is not lost.

- 002_create_audit_logs.sql
  - Creates the `audit_logs` table used by the project's `logAudit()` helper to persist audit events.

How to run (phpMyAdmin):
1. Log in to phpMyAdmin and select the target database.
2. Click the SQL tab.
3. Paste the contents of `001_add_visits_soft_delete.sql` and run.
4. Verify the `visits` table structure (columns `is_deleted` and `deleted_at` present).
5. Paste the contents of `002_create_audit_logs.sql` and run.
6. Verify the `audit_logs` table exists.

Rollback:
- Each SQL file contains commented rollback statements. Use them only if you are sure and after taking a fresh backup.

Notes:
- These migrations are provided as manual SQL files so you can run them through cPanel/phpMyAdmin.
- I will not execute these migrations on your behalf. After you run them, tell me and I will update `public_html/api/visits/list.php` to exclude deleted rows and harden authorization and search escaping.
