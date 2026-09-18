import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'parcelLockerSection',
        'postalCode',
        'city',
        'pickupPointId',
        'phoneNumber',
        'recipientPhoneSection',
    ];

    static values = {
        cityLookupUrl: String,
    };

    connect() {
        this.element.addEventListener('change', (event) => {
            if (event.target.type === 'radio') {
                this._updateVisibility();
            }
        });
        this._updateVisibility();
    }

    methodChanged() {
        this._updateVisibility();
    }

    _getSelectedDeliveryKind() {
        const checkedRadio = this.element.querySelector('input[type="radio"]:checked');
        if (!checkedRadio) {
            return null;
        }
        const card = checkedRadio.closest('[data-delivery-kind]');
        return card ? card.dataset.deliveryKind : null;
    }

    _getSelectedRequiresRecipientPhone() {
        const checkedRadio = this.element.querySelector('input[type="radio"]:checked');
        if (!checkedRadio) {
            return false;
        }

        // A `data-delivery-kind` attribútum CSAK akkor kerül ki, ha a módnak
        // van FoxPost kindja — a `data-requires-recipient-phone` viszont
        // MINDIG ('1' vagy '0'), tehát a `closest()` itt mindig talál.
        const card = checkedRadio.closest('[data-requires-recipient-phone]');

        return card ? card.dataset.requiresRecipientPhone === '1' : false;
    }

    _updateVisibility() {
        const kind = this._getSelectedDeliveryKind();

        if (this.hasParcelLockerSectionTarget) {
            this.parcelLockerSectionTarget.hidden = kind !== 'foxpost_parcel_locker';
        }

        if (this.hasRecipientPhoneSectionTarget) {
            this.recipientPhoneSectionTarget.hidden = !this._getSelectedRequiresRecipientPhone();
        }

        if (kind === 'foxpost_parcel_locker') {
            this._initFoxPostWidget();
        }
    }

    async lookupCity() {
        if (!this.hasPostalCodeTarget || !this.hasCityTarget) {
            return;
        }

        const postalCode = this.postalCodeTarget.value.trim();
        if (postalCode.length < 4) {
            return;
        }

        const countrySelect = this.element.querySelector('select[id$="_countryCode"]');
        const countryCode = countrySelect ? countrySelect.value : 'HU';
        if (!countryCode) {
            return;
        }

        try {
            const url = `${this.cityLookupUrlValue}?country=${encodeURIComponent(countryCode)}&postalCode=${encodeURIComponent(postalCode)}`;
            const response = await fetch(url);
            if (response.ok) {
                const data = await response.json();
                this.cityTarget.value = data.cityName;
            }
        } catch {
            // Silently fail — user can type city manually
        }
    }

    foxpostPickerCallback(point) {
        if (this.hasPickupPointIdTarget) {
            this.pickupPointIdTarget.value = point.place_id ?? point.foxpost_id ?? '';
        }
    }

    _initFoxPostWidget() {
        if (typeof window.foxpost === 'undefined') {
            return;
        }

        window.foxpost.widget.open({
            targetElement: this.element.querySelector('#foxpost-widget-container') ?? this.element,
            callbackFunction: (point) => this.foxpostPickerCallback(point),
        });
    }
}
