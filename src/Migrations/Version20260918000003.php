<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260918000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'FoxPost: a delivery_kind oszlop INT helyett VARCHAR(64) slug.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('foxpost_parcel')->getColumn('delivery_kind')->getType()->getName() !== 'integer',
            'A delivery_kind már slug.',
        );

        $this->addSql("ALTER TABLE foxpost_parcel ALTER COLUMN delivery_kind TYPE VARCHAR(64) USING CASE delivery_kind WHEN 1 THEN 'foxpost_home_delivery' WHEN 2 THEN 'foxpost_parcel_locker' END");
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('foxpost_parcel')->getColumn('delivery_kind')->getType()->getName() === 'integer',
            'A delivery_kind már integer.',
        );

        $this->addSql("ALTER TABLE foxpost_parcel ALTER COLUMN delivery_kind TYPE INT USING CASE delivery_kind WHEN 'foxpost_home_delivery' THEN 1 WHEN 'foxpost_parcel_locker' THEN 2 END");
    }
}
