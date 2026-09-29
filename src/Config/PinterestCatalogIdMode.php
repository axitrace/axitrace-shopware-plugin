<?php

declare(strict_types=1);

namespace AxitraceShopware6\Config;

enum PinterestCatalogIdMode: string
{
    case Legacy = 'legacy';
    case ProductNumber = 'product_number';
    case ProductNumberLowercase = 'product_number_lowercase';

    public static function fromConfigValue(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Legacy) : self::Legacy;
    }
}
