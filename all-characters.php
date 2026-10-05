<?php
require_once __DIR__ . '/lib/db.php';

if (!gacha_is_installed()) {
    header('Location: install.php');
    exit;
}

require_once __DIR__ . '/lib/settings.php';

$pdo = gacha_pdo();

$siteTitle = setting('site_title', '星海抽卡');
$favicon   = setting('favicon_url', '');
$bgUrl     = setting('background_url', '');
$customCss = setting('custom_css', '');

/* 图片 URL 归一：和前台使用同一套逻辑 */
function ac_url(string $p): string {
    $p = trim($p);
    if ($p === '') return '';
    if (preg_match('#^https?://#i', $p)) return $p;
    if (strpos($p, '//') === 0) return $p;
    if ($p[0] === '/') return $p;
    return ltrim($p, '/');
}

/* 全部角色 */
$chars = $pdo->query('SELECT * FROM characters ORDER BY rarity DESC, name')->fetchAll();

/* 每个角色所属卡池 */
$poolRefs = [];
$stmt = $pdo->query('
    SELECT pc.character_id, pc.is_up, p.name, p.is_active
    FROM pool_characters pc
    JOIN pools p ON p.id = pc.pool_id
    ORDER BY p.sort_order, p.id
');
foreach ($stmt->fetchAll() as $r) {
    $cid = (int)$r['character_id'];
    if (!isset($poolRefs[$cid])) $poolRefs[$cid] = [];
    $poolRefs[$cid][] = [
        'name'   => (string)$r['name'],
        'active' => (int)$r['is_active'] === 1,
        'is_up'  => (int)$r['is_up'] === 1,
    ];
}

/* 星级统计 */
$starCount = [];
for ($i = 1; $i <= 9; $i++) $starCount[$i] = 0;
foreach ($chars as $c) {
    $r = (int)$c['rarity'];
    if ($r >= 1 && $r <= 9) $starCount[$r]++;
}

/* 打包给 JS */
$charsData = [];
foreach ($chars as $c) {
    $cid = (int)$c['id'];
    $charsData[] = [
        'id'          => $cid,
        'code'        => (string)$c['code'],
        'name'        => (string)$c['name'],
        'title'       => (string)$c['title'],
        'rarity'      => (int)$c['rarity'],
        'avatar_text' => (string)$c['avatar_text'],
        'color_a'     => (string)$c['color_a'],
        'color_b'     => (string)$c['color_b'],
        'img_chibi'   => ac_url((string)$c['image_chibi']),
        'img_half'    => ac_url((string)$c['image_half']),
        'img_full'    => ac_url((string)$c['image_full']),
        'pools'       => $poolRefs[$cid] ?? [],
    ];
}

$totalCount = count($charsData);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>角色图鉴 · <?= htmlspecialchars($siteTitle, ENT_QUOTES) ?></title>
<?php if ($favicon !== ''): ?>
<link rel="icon" href="<?= htmlspecialchars(ac_url($favicon), ENT_QUOTES) ?>">
<?php endif; ?>
<link rel="stylesheet" href="assets/front.css?v=1">
<?php if ($bgUrl !== ''): ?>
<style>
body::before { background-image: url('<?= htmlspecialchars(ac_url($bgUrl), ENT_QUOTES) ?>'); }
</style>
<?php endif; ?>
<?php if ($customCss !== ''): ?>
<style><?= $customCss ?></style>
<?php endif; ?>
<style>
/* ================= 全角色预览专属样式 ================= */
.ac-page {
  max-width: 1180px;
  margin: 0 auto;
  padding: 32px 20px 80px;
}

/* 顶栏 */
.ac-top {
  display: flex; align-items: center; justify-content: space-between;
  gap: 16px; flex-wrap: wrap;
  margin-bottom: 26px;
}
.ac-title-wrap { display: flex; align-items: baseline; gap: 14px; flex-wrap: wrap; }
.ac-title {
  margin: 0;
  font-size: 26px; font-weight: 800; letter-spacing: 1px;
  background: linear-gradient(120deg, #ffd166, #b28dff, #7fc4ff);
  -webkit-background-clip: text; background-clip: text; color: transparent;
}
.ac-subtitle {
  font-size: 12px; letter-spacing: 3px;
  color: rgba(233,230,245,.5);
}
.ac-back {
  padding: 9px 18px; border-radius: 22px;
  border: 1px solid rgba(255,255,255,.14);
  background: rgba(255,255,255,.05);
  color: rgba(233,230,245,.85); text-decoration: none;
  font-size: 13px;
  transition: all .2s;
}
.ac-back:hover {
  background: rgba(255,255,255,.1);
  color: #fff;
  border-color: rgba(255,255,255,.24);
}

/* 筛选栏 */
.ac-toolbar {
  background: rgba(255,255,255,.04);
  border: 1px solid rgba(255,255,255,.1);
  border-radius: 16px;
  padding: 18px 22px;
  margin-bottom: 24px;
  display: flex; flex-direction: column; gap: 14px;
}
.ac-search {
  width: 100%;
  padding: 12px 18px;
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(10,10,20,.6);
  color: #e9e6f5;
  font-size: 14px;
  font-family: inherit;
  outline: none;
  transition: border-color .2s;
}
.ac-search:focus { border-color: #b28dff; }
.ac-search::placeholder { color: rgba(233,230,245,.35); }

.ac-filter-row {
  display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
}
.ac-filter-label {
  color: rgba(233,230,245,.5);
  font-size: 12px; font-weight: 600;
  letter-spacing: .5px;
  min-width: 36px;
}
.ac-chips { display: flex; gap: 6px; flex-wrap: wrap; }
.ac-chip {
  padding: 7px 14px; border-radius: 20px;
  border: 1px solid rgba(255,255,255,.12);
  background: rgba(255,255,255,.04);
  color: rgba(233,230,245,.6);
  font-size: 12.5px;
  cursor: pointer; user-select: none;
  font-family: inherit;
  transition: .15s;
}
.ac-chip:hover { color: #e9e6f5; background: rgba(255,255,255,.08); }
.ac-chip.on {
  background: linear-gradient(120deg, rgba(178,141,255,.35), rgba(255,154,60,.25));
  color: #fff;
  border-color: #b28dff;
  font-weight: 700;
}
.ac-chip[data-star="1"].on,
.ac-chip[data-star="2"].on { background: linear-gradient(120deg, #cfcfcf, #8a8a8a); border-color: #cfcfcf; color: #1a1a1a; }
.ac-chip[data-star="3"].on { background: linear-gradient(120deg, #4ade80, #16a34a); border-color: #4ade80; color: #052e16; }
.ac-chip[data-star="4"].on { background: linear-gradient(120deg, #3b82f6, #1e40af); border-color: #3b82f6; }
.ac-chip[data-star="5"].on { background: linear-gradient(120deg, #b28dff, #7a4ddb); border-color: #b28dff; }
.ac-chip[data-star="6"].on,
.ac-chip[data-star="7"].on,
.ac-chip[data-star="8"].on,
.ac-chip[data-star="9"].on { background: linear-gradient(120deg, #ffd166, #ff9a3c); border-color: #ffd166; color: #4a2b00; }

.ac-stats {
  margin-left: auto;
  font-size: 12.5px;
  color: rgba(233,230,245,.55);
}
.ac-stats strong { color: #ffd166; font-weight: 700; }

/* 角色网格 */
.ac-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 14px;
}

.ac-card {
  position: relative;
  border-radius: 14px;
  background: rgba(255,255,255,.04);
  border: 2px solid rgba(255,255,255,.1);
  overflow: hidden;
  cursor: pointer;
  display: flex; flex-direction: column;
  transition: transform .18s, border-color .2s, box-shadow .25s;
}
.ac-card:hover {
  transform: translateY(-4px);
  border-color: rgba(178,141,255,.6);
  box-shadow: 0 12px 30px rgba(0,0,0,.4);
}

/* 稀有度边框 */
.ac-card.r1  { border-color: #CFCFCF; }
.ac-card.r2  { border-color: #616161; }
.ac-card.r3  { border-color: #4ade80; }
.ac-card.r4  { border-color: #3b82f6; box-shadow: 0 0 0 1px rgba(59,130,246,.25); }
.ac-card.r5  { border-color: #b28dff; box-shadow: 0 0 0 1px rgba(178,141,255,.28), 0 6px 20px rgba(178,141,255,.2); }
.ac-card.r6  { border-color: #ffd166; box-shadow: 0 0 0 1px rgba(255,209,102,.35), 0 8px 26px rgba(255,209,102,.25); }
.ac-card.r7  { border-color: #ff9a3c; box-shadow: 0 0 0 1px rgba(255,154,60,.4), 0 8px 28px rgba(255,154,60,.3); }
.ac-card.r8  {
  border-color: transparent;
  background:
    linear-gradient(rgba(24,24,36,.94), rgba(24,24,36,.94)) padding-box,
    linear-gradient(120deg, #d63384, #7a4ddb, #5b8def) border-box;
  border: 2px solid transparent;
  box-shadow: 0 0 0 1px rgba(214,51,132,.35), 0 10px 30px rgba(214,51,132,.3);
}
.ac-card.r9 {
  border: 2px solid transparent;
  background:
    linear-gradient(rgba(24,24,36,.94), rgba(24,24,36,.94)) padding-box,
    linear-gradient(120deg, #ffd166, #d63384, #7a4ddb, #5b8def, #ffd166) border-box;
  background-size: 100% 100%, 300% 100%;
  animation: acHolo 4s linear infinite;
  box-shadow: 0 0 0 1px rgba(255,209,102,.5), 0 12px 34px rgba(214,51,132,.4);
}
@keyframes acHolo {
  0%   { background-position: 0 0, 0 0; }
  100% { background-position: 0 0, 300% 0; }
}

/* 卡片图片 */
.ac-thumb {
  position: relative;
  aspect-ratio: 1 / 1;
  overflow: hidden;
  background: rgba(13,13,22,.9);
  display: flex; align-items: center; justify-content: center;
}
.ac-thumb img {
  width: 100%; height: 100%;
  object-fit: contain;
  display: block;
  transition: transform .25s;
}
.ac-card:hover .ac-thumb img { transform: scale(1.06); }
.ac-thumb-fb {
  width: 100%; height: 100%;
  display: flex; align-items: center; justify-content: center;
  font-size: 52px; font-weight: 900;
  color: rgba(255,255,255,.92);
  text-shadow: 0 4px 18px rgba(0,0,0,.6);
  font-family: "STKaiti", "KaiTi", "PingFang SC", serif;
  letter-spacing: -3px;
}

/* 星级 / UP / 未入池 标签 */
.ac-badge {
  position: absolute;
  padding: 2px 8px;
  border-radius: 5px;
  font-size: 10.5px;
  font-weight: 800;
  backdrop-filter: blur(4px);
  z-index: 2;
}
.ac-badge-star { top: 6px; left: 6px; color: #fff; background: rgba(0,0,0,.7); }
.ac-badge-star.s1 { background: #CFCFCF; color: #333; }
.ac-badge-star.s2 { background: #616161; }
.ac-badge-star.s3 { background: #4ade80; color: #052e16; }
.ac-badge-star.s4 { background: #3b82f6; }
.ac-badge-star.s5 { background: #b28dff; }
.ac-badge-star.s6 { background: linear-gradient(120deg,#ffd166,#ff9a3c); color: #4a2b00; }
.ac-badge-star.s7 { background: linear-gradient(120deg,#ff9a3c,#d63384); }
.ac-badge-star.s8 { background: linear-gradient(120deg,#d63384,#7a4ddb); }
.ac-badge-star.s9 { background: linear-gradient(120deg,#ffd166,#d63384,#7a4ddb,#5b8def); }

.ac-badge-up {
  top: 6px; right: 6px;
  background: linear-gradient(120deg, #ffd166, #ff9a3c);
  color: #4a2b00;
  letter-spacing: 1px;
}
.ac-badge-nopool {
  bottom: 6px; left: 6px;
  background: rgba(0,0,0,.72);
  color: rgba(255,255,255,.6);
  font-weight: 600;
}

/* 名字条 */
.ac-body {
  padding: 10px 12px;
  text-align: center;
}
.ac-name {
  font-size: 14px; font-weight: 700; color: #e9e6f5;
  line-height: 1.2;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.ac-code {
  font-size: 10.5px; color: rgba(233,230,245,.4);
  font-family: ui-monospace, Menlo, Consolas, monospace;
  margin-top: 3px;
}

/* 空态 */
.ac-empty {
  grid-column: 1 / -1;
  padding: 60px 20px; text-align: center;
  border: 1px dashed rgba(255,255,255,.12);
  border-radius: 16px;
  color: rgba(233,230,245,.5);
  font-size: 14px;
}

/* ================= 详情弹窗 ================= */
.ac-modal {
  position: fixed; inset: 0; z-index: 999;
  background: rgba(0,0,0,.72);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  display: none;
  align-items: center; justify-content: center;
  padding: 24px;
  cursor: pointer;
}
.ac-modal.show { display: flex; animation: acModalIn .25s ease-out; }
@keyframes acModalIn {
  from { opacity: 0; }
  to   { opacity: 1; }
}

.ac-modal-box {
  width: 100%; max-width: 900px; max-height: 90vh;
  background: linear-gradient(160deg, #1a1a26, #14141c);
  border: 1px solid rgba(255,255,255,.12);
  border-radius: 20px;
  overflow: hidden;
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  cursor: default;
  animation: acModalBoxIn .3s cubic-bezier(.22, 1, .36, 1);
  position: relative;
}
@keyframes acModalBoxIn {
  from { transform: scale(.94) translateY(20px); opacity: 0; }
  to   { transform: scale(1) translateY(0); opacity: 1; }
}

.ac-modal-close {
  position: absolute; top: 14px; right: 14px;
  width: 36px; height: 36px;
  border-radius: 50%;
  background: rgba(0,0,0,.5);
  border: 1px solid rgba(255,255,255,.15);
  color: #fff; font-size: 16px;
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  z-index: 5;
  transition: .15s;
  font-family: inherit;
}
.ac-modal-close:hover { background: rgba(0,0,0,.75); }

/* 弹窗左侧立绘 */
.ac-modal-art {
  position: relative;
  background: rgba(10,10,18,.6);
  display: flex; align-items: center; justify-content: center;
  overflow: hidden;
  padding: 20px;
}
.ac-modal-art img {
  max-width: 100%;
  max-height: 100%;
  width: auto;
  height: auto;
  object-fit: contain;   /* 关键：contain + max-width/height，绝不裁切 */
  display: block;
}
.ac-modal-art .ac-modal-fb {
  width: 100%; height: 100%;
  display: flex; align-items: center; justify-content: center;
  font-size: 160px; font-weight: 900;
  color: rgba(255,255,255,.9);
  font-family: "STKaiti", "KaiTi", "PingFang SC", serif;
  text-shadow: 0 8px 40px rgba(0,0,0,.6);
}

/* 弹窗右侧信息 */
.ac-modal-info {
  padding: 32px 30px;
  display: flex; flex-direction: column;
  overflow-y: auto;
  max-height: 90vh;
}

.ac-modal-stars {
  font-size: 20px;
  letter-spacing: 6px;
  line-height: 1;
  margin-bottom: 8px;
}
.ac-modal-stars.tier-1 { color: #fff; text-shadow: 0 0 16px rgba(255,255,255,.5); }
.ac-modal-stars.tier-2 { color: #7fc4ff; text-shadow: 0 0 18px rgba(127,196,255,.8); }
.ac-modal-stars.tier-3 { color: #ffd166; text-shadow: 0 0 20px rgba(255,209,102,.9); }

.ac-modal-name {
  margin: 0 0 6px;
  font-size: 32px; font-weight: 900;
  color: #fff;
  letter-spacing: 2px;
  line-height: 1.15;
}
.ac-modal-title {
  font-size: 15px;
  color: rgba(233,230,245,.7);
  letter-spacing: 1px;
  margin-bottom: 22px;
}

.ac-modal-divider {
  height: 1px;
  background: linear-gradient(90deg, rgba(255,255,255,.15), transparent);
  margin: 20px 0;
}

.ac-modal-section {
  margin-bottom: 18px;
}
.ac-modal-section-label {
  font-size: 11.5px;
  color: rgba(233,230,245,.5);
  letter-spacing: 2px;
  text-transform: uppercase;
  margin-bottom: 8px;
  font-weight: 700;
}
.ac-modal-code {
  font-family: ui-monospace, Menlo, Consolas, monospace;
  font-size: 13px;
  color: #e9e6f5;
  padding: 6px 12px;
  background: rgba(255,255,255,.05);
  border-radius: 8px;
  display: inline-block;
  word-break: break-all;
}
.ac-modal-pools {
  display: flex; flex-wrap: wrap; gap: 8px;
}
.ac-modal-pool {
  padding: 6px 14px;
  border-radius: 18px;
  background: linear-gradient(120deg, rgba(122,77,219,.28), rgba(214,51,132,.2));
  border: 1px solid rgba(122,77,219,.4);
  color: #e9e6f5;
  font-size: 12.5px;
}
.ac-modal-pool.off {
  background: rgba(255,255,255,.04);
  border-color: rgba(255,255,255,.12);
  color: rgba(233,230,245,.5);
}
.ac-modal-pool.off::after {
  content: ' 已下架';
  font-size: 10px;
  opacity: .7;
}
.ac-modal-nopool {
  color: rgba(233,230,245,.5);
  font-size: 13px;
}

.ac-modal-colorrow {
  display: flex; gap: 10px;
}
.ac-modal-color {
  width: 40px; height: 40px;
  border-radius: 10px;
  border: 1px solid rgba(255,255,255,.15);
  box-shadow: 0 4px 12px rgba(0,0,0,.4);
}

/* 移动端 */
@media (max-width: 720px) {
  .ac-page { padding: 20px 14px 60px; }
  .ac-title { font-size: 20px; }
  .ac-subtitle { font-size: 10px; letter-spacing: 2px; }
  .ac-grid { grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 10px; }
  .ac-name { font-size: 12.5px; }
  .ac-thumb-fb { font-size: 38px; }
  .ac-stats { margin-left: 0; width: 100%; }

  .ac-modal { padding: 12px; }
  .ac-modal-box {
    grid-template-columns: 1fr;
    grid-template-rows: auto auto;
    max-height: 92vh;
    overflow-y: auto;
    display: block;
  }
  .ac-modal-art {
    min-height: 220px;
    max-height: 50vh;
    padding: 12px;
  }
  .ac-modal-info { padding: 22px 22px; max-height: none; }
  .ac-modal-name { font-size: 24px; }
  .ac-modal-art .ac-modal-fb { font-size: 100px; }
}
  .ac-modal-chibi {
    width: 220px;
    height: 220px;
    border-radius: 14px;
    overflow: hidden;
    background: rgba(0,0,0,.3);
    border: 1px solid rgba(255,255,255,.14);
    margin-bottom: 18px;
    flex-shrink: 0;
    box-shadow: 0 8px 24px rgba(0,0,0,.35);
    align-self: left;
  }
  .ac-modal-chibi img {
    width: 100%; height: 100%;
    object-fit: contain;   /* Q版也 contain，不裁 */
    display: block;
  }
</style>
</head>
<body>

<div class="ac-page">

  <div class="ac-top">
    <div class="ac-title-wrap">
      <h1 class="ac-title">✦ 角色图鉴</h1>
      <span class="ac-subtitle">ALL CHARACTERS</span>
    </div>
    <a class="ac-back" href="index.php">← 返回抽卡</a>
  </div>

  <div class="ac-toolbar">
    <input type="text" class="ac-search" id="ac-search"
           placeholder="🔍 搜索名字、称号或 code…" autocomplete="off">

    <div class="ac-filter-row">
      <span class="ac-filter-label">星级</span>
      <div class="ac-chips" id="ac-star-chips">
        <button type="button" class="ac-chip on" data-star="all">全部 (<?= $totalCount ?>)</button>
        <?php for ($i = 9; $i >= 1; $i--): ?>
          <?php if ($starCount[$i] === 0) continue; ?>
          <button type="button" class="ac-chip" data-star="<?= $i ?>">
            <?= $i ?>★ (<?= $starCount[$i] ?>)
          </button>
        <?php endfor; ?>
      </div>
    </div>

    <div class="ac-filter-row">
      <span class="ac-filter-label">状态</span>
      <div class="ac-chips" id="ac-pool-chips">
        <button type="button" class="ac-chip on" data-pool="all">全部</button>
        <button type="button" class="ac-chip" data-pool="in">已入池</button>
        <button type="button" class="ac-chip" data-pool="out">未入池</button>
        <button type="button" class="ac-chip" data-pool="up">UP 中</button>
      </div>
      <span class="ac-stats" id="ac-stats">
        显示 <strong><?= $totalCount ?></strong> / <?= $totalCount ?> 个角色
      </span>
    </div>
  </div>

  <div class="ac-grid" id="ac-grid">
    <div class="ac-empty">加载中…</div>
  </div>

</div>

<!-- 详情弹窗 -->
<div class="ac-modal" id="ac-modal">
  <div class="ac-modal-box" id="ac-modal-box">
    <button type="button" class="ac-modal-close" id="ac-modal-close">✕</button>
    <div class="ac-modal-art" id="ac-modal-art"></div>
    <div class="ac-modal-info" id="ac-modal-info"></div>
  </div>
</div>

<script>
window.AC_CHARS = <?= json_encode($charsData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>

<script>
(function () {
  'use strict';

  var CHARS = window.AC_CHARS || [];

  var $grid       = document.getElementById('ac-grid');
  var $search     = document.getElementById('ac-search');
  var $starChips  = document.getElementById('ac-star-chips');
  var $poolChips  = document.getElementById('ac-pool-chips');
  var $stats      = document.getElementById('ac-stats');

  var $modal      = document.getElementById('ac-modal');
  var $modalBox   = document.getElementById('ac-modal-box');
  var $modalArt   = document.getElementById('ac-modal-art');
  var $modalInfo  = document.getElementById('ac-modal-info');
  var $modalClose = document.getElementById('ac-modal-close');

  var state = { text: '', star: 'all', pool: 'all' };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
    });
  }

  function tierOf(rarity) {
    if (rarity >= 6) return 'tier-3';
    if (rarity >= 4) return 'tier-2';
    return 'tier-1';
  }

  /* ============ 卡片 HTML ============ */
  function cardHTML(c) {
    var hasPool = c.pools && c.pools.length > 0;
    // UP 状态：简单起见，只要进入过卡池就算"已入池"，不单独显示 UP（信息在详情里）
    var thumbHtml;
    if (c.img_chibi) {
      thumbHtml = '<img src="' + esc(c.img_chibi) + '" alt="" loading="lazy">';
    } else if (c.img_half) {
      thumbHtml = '<img src="' + esc(c.img_half) + '" alt="" loading="lazy">';
    } else if (c.img_full) {
      thumbHtml = '<img src="' + esc(c.img_full) + '" alt="" loading="lazy">';
    } else {
      var glyph = c.avatar_text || (c.name ? c.name.charAt(0) : '?');
      thumbHtml = '<div class="ac-thumb-fb" style="background:linear-gradient(160deg,' +
                  esc(c.color_a) + ',' + esc(c.color_b) + ')">' + esc(glyph) + '</div>';
    }

    var nopoolTag = hasPool ? '' : '<span class="ac-badge ac-badge-nopool">未入池</span>';

    // 是否 UP
    var isUp = false;
    if (c.pools) {
      isUp = c.pools.some(function (p) { return p.active && p.is_up; });
    }
    var upTag = isUp ? '<span class="ac-badge ac-badge-up">UP</span>' : '';

    var searchKey = (c.name + ' ' + c.code + ' ' + c.title).toLowerCase();

    var activePools = (c.pools || []).filter(function (p) { return p.active; });
    var allPools = c.pools || [];

    return '' +
      '<div class="ac-card r' + c.rarity + '" ' +
           'data-id="' + c.id + '" ' +
           'data-star="' + c.rarity + '" ' +
           'data-pool="' + (hasPool ? 'in' : 'out') + '" ' +
           'data-hasup="' + (isUp ? '1' : '0') + '" ' +
           'data-search="' + esc(searchKey) + '">' +
        '<div class="ac-thumb">' +
          thumbHtml +
          '<span class="ac-badge ac-badge-star s' + c.rarity + '">' + c.rarity + '★</span>' +
          upTag +
          nopoolTag +
        '</div>' +
        '<div class="ac-body">' +
          '<div class="ac-name">' + esc(c.name) + '</div>' +
          '<div class="ac-code">' + esc(c.code) + '</div>' +
        '</div>' +
      '</div>';
  }

  /* ============ 渲染列表 ============ */
  function renderList() {
    if (!CHARS.length) {
      $grid.innerHTML = '<div class="ac-empty">还没有任何角色。</div>';
      return;
    }
    var q = state.text.trim().toLowerCase();

    var html = '';
    var visible = 0;
    for (var i = 0; i < CHARS.length; i++) {
      var c = CHARS[i];
      var key = (c.name + ' ' + c.code + ' ' + c.title).toLowerCase();
      var hasPool = c.pools && c.pools.length > 0;
      var isUp = false;
      if (c.pools) {
        isUp = c.pools.some(function (p) { return p.active && p.is_up; });
      }

      var okText = (q === '' || key.indexOf(q) !== -1);
      var okStar = (state.star === 'all' || String(c.rarity) === String(state.star));
      var okPool = true;
      if (state.pool === 'in')  okPool = hasPool;
      if (state.pool === 'out') okPool = !hasPool;
      if (state.pool === 'up')  okPool = isUp;

      if (okText && okStar && okPool) {
        html += cardHTML(c);
        visible++;
      }
    }

    if (!visible) {
      $grid.innerHTML = '<div class="ac-empty">没有匹配的角色</div>';
    } else {
      $grid.innerHTML = html;
    }
    if ($stats) {
      $stats.innerHTML = '显示 <strong>' + visible + '</strong> / ' + CHARS.length + ' 个角色';
    }
  }

  /* ============ 打开详情 ============ */
  function openDetail(id) {
    var c = null;
    for (var i = 0; i < CHARS.length; i++) {
      if (String(CHARS[i].id) === String(id)) { c = CHARS[i]; break; }
    }
    if (!c) return;

    // 立绘大图
        // 主图：半身优先
    var artHtml;
    var mainImg = c.img_half || c.img_full || c.img_chibi;
    if (mainImg) {
      artHtml = '<img src="' + esc(mainImg) + '" alt="">';
    } else {
      var glyph = c.avatar_text || (c.name ? c.name.charAt(0) : '?');
      artHtml = '<div class="ac-modal-fb" style="background:linear-gradient(160deg,' +
                esc(c.color_a) + ',' + esc(c.color_b) + ')">' + esc(glyph) + '</div>';
    }
    $modalArt.innerHTML = artHtml;

    // Q 版小图：在弹窗右侧信息区顶部
    var chibiHtml = '';
    if (c.img_chibi) {
      chibiHtml = '<div class="ac-modal-chibi"><img src="' + esc(c.img_chibi) + '" alt=""></div>';
    }

    // 卡池列表
    var poolHtml = '<div class="ac-modal-nopool">尚未加入任何卡池</div>';
    if (c.pools && c.pools.length) {
      var items = c.pools.map(function (p) {
        var cls = p.active ? 'ac-modal-pool' : 'ac-modal-pool off';
        var upTag = (p.active && p.is_up) ? ' <span style="font-size:10px;background:linear-gradient(120deg,#ffd166,#ff9a3c);color:#4a2b00;padding:1px 6px;border-radius:4px;font-weight:800;">UP</span>' : '';
        return '<span class="' + cls + '">' + esc(p.name) + upTag + '</span>';
      }).join('');
      poolHtml = '<div class="ac-modal-pools">' + items + '</div>';
    }

    // 星级
    var stars = '';
    for (var s = 0; s < c.rarity; s++) stars += '★';
    var tierCls = tierOf(c.rarity);

    var infoHtml = chibiHtml +
      '<div class="ac-modal-stars ' + tierCls + '">' + stars + '</div>' +
      '<h2 class="ac-modal-name">' + esc(c.name) + '</h2>' +
      '<div class="ac-modal-title">' + esc(c.title || '') + '</div>' +

      '<div class="ac-modal-divider"></div>' +

      '<div class="ac-modal-section">' +
        '<div class="ac-modal-section-label">Code</div>' +
        '<div class="ac-modal-code">' + esc(c.code) + '</div>' +
      '</div>' +

      '<div class="ac-modal-section">' +
        '<div class="ac-modal-section-label">所属卡池</div>' +
        poolHtml +
      '</div>' +

      '<div class="ac-modal-section">' +
        '<div class="ac-modal-section-label">主题色</div>' +
        '<div class="ac-modal-colorrow">' +
          '<div class="ac-modal-color" style="background:' + esc(c.color_a) + '"></div>' +
          '<div class="ac-modal-color" style="background:' + esc(c.color_b) + '"></div>' +
        '</div>' +
      '</div>';

    $modalInfo.innerHTML = infoHtml;
    $modal.classList.add('show');
  }

  function closeModal() {
    $modal.classList.remove('show');
  }

  /* ============ 事件 ============ */
  $search.addEventListener('input', function () {
    state.text = this.value;
    renderList();
  });

  $starChips.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.ac-chip') : null;
    if (!btn) return;
    var all = $starChips.querySelectorAll('.ac-chip');
    for (var i = 0; i < all.length; i++) all[i].classList.remove('on');
    btn.classList.add('on');
    state.star = btn.getAttribute('data-star');
    renderList();
  });

  $poolChips.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.ac-chip') : null;
    if (!btn) return;
    var all = $poolChips.querySelectorAll('.ac-chip');
    for (var i = 0; i < all.length; i++) all[i].classList.remove('on');
    btn.classList.add('on');
    state.pool = btn.getAttribute('data-pool');
    renderList();
  });

  $grid.addEventListener('click', function (e) {
    var card = e.target.closest ? e.target.closest('.ac-card') : null;
    if (!card) return;
    openDetail(card.getAttribute('data-id'));
  });

  $modalClose.addEventListener('click', closeModal);
  $modal.addEventListener('click', function (e) {
    if (e.target === $modal) closeModal();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && $modal.classList.contains('show')) closeModal();
  });

  /* ============ 启动 ============ */
  renderList();
})();
</script>

</body>
</html>