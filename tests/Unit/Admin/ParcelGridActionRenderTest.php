<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Unit\Admin;

use CodeConjure\SyliusFoxPostPlugin\Entity\FoxpostParcelStatus;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\AdminTwig;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\ParcelFixture;
use CodeConjure\SyliusFoxPostPlugin\Tests\Support\ParcelGrid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\GridBundle\Form\Registry\FormTypeRegistryInterface;
use Sylius\Bundle\GridBundle\Parser\OptionsParser;
use Sylius\Bundle\GridBundle\Renderer\TwigBulkActionGridRenderer;
use Sylius\Bundle\GridBundle\Renderer\TwigGridRenderer;
use Sylius\Component\Grid\Definition\Action;
use Sylius\Component\Grid\Definition\Grid;
use Sylius\Component\Grid\FieldTypes\FieldTypeInterface;
use Sylius\Component\Grid\Parameters;
use Sylius\Component\Grid\View\GridView;
use Sylius\Component\Registry\ServiceRegistry;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * A #198 másik fele: az admin csomaglista öt akciója.
 *
 * A kiszervezéskor a grid akciói `type: links` + `options.template` párral
 * kerültek át a boltból. Mérve: ez a páros NEM azt teszi, amit a név sugall.
 * A Sylius akció-renderelője (`TwigGridRenderer::renderAction()`) a sablont
 * `$action->getTemplate()`-ből veszi — vagyis az akció `template:` kulcsából,
 * NEM az `options.template`-ből. Ha ott nincs semmi, a típushoz regisztrált
 * sablonra esik vissza; a `links` típusnál ez a Sylius saját
 * `@SyliusAdmin/shared/grid/action/links.html.twig`-je, ami az
 * `options.links` listát járja be. A FoxPost gridje ilyen listát nem ad,
 * tehát a gombok helyén üres legördülő jelent meg, a plugin öt saját
 * akció-sablonja pedig **halott kód** volt: soha nem renderelte semmi.
 *
 * A második, ugyanolyan néma hiba a sablonokban volt: `resource.…` és `ids`
 * változókra hivatkoztak, a renderelő viszont `grid`, `action`, `data`
 * (+ a resource-bundle dekorátoránál `options`) változókat ad át — mást nem.
 * Prodban (`strict_variables: false`) ez nem hibázik: a `resource` null, a
 * státusz-feltételek fordítva sülnek el, és a `path()` null azonosítóval
 * generál URL-t.
 *
 * A teszt ezért három dolgot méri, mind a VALÓDI Sylius renderelőkkel:
 *
 *  1. minden akciót a plugin SAJÁT sablonja rendel (nem a Sylius generikus
 *     fallbackja) — a sablon-hozzárendelést a plugin `sylius_grid.templates`
 *     konfigurációjából olvassuk, ahogy a host is;
 *  2. a megrendelt sablon kirenderel, és a plugin VALÓDI route-jaira mutató
 *     URL-t ír (l. [[PluginRoutes]]);
 *  3. az állapothoz kötött gombok a helyes állapotoknál jelennek meg.
 *
 * Amit tudatosan NEM mér: az akció-sablonok a `renderAction()` ALAP
 * változókészletével futnak, az `options` nélkül. A hostban a
 * `Sylius\Bundle\ResourceBundle\Grid\Renderer\TwigGridRenderer` dekorátor még
 * egy `options` változót is átad (a `ResourceGridView`-n keresztül) — a
 * plugin sablonjai szándékosan nem használják, így a teszt a szigorúbb
 * szerződésre állít.
 */
final class ParcelGridActionRenderTest extends TestCase
{
    private const string PLUGIN_TEMPLATE_PREFIX = '@CodeConjureSyliusFoxPostPlugin/';

    /**
     * Akciócsoport és -név → az a részlet, aminek a kirenderelt gombban
     * szerepelnie kell. Mind a hat a plugin valódi route-jából generált URL.
     *
     * @var array<string, array{group: string, expected: string, status: FoxpostParcelStatus}>
     */
    private const array EXPECTED_MARKUP = [
        'register' => [
            'group' => 'item',
            'expected' => 'action="/admin/foxpost/parcels/register/' . ParcelFixture::SHIPMENT_ID . '"',
            'status' => FoxpostParcelStatus::RegistrationFailed,
        ],
        'label' => [
            'group' => 'item',
            'expected' => 'href="/admin/foxpost/labels/download/' . ParcelFixture::PARCEL_ID . '"',
            'status' => FoxpostParcelStatus::LabelReady,
        ],
        'cancel' => [
            'group' => 'item',
            'expected' => 'action="/admin/foxpost/parcels/' . ParcelFixture::PARCEL_ID . '/cancel"',
            'status' => FoxpostParcelStatus::Registered,
        ],
        'bulk/register' => [
            'group' => 'bulk',
            'expected' => 'action="/admin/foxpost/parcels/bulk-register"',
            'status' => FoxpostParcelStatus::RegistrationFailed,
        ],
        'bulk/label' => [
            'group' => 'bulk',
            'expected' => 'action="/admin/foxpost/labels/download-bulk"',
            'status' => FoxpostParcelStatus::LabelReady,
        ],
    ];

