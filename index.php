<?php
require_once __DIR__ . '/lib/db.php';

if (!gacha_is_installed()) {
    header('Location: install.php');
    exit;
}

require_once __DIR__ . '/lib/settings.php';

$siteTitle    = setting('site_title', '星海抽卡');
$siteSubtitle = setting('site_subtitle', 'GACHA SIMULATOR');
$siteFooter   = setting('site_footer', '');
$bgUrl        = setting('background_url', '');
$favicon      = setting('favicon_url', '');
$customHead   = setting('custom_head', '');
$customFooter = setting('custom_footer', '');
$customCss    = setting('custom_css', '');
$tenDelay     = (int)setting('ten_pull_delay', 160);
$defaultPool  = (int)setting('default_pool_id', 0);

// 相对路径 / 绝对 URL 自适应
function front_url(string $p): string {
    if ($p === '') return '';
    if (preg_match('#^https?://#i', $p)) return $p;
    if (strpos($p, '//') === 0) return $p;
    if ($p[0] === '/') return $p;
    return ltrim($p, '/');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($siteTitle, ENT_QUOTES) ?></title>
<?php if ($favicon !== ''): ?>
<link rel="icon" href="<?= htmlspecialchars(front_url($favicon), ENT_QUOTES) ?>">
<?php endif; ?>
<link rel="stylesheet" href="assets/front.css?v=1">
<?php if ($bgUrl !== ''): ?>
<style>
body::before {
  background-image: url('<?= htmlspecialchars(front_url($bgUrl), ENT_QUOTES) ?>');
}
</style>
<?php endif; ?>
<?php if ($customCss !== ''): ?>
<style>
<?= $customCss ?>
</style>
<?php endif; ?>
<?php if ($customHead !== ''): ?>
<?= $customHead ?>
<?php endif; ?>
</head>
<body>
<body>
<div class="gacha-app" id="app">

  <header class="gacha-top">
  <h1 class="gacha-title">✦ <?= htmlspecialchars($siteTitle, ENT_QUOTES) ?></h1>
  <div class="gacha-sub"><?= htmlspecialchars($siteSubtitle, ENT_QUOTES) ?></div>
  </header>

  <a href="all-characters.php" class="gacha-album-fab" title="角色图鉴">📖</a>

  <nav class="pool-tabs" id="pool-tabs"></nav>

  <section class="banner" id="banner">
    <div class="banner-inner">
      <h2 id="banner-name">—</h2>
      <div class="banner-sub" id="banner-sub"></div>
      <p class="banner-desc" id="banner-desc"></p>
    </div>
  </section>

  <section class="pity-bar">
    <div class="pity-item">
      <span class="pity-label">距离 6★ 硬保底</span>
      <strong id="pity-num">--</strong>
      <span class="pity-label">抽</span>
    </div>
    <button class="info-btn" id="btn-info">📋 概率 &amp; 池内角色</button>
  </section>

  <section class="actions">
    <button class="action-btn single" id="btn-single">
      <span class="al">单抽</span>
      <span class="as">SINGLE × 1</span>
    </button>
    <button class="action-btn ten" id="btn-ten">
      <span class="al">十连抽</span>
      <span class="as">TEN PULL × 10</span>
    </button>
  </section>

  <section class="results" id="results">
    <div class="empty-hint">点击上方按钮开始抽卡 ✨</div>
  </section>

  <?php if ($siteFooter !== ''): ?>
    <footer class="gacha-footer"><?= htmlspecialchars($siteFooter, ENT_QUOTES) ?></footer>
  <?php endif; ?>
</div>

<div class="overlay" id="overlay">
  <div class="stage" id="stage"></div>
  <div class="overlay-hint" id="overlay-hint">点击继续</div>
</div>

<div class="modal" id="modal">
  <div class="modal-box">
    <div class="modal-head">
      <h3 id="modal-title">卡池详情</h3>
      <button class="modal-close" id="modal-close">✕</button>
    </div>
    <div class="modal-body" id="modal-body"></div>
  </div>
</div>

<script>
window.GACHA_TEN_DELAY   = <?= $tenDelay ?>;
window.GACHA_DEFAULT_POOL = <?= $defaultPool ?>;
</script>
<script src="assets/front.js?v=1"></script>

<?php if ($customFooter !== ''): ?>
<?= $customFooter ?>
<?php endif; ?>
</div>
</body>
</html>