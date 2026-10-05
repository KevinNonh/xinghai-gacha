<?php
$pageTitle  = '卡池管理';
$pageActive = 'pools';
require __DIR__ . '/_header.php';

$pdo = gacha_pdo();
$msg = q('msg');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    csrf_check();
    $id = (int)post('id');
    $pdo->prepare('DELETE FROM pool_characters WHERE pool_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM pools WHERE id = ?')->execute([$id]);
    redirect('pools.php?msg=deleted');
}

$pools = $pdo->query('SELECT * FROM pools ORDER BY sort_order, id')->fetchAll();

$counts = [];
foreach ($pdo->query('SELECT pool_id, COUNT(*) AS n FROM pool_characters GROUP BY pool_id') as $r) {
    $counts[(int)$r['pool_id']] = (int)$r['n'];
}
?>

<?php if ($msg === 'deleted'): ?><div class="flash ok">✓ 已删除</div><?php endif; ?>
<?php if ($msg === 'saved'):   ?><div class="flash ok">✓ 已保存</div><?php endif; ?>
<?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>

<div class="toolbar">
  <div class="count">共 <strong><?= count($pools) ?></strong> 个卡池</div>
  <a class="btn primary" href="pool_edit.php">＋ 新建卡池</a>
</div>

<?php if (empty($pools)): ?>
  <div class="empty">
    <p>还没有卡池。</p>
    <a class="btn primary" href="pool_edit.php">创建第一个卡池</a>
  </div>
<?php else: ?>
  <div class="pool-list">
    <?php foreach ($pools as $p): ?>
      <div class="pool-row">
        <div class="pool-banner" style="--ca:<?= h($p['color_a']) ?>;--cb:<?= h($p['color_b']) ?>">
          <div class="pool-name"><?= h($p['name']) ?></div>
          <div class="pool-sub"><?= h($p['subtitle']) ?></div>
        </div>
        <div class="pool-meta">
          <div class="pool-code"><?= h($p['code']) ?></div>
          <div class="pool-status <?= $p['is_active'] ? 'on' : 'off' ?>">
            <?= $p['is_active'] ? '已上架' : '已下架' ?>
          </div>
          <div class="pool-count">🃏 <?= $counts[$p['id']] ?? 0 ?> 个角色</div>
        </div>
        <div class="pool-actions">
          <a class="mini-btn" href="pool_edit.php?id=<?= (int)$p['id'] ?>">编辑</a>
          <form method="post" style="display:inline" onsubmit="return confirm('确定删除该卡池？池内关联会一起删除，角色本身会保留。')">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button type="submit" class="mini-btn danger">删除</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>