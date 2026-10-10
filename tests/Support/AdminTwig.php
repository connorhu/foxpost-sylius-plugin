<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Support;

use Symfony\Bridge\Twig\Extension\RoutingExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * A plugin admin sablonjainak futtatható környezete — a plugin SAJÁT
 * erőforrásaiból, dublo nélkül.
 *
 * Három dolog van benne, mert pontosan ez a három az, amin egy admin sablon
 * némán elhasalhat:
 *
 *  - a `@CodeConjureSyliusFoxPostPlugin` Twig-névtér (ugyanaz, amit a bundle
 *    `getPath()`-ja ad a hostban): egy elcsúszott sablonútvonal így
 *    `LoaderError`-t ad, nem üres cellát;
 *  - a `path()` a plugin VALÓDI route-gyűjteményén (l. [[PluginRoutes]]): egy
 *    nem létező route-név vagy egy hiányzó útvonal-paraméter itt bukik ki, nem
 *    az admin első kattintásánál;
 *  - a `trans` a plugin VALÓDI fordítási katalógusán: egy hiányzó kulcs így
 *    nyers kulcsként jelenik meg a kimenetben, és a teszt a lefordított
 *    szövegre tud állítani.
 *
 * A `strict_variables` SZÁNDÉKOSAN be van kapcsolva, és ez szigorúbb, mint a
 * befogadó bolt prod környezete. Ok: a Sylius grid-renderelő a sablonnak
 * `grid`, `action`, `data` és `options` változókat ad át — semmi mást. Egy
 * ezen kívüli változónév (`resource`, `ids`) prodban NEM hibázik, hanem
 * null-ként viselkedik, és a gomb vagy eltűnik, vagy rossz paraméterrel
 * generál URL-t. Ez a hibamód csak `strict_variables`-szel válik mérhetővé.
 */
final class AdminTwig
{
    public const string NAMESPACE = 'CodeConjureSyliusFoxPostPlugin';

    public const string LOCALE = 'hu';

    public static function environment(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(self::root() . '/templates', self::NAMESPACE);

        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension(self::translator()));
        $twig->addExtension(new RoutingExtension(PluginRoutes::generator()));

        return $twig;
    }

    public static function translator(): Translator
    {
        $translator = new Translator(self::LOCALE);
        $translator->addLoader('yaml', new YamlFileLoader());

        foreach (glob(self::root() . '/translations/*.' . self::LOCALE . '.yaml') ?: [] as $catalogue) {
            $domain = basename($catalogue, '.' . self::LOCALE . '.yaml');
            $translator->addResource('yaml', $catalogue, self::LOCALE, $domain);
        }

        return $translator;
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }
}
