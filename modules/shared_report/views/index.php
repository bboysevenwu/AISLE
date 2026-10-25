<div class="panel">
    <div class="panel-head">&gt; WEEKLY REPORT</div>
    <div class="panel-body">
        <table class="table">
            <thead><tr><th>DATE</th><th>OUTPUT</th><th>YIELD %</th><th>DOWN</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo e($r['date']); ?></td>
                    <td><?php echo e($r['output']); ?></td>
                    <td><?php echo e($r['yield']); ?></td>
                    <td><?php echo e($r['downs']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <br>
        <?php if ($canExport): ?>
            <a class="btn" href="<?php echo e(url('shared_report', 'export')); ?>">[ 匯出 CSV ]</a>
        <?php else: ?>
            <p class="dim">&gt; 匯出功能僅開放 PE 身份</p>
        <?php endif; ?>
    </div>
</div>
