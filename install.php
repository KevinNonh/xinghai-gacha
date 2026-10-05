<?php
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/settings.php';

auth_start();

// 已经安装过 → 直接去登录
if (gacha_is_installed() && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin/index.php');
}

// 环境检查
$checks = [
    'PHP 版本 ≥ 7.4'        => version_compare(PHP_VERSION, '7.4.0', '>='),
    'PDO SQLite 扩展'        => extension_loaded('pdo_sqlite'),
    'GD 扩展（图片压缩用）'   => extension_loaded('gd'),
    'data 目录可写'          => is_writable(GACHA_DATA_DIR) || @mkdir(GACHA_DATA_DIR, 0755, true),
    'uploads 目录可写'       => is_writable(GACHA_UPLOAD_DIR) || @mkdir(GACHA_UPLOAD_DIR, 0755, true),
];
$all_ok = !in_array(false, $checks, true);

$error = '';
$done  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $all_ok) {
    $username = trim(post('username'));
    $password = (string)post('password');
    $confirm  = (string)post('confirm');

    if (mb_strlen($username) < 3) {
        $error = '用户名至少 3 个字符';
    } elseif (strlen($password) < 6) {
        $error = '密码至少 6 个字符';
    } elseif ($password !== $confirm) {
        $error = '两次输入的密码不一致';
    } else {
        try {
            $pdo = gacha_pdo();
            if ((int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0) {
                redirect('admin/index.php');
            }
            $stmt = $pdo->prepare(
                'INSERT INTO admins (username, password_hash, created_at) VALUES (?, ?, ?)'
            );
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), time()]);
            $done = true;
        } catch (Throwable $e) {
            $error = '创建失败：' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>安装 · 星海抽卡</title>
<style>
:root { --bg:#0f0f16; --panel:#181824; --border:#2a2a3a; --text:#e8e8f0; --sub:#8a8aa0; --accent:#7a4ddb; --gold:#ffd166; }
* { box-sizing: border-box; }
body {
  margin: 0; min-height: 100vh;
  display: flex; align-items: center; justify-content: center;
  background: radial-gradient(circle at 30% 0%, rgba(122,77,219,.2), transparent 55%), var(--bg);
  color: var(--text);
  font-family: system-ui, -apple-system, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
  padding: 20px;
}
.box {
  width: 100%; max-width: 460px;
  background: var(--panel); border: 1px solid var(--border);
  border-radius: 16px; padding: 32px;
  box-shadow: 0 30px 80px rgba(0,0,0,.5);
}
h1 { margin: 0 0 6px; font-size: 22px; background: linear-gradient(120deg, var(--gold), var(--accent)); -webkit-background-clip: text; background-clip: text; color: transparent; }
.sub { margin: 0 0 24px; color: var(--sub); font-size: 13px; }
.checklist { margin: 0 0 22px; padding: 0; list-style: none; }
.checklist li {
  display: flex; justify-content: space-between; align-items: center;
  padding: 8px 12px; border-radius: 8px; font-size: 13px; margin-bottom: 6px;
  background: rgba(255,255,255,.03);
}
.checklist .ok   { color: #4ade80; }
.checklist .fail { color: #ef4444; }
label { display: block; margin-bottom: 6px; font-size: 12px; color: var(--sub); }
input {
  width: 100%; padding: 11px 14px; margin-bottom: 16px;
  border-radius: 10px; border: 1px solid var(--border);
  background: #10101a; color: var(--text); font-size: 14px; outline: none;
}
input:focus { border-color: var(--accent); }
button {
  width: 100%; padding: 13px; border: none; border-radius: 10px; cursor: pointer;
  font-size: 15px; font-weight: 700; color: #fff;
  background: linear-gradient(120deg, #7a4ddb, #d63384);
  transition: filter .15s;
}
button:hover { filter: brightness(1.1); }
button:disabled { opacity: .5; cursor: not-allowed; }
.error {
  padding: 10px 14px; margin-bottom: 16px; border-radius: 8px;
  background: rgba(239,68,68,.12); color: #fca5a5; font-size: 13px;
}
.done {
  text-align: center; padding: 20px 0;
}
.done a {
  display: inline-block; margin-top: 16px; padding: 12px 24px;
  background: linear-gradient(120deg, #7a4ddb, #d63384);
  color: #fff; text-decoration: none; border-radius: 10px; font-weight: 700;
}
</style>
</head>
<body>
<div class="box">
<?php if ($done): ?>
  <div class="done">
    <h1>✓ 安装完成</h1>
    <p class="sub">管理员账号已创建</p>
    <a href="admin/index.php">前往登录 →</a>
  </div>
<?php else: ?>
  <h1>安装向导</h1>
  <p class="sub">第一次使用，需要检查环境并创建管理员</p>

  <ul class="checklist">
    <?php foreach ($checks as $label => $ok): ?>
      <li><span><?= h($label) ?></span>
          <span class="<?= $ok ? 'ok' : 'fail' ?>"><?= $ok ? '✓ 通过' : '✗ 未通过' ?></span></li>
    <?php endforeach; ?>
  </ul>

  <?php if ($error): ?>
    <div class="error"><?= h($error) ?></div>
  <?php endif; ?>

  <?php if ($all_ok): ?>
    <form method="post">
      <label>管理员用户名</label>
      <input type="text" name="username" required minlength="3" autocomplete="username">
      <label>密码（至少 6 位）</label>
      <input type="password" name="password" required minlength="6" autocomplete="new-password">
      <label>确认密码</label>
      <input type="password" name="confirm" required minlength="6" autocomplete="new-password">
      <button type="submit">创建管理员</button>
    </form>
  <?php else: ?>
    <div class="error">请先解决上面标记 ✗ 的环境问题，再刷新本页。</div>
  <?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>