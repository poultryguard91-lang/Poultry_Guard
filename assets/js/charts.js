/* =====================================================================
   PoultryGuard - Chart.js configuration.

   Colour carries meaning consistently across the whole application:
     amber = feed        blue = water        violet = added by the system
   Amber is the logo's beak colour; green is reserved for the brand chrome
   so a data series is never mistaken for interface.
   Those hues were checked for colour-vision-deficiency separation, and
   every chart also labels its series, so identity never rests on colour
   alone. Charts use a single y-axis - never a second scale.
   ===================================================================== */

PG.charts = (function () {
    'use strict';

    const COLORS = {
        feed:      '#d18b10',
        water:     '#0284c7',
        added:     '#7c3aed',
        brand:     '#097228',
        grid:      '#eaefeb',
        tick:      '#6b7c72',
        ink:       '#14211a',
        threshold: '#93a698'
    };

    // Recessive axes and grid: the data should be the loudest thing present.
    const AXIS_FONT = {
        size: 11,
        family: '"Inter", "Segoe UI Variable Text", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif'
    };

    /**
     * Draws the refill threshold as a dashed rule across a level chart, so an
     * operator can see at a glance how close the hopper or tank is to
     * triggering an automatic top-up.
     */
    const thresholdPlugin = {
        id: 'pgThreshold',
        afterDatasetsDraw: function (chart, args, opts) {
            if (!opts || opts.value === null || opts.value === undefined) return;
            const y = chart.scales.y;
            const area = chart.chartArea;
            if (!y || !area) return;

            const py = y.getPixelForValue(opts.value);
            if (py < area.top || py > area.bottom) return;

            const ctx = chart.ctx;
            ctx.save();
            ctx.beginPath();
            ctx.setLineDash([5, 4]);
            ctx.lineWidth = 1.5;
            ctx.strokeStyle = opts.color || COLORS.threshold;
            ctx.moveTo(area.left, py);
            ctx.lineTo(area.right, py);
            ctx.stroke();

            if (opts.label) {
                ctx.setLineDash([]);
                ctx.font = '600 10px ' + AXIS_FONT.family;
                const text = opts.label;
                const w = ctx.measureText(text).width;
                const bx = area.right - w - 12;
                const by = py - 16;
                ctx.fillStyle = 'rgba(255,255,255,.92)';
                ctx.fillRect(bx - 4, by, w + 8, 14);
                ctx.fillStyle = opts.color || COLORS.threshold;
                ctx.fillText(text, bx, by + 10);
            }
            ctx.restore();
        }
    };

    /** Vertical crosshair that follows the tooltip on time-series charts. */
    const crosshairPlugin = {
        id: 'pgCrosshair',
        afterDatasetsDraw: function (chart) {
            const active = chart.tooltip && chart.tooltip.getActiveElements
                ? chart.tooltip.getActiveElements() : [];
            if (!active.length) return;

            const x = active[0].element.x;
            const area = chart.chartArea;
            const ctx = chart.ctx;
            ctx.save();
            ctx.beginPath();
            ctx.lineWidth = 1;
            ctx.strokeStyle = 'rgba(20,33,26,.22)';
            ctx.moveTo(x, area.top);
            ctx.lineTo(x, area.bottom);
            ctx.stroke();
            ctx.restore();
        }
    };

    if (window.Chart) {
        Chart.register(thresholdPlugin, crosshairPlugin);
        Chart.defaults.font.family = AXIS_FONT.family;
        Chart.defaults.font.size = 11;
        Chart.defaults.color = COLORS.tick;
        Chart.defaults.animation.duration = 400;
    }

    /** Shared tooltip styling. */
    function tooltipConfig(formatter) {
        return {
            enabled: true,
            backgroundColor: 'rgba(20,33,26,.95)',
            titleFont: { size: 11, weight: '600' },
            bodyFont: { size: 12 },
            padding: 10,
            cornerRadius: 8,
            displayColors: true,
            boxWidth: 8,
            boxHeight: 8,
            usePointStyle: true,
            callbacks: formatter ? { label: formatter } : {}
        };
    }

    function baseScales(unitFormatter, opts) {
        opts = opts || {};
        return {
            x: {
                grid: { display: false },
                border: { display: false },
                ticks: {
                    color: COLORS.tick,
                    maxRotation: 0,
                    autoSkip: true,
                    maxTicksLimit: opts.maxXTicks || 8
                }
            },
            y: {
                beginAtZero: opts.beginAtZero !== false,
                suggestedMax: opts.suggestedMax,
                grid: { color: COLORS.grid, drawTicks: false },
                border: { display: false },
                ticks: {
                    color: COLORS.tick,
                    padding: 8,
                    callback: function (value) { return unitFormatter(value); }
                }
            }
        };
    }

    /**
     * Line chart for a level over time (feed weight or water depth).
     *
     * @param {string} canvasId
     * @param {object} o { color, label, unit: fn, threshold, thresholdLabel, suggestedMax }
     */
    function levelChart(canvasId, o) {
        const el = document.getElementById(canvasId);
        if (!el || !window.Chart) return null;

        const color = o.color || COLORS.feed;
        const ctx = el.getContext('2d');

        // A soft vertical wash under the line adds depth without competing
        // with the stroke for attention.
        const fill = ctx.createLinearGradient(0, 0, 0, el.clientHeight || 260);
        fill.addColorStop(0, hexToRgba(color, 0.20));
        fill.addColorStop(1, hexToRgba(color, 0.01));

        return new Chart(ctx, {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: o.label || 'Level',
                    data: [],
                    borderColor: color,
                    backgroundColor: fill,
                    borderWidth: 2,
                    fill: true,
                    tension: 0.25,
                    pointRadius: 0,
                    pointHoverRadius: 5,
                    pointBackgroundColor: color,
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    // Generous hit area so the tooltip is easy to catch.
                    pointHitRadius: 14
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    // A single series is named by the card title, so no legend box.
                    legend: { display: false },
                    tooltip: tooltipConfig(function (c) {
                        return ' ' + c.dataset.label + ': ' + o.unit(c.parsed.y);
                    }),
                    pgThreshold: {
                        value: o.threshold === undefined ? null : o.threshold,
                        label: o.thresholdLabel || 'Refill threshold',
                        color: COLORS.threshold
                    }
                },
                scales: baseScales(o.unit, { suggestedMax: o.suggestedMax })
            }
        });
    }

    /**
     * Grouped bar chart for consumption per period.
     *
     * @param {string} canvasId
     * @param {object} o { unit: fn, primaryLabel, secondaryLabel, primaryColor }
     */
    function consumptionChart(canvasId, o) {
        const el = document.getElementById(canvasId);
        if (!el || !window.Chart) return null;

        const datasets = [{
            label: o.primaryLabel || 'Consumed',
            data: [],
            backgroundColor: o.primaryColor || COLORS.feed,
            // Rounded data-end anchored to the baseline.
            borderRadius: { topLeft: 4, topRight: 4, bottomLeft: 0, bottomRight: 0 },
            borderSkipped: 'bottom',
            // Charts with only a handful of categories look sparse at the
            // default cap, so callers can widen the marks.
            maxBarThickness: o.maxBarThickness || 34
        }];

        if (o.secondaryLabel) {
            datasets.push({
                label: o.secondaryLabel,
                data: [],
                backgroundColor: COLORS.added,
                borderRadius: { topLeft: 4, topRight: 4, bottomLeft: 0, bottomRight: 0 },
                borderSkipped: 'bottom',
                maxBarThickness: 34
            });
        }

        return new Chart(el.getContext('2d'), {
            type: 'bar',
            data: { labels: [], datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                // Leaves a clear gap between the bars in a group and between groups.
                categoryPercentage: 0.7,
                barPercentage: 0.86,
                plugins: {
                    legend: {
                        // Two series need a key; one is named by the card title.
                        display: datasets.length > 1,
                        position: 'top',
                        align: 'end',
                        labels: {
                            boxWidth: 8,
                            boxHeight: 8,
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: 14,
                            color: COLORS.ink,
                            font: { size: 11, weight: '600' }
                        }
                    },
                    tooltip: tooltipConfig(function (c) {
                        return ' ' + c.dataset.label + ': ' + o.unit(c.parsed.y);
                    })
                },
                scales: baseScales(o.unit, { maxXTicks: 12 })
            }
        });
    }

    /** Horizontal bar chart, used for alert counts by type. */
    function categoryChart(canvasId, o) {
        const el = document.getElementById(canvasId);
        if (!el || !window.Chart) return null;

        return new Chart(el.getContext('2d'), {
            type: 'bar',
            data: {
                labels: [],
                datasets: [{
                    label: o.label || 'Count',
                    data: [],
                    backgroundColor: [],
                    borderRadius: { topLeft: 0, topRight: 4, bottomLeft: 0, bottomRight: 4 },
                    borderSkipped: 'left',
                    maxBarThickness: 22
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: tooltipConfig(function (c) {
                        return ' ' + c.parsed.x + ' alert' + (c.parsed.x === 1 ? '' : 's');
                    })
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: COLORS.grid, drawTicks: false },
                        border: { display: false },
                        ticks: { color: COLORS.tick, precision: 0 }
                    },
                    y: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { color: COLORS.ink, font: { size: 11 } }
                    }
                }
            }
        });
    }

    /**
     * Replace a chart's data in place.
     * @param {Chart} chart
     * @param {string[]} labels
     * @param {Array<Array<number>>} series one array per dataset
     */
    function update(chart, labels, series, colors) {
        if (!chart) return;
        chart.data.labels = labels;
        series.forEach(function (values, i) {
            if (chart.data.datasets[i]) {
                chart.data.datasets[i].data = values;
                if (colors && colors[i]) chart.data.datasets[i].backgroundColor = colors[i];
            }
        });
        chart.update();
    }

    /** Move a level chart's threshold rule (settings can change at any time). */
    function setThreshold(chart, value) {
        if (!chart) return;
        chart.options.plugins.pgThreshold.value = value;
        chart.update('none');
    }

    function hexToRgba(hex, alpha) {
        const h = hex.replace('#', '');
        const n = parseInt(h.length === 3 ? h.split('').map(function (c) { return c + c; }).join('') : h, 16);
        return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + alpha + ')';
    }

    /** Render an empty-state message inside a chart's container. */
    function emptyState(canvasId, message) {
        const el = document.getElementById(canvasId);
        if (!el || !el.parentElement) return;
        let note = el.parentElement.querySelector('.pg-chart-empty');
        if (!note) {
            note = document.createElement('div');
            note.className = 'pg-chart-empty position-absolute top-50 start-50 translate-middle text-center pg-muted small';
            el.parentElement.appendChild(note);
        }
        note.textContent = message || '';
        note.hidden = !message;
        el.style.opacity = message ? '0.15' : '1';
    }

    return {
        COLORS: COLORS,
        levelChart: levelChart,
        consumptionChart: consumptionChart,
        categoryChart: categoryChart,
        update: update,
        setThreshold: setThreshold,
        emptyState: emptyState
    };
})();
