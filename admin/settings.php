<?php
$pageTitle  = '站点设置';
$pageActive = 'settings';
require __DIR__ . '/_header.php';
require_once __DIR__ . '/../lib/upload.php';

$pdo = gacha_pdo();
$errors = [];

$adminId = (int)($_SESSION['admin_id'] ?? 0);

/* ================= POST 处理 ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)post('do');

    /* ---- 站点信息 ---- */
    if ($do === 'site') {
        $updates = [
            'site_title'      => trim((string)post('site_title')),
            'site_subtitle'   => trim((string)post('site_subtitle')),
            'site_footer'     => trim((string)post('site_footer')),
            'default_pool_id' => (string)(int)post('default_pool_id', 0),
            'timezone'        => trim((string)post('timezone', 'Asia/Shanghai')),
        ];

        /* 背景图 */
        $bgOld = (string)setting('background_url', '');
        $bg    = $bgOld;
        if (!empty($_POST['clear_background'])) {
            if (strpos($bgOld, 'uploads/') === 0) delete_upload($bgOld);
            $bg = '';
        } elseif (!empty($_FILES['background_file']['name'])) {
            $r = upload_image($_FILES['background_file'], 'bg');
            if ($r['ok']) {
                $bg = $r['path'];
            } else {
                $errors[] = '背景图上传失败：' . $r['error'];
            }
        } else {
            $url = trim((string)post('background_url_input', ''));
            if ($url !== '') {
                if (strpos($bgOld, 'uploads/') === 0) delete_upload($bgOld);
                $bg = $url;
            }
        }
        $updates['background_url'] = $bg;

        /* favicon */
        $favOld = (string)setting('favicon_url', '');
        $fav    = $favOld;
        if (!empty($_POST['clear_favicon'])) {
            if (strpos($favOld, 'uploads/') === 0) delete_upload($favOld);
            $fav = '';
        } elseif (!empty($_FILES['favicon_file']['name'])) {
            $r = upload_favicon($_FILES['favicon_file']);
            if ($r['ok']) {
                $fav = $r['path'];
            } else {
                $errors[] = '图标上传失败：' . $r['error'];
            }
        } else {
            $url = trim((string)post('favicon_url_input', ''));
            if ($url !== '') {
                $fav = $url;
            }
        }
        $updates['favicon_url'] = $fav;

        if (empty($errors)) {
            settings_set_many($updates);
            redirect('settings.php?msg=site&tab=site');
        }
    }

    /* ---- 自定义代码 ---- */
    if ($do === 'custom') {
        $head   = (string)post('custom_head');
        $footer = (string)post('custom_footer');
        $css    = (string)post('custom_css');
        // CSS 里禁止 </style 突破
        $css = preg_replace('#</style#i', '', $css);

        settings_set_many([
            'custom_head'   => $head,
            'custom_footer' => $footer,
            'custom_css'    => $css,
        ]);
        redirect('settings.php?msg=custom&tab=custom');
    }

    /* ---- 概率 ---- */
    if ($do === 'prob') {
        $rc = max(0, (float)post('rate_common', 78));
        $rr = max(0, (float)post('rate_rare',   20));
        $rl = max(0, (float)post('rate_legend',  2));
        if ($rc + $rr + $rl <= 0) {
            $errors[] = '三档基础概率不能全为 0';
        } else {
            $updates = [
                'rate_common' => (string)$rc,
                'rate_rare'   => (string)$rr,
                'rate_legend' => (string)$rl,
            ];
            for ($i = 1; $i <= 9; $i++) {
                $w = max(0, (float)post("weight_$i", 0));
                $updates["weight_$i"] = (string)$w;
            }
            $pityStart = max(1, (int)post('pity_start', 50));
            $pityStep  = max(0, (float)post('pity_step', 2));
            $pityHard  = max($pityStart, (int)post('pity_hard', 80));
            $updates['pity_start'] = (string)$pityStart;
            $updates['pity_step']  = (string)$pityStep;
            $updates['pity_hard']  = (string)$pityHard;
            settings_set_many($updates);
            redirect('settings.php?msg=prob&tab=prob');
        }
    }

    /* ---- 界面参数 ---- */
    if ($do === 'ui') {
        $delay = max(30, min(2000, (int)post('ten_pull_delay', 160)));
        settings_set_many(['ten_pull_delay' => (string)$delay]);
        redirect('settings.php?msg=ui&tab=ui');
    }

    /* ---- 管理员账号 ---- */
    if ($do === 'admin') {
        $stmt = $pdo->prepare('SELECT * FROM admins WHERE id = ?');
        $stmt->execute([$adminId]);
        $admin = $stmt->fetch();
        if (!$admin) {
            $errors[] = '管理员不存在';
        } else {
            $newUser = trim((string)post('admin_username'));
            $oldPw   = (string)post('old_password');
            $newPw   = (string)post('new_password');
            $newPw2  = (string)post('new_password2');

            if (mb_strlen($newUser) < 3) {
                $errors[] = '用户名至少 3 个字符';
            } else {
                $stmt = $pdo->prepare('SELECT id FROM admins WHERE username = ? AND id != ?');
                $stmt->execute([$newUser, $adminId]);
                if ($stmt->fetch()) $errors[] = '用户名已被占用';
            }

            $changePw = ($oldPw !== '' || $newPw !== '' || $newPw2 !== '');
            if ($changePw) {
                if (!password_verify($oldPw, $admin['password_hash'])) {
                    $errors[] = '当前密码不正确';
                } elseif (strlen($newPw) < 6) {
                    $errors[] = '新密码至少 6 位';
                } elseif ($newPw !== $newPw2) {
                    $errors[] = '两次新密码不一致';
                }
            }

            if (empty($errors)) {
                $sets = ['username = ?'];
                $params = [$newUser];
                if ($changePw) {
                    $sets[] = 'password_hash = ?';
                    $params[] = password_hash($newPw, PASSWORD_DEFAULT);
                }
                $params[] = $adminId;
                $pdo->prepare('UPDATE admins SET ' . implode(', ', $sets) . ' WHERE id = ?')
                    ->execute($params);
                $_SESSION['admin_name'] = $newUser;
                redirect('settings.php?msg=admin&tab=admin');
            }
        }
    }
}

