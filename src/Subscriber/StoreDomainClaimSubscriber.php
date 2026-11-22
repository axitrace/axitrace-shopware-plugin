<?php

declare(strict_types=1);

namespace AxitraceShopware6\Subscriber;

use AxitraceShopware6\AxitraceShopware6;
use AxitraceShopware6\Domain\StoreDomainClaimer;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Plugin\Event\PluginPostActivateEvent;
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
 * single `set()` (for example `bin/console system:config:set`) goes through the
 * same batch path; the end of the request or command is the fallback.
 *
 * Activating the plugin claims too, once Shopware has stored the plugin as active
 * (PluginPostActivateEvent). Not from the plugin's activate(): until then every
 * other request still runs without the plugin, so AxiTrace's proof request would
 * get a 404 from /axitrace/domain-proof and the domain would be marked failed.
 *
 * Never while the plugin is inactive: uninstalling deactivates it first and then
 * deletes its settings, which fires the same change events; a claim there would
 * write the status rows back after Shopware removed them.
 *
 * A failing claim never fails the save or the activation: it is logged at
 * critical and the status field keeps the previous outcome.
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
        private readonly Connection $connection,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SystemConfigChangedEvent::class         => 'onConfigChanged',
            SystemConfigMultipleChangedEvent::class => 'onBatchSaved',
            KernelEvents::TERMINATE                 => 'onTerminate',
            ConsoleEvents::TERMINATE                => 'onTerminate',
            PluginPostActivateEvent::class          => 'onPluginActivated',
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

    public function onPluginActivated(PluginPostActivateEvent $event): void
    {
        if ($event->getPlugin()->getBaseClass() !== AxitraceShopware6::class) {
            return;
        }
        $this->pending = true;
        $this->claimIfPending();
    }

    private function claimIfPending(): void
    {
        if (!$this->pending) {
            return;
        }
        $this->pending = false;

        try {
            if (!$this->pluginIsActive()) {
                return;
            }
            $this->claimer->claimAll();
        } catch (\Throwable $e) {
            $this->logger->critical('AxiTrace store domain claim after a settings change or activation failed: ' . $e::class . ': ' . $e->getMessage());
        }
    }

    private function pluginIsActive(): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT `active` FROM `plugin` WHERE `base_class` = :baseClass',
            ['baseClass' => AxitraceShopware6::class],
        );
    }
}
