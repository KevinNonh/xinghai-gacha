<?php
$pageTitle  = '角色管理';
$pageActive = 'characters';
require __DIR__ . '/_header.php';
require_once __DIR__ . '/../lib/upload.php';

$pdo = gacha_pdo();
$msg = q('msg');
$error = '';

// 删除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'delete') {
    csrf_check();
    $id    = (int)post('id');
    $force = !empty($_POST['force']);

    $stmt = $pdo->prepare('SELECT p.name FROM pools p JOIN pool_characters pc ON pc.pool_id = p.id WHERE pc.character_id = ?');
    $stmt->execute([$id]);
    $used = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($used) && !$force) {
        $error = '该角色还在以下卡池中：' . implode('、', $used) . '。请先解除关联，或勾选「强制删除」。';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM characters WHERE id = ?');
        $stmt->execute([$id]);
        $ch = $stmt->fetch();
        if ($ch) {
            foreach (['image_full', 'image_half', 'image_chibi'] as $k) {
                if (!empty($ch[$k])) delete_upload($ch[$k]);
            }
        }
        $pdo->prepare('DELETE FROM pool_characters WHERE character_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM characters WHERE id = ?')->execute([$id]);
        redirect('characters.php?msg=deleted');
    }
}

$characters = $pdo->query('SELECT * FROM characters ORDER BY rarity DESC, id DESC')->fetchAll();

$refs = [];
foreach ($pdo->query('SELECT pc.character_id, p.name FROM pool_characters pc JOIN pools p ON p.id = pc.pool_id') as $r) {
    $refs[(int)$r['character_id']][] = $r['name'];
}
?>

<?php if ($msg === 'deleted'): ?><div class="flash ok">✓ 已删除</div><?php endif; ?>
<?php if ($msg === 'saved'):   ?><div class="flash ok">✓ 已保存</div><?php endif; ?>
<?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>

<div class="toolbar">
  <div class="count">共 <strong><?= count($characters) ?></strong> 个角色</div>
  <a class="btn primary" href="character_edit.php">＋ 新建角色</a>
</div>

<?php if (empty($characters)): ?>
  <div class="empty">
    <p>还没有角色。</p>
    <a class="btn primary" href="character_edit.php">创建第一个角色</a>
  </div>
<?php else: ?>
  <div class="char-grid">
    <?php foreach ($characters as $c): ?>
      <?php
        $thumb = $c['image_chibi'] ?: ($c['image_half'] ?: ($c['image_full'] ?: ''));
        $st = (int)$c['rarity'];
      ?>
      <div class="char-card rarity-border-<?= $st ?>">
        <div class="char-thumb">
          <?php if ($thumb): ?>
            <img src="<?= h(upload_url($thumb)) ?>" alt="">
          <?php else: ?>
            <div class="thumb-fallback" style="--ca:<?= h($c['color_a']) ?>;--cb:<?= h($c['color_b']) ?>">
              <?= h($c['avatar_text'] ?: mb_substr($c['name'], 0, 1)) ?>
            </div>
          <?php endif; ?>
          <span class="rarity-badge rarity-<?= $st ?>"><?= $st ?>★</span>
        </div>
        <div class="char-body">
          <div class="char-name"><?= h($c['name']) ?></div>
          <div class="char-title"><?= h($c['title']) ?></div>
          <div class="char-code"><?= h($c['code']) ?></div>
          <?php if (!empty($refs[$c['id']])): ?>
            <div class="char-refs">🃏 <?= h(implode('、', $refs[$c['id']])) ?></div>
          <?php else: ?>
            <div class="char-refs none">未加入任何卡池</div>
          <?php endif; ?>
        </div>
        <div class="char-actions">
          <a class="mini-btn" href="character_edit.php?id=<?= (int)$c['id'] ?>">编辑</a>
          <form method="post" style="display:inline" onsubmit="return confirm('确定删除该角色？')">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <button type="submit" class="mini-btn danger">删除</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>