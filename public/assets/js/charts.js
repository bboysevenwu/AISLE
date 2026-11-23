/*
 * charts 頁面 (jQuery 1.12 + ECharts 5)，所有 chart（BSLr、M1BF…）共用
 *
 * 三個區塊：single 單台趨勢 / newest 最新值排排站 / tbt 歷史趨勢排排站
 * 每個區塊依 charts/<名稱>.ini 的 [plot.xxx] 各畫一張圖（CFG.groups），
 * 同一組的參數（例 M1BF_Z_C1、M1BF_Z_C2）畫在同一張圖上。
 *
 * 後端回傳格式：
 *   { "APAN01": { "Date": [...], "Data": { "M1BF_Z_C1": [...], "M1BF_Z_C2": [...] } }, ... }
 *   每個參數陣列與 Date 等長，沒有值的位置是 null
 *   選用 "Url": [...]（與 Date 等長）：單台趨勢圖點擊數據點時在新分頁開啟該網址
 *
 * 資料載入方式：
 *   進入頁面時只呼叫一次 tbt，取回日期區間內「全部機台、全部參數」的資料存在 cache，所有圖都從這份資料取得：
 *     TBT 直接畫；single 取出所選機台；Newest_TBT 取每台每個參數在區間內的最後一個值（再依機型篩選）
 *   之後切換機台、切換機型只用 cache 重畫，不再發 request；
 *   只有改日期區間（查詢 / 快速區間）才會重新取 tbt。
 *
 * 編碼方式：顏色 = 機台，線型 / 長條紋理 = 參數（同組第 1 個參數實線無紋理，第 2 個虛線斜紋…）
 * 只用 ES5 語法，避免舊瀏覽器出錯
 */