    #[DataProvider('actionProvider')]
    public function testEveryActionIsRenderedByAPluginTemplate(string $key, string $group, Action $action): void
    {
        self::assertStringStartsWith(
            self::PLUGIN_TEMPLATE_PREFIX,
            self::templateFor($group, $action),
            \sprintf(
                'A `%s` akciót nem a plugin sablonja rendeli. A Sylius a sablont az akció `template:` ' .
                'kulcsából vagy a típushoz regisztrált sablonból veszi — az `options.template` kulcsot ' .
                'nem olvassa, az ilyen akció némán a Sylius generikus sablonjára esik vissza.',
                $key,
            ),
        );
    }

    #[DataProvider('actionProvider')]
    public function testEveryActionRendersAUrlOfARealPluginRoute(string $key, string $group, Action $action): void
    {
        self::assertArrayHasKey(
            $key,
            self::EXPECTED_MARKUP,
            \sprintf('A `%s` akció benne van a grid.yaml-ban, de nincs hozzá mért elvárás ebben a tesztben.', $key),
        );

        $expectation = self::EXPECTED_MARKUP[$key];

        self::assertStringContainsString(
            $expectation['expected'],
            $this->render($group, $action, ParcelFixture::parcel($expectation['status'])),
        );
    }

    /** @return iterable<string, array{string, string, Action}> */
    public static function actionProvider(): iterable
    {
        foreach (self::definition()->getActionGroups() as $group) {
            foreach ($group->getActions() as $action) {
                $key = $group->getName() === 'item' ? $action->getName() : $group->getName() . '/' . $action->getName();

                yield $key => [$key, $group->getName(), $action];
            }
        }
    }

    /**
     * A feladás-gomb csak akkor jelenik meg, ha a csomag feladása MÉG NEM
     * sikerült — különben egy admin kattintással újra feladhatna egy már
     * feladott csomagot (a FoxPostnál pénzbe kerülő művelet).
     */
    #[DataProvider('registerVisibilityProvider')]
    public function testTheRegisterButtonAppearsOnlyBeforeASuccessfulRegistration(
        FoxpostParcelStatus $status,
        bool $expected,
    ): void {
        $rendered = $this->render('item', self::action('item', 'register'), ParcelFixture::parcel($status));

        self::assertSame(
            $expected,
            str_contains($rendered, '/admin/foxpost/parcels/register/'),
            \sprintf('A `%s` állapotú csomagon a feladás-gomb megjelenése rossz.', $status->value),
        );
    }

    /** @return iterable<string, array{FoxpostParcelStatus, bool}> */
    public static function registerVisibilityProvider(): iterable
    {
        foreach (FoxpostParcelStatus::cases() as $status) {
            yield $status->value => [$status, !$status->isRegisteredOrBeyond()];
        }
    }

    /**
     * A sztornó-gomb csak addig jelenik meg, amíg a FoxPost oldalán a csomag
     * még törölhető: feladva, címke kész, csomagolva. Átadás után a
     * `cancel` hívás a FoxPost API-n hibát adna.
     */
    #[DataProvider('cancelVisibilityProvider')]
    public function testTheCancelButtonAppearsOnlyWhileTheParcelIsStillCancellable(
        FoxpostParcelStatus $status,
        bool $expected,
    ): void {
        $rendered = $this->render('item', self::action('item', 'cancel'), ParcelFixture::parcel($status));

        self::assertSame(
            $expected,
            str_contains($rendered, '/cancel"'),
            \sprintf('A `%s` állapotú csomagon a sztornó-gomb megjelenése rossz.', $status->value),
        );
    }

    /** @return iterable<string, array{FoxpostParcelStatus, bool}> */
    public static function cancelVisibilityProvider(): iterable
    {
        $cancellable = [
            FoxpostParcelStatus::Registered,
            FoxpostParcelStatus::LabelReady,
            FoxpostParcelStatus::Packed,
        ];

        foreach (FoxpostParcelStatus::cases() as $status) {
            yield $status->value => [$status, \in_array($status, $cancellable, true)];
        }
    }

    /**
     * A címke-gomb vonalkód nélkül nem jelenhet meg: a címke a vonalkódra
     * hivatkozik, és a controller is visszafordít vonalkód nélkül.
     */
    public function testTheLabelButtonIsHiddenWithoutABarcode(): void
    {
        $rendered = $this->render(
            'item',
            self::action('item', 'label'),
            ParcelFixture::parcel(FoxpostParcelStatus::Registered, null),
        );

        self::assertSame('', trim($rendered));
    }

