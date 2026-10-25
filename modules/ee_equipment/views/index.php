<div class="panel">
    <div class="panel-head">&gt; EQUIPMENT STATUS <span class="dim" id="eq-time" style="float:right;color:#000"></span></div>
    <div class="panel-body">
        <table class="table">
            <thead>
                <tr><th>TOOL</th><th>STATE</th><th>TEMP (°C)</th><th>WPH</th></tr>
            </thead>
            <tbody id="eq-rows">
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo e($r['tool']); ?></td>
                    <td><span class="led led-<?php echo e(strtolower($r['state'])); ?>"></span><?php echo e($r['state']); ?></td>
                    <td><?php echo e($r['temp']); ?></td>
                    <td><?php echo e($r['wph']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="dim">&gt; 每 10 秒自動刷新</p>
    </div>
</div>

<script>
$(function () {
    var statusUrl = <?php echo js(url('ee_equipment', 'status')); ?>;
    function refresh() {
        $.get(statusUrl).done(function (res) {
            var html = '';
            $.each(res.rows, function (i, r) {
                html += '<tr><td>' + AISLE.escape(r.tool) + '</td>' +
                    '<td><span class="led led-' + AISLE.escape(r.state.toLowerCase()) + '"></span>' + AISLE.escape(r.state) + '</td>' +
                    '<td>' + AISLE.escape(r.temp) + '</td><td>' + AISLE.escape(r.wph) + '</td></tr>';
            });
            $('#eq-rows').html(html);
            $('#eq-time').text(res.time);
        });
    }
    setInterval(refresh, 10000);
});
</script>
