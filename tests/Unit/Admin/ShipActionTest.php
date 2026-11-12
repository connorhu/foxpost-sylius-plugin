<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Unit\Admin;

use CodeConjure\SyliusFoxPostPlugin\Tests\Support\AdminTwig;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\FoxPostShipment;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\FoxPostShippingMethod;
use CodeConjure\SyliusFoxPostPlugin\Twig\FoxPostExtension;
use PHPUnit\Framework\TestCase;
use Sylius\TwigHooks\Bag\DataBag;
use Sylius\TwigHooks\Bag\ScalarDataBag;
use Sylius\TwigHooks\Hook\Metadata\HookMetadata;
use Sylius\TwigHooks\Hookable\Metadata\HookableMetadata;
use Sylius\TwigHooks\Twig\HooksExtension;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Forms;
use Twig\Markup;
use Twig\RuntimeLoader\FactoryRuntimeLoader;
use Twig\TwigFunction;

/**
 * A FoxPost feladás-gombja az admin szállítmány-panelen (#15).
 *
 * A `ship` slotot a közös `codeconjure/sylius-carrier-bundle` sablonja
 * tölti, és minden szállító a `codeconjure_carrier.admin.order.shipment.ship`
 * hookba kapcsolódik. Ez a sablon tehát HOOKABLE: a kontextusból olvas, és
 * CSAK a FoxPost-módoknál rajzol — máshol semmit, hogy a közös sablon a többi
 * szállítóra, vagy a Sylius alapgombjára tudjon esni.
 */
final class ShipActionTest extends TestCase
{
    private const string TEMPLATE = '@CodeConjureSyliusFoxPostPlugin/admin/shipment/ship.html.twig';

    public function testAFoxPostShipmentGetsTheRegisterButton(): void
    {
        $html = $this->render($this->shipment('foxpost_parcel_locker'), canShip: true);

        self::assertStringContainsString('/foxpost/parcels/register/' . \CodeConjure\SyliusFoxPostPlugin\Tests\Support\ParcelFixture::SHIPMENT_ID, $html);
        self::assertStringContainsString('data-live-id="ship-1"', $html);
    }

    public function testAShipmentThatCannotBeShippedGetsNothing(): void
    {
        self::assertSame('', trim($this->render($this->shipment('foxpost_parcel_locker'), canShip: false)));
    }

    public function testAnotherCarriersShipmentGetsNothing(): void
    {
        // Üres kimenet → a közös sablon a többi szállítóra / a Sylius
        // alapgombjára esik vissza.
        self::assertSame('', trim($this->render($this->shipment('gls_parcel_locker'), canShip: true)));
    }

    private function render(FoxPostShipment $shipment, bool $canShip): string
    {
        // A Sylius admin form-témája helyett egy üres téma: a teszt a gomb
        // MEGJELENÉSÉT méri, nem a stílusát.
        $twig = AdminTwig::environment(['SyliusAdmin' => \dirname(__DIR__, 2) . '/Fixtures/templates/SyliusAdmin']);
        $twig->addExtension(new HooksExtension());
        $twig->addExtension(new FormExtension());
        $twig->addExtension(new FoxPostExtension());
        $twig->addFunction(new TwigFunction('sylius_sm_can', static fn (): bool => $canShip));
        $twig->addFunction(new TwigFunction('sylius_test_html_attribute', static fn (): string => '', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('ux_icon', static fn (): string => '', ['is_safe' => ['html']]));
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            FormRenderer::class => static fn (): FormRenderer => new FormRenderer(new TwigRendererEngine(['form_div_layout.html.twig'], $twig)),
        ]));

        // A Sylius ship-űrlap alakja: egy névvel ellátott űrlap `_token` mezővel.
        $form = Forms::createFormFactory()
            ->createNamedBuilder('sylius_shipment_ship')
            ->add('_token', HiddenType::class, ['data' => 'csrf'])
            ->getForm()
            ->createView();

        // A hostban a LiveComponent `ComponentAttributes`-e, amit a Symfony UX
        // biztonságos osztályként regisztrál — a teszt ugyanezt `Markup`-pal adja.
        $attributes = new Markup('data-live-id="ship-1"', 'UTF-8');
        $context = ['resource' => $shipment, 'form' => $form, 'attributes' => $attributes];

        return $twig->render(self::TEMPLATE, [
            'hookable_metadata' => new HookableMetadata(
                new HookMetadata('codeconjure_carrier.admin.order.shipment.ship', new DataBag($context)),
                new DataBag($context),
                new ScalarDataBag([]),
                ['codeconjure_carrier.admin.order.shipment.ship'],
            ),
        ]);
    }

    private function shipment(string $kind): FoxPostShipment
    {
        return new FoxPostShipment(deliveryKindSlug: $kind, method: new FoxPostShippingMethod(deliveryKindSlug: $kind));
    }
}
