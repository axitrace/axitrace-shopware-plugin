<?php

declare(strict_types=1);

namespace AxitraceShopware6\Subscriber;

use AxitraceShopware6\Config\PluginConfig;
use Shopware\Storefront\Event\StorefrontRenderEvent;
use Shopware\Storefront\Page\Product\ProductPage;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

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
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            StorefrontRenderEvent::class => 'onStorefrontRender',
        ];
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

        $config = [
            'publicKey'       => $publicKey,
            'apiUrl'          => $sdkBaseUrl,
            'pageType'        => $pageType,
            'consentRequired' => true,
        ];

        // S-MED-2: debug key is gated behind the admin-preview header AND the per-channel debug flag.
        if ($request->headers->has('x-axitrace-admin-preview') && $this->config->isDebugMode($salesChannelId)) {
            $config['debug'] = true;
        }

        $event->setParameter('axitraceConfig', $config);
        $event->setParameter('axitraceScriptUrl', $sdkBaseUrl . '/axitrack.js');

        $this->injectProductContext($event, $salesChannelId);
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
            $page = $event->getPage();

            if (!($page instanceof ProductPage)) {
                return;
            }

            $product = $page->getProduct();
            if ($product === null) {
                return;
            }

            $event->setParameter('axitraceProductContext', [
                'sku'       => (string) ($product->getProductNumber() ?? ''),
                'productId' => (string) $product->getId(),
                'name'      => (string) $product->getTranslation('name'),
                'price'     => (float) ($product->getCalculatedPrice()?->getUnitPrice() ?? 0),
                'currency'  => $event->getSalesChannelContext()->getCurrency()->getIsoCode(),
            ]);
        } catch (\Throwable) {
            // Product context is non-critical; swallowing here is intentional — the storefront
            // render must not be interrupted by an SDK enrichment failure.
            // The axitraceConfig block is already set; the SDK loader will still fire.
        }
    }
}
