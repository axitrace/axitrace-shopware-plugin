<?php

declare(strict_types=1);

namespace AxitraceShopware6\Storefront;

use AxitraceShopware6\Config\PluginConfig;
use AxitraceShopware6\Domain\DomainProof;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the store domain proof AxiTrace fetches after a claim (see DomainProof).
 * A machine endpoint: no session, login, CSRF token or cookie consent, answered
 * in maintenance mode too, never cached. The keys are those of the Sales Channel
 * Shopware resolved from the requested domain, the signature covers that host.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID]])]
final class DomainProofController
{
    public function __construct(private readonly PluginConfig $config)
    {
    }

    #[Route(
        path: '/axitrace/domain-proof',
        name: 'frontend.axitrace.domain-proof',
        defaults: [
            PlatformRequest::ATTRIBUTE_NO_STORE => true,
            PlatformRequest::ATTRIBUTE_HTTP_CACHE => false,
            PlatformRequest::ATTRIBUTE_IS_ALLOWED_IN_MAINTENANCE => true,
        ],
        methods: [Request::METHOD_GET],
    )]
    public function proof(Request $request, SalesChannelContext $context): JsonResponse
    {
        $salesChannelId = $context->getSalesChannelId();
        // all() never throws on an array value (?nonce[]=x), get() would.
        $nonce = $request->query->all()['nonce'] ?? '';
        $answer = DomainProof::answer(
            \is_string($nonce) ? $nonce : '',
            $request->getHost(),
            $this->config->getPublicKey($salesChannelId),
            $this->config->getSecretKey($salesChannelId),
        );

        $response = new JsonResponse($answer['body'], $answer['status']);
        $response->headers->set('Cache-Control', 'private, no-store, no-cache, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
