<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260918000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'FoxPost: a három trait-oszlop (sylius_channel, sylius_shipping_method, sylius_shipment).';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->getTable('sylius_channel')->hasColumn('foxpost_label_page_size')) {
            $this->addSql('ALTER TABLE sylius_channel ADD foxpost_label_page_size VARCHAR(10) DEFAULT NULL');
        }

        if (!$schema->getTable('sylius_shipping_method')->hasColumn('foxpost_default_size')) {
            $this->addSql('ALTER TABLE sylius_shipping_method ADD foxpost_default_size VARCHAR(5) DEFAULT NULL');
        }

        if (!$schema->getTable('sylius_shipment')->hasColumn('foxpost_same_as_billing')) {
            $this->addSql('ALTER TABLE sylius_shipment ADD foxpost_same_as_billing BOOLEAN DEFAULT true NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->getTable('sylius_shipment')->hasColumn('foxpost_same_as_billing')) {
            $this->addSql('ALTER TABLE sylius_shipment DROP foxpost_same_as_billing');
        }

        if ($schema->getTable('sylius_shipping_method')->hasColumn('foxpost_default_size')) {
            $this->addSql('ALTER TABLE sylius_shipping_method DROP COLUMN foxpost_default_size');
        }

        if ($schema->getTable('sylius_channel')->hasColumn('foxpost_label_page_size')) {
            $this->addSql('ALTER TABLE sylius_channel DROP COLUMN foxpost_label_page_size');
        }
    }
}
