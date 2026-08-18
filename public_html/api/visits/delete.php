<?php
/**
 * Delete Visit API (soft-delete by default, admin-only hard delete)
 *
 * DELETE or POST /api/visits/delete.php
 * Body/Query: { id: 123, hard: 1 }
 */

require_once __DIR__ . '/../../config_loader.php';
require_once __DIR__ . '/../response.php';
require_once __DIR__ . '/../db_helper.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../auth/middleware.php';

handleCors();
requireMethod('POST', 'DELETE');

$user = requireAuth();

$input = getJsonInput();
// getParam reads GET, POST, or JSON
$id = (int) getParam('id', $input['id'] ?? 0);
$hard = (int) getParam('hard', $input['hard'] ?? 0);

if ($id <= 0) {
    Response::error('Visit ID is required', [], 400);
}

try {
    DB::beginTransaction();

    $pdo = Database::getInstance();
    $select = $pdo->prepare('SELECT * FROM visits WHERE id = :id FOR UPDATE');
    $select->execute([':id' => $id]);
    $existing = $select->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        DB::rollback();
        Response::error('Visit not found', [], 404);
    }

    $patientId = isset($existing['patient_id']) ? (int)$existing['patient_id'] : null;

    if ($hard) {
        // Only admin can hard-delete
        if (!(isset($user['role']) && $user['role'] === 'admin')) {
            DB::rollback();
            Response::error('Hard delete is restricted to admin users', [], 403);
        }

        // Perform hard delete
        DB::execute('DELETE FROM visits WHERE id = :id', [':id' => $id]);

        // Audit
        try {
            $userId = isset($user['id']) ? (int)$user['id'] : null;
            logAudit($userId, $patientId, 'hard_delete', 'visit', $id, $existing, null);
        } catch (Throwable $ae) {
            error_log('Audit log failed (hard delete): ' . $ae->getMessage());
        }

        DB::commit();
        Response::ok('Visit permanently deleted', ['id' => $id]);
    }

    // Soft-delete path
    $nowUpdate = DB::execute('UPDATE visits SET is_deleted = 1, deleted_at = NOW(), updated_at = NOW() WHERE id = :id', [':id' => $id]);

    // Fetch normalized visit after soft-delete
    $fetchSql = 'SELECT v.id, v.patient_id, v.visit_type, v.visit_date, v.chief_complaint, v.vital_signs, v.history_of_present_illness, v.physical_examination, v.diagnosis, v.notes, v.created_by, v.created_at, v.updated_at, p.full_name AS patient_name, p.phone AS patient_phone, p.register_number AS patient_register_number, u.full_name AS doctor_name FROM visits v LEFT JOIN patients p ON v.patient_id = p.id LEFT JOIN users u ON v.created_by = u.id WHERE v.id = :id LIMIT 1';
    $updated = DB::fetchOne($fetchSql, [':id' => $id]);

    if ($updated && !empty($updated['vital_signs'])) {
        $dec = json_decode($updated['vital_signs'], true);
        $updated['vital_signs'] = $dec !== null ? $dec : null;
    }

    // Audit
    try {
        $userId = isset($user['id']) ? (int)$user['id'] : null;
        logAudit($userId, $patientId, 'soft_delete', 'visit', $id, $existing, $updated);
    } catch (Throwable $ae) {
        error_log('Audit log failed (soft delete): ' . $ae->getMessage());
    }

    DB::commit();

    Response::ok('Visit soft-deleted', ['visit' => $updated]);

} catch (Throwable $e) {
    try { DB::rollback(); } catch (Throwable $_) {}
    error_log('Delete visit error: ' . $e->getMessage());
    Response::error('Failed to delete visit', [], 500);
}
