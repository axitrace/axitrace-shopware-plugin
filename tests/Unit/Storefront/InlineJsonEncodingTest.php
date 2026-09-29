<?php

declare(strict_types=1);

namespace AxitraceShopware6\Tests\Unit\Storefront;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class InlineJsonEncodingTest extends TestCase
{
    public function testHtmlSignificantCharactersCannotTerminateApplicationJsonScript(): void
    {
        $twig = new Environment(new ArrayLoader([
            'json' => '<script type="application/json">{{ value | json_encode(15) | raw }}</script>',
        ]));
        $attack = '</script><script>window.pwned=true</script> & "quoted" \'apostrophe\'';

        $rendered = $twig->render('json', ['value' => ['name' => $attack]]);

        self::assertSame(1, substr_count($rendered, '</script>'), 'Only the template-owned closing tag may remain literal.');
        self::assertStringNotContainsString('<script>window.pwned', $rendered);
        self::assertStringContainsString('\\u003C\\/script\\u003E', $rendered);

        $json = substr($rendered, strpos($rendered, '>') + 1, -strlen('</script>'));
        self::assertSame($attack, json_decode($json, true, 512, JSON_THROW_ON_ERROR)['name']);
    }

    public function testEveryInlineDataBlockUsesTheSafeFlags(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../src/Resources/views/storefront/layout/meta.html.twig');
        self::assertIsString($template);
        self::assertSame(3, substr_count($template, 'json_encode(15)'), 'Config, product and checkout blocks must all use JSON_HEX flags.');
        self::assertStringNotContainsString('json_encode(960)', $template);
    }
}
