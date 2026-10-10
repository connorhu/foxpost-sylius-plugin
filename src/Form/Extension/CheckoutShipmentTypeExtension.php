<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Form\Extension;

use CodeConjure\SyliusFoxPostPlugin\Form\Type\FoxPostPickupPointSelectType;
use Sylius\Bundle\CoreBundle\Form\Type\Checkout\ShipmentType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * A pénztár 2. lépésének FoxPost-mezői: a címzett telefonja és a
 * csomagautomata (#199).
 *
 * A #116 kiszervezéskor ez a két mező tulajdonosi döntéssel a BOLTBAN maradt,
 * mert a bolt saját telefonszám-infrastruktúrájára épült, és a #99 úgyis
 * átírta az egész mezőkészletet. Következmény: egy másik Sylius bolt, ami
 * feltelepítette a plugint, NEM látta a FoxPost-mezőket a pénztárban, amíg
 * maga nem replikálta őket — a kiszervezés üzleti célja a pénztár lépésén
 * félig teljesült. Ez a kiterjesztés zárja be a rést.
 *
 * ## A két mező ALAPÉRTELMEZÉS, nem szerződés
 *
 * A `priority` nem díszítés: a Symfony `FormPass` a `form.type_extension`
 * tagokat `findAndSortTaggedServices()`-szel rendezi (csökkenő prioritás), a
 * `ResolvedFormType::buildForm()` pedig ebben a sorrendben hívja őket — tehát
 * a KÉSŐBB futó kiterjesztés `->add()`-je írja felül a korábbit. Ennek a
 * kiterjesztésnek ezért magas prioritása van: így a host egy sima, 0
 * prioritású form kiterjesztéssel felülírhatja bármelyik mezőt (típust,
 * címkét, constrainteket), anélkül, hogy a pluginnak bármilyen
 * konfigurációs felületet kellene adnia erre.
 *
 * Prioritás nélkül a sorrend fordítva sülne el: a bundle szolgáltatásai a
 * `MergeExtensionConfigurationPass`-ban kerülnek a konténerbe, tehát a host
 * `services.yaml`-ja UTÁN — holtversenyben a plugin nyerne, és csendben
 * lecserélné a host dúsabb mezőjét a sajátjára.
 *
 * A referencia-bolt pontosan ezt az utat járja: a `pickupPointId`-t innen
 * kapja, a `phoneNumber`-t pedig felülírja a saját, magyar mobilszámra
 * szűkített validátorával.
 *
 * ## Miért `TelType` a telefonmező alapértelmezése
 *
 * Egy telefonszám-validátor országonként más, és a #115 szerint a bolt saját
 * telefonszám-komponense (a host `Components\Phone`-ja) opcionális
 * kiszervezési jelölt — nem ez az issue dönt róla. A plugin ezért azt adja, ami minden boltban helyes: egy
 * `tel` beviteli mezőt és azt az EGY szabályt, ami a FoxPostból következik
 * (a feladáshoz kell telefonszám, ha a mód kéri). A szigorítás a hosté.
 */
final class CheckoutShipmentTypeExtension extends AbstractTypeExtension
{
    /**
     * A Symfony beépített kiterjesztéseinek prioritása 0 (a CSRF-é -10), a
     * Syliusé is 0 — ez a szám csak annyit mond, hogy a plugin BIZTOSAN a
     * host elé fusson. Őr: tests/Unit/Form/CheckoutShipmentFieldsTest.
     */
    public const int PRIORITY = 16;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // A csomagautomata-azonosító. A típus a plugin sajátja (rejtett mező +
        // a FoxPost automatakereső widget URL-je), tehát host nélkül is
        // működik; a láthatóságot a Stimulus controller kapcsolja.
        $builder->add('pickupPointId', FoxPostPickupPointSelectType::class, [
            'label' => 'codeconjure.foxpost.checkout.pickup_point_id',
            'required' => false,
            'constraints' => [
                new NotBlank(
                    message: 'codeconjure.foxpost.checkout.pickup_point_id.not_blank',
                    groups: [SelectShippingTypeExtension::FOXPOST_PARCEL_LOCKER_VALIDATION_GROUP],
                ),
            ],
        ]);

        // A címzett telefonja. A `data-foxpost-shipping-target` attribútum nem
        // elhagyható: a plugin Stimulus controllere ezen a horgon találja meg
        // a mezőt, hogy a házhozszállítás/automata váltásnál kezelje.
        $builder->add('phoneNumber', TelType::class, [
            'label' => 'codeconjure.foxpost.checkout.phone_number.label',
            'help' => 'codeconjure.foxpost.checkout.phone_number.help',
            'required' => false,
            'attr' => ['data-foxpost-shipping-target' => 'phoneNumber'],
            'constraints' => [
                new NotBlank(
                    message: 'codeconjure.foxpost.checkout.phone_number.not_blank',
                    groups: [SelectShippingTypeExtension::RECIPIENT_PHONE_VALIDATION_GROUP],
                ),
            ],
        ]);
    }

    public static function getExtendedTypes(): iterable
    {
        return [ShipmentType::class];
    }
}
