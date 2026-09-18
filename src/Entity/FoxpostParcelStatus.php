<?php

declare(strict_types=1);

namespace CodeConjure\SyliusFoxPostPlugin\Entity;

enum FoxpostParcelStatus: string
{
    case Eligible = 'eligible';
    case ValidationFailed = 'validation_failed';
    case PendingRegistration = 'pending_registration';
    case RegistrationFailed = 'registration_failed';
    case Registered = 'registered';
    case LabelReady = 'label_ready';
    case Packed = 'packed';
    case HandedOver = 'handed_over';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case DeliveryFailed = 'delivery_failed';
    case Cancelled = 'cancelled';
    case Returned = 'returned';

    /** States where registration is already confirmed on FoxPost's side — block update-in-place. */
    public function isRegisteredOrBeyond(): bool
    {
        return in_array($this, [
            self::Registered,
            self::LabelReady,
            self::Packed,
            self::HandedOver,
            self::InTransit,
            self::Delivered,
        ], true);
    }

    /** Terminal states — no tracking sync needed. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Returned], true);
    }
}
