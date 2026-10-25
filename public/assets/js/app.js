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