/* ================= 载入当前值 ================= */
$vals = settings_all();
$g = function($k, $d = '') use ($vals) { return $vals[$k] ?? $d; };

$pools = $pdo->query('SELECT id, name FROM pools ORDER BY sort_order, id')->fetchAll();

$stmt = $pdo->prepare('SELECT * FROM admins WHERE id = ?');
$stmt->execute([$adminId]);
$admin = $stmt->fetch() ?: ['username' => ''];

$currentTab = (string)q('tab', 'site');
if (!in_array($currentTab, ['site', 'custom', 'prob', 'ui', 'admin'], true)) $currentTab = 'site';

$msgMap = [
    'site'   => '站点信息已保存',
    'custom' => '自定义代码已保存',
    'prob'   => '抽卡概率已保存',
    'ui'     => '界面参数已保存',
    'admin'  => '账号信息已保存',
];
$msg = (string)q('msg', '');
$msgText = $msgMap[$msg] ?? '';

/* 计算展示用：三档百分比归一化 */
$rc = (float)$g('rate_common', 78);
$rr = (float)$g('rate_rare',   20);
$rl = (float)$g('rate_legend',  2);
$sum = $rc + $rr + $rl;
if ($sum <= 0) $sum = 100;
$rcP = $rc / $sum * 100;
$rrP = $rr / $sum * 100;
$rlP = $rl / $sum * 100;
?>

<?php if ($msgText): ?>
  <div class="flash ok">✓ <?= h($msgText) ?></div>
<?php endif; ?>
<?php if ($errors): ?>
  <div class="flash err">
    <ul style="margin:0;padding-left:20px">
      <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<!-- ========== Tab 导航 ========== -->
<div class="settings-tabs" id="settings-tabs">
  <button type="button" class="settings-tab <?= $currentTab === 'site'   ? 'on' : '' ?>" data-tab="site">🏠 站点信息</button>
  <button type="button" class="settings-tab <?= $currentTab === 'custom' ? 'on' : '' ?>" data-tab="custom">💻 自定义代码</button>
  <button type="button" class="settings-tab <?= $currentTab === 'prob'   ? 'on' : '' ?>" data-tab="prob">🎲 抽卡概率</button>
  <button type="button" class="settings-tab <?= $currentTab === 'ui'     ? 'on' : '' ?>" data-tab="ui">✨ 界面参数</button>
  <button type="button" class="settings-tab <?= $currentTab === 'admin'  ? 'on' : '' ?>" data-tab="admin">👤 管理员账号</button>
