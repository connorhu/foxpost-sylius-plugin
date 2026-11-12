import { Application } from '@hotwired/stimulus';
import { afterEach, describe, expect, test } from 'vitest';
import FoxPostPickupPointController from '../controllers/foxpost_pickup_point_controller.js';

// A DOM a plugin `pickup_point_widget.html.twig`-je; a widget a közös
// `carrier-shipment` hatókörben ül (#15), a mezőt az a controller írja.
function render() {
    document.body.innerHTML = `
        <div id="scope">
            <div data-carrier-delivery-kinds="foxpost_parcel_locker"
                 data-controller="foxpost-pickup-point"
                 data-foxpost-pickup-point-widget-url-value="https://cdn.foxpost.hu/apt-finder/v1/app/">
                <div data-foxpost-pickup-point-target="display" class="mb-2 d-none">
                    <span data-foxpost-pickup-point-target="pointName"></span>
                    <span data-foxpost-pickup-point-target="pointAddress"></span>
                </div>
                <button type="button" data-foxpost-pickup-point-target="button" data-action="click->foxpost-pickup-point#openWidget">Csomagautomata kiválasztása</button>
            </div>
        </div>`;
}

let application;
let selections;

async function start() {
    render();
    selections = [];
    document.getElementById('scope').addEventListener('carrier:pickup-point-selected', (e) => selections.push(e.detail));
    application = Application.start();
    application.register('foxpost-pickup-point', FoxPostPickupPointController);
    await Promise.resolve();
}

function message(origin, data) {
    window.dispatchEvent(new MessageEvent('message', { origin, data }));
}

const POINT = { place_id: 'HU1234', name: 'Teszt automata', zip: '1011', city: 'Budapest', street: 'Fő utca 1.' };

// A controller a `window`-on figyel: a DOM eltávolítása után egy macrotaskot
// várni kell, hogy a Stimulus MutationObserver-e lebontsa (`disconnect()`),
// különben a listener átszivárog a következő tesztbe. A puszta
// `application.stop()` ezt nem teszi meg szinkron.
afterEach(async () => {
    document.body.innerHTML = '';
    await new Promise((resolve) => setTimeout(resolve, 0));
    application?.stop();
});

describe('a FoxPost választó', () => {
    test('a gomb megnyitja a FoxPost térképét', async () => {
        await start();
        document.querySelector('button').click();

        expect(document.querySelector('iframe').src).toBe('https://cdn.foxpost.hu/apt-finder/v1/app/');
    });

    test('a választott pontot a közös esemény adja át — azonosító és név', async () => {
        await start();
        message('https://cdn.foxpost.hu', JSON.stringify(POINT));

        expect(selections).toEqual([{ id: 'HU1234', label: 'Teszt automata' }]);
    });

    test('a választott pont megjelenik a választóban', async () => {
        await start();
        message('https://cdn.foxpost.hu', POINT);

        expect(document.querySelector('[data-foxpost-pickup-point-target="pointName"]').textContent).toBe('Teszt automata');
        expect(document.querySelector('[data-foxpost-pickup-point-target="pointAddress"]').textContent).toBe('1011 Budapest, Fő utca 1.');
        expect(document.querySelector('[data-foxpost-pickup-point-target="display"]').classList.contains('d-none')).toBe(false);
    });

    test('a választás után a térkép bezárul', async () => {
        await start();
        document.querySelector('button').click();
        message('https://cdn.foxpost.hu', POINT);

        expect(document.querySelector('iframe')).toBeNull();
    });

    test('egy a FoxPost nevét csak TARTALMAZÓ origin üzenetét eldobja', async () => {
        // `origin.includes('foxpost.hu')` ezt átengedte volna.
        await start();
        message('https://cdn.foxpost.hu.tamado.example', POINT);

        expect(selections).toEqual([]);
        expect(document.querySelector('[data-foxpost-pickup-point-target="pointName"]').textContent).toBe('');
    });

    test('idegen origin üzenetét eldobja', async () => {
        await start();
        message('https://example.com', POINT);

        expect(selections).toEqual([]);
        expect(document.querySelector('[data-foxpost-pickup-point-target="pointName"]').textContent).toBe('');
    });

    test('azonosító nélküli üzenetet eldob', async () => {
        await start();
        message('https://cdn.foxpost.hu', { name: 'névtelen' });

        expect(selections).toEqual([]);
    });
});
