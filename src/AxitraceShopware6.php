<?php

declare(strict_types=1);

namespace AxitraceShopware6;

use AxitraceShopware6\Domain\StoreDomainClaimer;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;

/**
 * Plugin bootstrap class for AxiTrace server-side tracking.
 *
 * Responsible only for lifecycle hooks (install / activate / update / uninstall).
 * Business logic lives in dedicated services and subscribers.
 */
final class AxitraceShopware6 extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        // Schema is managed via src/Resources/config/services.yaml and
        // src/Migration/ migrations — nothing to do manually on install.
        parent::install($installContext);
    }

    /**
     * Shopware rebuilds the container with the plugin active before calling
     * activate(), so the claimer is available here.
     */
    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);
        $this->claimStoreDomains();
    }

    /**
     * Runs in the container of the version that was installed before, which has
     * no claimer when updating from 0.7.x or older: then the daily task, due at
     * once when Shopware registers it, makes the first claim.
     */
    public function postUpdate(UpdateContext $updateContext): void
    {
        parent::postUpdate($updateContext);
        $this->claimStoreDomains();
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);
        $connection->executeStatement('DROP TABLE IF EXISTS `axitrace_failed_event_log`');
    }

    /**
     * Claims the storefront domains when a key is configured. A failing claim
     * never blocks activation or update: it is logged at critical and retried
     * by the daily task.
     */
    private function claimStoreDomains(): void
    {
        if ($this->container === null || !$this->container->has(StoreDomainClaimer::class)) {
            return;
        }

        try {
            /** @var StoreDomainClaimer $claimer */
            $claimer = $this->container->get(StoreDomainClaimer::class);
            if ($claimer->hostGroups() !== []) {
                $claimer->claimAll();
            }
        } catch (\Throwable $e) {
            if ($this->container->has('logger')) {
                /** @var LoggerInterface $logger */
                $logger = $this->container->get('logger');
                $logger->critical('AxiTrace store domain claim on plugin activation or update failed: ' . $e::class . ': ' . $e->getMessage());
            }
        }
    }
}
