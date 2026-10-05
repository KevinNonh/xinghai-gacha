<?php
$pageTitle  = '编辑角色';
$pageActive = 'characters';
require __DIR__ . '/_header.php';
require_once __DIR__ . '/../lib/upload.php';

$pdo = gacha_pdo();
$id  = (int)q('id', 0);
$isNew = $id === 0;

$errors = [];

$ch = [
    'code'        => '',
    'name'        => '',
    'title'       => '',
    'rarity'      => 3,
    'image_full'  => '',
    'image_half'  => '',
    'image_chibi' => '',
    'color_a'     => '#a6e3f0',
    'color_b'     => '#2c6c99',
    'avatar_text' => '',
];
if (!$isNew) {
    $stmt = $pdo->prepare('SELECT * FROM characters WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) redirect('characters.php');
    $ch = array_merge($ch, $row);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $ch['code']        = trim((string)post('code'));
    $ch['name']        = trim((string)post('name'));
    $ch['title']       = trim((string)post('title'));
    $ch['rarity']      = max(1, min(9, (int)post('rarity', 3)));
    $ch['color_a']     = trim((string)post('color_a', '#888888'));
    $ch['color_b']     = trim((string)post('color_b', '#444444'));
    $ch['avatar_text'] = function_exists('mb_substr')
                            ? mb_substr(trim((string)post('avatar_text')), 0, 2, 'UTF-8')
                            : substr(trim((string)post('avatar_text')), 0, 2);

    if ($ch['code'] === '') $errors[] = 'code 不能为空';
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $ch['code'])) $errors[] = 'code 只能包含字母、数字、下划线、连字符';
    if ($ch['name'] === '') $errors[] = '名字不能为空';

    if (empty($errors)) {
        $stmt = $pdo->prepare('SELECT id FROM characters WHERE code = ? AND id != ?');
        $stmt->execute([$ch['code'], $id]);
        if ($stmt->fetch()) $errors[] = '该 code 已被其他角色使用';
    }

    $imgFields = [
        'image_full'  => 'full',
        'image_half'  => 'half',
        'image_chibi' => 'chibi',
    ];

    foreach ($imgFields as $field => $subdir) {
        $oldVal  = (string)$ch[$field];
        $isLocal = strpos($oldVal, 'uploads/') === 0;

        /* ---- 清除当前图片 ---- */
        if (!empty($_POST['clear_' . $field])) {
            // 只有本地上传的文件才考虑删除
            if ($isLocal) {
                // 检查是不是还有别的地方引用（排除当前角色自己）
                $refs = find_file_refs($oldVal, $id);
                if (empty($refs)) {
                    delete_upload($oldVal);       // 没人引用 → 可以删
                }
                // 有别的引用 → 只清空字段，文件保留
            }
            $ch[$field] = '';
            continue;
        }

        /* ---- 上传新图：旧图不删，留在素材库 ---- */
        if (!empty($_FILES[$field]['name'])) {
            $r = upload_image($_FILES[$field], $subdir);
            if ($r['ok']) {
                $ch[$field] = $r['path'];       // 直接换新路径，旧文件保留
            } else {
                $errors[] = "$field 上传失败：{$r['error']}";
            }
            continue;
        }

        /* ---- 填 URL / 素材库路径：旧图同样不删 ---- */
        $url = trim((string)post($field . '_url', ''));
        if ($url !== $oldVal) {
            $ch[$field] = $url;
        }
    }

    if (empty($errors)) {
        if ($isNew) {
            $stmt = $pdo->prepare('INSERT INTO characters (code, name, title, rarity, image_full, image_half, image_chibi, color_a, color_b, avatar_text, created_at)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $ch['code'], $ch['name'], $ch['title'], $ch['rarity'],
                $ch['image_full'], $ch['image_half'], $ch['image_chibi'],
                $ch['color_a'], $ch['color_b'], $ch['avatar_text'], time(),
            ]);
        } else {
            $stmt = $pdo->prepare('UPDATE characters SET code=?, name=?, title=?, rarity=?, image_full=?, image_half=?, image_chibi=?, color_a=?, color_b=?, avatar_text=? WHERE id=?');
            $stmt->execute([
                $ch['code'], $ch['name'], $ch['title'], $ch['rarity'],
                $ch['image_full'], $ch['image_half'], $ch['image_chibi'],
                $ch['color_a'], $ch['color_b'], $ch['avatar_text'], $id,
            ]);
        }
        redirect('characters.php?msg=saved');
    }
}
?>

