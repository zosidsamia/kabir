<?php
/**
 * Automated Backup Script for Dr. Arman Kabir Care
 * 
 * Backs up:
 * - MySQL database (SQL dump)
 * - Critical files (config, uploads, migrations)
 * 
 * Usage:
 *   php backup.php                    # Create timestamped backup
 *   php backup.php --clean            # Remove backups older than 30 days
 *   php backup.php --list             # List existing backups
 *   php backup.php --restore <date>   # Restore from a specific backup
 * 
 * Setup cron job (in cPanel, Cron Jobs):
 *   0 2 * * * cd /home/drarmank && php backup.php >> /home/drarmank/logs/backup.log 2>&1
 *   (Runs daily at 2 AM)
 * 
 * For weekly cleanup:
 *   0 3 * * 0 cd /home/drarmank && php backup.php --clean >> /home/drarmank/logs/backup.log 2>&1
 *   (Runs Sundays at 3 AM)
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Load config
require_once __DIR__ . '/public_html/config_loader.php';
require_once __DIR__ . '/public_html/config.php';

// Backup directory (outside web root for security)
$backup_dir = __DIR__ . '/server-data/backups';
$max_backup_age = 30; // days
$timestamp = date('Y-m-d_H-i-s');
$backup_name = "backup_{$timestamp}";
$backup_path = "{$backup_dir}/{$backup_name}";

// Ensure backup directory exists
if (!is_dir($backup_dir)) {
    mkdir($backup_dir, 0750, true);
    log_msg("Created backup directory: {$backup_dir}");
}

// Determine action
$action = $argc > 1 ? $argv[1] : 'backup';

switch ($action) {
    case '--clean':
        clean_old_backups($backup_dir, $max_backup_age);
        break;
    
    case '--list':
        list_backups($backup_dir);
        break;
    
    case '--restore':
        if ($argc < 3) {
            log_msg("ERROR: --restore requires a backup name. Usage: php backup.php --restore <date>", true);
            exit(1);
        }
        restore_backup($backup_dir, $argv[2]);
        break;
    
    default:
        perform_backup($backup_path);
        break;
}

exit(0);

// ============================================================================
// FUNCTIONS
// ============================================================================

/**
 * Perform a full backup (database + files)
 */
