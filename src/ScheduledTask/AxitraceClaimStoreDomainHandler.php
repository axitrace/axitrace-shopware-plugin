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

    /**
     * Without a public key there is nothing to claim, and the stored status is
     * left alone: on a new install the task is due at once and may run (in the
     * administration's message worker) while the merchant saves the keys, and a
     * "no public key" outcome must never overwrite the claim of that save.
     */
    public function run(): void
    {
        if ($this->claimer->hostGroups() === []) {
            return;
        }
        $this->claimer->claimAll();
    }
}
