<div class="panel terminal error-panel">
    <div class="panel-head">SYSTEM FAILURE :: <?php echo (int) $code; ?></div>
    <div class="panel-body">
        <p class="text-red">&gt; <?php echo e($message); ?></p>
        <p class="dim">&gt; Wake up, operator...</p>
        <p><a class="btn" href="<?php echo e(url('')); ?>">[ 返回主控台 ]</a></p>
    </div>
</div>