function perform_backup($backup_path) {
    global $backup_dir;
    
    log_msg("=== BACKUP START ===");
    log_msg("Backup path: {$backup_path}");
    
    // Create backup subdirectory
    if (!mkdir($backup_path, 0750, true)) {
        log_msg("ERROR: Failed to create backup directory {$backup_path}", true);
        return false;
    }
    
    $success = true;
    
    // 1. Database backup
    log_msg("Backing up database...");
    if (!backup_database("{$backup_path}/database.sql")) {
        log_msg("ERROR: Database backup failed", true);
        $success = false;
    } else {
        log_msg("✓ Database backed up");
    }
    
    // 2. Config files backup
    log_msg("Backing up configuration...");
    if (!backup_files("{$backup_path}/config", [
        'public_html/config.php',
        'public_html/env.json',
        'public_html/.htaccess',
        'migrations',
    ])) {
        log_msg("WARNING: Config backup had issues", true);
    } else {
        log_msg("✓ Configuration backed up");
    }
    
    // 3. Uploads backup (if exists and not too large)
    $uploads_dir = __DIR__ . '/public_html/uploads';
    if (is_dir($uploads_dir)) {
        $uploads_size = dir_size($uploads_dir);
        $size_mb = $uploads_size / (1024 * 1024);
        
        if ($size_mb > 500) {
            log_msg("WARNING: Uploads directory is {$size_mb}MB (>500MB limit), skipping to conserve space");
        } else {
            log_msg("Backing up uploads ({$size_mb}MB)...");
            if (!backup_files("{$backup_path}/uploads", ['public_html/uploads'])) {
                log_msg("WARNING: Uploads backup had issues", true);
            } else {
                log_msg("✓ Uploads backed up");
            }
        }
    }
    
    // 4. Create metadata file
    $metadata = [
        'backup_date' => date('Y-m-d H:i:s'),
        'backup_name' => basename($backup_path),
        'php_version' => PHP_VERSION,
        'database' => DB_NAME,
        'size_bytes' => dir_size($backup_path),
        'files_included' => [
            'database.sql',
            'config/',
            'uploads/ (if <500MB)',
        ],
    ];
    
    file_put_contents(
        "{$backup_path}/metadata.json",
        json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    log_msg("✓ Metadata file created");
    
    if ($success) {
        $size_mb = ($metadata['size_bytes'] / (1024 * 1024));
        log_msg("=== BACKUP COMPLETE === ({$size_mb}MB)");
    } else {
        log_msg("=== BACKUP COMPLETE (WITH WARNINGS) ===");
    }
    
    return $success;
}

/**
 * Backup MySQL database
 */
function backup_database($output_file) {
    $host = DB_HOST;
    $user = DB_USER;
    $pass = DB_PASS;
    $db = DB_NAME;
    
    // Build mysqldump command
    $cmd = sprintf(
        'mysqldump --single-transaction --quick --lock-tables=false -h %s -u %s -p%s %s > %s 2>&1',
        escapeshellarg($host),
        escapeshellarg($user),
        escapeshellarg($pass),
        escapeshellarg($db),
        escapeshellarg($output_file)
    );
    
    $result = shell_exec($cmd);
    
    if (!file_exists($output_file) || filesize($output_file) === 0) {
        log_msg("ERROR: mysqldump failed or produced empty file. Output: {$result}", true);
        return false;
    }
    
    return true;
}

/**
 * Backup files/directories
 */
function backup_files($backup_subdir, $sources) {
    if (!mkdir($backup_subdir, 0750, true)) {
        log_msg("ERROR: Failed to create backup subdirectory {$backup_subdir}", true);
        return false;
    }
    
    $success = true;
    
    foreach ($sources as $source) {
        $full_path = __DIR__ . '/' . $source;
        $dest = $backup_subdir . '/' . basename($source);
        
        if (!file_exists($full_path)) {
            log_msg("WARNING: Source not found: {$full_path}", true);
            continue;
        }
        
        if (is_dir($full_path)) {
            if (!copy_dir($full_path, $dest)) {
                log_msg("ERROR: Failed to copy directory {$source}", true);
                $success = false;
            }
        } elseif (is_file($full_path)) {
            if (!copy($full_path, $dest)) {
                log_msg("ERROR: Failed to copy file {$source}", true);
                $success = false;
            }
        }
    }
    
    return $success;
}

/**
 * Copy directory recursively
 */
function copy_dir($src, $dst) {
    if (!is_dir($dst)) {
        mkdir($dst, 0750, true);
    }
    
    $dir = opendir($src);
    if (!$dir) return false;
    
    $success = true;
    while (($file = readdir($dir)) !== false) {
        if ($file === '.' || $file === '..') continue;
        
        $src_path = $src . '/' . $file;
        $dst_path = $dst . '/' . $file;
        
        if (is_dir($src_path)) {
            if (!copy_dir($src_path, $dst_path)) {
                $success = false;
            }
        } else {
            if (!copy($src_path, $dst_path)) {
                $success = false;
            }
        }
    }
    
    closedir($dir);
    return $success;
}

/**
 * Get directory size in bytes
 */
function dir_size($dir) {
    $size = 0;
    
    if (!is_dir($dir)) {
        return filesize($dir);
    }
    
    foreach (scandir($dir) as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = $dir . '/' . $file;
        
        if (is_dir($path)) {
            $size += dir_size($path);
        } else {
            $size += filesize($path);
        }
    }
    
    return $size;
}

/**
 * Clean backups older than max_age
 */
function clean_old_backups($backup_dir, $max_age) {
    log_msg("=== CLEANUP START ===");
    log_msg("Removing backups older than {$max_age} days");
    
    $cutoff = time() - ($max_age * 86400);
    $removed_count = 0;
    $removed_size = 0;
    
    foreach (scandir($backup_dir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        
        $path = $backup_dir . '/' . $entry;
        if (!is_dir($path)) continue;
        
        $mtime = filemtime($path);
        if ($mtime < $cutoff) {
            $size = dir_size($path);
            if (remove_dir($path)) {
                $removed_count++;
                $removed_size += $size;
                log_msg("✓ Removed: {$entry} ({$size} bytes)");
            } else {
                log_msg("ERROR: Failed to remove {$entry}", true);
            }
        }
    }
    
    $size_mb = $removed_size / (1024 * 1024);
    log_msg("=== CLEANUP COMPLETE === (Removed {$removed_count} backups, freed {$size_mb}MB)");
}

/**
 * Remove directory recursively
 */
function remove_dir($dir) {
    if (!is_dir($dir)) return false;
    
    foreach (scandir($dir) as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            if (!remove_dir($path)) return false;
        } else {
            if (!unlink($path)) return false;
        }
    }
    
    return rmdir($dir);
}

/**
 * List existing backups
 */
function list_backups($backup_dir) {
    log_msg("=== AVAILABLE BACKUPS ===");
    
    $backups = [];
    foreach (scandir($backup_dir, SCANDIR_SORT_DESCENDING) as $entry) {
        if ($entry === '.' || $entry === '..' || !is_dir($backup_dir . '/' . $entry)) continue;
        
        $path = $backup_dir . '/' . $entry;
        $size = dir_size($path);
        $mtime = filemtime($path);
        
        $metadata_file = $path . '/metadata.json';
        $metadata = file_exists($metadata_file) 
            ? json_decode(file_get_contents($metadata_file), true) 
            : null;
        
        $backups[] = [
            'name' => $entry,
            'date' => date('Y-m-d H:i:s', $mtime),
            'size_mb' => number_format($size / (1024 * 1024), 2),
            'backup_date' => $metadata['backup_date'] ?? 'unknown',
        ];
    }
    
    if (empty($backups)) {
        log_msg("No backups found");
        return;
    }
    
    foreach ($backups as $backup) {
        log_msg(sprintf(
            "%s | %s | %s MB | Created: %s",
            $backup['name'],
            $backup['date'],
            $backup['size_mb'],
            $backup['backup_date']
        ));
    }
    
    log_msg("=== END BACKUPS ===");
}

/**
 * Restore from a backup
 */
function restore_backup($backup_dir, $backup_name) {
    log_msg("=== RESTORE START ===");
    
    $backup_path = $backup_dir . '/' . $backup_name;
    
    if (!is_dir($backup_path)) {
        log_msg("ERROR: Backup not found: {$backup_name}", true);
        return false;
    }
    
    $db_file = $backup_path . '/database.sql';
    if (!file_exists($db_file)) {
        log_msg("ERROR: Database dump not found in backup", true);
        return false;
    }
    
    log_msg("WARNING: This will overwrite your current database!");
    log_msg("Backup name: {$backup_name}");
    log_msg("Proceed? (type 'yes' to confirm): ");
    
    $handle = fopen('php://stdin', 'r');
    $confirmation = trim(fgets($handle));
    fclose($handle);
    
    if ($confirmation !== 'yes') {
        log_msg("Restore cancelled");
        return false;
    }
    
    log_msg("Restoring database...");
    
    $cmd = sprintf(
        'mysql -h %s -u %s -p%s %s < %s 2>&1',
        escapeshellarg(DB_HOST),
        escapeshellarg(DB_USER),
        escapeshellarg(DB_PASS),
        escapeshellarg(DB_NAME),
        escapeshellarg($db_file)
    );
    
    $result = shell_exec($cmd);
    
    log_msg("Database restored");
    log_msg("=== RESTORE COMPLETE ===");
    
    return true;
}

/**
 * Log message with timestamp
 */
function log_msg($message, $is_error = false) {
    $timestamp = date('Y-m-d H:i:s');
    $prefix = $is_error ? '[ERROR]' : '[INFO]';
    $output = "{$timestamp} {$prefix} {$message}";
    
    echo $output . PHP_EOL;
    error_log($output);
}
?>
