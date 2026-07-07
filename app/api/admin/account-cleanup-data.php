<?php

declare(strict_types=1);

/**
 * Account cleanup DataTables AJAX endpoint
 *
 * Returns ownerless or archived accounts as JSON for DataTables on the
 * admin Account Cleanup tab.
 *
 * Query params:
 *   type      — 'unverified' | 'verified' | 'archive'
 *   threshold — int (days); ≥30 for unverified, ≥1 for verified; ignored for archive
 */

require_once '../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'app/admin/includes/account-cleanup-helpers.php';

if (!isAdmin()) {
    ApiResponse::forbidden('Forbidden')->send();
    exit;
}

$type      = $_GET['type']      ?? '';
$threshold = (int) ($_GET['threshold'] ?? 30);

if ($type === 'unverified') {
    $threshold = max(30, $threshold);
    $accounts  = findUnverifiedOwnerlessAccounts($db, $threshold);
    if ($db->error()) {
        logger(0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'account-cleanup-data: unverified query failed — ' . $db->errorString());
        ApiResponse::serverError('Database error')->send();
        exit;
    }
    $rows = array_map(static function (object $a): array {
        return [
            'id'       => (int) $a->id,
            'email'    => (string) $a->email,
            'name'     => trim(($a->fname ?? '') . ' ' . ($a->lname ?? '')),
            'city'     => (string) ($a->city    ?? ''),
            'state'    => (string) ($a->state   ?? ''),
            'country'  => (string) ($a->country ?? ''),
            'joined'   => (string) $a->join_date,
            'age_days' => (int) $a->age_days,
        ];
    }, $accounts);
} elseif ($type === 'verified') {
    $threshold = max(1, $threshold);
    $accounts  = findVerifiedOwnerlessAccounts($db, $threshold);
    if ($db->error()) {
        logger(0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'account-cleanup-data: verified query failed — ' . $db->errorString());
        ApiResponse::serverError('Database error')->send();
        exit;
    }
    $rows = array_map(static function (object $a): array {
        $ll = $a->last_login ?? null;
        return [
            'id'         => (int) $a->id,
            'email'      => (string) $a->email,
            'name'       => trim(($a->fname ?? '') . ' ' . ($a->lname ?? '')),
            'city'       => (string) ($a->city    ?? ''),
            'state'      => (string) ($a->state   ?? ''),
            'country'    => (string) ($a->country ?? ''),
            'joined'     => (string) $a->join_date,
            'last_login' => ($ll && $ll !== '0000-00-00 00:00:00') ? $ll : null,
            'logins'     => (int) $a->logins,
        ];
    }, $accounts);
} elseif ($type === 'archive') {
    $accounts = findArchivedAccounts($db);
    if ($db->error()) {
        logger(0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'account-cleanup-data: archive query failed — ' . $db->errorString());
        ApiResponse::serverError('Database error')->send();
        exit;
    }
    $rows = array_map(static function (object $a): array {
        $restoredBy = ($a->restored_by_fname || $a->restored_by_lname)
            ? trim(($a->restored_by_fname ?? '') . ' ' . ($a->restored_by_lname ?? ''))
            : null;
        return [
            'id'               => (int) $a->id,
            'original_user_id' => (int) $a->original_user_id,
            'email'            => (string) $a->email,
            'name'             => trim(($a->fname ?? '') . ' ' . ($a->lname ?? '')),
            'deletion_type'    => (string) $a->deletion_type,
            'deleted_at'       => (string) $a->deleted_at,
            'city'             => (string) ($a->city    ?? ''),
            'state'            => (string) ($a->state   ?? ''),
            'country'          => (string) ($a->country ?? ''),
            'restored_at'      => $a->restored_at ?: null,
            'restored_by'      => $restoredBy,
        ];
    }, $accounts);
} else {
    ApiResponse::error('Invalid type', 400)->send();
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['data' => $rows]);
