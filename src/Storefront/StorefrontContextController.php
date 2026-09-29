<?php

declare(strict_types=1);

namespace AxitraceShopware6\Storefront;

use AxitraceShopware6\Config\PluginConfig;
use AxitraceShopware6\Consent\ConsentGate;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
final class StorefrontContextController
{
    public function __construct(
        private readonly StorefrontContextProvider $provider,
        private readonly PluginConfig $config,
        private readonly ConsentGate $consentGate,
    ) {
    }

    #[Route(
        path: '/axitrace/storefront-context',
        name: 'frontend.axitrace.storefront-context',
        defaults: [PlatformRequest::ATTRIBUTE_NO_STORE => true],
        methods: [Request::METHOD_GET],
    )]
    public function context(Request $request, SalesChannelContext $context): JsonResponse
    {
        if (!$this->config->isEnabled($context->getSalesChannelId())) {
            return $this->response(['error' => 'not found'], JsonResponse::HTTP_NOT_FOUND);
        }

        if (!$this->isSameOrigin($request)) {
            return $this->response(['error' => 'same-origin request required'], JsonResponse::HTTP_FORBIDDEN);
        }

        $productId = trim((string) $request->query->get('productId', ''));
        if ($productId !== '' && !Uuid::isValid($productId)) {
            return $this->response(['error' => 'invalid productId'], JsonResponse::HTTP_BAD_REQUEST);
        }

        // The SDK sends the resolved workspace policy, not a fabricated visitor grant.
        // A local browser/all gate cannot be disabled by this request header.
        $includeCustomer = !$this->config->getConsentMode($context->getSalesChannelId())->requiresBrowserConsent()
            && $request->headers->get('X-AxiTrace-Consent-Required') === 'false';
        if (!$includeCustomer && $request->headers->get('X-AxiTrace-Consent') === ConsentGate::DECISION_GRANTED) {
            $cookieName = $this->config->getConsentCookieName($context->getSalesChannelId());
            $rawCookie = $request->cookies->get($cookieName);
            $includeCustomer = $this->consentGate->isGrantSignal(is_string($rawCookie) ? $rawCookie : null);
        }

        return $this->response($this->provider->load($context, $productId !== '' ? strtolower($productId) : null, $includeCustomer));
    }

    private function isSameOrigin(Request $request): bool
    {
        if (strtolower((string) $request->headers->get('Sec-Fetch-Site')) === 'cross-site') {
            return false;
        }
        $origin = $request->headers->get('Origin');

        return $origin === null || rtrim($origin, '/') === $request->getSchemeAndHttpHost();
    }

    private function response(array $data, int $status = JsonResponse::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
