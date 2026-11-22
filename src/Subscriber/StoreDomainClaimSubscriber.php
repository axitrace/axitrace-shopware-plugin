<?php

declare(strict_types=1);

namespace AxitraceShopware6\Subscriber;

use AxitraceShopware6\Domain\StoreDomainClaimer;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;
use Shopware\Core\System\SystemConfig\Event\SystemConfigMultipleChangedEvent;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Claims the storefront domains again after the plugin's key settings change.
 *
 * The administration saves the whole settings form in one batch, which fires one
 * SystemConfigChangedEvent per field and then one SystemConfigMultipleChangedEvent:
 * the change of a watched key is noted and the claim runs once, when the batch
 * is complete, so the stored status is current when the page is reloaded. A
 * single `set()` (for example `bin/console system:config:set`) has no batch event
 * and is claimed when the request or command terminates.
 *
 * A failing claim never fails the save: it is logged at critical and the status
 * field keeps the previous outcome.
 */
final class StoreDomainClaimSubscriber implements EventSubscriberInterface
{
    private const WATCHED_KEYS = [
        'AxitraceShopware6.config.publicKey',
        'AxitraceShopware6.config.secretKey',
        'AxitraceShopware6.config.enabled',
    ];

    private bool $pending = false;

    public function __construct(
        private readonly StoreDomainClaimer $claimer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SystemConfigChangedEvent::class         => 'onConfigChanged',
            SystemConfigMultipleChangedEvent::class => 'onBatchSaved',
            KernelEvents::TERMINATE                 => 'onTerminate',
            ConsoleEvents::TERMINATE                => 'onTerminate',
        ];
    }

    public function onConfigChanged(SystemConfigChangedEvent $event): void
    {
        if (\in_array($event->getKey(), self::WATCHED_KEYS, true)) {
            $this->pending = true;
        }
    }

    public function onBatchSaved(SystemConfigMultipleChangedEvent $event): void
    {
        $this->claimIfPending();
    }

    public function onTerminate(): void
    {
        $this->claimIfPending();
    }

    private function claimIfPending(): void
    {
        if (!$this->pending) {
            return;
        }
        $this->pending = false;

        try {
            $this->claimer->claimAll();
        } catch (\Throwable $e) {
            $this->logger->critical('AxiTrace store domain claim after a settings change failed: ' . $e::class . ': ' . $e->getMessage());
        }
    }
}
