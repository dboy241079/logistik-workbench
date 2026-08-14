<?php
declare(strict_types=1);

/**
 * Realtime-Outbox: Änderungen werden nur zusätzlich protokolliert.
 * Ein Fehler hier darf den eigentlichen Fachvorgang niemals blockieren.
 */

function realtimeEnsureSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS realtime_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_type VARCHAR(80) NOT NULL,
        location_code VARCHAR(32) NOT NULL DEFAULT 'WUN',
        entity_type VARCHAR(80) DEFAULT NULL,
        entity_id VARCHAR(128) DEFAULT NULL,
        payload_json JSON DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        published_at DATETIME DEFAULT NULL,
        publish_attempts INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY idx_realtime_pending (published_at, id),
        KEY idx_realtime_location (location_code, id),
        KEY idx_realtime_type (event_type, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS realtime_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        token_hash CHAR(64) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        location_code VARCHAR(32) NOT NULL DEFAULT 'WUN',
        scopes VARCHAR(255) NOT NULL DEFAULT 'drivers',
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        used_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_realtime_token_hash (token_hash),
        KEY idx_realtime_token_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function realtimeLocationCode(): string
{
    $loc = strtoupper(trim((string)($_SESSION['location_code'] ?? $_SESSION['location'] ?? 'WUN')));
    return preg_match('/^[A-Z0-9_-]{2,32}$/', $loc) ? $loc : 'WUN';
}

function realtimeEmit(PDO $pdo, string $eventType, array $payload = [], ?string $locationCode = null, ?string $entityType = null, ?string $entityId = null): void
{
    try {
        realtimeEnsureSchema($pdo);
        $locationCode = $locationCode ?: realtimeLocationCode();

        $st = $pdo->prepare("INSERT INTO realtime_events
            (event_type, location_code, entity_type, entity_id, payload_json, created_at)
            VALUES (:event_type, :location_code, :entity_type, :entity_id, :payload_json, NOW())");
        $st->execute([
            ':event_type' => $eventType,
            ':location_code' => $locationCode,
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $e) {
        error_log('realtimeEmit failed: ' . $e->getMessage());
    }
}
