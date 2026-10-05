<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/settings.php';

if (!gacha_is_installed()) redirect('../install.php');
if (auth_check())          redirect('dashboard.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim(post('username'));
    $p = (string)post('password');
    $r = !empty($_POST['remember']);
    if (auth_login($u, $p, $r)) {
        redirect('dashboard.php');
    }
    $error = '用户名或密码错误';
}
$siteTitle = setting('site_title', '星海抽卡');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>登录 · <?= h($siteTitle) ?></title>
<style>
:root { --bg:#0f0f16; --panel:#181824; --border:#2a2a3a; --text:#e8e8f0; --sub:#8a8aa0; --accent:#7a4ddb; --gold:#ffd166; }
* { box-sizing: border-box; }
body {
  margin: 0; min-height: 100vh;
  display: flex; align-items: center; justify-content: center;
  background: radial-gradient(circle at 70% 10%, rgba(122,77,219,.22), transparent 55%), var(--bg);
  color: var(--text);
  font-family: system-ui, -apple-system, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
  padding: 20px;
}
.box {
  width: 100%; max-width: 380px;
  background: var(--panel); border: 1px solid var(--border);
  border-radius: 16px; padding: 36px;
  box-shadow: 0 30px 80px rgba(0,0,0,.5);
}
h1 { margin: 0 0 4px; font-size: 20px; text-align: center;
     background: linear-gradient(120deg, var(--gold), var(--accent));
     -webkit-background-clip: text; background-clip: text; color: transparent; }
.sub { margin: 0 0 28px; color: var(--sub); font-size: 13px; text-align: center; }
label { display: block; margin-bottom: 6px; font-size: 12px; color: var(--sub); }
input[type=text], input[type=password] {
  width: 100%; padding: 11px 14px; margin-bottom: 16px;
  border-radius: 10px; border: 1px solid var(--border);
  background: #10101a; color: var(--text); font-size: 14px; outline: none;
}
input:focus { border-color: var(--accent); }
.row { display: flex; align-items: center; gap: 8px; margin-bottom: 18px; font-size: 13px; color: var(--sub); }
button {
  width: 100%; padding: 13px; border: none; border-radius: 10px; cursor: pointer;
  font-size: 15px; font-weight: 700; color: #fff;
  background: linear-gradient(120deg, #7a4ddb, #d63384);
  transition: filter .15s;
}
button:hover { filter: brightness(1.1); }
.error {
  padding: 10px 14px; margin-bottom: 16px; border-radius: 8px;
  background: rgba(239,68,68,.12); color: #fca5a5; font-size: 13px; text-align: center;
}
</style>
</head>
<body>
<div class="box">
  <h1>✦ <?= h($siteTitle) ?></h1>
  <p class="sub">后台管理登录</p>

  <?php if ($error): ?>
    <div class="error"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="post">
    <label>用户名</label>
    <input type="text" name="username" required autofocus autocomplete="username">
    <label>密码</label>
    <input type="password" name="password" required autocomplete="current-password">
    <div class="row">
      <input type="checkbox" name="remember" id="rm" style="width:auto;margin:0">
      <label for="rm" style="margin:0;cursor:pointer">记住我 30 天</label>
    </div>
    <button type="submit">登 录</button>
  </form>
</div>
</body>
</html>