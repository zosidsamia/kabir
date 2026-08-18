<?php
/**
 * Get Visit API (modernized)
 *
 * GET /api/visits/get.php?id=123
 * Headers: Authorization: Bearer <token>
 *
 * Returns a single visit record with selected patient and doctor fields. All
 * reads come from the central MySQL (phpMyAdmin / cPanel). No local or canister
 * storage is used.
 */

require_once __DIR__ . '/../../config_loader.php';
require_once __DIR__ . '/../response.php';
require_once __DIR__ . '/../db_helper.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../auth/middleware.php';

handleCors();
requireMethod('GET');

$user = requireAuth();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    Response::error('Missing or invalid visit id', [], 400);
}

try {
    $sql = 'SELECT v.id, v.patient_id, v.visit_type, v.visit_date, v.chief_complaint, v.vital_signs, v.history_of_present_illness, v.physical_examination, v.diagnosis, v.notes, v.created_by, v.created_at, v.updated_at, '
         . 'p.full_name AS patient_name, p.phone AS patient_phone, p.register_number AS patient_register_number, '
         . 'u.full_name AS doctor_name '
         . 'FROM visits v '
         . 'LEFT JOIN patients p ON v.patient_id = p.id '
         . 'LEFT JOIN users u ON v.created_by = u.id '
         . 'WHERE v.id = :id AND (v.is_deleted = 0 OR v.is_deleted IS NULL) LIMIT 1';

    $visit = DB::fetchOne($sql, [':id' => $id]);

    if (!$visit) {
        Response::error('Visit not found', [], 404);
    }

    // Authorization: allow if admin, if user has view_all_patients permission, or if the session is a patient matching the visit
    $allowed = false;
    // admin check: only admin role
    if (isset($user['role']) && $user['role'] === 'admin') {
        $allowed = true;
    }
    // role-based permission
    if (!$allowed && hasPermission($user, 'view_all_patients')) {
        $allowed = true;
    }
    // patient session accessing own visit
    if (!$allowed && isset($user['session_type']) && $user['session_type'] === 'patient') {
        if (isset($user['patient_id']) && isset($visit['patient_id']) && (int)$user['patient_id'] === (int)$visit['patient_id']) {
            $allowed = true;
        }
    }

    if (!$allowed) {
        Response::error('Access denied. You do not have permission to view this visit.', [], 403);
    }

    // Normalize output (patient and doctor summary only)
    $out = [];
    $out['id'] = isset($visit['id']) ? (int)$visit['id'] : null;
    $out['patient_id'] = isset($visit['patient_id']) ? (int)$visit['patient_id'] : null;
    $out['patient_name'] = $visit['patient_name'] ?? null;
    $out['patient_phone'] = $visit['patient_phone'] ?? null;
    $out['patient_register_number'] = $visit['patient_register_number'] ?? null;
    $out['visit_type'] = $visit['visit_type'] ?? null;
    $out['visit_date'] = $visit['visit_date'] ?? null;
    $out['chief_complaint'] = $visit['chief_complaint'] ?? null;

    // Decode vital_signs JSON safely
    $out['vital_signs'] = null;
    if (!empty($visit['vital_signs'])) {
        $decoded = json_decode($visit['vital_signs'], true);
        $out['vital_signs'] = $decoded !== null ? $decoded : null;
    }

    $out['history_of_present_illness'] = $visit['history_of_present_illness'] ?? null;
    $out['physical_examination'] = $visit['physical_examination'] ?? null;
    $out['diagnosis'] = $visit['diagnosis'] ?? null;
    $out['notes'] = $visit['notes'] ?? null;
    $out['created_by'] = isset($visit['created_by']) ? (int)$visit['created_by'] : null;
    $out['doctor_name'] = $visit['doctor_name'] ?? null;
    $out['created_at'] = $visit['created_at'] ?? null;
    $out['updated_at'] = $visit['updated_at'] ?? null;

    // Audit: record that this user viewed the visit
    try {
        $userId = isset($user['id']) ? (int)$user['id'] : null;
        logAudit($userId, $out['patient_id'], 'view', 'visit', $out['id']);
    } catch (Throwable $ae) {
        // logAudit swallows errors, but be safe here
        error_log('Audit log (view visit) failed: ' . $ae->getMessage());
    }

    Response::ok('Visit retrieved', ['visit' => $out]);

} catch (\Throwable $e) {
    error_log('Get visit error: ' . $e->getMessage());
    Response::error('Failed to fetch visit', [], 500);
}
