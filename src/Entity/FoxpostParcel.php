<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Entity;

use CodeConjure\FoxPost\DeliveryKind;
use CodeConjure\SyliusFoxPostPlugin\Repository\FoxpostParcelRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Resource\Model\ResourceInterface;

#[ORM\Entity(repositoryClass: FoxpostParcelRepository::class)]
#[ORM\Table(name: 'foxpost_parcel')]
#[ORM\Index(columns: ['barcode'], name: 'idx_foxpost_parcel_barcode')]
#[ORM\Index(columns: ['internal_status'], name: 'idx_foxpost_parcel_status')]
#[ORM\Index(columns: ['created_at'], name: 'idx_foxpost_parcel_created')]
class FoxpostParcel implements ResourceInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    protected ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ShipmentInterface::class)]
    #[ORM\JoinColumn(name: 'shipment_id', nullable: false, onDelete: 'RESTRICT')]
    private ShipmentInterface $shipment;

    #[ORM\Column(name: 'order_number', type: Types::STRING, length: 30)]
    private string $orderNumber;

    #[ORM\Column(name: 'delivery_kind', type: Types::STRING, length: 64, enumType: DeliveryKind::class)]
    private DeliveryKind $deliveryKind;

    #[ORM\Column(name: 'shipping_method_code', type: Types::STRING, length: 100, nullable: true)]
    private ?string $shippingMethodCode = null;

    #[ORM\Column(name: 'barcode', type: Types::STRING, length: 50, nullable: true, unique: true)]
    private ?string $barcode = null;

    // --- Recipient snapshot ---

    #[ORM\Column(name: 'recipient_name', type: Types::STRING, length: 150)]
    private string $recipientName;

    #[ORM\Column(name: 'recipient_phone', type: Types::STRING, length: 30)]
    private string $recipientPhone;

    #[ORM\Column(name: 'recipient_email', type: Types::STRING, length: 150)]
    private string $recipientEmail;

    // --- APM ---

    #[ORM\Column(name: 'destination_locker_id', type: Types::STRING, length: 20, nullable: true)]
    private ?string $destinationLockerId = null;

    // --- HD ---

    #[ORM\Column(name: 'recipient_zip', type: Types::STRING, length: 10, nullable: true)]
    private ?string $recipientZip = null;

    #[ORM\Column(name: 'recipient_city', type: Types::STRING, length: 25, nullable: true)]
    private ?string $recipientCity = null;

    #[ORM\Column(name: 'recipient_address', type: Types::STRING, length: 150, nullable: true)]
    private ?string $recipientAddress = null;

    #[ORM\Column(name: 'recipient_country', type: Types::STRING, length: 2, options: ['default' => 'HU'])]
    private string $recipientCountry = 'HU';

    // --- Parcel options ---

    #[ORM\Column(name: 'size', type: Types::STRING, length: 5, nullable: true)]
    private ?string $size = null;

    #[ORM\Column(name: 'cod', type: Types::INTEGER, nullable: true)]
    private ?int $cod = null;

    #[ORM\Column(name: 'ref_code', type: Types::STRING, length: 30, nullable: true)]
    private ?string $refCode = null;

    #[ORM\Column(name: 'comment', type: Types::STRING, length: 50, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(name: 'delivery_note', type: Types::STRING, length: 50, nullable: true)]
    private ?string $deliveryNote = null;

    #[ORM\Column(name: 'fragile', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $fragile = false;

    // --- State ---

    #[ORM\Column(name: 'internal_status', type: Types::STRING, length: 40, enumType: FoxpostParcelStatus::class)]
    private FoxpostParcelStatus $internalStatus = FoxpostParcelStatus::Eligible;

    #[ORM\Column(name: 'foxpost_status', type: Types::STRING, length: 40, nullable: true)]
    private ?string $foxpostStatus = null;

    #[ORM\Column(name: 'last_api_error', type: Types::TEXT, nullable: true)]
    private ?string $lastApiError = null;

    #[ORM\Column(name: 'last_api_error_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastApiErrorAt = null;

    #[ORM\Column(name: 'retry_count', type: Types::SMALLINT, options: ['default' => 0])]
    private int $retryCount = 0;

    // --- Timestamps ---

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'registered_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $registeredAt = null;

    #[ORM\Column(name: 'label_generated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $labelGeneratedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShipment(): ShipmentInterface
    {
        return $this->shipment;
    }

    public function setShipment(ShipmentInterface $shipment): void
    {
        $this->shipment = $shipment;
    }

    public function getOrderNumber(): string
    {
        return $this->orderNumber;
    }

    public function setOrderNumber(string $orderNumber): void
    {
        $this->orderNumber = $orderNumber;
    }

    public function getDeliveryKind(): DeliveryKind
    {
        return $this->deliveryKind;
    }

    public function setDeliveryKind(DeliveryKind $deliveryKind): void
    {
        $this->deliveryKind = $deliveryKind;
    }

    public function getShippingMethodCode(): ?string
    {
        return $this->shippingMethodCode;
    }

    public function setShippingMethodCode(?string $shippingMethodCode): void
    {
        $this->shippingMethodCode = $shippingMethodCode;
    }

    public function getBarcode(): ?string
    {
        return $this->barcode;
    }

    public function setBarcode(?string $barcode): void
    {
        $this->barcode = $barcode;
    }

    public function getRecipientName(): string
    {
        return $this->recipientName;
    }

    public function setRecipientName(string $recipientName): void
    {
        $this->recipientName = $recipientName;
    }

    public function getRecipientPhone(): string
    {
        return $this->recipientPhone;
    }

    public function setRecipientPhone(string $recipientPhone): void
    {
        $this->recipientPhone = $recipientPhone;
    }

    public function getRecipientEmail(): string
    {
        return $this->recipientEmail;
    }

    public function setRecipientEmail(string $recipientEmail): void
    {
        $this->recipientEmail = $recipientEmail;
    }

    public function getDestinationLockerId(): ?string
    {
        return $this->destinationLockerId;
    }

    public function setDestinationLockerId(?string $destinationLockerId): void
    {
        $this->destinationLockerId = $destinationLockerId;
    }

    public function getRecipientZip(): ?string
    {
        return $this->recipientZip;
    }

    public function setRecipientZip(?string $recipientZip): void
    {
        $this->recipientZip = $recipientZip;
    }

    public function getRecipientCity(): ?string
    {
        return $this->recipientCity;
    }

    public function setRecipientCity(?string $recipientCity): void
    {
        $this->recipientCity = $recipientCity;
    }

    public function getRecipientAddress(): ?string
    {
        return $this->recipientAddress;
    }

    public function setRecipientAddress(?string $recipientAddress): void
    {
        $this->recipientAddress = $recipientAddress;
    }

    public function getRecipientCountry(): string
    {
        return $this->recipientCountry;
    }

    public function setRecipientCountry(string $recipientCountry): void
    {
        $this->recipientCountry = $recipientCountry;
    }

    public function getSize(): ?string
    {
        return $this->size;
    }

    public function setSize(?string $size): void
    {
        $this->size = $size;
    }

    public function getCod(): ?int
    {
        return $this->cod;
    }

    public function setCod(?int $cod): void
    {
        $this->cod = $cod;
    }

    public function getRefCode(): ?string
    {
        return $this->refCode;
    }

    public function setRefCode(?string $refCode): void
    {
        $this->refCode = $refCode;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): void
    {
        $this->comment = $comment;
    }

    public function getDeliveryNote(): ?string
    {
        return $this->deliveryNote;
    }

    public function setDeliveryNote(?string $deliveryNote): void
    {
        $this->deliveryNote = $deliveryNote;
    }

    public function isFragile(): bool
    {
        return $this->fragile;
    }

    public function setFragile(bool $fragile): void
    {
        $this->fragile = $fragile;
    }

    public function getInternalStatus(): FoxpostParcelStatus
    {
        return $this->internalStatus;
    }

    public function setInternalStatus(FoxpostParcelStatus $internalStatus): void
    {
        $this->internalStatus = $internalStatus;
    }

    public function getFoxpostStatus(): ?string
    {
        return $this->foxpostStatus;
    }

    public function setFoxpostStatus(?string $foxpostStatus): void
    {
        $this->foxpostStatus = $foxpostStatus;
    }

    public function getLastApiError(): ?string
    {
        return $this->lastApiError;
    }

    public function setLastApiError(?string $lastApiError): void
    {
        $this->lastApiError = $lastApiError;
    }

    public function getLastApiErrorAt(): ?\DateTimeImmutable
    {
        return $this->lastApiErrorAt;
    }

    public function setLastApiErrorAt(?\DateTimeImmutable $lastApiErrorAt): void
    {
        $this->lastApiErrorAt = $lastApiErrorAt;
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function incrementRetryCount(): void
    {
        ++$this->retryCount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    public function getRegisteredAt(): ?\DateTimeImmutable
    {
        return $this->registeredAt;
    }

    public function setRegisteredAt(?\DateTimeImmutable $registeredAt): void
    {
        $this->registeredAt = $registeredAt;
    }

    public function getLabelGeneratedAt(): ?\DateTimeImmutable
    {
        return $this->labelGeneratedAt;
    }

    public function setLabelGeneratedAt(?\DateTimeImmutable $labelGeneratedAt): void
    {
        $this->labelGeneratedAt = $labelGeneratedAt;
    }
}
