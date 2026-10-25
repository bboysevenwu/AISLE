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

    function draw() {
        // 半透明黑色覆蓋，形成拖尾
        ctx.fillStyle = 'rgba(0, 0, 0, 0.06)';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.font = fontSize + 'px monospace';

        for (var i = 0; i < columns; i++) {
            var ch = chars.charAt(Math.floor(Math.random() * chars.length));
            var y = drops[i] * fontSize;
            // 雨滴最前端偏白，其餘為綠色
            ctx.fillStyle = Math.random() > 0.975 ? '#d4ffe0' : '#00ff41';
            ctx.fillText(ch, i * fontSize, y);
            if (y > canvas.height && Math.random() > 0.975) {
                drops[i] = 0;
            }
            drops[i]++;
        }
    }

    resize();
    window.addEventListener('resize', resize);

    if (reduceMotion) {
        for (var n = 0; n < 60; n++) { draw(); }
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