<style>
.lib-picker-mask {
  position: fixed; inset: 0; z-index: 999;
  background: rgba(0,0,0,.6);
  display: none;
  align-items: center; justify-content: center;
  padding: 20px;
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
}
.lib-picker-mask.show { display: flex; }
.lib-picker {
  width: 100%; max-width: 860px; max-height: 86vh;
  background: var(--panel); border: 1px solid var(--border);
  border-radius: 16px;
  display: flex; flex-direction: column;
  overflow: hidden;
  animation: libPickerIn .22s ease-out;
}
@keyframes libPickerIn {
  from { transform: scale(.96); opacity: 0; }
  to   { transform: scale(1); opacity: 1; }
}
.lib-picker-head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 22px;
  border-bottom: 1px solid var(--border);
}
.lib-picker-head h3 { margin: 0; font-size: 15px; color: var(--text); }
.lib-picker-head .sub { color: var(--sub); font-size: 12px; margin-left: 10px; }
.lib-picker-close {
  width: 30px; height: 30px; border-radius: 8px;
  border: 1px solid var(--border); background: transparent;
  color: var(--sub); font-size: 13px; cursor: pointer;
  font-family: inherit;
}
.lib-picker-close:hover { background: var(--overlay-strong); color: var(--text); }
.lib-picker-body { flex: 1; overflow-y: auto; padding: 16px 22px 22px; }
.lib-picker-search {
  width: 100%; padding: 10px 14px; margin-bottom: 14px;
  border-radius: 10px; border: 1px solid var(--border);
  background: var(--input-bg); color: var(--text);
  font-size: 13px; font-family: inherit; outline: none;
  transition: background .25s, border-color .25s;
}
.lib-picker-search:focus { border-color: var(--accent); }
.lib-picker-chips { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; }
.lib-picker-chip {
  padding: 5px 12px; border-radius: 16px; font-size: 12px;
  border: 1px solid var(--border); background: var(--panel-2);
  color: var(--sub); cursor: pointer; font-family: inherit;
  transition: .15s;
}
.lib-picker-chip:hover { color: var(--text); }
.lib-picker-chip.on {
  background: linear-gradient(120deg, var(--accent), var(--accent-2));
  color: #fff; border-color: transparent; font-weight: 700;
}
.lib-picker-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
  gap: 10px;
}
.lib-picker-item {
  position: relative;
  border-radius: 10px; overflow: hidden;
  border: 2px solid var(--border);
  background: var(--input-bg);
  aspect-ratio: 1 / 1;
  cursor: pointer;
  transition: transform .12s, border-color .2s;
}
.lib-picker-item:hover {
  border-color: var(--accent);
  transform: translateY(-2px);
}
.lib-picker-item img { width: 100%; height: 100%; object-fit: cover; display: block; }
.lib-picker-item .lb-cat {
  position: absolute; top: 4px; left: 4px;
  padding: 1px 6px; border-radius: 4px;
  background: rgba(0,0,0,.75); color: #fff;
  font-size: 9px; font-weight: 700;
  backdrop-filter: blur(4px);
}
.lib-picker-item .lb-name {
  position: absolute; left: 0; right: 0; bottom: 0;
  padding: 4px 6px;
  background: linear-gradient(to top, rgba(0,0,0,.85), transparent);
  color: #fff; font-size: 10px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.lib-picker-empty {
  padding: 50px 20px; text-align: center;
  color: var(--sub); font-size: 13.5px;
  border: 1px dashed var(--border); border-radius: 12px;
}
.lib-picker-empty a { color: var(--accent); text-decoration: none; display: inline-block; margin-top: 8px; }
.lib-picker-loading { padding: 40px; text-align: center; color: var(--sub); font-size: 13px; }
.lib-picker-btn {
  padding: 4px 10px; font-size: 11.5px; border-radius: 6px;
  border: 1px solid var(--border); background: var(--panel-2);
  color: var(--text); cursor: pointer; font-family: inherit;
  transition: .15s;
}
.lib-picker-btn:hover { background: var(--overlay-strong); border-color: var(--accent); }
</style>

<div class="crumb"><a href="characters.php">← 返回角色列表</a></div>

