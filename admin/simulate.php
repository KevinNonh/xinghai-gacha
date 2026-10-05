<?php
$pageTitle  = '模拟千抽';
$pageActive = 'simulate';
require __DIR__ . '/_header.php';

$pdo = gacha_pdo();

$result = null;
$errors = [];
$posted = ['mode' => 'pool', 'pool_id' => 0, 'times' => 1000];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $posted['mode']    = (string)post('mode', 'pool');
    $posted['pool_id'] = (int)post('pool_id', 0);
    $posted['times']   = max(100, min(10000, (int)post('times', 1000)));

    if (!in_array($posted['mode'], ['pool', 'all'], true)) {
        $posted['mode'] = 'pool';
    }

    if ($posted['mode'] === 'pool') {
        if ($posted['pool_id'] <= 0) {
            $errors[] = '请选择卡池';
        } else {
            $stmt = $pdo->prepare('SELECT id, name FROM pools WHERE id = ?');
            $stmt->execute([$posted['pool_id']]);
            $row = $stmt->fetch();
            if (!$row) $errors[] = '卡池不存在';
            else $posted['pool_name'] = $row['name'];
        }
    }

    if (empty($errors)) {
        $result = runSimulation($pdo, $posted['mode'], $posted['pool_id'], $posted['times']);
        if (isset($result['error'])) {
            $errors[] = $result['error'];
            $result = null;
        }
    }
}

/* ========================================================
   模拟核心
   ======================================================== */
