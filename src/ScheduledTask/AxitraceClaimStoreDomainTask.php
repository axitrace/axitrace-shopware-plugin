<?php

declare(strict_types=1);

namespace AxitraceShopware6\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Daily store domain claim (StoreDomainClaimer). A newly registered task is due
 * at once, so a shop that updated the plugin is claimed by the first scheduler
 * run, without anyone opening the settings.
 */
final class AxitraceClaimStoreDomainTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'axitrace.claim_store_domain';
    }

    public static function getDefaultInterval(): int
    {
        return 86400; // 1 day
    }
}
