<?php

declare(strict_types=1);

namespace AxitraceShopware6\Subscriber;

use AxitraceShopware6\Config\PluginConfig;
use AxitraceShopware6\Normalizer\PinterestCatalogIdResolver;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPage;
use Shopware\Storefront\Page\Product\ProductPage;
use Shopware\Storefront\Page\Product\ProductPageCriteriaEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Injects the AxiTrace SDK config block into every storefront page render.
 *
 * No inline executable JavaScript is emitted — only a CSP-safe
 * <script type="application/json"> data block and a deferred external script src.
 */
final class StorefrontSubscriber implements EventSubscriberInterface
{
    /** Default tracking domain when the merchant has not configured a CNAME. */
    private const DEFAULT_TRACKING_DOMAIN = 'stat.axitrace.com';

    public function __construct(
        private readonly PluginConfig $config,
        private readonly PinterestCatalogIdResolver $pinterestIdResolver,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductPageCriteriaEvent::class => 'onProductPageCriteria',
            StorefrontRenderEvent::class => 'onStorefrontRender',
        ];
    }

    public function onProductPageCriteria(ProductPageCriteriaEvent $event): void
    {
        if (!$this->config->isEnabled($event->getSalesChannelContext()->getSalesChannelId())) {
            return;
        }

        $event->getCriteria()->addAssociation('categories');
    }

    public function onStorefrontRender(StorefrontRenderEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelContext()->getSalesChannelId();

        if (!$this->config->isEnabled($salesChannelId)) {
            return;
        }

        $publicKey = $this->config->getPublicKey($salesChannelId);
        if ($publicKey === '') {
            return;
        }

        $request = $event->getRequest();
        $routeName = (string) $request->attributes->get('_route', '');

        $pageType = match ($routeName) {
            'frontend.home.page'           => 'home',
            'frontend.navigation.page'     => 'category',
            'frontend.detail.page'         => 'product',
            'frontend.checkout.cart.page'  => 'cart',
            'frontend.checkout.finish.page' => 'purchase',
            'frontend.checkout.confirm.page' => 'checkout',
            default                        => 'page',
        };

        // Browser SDK domain — defaults to stat.axitrace.com.  When the merchant
        // has configured a custom tracking domain (CNAME → stat.axitrace.com),
        // the SDK is loaded from their domain so that cookies (vt_vid, vt_sid,
        // vt_uid) land as first-party.  Server-side dispatch (IngestionApiClient)
        // always hits stat.axitrace.com regardless — see SSRF mitigation note.
        $trackingDomain = $this->config->getTrackingDomain($salesChannelId);
        if ($trackingDomain === '') {
            $trackingDomain = self::DEFAULT_TRACKING_DOMAIN;
        }
        $sdkBaseUrl = 'https://' . $trackingDomain;

        // Consent policy for the browser SDK. Only the POLICY is rendered here —
        // the mode and the cookie to watch. The decision itself is deliberately
        // NOT evaluated server-side and NOT emitted: Shopware's HTTP cache keys
        // pages on the URI plus sw-cache-hash / currency only (see
        // HttpCacheKeyGenerator::addCookies) and never on an arbitrary cookie,
        // so a page rendered for a consenting visitor is served verbatim to the
        // next anonymous visitor. A server-rendered "granted" would therefore
        // leak across visitors and boot the SDK for someone who declined. The
        // bootstrap in meta.html.twig reads the cookie in the visitor's own
        // browser instead — see ConsentGate::isGrantSignal() for the canonical
        // grant rule the JavaScript mirrors.
        $consentMode = $this->config->getConsentMode($salesChannelId);
        $consentCookie = $this->config->getConsentCookieName($salesChannelId);

        $config = [
            'publicKey'       => $publicKey,
            'apiUrl'          => $sdkBaseUrl,
            'pageType'        => $pageType,
            'sdkUrl'          => $sdkBaseUrl . '/axitrack.js',
            'storefrontContextUrl' => $this->urlGenerator->generate('frontend.axitrace.storefront-context'),
            'consent'         => [
                'mode'   => $consentMode->value,
                'cookie' => $consentCookie,
            ],
        ];

        // S-MED-2: debug key is gated behind the admin-preview header AND the per-channel debug flag.
        if ($request->headers->has('x-axitrace-admin-preview') && $this->config->isDebugMode($salesChannelId)) {
            $config['debug'] = true;
        }

        $event->setParameter('axitraceConfig', $config);
        $event->setParameter('axitraceScriptUrl', $sdkBaseUrl . '/axitrack.js');

        $this->injectProductContext($event, $salesChannelId);

        if ($pageType === 'checkout') {
            $this->injectCheckoutContext(
                $event,
                $event->getSalesChannelContext()->getCurrency()->getIsoCode(),
            );
        }
    }

    /**
     * Injects product context for PDP pages.
     *
     * Wrapped in a try/catch so that any failure in extracting product data
     * does not break the storefront render — the SDK config is already set
     * and the page will load without the product context.
     */
    private function injectProductContext(StorefrontRenderEvent $event, string $salesChannelId): void
    {
        try {
            $page = $event->getParameters()['page'] ?? null;

            if (!($page instanceof ProductPage)) {
                return;
            }

            $product = $page->getProduct();
            if ($product === null) {
                return;
            }

            $context = [
                'sku'       => (string) ($product->getProductNumber() ?? ''),
                'productId' => (string) $product->getId(),
                'name'      => (string) $product->getTranslation('name'),
                'price'     => (float) ($product->getCalculatedPrice()?->getUnitPrice() ?? 0),
                'currency'  => $event->getSalesChannelContext()->getCurrency()->getIsoCode(),
                'brand'     => (string) ($product->getManufacturer()?->getTranslation('name') ?? $product->getManufacturer()?->getName() ?? ''),
                'category'  => (string) ($product->getCategories()?->first()?->getTranslation('name') ?? $product->getCategories()?->first()?->getName() ?? ''),
                'variant'   => $this->formatVariation($product->getVariation()),
            ];
            $pinterestId = $this->pinterestIdResolver->resolve(
                $this->config->getPinterestCatalogIdMode($salesChannelId),
                (string) $product->getProductNumber(),
                (string) $product->getId(),
            );
            if ($pinterestId !== null) {
                $context['pinterest_id'] = $pinterestId;
            }
            $event->setParameter('axitraceProductContext', $context);
        } catch (\Throwable $error) {
            $message = $error::class . ': ' . $error->getMessage();
            $this->logger->critical('AxiTrace: product context initialization failed: ' . $message);
            $event->setParameter('axitraceProductContextError', $message);
        }
    }

    /**
     * Injects cart context for the checkout confirm page so the SDK's mid-funnel
     * events (InitiateCheckout / AddPaymentInfo) carry the real order value and
     * currency instead of firing empty. Read from Shopware's confirm page cart
     * (server-authoritative) rather than scraped from the theme's DOM, which is
     * theme-dependent and unreliable.
     *
     * Wrapped in try/catch like injectProductContext — this is non-critical
     * enrichment and must never interrupt the checkout page render.
     */
    private function injectCheckoutContext(StorefrontRenderEvent $event, string $currency): void
    {
        try {
            $page = $event->getParameters()['page'] ?? null;

            if (!($page instanceof CheckoutConfirmPage)) {
                return;
            }

            $cart = $page->getCart();

            $items = [];
            foreach ($cart->getLineItems() as $lineItem) {
                if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                    continue;
                }

                $referencedId = $lineItem->getReferencedId();
                if ($referencedId === null || $referencedId === '') {
                    continue;
                }

                $payload = $lineItem->getPayload();
                $productNumber = (string) ($payload['productNumber'] ?? '');
                $item = [
                    'id'       => $referencedId,
                    'item_id'  => $productNumber !== '' ? $productNumber : strtolower($referencedId),
                    'productId' => strtolower($referencedId),
                    'sku'      => $productNumber,
                    'name'     => (string) $lineItem->getLabel(),
                    'price'    => (float) $lineItem->getPrice()?->getUnitPrice(),
                    'quantity' => $lineItem->getQuantity(),
                ];
                $pinterestId = $this->pinterestIdResolver->resolve(
                    $this->config->getPinterestCatalogIdMode($event->getSalesChannelContext()->getSalesChannelId()),
                    $productNumber,
                    $referencedId,
                );
                if ($pinterestId !== null) {
                    $item['pinterest_id'] = $pinterestId;
                }
                $items[] = $item;
            }

            $event->setParameter('axitraceCheckoutContext', [
                'value'    => round($cart->getPrice()->getTotalPrice(), 2),
                'currency' => $currency,
                'items'    => $items,
                'quantity' => array_sum(array_column($items, 'quantity')),
            ]);
        } catch (\Throwable $error) {
            $message = $error::class . ': ' . $error->getMessage();
            $this->logger->critical('AxiTrace: checkout context initialization failed: ' . $message);
            $event->setParameter('axitraceCheckoutContextError', $message);
        }
    }

    /** @param array<int|string, mixed>|null $variation */
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
}
