<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * The settings form is what a merchant reads. Shopware's administration saves
 * plugin settings as entered (plaintext in `system_config`), so no help text
 * may promise encryption at rest.
 */
final class ConfigXmlTest extends TestCase
{
    public function testNoHelpTextClaimsEncryptionAtRest(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../src/Resources/config/config.xml');
        self::assertNotFalse($xml);

        foreach ($xml->xpath('//helpText') ?: [] as $helpText) {
            self::assertDoesNotMatchRegularExpression('/encrypt|verschlüssel/i', (string) $helpText);
        }
    }
}
