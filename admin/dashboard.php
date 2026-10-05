<?php
$pageTitle  = '仪表盘';
$pageActive = 'dashboard';
require __DIR__ . '/_header.php';

$pdo = gacha_pdo();

$charCount   = (int)$pdo->query('SELECT COUNT(*) FROM characters')->fetchColumn();
$poolCount   = (int)$pdo->query('SELECT COUNT(*) FROM pools')->fetchColumn();
$poolActive  = (int)$pdo->query('SELECT COUNT(*) FROM pools WHERE is_active = 1')->fetchColumn();

$rarityCounts = [];
foreach ($pdo->query('SELECT rarity, COUNT(*) AS n FROM characters GROUP BY rarity')->fetchAll() as $r) {
    $rarityCounts[(int)$r['rarity']] = (int)$r['n'];
}
$rarityCounts = $rarityCounts + array_fill_keys(range(1, 9), 0);
ksort($rarityCounts);

$dbSize     = file_exists(GACHA_DB_FILE) ? filesize(GACHA_DB_FILE) : 0;
$uploadSize = dir_size(GACHA_UPLOAD_DIR);
?>

<?php if ($charCount === 0 && $poolCount === 0): ?>
  <div class="callout">
    <h2>👋 欢迎使用</h2>
    <p>数据库已就绪。现在还没有任何角色和卡池。</p>
    <p>先到「角色管理」创建几个角色，再到「卡池管理」新建卡池并关联角色，最后去「站点设置」配置背景和概率。</p>
  </div>
<?php endif; ?>

<div class="stat-grid">
  <div class="stat">
    <div class="num"><?= $charCount ?></div>
    <div class="lbl">角色总数</div>
  </div>
  <div class="stat">
    <div class="num"><?= $poolCount ?></div>
    <div class="lbl">卡池总数</div>
  </div>
  <div class="stat">
    <div class="num"><?= $poolActive ?></div>
    <div class="lbl">已上架卡池</div>
  </div>
</div>

<div class="card">
  <h2>各星级角色</h2>
  <div class="rarity-grid">
    <?php for ($i = 9; $i >= 1; $i--): ?>
      <div class="rarity-cell">
        <div class="star"><?= $i ?>★</div>
        <div class="cnt"><?= $rarityCounts[$i] ?></div>
      </div>
    <?php endfor; ?>
  </div>
</div>

<div class="card">
  <h2>系统信息</h2>
  <table class="info-table">
    <tr><td>PHP 版本</td><td><?= h(PHP_VERSION) ?></td></tr>
    <tr><td>SQLite 版本</td><td><?= h($pdo->query('SELECT sqlite_version()')->fetchColumn()) ?></td></tr>
    <tr><td>GD 扩展</td><td><?= extension_loaded('gd') ? '已启用（' . h(gd_info()['GD Version'] ?? '') . '）' : '未启用' ?></td></tr>
    <tr><td>数据库文件</td><td><?= h(basename(GACHA_DB_FILE)) ?> · <?= format_size((int)$dbSize) ?></td></tr>
    <tr><td>上传目录</td><td><?= is_writable(GACHA_UPLOAD_DIR) ? '可写' : '不可写' ?> · <?= format_size($uploadSize) ?></td></tr>
    <tr><td>时区</td><td><?= h(setting('timezone', 'Asia/Shanghai')) ?></td></tr>
  </table>
</div>

<?php require __DIR__ . '/_footer.php'; ?>