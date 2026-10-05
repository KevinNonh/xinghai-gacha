<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/settings.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    $pdo = gacha_pdo();
    switch ($action) {
        case 'pools':
            echo json_encode(['pools' => apiActivePools($pdo)], JSON_UNESCAPED_UNICODE);
            break;
        case 'pool_detail':
            $poolId = (int)($_POST['pool_id'] ?? $_GET['pool_id'] ?? 0);
            echo json_encode(apiPoolDetail($pdo, $poolId), JSON_UNESCAPED_UNICODE);
            break;
        case 'draw':
            $poolId = (int)($_POST['pool_id'] ?? 0);
            $times  = (int)($_POST['times'] ?? 1);
            echo json_encode(apiDraw($pdo, $poolId, $times), JSON_UNESCAPED_UNICODE);
            break;
        case 'last_draw':
            $poolId = (int)($_POST['pool_id'] ?? $_GET['pool_id'] ?? 0);
            echo json_encode(apiLastDraw($poolId), JSON_UNESCAPED_UNICODE);
            break;
        default:
            echo json_encode(['error' => 'unknown action'], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

/* ================= 卡池列表 ================= */
function apiActivePools(PDO $pdo): array {
    $rows = $pdo->query("SELECT * FROM pools WHERE is_active = 1 ORDER BY sort_order, id")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'       => (int)$r['id'],
            'code'     => (string)$r['code'],
            'name'     => (string)$r['name'],
            'subtitle' => (string)$r['subtitle'],
            'desc'     => (string)$r['description'],
            'color_a'  => (string)$r['color_a'],
            'color_b'  => (string)$r['color_b'],
        ];
    }
    return $out;
}

/* ================= 卡池详情（概率 + 角色列表） ================= */
function apiPoolDetail(PDO $pdo, int $poolId): array {
    $stmt = $pdo->prepare("SELECT * FROM pools WHERE id = ? AND is_active = 1");
    $stmt->execute([$poolId]);
    $pool = $stmt->fetch();
    if (!$pool) throw new RuntimeException('卡池不存在或未上架');

    $settings = settings_all();

    // 概率计算
    $rateCommon = max(0, (float)($settings['rate_common'] ?? 78));
    $rateRare   = max(0, (float)($settings['rate_rare']   ?? 20));
    $rateLegend = max(0, (float)($settings['rate_legend'] ?? 2));
    $sum = $rateCommon + $rateRare + $rateLegend;
    if ($sum <= 0) { $rateCommon = 78; $rateRare = 20; $rateLegend = 2; $sum = 100; }
    $rateCommon = $rateCommon / $sum * 100;
    $rateRare   = $rateRare   / $sum * 100;
    $rateLegend = $rateLegend / $sum * 100;

    $tiers = [
        1 => 'common', 2 => 'common', 3 => 'common',
        4 => 'rare',   5 => 'rare',
        6 => 'legend', 7 => 'legend', 8 => 'legend', 9 => 'legend',
    ];

    // 每档的总权重
    $tierTotal = ['common' => 0, 'rare' => 0, 'legend' => 0];
    for ($s = 1; $s <= 9; $s++) {
        $tierTotal[$tiers[$s]] += max(0, (float)($settings["weight_$s"] ?? 0));
    }

    $rates = [];
    $rateByTier = ['common' => $rateCommon, 'rare' => $rateRare, 'legend' => $rateLegend];
    for ($s = 9; $s >= 1; $s--) {
        $w = max(0, (float)($settings["weight_$s"] ?? 0));
        $t = $tiers[$s];
        $pct = $tierTotal[$t] > 0 ? $rateByTier[$t] * ($w / $tierTotal[$t]) : 0;
        $rates[$s] = round($pct, 4);
    }

    // 池内角色
    $stmt = $pdo->prepare("
        SELECT c.*, pc.weight AS pool_weight, pc.is_up
        FROM characters c
        JOIN pool_characters pc ON pc.character_id = c.id
        WHERE pc.pool_id = ?
        ORDER BY c.rarity DESC, pc.is_up DESC, c.name
    ");
    $stmt->execute([$poolId]);
    $chars = [];
    foreach ($stmt->fetchAll() as $c) {
        $chars[] = [
            'id'          => (int)$c['id'],
            'code'        => (string)$c['code'],
            'name'        => (string)$c['name'],
            'title'       => (string)$c['title'],
            'rarity'      => (int)$c['rarity'],
            'avatar_text' => (string)$c['avatar_text'],
            'color_a'     => (string)$c['color_a'],
            'color_b'     => (string)$c['color_b'],
            'is_up'       => (int)$c['is_up'] === 1,
            'img_half'    => normImg((string)($c['image_half'] ?? '')),
            'img_chibi'   => normImg((string)($c['image_chibi'] ?? '')),
            'img_full'    => normImg((string)($c['image_full'] ?? '')),
        ];
    }

    return [
        'pool' => [
            'id' => (int)$pool['id'], 'code' => $pool['code'], 'name' => $pool['name'],
            'subtitle' => $pool['subtitle'], 'desc' => $pool['description'],
            'color_a' => $pool['color_a'], 'color_b' => $pool['color_b'],
        ],
        'rates' => $rates,
        'tier_total' => $tierTotal,
        'rate_tiers' => [
            'common' => round($rateCommon, 4),
            'rare'   => round($rateRare, 4),
            'legend' => round($rateLegend, 4),
        ],
        'chars' => $chars,
        'pity' => apiPitySnapshot($poolId, $settings),
    ];
}

/* ================= 抽卡 ================= */
function apiDraw(PDO $pdo, int $poolId, int $times): array {
    if (!in_array($times, [1, 10], true)) $times = 1;

    $stmt = $pdo->prepare("SELECT * FROM pools WHERE id = ? AND is_active = 1");
    $stmt->execute([$poolId]);
    $pool = $stmt->fetch();
    if (!$pool) throw new RuntimeException('卡池不存在或未上架');

    $settings = settings_all();
    $key      = 'gacha_pity_' . $poolId;
    if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
        $_SESSION[$key] = ['p5' => 0];
    }
    $pity = &$_SESSION[$key];

    $results = [];
    for ($i = 0; $i < $times; $i++) {
        $star = rollStar($pity, $settings);
        $char = pickCharacter($pdo, $poolId, $star);
        if ($char === null) {
            continue;
        }
        $results[] = formatCharForFront($char);
        if ($star >= 6) $pity['p5'] = 0;
        else            $pity['p5']++;
    }

    $_SESSION['gacha_last_' . $poolId] = [
        'pool_id' => $poolId,
        'results' => $results,
        'ts'      => time(),
    ];

    return [
        'results' => $results,
        'pity'    => apiPitySnapshot($poolId, $settings),
    ];
}

