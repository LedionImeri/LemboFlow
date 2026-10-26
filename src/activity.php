<?php
/**
 * Activity feed entries shown on the dashboard.
 *
 * log_activity($userId, 'invoice.created', 'Invoice INV-2026-0001 created for Acme', 'invoice', 12);
 *
 * Call it inside the same transaction as the change it describes, so the feed
 * never mentions something that was rolled back.
 */

declare(strict_types=1);

function log_activity(
    int $userId,
    string $type,
    string $description,
    ?string $subjectType = null,
    ?int $subjectId = null
): void {
    db()->prepare(
        'INSERT INTO activities (user_id, type, subject_type, subject_id, description)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        $userId,
        $type,
        $subjectType,
        $subjectId,
        mb_substr($description, 0, 255),
    ]);
}
