<?php
$pageTitle  = '编辑卡池';
$pageActive = 'pools';
require __DIR__ . '/_header.php';
require_once __DIR__ . '/../lib/upload.php';

$pdo = gacha_pdo();
$id  = (int)q('id', 0);
$isNew = $id === 0;
$errors = [];

/* ===== 字符清洗 ===== */
function v12_clean($s) {
    $s = is_scalar($s) ? (string)$s : '';
    if ($s === '') return '';
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
    if (function_exists('mb_convert_encoding')) {
        $fixed = @mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        if ($fixed !== false) $s = $fixed;
    }
    return $s;
}
function v12_h($s): string {
    return htmlspecialchars(v12_clean($s), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function v12_initial($text, $fb = '?'): string {
    $text = v12_clean($text);
    if ($text === '') return $fb;
    if (function_exists('mb_substr')) return mb_substr($text, 0, 1, 'UTF-8');
    if (preg_match('/^./u', $text, $m)) return $m[0];
    return substr($text, 0, 1);
}
function v12_img_url($p) {
    $p = v12_clean($p);
    if ($p === '') return '';
    if (stripos($p, 'http://') === 0 || stripos($p, 'https://') === 0) return $p;
    if (substr($p, 0, 1) === '/') return $p;
    if (strpos($p, 'uploads/') === 0) return '../' . $p;
    return $p;
}

/* ===== 载入 ===== */
$pool = [
    'code' => '', 'name' => '', 'subtitle' => '', 'description' => '',
    'color_a' => '#5b8def', 'color_b' => '#c084fc',
    'is_active' => 1, 'sort_order' => 0,
];
if (!$isNew) {
    $stmt = $pdo->prepare('SELECT * FROM pools WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) redirect('pools.php');
    $pool = array_merge($pool, $row);
}

$allChars = $pdo->query('SELECT * FROM characters ORDER BY rarity DESC, name')->fetchAll();

$starCount = [];
for ($i = 1; $i <= 9; $i++) $starCount[$i] = 0;
foreach ($allChars as $c) {
    $r = (int)$c['rarity'];
    if ($r >= 1 && $r <= 9) $starCount[$r]++;
}

$linked = [];
if (!$isNew) {
    $stmt = $pdo->prepare('SELECT character_id, weight, is_up FROM pool_characters WHERE pool_id = ?');
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll() as $r) {
        $linked[(int)$r['character_id']] = [
            'weight' => (float)$r['weight'],
            'is_up'  => (int)$r['is_up'],
        ];
    }
}

/* ===== 保存 ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $pool['code']        = trim((string)post('code'));
    $pool['name']        = trim((string)post('name'));
    $pool['subtitle']    = trim((string)post('subtitle'));
    $pool['description'] = trim((string)post('description'));
    $pool['color_a']     = trim((string)post('color_a', '#5b8def'));
    $pool['color_b']     = trim((string)post('color_b', '#c084fc'));
    $pool['is_active']   = !empty($_POST['is_active']) ? 1 : 0;
    $pool['sort_order']  = (int)post('sort_order', 0);

    if ($pool['code'] === '') $errors[] = 'code 不能为空';
    if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $pool['code'])) $errors[] = 'code 格式错误';
    if ($pool['name'] === '') $errors[] = '名称不能为空';

    if (empty($errors)) {
        $stmt = $pdo->prepare('SELECT id FROM pools WHERE code = ? AND id != ?');
        $stmt->execute([$pool['code'], $id]);
        if ($stmt->fetch()) $errors[] = 'code 已被使用';
    }

    $newLinked = [];
    foreach ($_POST['char_ids'] ?? [] as $cid) {
        $cid = (int)$cid;
        if ($cid <= 0) continue;
        $w  = (float)($_POST['weight'][$cid] ?? 1.0);
        $up = !empty($_POST['is_up'][$cid]) ? 1 : 0;
        if ($w <= 0) $w = 1.0;
        $newLinked[$cid] = ['weight' => $w, 'is_up' => $up];
    }

    if (empty($errors)) {
        $pdo->beginTransaction();
        try {
            if ($isNew) {
                $stmt = $pdo->prepare('INSERT INTO pools (code, name, subtitle, description, color_a, color_b, is_active, sort_order, created_at)
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$pool['code'], $pool['name'], $pool['subtitle'], $pool['description'],
                    $pool['color_a'], $pool['color_b'], $pool['is_active'], $pool['sort_order'], time()]);
                $id = (int)$pdo->lastInsertId();
            } else {
                $stmt = $pdo->prepare('UPDATE pools SET code=?, name=?, subtitle=?, description=?, color_a=?, color_b=?, is_active=?, sort_order=? WHERE id=?');
                $stmt->execute([$pool['code'], $pool['name'], $pool['subtitle'], $pool['description'],
                    $pool['color_a'], $pool['color_b'], $pool['is_active'], $pool['sort_order'], $id]);
            }
            $pdo->prepare('DELETE FROM pool_characters WHERE pool_id = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO pool_characters (pool_id, character_id, weight, is_up) VALUES (?, ?, ?, ?)');
            foreach ($newLinked as $cid => $d) {
                $ins->execute([$id, $cid, $d['weight'], $d['is_up']]);
            }
            $pdo->commit();
            redirect('pools.php?msg=saved');
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = '保存失败：' . $e->getMessage();
        }
    }

    $linked = $newLinked;
    $isNew  = false;
}

/* ===== 预格式化 ===== */
$rowsData = [];
foreach ($allChars as $c) {
    $cid  = (int)$c['id'];
    $isOn = isset($linked[$cid]);

    // 卡片优先用半身图，其次 Q 版，最后大图
    $thumb = '';
    foreach (['image_half', 'image_chibi', 'image_full'] as $k) {
        $v = v12_clean($c[$k] ?? '');
        if ($v !== '') { $thumb = $v; break; }
    }

    $rowsData[] = [
        'id'          => $cid,
        'code'        => v12_clean($c['code']        ?? ''),
        'name'        => v12_clean($c['name']        ?? ''),
        'title'       => v12_clean($c['title']       ?? ''),
        'rarity'      => (int)($c['rarity'] ?? 1),
        'color_a'     => v12_clean($c['color_a']     ?? '#666666'),
        'color_b'     => v12_clean($c['color_b']     ?? '#333333'),
        'avatar_text' => v12_clean($c['avatar_text'] ?? ''),
        'thumb_url'   => v12_img_url($thumb),
        'is_on'       => $isOn,
        'weight'      => $isOn ? (float)$linked[$cid]['weight'] : 1.0,
        'is_up'       => $isOn ? (int)$linked[$cid]['is_up'] : 0,
    ];
}
?>
<style>
.v12-badge {
  display: inline-block; padding: 5px 14px; margin-bottom: 16px;
  border-radius: 20px; font-size: 12px; font-weight: 700;
  background: linear-gradient(120deg, #3b82f6, #06b6d4);
  color: #fff; letter-spacing: 1px;
}
.v12-card {
  background: var(--panel); border: 1px solid var(--border);
  border-radius: 14px; padding: 24px; margin-bottom: 20px;
  transition: background .25s, border-color .25s;
}
.v12-card h2 { margin: 0 0 16px; font-size: 16px; color: var(--text); font-weight: 700; }
.v12-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 12px; }
.v12-field { display: flex; flex-direction: column; }
.v12-field label { font-size: 12px; color: var(--sub); margin-bottom: 6px; font-weight: 600; }
.v12-field input[type=text], .v12-field input[type=number], .v12-field textarea {
  width: 100%; padding: 10px 13px; border-radius: 10px;
  border: 1px solid var(--border); background: var(--input-bg); color: var(--text);
  font-size: 13.5px; font-family: inherit; outline: none; box-sizing: border-box;
  transition: background .25s, border-color .25s;
}
.v12-field input:focus, .v12-field textarea:focus { border-color: var(--accent); }
.v12-check { display: flex; align-items: center; gap: 8px; font-size: 13.5px; color: var(--text); padding: 10px 0; }

/* 筛选栏 */
.v12-toolbar { display: flex; flex-direction: column; gap: 12px; margin-bottom: 18px; }
.v12-search-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.v12-search {
  flex: 1; min-width: 200px; padding: 11px 16px;
  border-radius: 10px; border: 1px solid var(--border);
  background: var(--input-bg); color: var(--text); font-size: 13.5px;
  font-family: inherit; outline: none;
  transition: background .25s, border-color .25s;
}
.v12-search:focus { border-color: var(--accent); }

.v12-filter-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.v12-filter-label { color: var(--sub); font-size: 12px; font-weight: 600; min-width: 32px; }
.v12-btn-group { display: flex; gap: 6px; flex-wrap: wrap; }
.v12-fbtn {
  padding: 6px 14px; border-radius: 20px; font-size: 12.5px;
  border: 1px solid var(--border); background: var(--panel-2); color: var(--sub);
  cursor: pointer; user-select: none; font-family: inherit;
  transition: all .15s;
}
.v12-fbtn:hover { color: var(--text); }
.v12-fbtn.on {
  background: linear-gradient(120deg, var(--accent), var(--accent-2));
  color: #fff; border-color: transparent; font-weight: 700;
}
.v12-fbtn[data-sel="on"].on { background: linear-gradient(120deg, #22c55e, #10b981); }
.v12-fbtn[data-sel="off"].on { background: linear-gradient(120deg, #f59e0b, #ef4444); }

/* 卡片网格 */
.v12-cards {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
  gap: 14px;
}
.v12-pcard {
  position: relative; border-radius: 14px;
  background: var(--panel); border: 2px solid var(--border);
  overflow: hidden; cursor: pointer;
  transition: transform .15s, border-color .2s, box-shadow .2s, background .25s;
  display: flex; flex-direction: column;
}
.v12-pcard:hover {
  transform: translateY(-3px);
  border-color: var(--accent);
  box-shadow: var(--shadow-md);
}
.v12-pcard.selected {
  border-color: var(--accent);
  box-shadow: 0 0 0 1px var(--accent), 0 10px 30px rgba(122,77,219,.35);
}
.v12-pcard.selected:hover {
  box-shadow: 0 0 0 1px var(--accent), 0 16px 40px rgba(122,77,219,.5);
}

.v12-pimg {
  position: relative; aspect-ratio: 3 / 4;
  background: var(--input-bg); overflow: hidden;
}
.v12-pimg img { width: 100%; height: 100%; object-fit: cover; display: block; }
.v12-pfb {
  width: 100%; height: 100%;
  display: flex; align-items: center; justify-content: center;
  font-size: 56px; font-weight: 800; color: #fff;
  text-shadow: 0 4px 16px rgba(0,0,0,.55);
  font-family: "STKaiti", "KaiTi", "PingFang SC", sans-serif;
}

.v12-pstar {
  position: absolute; top: 8px; left: 8px;
  padding: 3px 9px; border-radius: 6px;
  font-size: 11px; font-weight: 800; color: #fff;
  backdrop-filter: blur(4px);
}
.v12-pstar.st1 { background: #CFCFCF; color: #333; }
.v12-pstar.st2 { background: #616161; }
.v12-pstar.st3 { background: #4ade80; color: #052e16; }
.v12-pstar.st4 { background: #3b82f6; }
.v12-pstar.st5 { background: #b28dff; }
.v12-pstar.st6 { background: linear-gradient(120deg,#ffd166,#ff9a3c); color: #4a2b00; }
.v12-pstar.st7 { background: linear-gradient(120deg,#ff9a3c,#d63384); }
.v12-pstar.st8 { background: linear-gradient(120deg,#d63384,#7a4ddb); }
.v12-pstar.st9 { background: linear-gradient(120deg,#ffd166,#d63384,#7a4ddb,#5b8def); }

.v12-pup {
  position: absolute; top: 8px; right: 8px;
  padding: 3px 9px; border-radius: 6px;
  font-size: 10.5px; font-weight: 800;
  background: linear-gradient(120deg, #ffd166, #ff9a3c);
  color: #4a2b00; letter-spacing: 1px;
  display: none;
}
.v12-pcard.is-up .v12-pup { display: block; }

.v12-pcheck {
  position: absolute; top: 8px; right: 8px;
  width: 26px; height: 26px; border-radius: 50%;
  background: var(--accent); color: #fff;
  font-size: 15px; font-weight: 800;
  display: flex; align-items: center; justify-content: center;
  opacity: 0; transform: scale(.5);
  transition: opacity .2s, transform .2s;
  box-shadow: 0 4px 12px rgba(122,77,219,.5);
  z-index: 2;
}
.v12-pcard.selected .v12-pcheck { opacity: 1; transform: scale(1); }
.v12-pcard.is-up .v12-pcheck { right: 60px; }

.v12-pbody { padding: 10px 12px 6px; flex: 1; }
.v12-pname {
  font-size: 14.5px; font-weight: 700; color: var(--text);
  line-height: 1.2; margin-bottom: 2px;
}
.v12-ptitle {
  font-size: 11.5px; color: var(--sub);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

.v12-pfoot {
  padding: 8px 12px 12px;
  display: flex; gap: 8px; align-items: center;
  border-top: 1px solid var(--border);
}
.v12-pfoot label { display: flex; align-items: center; gap: 5px; font-size: 11px; color: var(--sub); }
.v12-pweight {
  width: 56px; padding: 4px 6px; border-radius: 6px;
  border: 1px solid var(--border); background: var(--input-bg);
  color: var(--text); font-size: 12px; font-family: inherit;
  text-align: center; outline: none;
}
.v12-pweight:focus { border-color: var(--accent); }
.v12-pup-cb { width: 16px; height: 16px; cursor: pointer; margin: 0; }

.v12-pcb { position: absolute; left: -9999px; top: -9999px; }

.v12-hint {
  color: var(--sub); font-size: 12.5px; margin-top: 14px;
  display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px;
}
.v12-hint .cnt { color: var(--gold); font-weight: 700; }

.v12-foot {
  display: flex; gap: 10px; justify-content: flex-end;
  padding: 24px 0 8px; position: sticky; bottom: 0;
  background: linear-gradient(to top, var(--bg) 60%, transparent);
  margin-top: 10px;
}
.v12-btn {
  display: inline-block; padding: 12px 26px; border-radius: 10px;
  font-size: 14px; text-decoration: none; cursor: pointer;
  border: 1px solid var(--border); background: var(--panel-2); color: var(--text);
  font-family: inherit;
}
.v12-btn:hover { background: var(--overlay-strong); }
.v12-btn.primary { background: linear-gradient(120deg, var(--accent), var(--accent-2)); border: none; color: #fff; font-weight: 700; }

.v12-error {
  padding: 12px 16px; margin-bottom: 18px; border-radius: 10px;
  background: var(--danger-bg); color: var(--danger); border: 1px solid var(--danger-border); font-size: 13px;
}

.v12-empty {
  padding: 60px 20px; text-align: center; color: var(--sub);
  border: 1px dashed var(--border); border-radius: 14px;
  font-size: 13.5px;
}
.v12-empty a { color: var(--accent); }

@media (max-width: 720px) {
  .v12-cards { grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 10px; }
  .v12-pfb { font-size: 40px; }
  .v12-pname { font-size: 13px; }
  .v12-ptitle { font-size: 10.5px; }
  .v12-filter-label { min-width: 100%; }
}
</style>

<div class="v12-badge">pool_edit.php · v12（卡片式）</div>

<?php if ($errors): ?>
  <div class="v12-error">
    <?php foreach ($errors as $e): ?>· <?= v12_h($e) ?><br><?php endforeach; ?>
  </div>
<?php endif; ?>

<form method="post" id="pool-form">
  <input type="hidden" name="_csrf" value="<?= v12_h(csrf_token()) ?>">

  <div class="v12-card">
    <h2>基本信息</h2>
    <div class="v12-grid">
      <div class="v12-field">
        <label>Code（唯一标识）</label>
        <input type="text" name="code" value="<?= v12_h($pool['code']) ?>" placeholder="例如 frost">
      </div>
      <div class="v12-field">
        <label>名称</label>
        <input type="text" name="name" value="<?= v12_h($pool['name']) ?>">
      </div>
      <div class="v12-field">
        <label>排序</label>
        <input type="number" name="sort_order" value="<?= (int)$pool['sort_order'] ?>">
      </div>
    </div>
    <div class="v12-field" style="margin-bottom:12px">
      <label>副标题</label>
      <input type="text" name="subtitle" value="<?= v12_h($pool['subtitle']) ?>">
    </div>
    <div class="v12-field" style="margin-bottom:12px">
      <label>介绍语</label>
      <textarea name="description"><?= v12_h($pool['description']) ?></textarea>
    </div>
    <div class="v12-grid">
      <div class="v12-field">
        <label>横幅配色 A</label>
        <input type="text" name="color_a" value="<?= v12_h($pool['color_a']) ?>">
      </div>
      <div class="v12-field">
        <label>横幅配色 B</label>
        <input type="text" name="color_b" value="<?= v12_h($pool['color_b']) ?>">
      </div>
      <div class="v12-field">
        <label>上架状态</label>
        <div class="v12-check">
          <input type="checkbox" name="is_active" value="1" <?= $pool['is_active'] ? 'checked' : '' ?>>
          <span>在前台显示</span>
        </div>
      </div>
    </div>
  </div>

  <div class="v12-card">
    <h2>关联角色</h2>

    <?php if (empty($rowsData)): ?>
      <div class="v12-empty">
        还没有角色。<a href="character_edit.php">去创建角色 →</a>
      </div>
    <?php else: ?>

      <div class="v12-toolbar">
        <div class="v12-search-row">
          <input type="text" id="v12-search" class="v12-search"
                 placeholder="🔍 搜索角色名字、code 或称号…" autocomplete="off">
        </div>
        <div class="v12-filter-row">
          <span class="v12-filter-label">状态</span>
          <div class="v12-btn-group" id="v12-sel-filter">
            <button type="button" class="v12-fbtn on" data-sel="all">全部</button>
            <button type="button" class="v12-fbtn" data-sel="on">已选 (<span class="cnt-on">0</span>)</button>
            <button type="button" class="v12-fbtn" data-sel="off">未选 (<span class="cnt-off">0</span>)</button>
          </div>
        </div>
        <div class="v12-filter-row">
          <span class="v12-filter-label">星级</span>
          <div class="v12-btn-group" id="v12-star-filter">
            <button type="button" class="v12-fbtn on" data-star="all">全部</button>
            <?php for ($i = 9; $i >= 1; $i--): ?>
              <button type="button" class="v12-fbtn" data-star="<?= $i ?>"><?= $i ?>★ (<?= $starCount[$i] ?>)</button>
            <?php endfor; ?>
          </div>
        </div>
      </div>

      <div class="v12-cards" id="v12-cards">
        <?php foreach ($rowsData as $d): ?>
          <div class="v12-pcard <?= $d['is_on'] ? 'selected' : '' ?> <?= $d['is_up'] ? 'is-up' : '' ?>"
               data-id="<?= $d['id'] ?>"
               data-star="<?= $d['rarity'] ?>"
               data-selected="<?= $d['is_on'] ? '1' : '0' ?>"
               data-search="<?= v12_h(strtolower($d['name'] . ' ' . $d['code'] . ' ' . $d['title'])) ?>">

            <input type="checkbox" name="char_ids[]" value="<?= $d['id'] ?>"
                   class="v12-pcb" <?= $d['is_on'] ? 'checked' : '' ?>>

            <div class="v12-pimg">
              <?php if ($d['thumb_url'] !== ''): ?>
                <img src="<?= v12_h($d['thumb_url']) ?>" alt="">
              <?php else: ?>
                <div class="v12-pfb" style="background:linear-gradient(140deg,<?= v12_h($d['color_a']) ?>,<?= v12_h($d['color_b']) ?>)">
                  <?= v12_h($d['avatar_text'] !== '' ? $d['avatar_text'] : v12_initial($d['name'])) ?>
                </div>
              <?php endif; ?>
              <span class="v12-pstar st<?= $d['rarity'] ?>"><?= $d['rarity'] ?>★</span>
              <span class="v12-pup">UP</span>
              <span class="v12-pcheck">✓</span>
            </div>

            <div class="v12-pbody">
              <div class="v12-pname"><?= v12_h($d['name']) ?></div>
              <div class="v12-ptitle"><?= v12_h($d['title'] !== '' ? $d['title'] : $d['code']) ?></div>
            </div>

            <div class="v12-pfoot">
              <label>权重
                <input type="number" step="0.1" min="0.1"
                       name="weight[<?= $d['id'] ?>]"
                       value="<?= v12_h($d['weight']) ?>"
                       class="v12-pweight">
              </label>
              <label style="margin-left:auto">
                <input type="checkbox" name="is_up[<?= $d['id'] ?>]" value="1"
                       class="v12-pup-cb" <?= $d['is_up'] ? 'checked' : '' ?>>
                UP
              </label>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="v12-hint">
        <span>显示 <span class="cnt" id="v12-visible-count">0</span> / <span class="cnt"><?= count($rowsData) ?></span> 个角色</span>
        <span>当前已选 <span class="cnt" id="v12-selected-count">0</span> 个</span>
      </div>

    <?php endif; ?>
  </div>

  <div class="v12-foot">
    <a class="v12-btn" href="pools.php">取消</a>
    <button type="submit" class="v12-btn primary">保存卡池</button>
  </div>
</form>

<script>
(function () {
  var grid = document.getElementById('v12-cards');
  if (!grid) return;

  var cards = Array.prototype.slice.call(grid.querySelectorAll('.v12-pcard'));
  var searchInput = document.getElementById('v12-search');
  var selFilter   = document.getElementById('v12-sel-filter');
  var starFilter  = document.getElementById('v12-star-filter');
  var visibleCnt  = document.getElementById('v12-visible-count');
  var selCntEl    = document.getElementById('v12-selected-count');
  var onCountEl   = document.querySelector('.cnt-on');
  var offCountEl  = document.querySelector('.cnt-off');

  var filters = { text: '', star: 'all', sel: 'all' };

  function updateCounts() {
    var on = 0;
    for (var i = 0; i < cards.length; i++) {
      if (cards[i].getAttribute('data-selected') === '1') on++;
    }
    var off = cards.length - on;
    if (onCountEl)  onCountEl.textContent = on;
    if (offCountEl) offCountEl.textContent = off;
    if (selCntEl)   selCntEl.textContent = on;
  }

  function apply() {
    var q = filters.text.toLowerCase();
    var visible = 0;
    for (var i = 0; i < cards.length; i++) {
      var card = cards[i];
      var key  = (card.getAttribute('data-search') || '').toLowerCase();
      var star = card.getAttribute('data-star') || '';
      var isOn = card.getAttribute('data-selected') === '1';

      var okText = (q === '' || key.indexOf(q) !== -1);
      var okStar = (filters.star === 'all' || star === filters.star);
      var okSel  = (filters.sel === 'all'
                    || (filters.sel === 'on' && isOn)
                    || (filters.sel === 'off' && !isOn));

      if (okText && okStar && okSel) {
        card.style.display = '';
        visible++;
      } else {
        card.style.display = 'none';
      }
    }
    if (visibleCnt) visibleCnt.textContent = visible;
  }

  function setActive(group, activeBtn) {
    if (!group) return;
    var all = group.querySelectorAll('.v12-fbtn');
    for (var i = 0; i < all.length; i++) all[i].classList.remove('on');
    activeBtn.classList.add('on');
  }

  if (searchInput) {
    searchInput.addEventListener('input', function () {
      filters.text = this.value;
      apply();
    });
  }

  if (selFilter) {
    selFilter.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('.v12-fbtn') : null;
      if (!btn) return;
      setActive(selFilter, btn);
      filters.sel = btn.getAttribute('data-sel');
      apply();
    });
  }

  if (starFilter) {
    starFilter.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('.v12-fbtn') : null;
      if (!btn) return;
      setActive(starFilter, btn);
      filters.star = btn.getAttribute('data-star');
      apply();
    });
  }

  function syncCard(card) {
    var cb = card.querySelector('.v12-pcb');
    var upCb = card.querySelector('.v12-pup-cb');
    if (!cb) return;
    var isOn = cb.checked;
    card.setAttribute('data-selected', isOn ? '1' : '0');
    card.classList.toggle('selected', isOn);
    card.classList.toggle('is-up', !!(upCb && upCb.checked));
  }

  // 点击卡片（除交互元素外）→ 切换选中
  grid.addEventListener('click', function (e) {
    // 点在权重输入框 / UP 复选框 / 复选框本身 → 不切换
    if (e.target.closest('.v12-pfoot')) return;
    if (e.target.closest('.v12-pcb')) return;

    var card = e.target.closest('.v12-pcard');
    if (!card) return;
    var cb = card.querySelector('.v12-pcb');
    if (!cb) return;

    cb.checked = !cb.checked;
    syncCard(card);
    updateCounts();
    apply();
  });

  // UP 复选框变化 → 更新卡片角标
  grid.addEventListener('change', function (e) {
    var card = e.target.closest('.v12-pcard');
    if (!card) return;
    if (e.target.classList.contains('v12-pup-cb')) {
      syncCard(card);
    }
  });

  updateCounts();
  apply();
})();
</script>

<?php require __DIR__ . '/_footer.php'; ?>