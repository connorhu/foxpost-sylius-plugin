<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Unit\Admin;

use CodeConjure\SyliusFoxPostPlugin\Tests\Support\AdminTwig;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\FoxPostShipment;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\FoxPostShippingMethod;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\RecordingHookRenderer;
use CodeConjure\SyliusFoxPostPlugin\Twig\FoxPostExtension;
use PHPUnit\Framework\TestCase;
use Sylius\TwigHooks\Hook\Normalizer\Name\CompositeNameNormalizer;
use Sylius\TwigHooks\Hook\Normalizer\Prefix\CompositePrefixNormalizer;
use Sylius\TwigHooks\Hook\Renderer\Debug\HookDebugCommentRenderer;
use Sylius\TwigHooks\Hook\Renderer\HookRendererInterface;
use Sylius\TwigHooks\Twig\HooksExtension;
use Sylius\TwigHooks\Twig\Runtime\HooksRuntime;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\FormView;
use Twig\RuntimeLoader\FactoryRuntimeLoader;
use Twig\TwigFunction;

/**
 * Az admin szállítmány-panel `ship` slotja SZÁLLÍTÓ-SEMLEGES (#15).
 *
 * A host egyetlen `props.template`-et állít be a
 * `sylius_admin.order.show.content.sections.shipments.item.actions` → `ship`
 * hookable-re, és az ma a FoxPost sablonja. Egy második szállító-plugin
 * (GLS, Packeta) ugyanezt a sort kérné, és az egyik némán kiütné a másikat.
 *
 * A szerződés: ha a szállítmány NEM FoxPost-os, a sablon a
 * {@see self::CARRIER_HOOK} hookot rendereli, a szállítmány adataival; ha
 * abba egyetlen plugin sem rajzolt semmit, a Sylius alapgombja jön.
 */
final class ShipActionCarrierSlotTest extends TestCase
{
    private const string CARRIER_HOOK = 'codeconjure_carrier.admin.order.shipment.ship';

    private const string TEMPLATE = '@CodeConjureSyliusFoxPostPlugin/admin/shipment/ship.html.twig';

    public function testAnotherCarrierRendersItsOwnActionInsteadOfTheStockButton(): void
    {
        $hooks = new RecordingHookRenderer([self::CARRIER_HOOK => '<button>GLS feladás</button>']);

        $html = $this->render($hooks, $this->shipment('gls_parcel_locker'));

        self::assertStringContainsString('<button>GLS feladás</button>', $html);
        self::assertStringNotContainsString('STOCK-SYLIUS-SHIP-BUTTON', $html);
    }

    public function testTheStockButtonStaysWhenNoCarrierClaimsTheShipment(): void
    {
        $html = $this->render(new RecordingHookRenderer(), $this->shipment(null));

        self::assertStringContainsString('STOCK-SYLIUS-SHIP-BUTTON', $html);
    }

    /**
     * `kernel.debug` mellett (dev, test) a Twig Hooks a renderelőt a
     * `HookDebugCommentRenderer`-rel dekorálja, ami egy ÜRES hookot is két
     * HTML-kommenttel ad vissza. Ha a sablon ezt „tartalomnak" venné, dev-ben
     * eltűnne a Sylius alapgombja.
     */
    public function testDebugCommentsAloneDoNotCountAsACarrierAction(): void
    {
        $html = $this->render(new HookDebugCommentRenderer(new RecordingHookRenderer()), $this->shipment(null));

        self::assertStringContainsString('STOCK-SYLIUS-SHIP-BUTTON', $html);
    }

    public function testACarrierActionStillWinsUnderDebugComments(): void
    {
        $hooks = new HookDebugCommentRenderer(new RecordingHookRenderer([self::CARRIER_HOOK => '<button>GLS feladás</button>']));

        $html = $this->render($hooks, $this->shipment('gls_parcel_locker'));

        self::assertStringContainsString('<button>GLS feladás</button>', $html);
        self::assertStringNotContainsString('STOCK-SYLIUS-SHIP-BUTTON', $html);
    }

    public function testTheCarrierHookReceivesTheShipmentAndItsForm(): void
    {
        $hooks = new RecordingHookRenderer();
        $shipment = $this->shipment('gls_home_delivery');

        $this->render($hooks, $shipment);

        self::assertCount(1, $hooks->calls);
        self::assertSame([self::CARRIER_HOOK], $hooks->calls[0]['names']);
        self::assertSame($shipment, $hooks->calls[0]['context']['resource']);
        self::assertInstanceOf(FormView::class, $hooks->calls[0]['context']['form']);
        self::assertArrayHasKey('attributes', $hooks->calls[0]['context']);
    }

    public function testAFoxPostShipmentDoesNotAskOtherCarriers(): void
    {
        $hooks = new RecordingHookRenderer();

        $this->render($hooks, $this->shipment('foxpost_parcel_locker'));

        self::assertSame([], $hooks->calls);
    }

    private function render(HookRendererInterface $hooks, FoxPostShipment $shipment): string
    {
        // A Sylius alapgombja helyett egy jelölő-sablon: a teszt azt méri,
        // hogy a FoxPost-sablon MIKOR esik vissza rá, nem a gomb tartalmát.
        $twig = AdminTwig::environment([
            'SyliusAdmin' => \dirname(__DIR__, 2) . '/Fixtures/templates/SyliusAdmin',
        ]);
        $twig->addExtension(new HooksExtension());
        $twig->addExtension(new FormExtension());
        $twig->addExtension(new FoxPostExtension());
        // A FoxPost-ág gombjához kellene; a teszt a NEM FoxPost-ágot méri,
        // a FoxPost-ágban pedig csak azt, hogy a hookot nem kérdezi.
        $twig->addFunction(new TwigFunction('sylius_sm_can', static fn (): bool => false));
        // A sablon fordításához kellenek (a Sylius és a Symfony UX adja őket a hostban).
        $twig->addFunction(new TwigFunction('sylius_test_html_attribute', static fn (): string => '', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('ux_icon', static fn (): string => '', ['is_safe' => ['html']]));
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            HooksRuntime::class => static fn (): HooksRuntime => new HooksRuntime(
                $hooks,
                new CompositeNameNormalizer([]),
                new CompositePrefixNormalizer([]),
                true,
            ),
            FormRenderer::class => static fn (): FormRenderer => new FormRenderer(new TwigRendererEngine([], $twig)),
        ]));

        return $twig->render(self::TEMPLATE, [
            'resource' => $shipment,
            'form' => self::formView(),
            'attributes' => 'data-live-id="ship-1"',
        ]);
    }

    /** Egy valódi űrlap nézete mindig hordozza a `cache_key`-t; a `form_theme` erre indexel. */
    private static function formView(): FormView
    {
        $view = new FormView();
        $view->vars['cache_key'] = '_sylius_shipment_ship';

        return $view;
    }

    private function shipment(?string $deliveryKindSlug): FoxPostShipment
    {
        return new FoxPostShipment(
            deliveryKindSlug: $deliveryKindSlug,
            method: new FoxPostShippingMethod(deliveryKindSlug: $deliveryKindSlug),
        );
    }
}
