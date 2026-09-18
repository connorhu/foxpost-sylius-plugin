import { Controller } from '@hotwired/stimulus';

const WIDGET_ORIGIN_PROD = 'https://cdn.foxpost.hu';
const WIDGET_ORIGIN_TEST = 'https://cdn.foxpost.hu'; // sandbox is also served from CDN

export default class extends Controller {
    static targets = ['input', 'display', 'pointName', 'pointAddress', 'button'];
    static values = { widgetUrl: String };

    #modal = null;
    #iframe = null;
    #messageListener = null;

    connect() {
        this.#messageListener = (event) => this.#handleMessage(event);
        window.addEventListener('message', this.#messageListener);
    }

    disconnect() {
        window.removeEventListener('message', this.#messageListener);
        this.#closeModal();
    }

    openWidget() {
        if (this.#modal) {
            this.#closeModal();
        }

        this.#modal = this.#buildModal();
        document.body.appendChild(this.#modal);
    }

    // -------------------------------------------------------------------------

    #buildModal() {
        const overlay = document.createElement('div');
        overlay.style.cssText = [
            'position:fixed', 'inset:0', 'z-index:9999',
            'background:rgba(0,0,0,.55)', 'display:flex',
            'align-items:center', 'justify-content:center',
        ].join(';');

        const box = document.createElement('div');
        box.style.cssText = [
            'position:relative', 'width:min(90%,96vw)',
            'height:min(90%,92vh)', 'background:#fff',
            'border-radius:8px', 'overflow:hidden',
        ].join(';');

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.textContent = '✕';
        closeBtn.setAttribute('aria-label', 'Bezárás');
        closeBtn.style.cssText = [
            'position:absolute', 'top:8px', 'right:10px',
            'z-index:1', 'background:none', 'border:none',
            'font-size:1.4rem', 'cursor:pointer', 'line-height:1',
        ].join(';');
        closeBtn.addEventListener('click', () => this.#closeModal());

        this.#iframe = document.createElement('iframe');
        this.#iframe.src = this.widgetUrlValue;
        this.#iframe.style.cssText = 'width:100%;height:100%;border:none;';
        this.#iframe.allow = 'geolocation';

        box.appendChild(closeBtn);
        box.appendChild(this.#iframe);
        overlay.appendChild(box);

        // Overlay kattintásra bezár (de az iframe-en belüli kattintás nem buborékozik fel)
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                this.#closeModal();
            }
        });

        return overlay;
    }

    #closeModal() {
        this.#modal?.remove();
        this.#modal = null;
        this.#iframe = null;
    }

    #handleMessage(event) {
        // Csak a FoxPost CDN-ről fogadjunk üzeneteket
        if (!event.origin.includes('foxpost.hu')) {
            return;
        }

        const data = this.#parseMessageData(event.data);
        if (!data) {
            return;
        }

        const pointId = data.place_id ?? data.foxpost_id ?? data.id ?? null;
        const pointName = data.name ?? data.place_name ?? data.operator_name ?? null;
        const pointAddress = data.address ?? this.#buildAddress(data);

        if (!pointId) {
            return;
        }

        this.#applySelection(String(pointId), pointName, pointAddress);
        this.#closeModal();
    }

    #parseMessageData(raw) {
        if (typeof raw === 'object' && raw !== null) {
            // Direkt objektum vagy { type, point } wrapper
            return raw.point ?? raw;
        }

        if (typeof raw === 'string') {
            try {
                const parsed = JSON.parse(raw);
                return parsed.point ?? parsed;
            } catch {
                return null;
            }
        }

        return null;
    }

    #buildAddress(data) {
        const zip = data.zip ?? data.postal_code ?? '';
        const city = data.city ?? '';
        const street = data.street ?? data.place_address ?? '';
        return [zip, city ? `${city},` : '', street].filter(Boolean).join(' ').trim() || null;
    }

    #applySelection(pointId, pointName, pointAddress) {
        // Hidden input frissítése
        if (this.hasInputTarget) {
            this.inputTarget.value = pointId;
        } else {
            // Ha nincs explicit target, az első hidden input-ot keressük
            const hidden = this.element.querySelector('input[type="hidden"]');
            if (hidden) {
                hidden.value = pointId;
            }
        }

        // Megjelenítési szöveg frissítése
        if (this.hasPointNameTarget) {
            this.pointNameTarget.textContent = pointName ?? pointId;
        }

        if (this.hasPointAddressTarget) {
            this.pointAddressTarget.textContent = pointAddress ?? '';
        }

        if (this.hasDisplayTarget) {
            this.displayTarget.classList.remove('d-none');
        }

        // Gomb szövege frissítése
        if (this.hasButtonTarget) {
            this.buttonTarget.querySelector('i')?.nextSibling?.remove();
            this.buttonTarget.append(
                document.createTextNode(this.buttonTarget.dataset.labelChange ?? ' Módosítás'),
            );
        }
    }
}