function apiLastDraw(int $poolId): array {
    $key = 'gacha_last_' . $poolId;
    if (empty($_SESSION[$key]) || !is_array($_SESSION[$key])) {
        return ['results' => []];
    }
    $data = $_SESSION[$key];
    // 超过 1 小时就不再回显
    if (time() - (int)($data['ts'] ?? 0) > 3600) {
        unset($_SESSION[$key]);
        return ['results' => []];
    }
    return ['results' => $data['results'] ?? []];
}

/* ================= 抽卡核心 ================= */
function rollStar(array &$pity, array $settings): int {
    $rateCommon = (float)($settings['rate_common'] ?? 78);
    $rateRare   = (float)($settings['rate_rare']   ?? 20);
    $rateLegend = (float)($settings['rate_legend'] ?? 2);
    $sum = $rateCommon + $rateRare + $rateLegend;
    if ($sum <= 0) { $rateCommon = 78; $rateRare = 20; $rateLegend = 2; $sum = 100; }
    $rateCommon = $rateCommon / $sum * 100;
    $rateRare   = $rateRare   / $sum * 100;
    $rateLegend = $rateLegend / $sum * 100;

    $pityStart = max(1, (int)($settings['pity_start'] ?? 50));
    $pityStep  = max(0, (float)($settings['pity_step'] ?? 2));
    $pityHard  = max($pityStart, (int)($settings['pity_hard'] ?? 80));

    $pullNumber = (int)$pity['p5'] + 1;

    $legendRate = $rateLegend;
    if ($pullNumber >= $pityHard) {
        $legendRate = 100.0;
    } elseif ($pullNumber > $pityStart) {
        $extra = ($pullNumber - $pityStart) * $pityStep;
        $legendRate = min(100.0, $rateLegend + $extra);
    }

    // 剩下的按比例给 common / rare
    if ($legendRate >= 100) {
        $cRate = 0; $rRate = 0;
    } else {
        $scale = (100 - $legendRate) / max(0.0001, (100 - $rateLegend));
        $cRate = $rateCommon * $scale;
        $rRate = $rateRare   * $scale;
    }

    $roll = mt_rand(0, 999999) / 1000000 * 100; // 0 ~ 100

    if ($roll < $legendRate)         return rollWithin($settings, [6, 7, 8, 9]);
    if ($roll < $legendRate + $rRate) return rollWithin($settings, [4, 5]);
    return rollWithin($settings, [1, 2, 3]);
}

