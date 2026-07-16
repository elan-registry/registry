(function () {
    function showMapError(el) {
        el.replaceChildren();
        const wrap = document.createElement('div');
        wrap.className = 'd-flex flex-column align-items-center justify-content-center h-100 text-muted';
        const msg = document.createElement('p');
        msg.className = 'mb-2';
        msg.textContent = 'Map unavailable. Please try refreshing.';
        const btn = document.createElement('button');
        btn.className = 'btn btn-sm btn-outline-secondary';
        btn.type = 'button';
        btn.textContent = 'Retry';
        btn.addEventListener('click', function () { location.reload(); });
        wrap.appendChild(msg);
        wrap.appendChild(btn);
        el.appendChild(wrap);
    }

    const mapEl = document.getElementById('map');

    if (typeof maplibregl === 'undefined') {
        if (mapEl) { showMapError(mapEl); }
        return;
    }

    const lat = window.carDetailsMapConfig.lat;
    const lon = window.carDetailsMapConfig.lon;
    const series = window.carDetailsMapConfig.series;

    function getSeriesClass(name) {
        const s = name.toLowerCase();
        if (s.includes('sprint')) return 'sprint';
        if (s.includes('+2'))     return 'plus2';
        if (s.includes('s1'))     return 's1';
        if (s.includes('s2'))     return 's2';
        if (s.includes('s3'))     return 's3';
        if (s.includes('s4'))     return 's4';
        return 'unknown';
    }
    const seriesClass = getSeriesClass(series);

    const map = new maplibregl.Map({
        container: 'map',
        style: window.carDetailsMapConfig.styleUrl,
        center: [lon, lat],
        zoom: 8,
        scrollZoom: false,
        attributionControl: false
    });

    map.addControl(new maplibregl.AttributionControl({ compact: true }), 'bottom-right');
    map.once('idle', function () {
        var attrEl = document.querySelector('#map .maplibregl-ctrl-attrib');
        if (attrEl) attrEl.classList.remove('maplibregl-compact-show');
    });
    map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
    map.addControl(new maplibregl.ScaleControl({ unit: 'metric' }), 'bottom-left');

    map.on('load', function () {
        const el = document.createElement('div');
        el.className = 'elan-marker-wrapper';
        const dot = document.createElement('div');
        dot.className = 'elan-marker ' + seriesClass;
        el.appendChild(dot);

        new maplibregl.Marker({ element: el, anchor: 'bottom' })
            .setLngLat([lon, lat])
            .addTo(map);
    });

    map.on('error', function (e) {
        // Tile and source load errors are transient — let MapLibre retry
        if (e.sourceId !== undefined || (e.error && typeof e.error.status === 'number')) {
            console.warn('[ElanRegistry] Map tile/source error (non-fatal):', e.error);
            return;
        }
        console.error('[ElanRegistry] Fatal map error on car details page:', e.error);
        if (mapEl) { showMapError(mapEl); }
    });
}());
