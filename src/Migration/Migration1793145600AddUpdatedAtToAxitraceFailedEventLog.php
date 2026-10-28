<?php

declare(strict_types=1);

namespace AxitraceShopware6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The DAL reads `updated_at` on every entity, but the table was created
 * without it, so every repository search on the retry queue failed with
 * "Unknown column" and no queued event was ever resent.
 */
final class Migration1793145600AddUpdatedAtToAxitraceFailedEventLog extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1793145600;
    }

    public function update(Connection $connection): void
    {
        // A reinstall that kept user data finds the column already there.
        $existing = $connection->fetchOne(
            "SHOW COLUMNS FROM `axitrace_failed_event_log` LIKE 'updated_at'",
        );
        if ($existing !== false) {
            return;
        }

        $connection->executeStatement(<<<'SQL'
            ALTER TABLE `axitrace_failed_event_log`
                ADD COLUMN `updated_at` DATETIME(3) NULL AFTER `created_at`
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive update needed
    }
}
