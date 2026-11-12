<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Unit\Checkout;

use CodeConjure\FoxPost\Client;
use CodeConjure\FoxPost\Environment;
use CodeConjure\SyliusFoxPostPlugin\CachedParcelLockerProvider;
use CodeConjure\SyliusFoxPostPlugin\Repository\FoxpostParcelRepository;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\AdminTwig;
use CodeConjure\SyliusFoxPostPlugin\Twig\FoxPostExtension;
use CodeConjure\SyliusFoxPostPlugin\Twig\FoxPostRuntime;
use Http\Mock\Client as MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Sylius\TwigHooks\Bag\DataBag;
use Sylius\TwigHooks\Bag\ScalarDataBag;
use Sylius\TwigHooks\Hook\Metadata\HookMetadata;
use Sylius\TwigHooks\Hookable\Metadata\HookableMetadata;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Forms;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

/**
 * A FoxPost csomagautomata-választója a pénztárban (#15).
 *
 * HOOKABLE a közös `codeconjure/sylius-carrier-bundle` widget-hookján
 * (`…shipments.shipment.carrier_fields`): a `pickupPointId` mezőt a közös
 * csomag adja, ez a sablon csak a választó felületét. A gyökérelem
 * `data-carrier-delivery-kinds` listája mondja meg a közös controllernek, hogy
 * csak a FoxPost automata-módnál látszódjon.
 */
final class PickupPointWidgetTest extends TestCase
{
    private const string TEMPLATE = '@CodeConjureSyliusFoxPostPlugin/shop/checkout/select_shipping/pickup_point_widget.html.twig';

    public function testTheWidgetDeclaresTheFoxPostLockerKindForTheCarrierController(): void
    {
        $root = $this->root($this->render(null));

        self::assertSame('foxpost_parcel_locker', $root->getAttribute('data-carrier-delivery-kinds'));
        self::assertSame('foxpost-pickup-point', $root->getAttribute('data-controller'));
    }

    public function testTheWidgetOpensTheFoxPostFinder(): void
    {
        $root = $this->root($this->render(null));

        self::assertSame('https://cdn.foxpost.hu/apt-finder/v1/app/', $root->getAttribute('data-foxpost-pickup-point-widget-url-value'));
        self::assertStringContainsString('click->foxpost-pickup-point#openWidget', $this->render(null));
    }

    public function testWithoutAStoredPointTheButtonInvitesToChoose(): void
    {
        $html = $this->render(null);

        self::assertStringContainsString('Csomagautomata kiválasztása', $html);
        self::assertStringNotContainsString('Teszt automata', $html);
    }

    public function testAStoredFoxPostPointIsShownByItsNameAndAddress(): void
    {
        $html = $this->render('HU1234');

        self::assertStringContainsString('Teszt automata', $html);
        self::assertStringContainsString('1011 Budapest, Fő utca 1.', $html);
        self::assertStringContainsString('Csomagautomata módosítása', $html);
    }

    public function testThePluginDoesNotRenderAPickupPointFieldOfItsOwn(): void
    {
        // A mező a közös csomagé: egy második renderelés a Symfonyban nem
        // rajzolna semmit, egy saját mező pedig felülírná a közöset.
        self::assertStringNotContainsString('<input', $this->render('HU1234'));
    }

    private function render(?string $storedPointId): string
    {
        $twig = AdminTwig::environment();
        $twig->addExtension(new FoxPostExtension());
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            FoxPostRuntime::class => fn (): FoxPostRuntime => new FoxPostRuntime(
                $this->lockerProvider(),
                self::createStub(FoxpostParcelRepository::class),
            ),
        ]));

        // A közös csomag mezője: rejtett `pickupPointId` a szállítmány-űrlapon.
        $form = Forms::createFormFactory()
            ->createNamedBuilder('shipment')
            ->add('pickupPointId', HiddenType::class, ['data' => $storedPointId])
            ->getForm()
            ->createView();
        $context = ['form' => $form, 'index' => 1];

        return $twig->render(self::TEMPLATE, [
            'hookable_metadata' => new HookableMetadata(
                new HookMetadata('sylius_shop.checkout.select_shipping.content.form.shipments.shipment.carrier_fields', new DataBag($context)),
                new DataBag($context),
                new ScalarDataBag([]),
                ['sylius_shop.checkout.select_shipping.content.form.shipments.shipment.carrier_fields'],
            ),
        ]);
    }

    /** Valódi szolgáltatás, mock HTTP-n: a FoxPost automata-listája egy elemmel. */
    private function lockerProvider(): CachedParcelLockerProvider
    {
        $psr17 = new Psr17Factory();
        $http = new MockHttpClient();
        $http->setDefaultResponse($psr17->createResponse(200)->withBody($psr17->createStream((string) json_encode([
            ['place_id' => 'HU1234', 'name' => 'Teszt automata', 'zip' => '1011', 'city' => 'Budapest', 'street' => 'Fő utca 1.'],
        ]))));

        return new CachedParcelLockerProvider(
            new Client($http, $psr17, $psr17, 'felhasznalo', 'jelszo', 'kulcs', Environment::Production),
            new ArrayAdapter(),
        );
    }

    private function root(string $html): \DOMElement
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
        $nodes = (new \DOMXPath($dom))->query('//*[@data-carrier-delivery-kinds]');
        self::assertInstanceOf(\DOMNodeList::class, $nodes);
        $node = $nodes->item(0);
        self::assertInstanceOf(\DOMElement::class, $node);

        return $node;
    }
}
