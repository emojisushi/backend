(function () {
    'use strict';

    var REFRESH_MS = 30000;

    var map, zoneLayer, pinLayer, zonesVisible = true, timer = null;
    var orders = [], activeKey = null, markers = {};

    function initMap(el) {
        map = L.map(el).setView([46.4825, 30.7233], 12); // Odesa

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap'
        }).addTo(map);

        zoneLayer = L.layerGroup().addTo(map);
        pinLayer = L.layerGroup().addTo(map);

        // The container is often still hidden or zero-height when the backend
        // swaps pages over AJAX, which is what leaves Leaflet with grey tiles.
        setTimeout(function () { map.invalidateSize(); }, 50);
    }

    function loadZones() {
        $.request('onLoadAreas', {
            success: function (data) {
                zoneLayer.clearLayers();

                (data.areas || []).forEach(function (area) {
                    if (!area.coords || !area.coords.length) { return; }

                    L.polygon(area.coords, {
                        color: area.color || '#3577f6',
                        weight: 2,
                        fillOpacity: 0.07
                    }).bindTooltip(area.name || '').addTo(zoneLayer);
                });
            }
        });
    }

    function toggleZones() {
        zonesVisible = !zonesVisible;

        if (zonesVisible) {
            map.addLayer(zoneLayer);
        } else {
            map.removeLayer(zoneLayer);
        }

        $('#dmToggleZones').text(zonesVisible ? 'Скрыть зоны' : 'Показать зоны');
    }

    function loadOrders(silent, fit) {
        if (!silent) {
            $('#dmOrders').html('<div class="dm-empty">Загрузка…</div>');
        }

        $.request('onLoadOrders', {
            data: { spot_id: $('#dmSpot').val(), date: $('#dmDate').val() },
            success: function (data) {
                if (data.error) {
                    $('#dmOrders').html('<div class="dm-empty">Ошибка: ' + escapeHtml(data.error) + '</div>');
                    return;
                }

                orders = data.orders || [];
                fillCourierFilter(data.couriers || []);
                render(fit);
                $('#dmUpdated').text('обновлено ' + new Date().toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }));
            },
            error: function () {
                if (!silent) {
                    $('#dmOrders').html('<div class="dm-empty">Не удалось загрузить заказы</div>');
                }
            }
        });
    }

    /**
     * Options come from the courier registry, so every courier is selectable even
     * on a day they have no orders. Any id on an order without a registry entry is
     * appended too, which makes a missing mapping visible rather than silent.
     */
    function fillCourierFilter(registered) {
        var select = $('#dmCourier'), current = select.val(), seen = {};

        select.html('<option value="all">Все курьеры</option><option value="unassigned">Без курьера</option>');

        registered.forEach(function (c) {
            seen[c.id] = true;
            select.append('<option value="' + c.id + '">' + escapeHtml(c.name) + '</option>');
        });

        orders.forEach(function (o) {
            if (o.courier_id && !seen[o.courier_id]) {
                seen[o.courier_id] = true;
                select.append('<option value="' + o.courier_id + '">' + escapeHtml(o.courier) + '</option>');
            }
        });

        select.val(current && select.find('option[value="' + current + '"]').length ? current : 'all');
    }

    function visibleOrders() {
        var filter = $('#dmCourier').val();

        return orders.filter(function (o) {
            if (filter === 'all') { return true; }
            if (filter === 'unassigned') { return !o.courier_id; }
            return String(o.courier_id) === String(filter);
        }).sort(function (a, b) {
            // Not accepted first — those need attention before anything else.
            return (a.pending_accept ? 0 : 1) - (b.pending_accept ? 0 : 1) ||
                (a.courier_id ? 1 : 0) - (b.courier_id ? 1 : 0) ||
                String(a.due).localeCompare(String(b.due));
        });
    }

    /**
     * Orders on the same street share coordinates, so a group would collapse into
     * a single pin. Spread duplicates evenly around a small circle instead.
     */
    function spread(list) {
        var groups = {};

        list.forEach(function (o) {
            o.pinLat = o.lat;
            o.pinLng = o.lng;

            if (o.lat === null || o.lng === null) { return; }

            var key = o.lat.toFixed(5) + ',' + o.lng.toFixed(5);
            (groups[key] = groups[key] || []).push(o);
        });

        Object.keys(groups).forEach(function (key) {
            var group = groups[key];

            if (group.length < 2) { return; }

            var radius = 0.00016 * (1 + Math.floor(group.length / 9));
            var scale = Math.cos(group[0].lat * Math.PI / 180) || 1;

            group.forEach(function (o, i) {
                var angle = (2 * Math.PI * i) / group.length;
                o.pinLat = o.lat + radius * Math.cos(angle);
                o.pinLng = o.lng + (radius * Math.sin(angle)) / scale;
            });
        });
    }

    function render(fit) {
        var list = visibleOrders();

        spread(list);
        $('#dmCount').text(list.length + ' / ' + orders.length);
        renderList(list);
        renderPins(list, fit);
    }

    function renderList(list) {
        if (!list.length) {
            $('#dmOrders').html('<div class="dm-empty">Нет открытых заказов</div>');
            return;
        }

        var html = list.map(function (o) {
            var state = o.pending_accept ? 'pending' : (o.courier_id ? 'assigned' : '');

            return '<div class="dm-order ' + state + (activeKey === o.key ? ' active' : '') +
                '" data-key="' + escapeHtml(o.key) + '">' +
                '<div class="dm-order-top"><span class="dm-order-num">#' + escapeHtml(o.id) + '</span>' +
                (o.due ? '<span class="dm-order-due">' +
                    (o.pending_accept ? '' : 'до ') + escapeHtml(o.due) + '</span>' : '') + '</div>' +
                '<div class="dm-order-address">' + escapeHtml(o.address || '—') + '</div>' +
                '<div class="dm-order-meta"><span>' + escapeHtml(o.client) + '</span><b>' +
                (o.amount === null ? '—' : o.amount + ' &#8372;') + '</b></div>' +
                '<div class="dm-order-meta"><span>' + escapeHtml(o.phone) + '</span></div>' +
                '<span class="dm-order-courier ' + (o.pending_accept ? 'pending' : (o.courier_id ? '' : 'none')) + '">' +
                (o.pending_accept ? 'Не принят' : (o.courier_id ? escapeHtml(o.courier) : 'Без курьера')) +
                '</span></div>';
        }).join('');

        $('#dmOrders').html(html);
    }

    /**
     * Up to two initials, so a pin says who is carrying it without being opened.
     */
    function initials(name) {
        return String(name || '')
            .split(/\s+/)
            .filter(function (part) { return part.length; })
            .slice(0, 2)
            .map(function (part) { return part.charAt(0).toUpperCase(); })
            .join('');
    }

    function pinIcon(o) {
        var state = o.pending_accept ? 'pending' : (o.courier_id ? 'assigned' : 'unassigned');
        var label = o.courier_id ? initials(o.courier) : '';

        return L.divIcon({
            className: 'dm-pin-wrap',
            html: '<span class="dm-pin dm-pin-' + state + '">' + escapeHtml(label) + '</span>',
            iconSize: [28, 28],
            iconAnchor: [14, 14],
            popupAnchor: [0, -14]
        });
    }

    function renderPins(list, fit) {
        pinLayer.clearLayers();
        markers = {};

        var bounds = [];

        list.forEach(function (o) {
            if (o.pinLat === null || o.pinLng === null) { return; }

            markers[o.key] = L.marker([o.pinLat, o.pinLng], { icon: pinIcon(o) })
                .bindPopup(popupHtml(o), { className: 'dm-popup-wrap', minWidth: 268 })
                .addTo(pinLayer);

            bounds.push([o.pinLat, o.pinLng]);
        });

        if (fit && bounds.length) {
            map.fitBounds(bounds, { padding: [45, 45], maxZoom: 15 });
        }
    }

    function popupHtml(o) {
        return '<div class="dm-popup">' +
            '<div class="dm-popup-head">' +
                '<div><div class="dm-popup-eyebrow">Чек #' + escapeHtml(o.id) + '</div>' +
                '<div class="dm-popup-client">' + escapeHtml(o.client) + '</div></div>' +
                '<span class="dm-popup-pill ' + (o.pending_accept ? 'pending' : (o.courier_id ? 'ok' : 'warn')) + '">' +
                (o.pending_accept ? 'Не принят' : (o.courier_id ? 'Назначен' : 'Без курьера')) + '</span>' +
            '</div>' +
            '<div class="dm-popup-address">' + escapeHtml(fullAddress(o)) + '</div>' +
            '<div class="dm-popup-grid">' +
                field('Доставить до', o.due || '—') +
                field('Сумма', o.amount === null ? '—' : o.amount + ' &#8372;') +
                field('Телефон', o.phone || '—') +
                field('Курьер', o.courier || 'Не назначен') +
                field('Точка на карте', precisionLabel(o)) +
                (o.comment ? field('Комментарий', escapeHtml(o.comment), 'wide') : '') +
            '</div>' +
        '</div>';
    }

    /**
     * Street and house, apartment details, then city and district — the district
     * matters because several streets share a name across the city.
     */
    function fullAddress(o) {
        var parts = [o.address, o.place].filter(function (p) {
            return p && String(p).trim() !== '';
        });

        return parts.length ? parts.join(', ') : '—';
    }

    /**
     * Whether the pin is the actual building or only the street it is on, so a
     * courier knows how much to trust it.
     */
    function precisionLabel(o) {
        if (o.location_source === 'building') {
            return '<span class="dm-precise">Точный адрес</span>';
        }

        if (o.location_source === 'street') {
            return '<span class="dm-approx">Примерно</span>';
        }

        return '<span class="dm-approx">Не определена</span>';
    }

    function field(label, value, modifier) {
        return '<div class="dm-popup-field' + (modifier ? ' dm-popup-field-' + modifier : '') + '">' +
            '<small>' + label + '</small><b>' + value + '</b></div>';
    }

    function escapeHtml(value) {
        var str = String(value === null || value === undefined ? '' : value);

        return str.replace(/[&<>"']/g, function (c) {
            if (c === '&') { return '&amp;'; }
            if (c === '<') { return '&lt;'; }
            if (c === '>') { return '&gt;'; }
            if (c === '"') { return '&quot;'; }
            return '&#39;';
        });
    }

    function restartTimer() {
        if (timer) { clearInterval(timer); }
        timer = setInterval(function () { loadOrders(true, false); }, REFRESH_MS);
    }

    function teardown() {
        if (timer) { clearInterval(timer); timer = null; }

        if (map) {
            try { map.remove(); } catch (e) { /* container already gone */ }
            map = zoneLayer = pinLayer = null;
        }

        orders = [];
        activeKey = null;
    }

    /**
     * The backend swaps pages over AJAX, so this runs again on every navigation.
     * A map bound to a container that has since been replaced renders nothing and
     * leaves the page stuck, so the old instance is always discarded first.
     */
    function init() {
        var el = document.getElementById('dmMap');

        if (!el) {
            teardown();
            return;
        }

        if (map && map.getContainer() === el) {
            map.invalidateSize();
            return;
        }

        teardown();
        initMap(el);
        loadZones();
        loadOrders(false, true);
        restartTimer();
    }

    // Delegated, so they survive the markup being replaced.
    $(document)
        .on('click', '#dmToggleZones', toggleZones)
        .on('click', '#dmRefresh', function () {
            loadOrders(false, false);
            restartTimer();
        })
        .on('change', '#dmSpot, #dmDate', function () {
            loadOrders(false, true);
            restartTimer();
        })
        .on('change', '#dmCourier', function () { render(false); })
        .on('click', '.dm-order', function () {
            activeKey = String($(this).data('key'));

            var order = orders.filter(function (o) { return o.key === activeKey; })[0];

            render(false);

            if (!order || order.pinLat === null || order.pinLng === null) {
                return;
            }

            map.setView([order.pinLat, order.pinLng], 16);

            // Show the same card the pin shows, so the list and the map agree.
            if (markers[activeKey]) {
                markers[activeKey].openPopup();
            }
        });

    $(document).ready(init);
    // Fired by the backend after an AJAX page update.
    $(document).on('render page:loaded page:updated', init);
    $(window).on('resize', function () { if (map) { map.invalidateSize(); } });
}());
