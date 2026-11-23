/*
 * AISLE 設備選機表（jQuery 1.12，ES5 寫法）
 *   - 機型頁籤：每個機型各自的機台、分類、項目、spec、數值
 *   - 項目篩選（列）：分類多選下拉 + 關鍵字；機台篩選（欄）：可搜尋的多選下拉
 *   - 數值超出 OOC / OOS 依設定上底色；項目名稱 / 數值格可連到 chart（href 的 {tool} 換成機台）
 *   - 篩選全部在瀏覽器端進行，條件依機型記在 localStorage
 *   - 全螢幕：標題列按鈕或快捷鍵 F；Esc 恢復（瀏覽器不允許全螢幕時改成鋪滿瀏覽器視窗）
 * 設定由 view 放在 window.TOOL_SELECT
 */
(function ($) {
    if (!$ || !window.TOOL_SELECT) {
        return;
    }
    var CFG = window.TOOL_SELECT;
    var MODEL_KEY = 'aisle.tool_select.model';
    var FILTER_KEY = 'aisle.tool_select.filter.';
    var SPEC_NAMES = ['uOOS', 'uOOC', 'lOOC', 'lOOS'];

    var state = {
        model: '',
        data: null,
        request: null,
        // 存「被取消勾選的」分類 / 機台，設定檔之後新增的分類、機台預設會顯示
        hiddenCats: {},
        hiddenTools: {},
        q: '',
        oocOnly: false
    };
    var catDd = null, toolDd = null;

    // ==================================================================
    // 小工具
    // ==================================================================
    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function store(key, value) {
        try {
            if (value === undefined) {
                return window.localStorage.getItem(key);
            }
            window.localStorage.setItem(key, value);
        } catch (e) { /* 私密模式等：不記憶 */ }
        return null;
    }
    function isLight() { return $('body').hasClass('theme-light'); }
    function keys(obj) {
        var out = [];
        $.each(obj, function (k, v) { if (v) { out.push(k); } });
        return out;
    }
    function toSet(list) {
        var o = {};
        $.each(list || [], function (i, v) { o[v] = true; });
        return o;
    }

    /** 數字顯示：有 decimals 就固定位數，否則去掉浮點數尾巴 */
    function fmt(v, decimals) {
        if (v === null || v === undefined) {
            return '-';
        }
        if (decimals !== null && decimals !== undefined) {
            return v.toFixed(decimals);
        }
        return String(parseFloat(v.toPrecision(10)));
    }
    function specText(v) { return v === null || v === undefined ? '-' : String(parseFloat(v.toPrecision(10))); }

    /** 剛好等於界限算正常 */
    function judge(v, s) {
        if (v === null || v === undefined) {
            return '';
        }
        if ((s[0] !== null && v > s[0]) || (s[3] !== null && v < s[3])) {
            return 'oos';
        }
        if ((s[1] !== null && v > s[1]) || (s[2] !== null && v < s[2])) {
            return 'ooc';
        }
        return '';
    }
    /** 偏移模式的 spec 欄：upper 加 +、lower 加 - */
    function specCell(item, k) {
        var v = item.spec[k];
        if (!item.offset || v === null || v === undefined) {
            return specText(v);
        }
        return (k < 2 ? '+' : '-') + specText(v);
    }
    /** 偏移模式：該台的 target */
    function targetOf(item, tool) {
        return item.targets && item.targets.hasOwnProperty(tool) ? item.targets[tool] : item.target;
    }
    /** 某台的有效 spec（絕對值）；偏移模式時伺服器已換算成 target ± 偏移 */
    function specOf(item, tool) {
        if (item.offset) {
            return item.abs && item.abs[tool] ? item.abs[tool] : [null, null, null, null];
        }
        return item.toolSpec && item.toolSpec[tool] ? item.toolSpec[tool] : item.spec;
    }

    /** 依底色亮度決定文字用黑或白 */
    function textOn(hex) {
        var h = hex.replace('#', '');
        if (h.length === 3) {
            h = h.charAt(0) + h.charAt(0) + h.charAt(1) + h.charAt(1) + h.charAt(2) + h.charAt(2);
        }
        var r = parseInt(h.substr(0, 2), 16), g = parseInt(h.substr(2, 2), 16), b = parseInt(h.substr(4, 2), 16);
        return (r * 299 + g * 587 + b * 114) / 1000 > 140 ? '#000000' : '#FFFFFF';
    }
    function bgOf(item, level) {
        var k = level + '_bg' + (isLight() ? '_light' : '');
        return (item.colors && item.colors[k]) || state.data.colors[k];
    }

    /** href：{tool} 換成機台（點項目名稱時為空白）；?m=... 接上網站路徑前綴 */
    function hrefOf(item, tool) {
        if (!item.href) {
            return '';
        }
        var h = item.href.replace(/\{tool\}/g, tool ? encodeURIComponent(tool) : '');
        return h.charAt(0) === '?' ? CFG.urls.base + h : h;
    }

    // ==================================================================
    // 多選下拉（分類、機台共用）
    // ==================================================================
    /**
     * opts: { search: bool, noun: '台', placeholder, onChange(hiddenSet) }
     * setOptions(list[{value,label}], hiddenSet)
     */
    function MultiDropdown($root, opts) {
        var self = this;
        this.$root = $root;
        this.opts = opts;
        this.list = [];
        this.hidden = {};
        $root.html(
            '<button type="button" class="ts-dd-btn"><span class="ts-dd-text"></span> <span class="ts-dd-caret">▾</span></button>' +
            '<div class="ts-dd-panel" style="display:none">' +
            (opts.search ? '<input type="text" class="ts-dd-search" placeholder="' + esc(opts.placeholder || '搜尋') + '" autocomplete="off">' : '') +
            '<div class="ts-dd-actions">' +
            '<button type="button" class="btn btn-sm" data-act="all">全選</button>' +
            '<button type="button" class="btn btn-sm" data-act="none">全不選</button>' +
            '<button type="button" class="btn btn-sm" data-act="invert">反選</button>' +
            '</div><div class="ts-dd-list"></div></div>'
        );
        this.$btn = $root.find('.ts-dd-btn');
        this.$panel = $root.find('.ts-dd-panel');
        this.$list = $root.find('.ts-dd-list');
        this.$search = $root.find('.ts-dd-search');

        this.$btn.on('click', function () {
            var open = self.$panel.is(':visible');
            $('.ts-dd-panel').hide();
            if (!open) {
                self.$panel.show();
                self.$search.val('');
                self.filterList();
                self.$search.focus();
            }
        });
        this.$search.on('input keyup', function () { self.filterList(); });
        this.$list.on('change', 'input', function () {
            self.hidden[this.value] = !this.checked;
            self.changed();
        });
        $root.find('.ts-dd-actions').on('click', 'button', function () {
            var act = $(this).data('act');
            // 搜尋中只作用在看得到的項目
            self.$list.find('label:visible input').each(function () {
                var on = act === 'all' ? true : (act === 'none' ? false : !this.checked);
                this.checked = on;
                self.hidden[this.value] = !on;
            });
            self.changed();
        });
    }
    MultiDropdown.prototype.setOptions = function (list, hidden) {
        var html = '';
        this.list = list;
        this.hidden = {};
        for (var i = 0; i < list.length; i++) {
            var v = list[i].value;
            if (hidden[v]) {
                this.hidden[v] = true;
            }
            html += '<label class="ts-dd-item" data-text="' + esc(String(list[i].label).toLowerCase()) + '">' +
                '<input type="checkbox" value="' + esc(v) + '"' + (hidden[v] ? '' : ' checked') + '> ' + esc(list[i].label) + '</label>';
        }
        this.$list.html(html || '<div class="dim">（無）</div>');
        this.updateText();
    };
    MultiDropdown.prototype.selected = function () {
        var out = [];
        for (var i = 0; i < this.list.length; i++) {
            if (!this.hidden[this.list[i].value]) {
                out.push(this.list[i]);
            }
        }
        return out;
    };
    MultiDropdown.prototype.filterList = function () {
        var q = $.trim(this.$search.val() || '').toLowerCase();
        this.$list.find('label').each(function () {
            $(this).toggle(q === '' || $(this).attr('data-text').indexOf(q) >= 0);
        });
    };
    MultiDropdown.prototype.updateText = function () {
        var sel = this.selected(), n = this.list.length, text;
        if (sel.length === n) {
            text = '全部' + (n ? ' ' + n + ' ' + this.opts.noun : '');
        } else if (!sel.length) {
            text = '未選擇';
        } else if (sel.length <= 2) {
            text = $.map(sel, function (o) { return o.label; }).join(', ');
        } else {
            text = sel[0].label + ', ' + sel[1].label + ' +' + (sel.length - 2) + '（' + sel.length + ' / ' + n + '）';
        }
        this.$btn.find('.ts-dd-text').text(text);
        this.$btn.toggleClass('is-filtered', sel.length !== n);
    };
    MultiDropdown.prototype.changed = function () {
        this.updateText();
        this.opts.onChange(this.hidden);
    };

    // 點下拉以外的地方收起（Esc 在下面的全螢幕區塊一起處理）
    $(document).on('mousedown', function (e) {
        if (!$(e.target).closest('.ts-dd').length) {
            $('.ts-dd-panel').hide();
        }
    });

    // ==================================================================
    // 全螢幕 / 恢復（做法同借機看板）
    //   優先用瀏覽器全螢幕 API；瀏覽器不允許時改成「頁面內最大化」（蓋滿瀏覽器視窗）
    //   Esc：先收起開著的下拉選單，再按一次離開全螢幕
    // ==================================================================
    var FS_TEXT = { off: '[ ⛶ 全螢幕 ]', on: '[ 🗗 恢復 ]' };
    function rootEl() { return document.getElementById('ts-root'); }
    function fsElement() {
        return document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement || null;
    }
    function isFull() { return $('#ts-root').hasClass('ts-full'); }
    function fitWrap() {
        var $wrap = $('#ts-wrap');
        if (isFull()) {
            var top = $wrap.offset().top - $(window).scrollTop();
            $wrap.css('max-height', Math.max(200, $(window).height() - top - 16) + 'px');
        } else {
            $wrap.css('max-height', '');
        }
    }
    function setFull(on, nativeFs) {
        $('#ts-root').toggleClass('ts-full', on).toggleClass('ts-full-native', on && !!nativeFs);
        $('body').toggleClass('ts-body-full', on);
        $('#ts-fs').text(on ? FS_TEXT.on : FS_TEXT.off).attr('title', on ? '恢復正常大小（Esc）' : '選機表全螢幕（快捷鍵 F）');
        setTimeout(fitWrap, 0);
    }
    function enterFull() {
        var el = rootEl();
        var req = el.requestFullscreen || el.webkitRequestFullscreen || el.msRequestFullscreen;
        if (!req) {
            setFull(true, false);
            return;
        }
        try {
            var p = req.call(el);
            if (p && p.then) {
                p.then(null, function () { setFull(true, false); });   // 被瀏覽器或政策擋下：頁面內最大化
            }
        } catch (err) {
            setFull(true, false);
        }
    }
    function exitFull() {
        if (fsElement()) {
            var ex = document.exitFullscreen || document.webkitExitFullscreen || document.msExitFullscreen;
            if (ex) {
                ex.call(document);
                return;
            }
        }
        setFull(false);
    }
    function toggleFull() {
        if (isFull()) {
            exitFull();
        } else {
            enterFull();
        }
    }
    $(document).on('fullscreenchange webkitfullscreenchange MSFullscreenChange', function () {
        var el = fsElement();
        if (el && el === rootEl()) {
            setFull(true, true);
        } else if (!el && $('#ts-root').hasClass('ts-full-native')) {
            setFull(false);
        }
    });
    $(document).on('keydown', function (e) {
        if (e.ctrlKey || e.altKey || e.metaKey) {
            return;
        }
        if (e.which === 27) {
            // 先收起下拉選單；沒有開著的下拉選單時離開全螢幕
            // （瀏覽器全螢幕時多數瀏覽器自己會處理 Esc，這裡再補一次，收不到也沒關係）
            if ($('.ts-dd-panel:visible').length) {
                $('.ts-dd-panel').hide();
            } else if (isFull()) {
                exitFull();
            }
            return;
        }
        if ($(e.target).is('input, textarea, select')) {
            return;
        }
        if ((e.key === 'f' || e.key === 'F' || e.which === 70) && !e.shiftKey) {
            e.preventDefault();
            toggleFull();
        }
    });
    $(window).on('resize', fitWrap);

    // ==================================================================
    // 篩選條件記憶
    // ==================================================================
    function loadFilter(model) {
        var f = null;
        try {
            f = JSON.parse(store(FILTER_KEY + model) || 'null');
        } catch (e) { f = null; }
        f = f || {};
        state.hiddenCats = toSet(f.hiddenCats);
        state.hiddenTools = toSet(f.hiddenTools);
        state.q = typeof f.q === 'string' ? f.q : '';
        state.oocOnly = !!f.oocOnly;
    }
    function saveFilter() {
        if (!state.model) {
            return;
        }
        store(FILTER_KEY + state.model, JSON.stringify({
            hiddenCats: keys(state.hiddenCats),
            hiddenTools: keys(state.hiddenTools),
            q: state.q,
            oocOnly: state.oocOnly
        }));
    }

    // ==================================================================
    // 載入
    // ==================================================================
    function selectModel(model) {
        var ok = false;
        $.each(CFG.models, function (i, m) { if (m.key === model) { ok = true; } });
        if (!ok) {
            model = CFG.models.length ? CFG.models[0].key : '';
        }
        if (!model) {
            $('#ts-wrap').html('<div class="dim ts-empty">&gt; 沒有任何機型</div>');
            return;
        }
        state.model = model;
        store(MODEL_KEY, model);
        $('#ts-tabs .pi-tab').removeClass('active').filter('[data-model="' + model + '"]').addClass('active');
        if (window.history && window.history.replaceState) {
            var url = window.location.href.replace(/([?&])model=[^&#]*(&)?/, function (all, p, amp) { return amp ? p : ''; })
                .replace(/[?&]$/, '').replace(/#.*$/, '');
            window.history.replaceState(null, '', url + (url.indexOf('?') >= 0 ? '&' : '?') + 'model=' + encodeURIComponent(model));
        }
        loadFilter(model);
        $('#ts-q').val(state.q);
        $('#ts-ooc-only').prop('checked', state.oocOnly);
        load();
    }

    function load() {
        if (state.request) {
            state.request.abort();
        }
        $('#ts-error').hide();
        $('#ts-refresh').prop('disabled', true);
        state.request = $.ajax({ url: CFG.urls.data, data: { model: state.model }, dataType: 'json', cache: false })
            .done(function (res) {
                state.data = res;
                $('#ts-title').text('TOOL SELECT // ' + res.label);
                $('#ts-updated').text(res.updated ? '資料時間 ' + res.updated : '');
                if (res.message) {
                    $('#ts-msg').text('> ' + res.message).show();
                } else {
                    $('#ts-msg').hide();
                }
                catDd.setOptions($.map(res.categories, function (c) { return { value: c.code, label: c.label + (c.view ? ' [' + c.view + ']' : '') }; }), state.hiddenCats);
                toolDd.setOptions($.map(res.tools, function (t) { return { value: t, label: t }; }), state.hiddenTools);
                state.hiddenCats = $.extend({}, catDd.hidden);
                state.hiddenTools = $.extend({}, toolDd.hidden);
                renderLegend();
                render();
            })
            .fail(function (xhr, status) {
                if (status === 'abort') {
                    return;
                }
                state.data = null;
                var msg = (xhr.responseJSON && xhr.responseJSON.error) || ('HTTP ' + xhr.status);
                $('#ts-error').text('> ' + msg).show();
                $('#ts-msg').hide();
                $('#ts-wrap').html('');
                $('#ts-summary').text('');
            })
            .always(function () {
                $('#ts-refresh').prop('disabled', false);
            });
    }

    // ==================================================================
    // 畫表格
    // ==================================================================
    function visibleTools() {
        var out = [];
        $.each(state.data.tools, function (i, t) {
            if (!state.hiddenTools[t]) {
                out.push(t);
            }
        });
        return out;
    }

    function visibleItems(tools) {
        var d = state.data;
        var catLabel = {};
        $.each(d.categories, function (i, c) { catLabel[c.code] = c.label; });
        var words = $.grep($.trim(state.q).toLowerCase().split(/\s+/), function (w) { return w !== ''; });
        return $.grep(d.items, function (it) {
            if (state.hiddenCats[it.category]) {
                return false;
            }
            if (words.length) {
                var text = (catLabel[it.category] + ' ' + it.label).toLowerCase();
                var hit = false;
                for (var i = 0; i < words.length; i++) {
                    if (text.indexOf(words[i]) >= 0) {
                        hit = true;
                        break;
                    }
                }
                if (!hit) {
                    return false;
                }
            }
            if (state.oocOnly) {
                var vals = d.values[it.id] || {};
                for (var j = 0; j < tools.length; j++) {
                    var c = vals[tools[j]];
                    if (c && judge(c.v, specOf(it, tools[j])) !== '') {
                        return true;
                    }
                }
                return false;
            }
            return true;
        });
    }

    function cellHtml(it, tool, c) {
        var s = specOf(it, tool);
        var ovr = !!(it.toolSpec && it.toolSpec[tool]) ||
            !!(it.offset && it.targets && it.targets.hasOwnProperty(tool) && it.targets[tool] !== it.target);
        var cls = ['ts-v'], style = '', text = '-', level = '';
        var tip = [tool + ' · ' + it.label];
        if (!c) {
            cls.push('ts-na');
            tip.push('沒有資料');
        } else if (c.v === null) {
            text = c.s;
            var sc = state.data.statusColors[String(c.s).toLowerCase()];
            if (sc) {
                style = 'background:' + sc + ';color:' + textOn(sc);
            }
            cls.push('ts-status');
            tip.push('狀態：' + c.s);
        } else {
            text = fmt(c.v, it.decimals);
            level = judge(c.v, s);
            tip.push('值：' + text + (it.unit ? ' ' + it.unit : '') + '　判定：' + (level === 'oos' ? 'OOS' : (level === 'ooc' ? 'OOC' : '正常')));
            if (level) {
                var bg = bgOf(it, level);
                style = 'background:' + bg + ';color:' + textOn(bg);
                cls.push('ts-' + level);
            }
        }
        if (c && c.t) {
            tip.push('資料時間：' + c.t);
        }
        if (it.offset) {
            tip.push('此機台 target ' + specText(targetOf(it, tool)));
        }
        if (ovr || it.offset) {
            if (ovr) {
                cls.push('ts-ovr');
            }
            tip.push('此機台 spec：uOOS ' + specText(s[0]) + ' / uOOC ' + specText(s[1]) +
                ' / lOOC ' + specText(s[2]) + ' / lOOS ' + specText(s[3]));
        }
        var href = c ? hrefOf(it, tool) : '';
        var inner = esc(text);
        if (href) {
            tip.push(it.href.indexOf('{tool}') >= 0 ? '點擊開啟 ' + tool + ' 的 chart' : '點擊開啟 chart');
            inner = '<a href="' + esc(href) + '" target="_blank" rel="noopener noreferrer">' + inner + '</a>';
        }
        return '<td class="' + cls.join(' ') + '"' + (style ? ' style="' + style + '"' : '') +
            ' title="' + esc(tip.join('\n')) + '">' + inner + '</td>';
    }

    /** 限定身份的分類 / 項目：只有看得到的人才會收到，加小標記提醒（例 [EE]） */
    function viewTag(view) {
        return view ? ' <small class="ts-view" title="限定身份：只有 ' + esc(view) + '（與 ADMIN）看得到">[' + esc(view) + ']</small>' : '';
    }

    function render() {
        var d = state.data;
        if (!d) {
            return;
        }
        var tools = visibleTools();
        var items = visibleItems(tools);
        $('#ts-summary').text('顯示 ' + items.length + ' / ' + d.items.length + ' 項、' + tools.length + ' / ' + d.tools.length + ' 台');
        $('#ts-clear').toggleClass('is-active', !!(keys(state.hiddenCats).length || keys(state.hiddenTools).length || state.q || state.oocOnly));

        if (d.noAccess) {
            $('#ts-wrap').html('<div class="dim ts-empty">&gt; 此機型沒有你可檢視的分類</div>');
            return;
        }
        if (!d.items.length) {
            $('#ts-wrap').html('<div class="dim ts-empty">&gt; 這個機型還沒有設定任何項目（config/tool_select/' + esc(d.model) + '.ini 的 [item.*]）</div>');
            return;
        }

        var catLabel = {}, catView = {};
        $.each(d.categories, function (i, c) { catLabel[c.code] = c.label; catView[c.code] = c.view || ''; });

        var h = '<table class="ts-table"><thead><tr>' +
            '<th class="ts-c0">分類</th><th class="ts-c1">項目</th>';
        for (var k = 0; k < 4; k++) {
            h += '<th class="ts-spec ts-c' + (k + 2) + '">' + SPEC_NAMES[k] + '</th>';
        }
        $.each(tools, function (i, t) { h += '<th class="ts-tool">' + esc(t) + '</th>'; });
        h += '</tr></thead><tbody>';

        if (!items.length) {
            h += '<tr><td colspan="' + (6 + tools.length) + '" class="dim ts-none">&gt; 沒有符合篩選條件的項目</td></tr>';
        }
        // 同分類連續的列合併分類格
        for (var i = 0; i < items.length; i++) {
            var it = items[i];
            h += '<tr>';
            if (i === 0 || items[i - 1].category !== it.category) {
                var span = 1;
                while (i + span < items.length && items[i + span].category === it.category) {
                    span++;
                }
                h += '<td class="ts-c0 ts-cat" rowspan="' + span + '">' + esc(catLabel[it.category]) + viewTag(catView[it.category]) + '</td>';
            }
            var href = hrefOf(it, '');
            var label = esc(it.label);
            if (href) {
                label = '<a href="' + esc(href) + '" target="_blank" rel="noopener noreferrer" title="開啟 chart">' + label + '</a>';
            }
            h += '<td class="ts-c1 ts-item">' + label + viewTag(it.view) + (it.unit ? ' <small class="dim">' + esc(it.unit) + '</small>' : '') +
                (it.offset ? ' <small class="dim ts-target" title="spec 是距離各機台 target 的偏移量（滑鼠移到數值格看該台的 target 與 spec）">±T</small>' : '') + '</td>';
            for (k = 0; k < 4; k++) {
                h += '<td class="ts-spec ts-c' + (k + 2) + '">' + specCell(it, k) + '</td>';
            }
            var vals = d.values[it.id] || {};
            for (var j = 0; j < tools.length; j++) {
                h += cellHtml(it, tools[j], vals[tools[j]]);
            }
            h += '</tr>';
        }
        h += '</tbody></table>';
        if (!tools.length) {
            h += '<div class="dim ts-empty">&gt; 請在「機台」選單勾選至少一台機台</div>';
        }
        $('#ts-wrap').html(h);
    }

    function renderLegend() {
        var c = state.data.colors, sfx = isLight() ? '_light' : '';
        var anyOffset = false;
        $.each(state.data.items, function (i, it) { anyOffset = anyOffset || !!it.offset; });
        $('#ts-legend').html(
            '圖例 <span class="ts-swatch" style="background:' + c['ooc_bg' + sfx] + '"></span>OOC ' +
            '<span class="ts-swatch" style="background:' + c['oos_bg' + sfx] + '"></span>OOS ' +
            '<span class="ts-swatch ts-swatch-ovr"></span>該機台 spec' + (anyOffset ? ' 或 target' : '') + ' 有覆蓋（滑鼠移上去看）' +
            (anyOffset ? ' <small class="dim">±T</small> spec 為距離各機台 target 的偏移量' : '')
        );
    }

    // ==================================================================
    // 事件
    // ==================================================================
    function exportCsv() {
        if (!state.data) {
            return;
        }
        var tools = visibleTools();
        var items = visibleItems(tools);
        if (!tools.length || !items.length) {
            $('#ts-msg').text('> 目前沒有可匯出的資料（請確認篩選條件）').show();
            return;
        }
        var url = CFG.urls['export'] + (CFG.urls['export'].indexOf('?') >= 0 ? '&' : '?') +
            'model=' + encodeURIComponent(state.model) +
            '&items=' + encodeURIComponent($.map(items, function (it) { return it.id; }).join(',')) +
            '&tools=' + encodeURIComponent(tools.join(','));
        window.location.href = url;
    }

    $(function () {
        catDd = new MultiDropdown($('#ts-cat'), {
            search: false, noun: '類',
            onChange: function (hidden) { state.hiddenCats = $.extend({}, hidden); saveFilter(); render(); }
        });
        toolDd = new MultiDropdown($('#ts-tool'), {
            search: true, noun: '台', placeholder: '搜尋機台，例 05',
            onChange: function (hidden) { state.hiddenTools = $.extend({}, hidden); saveFilter(); render(); }
        });

        var qTimer = null;
        $('#ts-q').on('input keyup', function () {
            var v = $(this).val();
            clearTimeout(qTimer);
            qTimer = setTimeout(function () {
                if (v !== state.q) {
                    state.q = v;
                    saveFilter();
                    render();
                }
            }, 150);
        });
        $('#ts-ooc-only').on('change', function () {
            state.oocOnly = this.checked;
            saveFilter();
            render();
        });
        $('#ts-clear').on('click', function () {
            state.hiddenCats = {};
            state.hiddenTools = {};
            state.q = '';
            state.oocOnly = false;
            $('#ts-q').val('');
            $('#ts-ooc-only').prop('checked', false);
            saveFilter();
            if (state.data) {
                catDd.setOptions(catDd.list, {});
                toolDd.setOptions(toolDd.list, {});
                render();
            }
        });
        $('#ts-refresh').on('click', load);
        $('#ts-export').on('click', exportCsv);
        $('#ts-fs').on('click', toggleFull);
        $('#ts-tabs').on('click', '.pi-tab', function (e) {
            e.preventDefault();
            var m = $(this).data('model');
            if (m !== state.model) {
                selectModel(m);
            }
        });
        $(document).on('aisle:theme', function () {
            if (state.data) {
                renderLegend();
                render();
            }
        });

        selectModel(CFG.model || store(MODEL_KEY) || '');
    });
})(window.jQuery);
