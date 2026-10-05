<?php
$pageTitle  = '素材库';
$pageActive = 'uploads';
require __DIR__ . '/_header.php';
require_once __DIR__ . '/../lib/upload.php';

$pdo = gacha_pdo();
$errors = [];

/* ================= POST 处理 ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = (string)post('do');

    /* ---- 删除 ---- */
    if ($do === 'delete') {
        $rel = (string)post('path');
        if (strpos($rel, 'uploads/') !== 0 || strpos($rel, '..') !== false) {
            $errors[] = '非法路径';
        } else {
            $refs = find_refs($pdo, $rel);
            if (!empty($refs)) {
                $errors[] = '该文件正在被引用，无法删除：' . implode('、', $refs);
            } else {
                $full = GACHA_ROOT . '/' . $rel;
                if (is_file($full)) @unlink($full);
                redirect('uploads.php?msg=deleted');
            }
        }
    }

    /* ---- 本地上传 ---- */
    if ($do === 'upload') {
        if (empty($_FILES['file']['name'])) {
            $errors[] = '请选择要上传的文件';
        } else {
            $r = upload_image($_FILES['file'], 'library');
            if ($r['ok']) redirect('uploads.php?msg=uploaded');
            else          $errors[] = '上传失败：' . $r['error'];
        }
    }

    /* ---- URL 导入 ---- */
    if ($do === 'import') {
        $url = trim((string)post('url'));
        if ($url === '') {
            $errors[] = '请输入图片 URL';
        } else {
            $r = import_image_from_url($url, 'library');
            if ($r['ok']) redirect('uploads.php?msg=imported');
            else          $errors[] = '导入失败：' . $r['error'];
        }
    }
}

/* ================= 引用检查 ================= */
function find_refs(PDO $pdo, string $rel): array {
    $refs = [];
    $stmt = $pdo->prepare('SELECT name FROM characters WHERE image_full = ? OR image_half = ? OR image_chibi = ?');
    $stmt->execute([$rel, $rel, $rel]);
    foreach ($stmt->fetchAll() as $r) {
        $refs[] = '角色「' . $r['name'] . '」';
    }
    $stmt = $pdo->prepare("SELECT key FROM settings WHERE value = ? AND key IN ('background_url','favicon_url')");
    $stmt->execute([$rel]);
    foreach ($stmt->fetchAll() as $r) {
        $map = ['background_url' => '站点背景图', 'favicon_url' => '站点图标'];
        $refs[] = $map[$r['key']] ?? $r['key'];
    }
    return $refs;
}

/* ================= 收集文件 ================= */
$catLabels = [
    'library' => '素材库',
    'full'    => '立绘大图',
    'half'    => '半身立绘',
    'chibi'   => 'Q 版',
    'icon'    => '图标',
    'bg'      => '背景图',
];