function rollWithin(array $settings, array $stars): int {
    $weights = [];
    $total = 0;
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

function pickCharacter(PDO $pdo, int $poolId, int $targetStar): ?array {
    // 精确匹配
    $stmt = $pdo->prepare("
        SELECT c.*, pc.weight AS pool_weight, pc.is_up
        FROM characters c
        JOIN pool_characters pc ON pc.character_id = c.id
        WHERE pc.pool_id = ? AND c.rarity = ?
    ");
    $stmt->execute([$poolId, $targetStar]);
    $cand = $stmt->fetchAll();
    if ($cand) return weightedPick($cand);

    // 找最近可用星级
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.rarity FROM characters c
        JOIN pool_characters pc ON pc.character_id = c.id
        WHERE pc.pool_id = ?
    ");
    $stmt->execute([$poolId]);
    $avail = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (!$avail) return null;

    $best = null; $bestDist = PHP_INT_MAX;
    foreach ($avail as $r) {
        $d = abs($r - $targetStar);
        // 距离相同时优先更低星级
        if ($d < $bestDist || ($d === $bestDist && ($best === null || $r < $best))) {
            $best = $r; $bestDist = $d;
        }
    }
    if ($best === null) return null;

    $stmt = $pdo->prepare("
        SELECT c.*, pc.weight AS pool_weight, pc.is_up
        FROM characters c
        JOIN pool_characters pc ON pc.character_id = c.id
        WHERE pc.pool_id = ? AND c.rarity = ?
    ");
    $stmt->execute([$poolId, $best]);
    $cand = $stmt->fetchAll();
    return $cand ? weightedPick($cand) : null;
}

function weightedPick(array $cand): array {
    $total = 0;
    foreach ($cand as $c) $total += max(0.0, (float)$c['pool_weight']);
    if ($total <= 0) return $cand[array_rand($cand)];
    $roll = mt_rand(0, 999999) / 1000000 * $total;
    $acc = 0;
    foreach ($cand as $c) {
        $acc += max(0.0, (float)$c['pool_weight']);
        if ($roll < $acc) return $c;
    }
    return $cand[count($cand) - 1];
}

function formatCharForFront(array $c): array {
    return [
        'id'          => (int)$c['id'],
        'name'        => (string)$c['name'],
        'title'       => (string)$c['title'],
        'rarity'      => (int)$c['rarity'],
        'avatar_text' => (string)$c['avatar_text'],
        'color_a'     => (string)$c['color_a'],
        'color_b'     => (string)$c['color_b'],
        'is_up'       => (int)$c['is_up'] === 1,
        'img_half'    => normImg((string)($c['image_half'] ?? '')),
        'img_chibi'   => normImg((string)($c['image_chibi'] ?? '')),
        'img_full'    => normImg((string)($c['image_full'] ?? '')),
    ];
}

function apiPitySnapshot(int $poolId, array $settings): array {
    $key = 'gacha_pity_' . $poolId;
    $since = 0;
    if (!empty($_SESSION[$key]['p5'])) $since = (int)$_SESSION[$key]['p5'];
    $hard = (int)($settings['pity_hard'] ?? 80);
    $start = (int)($settings['pity_start'] ?? 50);
    return [
        'since_6' => $since,
        'hard'    => $hard,
        'start'   => $start,
        'until_hard' => max(0, $hard - $since),
    ];
}

function normImg(string $p): string {
    if ($p === '') return '';
    if (preg_match('#^https?://#i', $p)) return $p;
    if ($p[0] === '/') return $p;
    if (strpos($p, 'uploads/') === 0) return $p;
    return 'uploads/' . ltrim($p, '/');
}