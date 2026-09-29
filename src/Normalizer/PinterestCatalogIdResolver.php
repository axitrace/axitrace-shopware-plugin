<?php

declare(strict_types=1);

namespace AxitraceShopware6\Normalizer;

use AxitraceShopware6\Config\PinterestCatalogIdMode;

final class PinterestCatalogIdResolver
{
    public function resolve(PinterestCatalogIdMode $mode, string $productNumber, string $productId): ?string
    {
        if ($mode === PinterestCatalogIdMode::Legacy) {
            return null;
        }

        $productNumber = trim($productNumber);
        if ($productNumber !== '') {
            return $mode === PinterestCatalogIdMode::ProductNumberLowercase
                ? strtolower($productNumber)
                : $productNumber;
        }

        $productId = strtolower(trim($productId));

        return $productId !== '' ? $productId : null;
    }
}
