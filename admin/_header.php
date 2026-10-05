<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/settings.php';

auth_require();

$adminName  = $_SESSION['admin_name'] ?? 'admin';
$siteTitle  = setting('site_title', '星海抽卡');
$pageTitle  = $pageTitle  ?? '后台';
$pageActive = $pageActive ?? '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($pageTitle) ?> · <?= h($siteTitle) ?></title>
<!-- 尽早应用主题，避免闪烁：必须在 CSS 之前 -->
<script>
(function () {
  try {
    if (localStorage.getItem('gacha-admin-theme') === 'light') {
      document.documentElement.classList.add('theme-light');
    }
  } catch (e) {}
})();
</script>
<link rel="stylesheet" href="../assets/admin.css?v=2">
</head>
<body>
<div class="admin-layout">
  <aside class="admin-side">
    <div class="brand">
      <span class="brand-mark">✦</span>
      <span class="brand-name"><?= h($siteTitle) ?></span>
    </div>
    <nav class="nav">
      <a class="nav-item <?= $pageActive === 'dashboard'  ? 'active' : '' ?>" href="dashboard.php">📊 仪表盘</a>
      <a class="nav-item <?= $pageActive === 'characters' ? 'active' : '' ?>" href="characters.php">👤 角色管理</a>
      <a class="nav-item <?= $pageActive === 'pools'      ? 'active' : '' ?>" href="pools.php">🎴 卡池管理</a>
      <a class="nav-item <?= $pageActive === 'uploads'    ? 'active' : '' ?>" href="uploads.php">🖼️ 素材库</a>
      <a class="nav-item <?= $pageActive === 'backup'     ? 'active' : '' ?>" href="backup.php">💾 数据备份</a>
	  <a class="nav-item <?= $pageActive === 'simulate' ? 'active' : '' ?>" href="simulate.php">🎲 模拟千抽</a>
      <a class="nav-item <?= $pageActive === 'settings'   ? 'active' : '' ?>" href="settings.php">⚙️ 站点设置</a>
    </nav>
    <div class="side-foot">
      <a href="../index.php" target="_blank">↗ 打开前台</a>
      <a href="logout.php">↩ 退出登录</a>
    </div>
  </aside>
  <main class="admin-main">
    <header class="admin-top">
      <h1><?= h($pageTitle) ?></h1>
      <div class="top-right">
        <button class="theme-toggle" id="admin-theme-toggle" type="button" title="切换浅色 / 深色">
          <span class="icon-dark">🌙</span>
          <span class="icon-light">☀️</span>
        </button>
        <div class="who">👤 <?= h($adminName) ?></div>
      </div>
    </header>
    <div class="admin-content">

<script>
(function () {
  var btn = document.getElementById('admin-theme-toggle');
  if (!btn) return;
  btn.addEventListener('click', function () {
    var html = document.documentElement;
    var isLight = html.classList.toggle('theme-light');
    try {
      localStorage.setItem('gacha-admin-theme', isLight ? 'light' : 'dark');
    } catch (e) {}
  });
})();
</script>