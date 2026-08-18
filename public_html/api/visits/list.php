<?php
/**
 * List Visits API (modernized)
 *
 * GET /api/visits/list.php?patient_id=123&page=1&per_page=20&visit_type=outpatient&date_from=2026-01-01&date_to=2026-01-31&q=fever
 */

require_once __DIR__ . '/../../config_loader.php';
require_once __DIR__ . '/../response.php';
require_once __DIR__ . '/../db_helper.php';
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../auth/middleware.php';

handleCors();
requireMethod('GET');

$user = requireAuth();

// Pagination params: support per_page (preferred) and limit (legacy)
$page = max(1, (int) getParam('page', 1));
$perPage = (int) getParam('per_page', getParam('limit', 20));
$perPage = max(1, min(100, $perPage));
$offset = ($page - 1) * $perPage;

// Filters
$patientId = (int) getParam('patient_id', 0);
$visitType = getParam('visit_type', null);
$q = trim((string) getParam('q', ''));
$dateFrom = getParam('date_from', null);
$dateTo = getParam('date_to', null);

// Validate dates (YYYY-MM-DD) if provided
$dateFromSql = null; $dateToSql = null;
if ($dateFrom) {
    $d = DateTime::createFromFormat('Y-m-d', $dateFrom);
    if (!$d || $d->format('Y-m-d') !== $dateFrom) {
        Response::error('Invalid date_from format. Expected YYYY-MM-DD', [], 400);
    }
    $dateFromSql = $dateFrom;
}
if ($dateTo) {
    $d = DateTime::createFromFormat('Y-m-d', $dateTo);
    if (!$d || $d->format('Y-m-d') !== $dateTo) {
        Response::error('Invalid date_to format. Expected YYYY-MM-DD', [], 400);
    }
    $dateToSql = $dateTo;
}

try {
    $pdo = Database::getInstance();

    $where = [];
    $params = [];

    if ($patientId > 0) {
        $where[] = 'v.patient_id = :patient_id';
        $params[':patient_id'] = $patientId;
    }

    if (!empty($visitType)) {
        $where[] = 'v.visit_type = :visit_type';
        $params[':visit_type'] = $visitType;
    }

    if ($dateFromSql !== null) {
        $where[] = 'v.visit_date >= :date_from';
        $params[':date_from'] = $dateFromSql;
    }
    if ($dateToSql !== null) {
        $where[] = 'v.visit_date <= :date_to';
        $params[':date_to'] = $dateToSql;
    }

    if ($q !== '') {
        // safe search across text columns
        $where[] = '(v.chief_complaint LIKE :q OR v.diagnosis LIKE :q OR v.notes LIKE :q)';
        $params[':q'] = '%' . str_replace('%', '\\%', $q) . '%';
    }

    $whereSql = '';
    if (!empty($where)) {
        $whereSql = 'WHERE ' . implode(' AND ', $where);
    }

    // Total count
    $countSql = 'SELECT COUNT(*) AS total FROM visits v ' . $whereSql;
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Select explicit columns to normalize output
    $selectSql = "SELECT v.id, v.patient_id, v.visit_type, v.visit_date, v.chief_complaint, v.vital_signs, v.history_of_present_illness, v.physical_examination, v.diagnosis, v.notes, v.created_by, v.created_at, v.updated_at, "
        . "p.full_name AS patient_name, p.phone AS patient_phone, p.register_number AS patient_register_number, "
        . "u.full_name AS doctor_name "
        . "FROM visits v "
        . "LEFT JOIN patients p ON v.patient_id = p.id "
        . "LEFT JOIN users u ON v.created_by = u.id "
        . $whereSql
        . " ORDER BY v.visit_date DESC, v.created_at DESC "
        . " LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($selectSql);
    // bind params for filters
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    // bind limit/offset as integers
    $stmt->bindValue(':limit', (int) $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Normalize rows
    $items = [];
    foreach ($rows as $r) {
        $item = [];
        $item['id'] = isset($r['id']) ? (int)$r['id'] : null;
        $item['patient_id'] = isset($r['patient_id']) ? (int)$r['patient_id'] : null;
        $item['patient_name'] = $r['patient_name'] ?? null;
        $item['patient_phone'] = $r['patient_phone'] ?? null;
        $item['patient_register_number'] = $r['patient_register_number'] ?? null;
        $item['visit_type'] = $r['visit_type'] ?? null;
        $item['visit_date'] = $r['visit_date'] ?? null;
        $item['chief_complaint'] = $r['chief_complaint'] ?? null;
        $item['vital_signs'] = null;
        if (!empty($r['vital_signs'])) {
            $decoded = json_decode($r['vital_signs'], true);
            $item['vital_signs'] = $decoded !== null ? $decoded : null;
        }
        $item['history_of_present_illness'] = $r['history_of_present_illness'] ?? null;
        $item['physical_examination'] = $r['physical_examination'] ?? null;
        $item['diagnosis'] = $r['diagnosis'] ?? null;
        $item['notes'] = $r['notes'] ?? null;
        $item['created_by'] = isset($r['created_by']) ? (int)$r['created_by'] : null;
        $item['doctor_name'] = $r['doctor_name'] ?? null;
        $item['created_at'] = $r['created_at'] ?? null;
        $item['updated_at'] = $r['updated_at'] ?? null;

        $items[] = $item;
    }

    paginatedResponse($items, $total, $page, $perPage);

} catch (Throwable $e) {
    error_log('List visits error: ' . $e->getMessage());
    Response::error('Failed to fetch visits', [], 500);
}
