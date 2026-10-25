<div class="panel">
    <div class="panel-head">&gt; RECIPE DATABASE</div>
    <div class="panel-body">
        <form method="get" action="index.php">
            <input type="hidden" name="m" value="pe_recipe">
            <label for="q">SEARCH</label>
            <input type="text" id="q" name="q" value="<?php echo e($keyword); ?>" placeholder="配方編號 / 製程 / 負責人">
        </form>
        <br>
        <table class="table">
            <thead><tr><th>RECIPE</th><th>STEP</th><th>VERSION</th><th>OWNER</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="4" class="dim">&gt; no match found.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo e($r['id']); ?></td>
                    <td><?php echo e($r['step']); ?></td>
                    <td><?php echo e($r['version']); ?></td>
                    <td><?php echo e($r['owner']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
