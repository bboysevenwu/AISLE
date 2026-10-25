<div class="panel terminal">
    <div class="panel-head">&gt; SYSTEM CONSOLE</div>
    <div class="panel-body" id="console"></div>
</div>

<div class="grid">
<?php foreach ($modules as $m): ?>
    <div class="col">
        <a class="card-link" href="<?php echo e($m['url']); ?>">
            <span class="big"><?php echo e($m['icon']); ?></span>
            <?php echo e($m['title']); ?>
        </a>
    </div>
<?php endforeach; ?>
</div>

<script>
$(function () {
    AISLE.typewriter($('#console'), [
        '> connecting to AISLE mainframe ... OK',
        <?php echo js('> operator: ' . $user['display_name']); ?>,
        <?php echo js('> clearance: ' . $roleLabel); ?>,
        '> <?php echo count($modules); ?> module(s) online.',
        '> Follow the white rabbit.'
    ], 18);
});
</script>
