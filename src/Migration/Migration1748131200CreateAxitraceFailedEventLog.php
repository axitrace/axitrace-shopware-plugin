<?php

declare(strict_types=1);

namespace AxitraceShopware6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

final class Migration1748131200CreateAxitraceFailedEventLog extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1748131200;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `axitrace_failed_event_log` (
                `id`               BINARY(16)   NOT NULL,
                `event_id`         VARCHAR(64)  NOT NULL,
                `payload`          LONGTEXT     NOT NULL,
                `attempts`         INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at`       DATETIME(3)  NOT NULL,
                `last_attempt_at`  DATETIME(3)  NULL,
                `last_error`       TEXT         NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_axitrace_failed_event_log_event_id` (`event_id`),
                KEY `idx_axitrace_failed_event_log_attempts` (`attempts`, `last_attempt_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive update needed
    }
}
