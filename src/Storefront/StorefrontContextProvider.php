<?php

declare(strict_types=1);

namespace AxitraceShopware6\Storefront;

use AxitraceShopware6\Config\PluginConfig;
use AxitraceShopware6\Normalizer\PinterestCatalogIdResolver;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class StorefrontContextProvider
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly SalesChannelRepository $productRepository,
        private readonly PluginConfig $config,
        private readonly PinterestCatalogIdResolver $pinterestIdResolver,
    ) {
    }

    /** @return array<string, mixed> */
    public function load(SalesChannelContext $context, ?string $productId, bool $includeCustomer): array
    {
        $cart = $this->cartService->getCart($context->getToken(), $context);
        $productIds = [];
        foreach ($cart->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $lineItem) {
            if ($lineItem->getReferencedId()) {
                $productIds[] = $lineItem->getReferencedId();
            }
        }
        if ($productId !== null) {
            $productIds[] = $productId;
        }

        $products = $this->loadProducts(array_values(array_unique($productIds)), $context);
        $items = [];
        $quantity = 0;
        foreach ($cart->getLineItems()->filterType(LineItem::PRODUCT_LINE_ITEM_TYPE) as $lineItem) {
            $id = (string) $lineItem->getReferencedId();
            $quantity += $lineItem->getQuantity();
            $items[] = $this->formatLineItem($lineItem, $products[$id] ?? null, $context);
        }

        $result = [
            'cart' => [
                'value' => round($cart->getPrice()->getTotalPrice(), 2),
                'currency' => $context->getCurrency()->getIsoCode(),
                'quantity' => $quantity,
                'items' => $items,
            ],
        ];

        if ($productId !== null && isset($products[$productId])) {
            $result['product'] = $this->formatProduct($products[$productId], $context);
        }
        if ($includeCustomer && $context->getCustomer() !== null) {
            $customer = $context->getCustomer();
            $billing = $customer->getActiveBillingAddress() ?? $customer->getDefaultBillingAddress();
            $result['customer'] = [
                'email' => (string) $customer->getEmail(),
                'firstName' => (string) $customer->getFirstName(),
                'lastName' => (string) $customer->getLastName(),
                'city' => (string) ($billing?->getCity() ?? ''),
                'state' => $this->normalizeState((string) ($billing?->getCountryState()?->getShortCode() ?? $billing?->getCountryState()?->getName() ?? '')),
                'zip' => (string) ($billing?->getZipcode() ?? ''),
                'country' => (string) ($billing?->getCountry()?->getIso() ?? ''),
            ];
        }

        return $result;
    }

    /** @return array<string, SalesChannelProductEntity> */
    private function loadProducts(array $ids, SalesChannelContext $context): array
    {
        if ($ids === []) {
            return [];
        }
        $criteria = new Criteria($ids);
        $criteria->addAssociation('manufacturer');
        $criteria->addAssociation('categories');
        $result = [];
        foreach ($this->productRepository->search($criteria, $context) as $product) {
            if ($product instanceof SalesChannelProductEntity) {
                $result[$product->getId()] = $product;
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function formatProduct(SalesChannelProductEntity $product, SalesChannelContext $context): array
    {
        $sku = $product->getProductNumber();
        $result = [
            'productId' => strtolower($product->getId()),
            'item_id' => $sku !== '' ? $sku : strtolower($product->getId()),
            'sku' => $sku,
            'name' => (string) $product->getTranslation('name'),
            'price' => (float) $product->getCalculatedPrice()->getUnitPrice(),
            'currency' => $context->getCurrency()->getIsoCode(),
            'brand' => (string) ($product->getManufacturer()?->getTranslation('name') ?? $product->getManufacturer()?->getName() ?? ''),
            'category' => (string) ($product->getCategories()?->first()?->getTranslation('name') ?? $product->getCategories()?->first()?->getName() ?? ''),
            'variant' => $this->formatVariation($product->getVariation()),
        ];
        $pinterestId = $this->pinterestIdResolver->resolve(
            $this->config->getPinterestCatalogIdMode($context->getSalesChannelId()),
            $sku,
            $product->getId(),
        );
        if ($pinterestId !== null) {
            $result['pinterest_id'] = $pinterestId;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function formatLineItem(LineItem $lineItem, ?SalesChannelProductEntity $product, SalesChannelContext $context): array
    {
        if ($product !== null) {
            $item = $this->formatProduct($product, $context);
        } else {
            $id = strtolower((string) $lineItem->getReferencedId());
            $sku = (string) ($lineItem->getPayload()['productNumber'] ?? '');
            $item = ['productId' => $id, 'item_id' => $sku !== '' ? $sku : $id, 'sku' => $sku, 'name' => (string) $lineItem->getLabel()];
            $pinterestId = $this->pinterestIdResolver->resolve($this->config->getPinterestCatalogIdMode($context->getSalesChannelId()), $sku, $id);
            if ($pinterestId !== null) {
                $item['pinterest_id'] = $pinterestId;
            }
        }
        $item['price'] = (float) $lineItem->getPrice()?->getUnitPrice();
        $item['quantity'] = $lineItem->getQuantity();

        return $item;
    }

    private function formatVariation(?array $variation): string
    {
        if ($variation === null) {
            return '';
        }
        $parts = [];
        foreach ($variation as $value) {
            if (is_array($value)) {
                $value = $value['option'] ?? $value['name'] ?? null;
            }
            if (is_scalar($value) && trim((string) $value) !== '') {
                $parts[] = trim((string) $value);
            }
        }

        return implode(' / ', $parts);
    }

    private function normalizeState(string $state): string
    {
        $state = trim($state);
        if (preg_match('/^[A-Za-z]{2}-(.+)$/', $state, $matches) === 1) {
            return $matches[1];
        }

        return $state;
    }
}