$files = [];
/* ================= 计算站点根 URL ================= */
$scheme = 'http';
if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    $scheme = explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0];
} elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $scheme = 'https';
} elseif (($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on') {
    $scheme = 'https';
}
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$rootUrl = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
$baseUrl = $scheme . '://' . $host . $rootUrl;   // 例如 https://example.com/gacha
$base  = GACHA_UPLOAD_DIR;
if (is_dir($base)) {
    $cats = @scandir($base) ?: [];
    foreach ($cats as $cat) {
        if ($cat === '.' || $cat === '..') continue;
        $catDir = $base . '/' . $cat;
        if (!is_dir($catDir)) continue;
        $sub = @scandir($catDir) ?: [];
        foreach ($sub as $f) {
            if ($f === '.' || $f === '..') continue;
            $full = $catDir . '/' . $f;
            if (!is_file($full)) continue;
            $rel = 'uploads/' . $cat . '/' . $f;
			$fullUrl = $baseUrl . '/' . $rel;
            $files[] = [
                'cat'   => $cat,
                'name'  => $f,
                'rel'   => $rel,
				'full_url' => $fullUrl,
                'size'  => filesize($full),
                'mtime' => filemtime($full),
                'refs'  => find_refs($pdo, $rel),
                'isImg' => (bool)preg_match('/\.(png|jpe?g|gif|webp|svg|ico)$/i', $f),
            ];
        }
    }
}
usort($files, function ($a, $b) { return $b['mtime'] - $a['mtime']; });

$totalSize  = 0;
foreach ($files as $f) $totalSize += $f['size'];
$totalCount = count($files);

$msg = (string)q('msg', '');
$msgText = [
    'deleted'  => '已删除',
    'uploaded' => '上传成功',
    'imported' => '导入成功',
][$msg] ?? '';

/* 各分类数量统计（用于筛选按钮） */
$catCount = ['all' => $totalCount];
foreach ($catLabels as $k => $_) $catCount[$k] = 0;
foreach ($files as $f) if (isset($catCount[$f['cat']])) $catCount[$f['cat']]++;
?>

<style>
.lib-page { max-width: 1200px; }
.lib-flash {
  padding: 12px 16px; margin-bottom: 18px; border-radius: 10px; font-size: 13.5px;
}
.lib-flash.ok  { background: var(--ok-bg); color: var(--ok); border: 1px solid var(--ok-border); }
.lib-flash.err { background: var(--danger-bg); color: var(--danger); border: 1px solid var(--danger-border); }

.lib-tools { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
.lib-tool {
  background: var(--panel); border: 1px solid var(--border);
  border-radius: 14px; padding: 20px 22px;
  transition: background .25s, border-color .25s;
}
.lib-tool h3 {
  margin: 0 0 12px; font-size: 14.5px; font-weight: 700;
  display: flex; align-items: center; gap: 8px;
  color: var(--text);
}
.lib-tool .hint { color: var(--sub); font-size: 12px; margin-bottom: 12px; line-height: 1.6; }
.lib-tool input[type=file],
.lib-tool input[type=text] {
  width: 100%; padding: 10px 13px; border-radius: 10px;
  border: 1px solid var(--border); background: var(--input-bg); color: var(--text);
  font-size: 13px; font-family: inherit; outline: none; box-sizing: border-box;
  margin-bottom: 10px;
  transition: background .25s, border-color .25s;
}
.lib-tool input:focus { border-color: var(--accent); }
.lib-tool input[type=file] { padding: 8px; font-size: 12px; color: var(--sub); border-style: dashed; }
.lib-tool button {
  padding: 10px 20px; border-radius: 10px; border: none; cursor: pointer;
  background: linear-gradient(120deg, var(--accent), var(--accent-2));
  color: #fff; font-size: 13.5px; font-weight: 700; font-family: inherit;
}
.lib-tool button:hover { filter: brightness(1.1); }
.lib-tool button:disabled { opacity: .5; cursor: not-allowed; }

.lib-bar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 18px; }
.lib-search {
  flex: 1; min-width: 220px; max-width: 380px;
  padding: 11px 16px; border-radius: 12px;
  border: 1px solid var(--border); background: var(--input-bg); color: var(--text);
  font-size: 13.5px; font-family: inherit; outline: none;
  transition: background .25s, border-color .25s;
}
.lib-search:focus { border-color: var(--accent); }
.lib-chips { display: flex; gap: 6px; flex-wrap: wrap; }
.lib-chip {
  padding: 7px 14px; border-radius: 20px; font-size: 12.5px;
  border: 1px solid var(--border); background: var(--panel-2);
  color: var(--sub); cursor: pointer; user-select: none;
  font-family: inherit;
  transition: .15s;
}
.lib-chip:hover { color: var(--text); }
.lib-chip.on {
  background: linear-gradient(120deg, var(--accent), var(--accent-2));
  color: #fff; border-color: transparent; font-weight: 700;
}
.lib-stats { font-size: 12.5px; color: var(--sub); margin-left: auto; }
.lib-stats strong { color: var(--gold); }

.lib-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
  gap: 14px;
}
.lib-card {
  background: var(--panel); border: 1px solid var(--border);
  border-radius: 12px; overflow: hidden;
  display: flex; flex-direction: column;
  transition: transform .15s, box-shadow .2s, background .25s, border-color .25s;
}
.lib-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
.lib-thumb {
  height: 150px; background: var(--input-bg);
  display: flex; align-items: center; justify-content: center;
  overflow: hidden; position: relative;
}
.lib-thumb img { max-width: 100%; max-height: 100%; object-fit: contain; display: block; }
.lib-thumb .cat {
  position: absolute; top: 6px; left: 6px;
  padding: 2px 8px; border-radius: 5px;
  background: rgba(0,0,0,.72); color: #fff;
  font-size: 10px; font-weight: 700;
  backdrop-filter: blur(4px);
}
.lib-thumb .ref-mark {
  position: absolute; top: 6px; right: 6px;
  padding: 2px 8px; border-radius: 5px;
  background: rgba(255,209,102,.9); color: #4a2b00;
  font-size: 10px; font-weight: 800;
}
.lib-body { padding: 10px 12px; flex: 1; display: flex; flex-direction: column; gap: 4px; }
.lib-name {
  font-size: 11.5px; word-break: break-all; line-height: 1.3;
  font-family: ui-monospace, Menlo, Consolas, monospace;
  color: var(--text);
}
.lib-meta { font-size: 11px; color: var(--sub); }
.lib-path {
  font-size: 10.5px; color: var(--sub); opacity: .75;
  word-break: break-all; margin-top: 2px;
  font-family: ui-monospace, Menlo, Consolas, monospace;
}
.lib-refs { font-size: 11px; color: var(--gold); margin-top: 4px; line-height: 1.4; }
.lib-refs.none { color: var(--sub); opacity: .7; }
.lib-actions {
  display: flex; gap: 6px; padding: 8px 12px;
  border-top: 1px solid var(--border);
}
.lib-actions button, .lib-actions a {
  flex: 1; padding: 6px 8px; font-size: 11.5px; border-radius: 6px;
  text-align: center; text-decoration: none; cursor: pointer;
  border: 1px solid var(--border); background: var(--panel-2);
  color: var(--text); font-family: inherit;
  transition: .15s;
}
.lib-actions button:hover, .lib-actions a:hover { background: var(--overlay-strong); }
.lib-actions .danger { color: var(--danger); }
.lib-actions .danger:hover { background: var(--danger-bg); border-color: var(--danger-border); }
.lib-actions button:disabled { opacity: .35; cursor: not-allowed; }
.lib-actions button:disabled:hover { background: var(--panel-2); }

