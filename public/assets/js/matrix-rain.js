/*
 * 數位雨背景（純 JavaScript，不依賴 jQuery）
 * 字元：半形片假名 + 數字 + 部分英文字母，與電影相同的感覺
 */
(function () {
    var canvas = document.getElementById('matrix-rain');
    if (!canvas || !canvas.getContext) {
        return;
    }
    var ctx = canvas.getContext('2d');
    var chars = 'ｱｲｳｴｵｶｷｸｹｺｻｼｽｾｿﾀﾁﾂﾃﾄﾅﾆﾇﾈﾉﾊﾋﾌﾍﾎﾏﾐﾑﾒﾓﾔﾕﾖﾗﾘﾙﾚﾛﾜﾝ0123456789AISLE:.=*+-<>';
    var fontSize = 16;
    var columns = 0;
    var drops = [];

    // 使用者偏好減少動態時，只畫一張靜態畫面
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function resize() {
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
        columns = Math.ceil(canvas.width / fontSize);
        drops = [];
        for (var i = 0; i < columns; i++) {
            drops[i] = Math.floor(Math.random() * -50);
        }
    }

    // 兩種風格的配色：深色＝黑底亮綠字；淺色＝淡綠白底、深綠字
    var THEMES = {
        dark:  { fade: 'rgba(0, 0, 0, 0.06)',        head: '#d4ffe0', body: '#00ff41' },
        light: { fade: 'rgba(242, 251, 244, 0.08)',  head: '#003d12', body: '#00a832' }
    };
    var theme = null;

    /** body 有 theme-light 就是淺色；風格變了就清空畫布，避免殘留上一種風格的拖尾 */
    function syncTheme() {
        var now = /(^|\s)theme-light(\s|$)/.test(document.body.className) ? 'light' : 'dark';
        if (now !== theme) {
            theme = now;
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            return true;
        }
        return false;
    }

    function draw() {
        syncTheme();
        var t = THEMES[theme];
        // 半透明底色覆蓋，形成拖尾
        ctx.fillStyle = t.fade;
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.font = fontSize + 'px monospace';

        for (var i = 0; i < columns; i++) {
            var ch = chars.charAt(Math.floor(Math.random() * chars.length));
            var y = drops[i] * fontSize;
            // 雨滴最前端顏色較突出，其餘為綠色
            ctx.fillStyle = Math.random() > 0.975 ? t.head : t.body;
            ctx.fillText(ch, i * fontSize, y);
            if (y > canvas.height && Math.random() > 0.975) {
                drops[i] = 0;
            }
            drops[i]++;
        }
    }

    resize();
    window.addEventListener('resize', resize);

    function drawStatic() {
        for (var n = 0; n < 60; n++) { draw(); }
    }
    if (reduceMotion) {
        drawStatic();
        // 靜態畫面：切換風格時重畫一次
        setInterval(function () {
            if (syncTheme()) {
                resize();
                drawStatic();
            }
        }, 500);
        return;
    }

    var last = 0;
    var raf = window.requestAnimationFrame || function (cb) { return setTimeout(function () { cb(Date.now()); }, 50); };
    function loop(ts) {
        // 約 20fps，省 CPU
        if (!last || ts - last > 50) {
            draw();
            last = ts;
        }
        raf(loop);
    }
    raf(loop);
})();