<?php if ($errors): ?>
  <div class="flash err">
    <ul style="margin:0;padding-left:20px">
      <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="edit-form">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

  <div class="form-card">
    <h2>基本信息</h2>
    <div class="form-row">
      <div class="field">
        <label>Code（唯一标识）</label>
        <input type="text" name="code" value="<?= h($ch['code']) ?>" required
               pattern="[a-zA-Z0-9_\-]+" placeholder="例如 shuanglan">
      </div>
      <div class="field">
        <label>稀有度</label>
        <select name="rarity">
          <?php for ($i = 9; $i >= 1; $i--): ?>
            <option value="<?= $i ?>" <?= (int)$ch['rarity'] === $i ? 'selected' : '' ?>><?= $i ?>★</option>
          <?php endfor; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="field">
        <label>名字</label>
        <input type="text" name="name" value="<?= h($ch['name']) ?>" required>
      </div>
      <div class="field">
        <label>称号</label>
        <input type="text" name="title" value="<?= h($ch['title']) ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="field">
        <label>渐变色 A</label>
        <div class="color-pair">
          <input type="color" name="color_a" value="<?= h($ch['color_a']) ?>">
          <input type="text" value="<?= h($ch['color_a']) ?>" oninput="this.previousElementSibling.value=this.value" style="flex:1">
        </div>
      </div>
      <div class="field">
        <label>渐变色 B</label>
        <div class="color-pair">
          <input type="color" name="color_b" value="<?= h($ch['color_b']) ?>">
          <input type="text" value="<?= h($ch['color_b']) ?>" oninput="this.previousElementSibling.value=this.value" style="flex:1">
        </div>
      </div>
      <div class="field">
        <label>无图首字（最多 2 字）</label>
        <input type="text" name="avatar_text" value="<?= h($ch['avatar_text']) ?>" maxlength="2" placeholder="例：霜">
      </div>
    </div>
  </div>

  <div class="form-card">
    <h2>图片素材</h2>
    <p class="desc">
      每张图都可以「上传本地」「从素材库选择」或「填外部 URL」。<br>
      <span style="color:#86efac">✓ 换图不会删除旧文件，旧图仍保留在素材库可供其他角色使用。</span>
    </p>

    <?php
    $imgLabels = [
        'image_full'  => ['立绘大图', '抽卡过场使用，建议竖图'],
        'image_half'  => ['半身立绘', '结果卡片使用'],
        'image_chibi' => ['Q 版小人', '抽卡画面右下角，可留空'],
    ];
    foreach ($imgLabels as $field => $meta):
        $val = (string)$ch[$field];
    ?>
      <div class="img-row" data-field="<?= h($field) ?>">
        <div class="img-preview">
          <?php if ($val): ?>
            <img src="<?= h(upload_url($val)) ?>" alt="">
          <?php else: ?>
            <div class="preview-empty">暂无</div>
          <?php endif; ?>
        </div>
        <div class="img-controls">
          <div class="img-label">
            <strong><?= h($meta[0]) ?></strong>
            <span class="desc"><?= h($meta[1]) ?></span>
          </div>

          <div class="field">
            <label>上传本地图片</label>
            <input type="file" name="<?= h($field) ?>" accept="image/*">
          </div>

          <div class="field">
            <label style="display:flex;justify-content:space-between;align-items:center;gap:10px">
              <span>素材库路径 / 外部 URL</span>
              <button type="button" class="lib-picker-btn"
                      data-field="<?= h($field) ?>"
                      style="padding:4px 10px;font-size:11.5px;border-radius:6px;
                             border:1px solid var(--border);background:var(--panel-2);
                             color:var(--text);cursor:pointer;font-family:inherit;">
                📁 从素材库选择
              </button>
            </label>
            <input type="text" name="<?= h($field) ?>_url" class="lib-picker-input"
                   data-field="<?= h($field) ?>"
                   value="<?= h($val) ?>"
                   placeholder="uploads/library/xxx.webp 或 https://...">
          </div>

          <?php if ($val): ?>
            <label class="clear-line">
              <input type="checkbox" name="clear_<?= h($field) ?>" value="1">
              清除当前图片
              <span style="color:var(--sub);font-size:11px;margin-left:6px">
                （如果这张图还被别的角色或站点使用，文件会保留，只解除本角色的关联）
              </span>
            </label>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="form-foot">
    <a class="btn" href="characters.php">取消</a>
    <button type="submit" class="btn primary">保存</button>
  </div>
</form>