.lib-empty {
  padding: 60px 20px; text-align: center; color: var(--sub);
  border: 1px dashed var(--border); border-radius: 14px;
  background: var(--overlay); grid-column: 1 / -1;
}

@media (max-width: 780px) {
  .lib-tools { grid-template-columns: 1fr; }
  .lib-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); }
  .lib-thumb { height: 120px; }
  .lib-stats { margin-left: 0; width: 100%; }
}
</style>

<!-- DEBUG baseUrl=<?= h($baseUrl) ?> scheme=<?= h($scheme) ?> host=<?= h($host) ?> rootUrl=<?= h($rootUrl) ?> -->
<div class="lib-page">

<?php if ($msgText): ?>
  <div class="lib-flash ok">✓ <?= h($msgText) ?></div>
<?php endif; ?>
<?php if ($errors): ?>
  <div class="lib-flash err">
    <?php foreach ($errors as $e): ?>· <?= h($e) ?><br><?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- ========== 工具区 ========== -->
<div class="lib-tools">
  <div class="lib-tool">
    <h3>📤 本地上传</h3>
    <p class="hint">支持 JPG / PNG / WebP / GIF，单张不超过 5MB。上传后会自动压缩并转为 WebP。</p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="do" value="upload">
      <input type="file" name="file" accept="image/*" required>
      <button type="submit">上传到素材库</button>
    </form>
  </div>

  <div class="lib-tool">
    <h3>🔗 从 URL 导入</h3>
    <p class="hint">粘贴图片的直链，服务器会下载并保存到本地。支持大多数公开图床。<br>
       <span style="color:#fca5a5">⚠ 不允许内网地址。</span></p>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="do" value="import">
      <input type="text" name="url" placeholder="https://example.com/image.png" required>
      <button type="submit">下载并导入</button>
    </form>
  </div>
</div>

<!-- ========== 筛选栏 ========== -->
<div class="lib-bar">
  <input type="text" id="lib-search" class="lib-search" placeholder="🔍 搜索文件名或路径…" autocomplete="off">

  <div class="lib-chips" id="lib-cat-chips">
    <button type="button" class="lib-chip on" data-cat="all">全部 (<?= $catCount['all'] ?>)</button>
    <?php foreach ($catLabels as $k => $label): if ($catCount[$k] === 0) continue; ?>
      <button type="button" class="lib-chip" data-cat="<?= h($k) ?>"><?= h($label) ?> (<?= $catCount[$k] ?>)</button>
    <?php endforeach; ?>
  </div>

  <div class="lib-chips" id="lib-ref-chips">
    <button type="button" class="lib-chip on" data-ref="all">全部</button>
    <button type="button" class="lib-chip" data-ref="used">已引用</button>
    <button type="button" class="lib-chip" data-ref="free">未引用</button>
  </div>

  <div class="lib-stats" id="lib-stats">
    共 <strong><?= $totalCount ?></strong> 个文件 ·
    <strong><?= h(format_size((int)$totalSize)) ?></strong>
  </div>
</div>

