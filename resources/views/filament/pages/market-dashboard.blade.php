<x-filament-panels::page>
    <div
        x-data="marketDashboard({
            symbol: @js($symbol),
            interval: @js($interval),
            pairs: @js($this->getPairs()),
            timeframes: @js($this->getTimeframes()),
        })"
        class="space-y-4"
    >
        {{-- ───────────────────────── Toolbar ───────────────────────── --}}
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-900">
            {{-- Pair --}}
            <div class="flex items-center gap-2">
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Пара</span>
                <select x-model="symbol" @change="reload()"
                    class="rounded-lg border-gray-300 bg-white py-1.5 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-white">
                    <template x-for="p in pairs" :key="p">
                        <option :value="p" x-text="p"></option>
                    </template>
                </select>
            </div>

            {{-- Timeframe --}}
            <div class="flex items-center gap-1">
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">ТФ</span>
                <template x-for="tf in timeframes" :key="tf">
                    <button type="button" @click="symbol && (interval = tf, reload())"
                        class="rounded-lg px-2.5 py-1 text-xs font-semibold transition"
                        :class="interval === tf
                            ? 'bg-amber-500 text-white'
                            : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300'">
                        <span x-text="tf"></span>
                    </button>
                </template>
            </div>

            <div class="mx-1 h-6 w-px bg-gray-200 dark:bg-white/10"></div>

            {{-- Overlay toggles --}}
            <button type="button" @click="toggle('levels')"
                class="rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                :class="show.levels ? 'bg-sky-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300'">
                Уровни
            </button>
            <button type="button" @click="toggle('htfLevels')"
                class="rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                :class="show.htfLevels ? 'bg-indigo-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300'">
                Уровни <span x-text="data.htf_interval ?? 'HTF'"></span>
            </button>
            <button type="button" @click="toggle('patterns')"
                class="rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                :class="show.patterns ? 'bg-violet-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300'">
                Фигуры
            </button>
            <button type="button" @click="toggle('atr')"
                class="rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                :class="show.atr ? 'bg-orange-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300'">
                ATR
            </button>

            <div class="ml-auto flex items-center gap-3">
                <template x-if="loading">
                    <span class="text-xs text-gray-400">загрузка…</span>
                </template>

                {{-- Trend badge --}}
                <template x-if="data.trend">
                    <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold text-white"
                        :style="`background:${data.trend.color}`">
                        <span x-text="data.trend.label"></span>
                        <span class="opacity-80" x-text="'· сила ' + Math.round(data.trend.strength * 100) + '%'"></span>
                    </span>
                </template>

                {{-- Last price --}}
                <template x-if="data.ticker">
                    <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">
                        <span x-text="data.ticker.last"></span>
                        <span class="text-xs font-medium"
                            :class="data.ticker.changePercent >= 0 ? 'text-emerald-500' : 'text-rose-500'"
                            x-text="(data.ticker.changePercent >= 0 ? '+' : '') + data.ticker.changePercent + '%'"></span>
                    </span>
                </template>

                {{-- ATR value --}}
                <template x-if="data.atr">
                    <span class="rounded-lg bg-orange-50 px-2 py-1 text-xs font-semibold text-orange-600 dark:bg-orange-500/10 dark:text-orange-400">
                        ATR <span x-text="data.atr"></span> <span class="opacity-75" x-text="data.atr_travel_signed !== null ? '(от уровня: ' + (data.atr_travel_signed > 0 ? '+' : '') + data.atr_travel_signed + ' ATR)' : ''"></span>
                    </span>
                </template>
            </div>
        </div>

        {{-- ───────────────────────── Chart ───────────────────────── --}}
        <div wire:ignore class="rounded-xl border border-gray-200 bg-white p-2 dark:border-white/10 dark:bg-gray-900">
            {{-- Inline height/width so the chart always has a box to draw into,
                 even if the Tailwind utility classes (h-[520px]) aren't compiled into the panel theme. --}}
            <div x-ref="chart" class="h-[520px] w-full" style="height: 520px; width: 100%;"></div>
        </div>

        {{-- ───────────────────────── Patterns list ───────────────────────── --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <h3 class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-200">Обнаруженные фигуры</h3>
            <template x-if="!data.patterns || data.patterns.length === 0">
                <p class="text-sm text-gray-400">Фигуры не обнаружены на текущем таймфрейме.</p>
            </template>
            <div class="flex flex-wrap gap-2">
                <template x-for="p in (data.patterns || [])" :key="p.type + p.startTime">
                    <span class="inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-xs"
                        :class="{
                            'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-400': p.bias === 'bullish',
                            'border-rose-300 bg-rose-50 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-400': p.bias === 'bearish',
                            'border-gray-300 bg-gray-50 text-gray-600 dark:border-white/10 dark:bg-gray-800 dark:text-gray-300': p.bias === 'neutral',
                        }">
                        <span class="font-semibold" x-text="p.label"></span>
                        <span class="opacity-70" x-text="Math.round(p.confidence * 100) + '%'"></span>
                    </span>
                </template>
            </div>
        </div>
    </div>

    {{-- lightweight-charts (TradingView), self-hosted so the chart never depends on an external CDN at runtime --}}
    <script src="{{ asset('vendor/lightweight-charts/lightweight-charts.standalone.production.js') }}"></script>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('marketDashboard', (cfg) => ({
                symbol: cfg.symbol,
                interval: cfg.interval,
                pairs: cfg.pairs,
                timeframes: cfg.timeframes,
                show: { levels: false, htfLevels: false, patterns: false, atr: false },
                loading: false,
                data: {},
                chart: null,
                candleSeries: null,
                volumeSeries: null,
                overlay: { levelLines: [], htfLevelLines: [], atrLines: [], patternSeries: [] },
                refreshTimer: null,

                error: null,

                async init() {
                    try {
                        await this.ensureLibrary();
                    } catch (e) {
                        this.error = e.message;
                        this.renderError(e.message);
                        return;
                    }
                    this.buildChart();
                    await this.reload();
                    window.addEventListener('resize', () => this.resize());
                    // Live updates: pull fresh candles every 30s without disturbing zoom/scroll.
                    this.refreshTimer = setInterval(() => this.refresh(), 30000);
                },

                ensureLibrary({ timeout = 10000 } = {}) {
                    return new Promise((resolve, reject) => {
                        const ready = () => typeof window.LightweightCharts !== 'undefined';
                        if (ready()) return resolve();
                        const started = Date.now();
                        const timer = setInterval(() => {
                            if (ready()) { clearInterval(timer); return resolve(); }
                            if (Date.now() - started >= timeout) {
                                clearInterval(timer);
                                reject(new Error('Не удалось загрузить библиотеку графиков (lightweight-charts).'));
                            }
                        }, 50);
                    });
                },

                renderError(message) {
                    const host = this.$refs.chart;
                    host.textContent = '';
                    const box = document.createElement('div');
                    box.className = 'flex h-full items-center justify-center text-center text-sm text-rose-500';
                    box.textContent = message;
                    host.appendChild(box);
                },

                isDark() {
                    return document.documentElement.classList.contains('dark');
                },

                buildChart() {
                    const dark = this.isDark();
                    this.chart = LightweightCharts.createChart(this.$refs.chart, {
                        autoSize: true,
                        layout: {
                            background: { color: 'transparent' },
                            textColor: dark ? '#d1d5db' : '#374151',
                        },
                        grid: {
                            vertLines: { color: dark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)' },
                            horzLines: { color: dark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)' },
                        },
                        rightPriceScale: { borderColor: dark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)' },
                        timeScale: {
                            borderColor: dark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.1)',
                            timeVisible: true,
                            // Data times stay UTC; only the axis labels are rendered in the
                            // browser's local timezone (TickMarkType: 0 Year,1 Month,2 Day,3 Time,4 TimeWithSeconds).
                            tickMarkFormatter: (time, tickMarkType, locale) => {
                                const d = new Date(time * 1000);
                                switch (tickMarkType) {
                                    case 0: return String(d.getFullYear());
                                    case 1: return d.toLocaleDateString(locale, { month: 'short' });
                                    case 2: return d.toLocaleDateString(locale, { day: 'numeric', month: 'short' });
                                    case 4: return d.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                                    default: return d.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
                                }
                            },
                        },
                        // Crosshair label: full local date-time (browser timezone).
                        localization: {
                            timeFormatter: (time) => new Date(time * 1000).toLocaleString(),
                        },
                        crosshair: { mode: LightweightCharts.CrosshairMode.Normal },
                    });

                    this.candleSeries = this.chart.addCandlestickSeries({
                        upColor: '#26a69a', downColor: '#ef5350',
                        borderUpColor: '#26a69a', borderDownColor: '#ef5350',
                        wickUpColor: '#26a69a', wickDownColor: '#ef5350',
                        autoscaleInfoProvider: () => {
                            if (!this.candleSeries) return null;
                            // Grab default autoscale price range from candles data
                            const data = this.data.candles || [];
                            if (data.length === 0) return null;
                            
                            let min = Infinity;
                            let max = -Infinity;
                            
                            // We only calculate range for the visible or loaded candles
                            data.forEach(c => {
                                if (c.low < min) min = c.low;
                                if (c.high > max) max = c.high;
                            });

                            // Adjust min/max if overlays are active
                            if (this.show.levels && this.data.levels) {
                                this.data.levels.forEach(lvl => {
                                    if (lvl.price < min) min = lvl.price;
                                    if (lvl.price > max) max = lvl.price;
                                });
                            }
                            if (this.show.htfLevels && this.data.htf_levels) {
                                this.data.htf_levels.forEach(lvl => {
                                    if (lvl.price < min) min = lvl.price;
                                    if (lvl.price > max) max = lvl.price;
                                });
                            }
                            if (this.show.atr && this.data.atr) {
                                const last = data[data.length - 1].close;
                                const atr = this.data.atr;
                                const p1 = last + atr;
                                const p2 = last - atr;
                                if (p1 > max) max = p1;
                                if (p2 < min) min = p2;
                            }

                            return {
                                priceRange: {
                                    minValue: min,
                                    maxValue: max,
                                },
                            };
                        }
                    });

                    this.volumeSeries = this.chart.addHistogramSeries({
                        priceFormat: { type: 'volume' },
                        priceScaleId: '',
                    });
                    this.volumeSeries.priceScale().applyOptions({
                        scaleMargins: { top: 0.8, bottom: 0 },
                    });
                },

                async reload() {
                    this.loading = true;
                    try {
                        this.data = await this.$wire.marketData(this.symbol, this.interval);
                        this.renderSeries();
                        this.applyLevels();
                        this.applyHtfLevels();
                        this.applyAtr();
                        this.applyPatterns();
                        this.chart.timeScale().fitContent();
                    } finally {
                        this.loading = false;
                    }
                },

                // Periodic live update: refresh data + overlays but keep the current
                // zoom/scroll (no fitContent). Skips if a reload is already running.
                async refresh() {
                    if (!this.chart || this.loading) return;
                    try {
                        this.data = await this.$wire.marketData(this.symbol, this.interval);
                        this.renderSeries();
                        this.applyLevels();
                        this.applyHtfLevels();
                        this.applyAtr();
                        this.applyPatterns();
                    } catch (e) {
                        // Transient failure (e.g. network) — keep the last good chart.
                    }
                },

                renderSeries() {
                    const candles = this.data.candles || [];
                    this.candleSeries.setData(candles.map(c => ({
                        time: c.time, open: c.open, high: c.high, low: c.low, close: c.close,
                    })));
                    this.volumeSeries.setData(candles.map(c => ({
                        time: c.time,
                        value: c.volume,
                        color: c.close >= c.open ? 'rgba(38,166,154,0.5)' : 'rgba(239,83,80,0.5)',
                    })));
                },

                toggle(key) {
                    this.show[key] = !this.show[key];
                    if (key === 'levels') this.applyLevels();
                    if (key === 'htfLevels') this.applyHtfLevels();
                    if (key === 'atr') this.applyAtr();
                    if (key === 'patterns') this.applyPatterns();
                },

                clearPriceLines(bucket) {
                    this.overlay[bucket].forEach(line => this.candleSeries.removePriceLine(line));
                    this.overlay[bucket] = [];
                },

                applyLevels() {
                    this.clearPriceLines('levelLines');
                    if (this.show.levels) {
                        (this.data.levels || []).forEach(level => {
                            this.overlay.levelLines.push(this.candleSeries.createPriceLine({
                                price: level.price,
                                color: level.color,
                                lineWidth: 2,
                                lineStyle: LightweightCharts.LineStyle.Dashed,
                                axisLabelVisible: true,
                                title: `${level.label} (${level.touches})`,
                            }));
                        });
                    }
                    if (this.candleSeries) {
                        this.candleSeries.applyOptions({});
                    }
                },

                applyHtfLevels() {
                    this.clearPriceLines('htfLevelLines');
                    if (this.show.htfLevels) {
                        const htf = this.data.htf_interval ?? 'HTF';
                        (this.data.htf_levels || []).forEach(level => {
                            this.overlay.htfLevelLines.push(this.candleSeries.createPriceLine({
                                price: level.price,
                                color: level.color,
                                lineWidth: 3,
                                lineStyle: LightweightCharts.LineStyle.Solid,
                                axisLabelVisible: true,
                                title: `${level.label} ${htf} (${level.touches})`,
                            }));
                        });
                    }
                    if (this.candleSeries) {
                        this.candleSeries.applyOptions({});
                    }
                },

                applyAtr() {
                    this.clearPriceLines('atrLines');
                    if (this.show.atr) {
                        const candles = this.data.candles || [];
                        const atr = this.data.atr || 0;
                        if (candles.length && atr) {
                            const last = candles[candles.length - 1].close;
                            [['ATR +', last + atr], ['ATR −', last - atr]].forEach(([title, price]) => {
                                this.overlay.atrLines.push(this.candleSeries.createPriceLine({
                                    price, color: '#f59e0b', lineWidth: 1,
                                    lineStyle: LightweightCharts.LineStyle.Dotted,
                                    axisLabelVisible: true, title,
                                }));
                            });
                        }
                    }
                    if (this.candleSeries) {
                        this.candleSeries.applyOptions({});
                    }
                },

                applyPatterns() {
                    this.overlay.patternSeries.forEach(s => this.chart.removeSeries(s));
                    this.overlay.patternSeries = [];
                    let markers = [];
                    if (this.show.patterns) {
                        const color = (bias) => bias === 'bullish' ? '#26a69a' : (bias === 'bearish' ? '#ef5350' : '#8b5cf6');
                        (this.data.patterns || []).forEach(p => {
                            const series = this.chart.addLineSeries({
                                color: color(p.bias), lineWidth: 2, lastValueVisible: false, priceLineVisible: false,
                            });
                            series.setData(p.points.map(pt => ({ time: pt.time, value: pt.price })));
                            this.overlay.patternSeries.push(series);
                            const lastPoint = p.points[p.points.length - 1];
                            markers.push({
                                time: lastPoint.time,
                                position: p.bias === 'bearish' ? 'aboveBar' : 'belowBar',
                                color: color(p.bias),
                                shape: p.bias === 'bearish' ? 'arrowDown' : 'arrowUp',
                                text: p.label,
                            });
                        });
                    }
                    markers.sort((a, b) => a.time - b.time);
                    this.candleSeries.setMarkers(markers);
                },

                resize() {
                    if (this.chart) {
                        this.chart.applyOptions({ width: this.$refs.chart.clientWidth });
                    }
                },

                // Alpine calls this when the component is torn down (e.g. SPA navigation).
                destroy() {
                    if (this.refreshTimer) clearInterval(this.refreshTimer);
                },
            }));
        });
    </script>
</x-filament-panels::page>