    /**
     * A tömeges címke-gomb a Sylius admin saját, GENERIKUS tömeges
     * mechanizmusát használja: a `data-bulk-delete="index"` attribútumot
     * hordozó formhoz a Sylius `bulk-delete.js`-e a bejelölt sorok
     * azonosítóit `ids[]` inputként csatolja (mérve a
     * `SyliusAdminBundle/Resources/assets/scripts/bulk-delete.js`-ben; a
     * `data-check-all-group="index"` jelölőket a Sylius
     * `shared/helper/table.html.twig`-je teszi ki, és az értékük a sor
     * `id`-ja). A csoportnév ezért nem szabadon választott, és a
     * `FoxPostLabelController::downloadBulk()` is pont `ids`-t olvas.
     */
    public function testTheBulkLabelFormUsesTheAdminBulkSelectionMechanism(): void
    {
        $rendered = $this->render('bulk', self::action('bulk', 'label'), null);

        self::assertStringContainsString('data-bulk-delete="index"', $rendered);
        self::assertStringContainsString('method="post"', $rendered);
    }

    /**
     * A két tömeges akció UGYANAZT a kijelölést olvassa, tehát ugyanazt is
     * kapja: a Sylius `bulk-delete.js`-e a `data-bulk-delete` értékével
     * megadott jelölő-csoport bejelölt elemeinek értékét csatolja `ids[]`-ben,
     * és a sor-jelölők értéke a SOR azonosítója. Két különböző csoportnév (vagy
     * egy hiányzó attribútum) azt jelentené, hogy a két gomb máshonnan szedi
     * az azonosítókat — pedig egyetlen kijelölés van a lapon.
     *
     * Ez a #208 szerződésének felületi fele: a `bulkRegister()` és a
     * `downloadBulk()` is CSOMAG-azonosítót olvas, mert a grid sorai csomagok.
     */
    public function testBothBulkActionsReadTheSameRowSelection(): void
    {
        $groups = [];

        foreach (self::definition()->getActionGroup('bulk')->getActions() as $action) {
            $rendered = $this->render('bulk', $action, null);

            self::assertMatchesRegularExpression(
                '/<form[^>]+method="post"/',
                $rendered,
                \sprintf('A `bulk/%s` akció nem POST formot rendel.', $action->getName()),
            );

            $group = self::selectionGroupOf($rendered);

            self::assertNotNull(
                $group,
                \sprintf(
                    'A `bulk/%s` akció formján nincs `data-bulk-delete` attribútum, tehát a Sylius ' .
                    '`bulk-delete.js`-e nem csatol hozzá `ids[]` inputot: a gomb azonosítók nélkül küld.',
                    $action->getName(),
                ),
            );

            $groups[$action->getName()] = $group;
        }

        self::assertGreaterThan(1, \count($groups), 'Ez a teszt legalább két tömeges akciót mér.');
        self::assertCount(
            1,
            array_unique($groups),
            'A tömeges akciók különböző jelölő-csoportból olvasnák az azonosítókat: ' . json_encode($groups),
        );
        self::assertSame(
            ['index'],
            array_values(array_unique($groups)),
            'A csoportnév nem szabadon választott: a Sylius `shared/helper/table.html.twig`-je ' .
            '`index` néven teszi ki a sor-jelölőket, és az értékük a sor `id`-ja.',
        );
    }

    /** A form `data-bulk-delete` attribútumának értéke — a jelölő-csoport neve. */
    private static function selectionGroupOf(string $rendered): ?string
    {
        if (preg_match('/data-bulk-delete="([^"]+)"/', $rendered, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function render(string $group, Action $action, mixed $data): string
    {
        $twig = AdminTwig::environment();
        $templates = self::configuredTemplates($group);

        if ($group === 'bulk') {
            return (new TwigBulkActionGridRenderer($twig, $templates))
                ->renderBulkAction($this->gridView(), $action, $data);
        }

        return (new TwigGridRenderer(
            $twig,
            new ServiceRegistry(FieldTypeInterface::class, 'grid field'),
            $this->createStub(FormFactoryInterface::class),
            $this->createStub(FormTypeRegistryInterface::class),
            '@SyliusGrid/_grid.html.twig',
            $templates,
            [],
            new OptionsParser(),
        ))->renderAction($this->gridView(), $action, $data);
    }

    private function gridView(): GridView
    {
        return new GridView([ParcelFixture::parcel()], self::definition(), new Parameters());
    }

    private static function templateFor(string $group, Action $action): string
    {
        $template = $action->getTemplate() ?? self::configuredTemplates($group)[$action->getType()] ?? null;

        self::assertIsString(
            $template,
            \sprintf(
                'A `%s` akció `%s` típusához nincs sablon sem az akción, sem a plugin ' .
                '`sylius_grid.templates.%s` konfigurációjában — a Sylius renderelője ' .
                '"Missing template for action type" kivétellel állna le.',
                $action->getName(),
                $action->getType(),
                $group === 'bulk' ? 'bulk_action' : 'action',
            ),
        );

        return $template;
    }

    /** @return array<string, string> */
    private static function configuredTemplates(string $group): array
    {
        return ParcelGrid::actionTemplates($group === 'bulk' ? 'bulk_action' : 'action');
    }

    private static function action(string $group, string $name): Action
    {
        return self::definition()->getActionGroup($group)->getAction($name);
    }

    private static function definition(): Grid
    {
        return ParcelGrid::definition();
    }
}
