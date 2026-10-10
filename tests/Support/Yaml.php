<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Support;

use RuntimeException;
use Symfony\Component\Yaml\Yaml as Parser;

/**
 * A plugin saját konfigurációs fájljainak beolvasása a tesztekhez.
 *
 * Azért van külön, mert a tesztek nem a YAML-t mérik, hanem azt, ami belőle
 * következik — egy elgépelt kulcsút így `RuntimeException`-nel áll le a
 * mérés helyén, nem `null`-ként csúszik tovább egy állításba.
 */
final class Yaml
{
    /** @return array<array-key, mixed> */
    public static function parse(string $relative): array
    {
        $path = \dirname(__DIR__, 2) . '/' . $relative;
        $parsed = Parser::parseFile($path);

        if (!\is_array($parsed)) {
            throw new RuntimeException(\sprintf('Nem kulcsfa: %s', $path));
        }

        return $parsed;
    }

    /**
     * @param list<string> $path
     *
     * @return array<array-key, mixed>
     */
    public static function at(string $relative, array $path): array
    {
        $node = self::parse($relative);

        foreach ($path as $key) {
            if (!\is_array($node) || !\array_key_exists($key, $node)) {
                throw new RuntimeException(\sprintf('A(z) %s fájlban nincs `%s` kulcsút.', $relative, implode('.', $path)));
            }

            $node = $node[$key];
        }

        if (!\is_array($node)) {
            throw new RuntimeException(\sprintf('A(z) %s `%s` kulcsútja nem kulcsfa.', $relative, implode('.', $path)));
        }

        return $node;
    }
}
