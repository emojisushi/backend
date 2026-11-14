(function () {
    'use strict';

    var REFRESH_MS = 30000;

    var timer = null, loading = false, screen = null;

    // "Чеки доставки": the last loaded groups and the chosen status, so switching
    // the filter re-renders at once without asking Poster again.
    var RECEIPT_FILTERS = [
        { key: 'all', label: 'Все' },
        { key: 'open', label: 'Открытые' },
        { key: 'on_way', label: 'В пути' },
        { key: 'closed', label: 'Закрытые' }
    ];
    var RECEIPT_TONES = { open: 'info', on_way: 'warn', closed: 'ok' };
    var FILTER_STORAGE_KEY = 'operators.receiptFilter';
    var receiptGroups = [];
    var receiptFilter = readFilter();

    function readFilter() {
        try {
            var saved = window.localStorage.getItem(FILTER_STORAGE_KEY);
            return RECEIPT_FILTERS.some(function (f) { return f.key === saved; }) ? saved : 'all';
        } catch (e) {
            return 'all';
        }
    }

    function saveFilter(value) {
        try { window.localStorage.setItem(FILTER_STORAGE_KEY, value); } catch (e) { /* private mode */ }
    }

    function load(silent) {
        if (loading) {
            return;
        }

        loading = true;

        if (!silent) {
            $('#opSpots, #opEvents, #opLate, #opReceipts').html('<div class="op-empty">Загрузка…</div>');
        }

        $.request('onLoadOverview', {
            data: { city_id: $('#opCity').val() },
            complete: function () { loading = false; },
            success: function (data) {
                var error = $('#opError');

                if (data.error) {
                    error.text('Poster недоступен: ' + data.error).prop('hidden', false);
                } else {
                    error.prop('hidden', true);
                }

                renderSpots(data.spots || []);
                renderEvents(data.events || []);
                renderLate(data.late || [], data.late_after_minutes);
                renderSummary(data.summary || {});
                renderUnaccepted(data.unaccepted || []);
                receiptGroups = data.receipts || [];
                renderReceipts();
                $('#opUpdated').text('обновлено ' + (data.updated_at || ''));
            },
            error: function () {
                if (!silent) {
                    $('#opSpots').html('<div class="op-empty">Не удалось загрузить данные</div>');
                }
            }
        });
    }

    function renderSpots(spots) {
        if (!spots.length) {
            $('#opSpots').html('<div class="op-empty">Нет заведений</div>');
            return;
        }

        var mapUrl = $('.operators-screen').data('map-url');

        $('#opSpots').html(spots.map(function (s) {
            return '<a class="op-spot' + (s.alert ? ' alert' : '') + '" href="' + escapeHtml(mapUrl + '?spot=' + s.id) + '">' +
                (s.alert ? '<span class="op-spot-alert"></span>' : '') +
                '<div class="op-spot-title">' + escapeHtml(s.name) + '</div>' +
                '<div class="op-spot-city">' + escapeHtml(s.city) + '</div>' +
                '<div class="op-metrics">' +
                    metric(s.open, 'Открыто') +
                    metric(s.no_courier, 'Без курьера', s.no_courier ? 'warn' : '') +
                    metric(s.late, 'Просрочено', s.late ? 'bad' : '') +
                    metric(s.unaccepted, 'Не принято', s.unaccepted ? 'pending' : '') +
                '</div>' +
            '</a>';
        }).join(''));
    }

    function metric(value, label, tone) {
        return '<div class="op-metric"><b class="' + (tone || '') + '">' + value + '</b><span>' + label + '</span></div>';
    }

    function renderEvents(events) {
        if (!events.length) {
            $('#opEvents').html('<div class="op-empty">Событий пока нет. Они появляются по мере прихода вебхуков Poster.</div>');
            return;
        }

        $('#opEvents').html(events.map(function (e) {
            return '<div class="op-event">' +
                '<div class="op-event-time">' + escapeHtml(e.time) + '</div>' +
                '<div class="op-event-spot">' + escapeHtml(e.spot) + '</div>' +
                '<div class="op-event-desc"><b>' + escapeHtml(e.title) + '</b>' +
                    (e.detail ? '<span>' + escapeHtml(e.detail) + '</span>' : '') + '</div>' +
                '<div>' + (e.badge ? '<span class="op-pill ' + escapeHtml(e.tone) + '">' + escapeHtml(e.badge) + '</span>' : '') + '</div>' +
            '</div>';
        }).join(''));
    }

    function renderLate(late, threshold) {
        $('#opLateCount').text(late.length);

        if (!late.length) {
            $('#opLate').html('<div class="op-empty">Просроченных заказов нет</div>');
            return;
        }

        $('#opLate').html(late.map(function (o) {
            return '<div class="op-late">' +
                '<div class="op-late-top"><span class="op-late-num">#' + escapeHtml(o.id) + ' · ' + escapeHtml(o.spot) + '</span>' +
                    '<span class="op-late-time">+' + o.late_minutes + ' мин</span></div>' +
                '<div class="op-late-address">' + escapeHtml(o.address || '—') + '</div>' +
                '<div class="op-late-meta"><span>Доставить до ' + escapeHtml(o.due) + '</span><span>' + escapeHtml(o.client) + '</span></div>' +
                '<div class="op-late-courier' + (o.courier ? '' : ' none') + '">' +
                    (o.courier ? escapeHtml(o.courier) : 'Курьер не назначен') + '</div>' +
            '</div>';
        }).join(''));
    }

    function renderSummary(s) {
        $('#opSummary').html(
            line('Всего открытых чеков', s.open) +
            line('Без курьера', s.no_courier, 'warn') +
            line('С назначенным курьером', s.with_courier, 'ok') +
            line('Просрочено', s.late, 'bad')
        );
    }

    function line(label, value, tone) {
        return '<div class="op-line"><span>' + label + '</span><strong class="' + (tone || '') + '">' +
            (value === null || value === undefined ? '—' : value) + '</strong></div>';
    }

    function renderUnaccepted(orders) {
        $('#opUnacceptedCount').text(orders.length);

        if (!orders.length) {
            $('#opUnaccepted').html('<div class="op-empty">Все онлайн-заказы приняты</div>');
            return;
        }

        $('#opUnaccepted').html(orders.map(function (o) {
            return '<div class="op-pending">' +
                '<div class="op-late-top"><span class="op-late-num">#' + escapeHtml(o.id) + ' · ' + escapeHtml(o.spot) + '</span>' +
                    (o.waiting_minutes !== null ? '<span class="op-pending-time">ждёт ' + o.waiting_minutes + ' мин</span>' : '') + '</div>' +
                '<div class="op-late-address">' + escapeHtml(o.address || '—') + '</div>' +
                '<div class="op-late-meta"><span>' + escapeHtml(o.client) + '</span><span>' + escapeHtml(o.phone) + '</span></div>' +
            '</div>';
        }).join(''));
    }

    function renderReceipts() {
        var counts = { all: 0, open: 0, on_way: 0, closed: 0 };

        receiptGroups.forEach(function (g) {
            (g.receipts || []).forEach(function (r) {
                counts.all++;
                counts[r.status] = (counts[r.status] || 0) + 1;
            });
        });

        $('#opReceiptFilter').html(RECEIPT_FILTERS.map(function (f) {
            return '<button type="button" class="op-filter-btn' + (f.key === receiptFilter ? ' active' : '') +
                '" data-filter="' + f.key + '">' + f.label + ' <span>' + (counts[f.key] || 0) + '</span></button>';
        }).join(''));

        var groups = receiptGroups
            .map(function (g) {
                return {
                    name: g.name,
                    receipts: (g.receipts || []).filter(function (r) {
                        return receiptFilter === 'all' || r.status === receiptFilter;
                    })
                };
            })
            .filter(function (g) { return g.receipts.length; });

        if (!groups.length) {
            $('#opReceipts').html('<div class="op-empty">Чеков нет</div>');
            return;
        }

        $('#opReceipts').html(groups.map(function (g) {
            return '<div class="op-rgroup">' +
                '<div class="op-rgroup-head"><b>' + escapeHtml(g.name) + '</b><span>' + g.receipts.length + ' ' + plural(g.receipts.length, 'чек', 'чека', 'чеков') + '</span></div>' +
                '<div class="op-receipt op-receipt-labels">' +
                    '<span>Чек</span><span>Принят</span><span>Закрыт</span><span>Статус</span><span>Адрес · клиент</span><span>Курьер</span><span>Сумма</span>' +
                '</div>' +
                g.receipts.map(function (r) {
                    return '<div class="op-receipt">' +
                        '<span class="op-receipt-num">#' + escapeHtml(r.id) + '</span>' +
                        '<span class="op-receipt-time">' + escapeHtml(r.accepted || '—') + '</span>' +
                        '<span class="op-receipt-time' + (r.closed ? '' : ' none') + '">' + escapeHtml(r.closed || '—') + '</span>' +
                        '<span><span class="op-pill ' + (RECEIPT_TONES[r.status] || 'muted') + '">' + escapeHtml(r.status_label) + '</span></span>' +
                        '<span class="op-receipt-desc"><b>' + escapeHtml(r.address || '—') + '</b><span>' + escapeHtml(r.client) + '</span></span>' +
                        '<span class="op-receipt-courier' + (r.courier ? '' : ' none') + '">' + escapeHtml(r.courier || 'не назначен') + '</span>' +
                        '<span class="op-receipt-sum">' + r.amount + ' ₴</span>' +
                    '</div>';
                }).join('') +
            '</div>';
        }).join(''));
    }

    function plural(n, one, few, many) {
        var mod10 = n % 10, mod100 = n % 100;
        if (mod10 === 1 && mod100 !== 11) { return one; }
        if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) { return few; }
        return many;
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
        timer = setInterval(function () { load(true); }, REFRESH_MS);
    }

    /**
     * The backend swaps pages over AJAX, so this runs again on every navigation;
     * the timer must stop once the screen is gone.
     */
    function init() {
        var el = document.querySelector('.operators-screen');

        if (!el) {
            if (timer) { clearInterval(timer); timer = null; }
            screen = null;
            return;
        }

        // "render" also fires after every AJAX request, including our own.
        if (el === screen) {
            return;
        }

        screen = el;
        load(false);
        restartTimer();
    }

    $(document)
        .on('click', '#opRefresh', function () {
            load(false);
            restartTimer();
        })
        .on('change', '#opCity', function () {
            load(false);
            restartTimer();
        })
        .on('click', '#opReceiptFilter .op-filter-btn', function () {
            receiptFilter = $(this).data('filter');
            saveFilter(receiptFilter);
            renderReceipts();
        });

    $(document).ready(init);
    $(document).on('render page:loaded page:updated', init);
}());
