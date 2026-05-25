<?php

declare(strict_types=1);

namespace AxitraceShopware6\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

final class AxitraceRetryFailedEventsTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'axitrace.retry_failed_events';
    }

    public static function getDefaultInterval(): int
    {
        return 900; // 15 minutes
    }
}
