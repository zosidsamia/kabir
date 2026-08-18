<?php
/**
 * Update Visit API (modernized)
 *
 * PUT|PATCH|POST /api/visits/update.php
 * Body: JSON with camelCase fields (id required)
 */

require_once __DIR__ . '/../../config_loader.php';
require_once __DIR__ . '/../response.php';
require_once __DIR__ . '/../db_helper.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../auth/middleware.php';

handleCors();
requireMethod('POST', 'PUT', 'PATCH');

$user = requireAuth();
$input = getJsonInput();

$id = isset($input['id']) ? (int)$input['id'] : 0;
if ($id <= 0) {
    Response::error('Visit ID is required', [], 400);
}

// Whitelist updatable fields (camelCase => db_column)
$fieldsMap = [
    'visitDate' => 'visit_date',
    'visitType' => 'visit_type',
    'chiefComplaint' => 'chief_complaint',
    'vitalSigns' => 'vital_signs',
    'historyOfPresentIllness' => 'history_of_present_illness',
    'physicalExamination' => 'physical_examination',
    'diagnosis' => 'diagnosis',
    'notes' => 'notes',
];

$updateData = [];
foreach ($fieldsMap as $inKey => $dbCol) {
    if (array_key_exists($inKey, $input)) {
        $updateData[$dbCol] = $input[$inKey];
    }
}

try {
    DB::beginTransaction();

    // Lock the row for update
    $pdo = Database::getInstance();
    $selectStmt = $pdo->prepare('SELECT * FROM visits WHERE id = :id FOR UPDATE');
    $selectStmt->execute([':id' => $id]);
    $existing = $selectStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        DB::rollback();
        Response::error('Visit not found', [], 404);
    }

    if (empty($updateData)) {
        DB::rollback();
        Response::error('No updatable fields provided', [], 400);
    }

    $params = [':id' => $id];
    $setParts = [];

    foreach ($updateData as $col => $val) {
        if ($col === 'vital_signs') {
            // Expect array or JSON-able value; normalize to JSON string or NULL
            if ($val === null || $val === '') {
                $params[':' . $col] = null;
            } elseif (is_array($val) || is_object($val)) {
                $encoded = json_encode($val);
                $params[':' . $col] = $encoded;
            } elseif (is_string($val)) {
                // try decode then re-encode to ensure valid JSON
                $decoded = json_decode($val, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $params[':' . $col] = json_encode($decoded);
                } else {
                    // treat as raw string
                    $params[':' . $col] = $val;
                }
            } else {
                $params[':' . $col] = json_encode($val);
            }
        } else {
            $params[':' . $col] = $val;
        }
        $setParts[] = "{$col} = :{$col}";
    }

    $setParts[] = 'updated_at = NOW()';
    $sql = 'UPDATE visits SET ' . implode(', ', $setParts) . ' WHERE id = :id';

    DB::execute($sql, $params);

    // Fetch updated normalized record (patient + doctor summary)
    $fetchSql = "SELECT v.id, v.patient_id, v.visit_type, v.visit_date, v.chief_complaint, v.vital_signs, v.history_of_present_illness, v.physical_examination, v.diagnosis, v.notes, v.created_by, v.created_at, v.updated_at, p.full_name AS patient_name, p.phone AS patient_phone, p.register_number AS patient_register_number, u.full_name AS doctor_name FROM visits v LEFT JOIN patients p ON v.patient_id = p.id LEFT JOIN users u ON v.created_by = u.id WHERE v.id = :id LIMIT 1";
    $updated = DB::fetchOne($fetchSql, [':id' => $id]);

    // Decode vital_signs safely
    if ($updated && !empty($updated['vital_signs'])) {
        $dec = json_decode($updated['vital_signs'], true);
        $updated['vital_signs'] = $dec !== null ? $dec : null;
    }

    // Commit
    DB::commit();

    // Audit
    try {
        $userId = isset($user['id']) ? (int)$user['id'] : null;
        logAudit($userId, isset($existing['patient_id']) ? (int)$existing['patient_id'] : null, 'update', 'visit', $id, $existing, $updated);
    } catch (Throwable $ae) {
        error_log('Audit log failed: ' . $ae->getMessage());
    }

    Response::ok('Visit updated', ['visit' => $updated]);

} catch (Throwable $e) {
    DB::rollback();
    error_log('Update visit error: ' . $e->getMessage());
    Response::error('Failed to update visit', [], 500);
}
