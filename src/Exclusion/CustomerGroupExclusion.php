<?php

declare(strict_types=1);

namespace AxitraceShopware6\Exclusion;

use AxitraceShopware6\Config\PluginConfig;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * "Customer groups excluded from tracking": customers of the groups the
 * merchant selected (typically a B2B group) are never tracked by AxiTrace.
 *
 * Two independent paths apply it:
 *  - Orders: OrderPaidSubscriber and OrderRefundSubscriber skip an order of an
 *    excluded customer, so it reaches neither AxiTrace nor any connector. The
 *    group is the one recorded on the order at placement (OrderPlacedSubscriber),
 *    else the customer's current group, so admin and API orders are covered too.
 *  - Browsing: the storefront bootstrap asks StorefrontContextController whether
 *    the logged-in visitor is excluded before it starts the SDK. The answer
 *    cannot be rendered into the page: Shopware's HTTP cache shares page HTML
 *    across visitors.
 *
 * Only a logged-in customer (guest checkout accounts included) has a group:
 * an anonymous visitor is never excluded, even when the merchant excluded the
 * Sales Channel's default customer group.
 */
final class CustomerGroupExclusion
{
    /** The customer group of the order's customer at placement, written by OrderPlacedSubscriber. */
    public const CUSTOM_FIELD_CUSTOMER_GROUP = 'axitrace_customer_group_id';

    public function __construct(
        private readonly PluginConfig $config,
    ) {
    }

    public function isConfigured(string $salesChannelId): bool
    {
        return $this->config->getExcludedCustomerGroupIds($salesChannelId) !== [];
    }

    public function excludesGroup(?string $customerGroupId, string $salesChannelId): bool
    {
        if ($customerGroupId === null || $customerGroupId === '') {
            return false;
        }

        return in_array(strtolower($customerGroupId), $this->config->getExcludedCustomerGroupIds($salesChannelId), true);
    }

    public function excludesVisitor(SalesChannelContext $context): bool
    {
        return $this->excludesGroup($context->getCustomer()?->getGroupId(), $context->getSalesChannelId());
    }

    /**
     * Needs the `orderCustomer.customer` association for orders placed before
     * the group was recorded on them (or created without a placement event).
     */
    public function excludesOrder(OrderEntity $order): bool
    {
        return $this->excludesGroup($this->orderCustomerGroupId($order), (string) $order->getSalesChannelId());
    }

    private function orderCustomerGroupId(OrderEntity $order): ?string
    {
        $recorded = $order->getCustomFields()[self::CUSTOM_FIELD_CUSTOMER_GROUP] ?? null;
        if (is_string($recorded) && $recorded !== '') {
            return $recorded;
        }

        return $order->getOrderCustomer()?->getCustomer()?->getGroupId();
    }
}