(function ($, window) {
    'use strict';

    var CFG = window.AISLE_CHARTS;
    if (!$ || !CFG) {
        return;
    }

    // ---------- 主題 ----------
    // 機台顏色：8 色類別色盤（已用 dataviz validate_palette.js 檢查深色底 #001406：
    // 亮度、彩度、色盲區分、對比全部通過）。同機型內依序取色，APAN01 與 APAP01 同色，
    // 以 X 軸機台名稱區分。顏色跟著機台走，篩選後不會變色。
    var PALETTE = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767'];
    // 參數：線型與長條紋理，依參數在組內的順序取用
    var LINE_TYPES = ['solid', 'dashed', 'dotted', [10, 4, 2, 4], [2, 6]];
    var LINE_NAMES = ['實線', '虛線', '點線', '點劃線', '稀點線'];
    var DECALS = [
        null,
        { symbol: 'rect', symbolSize: 1, dashArrayX: [1, 0], dashArrayY: [2, 4], rotation: Math.PI / 4, color: 'rgba(0,0,0,0.5)' },
        { symbol: 'circle', symbolSize: 0.6, dashArrayX: [[4, 4]], dashArrayY: [4, 0], color: 'rgba(0,0,0,0.5)' },
        { symbol: 'rect', symbolSize: 1, dashArrayX: [1, 0], dashArrayY: [2, 4], rotation: -Math.PI / 4, color: 'rgba(0,0,0,0.5)' },
        { symbol: 'rect', symbolSize: 1, dashArrayX: [1, 0], dashArrayY: [1, 3], rotation: 0, color: 'rgba(0,0,0,0.5)' }
    ];
    // 文字、格線、軸線等依畫面風格切換（body.theme-light = 淺色）；機台色盤兩種風格共用
    // （PALETTE 另以淺色底 #ffffff 檢查過，同樣全部通過）
    var THEMES = {
        dark: {
            INK: '#00ff41',                 // 主要文字
            INK_DIM: '#00b82e',             // 次要文字
            GRID: '#0a2a12',                // 格線
            AXIS: '#00661a',                // 軸線
            NEUTRAL: '#7dffb0',             // 圖例中代表「參數」的中性色
            BAND: 'rgba(0, 255, 65, 0.05)', // TBT 交錯底色
            INACTIVE: '#1f4a2a',            // 圖例關閉的項目
            TIP_BG: 'rgba(0, 10, 3, 0.95)',
            TIP_SHADOW: 'box-shadow: 0 0 12px rgba(0,255,65,0.3);',
            MASK: 'rgba(0, 0, 0, 0.6)',
            HOVER: 'rgba(0,255,65,0.06)',
            ZOOM_FILL: 'rgba(0,255,65,0.12)',
            ZOOM_AREA: 'rgba(0,255,65,0.08)',
            OOS: '#ff3b3b',                 // spec 線：OOS（規格界限）
            OOC: '#ffb000',                 // spec 線：OOC（管制界限）
            TARGET: '#4dd0e1'               // target 線（ini 有寫 target 的組）
        },
        light: {
            INK: '#00591a',
            INK_DIM: '#2e6b3c',
            GRID: '#dcefe1',
            AXIS: '#8fbf9a',
            NEUTRAL: '#2e6b3c',
            BAND: 'rgba(0, 143, 17, 0.06)',
            INACTIVE: '#b5cdb9',
            TIP_BG: 'rgba(255, 255, 255, 0.97)',
            TIP_SHADOW: 'box-shadow: 0 2px 10px rgba(0,80,20,0.18);',
            MASK: 'rgba(255, 255, 255, 0.7)',
            HOVER: 'rgba(0,143,17,0.08)',
            ZOOM_FILL: 'rgba(0,143,17,0.15)',
            ZOOM_AREA: 'rgba(0,143,17,0.10)',
            OOS: '#c62828',
            OOC: '#b26a00',
            TARGET: '#00838f'
        }
    };
    var T, INK, INK_DIM, GRID, AXIS, NEUTRAL, BAND;
    function setTheme(name) {
        T = THEMES[name] || THEMES.dark;
        INK = T.INK;
        INK_DIM = T.INK_DIM;
        GRID = T.GRID;
        AXIS = T.AXIS;
        NEUTRAL = T.NEUTRAL;
        BAND = T.BAND;
    }
    setTheme($('body').hasClass('theme-light') ? 'light' : 'dark');
    // spec 線的顏色依風格取 T.OOS / T.OOC，畫圖時才查（切換風格後會跟著變）
    var SPEC_KEYS = [
        { key: 'upper_oos', label: 'OOS', colorKey: 'OOS', type: 'solid' },
        { key: 'upper_ooc', label: 'OOC', colorKey: 'OOC', type: 'dashed' },
        { key: 'lower_ooc', label: 'OOC', colorKey: 'OOC', type: 'dashed' },
        { key: 'lower_oos', label: 'OOS', colorKey: 'OOS', type: 'solid' }
    ];
    var FONT = '"Share Tech Mono", Consolas, "Courier New", "Microsoft JhengHei", monospace';

    var COLOR = {};
    (function assignColors() {
        var counter = {};
        $.each(CFG.tools, function (i, tool) {
            var model = CFG.models[tool] || '';
            counter[model] = counter[model] || 0;
            COLOR[tool] = PALETTE[counter[model] % PALETTE.length];
            counter[model]++;
        });
    })();

    var GROUPS = {};
    $.each(CFG.groups, function (i, g) { GROUPS[g.id] = g; });

    function colorOf(tool) { return COLOR[tool] || NEUTRAL; }
    function lineTypeOf(i) { return LINE_TYPES[i % LINE_TYPES.length]; }
    function decalOf(i) { return DECALS[i % DECALS.length]; }
    function shortName(param, group) {
        // 圖例只顯示參數名稱與組標題不同的部分，例 M1BF_Z 組內的 M1BF_Z_C1 → C1
        var prefix = group.title + '_';
        return param.indexOf(prefix) === 0 && param.length > prefix.length ? param.substring(prefix.length) : param;
    }

    function baseOption() {
        return {
            backgroundColor: 'transparent',
            textStyle: { color: INK, fontFamily: FONT },
            animationDuration: 400,
            tooltip: {
                backgroundColor: T.TIP_BG,
                borderColor: INK_DIM,
                borderWidth: 1,
                textStyle: { color: INK, fontFamily: FONT, fontSize: 13 },
                extraCssText: T.TIP_SHADOW
            },
            grid: { left: 50, right: 24, top: 40, bottom: 40, containLabel: false }
        };
    }

    /**
     * Y 軸；config 有設上下限就固定，否則自動（自動時會把 spec 線納入範圍）。kind = single / newest / tbt
     * lines = specLines() 的結果。偏移模式的 newest / tbt 畫的是「值 - target」，軸名稱加註
     */
    function valueAxis(group, kind, lines) {
        var lim = (group.limits && group.limits[kind]) || {};
        var axis = {
            type: 'value',
            name: (group.unit || 'Data') + (group.offset && kind !== 'single' ? '（- target）' : ''),
            nameTextStyle: { color: INK_DIM },
            axisLine: { show: false },
            axisTick: { show: false },
            axisLabel: { color: INK_DIM },
            splitLine: { lineStyle: { color: GRID } },
            scale: kind !== 'newest'
        };
        if (group.offset && kind !== 'single') {
            axis.nameTextStyle.align = 'left';   // 名稱較長，靠左對齊才不會被裁掉
        }
        var spec = $.map(lines, function (l) { return l.value; });
        if (lim.min !== null && lim.min !== undefined) {
            axis.min = lim.min;
        } else if (spec.length) {
            axis.min = function (v) { return specBounds(v, spec, kind).min; };
        }
        if (lim.max !== null && lim.max !== undefined) {
            axis.max = lim.max;
        } else if (spec.length) {
            axis.max = function (v) { return specBounds(v, spec, kind).max; };
        }
        return axis;
    }

    // ---------- target（偏移模式） ----------
    // ini 有寫任何一行 target 的組 group.offset = true：spec 是距離 target 的偏移量，
    //   single：畫原始值，spec 線在 target ± 偏移，另畫 target 線
    //   newest / tbt：畫「值 - target」，所有機台共用 +偏移 / 0 / -偏移 的水平線
    // 取值順序：target.<機台>.<參數> → target.<機台> → target → 0（機台不分大小寫）

    /** 某台機台、某個參數的 target；不是偏移模式時為 0 */
    function targetOf(group, tool, param) {
        if (!group.offset) {
            return 0;
        }
        var t = group.targets ? group.targets[String(tool).toLowerCase()] : null;
        if (t && t.hasOwnProperty(param)) {
            return t[param];
        }
        if (t && t.hasOwnProperty('*')) {
            return t['*'];
        }
        var model = CFG.models[tool];
        if (model && group.targetModel && group.targetModel.hasOwnProperty(model)) {
            return group.targetModel[model];
        }
        return group.target || 0;
    }

    /**
     * 某台機台的 spec：每一條依 機台覆蓋 → 機型覆蓋 → 整組 取值（ini 的 upper_ooc.<機台或機型>）
     * 覆蓋值 null = 這台不畫這一條；tool 省略時為整組的 spec
     */
    function specOf(group, tool) {
        var out = {};
        var byTool = tool !== undefined && group.specTool ? group.specTool[String(tool).toLowerCase()] : null;
        var model = tool !== undefined ? CFG.models[tool] : '';
        var byModel = model && group.specModel ? group.specModel[model] : null;
        $.each(SPEC_KEYS, function (i, k) {
            var v = group.spec ? group.spec[k.key] : null;
            if (byTool && byTool.hasOwnProperty(k.key)) {
                v = byTool[k.key];
            } else if (byModel && byModel.hasOwnProperty(k.key)) {
                v = byModel[k.key];
            }
            out[k.key] = v;
        });
        return out;
    }

    /** 扣掉 target 的偏差，去掉浮點誤差（28.2 - 27 = 1.2 而不是 1.1999999） */
    function minus(v, t) {
        return parseFloat((v - t).toPrecision(12));
    }

    /** newest / tbt 的資料點：偏移模式時畫偏差，原始值放在 raw 給 tooltip 用 */
    function plotPoint(group, tool, param, v) {
        if (v === null || v === undefined) {
            return null;
        }
        if (!group.offset) {
            return v;
        }
        return { value: minus(v, targetOf(group, tool, param)), raw: v };
    }

    /** tooltip 顯示的原始值 */
    function rawOf(p) {
        return p.data && typeof p.data === 'object' && p.data.hasOwnProperty('raw') ? p.data.raw : p.value;
    }

    /**
     * 這張圖要畫的 spec 線：[{ key, value, text, colorKey, type }]
     *   絕對值模式：ini 的四條線（三個區塊相同）
     *   偏移模式 single：依所選機台每個參數的 target 算出 target ± 偏移與 target 線；
     *                    各參數 target 相同時只有一組線，不同時每個參數各一組，標籤加參數簡稱
     *   偏移模式 newest / tbt：+偏移 / 0（target）/ -偏移
     *   spec 依 specOf(group, tool) 取得（含機台 / 機型覆蓋）；newest / tbt 各台不同時見 spanLines()
     */
    function specLines(group, kind, tool) {
        var lines = [];
        var spec = specOf(group, tool);
        function add(t, prefix) {
            $.each(SPEC_KEYS, function (i, k) {
                var v = spec[k.key];
                if (v === null || v === undefined) {
                    return;
                }
                if (group.offset) {
                    v = k.key.indexOf('upper') === 0 ? minus(t, -v) : minus(t, v);
                }
                lines.push({ key: k.key, value: v, text: prefix + k.label + ' ' + fmtNum(v), colorKey: k.colorKey, type: k.type });
            });
            if (group.offset) {
                lines.push({ key: 'target', value: t, text: prefix + 'Target ' + fmtNum(t), colorKey: 'TARGET', type: 'dotted' });
            }
        }
        if (!group.offset) {
            add(0, '');
        } else if (kind !== 'single') {
            add(0, '');
        } else {
            var order = [];
            var byTarget = {};
            $.each(group.params, function (i, p) {
                var t = targetOf(group, tool, p);
                var key = String(t);
                if (!byTarget.hasOwnProperty(key)) {
                    byTarget[key] = { t: t, names: [] };
                    order.push(key);
                }
                byTarget[key].names.push(shortName(p, group));
            });
            $.each(order, function (i, key) {
                add(byTarget[key].t, order.length > 1 ? byTarget[key].names.join(',') + ' ' : '');
            });
        }
        return lines;
    }

    /**
     * newest / tbt：spans = [{ tool, from, to }]（X 軸類別的起訖位置）
     * 各台的 spec 線都相同 → 回傳一般水平線；不同 → 每台在自己的位置畫一段（line 多了 from / to / pad），
     * 標籤只在和前一台不同時顯示
     */
    function spanLines(group, kind, spans) {
        if (!spans.length) {
            return specLines(group, kind);
        }
        var sets = $.map(spans, function (s) { return [specLines(group, kind, s.tool)]; });
        function sig(set) {
            return $.map(set, function (l) { return l.key + '=' + l.value; }).join('|');
        }
        var same = true;
        for (var i = 1; i < sets.length; i++) {
            if (sig(sets[i]) !== sig(sets[0])) {
                same = false;
                break;
            }
        }
        if (same) {
            return sets[0];
        }
        var out = [];
        var prev = {};
        $.each(spans, function (i, s) {
            var cur = {};
            $.each(sets[i], function (j, l) {
                cur[l.key] = l.value;
                out.push($.extend({}, l, {
                    from: s.from,
                    to: s.to,
                    pad: kind === 'newest',
                    showLabel: prev[l.key] !== l.value
                }));
            });
            prev = cur;
        });
        return out;
    }

    function fmtNum(v) {
        return String(parseFloat(Number(v).toPrecision(10)));
    }

    /** 資料範圍 v（{min, max}）加上 spec 線後的 Y 軸上下限，取整到刻度間距 */
    function specBounds(v, spec, kind) {
        var lo = Math.min.apply(null, spec);
        var hi = Math.max.apply(null, spec);
        if (isFinite(v.min)) {
            lo = Math.min(lo, v.min);
        }
        if (isFinite(v.max)) {
            hi = Math.max(hi, v.max);
        }
        if (kind === 'newest') {   // 長條圖從 0 開始
            lo = Math.min(lo, 0);
            hi = Math.max(hi, 0);
        }
        var span = hi - lo;
        if (span <= 0) {
            span = Math.abs(hi) || 1;
        }
        var step = niceStep(span / 5);
        var min = Math.floor(lo / step) * step;
        var max = Math.ceil(hi / step) * step;
        return { min: +min.toFixed(10), max: +max.toFixed(10) };
    }

    function niceStep(x) {
        var p = Math.pow(10, Math.floor(Math.log(x) / Math.LN10));
        var f = x / p;
        return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * p;
    }

    /** spec 水平線：放在一條沒有資料的輔助 series 上；沒有線時回傳 null */
    function specSeries(specs) {
        var lines = [];
        $.each(specs, function (i, l) {
            if (l.from !== undefined) {
                return;   // 分段線另外畫（specSegments）
            }
            lines.push({
                name: l.key,
                yAxis: l.value,
                lineStyle: { color: T[l.colorKey], type: l.type, width: 1.5 },
                label: {
                    show: true,
                    position: l.key.indexOf('lower') === 0 ? 'insideEndBottom' : 'insideEndTop',
                    color: T[l.colorKey],
                    fontFamily: FONT,
                    formatter: l.text
                }
            });
        });
        if (!lines.length) {
            return null;
        }
        return {
            name: '_spec',
            type: 'line',
            data: [],
            silent: true,
            markLine: { silent: true, symbol: 'none', animation: false, data: lines }
        };
    }

    /**
     * 各台 spec 不同時的分段線（newest：每根長條的寬度；tbt：每台的區段）
     * 用 custom series 畫：data = [from, to, y, 第幾段]，位置由 X 軸類別索引換算
     */
    function specSegments(specs) {
        var segs = $.grep(specs, function (l) { return l.from !== undefined; });
        if (!segs.length) {
            return null;
        }
        var DASH = { solid: null, dashed: [6, 4], dotted: [2, 3] };
        return {
            name: '_spec',
            type: 'custom',
            silent: true,
            z: 5,
            clip: true,
            animation: false,
            encode: { x: [0, 1], y: 2 },
            data: $.map(segs, function (l, i) { return [[l.from, l.to, l.value, i]]; }),
            renderItem: function (params, api) {
                var l = segs[api.value(3)];
                var a = api.coord([api.value(0), api.value(2)]);
                var b = api.coord([api.value(1), api.value(2)]);
                if (l.pad) {
                    var w = api.size([1, 0])[0] * 0.45;
                    a[0] -= w;
                    b[0] += w;
                }
                var lower = l.key.indexOf('lower') === 0;
                var children = [{
                    type: 'line',
                    shape: { x1: a[0], y1: a[1], x2: b[0], y2: b[1] },
                    style: { stroke: T[l.colorKey], lineWidth: 1.5, lineDash: DASH[l.type] || null }
                }];
                if (l.showLabel) {
                    children.push({
                        type: 'text',
                        x: b[0] - 2,
                        y: a[1] + (lower ? 2 : -2),
                        style: {
                            text: l.text,
                            fill: T[l.colorKey],
                            font: '11px ' + FONT,
                            align: 'right',
                            verticalAlign: lower ? 'top' : 'bottom'
                        }
                    });
                }
                return { type: 'group', children: children };
            }
        };
    }

    /** 有 spec 時把輔助 series 加到 series 陣列最後 */
    function pushSpec(series, specs) {
        var s = specSeries(specs);
        if (s) {
            series.push(s);
        }
        var seg = specSegments(specs);
        if (seg) {
            series.push(seg);
        }
    }

    function axisLine() {
        return { lineStyle: { color: AXIS } };
    }

    function legendBase(data) {
        return {
            type: 'scroll',
            top: 0,
            data: data,
            textStyle: { color: INK, fontFamily: FONT },
            pageTextStyle: { color: INK },
            pageIconColor: INK,
            pageIconInactiveColor: AXIS,
            inactiveColor: T.INACTIVE,
            itemWidth: 26,
            itemHeight: 10
        };
    }

    function fmt(v) {
        return v === null || v === undefined ? '-' : AISLE.escape(v);
    }

    // ---------- 圖表盒 ----------
    // boxes['tbt:m1bf_z'] = { kind, group, $el, chart, shown }
    var boxes = {};
    var cache = { tbt: null };
    var request = null;

    function eachBox(kind, fn) {
        $.each(boxes, function (key, box) {
            if (!kind || box.kind === kind) {
                fn(box);
            }
        });
    }

    function showMessage(chart, text) {
        chart.clear();
        chart.setOption({
            title: {
                text: text,
                left: 'center',
                top: 'middle',
                textStyle: { color: INK_DIM, fontSize: 14, fontWeight: 'normal', fontFamily: FONT }
            }
        });
    }

    function loadingOpts() {
        return { text: 'LOADING...', color: INK, textColor: INK, maskColor: T.MASK, fontFamily: FONT };
    }

    /** 把資料畫到圖表盒；沒有資料時顯示 NO DATA，並更新資料表 */
    function show(box, data, draw) {
        box.shown = data;
        box.urlInfo = null;
        if ($.isEmptyObject(data)) {
            showMessage(box.chart, '> 查無資料 // NO DATA');
        } else {
            draw(box, data);
        }
        renderTable(box);
    }

    /** 資料表檢視：機台 / 時間 / 該組每個參數一欄 */
    function renderTable(box) {
        var params = box.group.params;
        var head = '<tr><th>TOOL</th><th>DATE</th>';
        $.each(params, function (i, p) { head += '<th>' + AISLE.escape(p) + '</th>'; });
        head += '</tr>';
        var rows = [];
        var withUrl = false;
        $.each(box.shown, function (tool, s) {
            if ($.isArray(s.Url)) {
                withUrl = true;
            }
        });
        if (withUrl) {
            head = head.replace(/<\/tr>$/, '<th>URL</th></tr>');
        }
        $.each(box.shown, function (tool, s) {
            if (!$.isArray(s.Date)) {
                // newest：各參數的日期分開存，值後面附上日期
                var tr0 = '<tr><td>' + AISLE.escape(tool) + '</td><td>LATEST</td>';
                $.each(params, function (j, p) {
                    tr0 += '<td>' + fmt(s.Data[p][0]) + (s.Date[p][0] ? ' <span class="dim">(' + AISLE.escape(s.Date[p][0]) + ')</span>' : '') + '</td>';
                });
                rows.push(tr0 + '</tr>');
                return;
            }
            for (var i = 0; i < s.Date.length; i++) {
                var tr = '<tr><td>' + AISLE.escape(tool) + '</td><td>' + AISLE.escape(s.Date[i]) + '</td>';
                for (var j = 0; j < params.length; j++) {
                    tr += '<td>' + fmt(s.Data[params[j]] ? s.Data[params[j]][i] : null) + '</td>';
                }
                if (withUrl) {
                    var u = $.isArray(s.Url) ? safeUrl(s.Url[i]) : null;
                    tr += '<td>' + (u ? '<a href="' + AISLE.escape(u) + '" target="_blank" rel="noopener noreferrer">開啟</a>' : '-') + '</td>';
                }
                rows.push(tr + '</tr>');
            }
        });
        box.$el.find('.cd-table').html(rows.length
            ? '<table class="table"><thead>' + head + '</thead><tbody>' + rows.join('') + '</tbody></table>'
            : '<p class="dim">&gt; 查無資料</p>');
    }

    // ---------- 1. single：所選機台，組內每個參數一條線 ----------
    function drawSingle(box, data) {
        var group = box.group;
        var tool;
        for (tool in data) {
            if (data.hasOwnProperty(tool)) {
                break;
            }
        }
        var s = data[tool];
        var urls = $.isArray(s.Url) ? s.Url : null;
        var series = [];
        $.each(group.params, function (pi, param) {
            var points = [];
            var col = s.Data[param] || [];
            for (var i = 0; i < s.Date.length; i++) {
                points.push([s.Date[i], col[i] === undefined ? null : col[i]]);
            }
            series.push({
                name: param,
                type: 'line',
                data: points,
                connectNulls: !!group.connect,   // ini 的 connect = On：跳過空值把線連起來
                showSymbol: points.length <= 45,
                symbolSize: 8,
                color: colorOf(tool),
                lineStyle: { width: 2, type: lineTypeOf(pi) },
                emphasis: { focus: 'series' }
            });
        });
        var opt = baseOption();
        opt.legend = legendBase(group.params.slice(0));
        opt.legend.formatter = function (name) { return shortName(name, group); };
        opt.tooltip.trigger = 'axis';
        opt.tooltip.axisPointer = { type: 'line', lineStyle: { color: INK_DIM, type: 'dashed' } };
        // tooltip 標題一律顯示完整時間（後端已統一成 Y-m-d H:i:s）；有 Url 時多一行提示，點數據點開新分頁（見 bindUrlClick）
        opt.tooltip.formatter = function (params) {
            var html = '';
            var idx = null;
            $.each(params, function (i, p) {
                if (p.seriesName === '_spec') {
                    return;
                }
                if (!html) {
                    html = AISLE.escape(s.Date[p.dataIndex]);
                    idx = p.dataIndex;
                }
                html += '<br>' + p.marker + AISLE.escape(p.seriesName) + '：<b>' + fmt(p.value[1]) + '</b>';
            });
            if (urls && idx !== null && safeUrl(urls[idx])) {
                html += '<br><span style="color:' + INK_DIM + '">&gt; 點擊數據點開啟連結</span>';
            }
            return html;
        };
        if (urls) {
            box.urlInfo = { dates: s.Date, urls: urls, cols: $.map(group.params, function (p) { return [s.Data[p] || []]; }) };
        }
        opt.xAxis = {
            type: 'time',
            axisLine: axisLine(),
            axisLabel: { color: INK_DIM, hideOverlap: true, formatter: '{yyyy}-{MM}-{dd} {HH}:{mm}:{ss}' },
            splitLine: { show: false }
        };
        var specs = specLines(group, 'single', tool);
        opt.yAxis = valueAxis(group, 'single', specs);
        opt.dataZoom = [{ type: 'inside' }];
        pushSpec(series, specs);
        opt.series = series;
        box.chart.clear();
        box.chart.setOption(opt);
    }

    // ---------- 2. newest：X 軸機台，組內每個參數一根長條 ----------
    function drawNewest(box, data) {
        var group = box.group;
        var tools = [];
        $.each(data, function (tool) { tools.push(tool); });
        var series = [];
        $.each(group.params, function (pi, param) {
            var bars = [];
            $.each(tools, function (ti, tool) {
                var s = data[tool];
                var style = { color: colorOf(tool), borderRadius: [4, 4, 0, 0] };
                if (decalOf(pi)) {
                    style.decal = decalOf(pi);
                }
                var v = s.Data[param][0];
                var bar = { value: v, raw: v, date: s.Date[param][0], itemStyle: style };
                if (group.offset && v !== null && v !== undefined) {
                    bar.value = minus(v, targetOf(group, tool, param));   // 偏移模式畫「值 - target」
                }
                bars.push(bar);
            });
            // series 的 itemStyle 只決定圖例的樣子（中性色 + 該參數的紋理），每根長條另用機台色
            var legendStyle = { color: NEUTRAL };
            if (decalOf(pi)) {
                legendStyle.decal = decalOf(pi);
            }
            series.push({
                name: param,
                type: 'bar',
                data: bars,
                barMaxWidth: 28,
                barGap: '15%',
                itemStyle: legendStyle,
                emphasis: { focus: 'series', itemStyle: { shadowBlur: 12, shadowColor: INK } },
                cursor: 'pointer'
            });
        });
        var opt = baseOption();
        opt.legend = legendBase(group.params.slice(0));
        opt.legend.formatter = function (name) { return shortName(name, group); };
        opt.tooltip.trigger = 'axis';
        opt.tooltip.axisPointer = { type: 'shadow', shadowStyle: { color: T.HOVER } };
        opt.tooltip.formatter = function (params) {
            if (!params.length) {
                return '';
            }
            var html = '<b>' + AISLE.escape(params[0].name) + '</b>';
            $.each(params, function (i, p) {
                if (p.seriesName === '_spec') {
                    return;
                }
                html += '<br>' + p.marker + AISLE.escape(p.seriesName) + '：<b>' + fmt(rawOf(p)) + '</b>' +
                    ' <span style="color:' + INK_DIM + '">(' + fmt(p.data.date) + ')</span>';
            });
            return html;
        };
        opt.xAxis = {
            type: 'category',
            data: tools,
            axisLine: axisLine(),
            axisTick: { show: false },
            axisLabel: { color: INK, interval: 0, rotate: tools.length > 14 ? 45 : 0 }
        };
        var specs = spanLines(group, 'newest', $.map(tools, function (t, i) { return [{ tool: t, from: i, to: i }]; }));
        opt.yAxis = valueAxis(group, 'newest', specs);
        pushSpec(series, specs);
        opt.series = series;
        box.chart.clear();
        box.chart.setOption(opt);
    }

    // ---------- 3. TBT ----------
    // X 軸為類別軸：每台機台佔一段，段內依時間排列。每台每個參數一條 series，
    // 同一台的 series 用同一個名稱，所以圖例以機台為單位開關；其他段落填 null，
    // 所以每台只畫在自己的區段，相鄰機台以交錯底色分隔。
    function drawTbt(box, data) {
        var group = box.group;
        var keys = [];      // 類別值（唯一）："APAN01|2026-01-01"
        var segments = [];  // { tool, from, to }
        $.each(data, function (tool, s) {
            var from = keys.length;
            for (var i = 0; i < s.Date.length; i++) {
                keys.push(tool + '|' + s.Date[i]);
            }
            segments.push({ tool: tool, from: from, to: keys.length - 1 });
        });

        var centers = {};
        var bands = [];
        $.each(segments, function (i, seg) {
            centers[Math.floor((seg.from + seg.to) / 2)] = seg.tool;
            if (i % 2 === 1) {
                bands.push([{ xAxis: keys[seg.from] }, { xAxis: keys[seg.to] }]);
            }
        });

        var series = [];
        var legend = [];
        var paramOf = [];   // seriesIndex → 參數名稱（tooltip 用）
        $.each(segments, function (i, seg) {
            var s = data[seg.tool];
            legend.push(seg.tool);
            $.each(group.params, function (pi, param) {
                var col = s.Data[param] || [];
                var arr = new Array(keys.length);
                for (var k = 0; k < keys.length; k++) {
                    arr[k] = null;
                }
                for (var j = 0; j < s.Date.length; j++) {
                    arr[seg.from + j] = plotPoint(group, seg.tool, param, col[j]);   // 偏移模式為「值 - target」
                }
                paramOf.push(param);
                series.push({
                    name: seg.tool,
                    type: 'line',
                    data: arr,
                    connectNulls: !!group.connect,   // ini 的 connect = On：跳過空值把線連起來
                    showSymbol: false,
                    symbolSize: 8,
                    color: colorOf(seg.tool),
                    lineStyle: { width: 2, type: lineTypeOf(pi) },
                    emphasis: { focus: 'series' }
                });
            });
        });
        // 交錯底色放在一條不顯示的輔助 series 上
        series.push({
            name: '_bands',
            type: 'line',
            data: [],
            silent: true,
            markArea: { silent: true, itemStyle: { color: BAND }, data: bands }
        });

        // 機台名稱轉 90 度：X 軸下方的高度依最長的名稱計算（名稱 + 刻度間距 + 縮放拉桿），
        // 圖表高度同步加高，資料區不會因為名稱變長而被壓縮
        var labelPx = 0;
        $.each(segments, function (i, seg) { labelPx = Math.max(labelPx, textWidth(seg.tool)); });
        var bottom = Math.max(70, Math.ceil(labelPx) + 52);
        fitHeight(box, bottom - 70);

        var opt = baseOption();
        opt.grid.top = 64;
        opt.grid.bottom = bottom;
        opt.legend = legendBase(legend);
        opt.tooltip.trigger = 'axis';
        opt.tooltip.axisPointer = { type: 'line', lineStyle: { color: INK_DIM, type: 'dashed' } };
        opt.tooltip.formatter = function (params) {
            var html = '';
            $.each(params, function (i, p) {
                if (p.seriesName === '_bands' || p.seriesName === '_spec' || p.value === null || p.value === undefined) {
                    return;
                }
                if (!html) {
                    html = p.marker + AISLE.escape(p.seriesName) + '<br>' + AISLE.escape(String(p.name).split('|')[1]);
                }
                html += '<br>' + AISLE.escape(paramOf[p.seriesIndex]) + '：<b>' + fmt(rawOf(p)) + '</b>';
            });
            return html;
        };
        opt.xAxis = {
            type: 'category',
            data: keys,
            boundaryGap: false,
            axisLine: axisLine(),
            axisTick: { show: false },
            axisLabel: {
                color: INK,
                rotate: 90,
                interval: function (idx) { return centers.hasOwnProperty(idx); },
                formatter: function (v) { return String(v).split('|')[0]; }
            },
            splitLine: { show: false }
        };
        var specs = spanLines(group, 'tbt', segments);
        opt.yAxis = valueAxis(group, 'tbt', specs);
        // weakFilter：縮放時橫跨視窗邊界的 spec 分段線（各台 spec 不同時）也會保留，一般資料線不受影響
        opt.dataZoom = [
            { type: 'inside', filterMode: 'weakFilter' },
            {
                type: 'slider', height: 18, bottom: 12, filterMode: 'weakFilter',
                borderColor: AXIS, fillerColor: T.ZOOM_FILL,
                handleStyle: { color: INK_DIM }, moveHandleStyle: { color: INK_DIM },
                dataBackground: { lineStyle: { color: AXIS }, areaStyle: { color: T.ZOOM_AREA } },
                textStyle: { color: INK_DIM },
                labelFormatter: function (i, v) { return String(v).replace('|', ' '); }
            }
        ];
        pushSpec(series, specs);
        opt.series = series;
        box.chart.clear();
        box.chart.setOption(opt);

        var styles = $.map(group.params, function (p, i) {
            return shortName(p, group) + ' ' + LINE_NAMES[i % LINE_NAMES.length];
        });
        box.$el.find('.cd-box-info').text(segments.length + ' 台 · ' + keys.length + ' 個時間點 · ' + styles.join(' / '));
    }

    /** 文字在圖表字型下的寬度（px），用來計算轉 90 度後需要的高度 */
    var measureCtx = null;
    function textWidth(text) {
        if (measureCtx === null) {
            var c = document.createElement('canvas');
            measureCtx = c.getContext ? c.getContext('2d') : false;
        }
        if (!measureCtx) {
            return String(text).length * 8;
        }
        measureCtx.font = '12px ' + FONT;
        return measureCtx.measureText(String(text)).width;
    }

    /** 圖表高度 = CSS 原本的高度 + extra（px）；高度有變時通知 ECharts 重新排版 */
    function fitHeight(box, extra) {
        var $c = box.$el.find('.cd-chart');
        var before = $c.height();
        $c.css('height', '');                       // 先還原，讀 CSS（含手機版）的原始高度
        var h = $c.height() + Math.max(0, extra);
        $c.height(h);
        if (h !== before) {
            box.chart.resize();
        }
    }

    // ---------- single 點擊數據點開啟 Url ----------
    /** 只接受 http://、https:// 或 / 開頭（不含 //）的網址，其他回傳 null（後端已過濾，這裡再擋一次） */
    function safeUrl(u) {
        if (typeof u !== 'string') {
            return null;
        }
        u = $.trim(u);
        return /^https?:\/\/\S+$/i.test(u) || /^\/(?!\/)\S*$/.test(u) ? u : null;
    }

    /** 滑鼠位置最近、且有網址的數據點（距離 14px 內），回傳網址或 null */
    function urlAt(box, x, y) {
        var info = box.urlInfo;
        if (!info || !box.chart.containPixel('grid', [x, y])) {
            return null;
        }
        var best = null;
        var bestDist = 14 * 14;
        for (var i = 0; i < info.dates.length; i++) {
            var u = safeUrl(info.urls[i]);
            if (!u) {
                continue;
            }
            for (var c = 0; c < info.cols.length; c++) {
                var v = info.cols[c][i];
                if (v === null || v === undefined) {
                    continue;
                }
                var pt = box.chart.convertToPixel({ xAxisIndex: 0, yAxisIndex: 0 }, [info.dates[i], v]);
                if (!pt) {
                    continue;
                }
                var d = (pt[0] - x) * (pt[0] - x) + (pt[1] - y) * (pt[1] - y);
                if (d <= bestDist) {
                    bestDist = d;
                    best = u;
                }
            }
        }
        return best;
    }

    /** single 圖：點擊數據點在新分頁開啟網址；滑過有網址的點顯示手指游標。沒有 Url 時不做任何事 */
    function bindUrlClick(box) {
        var zr = box.chart.getZr();
        zr.on('mousemove', function (e) {
            if (box.urlInfo && urlAt(box, e.offsetX, e.offsetY)) {
                zr.setCursorStyle('pointer');
            }
        });
        zr.on('click', function (e) {
            var u = urlAt(box, e.offsetX, e.offsetY);
            if (u) {
                var w = window.open(u, '_blank');
                if (w) {
                    w.opener = null;
                }
            }
        });
    }

    // ---------- 從 cache 取出各圖要的資料 ----------
    /** 只保留某組的參數（有 Url 時一併帶上） */
    function pick(s, params) {
        var out = { Date: s.Date, Data: {} };
        $.each(params, function (i, p) { out.Data[p] = s.Data[p] || []; });
        if ($.isArray(s.Url)) {
            out.Url = s.Url;
        }
        return out;
    }

    function showTbt() {
        eachBox('tbt', function (box) {
            var data = {};
            $.each(cache.tbt, function (tool, s) { data[tool] = pick(s, box.group.params); });
            show(box, data, drawTbt);
        });
    }

    /** 單台機台：從 cache.tbt 取出，不發 request */
    function showSingle() {
        var tool = $('#cd-tool').val();
        $('.cd-single-title').text(tool ? '· ' + tool : '');
        if (!cache.tbt || !tool) {
            return;
        }
        eachBox('single', function (box) {
            var data = {};
            if (cache.tbt[tool]) {
                data[tool] = pick(cache.tbt[tool], box.group.params);
            }
            show(box, data, drawSingle);
        });
    }

    /**
     * 最新值：每台每個參數在區間內最後一個非 null 的值，再依機型篩選，不發 request
     * 各參數最後一筆的日期可能不同，所以 Date 也依參數分開存：
     *   { Date: { param: [日期] }, Data: { param: [值] } }
     */
    function showNewest() {
        if (!cache.tbt) {
            return;
        }
        var model = $('#cd-model').val() || 'All';  // ini 沒設定機型時沒有下拉選單
        eachBox('newest', function (box) {
            var data = {};
            $.each(cache.tbt, function (tool, s) {
                if (model !== 'All' && CFG.models[tool] !== model) {
                    return;
                }
                var latest = { Date: {}, Data: {} };
                var any = false;
                $.each(box.group.params, function (pi, p) {
                    var col = s.Data[p] || [];
                    latest.Date[p] = [null];
                    latest.Data[p] = [null];
                    for (var i = col.length - 1; i >= 0; i--) {
                        if (col[i] !== null && col[i] !== undefined) {
                            latest.Date[p] = [s.Date[i]];
                            latest.Data[p] = [col[i]];
                            any = true;
                            break;
                        }
                    }
                });
                if (any) {
                    data[tool] = latest;
                }
            });
            show(box, data, drawNewest);
        });
    }

    // ---------- 選擇器 ----------
    function pad(n) { return n < 10 ? '0' + n : String(n); }
    function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function isDate(s) { return /^\d{4}-\d{2}-\d{2}$/.test(s); }

    function range() {
        var start = $.trim($('#cd-start').val());
        var end = $.trim($('#cd-end').val());
        var $msg = $('#cd-msg').text('');
        if (!isDate(start) || !isDate(end)) {
            $msg.text('> 日期格式需為 YYYY-MM-DD');
            return null;
        }
        if (start > end) {
            $msg.text('> 起始日期不可晚於結束日期');
            return null;
        }
        return { start: start, end: end };
    }

    /** 取日期區間內全部機台、全部參數的資料（所有圖共用），進頁面與改日期時呼叫 */
    function loadRange() {
        var r = range();
        if (!r) {
            return;
        }
        if (request) {
            request.abort();
        }
        eachBox(null, function (box) { box.chart.showLoading('default', loadingOpts()); });
        request = $.ajax({ url: CFG.urls.tbt, data: { start: r.start, end: r.end }, dataType: 'json', cache: false })
            .done(function (res) {
                cache.tbt = res || {};
                eachBox(null, function (box) { box.chart.hideLoading(); });
                showTbt();
                showSingle();
                showNewest();
            })
            .fail(function (xhr, status) {
                if (status === 'abort') {
                    return;
                }
                var msg = (xhr.responseJSON && xhr.responseJSON.error) || ('HTTP ' + xhr.status);
                eachBox(null, function (box) {
                    box.chart.hideLoading();
                    showMessage(box.chart, '> ERROR: ' + msg);
                });
            });
    }

    $(function () {
        if (!window.echarts) {
            $('.cd-chart').html('<p class="text-red">&gt; ECharts 載入失敗：請確認可連到 CDN，或執行 scripts/shell/fetch_vendor.sh 放到 public/assets/vendor/</p>');
            return;
        }
        $('.cd-box').each(function () {
            var $el = $(this);
            var kind = $el.data('kind');
            var group = GROUPS[$el.data('group')];
            boxes[kind + ':' + group.id] = {
                kind: kind,
                group: group,
                $el: $el,
                chart: window.echarts.init($el.find('.cd-chart')[0]),
                shown: {},
                urlInfo: null
            };
            if (kind === 'single') {
                bindUrlClick(boxes[kind + ':' + group.id]);
            }
        });

        if (!CFG.tools.length) {
            eachBox(null, function (box) { showMessage(box.chart, '> 沒有任何機台 // NO TOOLS'); });
            return;
        }

        $('#cd-filters').on('submit', function () {
            loadRange();
            return false;
        });
        $('#cd-tool').on('change', showSingle);
        $('#cd-model').on('change', showNewest);
        $('.cd-quick').on('click', 'button', function () {
            var days = parseInt($(this).data('days'), 10);
            var end = new Date();
            var start = new Date(end.getTime() - (days - 1) * 86400000);
            $('#cd-start').val(ymd(start));
            $('#cd-end').val(ymd(end));
            loadRange();
        });

        // 點最新值長條 → 切換單台機台
        eachBox('newest', function (box) {
            box.chart.on('click', function (p) {
                if (p.componentType === 'series' && p.name) {
                    $('#cd-tool').val(p.name);
                    showSingle();
                    var $first = $('.cd-box[data-kind="single"]').first();
                    if ($first.length) {
                        $('html, body').animate({ scrollTop: $first.offset().top - 80 }, 200);
                    }
                }
            });
        });

        function boxOf(el) {
            var $el = $(el).closest('.cd-box');
            return boxes[$el.data('kind') + ':' + $el.data('group')];
        }
        $('.cd-legend-all').on('click', function () {
            boxOf(this).chart.dispatchAction({ type: 'legendAllSelect' });
        });
        $('.cd-legend-none').on('click', function () {
            var box = boxOf(this);
            $.each(box.shown, function (tool) {
                box.chart.dispatchAction({ type: 'legendUnSelect', name: tool });
            });
        });
        $('.cd-table-btn').on('click', function () {
            boxOf(this).$el.find('.cd-table').toggle();
        });

        // 切換畫面風格：換配色後用 cache 重畫，不重新取資料
        $(document).on('aisle:theme', function (e, theme) {
            setTheme(theme);
            if (cache.tbt) {
                showTbt();
                showSingle();
                showNewest();
            }
        });

        var resizeTimer = null;
        $(window).on('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                eachBox(null, function (box) { box.chart.resize(); });
            }, 150);
        });

        loadRange();

        // 網址帶 &tool=（例如從選機表點數值格過來）：捲到單台機台趨勢
        if (CFG.focusTool) {
            var $single = $('.cd-box[data-kind="single"]').first();
            if ($single.length) {
                $('html, body').scrollTop($single.offset().top - 80);
            }
        }
    });
})(window.jQuery, window);