</div>

<!-- ========== Tab: 站点信息 ========== -->
<div class="tab-pane <?= $currentTab === 'site' ? 'on' : '' ?>" data-pane="site">
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="do" value="site">

    <div class="settings-section">
      <h2>基础信息</h2>
      <p class="sec-desc">显示在前台顶部的标题、副标题、页脚文字</p>

      <div class="field-row">
        <label class="field-label">站点标题</label>
        <input type="text" name="site_title" class="settings-input" value="<?= h($g('site_title', '星海抽卡')) ?>">
      </div>

      <div class="field-row">
        <label class="field-label">副标题</label>
        <input type="text" name="site_subtitle" class="settings-input" value="<?= h($g('site_subtitle', 'GACHA SIMULATOR')) ?>">
      </div>

      <div class="field-row">
        <label class="field-label">页脚文字</label>
        <input type="text" name="site_footer" class="settings-input" value="<?= h($g('site_footer', '')) ?>" placeholder="留空则不显示">
      </div>

      <div class="field-row">
        <label class="field-label">默认卡池</label>
        <select name="default_pool_id" class="settings-select">
          <option value="0">（自动选择第一个上架的卡池）</option>
          <?php foreach ($pools as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= (int)$g('default_pool_id', 0) === (int)$p['id'] ? 'selected' : '' ?>>
              <?= h($p['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field-row">
        <label class="field-label">时区</label>
        <input type="text" name="timezone" class="settings-input" value="<?= h($g('timezone', 'Asia/Shanghai')) ?>">
        <span class="field-hint">如 Asia/Shanghai、UTC</span>
      </div>
    </div>

    <div class="settings-section">
      <h2>背景图片</h2>
      <p class="sec-desc">前台整体背景，建议横向大图。可上传或填外部 URL。</p>

      <?php $bgVal = (string)$g('background_url', ''); ?>
      <div class="image-field">
        <div class="preview-box tall">
          <?php if ($bgVal !== ''): ?>
            <img src="<?= h(upload_url($bgVal)) ?>" alt="">
          <?php else: ?>
            <span class="empty">暂无</span>
          <?php endif; ?>
        </div>
        <div class="controls">
          <div class="field-row">
            <label class="field-label">上传图片</label>
            <input type="file" name="background_file" accept="image/*">
          </div>
          <div class="field-row">
            <label class="field-label">或填写 URL</label>
            <input type="text" name="background_url_input" class="settings-input"
                   value="<?= h(strpos($bgVal, 'uploads/') === 0 ? '' : $bgVal) ?>"
                   placeholder="https://example.com/bg.jpg">
          </div>
          <?php if ($bgVal !== ''): ?>
            <label style="display:flex;gap:6px;align-items:center;font-size:12.5px;color:#fca5a5;cursor:pointer">
              <input type="checkbox" name="clear_background" value="1"> 清除当前背景图
            </label>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="settings-section">
      <h2>站点图标 favicon</h2>
      <p class="sec-desc">浏览器标签页的小图标。推荐 PNG / WebP / SVG / ICO，建议尺寸 32×32 或 64×64。</p>

      <?php $favVal = (string)$g('favicon_url', ''); ?>
      <div class="image-field">
        <div class="preview-box" style="width:80px;height:80px">
          <?php if ($favVal !== ''): ?>
            <img src="<?= h(upload_url($favVal)) ?>" alt="" style="width:64px;height:64px;object-fit:contain">
          <?php else: ?>
            <span class="empty">暂无</span>
          <?php endif; ?>
        </div>
        <div class="controls">
          <div class="field-row">
            <label class="field-label">上传图标</label>
            <input type="file" name="favicon_file" accept="image/*,.ico">
          </div>
          <div class="field-row">
            <label class="field-label">或填写 URL</label>
            <input type="text" name="favicon_url_input" class="settings-input"
                   value="<?= h(strpos($favVal, 'uploads/') === 0 ? '' : $favVal) ?>"
                   placeholder="https://example.com/favicon.png">
          </div>
          <?php if ($favVal !== ''): ?>
            <label style="display:flex;gap:6px;align-items:center;font-size:12.5px;color:#fca5a5;cursor:pointer">
              <input type="checkbox" name="clear_favicon" value="1"> 清除当前图标
            </label>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="form-foot">
      <button type="submit" class="btn primary">保存站点信息</button>
    </div>
  </form>
</div>

<!-- ========== Tab: 自定义代码 ========== -->
<div class="tab-pane <?= $currentTab === 'custom' ? 'on' : '' ?>" data-pane="custom">
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="do" value="custom">

    <div class="settings-section">
      <h2>自定义 &lt;head&gt; 内容</h2>
      <p class="sec-desc">
        插入到前台页面 &lt;head&gt; 里。可放 &lt;meta&gt;、&lt;link&gt;、&lt;style&gt;、统计脚本等。<br>
        <strong style="color:#fca5a5">⚠ 会原样输出，请只写你信任的代码。</strong>
      </p>
      <textarea name="custom_head" class="settings-textarea" rows="6"><?= h($g('custom_head', '')) ?></textarea>
    </div>

    <div class="settings-section">
      <h2>自定义页脚 HTML</h2>
      <p class="sec-desc">插入到前台页面 &lt;/body&gt; 之前，可放浮动按钮、备案号、回到顶部等。</p>
      <textarea name="custom_footer" class="settings-textarea" rows="6"><?= h($g('custom_footer', '')) ?></textarea>
    </div>

    <div class="settings-section">
      <h2>自定义 CSS</h2>
      <p class="sec-desc">会以 &lt;style&gt; 标签插入前台，用于微调颜色、字号、圆角等。</p>
      <textarea name="custom_css" class="settings-textarea" rows="10" placeholder="例如：&#10;.banner h2 { letter-spacing: 4px; }&#10;.action-btn { border-radius: 6px; }"><?= h($g('custom_css', '')) ?></textarea>
    </div>

    <div class="form-foot">
      <button type="submit" class="btn primary">保存自定义代码</button>
    </div>
  </form>
</div>

<!-- ========== Tab: 抽卡概率 ========== -->
<div class="tab-pane <?= $currentTab === 'prob' ? 'on' : '' ?>" data-pane="prob">
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="do" value="prob">

    <div class="settings-section">
      <h2>三档基础概率</h2>
      <p class="sec-desc">
        抽卡先决定落在哪一档，再在档内按权重挑具体星级。<br>
        <strong>三个数字总和不必等于 100</strong>，系统会自动按比例归一化。
      </p>
      <div class="prob-rate-row">
        <div>
          <label class="field-label">普通档（1★ ~ 3★）</label>
          <input type="number" step="0.01" min="0" name="rate_common" class="settings-input"
                 value="<?= h($g('rate_common', 78)) ?>">
          <span class="field-hint">当前归一化后：<?= number_format($rcP, 2) ?>%</span>
        </div>
        <div>
          <label class="field-label">稀有档（4★ ~ 5★）</label>
          <input type="number" step="0.01" min="0" name="rate_rare" class="settings-input"
                 value="<?= h($g('rate_rare', 20)) ?>">
          <span class="field-hint">当前归一化后：<?= number_format($rrP, 2) ?>%</span>
        </div>
        <div>
          <label class="field-label">传说档（6★ ~ 9★）</label>
          <input type="number" step="0.01" min="0" name="rate_legend" class="settings-input"
                 value="<?= h($g('rate_legend', 2)) ?>">
          <span class="field-hint">当前归一化后：<?= number_format($rlP, 2) ?>%</span>
        </div>
      </div>
    </div>

    <div class="settings-section">
      <h2>各星级在档内的相对权重</h2>
      <p class="sec-desc">只影响档内分配比例，不影响档位总概率。可以随便填数字，系统按比例算。</p>

      <div class="tier-block">
        <h3>普通档</h3>
        <div class="weight-grid">
          <?php for ($i = 1; $i <= 3; $i++): ?>
            <div class="weight-item">
              <label><?= $i ?>★ 权重</label>
              <input type="number" step="0.1" min="0" name="weight_<?= $i ?>" value="<?= h($g("weight_$i", [1=>30,2=>40,3=>30][$i])) ?>">
            </div>
          <?php endfor; ?>
        </div>
      </div>

      <div class="tier-block">
        <h3>稀有档</h3>
        <div class="weight-grid">
          <?php for ($i = 4; $i <= 5; $i++): ?>
            <div class="weight-item">
              <label><?= $i ?>★ 权重</label>
              <input type="number" step="0.1" min="0" name="weight_<?= $i ?>" value="<?= h($g("weight_$i", [4=>70,5=>30][$i])) ?>">
            </div>
          <?php endfor; ?>
        </div>
      </div>

      <div class="tier-block">
        <h3>传说档</h3>
        <div class="weight-grid">
          <?php for ($i = 6; $i <= 9; $i++): ?>
            <div class="weight-item">
              <label><?= $i ?>★ 权重</label>
              <input type="number" step="0.1" min="0" name="weight_<?= $i ?>" value="<?= h($g("weight_$i", [6=>75,7=>18,8=>5.5,9=>1.5][$i])) ?>">
            </div>
          <?php endfor; ?>
        </div>
      </div>
    </div>

    <div class="settings-section">
      <h2>6★ 及以上软保底</h2>
      <p class="sec-desc">从指定抽数开始，每次未出 6★ 就累加概率，直到硬保底。</p>
      <div class="prob-rate-row">
        <div>
          <label class="field-label">从第几抽开始加概率</label>
          <input type="number" min="1" name="pity_start" class="settings-input"
                 value="<?= h($g('pity_start', 50)) ?>">
        </div>
        <div>
          <label class="field-label">每抽累加（%）</label>
          <input type="number" step="0.1" min="0" name="pity_step" class="settings-input"
                 value="<?= h($g('pity_step', 2)) ?>">
        </div>
        <div>
          <label class="field-label">硬保底抽数</label>
          <input type="number" min="1" name="pity_hard" class="settings-input"
                 value="<?= h($g('pity_hard', 80)) ?>">
        </div>
      </div>
    </div>

    <div class="form-foot">
      <button type="submit" class="btn primary">保存概率设置</button>
    </div>
  </form>
</div>

<!-- ========== Tab: 界面参数 ========== -->
<div class="tab-pane <?= $currentTab === 'ui' ? 'on' : '' ?>" data-pane="ui">
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="do" value="ui">

    <div class="settings-section">
      <h2>十连过场速度</h2>
      <p class="sec-desc">
        数值越小，每张立绘停留越短；数值越大，越有仪式感。<br>
        建议范围 80 ~ 400 毫秒。
      </p>
      <div class="field-row">
        <label class="field-label">每张停留（毫秒）</label>
        <input type="number" min="30" max="2000" name="ten_pull_delay" class="settings-input"
               value="<?= h($g('ten_pull_delay', 160)) ?>">
        <span class="field-hint">当前值：<?= h($g('ten_pull_delay', 160)) ?> ms</span>
      </div>
    </div>

    <div class="form-foot">
      <button type="submit" class="btn primary">保存界面参数</button>
    </div>
  </form>
</div>

<!-- ========== Tab: 管理员账号 ========== -->
<div class="tab-pane <?= $currentTab === 'admin' ? 'on' : '' ?>" data-pane="admin">
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="do" value="admin">

    <div class="settings-section">
      <h2>用户名</h2>
      <div class="field-row">
        <label class="field-label">管理员用户名</label>
        <input type="text" name="admin_username" class="settings-input" value="<?= h($admin['username']) ?>" required>
      </div>
    </div>

    <div class="settings-section">
      <h2>修改密码</h2>
      <p class="sec-desc">不修改密码就留空这三个输入框。修改时需要验证当前密码。</p>
      <div class="prob-rate-row">
        <div>
          <label class="field-label">当前密码</label>
          <input type="password" name="old_password" class="settings-input" autocomplete="current-password">
        </div>
        <div>
          <label class="field-label">新密码（至少 6 位）</label>
          <input type="password" name="new_password" class="settings-input" autocomplete="new-password">
        </div>
        <div>
          <label class="field-label">确认新密码</label>
          <input type="password" name="new_password2" class="settings-input" autocomplete="new-password">
        </div>
      </div>
    </div>

    <div class="form-foot">
      <button type="submit" class="btn primary">保存账号信息</button>
    </div>
  </form>
</div>

<script>
(function () {
  var tabs = document.querySelectorAll('.settings-tab');
  var panes = document.querySelectorAll('.tab-pane');
  tabs.forEach(function (t) {
    t.addEventListener('click', function () {
      var target = t.getAttribute('data-tab');
      tabs.forEach(function (x) { x.classList.remove('on'); });
      panes.forEach(function (p) {
        p.classList.toggle('on', p.getAttribute('data-pane') === target);
      });
      t.classList.add('on');
      history.replaceState(null, '', '?tab=' + target);
    });
  });
})();
</script>

<style>
.settings-tabs {
  display: flex; gap: 6px; flex-wrap: wrap;
  margin-bottom: 22px;
  border-bottom: 1px solid var(--border);
  padding-bottom: 12px;
}
.settings-tab {
  padding: 8px 16px; border-radius: 10px;
  border: 1px solid var(--border);
  background: var(--panel-2);
  color: var(--sub);
  cursor: pointer; font-size: 13.5px;
  font-family: inherit;
  transition: .15s;
}
.settings-tab:hover { color: var(--text); }
.settings-tab.on {
  background: linear-gradient(120deg, var(--accent), var(--accent-2));
  color: #fff; border-color: transparent; font-weight: 700;
}
.tab-pane { display: none; }
.tab-pane.on { display: block; }

.settings-section {
  background: var(--panel); border: 1px solid var(--border);
  border-radius: 14px; padding: 24px; margin-bottom: 20px;
  transition: background .25s, border-color .25s;
}
.settings-section h2 { margin: 0 0 6px; font-size: 16px; color: var(--text); }
.settings-section .sec-desc { color: var(--sub); font-size: 12.5px; margin-bottom: 18px; line-height: 1.7; }

.field-row { margin-bottom: 16px; }
.field-label {
  display: block; font-size: 12px; color: var(--sub);
  font-weight: 600; margin-bottom: 6px; letter-spacing: .3px;
}
.field-hint { display: block; font-size: 11.5px; color: var(--sub); margin-top: 4px; opacity: .75; }

.settings-input, .settings-textarea, .settings-select {
  width: 100%; padding: 10px 13px;
  border-radius: 10px; border: 1px solid var(--border);
  background: var(--input-bg); color: var(--text);
  font-size: 13.5px; font-family: inherit; outline: none;
  box-sizing: border-box;
  transition: background .25s, border-color .25s;
}
.settings-textarea {
  min-height: 100px; resize: vertical;
  font-family: ui-monospace, Menlo, Consolas, monospace;
  font-size: 12.5px; line-height: 1.6;
}
.settings-input:focus, .settings-textarea:focus, .settings-select:focus { border-color: var(--accent); }

.image-field { display: flex; gap: 18px; align-items: flex-start; flex-wrap: wrap; }
.image-field .preview-box {
  width: 160px; height: 100px; flex-shrink: 0;
  background: var(--input-bg); border: 1px solid var(--border); border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  overflow: hidden;
}
.image-field .preview-box.tall { height: 160px; }
.image-field .preview-box img { max-width: 100%; max-height: 100%; object-fit: contain; display: block; }
.image-field .preview-box .empty { color: var(--sub); font-size: 12px; }
.image-field .controls { flex: 1; min-width: 240px; }

.prob-rate-row {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 14px;
}

.tier-block {
  padding: 16px; border-radius: 12px;
  background: var(--panel-2); border: 1px solid var(--border);
  margin-bottom: 14px;
  transition: background .25s, border-color .25s;
}
.tier-block h3 { margin: 0 0 12px; font-size: 14px; color: var(--gold); }
.weight-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; }
.weight-item { display: flex; flex-direction: column; }
.weight-item label { font-size: 11.5px; color: var(--sub); margin-bottom: 4px; }
.weight-item input {
  width: 100%; padding: 8px 10px;
  border-radius: 8px; border: 1px solid var(--border);
  background: var(--input-bg); color: var(--text);
  font-size: 13px; font-family: inherit; outline: none;
  box-sizing: border-box;
  transition: background .25s, border-color .25s;
}
.weight-item input:focus { border-color: var(--accent); }

@media (max-width: 720px) {
  .prob-rate-row { grid-template-columns: 1fr; }
  .image-field .preview-box { width: 120px; height: 90px; }
  .image-field .preview-box.tall { height: 120px; }
}
</style>

<?php require __DIR__ . '/_footer.php'; ?>