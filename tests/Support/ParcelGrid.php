<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Support;

use RuntimeException;
use Sylius\Component\Grid\Definition\ArrayToDefinitionConverter;
use Sylius\Component\Grid\Definition\Grid;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Az admin csomaglista grid-definíciója, a plugin SAJÁT `grid.yaml`-jából, a
 * Sylius valódi `ArrayToDefinitionConverter`-ével — ugyanazzal a lépéssel,
 * amit a host a konténer fordításakor elvégez.
 *
 * Egy helyen van, mert a mező- és az akció-renderelés tesztje is ugyanezt a
 * definíciót kéri.
 */
final class ParcelGrid
{
    public const string CODE = 'app_admin_foxpost_parcel';

    private const string FILE = 'src/Resources/config/app/grid.yaml';

    public static function definition(): Grid
    {
        /**
         * @var array{
         *     driver: array{name: string, options?: array<string, mixed>},
         *     sorting?: array<string, string>,
         *     limits?: array<int>,
         *     fields?: array<string, array{type: string, label?: string, path?: string, enabled?: bool, sortable?: bool|string, position?: int, options?: array<string, mixed>}>,
         *     filters?: array<string, array{type: string, label?: string, template?: string, enabled?: bool, position?: int, options?: array<string, mixed>, form_options?: array<string, mixed>, default_value?: mixed}>,
         *     actions?: array<string, array<string, array{type: string, label?: string, template?: string, icon?: string, enabled?: bool, position?: int, options?: array<string, mixed>}>>,
         * } $configuration a `grid.yaml` alakja — a konverter ezt a szerződést kéri
         */
        $configuration = Yaml::at(self::FILE, ['sylius_grid', 'grids', self::CODE]);

        return (new ArrayToDefinitionConverter(new EventDispatcher()))->convert(self::CODE, $configuration);
    }

    /**
     * A plugin akció-sablon regisztrációja — ugyanaz a konfiguráció, amit a
     * `prepend()` a host `sylius_grid` extensionjének átad.
     *
     * @param 'action'|'bulk_action' $group
     *
     * @return array<string, string>
     */
    public static function actionTemplates(string $group): array
    {
        $templates = [];

        foreach (Yaml::at(self::FILE, ['sylius_grid', 'templates', $group]) as $type => $template) {
            if (!\is_string($type) || !\is_string($template)) {
                throw new RuntimeException(\sprintf('A `sylius_grid.templates.%s` nem típus → sablon leképezés.', $group));
            }

            $templates[$type] = $template;
        }

        return $templates;
    }
}
