<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Exclusion;

use AxitraceShopware6\Config\AxitraceCrypto;
use AxitraceShopware6\Config\PluginConfig;
use AxitraceShopware6\Exclusion\CustomerGroupExclusion;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * "Customer groups excluded from tracking" on the real PluginConfig, with only
 * the SystemConfigService doubled (the plugin's services are final).
 */
final class CustomerGroupExclusionTest extends TestCase
{
    private const SALES_CHANNEL = '0191d3d2c1ce7a2ba9d1f2f2c8b1a001';
    private const B2B_GROUP = '0191d3d2c1ce7a2ba9d1f2f2c8b1ab2b';
    private const RETAIL_GROUP = 'cfbd5018d38d41d8adca10d94fc8bdd6';

    public function testNothingIsExcludedUntilTheMerchantSelectsAGroup(): void
    {
        $exclusion = $this->exclusion(null);

        self::assertFalse($exclusion->isConfigured(self::SALES_CHANNEL));
        self::assertFalse($exclusion->excludesGroup(self::B2B_GROUP, self::SALES_CHANNEL));
        self::assertFalse($exclusion->excludesOrder($this->order([CustomerGroupExclusion::CUSTOM_FIELD_CUSTOMER_GROUP => self::B2B_GROUP], self::B2B_GROUP)));
    }

    public function testSelectedGroupIsExcludedWhateverTheCaseOfTheId(): void
    {
        $exclusion = $this->exclusion([strtoupper(self::B2B_GROUP)]);

        self::assertTrue($exclusion->isConfigured(self::SALES_CHANNEL));
        self::assertTrue($exclusion->excludesGroup(self::B2B_GROUP, self::SALES_CHANNEL));
        self::assertTrue($exclusion->excludesGroup(strtoupper(self::B2B_GROUP), self::SALES_CHANNEL));
        self::assertFalse($exclusion->excludesGroup(self::RETAIL_GROUP, self::SALES_CHANNEL));
        self::assertFalse($exclusion->excludesGroup(null, self::SALES_CHANNEL));
        self::assertFalse($exclusion->excludesGroup('', self::SALES_CHANNEL));
    }

    public function testValuesThatAreNotShopwareIdsAreIgnored(): void
    {
        $exclusion = $this->exclusion(['', 'all', '*', 42, null, ['nested'], self::B2B_GROUP]);

        self::assertSame([self::B2B_GROUP], $this->config(['', 'all', '*', 42, null, ['nested'], self::B2B_GROUP, self::B2B_GROUP])->getExcludedCustomerGroupIds(self::SALES_CHANNEL));
        self::assertFalse($exclusion->excludesGroup('all', self::SALES_CHANNEL));
        self::assertFalse($this->exclusion('not-a-list')->isConfigured(self::SALES_CHANNEL), 'a scalar setting excludes nobody');
    }

    public function testOnlyALoggedInCustomerOfTheGroupIsAnExcludedVisitor(): void
    {
        $exclusion = $this->exclusion([self::B2B_GROUP]);

        self::assertTrue($exclusion->excludesVisitor($this->visitor(self::B2B_GROUP)));
        self::assertFalse($exclusion->excludesVisitor($this->visitor(self::RETAIL_GROUP)));
        self::assertFalse($exclusion->excludesVisitor($this->visitor(null)), 'an anonymous visitor cannot be recognised');
    }

    public function testOrderIsJudgedByTheGroupRecordedAtPlacement(): void
    {
        $exclusion = $this->exclusion([self::B2B_GROUP]);

        self::assertTrue($exclusion->excludesOrder($this->order([CustomerGroupExclusion::CUSTOM_FIELD_CUSTOMER_GROUP => self::B2B_GROUP], self::RETAIL_GROUP)));
        self::assertFalse(
            $exclusion->excludesOrder($this->order([CustomerGroupExclusion::CUSTOM_FIELD_CUSTOMER_GROUP => self::RETAIL_GROUP], self::B2B_GROUP)),
            'a retail order stays tracked when the customer is moved to the B2B group before it is paid',
        );
    }

    public function testOrderWithoutARecordedGroupIsJudgedByTheCustomersCurrentGroup(): void
    {
        $exclusion = $this->exclusion([self::B2B_GROUP]);

        self::assertTrue($exclusion->excludesOrder($this->order([], self::B2B_GROUP)), 'admin, API and pre-0.6.0 orders');
        self::assertFalse($exclusion->excludesOrder($this->order([], self::RETAIL_GROUP)));
        self::assertFalse($exclusion->excludesOrder($this->order([], null)), 'a deleted customer has no group');
    }

    /** @param mixed $excluded the raw system_config value */
    private function exclusion(mixed $excluded): CustomerGroupExclusion
    {
        return new CustomerGroupExclusion($this->config($excluded));
    }

    private function config(mixed $excluded): PluginConfig
    {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key, ?string $salesChannelId = null): mixed => $key === 'AxitraceShopware6.config.excludedCustomerGroups' && $salesChannelId === self::SALES_CHANNEL
                ? $excluded
                : null,
        );

        return new PluginConfig($systemConfig, new AxitraceCrypto('test-app-secret-fixture'), $this->createStub(LoggerInterface::class));
    }

    private function visitor(?string $groupId): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn(self::SALES_CHANNEL);
        $context->method('getCustomer')->willReturn($groupId === null ? null : $this->customer($groupId));

        return $context;
    }

    /** @param array<string, string> $customFields */
    private function order(array $customFields, ?string $currentGroupId): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId('0191d3d2c1ce7a2ba9d1f2f2c8b1a002');
        $order->setSalesChannelId(self::SALES_CHANNEL);
        $order->setCustomFields($customFields);
        $orderCustomer = new OrderCustomerEntity();
        if ($currentGroupId !== null) {
            $orderCustomer->setCustomer($this->customer($currentGroupId));
        }
        $order->setOrderCustomer($orderCustomer);

        return $order;
    }

    private function customer(string $groupId): CustomerEntity
    {
        $customer = new CustomerEntity();
        $customer->setGroupId($groupId);

        return $customer;
    }
}
