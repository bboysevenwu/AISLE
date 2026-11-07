/*
 * AISLE 共用前端 (jQuery 1.12)
 */
(function ($) {
    if (!$) {
        return;
    }

    // 所有 jQuery AJAX 自動帶 CSRF token
    var csrf = $('meta[name="csrf-token"]').attr('content');
    $.ajaxSetup({
        headers: { 'X-CSRF-Token': csrf },
        dataType: 'json'
    });

    // Session 過期時導回登入頁
    $(document).ajaxError(function (e, xhr) {
        if (xhr.status === 401) {
            // 用版面提供的絕對網址，public/legacy/ 等子資料夾頁面也能正確導回
            window.location.href = $('meta[name="login-url"]').attr('content') || 'login.php';
        }
    });

    // 側邊選單分類收折：狀態記在瀏覽器（localStorage），目前頁面所在的分類一律展開
    var NAV_KEY = 'aisle.nav.';
    function navStore(key, value) {
        try {
            if (value === undefined) {
                return window.localStorage.getItem(NAV_KEY + key);
            }
            window.localStorage.setItem(NAV_KEY + key, value);
        } catch (e) {
            // 瀏覽器停用 localStorage 時只是不記憶狀態
        }
        return null;
    }
    $(function () {
        $('.nav-group').each(function () {
            var $g = $(this), saved = navStore($g.data('group'));
            if (!$g.hasClass('has-active') && saved !== null) {
                $g.toggleClass('open', saved === '1');
            }
            $g.children('.nav-group-toggle').attr('aria-expanded', $g.hasClass('open') ? 'true' : 'false');
        });
        $(document).on('click', '.nav-group-toggle', function (e) {
            e.preventDefault();
            var $g = $(this).closest('.nav-group').toggleClass('open'), open = $g.hasClass('open');
            $(this).attr('aria-expanded', open ? 'true' : 'false');
            navStore($g.data('group'), open ? '1' : '0');
        });
    });

    // 畫面風格切換：dark（駭客任務）/ light（淺色底的駭客任務）
    // 選擇記在 cookie aisle_theme（每台電腦各自記憶，一年），PHP 輸出頁面時就會套上 body.theme-light，換頁不會閃爍
    // 切換後觸發 $(document) 的 'aisle:theme' 事件，圖表等元件可以跟著重畫
    var THEME_COOKIE = 'aisle_theme';
    var THEME_TEXT = {
        dark:  { label: '[ ☀ 淺色 ]', tip: '切換為淺色底的駭客任務風格' },
        light: { label: '[ ☾ 深色 ]', tip: '切換為駭客任務（深色）風格' }
    };
    function currentTheme() {
        return $('body').hasClass('theme-light') ? 'light' : 'dark';
    }
    function saveTheme(theme) {
        // path 用網站的路徑前綴（例 /D1C1/aisle/），經過 proxy 也只作用在 AISLE 底下
        var path = $('meta[name="base-path"]').attr('content') || '/';
        var expires = new Date();
        expires.setFullYear(expires.getFullYear() + 1);
        document.cookie = THEME_COOKIE + '=' + theme + '; expires=' + expires.toUTCString() + '; path=' + path;
    }
    function applyTheme(theme) {
        $('html, body').toggleClass('theme-light', theme === 'light');
        $('.theme-toggle').text(THEME_TEXT[theme].label).attr('title', THEME_TEXT[theme].tip);
        $(document).trigger('aisle:theme', [theme]);
    }
    $(function () {
        $(document).on('click', '.theme-toggle', function () {
            var next = currentTheme() === 'light' ? 'dark' : 'light';
            saveTheme(next);
            applyTheme(next);
        });
    });

    // 共用工具，模組 view 中可使用 AISLE.typewriter(...)
    // 用 extend 合併，保留頁面先載入的擴充（例如 auth.js 的 AISLE.auth）
    window.AISLE = $.extend(window.AISLE || {}, {
        /**
         * 打字機效果：逐字印出多行文字
         * @param $el   目標容器
         * @param lines 字串陣列
         * @param speed 每字毫秒
         */
        typewriter: function ($el, lines, speed, done) {
            speed = speed || 25;
            var li = 0, ci = 0, $line = null;
            function tick() {
                if (li >= lines.length) {
                    if (done) { done(); }
                    return;
                }
                if (ci === 0) {
                    $line = $('<p class="line"></p>').appendTo($el);
                }
                $line.text(lines[li].substring(0, ci + 1));
                ci++;
                if (ci >= lines[li].length) {
                    li++;
                    ci = 0;
                    setTimeout(tick, speed * 8);
                } else {
                    setTimeout(tick, speed);
                }
            }
            tick();
        },

        /** 安全地把文字放進 HTML（避免 XSS） */
        escape: function (s) {
            return $('<div>').text(s === null || s === undefined ? '' : String(s)).html();
        }
    });

    $(function () {
        // 右上角時鐘
        var $clock = $('#clock');
        function pad(n) { return n < 10 ? '0' + n : n; }
        function updateClock() {
            var d = new Date();
            $clock.text(d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' +
                pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()));
        }
        if ($clock.length) {
            updateClock();
            setInterval(updateClock, 1000);
        }

        // 有 data-confirm 的按鈕送出前確認
        $(document).on('click', '[data-confirm]', function (e) {
            if (!window.confirm($(this).data('confirm'))) {
                e.preventDefault();
            }
        });
    });
})(window.jQuery);