<!-- ========== 文件网格 ========== -->
<?php if (empty($files)): ?>
  <div class="lib-empty">
    <p>还没有任何文件。</p>
    <p style="font-size:12.5px;opacity:.7">上传图片或从 URL 导入后，会在这里显示。</p>
  </div>
<?php else: ?>
  <div class="lib-grid" id="lib-grid">
    <?php foreach ($files as $f): ?>
      <?php
        $hasRef = !empty($f['refs']);
        $nameLo = strtolower($f['name'] . ' ' . $f['rel']);
      ?>
      <div class="lib-card"
           data-cat="<?= h($f['cat']) ?>"
           data-ref="<?= $hasRef ? 'used' : 'free' ?>"
           data-search="<?= h($nameLo) ?>">
        <div class="lib-thumb">
          <?php if ($f['isImg']): ?>
            <img src="<?= h(upload_url($f['rel'])) ?>" alt="" loading="lazy">
          <?php else: ?>
            <span style="color:#8a8aa0;font-size:12px">非图片</span>
          <?php endif; ?>
          <span class="cat"><?= h($catLabels[$f['cat']] ?? $f['cat']) ?></span>
          <?php if ($hasRef): ?>
            <span class="ref-mark">已引用</span>
          <?php endif; ?>
        </div>
        <div class="lib-body">
          <div class="lib-name"><?= h($f['name']) ?></div>
          <div class="lib-meta">
            <?= h(format_size((int)$f['size'])) ?> ·
            <?= h(date('Y-m-d H:i', $f['mtime'])) ?>
          </div>
          <div class="lib-path"><?= h($f['rel']) ?></div>
          <?php if ($hasRef): ?>
            <div class="lib-refs">🔗 <?= h(implode('、', $f['refs'])) ?></div>
          <?php else: ?>
            <div class="lib-refs none">未被引用</div>
          <?php endif; ?>
        </div>
        <div class="lib-actions">
          <button type="button" class="copy-path" data-url="<?= h($f['full_url']) ?>">复制链接</button>
          <form method="post" style="display:contents"
                onsubmit="return confirm('确定删除这个文件？')">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="path" value="<?= h($f['rel']) ?>">
            <button type="submit" class="danger"
                    <?= $hasRef ? 'disabled title="该文件正在被引用"' : '' ?>>删除</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

</div>

<script>
(function () {
  var searchInput = document.getElementById('lib-search');
  var catChips    = document.querySelectorAll('#lib-cat-chips .lib-chip');
  var refChips    = document.querySelectorAll('#lib-ref-chips .lib-chip');
  var cards       = document.querySelectorAll('.lib-card');
  var stats       = document.getElementById('lib-stats');
  if (!cards.length) return;

  var state = { text: '', cat: 'all', ref: 'all' };

  function apply() {
    var q = state.text.toLowerCase();
    var visible = 0;
    cards.forEach(function (c) {
      var key = c.getAttribute('data-search') || '';
      var cat = c.getAttribute('data-cat') || '';
      var ref = c.getAttribute('data-ref') || '';
      var okT = (q === '' || key.indexOf(q) !== -1);
      var okC = (state.cat === 'all' || cat === state.cat);
      var okR = (state.ref === 'all' || ref === state.ref);
      if (okT && okC && okR) { c.style.display = ''; visible++; }
      else { c.style.display = 'none'; }
    });
    if (stats) stats.innerHTML = '显示 <strong>' + visible + '</strong> / ' + cards.length + ' 个文件';
  }

  if (searchInput) searchInput.addEventListener('input', function () {
    state.text = this.value; apply();
  });

  function bindChips(chips, key) {
    chips.forEach(function (btn) {
      btn.addEventListener('click', function () {
        chips.forEach(function (x) { x.classList.remove('on'); });
        btn.classList.add('on');
        state[key] = btn.getAttribute('data-' + (key === 'cat' ? 'cat' : 'ref'));
        apply();
      });
    });
  }
  bindChips(Array.prototype.slice.call(catChips), 'cat');
  bindChips(Array.prototype.slice.call(refChips), 'ref');

  // 复制路径
  document.querySelectorAll('.copy-path').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var url = btn.getAttribute('data-url') || btn.getAttribute('data-path') || '';
        navigator.clipboard.writeText(url).then(function () {
            var old = btn.textContent;
            btn.textContent = '✓ 已复制';
            setTimeout(function () { btn.textContent = old; }, 1200);
        });
    });
  });

  apply();
})();
</script>

<?php require __DIR__ . '/_footer.php'; ?>