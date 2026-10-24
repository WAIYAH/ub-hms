<?php
/**
 * Audit trail. Records who did what, from which computer and when. The
 * details column never holds clinical information, only identifiers and
 * short descriptions, so the log can be read by managers without exposing
 * patient records.
 */
declare(strict_types=1);

function audit(string $action, ?string $entity = null, ?int $entityId = null, ?string $details = null, ?int $userId = null, ?string $ip = null): void
{
    try {
        $userId = $userId ?? (current_user()['id'] ?? null);
        db_exec(
            'INSERT INTO audit_log (user_id, action, entity, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, mb_substr($action, 0, 40), $entity, $entityId, $details !== null ? mb_substr($details, 0, 500) : null, $ip ?? client_ip()]
        );
    } catch (Throwable $e) {
        // A logging failure must never stop the user's work, but it must be visible to the administrator.
        log_message('error', 'Audit write failed: ' . $e->getMessage());
    }
}
