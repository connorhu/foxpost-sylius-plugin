<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A `foxpost_same_as_billing` oszlop elhagyása.
 *
 * Az oszlopot a `FoxPostShipmentTrait` hordozta, a traitet pedig a #99
 * törölte: a szállítási cím a pénztár 1. lépésén dől el, a Sylius saját
 * `differentShippingAddress` kapcsolójával — a „megegyezik a számlázásival"
 * pipára nincs többé szükség. A `Version20260918000002` viszont továbbra is
 * létrehozza (az kiadott migráció, nem írjuk át), ezért egy FRISS telepítés
 * előbb felveszi, majd ez eldobja.
 *
 * Mindkét irány őrzött: egy olyan bolt, ami a saját migrációjával már
 * eldobta az oszlopot (a referencia-bolt ilyen), itt nem kap hibát.
 */
final class Version20261010000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'FoxPost: a foxpost_same_as_billing oszlop elhagyása (a trait a #99-ben megszűnt).';
    }

    public function up(Schema $schema): void
    {
        if ($schema->getTable('sylius_shipment')->hasColumn('foxpost_same_as_billing')) {
            $this->addSql('ALTER TABLE sylius_shipment DROP COLUMN foxpost_same_as_billing');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->getTable('sylius_shipment')->hasColumn('foxpost_same_as_billing')) {
            $this->addSql('ALTER TABLE sylius_shipment ADD foxpost_same_as_billing BOOLEAN DEFAULT true NOT NULL');
        }
    }
}