<div class="lib-picker-mask" id="lib-picker-mask">
  <div class="lib-picker">
    <div class="lib-picker-head">
      <h3>📁 从素材库选择<span class="sub" id="lib-picker-target"></span></h3>
      <button type="button" class="lib-picker-close" id="lib-picker-close">✕</button>
    </div>
    <div class="lib-picker-body">
      <input type="text" class="lib-picker-search" id="lib-picker-search"
             placeholder="🔍 搜索文件名…" autocomplete="off">
      <div class="lib-picker-chips" id="lib-picker-chips"></div>
      <div id="lib-picker-content">
        <div class="lib-picker-loading">加载中…</div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var API_PATH = 'library_api.php';
  var mask      = document.getElementById('lib-picker-mask');
  var closeBtn  = document.getElementById('lib-picker-close');
  var searchEl  = document.getElementById('lib-picker-search');
  var contentEl = document.getElementById('lib-picker-content');
  var targetEl  = document.getElementById('lib-picker-target');
  var chipsBox  = document.getElementById('lib-picker-chips');

  var currentField = '';
  var allItems = [];
  var loaded = false;
  var currentCat = 'all';
  var catCounts = {};
  var labelMap = { library:'素材库', full:'立绘大图', half:'半身立绘', chibi:'Q版', icon:'图标', bg:'背景图' };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
    });
  }

  function open(field, label) {
    currentField = field;
    targetEl.textContent = ' → ' + label;
    mask.classList.add('show');
    searchEl.value = '';
    currentCat = 'all';
    if (!loaded) loadList();
    else { renderChips(); renderList(''); }
    setTimeout(function () { searchEl.focus(); }, 100);
  }

  function close() { mask.classList.remove('show'); }

  function loadList() {
    contentEl.innerHTML = '<div class="lib-picker-loading">加载中…</div>';
    fetch(API_PATH, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        allItems = data.items || [];
        catCounts = { all: allItems.length };
        allItems.forEach(function (it) {
          catCounts[it.cat] = (catCounts[it.cat] || 0) + 1;
        });
        loaded = true;
        renderChips();
        renderList('');
      })
      .catch(function (e) {
        contentEl.innerHTML = '<div class="lib-picker-empty">加载失败：' + esc(e.message) + '</div>';
      });
  }

  function renderChips() {
    if (!chipsBox) return;
    var html = '<button type="button" class="lib-picker-chip' + (currentCat==='all'?' on':'') + '" data-cat="all">全部 (' + (catCounts.all||0) + ')</button>';
    Object.keys(labelMap).forEach(function (k) {
      if (!catCounts[k]) return;
      html += '<button type="button" class="lib-picker-chip' + (currentCat===k?' on':'') + '" data-cat="' + k + '">' + labelMap[k] + ' (' + catCounts[k] + ')</button>';
    });
    chipsBox.innerHTML = html;
  }

  function renderList(q) {
    q = (q || '').trim().toLowerCase();
    var list = allItems.filter(function (it) {
      var okText = (q === '' || it.name.toLowerCase().indexOf(q) !== -1);
      var okCat  = (currentCat === 'all' || it.cat === currentCat);
      return okText && okCat;
    });

    if (!allItems.length) {
      contentEl.innerHTML =
        '<div class="lib-picker-empty">素材库还是空的。<br>' +
        '<a href="uploads.php" target="_blank">前往素材库上传 →</a></div>';
      return;
    }
    if (!list.length) {
      contentEl.innerHTML = '<div class="lib-picker-empty">没有匹配的图片</div>';
      return;
    }

    var html = '<div class="lib-picker-grid">';
    list.forEach(function (it) {
      html +=
        '<div class="lib-picker-item" data-path="' + esc(it.path) + '" title="' + esc(it.name) + '">' +
          '<img src="' + esc(it.thumb) + '" alt="" loading="lazy">' +
          '<span class="lb-cat">' + esc(it.label || it.cat) + '</span>' +
          '<span class="lb-name">' + esc(it.name) + '</span>' +
        '</div>';
    });
    html += '</div>';
    contentEl.innerHTML = html;
  }

  document.querySelectorAll('.lib-picker-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var field = btn.getAttribute('data-field');
      var row = btn.closest('.img-row');
      var labelText = row ? row.querySelector('.img-label strong').textContent : field;
      open(field, labelText);
    });
  });

  closeBtn.addEventListener('click', close);
  mask.addEventListener('click', function (e) { if (e.target === mask) close(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && mask.classList.contains('show')) close();
  });

  searchEl.addEventListener('input', function () { renderList(this.value); });

  if (chipsBox) {
    chipsBox.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('.lib-picker-chip') : null;
      if (!btn) return;
      currentCat = btn.getAttribute('data-cat');
      chipsBox.querySelectorAll('.lib-picker-chip').forEach(function (x) { x.classList.remove('on'); });
      btn.classList.add('on');
      renderList(searchEl.value);
    });
  }

  contentEl.addEventListener('click', function (e) {
    var item = e.target.closest ? e.target.closest('.lib-picker-item') : null;
    if (!item) return;
    var path = item.getAttribute('data-path');
    var input = document.querySelector('.lib-picker-input[data-field="' + currentField + '"]');
    if (input) input.value = path;

    var row = document.querySelector('.img-row[data-field="' + currentField + '"]');
    if (row) {
      var preview = row.querySelector('.img-preview');
      preview.innerHTML = '<img src="../' + esc(path) + '" alt="">';
    }

    close();
  });
})();
</script>

<?php require __DIR__ . '/_footer.php'; ?>