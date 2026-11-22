<?php

declare(strict_types=1);

namespace AxitraceShopware6\ScheduledTask;

use AxitraceShopware6\Domain\StoreDomainClaimer;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: AxitraceClaimStoreDomainTask::class)]
final class AxitraceClaimStoreDomainHandler extends ScheduledTaskHandler
{
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $logger,
        private readonly StoreDomainClaimer $claimer,
    ) {
        parent::__construct($scheduledTaskRepository, $logger);
    }

    public function run(): void
    {
        $this->claimer->claimAll();
    }
}
