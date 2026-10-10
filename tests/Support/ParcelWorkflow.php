<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Tests\Support;

use Symfony\Component\Workflow\DefinitionBuilder;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * A `foxpost_parcel` állapotgép VALÓDI példánya, kernel nélkül — a plugin
 * SAJÁT `foxpost_parcel.yaml`-jából felépítve.
 *
 * Nem dublőr: ha egy szolgáltatás vagy controller rossz sorrendben hívna egy
 * átmenetet (pl. kihagyná a `queue`-t a `registration_fail` előtt), az ezzel
 * az objektummal valódi `Symfony\Component\Workflow\Exception\LogicException`
 * lesz, nem egy elnyelt `willReturn(true)`.
 *
 * Hogy maga a YAML helyes-e (a 20 átmenet neve, from-ja, to-ja), azt
 * SZÁNDÉKOSAN nem innen méri a csomag: a
 * `tests/Workflow/FoxPostParcelWorkflowDefinitionTest` egy FÜGGETLEN,
 * kézzel rögzített referencialistához hasonlítja — különben a mérés
 * önmagát igazolná.
 */
final class ParcelWorkflow
{
    public static function stateMachine(): WorkflowInterface
    {
        $config = self::configuration();

        /** @var list<string> $places */
        $places = $config['places'];

        // A több `from`-ú átmeneteket EGYENKÉNT kell felvenni, nem egy
        // többelemű `from` listával. A Symfony `Workflow::doCan()`-je ÉS
        // szemantikával dönt (minden `from` helyen állnia kell a tárgynak),
        // ezért egy `from: [registered, label_ready, packed]` átmenet egyetlen
        // `Transition`-ként SOHA nem engedélyezett. A host ugyanezt teszi: a
        // FrameworkExtension a state_machine átmeneteit `from`-onként bontja
        // szét — a plugin 14 konfigurált átmenete így 20 valódi átmenet (l.
        // FoxPostParcelWorkflowDefinitionTest).
        $transitions = [];
        /** @var array<string, array{from: list<string>, to: string}> $configured */
        $configured = $config['transitions'];
        foreach ($configured as $name => $transition) {
            foreach ($transition['from'] as $from) {
                $transitions[] = new Transition($name, $from, $transition['to']);
            }
        }

        $builder = new DefinitionBuilder($places, $transitions);

        /** @var string $initial */
        $initial = $config['initial_marking'];
        $builder->setInitialPlaces($initial);

        /** @var array{type: string, property: string} $markingStore */
        $markingStore = $config['marking_store'];

        return new StateMachine($builder->build(), new MethodMarkingStore(true, $markingStore['property']));
    }

    /** @return array<array-key, mixed> */
    private static function configuration(): array
    {
        return Yaml::at('src/Resources/config/app/foxpost_parcel.yaml', ['framework', 'workflows', 'foxpost_parcel']);
    }
}
