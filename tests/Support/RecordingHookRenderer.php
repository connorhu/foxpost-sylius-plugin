<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Support;

use Sylius\TwigHooks\Hook\Renderer\HookRendererInterface;

/**
 * A Twig Hooks renderelőjének felvevő dublőre: megjegyzi, MELYIK hook-nevet
 * és MILYEN kontextussal kérte a sablon, és hook-nevenként előre megadott
 * HTML-t ad vissza (alapból üreset — vagyis „senki nem kapcsolódott be").
 *
 * A `{% hook %}` tag a valódi `HooksRuntime`-on fut át (névnormalizálás,
 * `_prefixes`, kontextus-összefésülés); csak a legalsó réteg — a hookable-ek
 * konfigurációból való kikeresése — helyett áll ez az osztály.
 */
final class RecordingHookRenderer implements HookRendererInterface
{
    /** @var list<array{names: list<string>, context: array<string, mixed>}> */
    public array $calls = [];

    /** @param array<string, string> $outputByHookName */
    public function __construct(private readonly array $outputByHookName = [])
    {
    }

    public function render(array $hookNames, array $hookContext = []): string
    {
        $this->calls[] = ['names' => array_values($hookNames), 'context' => $hookContext];

        $html = '';
        foreach ($hookNames as $name) {
            $html .= $this->outputByHookName[$name] ?? '';
        }

        return $html;
    }
}