function runSimulation(PDO $pdo, string $mode, int $poolId, int $times): array {
    $settings = settings_all();

    /* 载入候选角色 */
    if ($mode === 'pool') {
        $stmt = $pdo->prepare("
            SELECT c.id, c.name, c.rarity, c.color_a, c.color_b,
                   pc.weight AS pool_weight, pc.is_up
            FROM characters c
            JOIN pool_characters pc ON pc.character_id = c.id
            WHERE pc.pool_id = ?
        ");
        $stmt->execute([$poolId]);
        $chars = $stmt->fetchAll();
    } else {
        $chars = $pdo->query("
            SELECT id, name, rarity, color_a, color_b,
                   1.0 AS pool_weight, 0 AS is_up
            FROM characters
        ")->fetchAll();
    }

    if (empty($chars)) {
        return ['error' => '没有可用角色'];
    }

    /* 按星级分组 */
    $byStar = [];
    foreach ($chars as $c) {
        $r = (int)$c['rarity'];
        if ($r < 1 || $r > 9) continue;
        $byStar[$r][] = $c;
    }
    if (empty($byStar)) {
        return ['error' => '角色星级配置异常，无 1~9 星角色'];
    }
    $availStars = array_keys($byStar);
    sort($availStars);

    /* 统计容器 */
    $starCount = array_fill_keys(range(1, 9), 0);
    $charCount = [];
    foreach ($chars as $c) $charCount[(int)$c['id']] = 0;

    /* 循环抽卡（含保底） */
    $pity = 0;
    for ($i = 0; $i < $times; $i++) {
        $rolledStar = rollStarSim($pity, $settings);
        $actualStar = findClosestStar($rolledStar, $availStars);

        $candidates = $byStar[$actualStar] ?? [];
        if (empty($candidates)) continue;

        $pick = weightedPickSim($candidates);

        $starCount[$rolledStar]++;
        $charCount[(int)$pick['id']]++;

        if ($rolledStar >= 6) $pity = 0;
        else                  $pity++;
    }

    /* 星级汇总 */
    $starSummary = [];
    $tierOf = [1=>'common',2=>'common',3=>'common',4=>'rare',5=>'rare',6=>'legend',7=>'legend',8=>'legend',9=>'legend'];

    $rc = (float)($settings['rate_common'] ?? 78);
    $rr = (float)($settings['rate_rare']   ?? 20);
    $rl = (float)($settings['rate_legend'] ?? 2);
    $sum = $rc + $rr + $rl;
    if ($sum <= 0) $sum = 100;
    $rateByTier = [
        'common' => $rc / $sum * 100,
        'rare'   => $rr / $sum * 100,
        'legend' => $rl / $sum * 100,
    ];
    $tierTotal = ['common'=>0,'rare'=>0,'legend'=>0];
    for ($s = 1; $s <= 9; $s++) {
        $tierTotal[$tierOf[$s]] += max(0, (float)($settings["weight_$s"] ?? 0));
    }

    for ($s = 9; $s >= 1; $s--) {
        $cnt = $starCount[$s];
        $actualPct = $times > 0 ? $cnt / $times * 100 : 0;
        $t  = $tierOf[$s];
        $w  = max(0, (float)($settings["weight_$s"] ?? 0));
        $expectPct = $tierTotal[$t] > 0
            ? $rateByTier[$t] * ($w / $tierTotal[$t])
            : 0;
        $starSummary[$s] = [
            'count'  => $cnt,
            'actual' => $actualPct,
            'expect' => $expectPct,
            'diff'   => $actualPct - $expectPct,
        ];
    }

    /* 角色汇总 */
    $charSummary = [];
    foreach ($chars as $c) {
        $cnt = $charCount[(int)$c['id']];
        $charSummary[] = [
            'id'     => (int)$c['id'],
            'name'   => (string)$c['name'],
            'rarity' => (int)$c['rarity'],
            'is_up'  => !empty($c['is_up']),
            'count'  => $cnt,
            'pct'    => $times > 0 ? $cnt / $times * 100 : 0,
        ];
    }
    usort($charSummary, function ($a, $b) {
        if ($a['rarity'] !== $b['rarity']) return $b['rarity'] - $a['rarity'];
        return $b['count'] - $a['count'];
    });

    /* 6★+ 合计 */
    $legend6plus = 0;
    for ($s = 6; $s <= 9; $s++) $legend6plus += $starCount[$s];

    return [
        'times'         => $times,
        'mode'          => $mode,
        'pool_id'       => $poolId,
        'star_summary'  => $starSummary,
        'char_summary'  => $charSummary,
        'total_chars'   => count($chars),
        'legend6plus'   => $legend6plus,
        'legend6plus_pct' => $times > 0 ? $legend6plus / $times * 100 : 0,
    ];
}

/* ---------- 模拟抽卡（含保底，纯函数，不写 session） ---------- */
function rollStarSim(int $pity, array $settings): int {
    $rc = (float)($settings['rate_common'] ?? 78);
    $rr = (float)($settings['rate_rare']   ?? 20);
    $rl = (float)($settings['rate_legend'] ?? 2);
    $sum = $rc + $rr + $rl;
    if ($sum <= 0) { $rc = 78; $rr = 20; $rl = 2; $sum = 100; }
    $rc = $rc / $sum * 100;
    $rr = $rr / $sum * 100;
    $rl = $rl / $sum * 100;

    $pityStart = max(1, (int)($settings['pity_start'] ?? 50));
    $pityStep  = max(0, (float)($settings['pity_step'] ?? 2));
    $pityHard  = max($pityStart, (int)($settings['pity_hard'] ?? 80));

    $pullNumber = $pity + 1;
    $legendRate = $rl;
    if ($pullNumber >= $pityHard) {
        $legendRate = 100.0;
    } elseif ($pullNumber > $pityStart) {
        $legendRate = min(100.0, $rl + ($pullNumber - $pityStart) * $pityStep);
    }

    if ($legendRate >= 100) {
        $cRate = 0; $rRate = 0;
    } else {
        $scale = (100 - $legendRate) / max(0.0001, (100 - $rl));
        $cRate = $rc * $scale;
        $rRate = $rr * $scale;
    }

    $roll = mt_rand(0, 999999) / 1000000 * 100;
    if ($roll < $legendRate)          return rollStarWithin($settings, [6,7,8,9]);
    if ($roll < $legendRate + $rRate) return rollStarWithin($settings, [4,5]);
    return rollStarWithin($settings, [1,2,3]);
}

function rollStarWithin(array $settings, array $stars): int {
    $weights = []; $total = 0;
    foreach ($stars as $s) {
        $w = max(0, (float)($settings["weight_$s"] ?? 0));
        $weights[$s] = $w;
        $total += $w;
    }
    if ($total <= 0) return $stars[0];
    $roll = mt_rand(0, 999999) / 1000000 * $total;
    $acc = 0;
    foreach ($weights as $s => $w) {
        $acc += $w;
        if ($roll < $acc) return $s;
    }
    return $stars[count($stars) - 1];
}

function findClosestStar(int $target, array $avail): int {
    if (in_array($target, $avail, true)) return $target;
    if (empty($avail)) return $target;
    $best = null; $bestDist = PHP_INT_MAX;
    foreach ($avail as $r) {
        $d = abs($r - $target);
        if ($d < $bestDist || ($d === $bestDist && ($best === null || $r < $best))) {
            $best = $r; $bestDist = $d;
        }
    }
    return $best ?? $target;
}

function weightedPickSim(array $candidates): array {
    $total = 0;
    foreach ($candidates as $c) $total += max(0.0, (float)($c['pool_weight'] ?? 1.0));
    if ($total <= 0) return $candidates[array_rand($candidates)];
    $roll = mt_rand(0, 999999) / 1000000 * $total;
    $acc = 0;
    foreach ($candidates as $c) {
        $acc += max(0.0, (float)($c['pool_weight'] ?? 1.0));
        if ($roll < $acc) return $c;
    }
    return $candidates[count($candidates) - 1];
}

/* 拉卡池列表给下拉框用 */
$pools = $pdo->query('SELECT id, name, is_active FROM pools ORDER BY sort_order, id')->fetchAll();
?>

<style>
/* ========== 模拟千抽样式 ========== */
.sim-page { max-width: 1080px; }
.sim-flash {
  padding: 12px 16px; margin-bottom: 18px; border-radius: 10px; font-size: 13.5px;
}
.sim-flash.err { background: var(--danger-bg); color: var(--danger); border: 1px solid var(--danger-border); }

.sim-form {
  background: var(--panel); border: 1px solid var(--border);
  border-radius: 14px; padding: 24px; margin-bottom: 22px;
  transition: background .25s, border-color .25s;
}
.sim-form h2 { margin: 0 0 4px; font-size: 16px; color: var(--text); }
.sim-form .desc { color: var(--sub); font-size: 12.5px; margin-bottom: 20px; line-height: 1.7; }

.sim-section {
  padding: 16px 18px; border-radius: 12px;
  background: var(--panel-2); border: 1px solid var(--border);
  margin-bottom: 14px;
  transition: background .25s, border-color .25s;
}
.sim-section h3 {
  margin: 0 0 12px; font-size: 13px; color: var(--sub);
  font-weight: 700; letter-spacing: .5px; text-transform: uppercase;
}
.sim-radio-row { display: flex; gap: 22px; flex-wrap: wrap; }
.sim-radio {
  display: flex; align-items: center; gap: 8px;
  font-size: 13.5px; color: var(--text); cursor: pointer;
}
.sim-radio input { width: 18px; height: 18px; cursor: pointer; }
.sim-radio small { color: var(--sub); font-size: 11.5px; }

.sim-select, .sim-input {
  width: 100%; max-width: 360px;
  padding: 10px 14px; border-radius: 10px;
  border: 1px solid var(--border); background: var(--input-bg);
  color: var(--text); font-size: 13.5px; font-family: inherit; outline: none;
  box-sizing: border-box;
  transition: background .25s, border-color .25s;
}
.sim-select:focus, .sim-input:focus { border-color: var(--accent); }

.sim-submit {
  padding: 12px 28px; border: none; border-radius: 10px;
  background: linear-gradient(120deg, var(--accent), var(--accent-2));
  color: #fff; font-size: 14px; font-weight: 700; font-family: inherit;
  cursor: pointer;
}
.sim-submit:hover { filter: brightness(1.1); }

/* 结果区 */
.sim-result {
  background: var(--panel); border: 1px solid var(--border);
  border-radius: 14px; padding: 24px; margin-bottom: 22px;
  transition: background .25s, border-color .25s;
}
.sim-result h2 { margin: 0 0 4px; font-size: 16px; color: var(--text); }
.sim-result .meta { color: var(--sub); font-size: 12.5px; margin-bottom: 20px; }

.sim-highlight {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 14px; margin-bottom: 22px;
}
.sim-hl {
  padding: 18px 20px; border-radius: 12px;
  background: linear-gradient(120deg, rgba(122,77,219,.12), rgba(214,51,132,.08));
  border: 1px solid rgba(122,77,219,.28);
}
.sim-hl .num {
  font-size: 30px; font-weight: 800; color: var(--gold);
  line-height: 1.1; font-family: ui-monospace, monospace;
}
.sim-hl .lbl { color: var(--sub); font-size: 12px; margin-top: 4px; }

.sim-table {
  width: 100%; border-collapse: collapse; font-size: 13px;
  margin-bottom: 22px;
}
.sim-table thead th {
  padding: 10px 12px; text-align: left; font-size: 11px;
  color: var(--sub); text-transform: uppercase; letter-spacing: .5px;
  font-weight: 700; border-bottom: 1px solid var(--border);
}
.sim-table tbody td {
  padding: 9px 12px; border-bottom: 1px solid var(--border);
}
.sim-table tbody tr:last-child td { border-bottom: none; }
.sim-table tbody tr:hover { background: var(--overlay); }

.sim-star {
  display: inline-block; padding: 2px 9px; border-radius: 5px;
  font-size: 11.5px; font-weight: 800; color: #fff;
}
.sim-star.s1 { background: #CFCFCF; color: #333; }
.sim-star.s2 { background: #616161; }
.sim-star.s3 { background: #4ade80; color: #052e16; }
.sim-star.s4 { background: #3b82f6; }
.sim-star.s5 { background: #b28dff; }
.sim-star.s6 { background: linear-gradient(120deg,#ffd166,#ff9a3c); color: #4a2b00; }
.sim-star.s7 { background: linear-gradient(120deg,#ff9a3c,#d63384); }
.sim-star.s8 { background: linear-gradient(120deg,#d63384,#7a4ddb); }
.sim-star.s9 { background: linear-gradient(120deg,#ffd166,#d63384,#7a4ddb,#5b8def); }

.sim-num {
  font-family: ui-monospace, Menlo, Consolas, monospace;
  font-size: 13px; color: var(--text);
}
.sim-diff-up   { color: #22c55e; }
.sim-diff-down { color: #ef4444; }
html.theme-light .sim-diff-up   { color: #15803d; }
html.theme-light .sim-diff-down { color: #b91c1c; }
.sim-diff-flat { color: var(--sub); }

.sim-char-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 10px;
}
.sim-char {
  padding: 10px 14px; border-radius: 10px;
  background: var(--panel-2); border: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
  transition: background .25s, border-color .25s;
}
.sim-char .cn {
  display: flex; align-items: center; gap: 8px;
  font-size: 13px; color: var(--text);
  min-width: 0; flex: 1;
}
.sim-char .cn .nm {
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.sim-char .cn .up {
  font-size: 9.5px; padding: 1px 5px;
  background: linear-gradient(120deg, #ffd166, #ff9a3c);
  color: #4a2b00; border-radius: 4px;
  font-weight: 800; letter-spacing: .5px;
  flex-shrink: 0;
}
.sim-char .cc {
  font-family: ui-monospace, monospace;
  font-size: 13px; color: var(--gold); font-weight: 700;
  flex-shrink: 0;
}
.sim-char .cp {
  font-family: ui-monospace, monospace;
  font-size: 11px; color: var(--sub);
  flex-shrink: 0;
}

.sim-note {
  padding: 12px 16px; border-radius: 10px;
  background: var(--overlay); border: 1px solid var(--border);
  color: var(--sub); font-size: 12px; line-height: 1.7;
  margin-top: 18px;
}
.sim-note code {
  background: var(--overlay-strong); padding: 1px 6px;
  border-radius: 4px; font-family: ui-monospace, monospace;
  color: var(--gold); font-size: 11.5px;
}

@media (max-width: 720px) {
  .sim-char-grid { grid-template-columns: 1fr 1fr; }
  .sim-hl .num { font-size: 24px; }
  .sim-table { font-size: 12px; }
  .sim-table thead th, .sim-table tbody td { padding: 8px 6px; }
}
</style>

<div class="sim-page">

<?php if ($errors): ?>
  <div class="sim-flash err">
    <?php foreach ($errors as $e): ?>· <?= h($e) ?><br><?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- ========== 表单 ========== -->
<form method="post" class="sim-form">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <h2>🎲 模拟抽卡</h2>
  <p class="desc">
    在服务器端跑一批抽卡，<strong>不写数据库、不影响真实保底、不影响前台</strong>，只用来验证概率配置是否符合预期。<br>
    模拟会模拟保底机制（累积 6★ 保底计数），所以实际出率通常会略高于基础概率。
  </p>

  <div class="sim-section">
    <h3>抽取模式</h3>
    <div class="sim-radio-row">
      <label class="sim-radio">
        <input type="radio" name="mode" value="pool" id="mode-pool"
               <?= $posted['mode'] === 'pool' ? 'checked' : '' ?>>
        <span>按卡池抽 <small>（含该池关联角色 + 权重）</small></span>
      </label>
      <label class="sim-radio">
        <input type="radio" name="mode" value="all" id="mode-all"
               <?= $posted['mode'] === 'all' ? 'checked' : '' ?>>
        <span>全部角色模式 <small>（不分卡池，所有角色等权重）</small></span>
      </label>
    </div>
  </div>

  <div class="sim-section" id="pool-row">
    <h3>选择卡池</h3>
    <select name="pool_id" class="sim-select">
      <option value="0">—— 请选择 ——</option>
      <?php foreach ($pools as $p): ?>
        <option value="<?= (int)$p['id'] ?>"
                <?= (int)$posted['pool_id'] === (int)$p['id'] ? 'selected' : '' ?>>
          <?= h($p['name']) ?><?= empty($p['is_active']) ? '（已下架）' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="sim-section">
    <h3>抽取次数</h3>
    <input type="number" name="times" class="sim-input"
           value="<?= (int)$posted['times'] ?>" min="100" max="10000" step="100">
    <div style="color:var(--sub);font-size:11.5px;margin-top:6px">
      范围 100 ~ 10000，默认 1000。次数越多，结果越接近真实概率。
    </div>
  </div>

  <button type="submit" class="sim-submit">开始模拟</button>
</form>

<!-- ========== 结果 ========== -->
<?php if ($result): ?>
  <?php
    $modeLabel = $result['mode'] === 'pool'
        ? '卡池：' . h($posted['pool_name'] ?? ('#' . $result['pool_id']))
        : '全部角色模式';
  ?>
  <div class="sim-result">
    <h2>📊 模拟结果</h2>
    <p class="meta">
      <?= $modeLabel ?> · 共 <?= (int)$result['times'] ?> 抽 ·
      候选角色 <?= (int)$result['total_chars'] ?> 个
    </p>

    <div class="sim-highlight">
      <div class="sim-hl">
        <div class="num"><?= (int)$result['legend6plus'] ?></div>
        <div class="lbl">6★ 及以上合计</div>
      </div>
      <div class="sim-hl">
        <div class="num"><?= number_format($result['legend6plus_pct'], 3) ?>%</div>
        <div class="lbl">6★+ 实际出率</div>
      </div>
    </div>

    <!-- 星级分布表 -->
    <table class="sim-table">
      <thead>
        <tr>
          <th style="width:90px">星级</th>
          <th style="width:90px">次数</th>
          <th style="width:110px">实际出率</th>
          <th style="width:110px">预期出率</th>
          <th>偏差</th>
        </tr>
      </thead>
      <tbody>
        <?php for ($s = 9; $s >= 1; $s--): ?>
          <?php $row = $result['star_summary'][$s]; ?>
          <tr>
            <td><span class="sim-star s<?= $s ?>"><?= $s ?>★</span></td>
            <td class="sim-num"><?= (int)$row['count'] ?></td>
            <td class="sim-num"><?= number_format($row['actual'], 3) ?>%</td>
            <td class="sim-num" style="color:var(--sub)"><?= number_format($row['expect'], 3) ?>%</td>
            <td>
              <?php
                $d = $row['diff'];
                $cls = 'sim-diff-flat';
                if ($d > 0.05)      $cls = 'sim-diff-up';
                elseif ($d < -0.05) $cls = 'sim-diff-down';
              ?>
              <span class="sim-num <?= $cls ?>">
                <?= $d >= 0 ? '+' : '' ?><?= number_format($d, 3) ?>%
              </span>
            </td>
          </tr>
        <?php endfor; ?>
      </tbody>
    </table>

    <!-- 角色分布 -->
    <h3 style="font-size:14px;color:var(--text);margin:22px 0 12px">各角色出现次数</h3>
    <div class="sim-char-grid">
      <?php foreach ($result['char_summary'] as $c): ?>
        <div class="sim-char">
          <div class="cn">
            <span class="nm"><?= h($c['name']) ?></span>
            <?php if ($c['is_up']): ?><span class="up">UP</span><?php endif; ?>
          </div>
          <div class="cc"><?= (int)$c['count'] ?></div>
          <div class="cp"><?= number_format($c['pct'], 2) ?>%</div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="sim-note">
      💡 <strong>怎么读这张表：</strong><br>
      · <strong>预期出率</strong>是"按后台概率设置计算出的理论值"，不含保底<br>
      · <strong>实际出率</strong>是本次模拟的真实结果，会包含保底带来的概率提升<br>
      · 实际值通常略高于预期值，这是保底机制的正常表现<br>
      · 想验证概率是否配置正确，让 <code>偏差</code> 列接近 0 即可；加大次数（如 5000）能让结果更稳定
    </div>
  </div>
<?php endif; ?>

</div>

<script>
(function () {
  var modePool = document.getElementById('mode-pool');
  var modeAll  = document.getElementById('mode-all');
  var poolRow  = document.getElementById('pool-row');
  if (!modePool || !poolRow) return;

  function sync() {
    poolRow.style.display = modePool.checked ? '' : 'none';
  }
  modePool.addEventListener('change', sync);
  modeAll.addEventListener('change', sync);
  sync();
})();
</script>

<?php require __DIR__ . '/_footer.php'; ?>