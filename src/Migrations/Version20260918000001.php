<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260918000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'FoxPost: a foxpost_parcel tábla.';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf($schema->hasTable('foxpost_parcel'), 'A foxpost_parcel tábla már létezik.');

        $this->addSql('CREATE TABLE foxpost_parcel (
            id SERIAL NOT NULL,
            shipment_id INT NOT NULL,
            order_number VARCHAR(30) NOT NULL,
            delivery_kind VARCHAR(64) NOT NULL,
            shipping_method_code VARCHAR(100) DEFAULT NULL,
            barcode VARCHAR(50) DEFAULT NULL,
            recipient_name VARCHAR(150) NOT NULL,
            recipient_phone VARCHAR(30) NOT NULL,
            recipient_email VARCHAR(150) NOT NULL,
            destination_locker_id VARCHAR(20) DEFAULT NULL,
            recipient_zip VARCHAR(10) DEFAULT NULL,
            recipient_city VARCHAR(25) DEFAULT NULL,
            recipient_address VARCHAR(150) DEFAULT NULL,
            recipient_country VARCHAR(2) NOT NULL DEFAULT \'HU\',
            size VARCHAR(5) DEFAULT NULL,
            cod INT DEFAULT NULL,
            ref_code VARCHAR(30) DEFAULT NULL,
            comment VARCHAR(50) DEFAULT NULL,
            delivery_note VARCHAR(50) DEFAULT NULL,
            fragile BOOLEAN NOT NULL DEFAULT FALSE,
            internal_status VARCHAR(40) NOT NULL DEFAULT \'eligible\',
            foxpost_status VARCHAR(40) DEFAULT NULL,
            last_api_error TEXT DEFAULT NULL,
            last_api_error_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            retry_count SMALLINT NOT NULL DEFAULT 0,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            registered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            label_generated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');

        $this->addSql('CREATE INDEX idx_foxpost_parcel_barcode ON foxpost_parcel (barcode)');
        $this->addSql('CREATE INDEX idx_foxpost_parcel_status ON foxpost_parcel (internal_status)');
        $this->addSql('CREATE INDEX idx_foxpost_parcel_created ON foxpost_parcel (created_at)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_foxpost_parcel_barcode ON foxpost_parcel (barcode)');
        $this->addSql('ALTER TABLE foxpost_parcel ADD CONSTRAINT FK_foxpost_parcel_shipment
            FOREIGN KEY (shipment_id) REFERENCES sylius_shipment (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->skipIf(!$schema->hasTable('foxpost_parcel'), 'A foxpost_parcel tábla nincs meg.');

        $this->addSql('DROP TABLE foxpost_parcel');
    }
}
