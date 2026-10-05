<?php
// 数据库连接、建表、默认设置
if (!defined('GACHA_ROOT')) {
    define('GACHA_ROOT', dirname(__DIR__));
}
define('GACHA_DATA_DIR',   GACHA_ROOT . '/data');
define('GACHA_DB_FILE',    GACHA_DATA_DIR . '/gacha.sqlite');
define('GACHA_UPLOAD_DIR', GACHA_ROOT . '/uploads');

function gacha_pdo(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    if (!is_dir(GACHA_DATA_DIR)) {
        @mkdir(GACHA_DATA_DIR, 0755, true);
    }

    $pdo = new PDO('sqlite:' . GACHA_DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');

    gacha_ensure_schema($pdo);
    return $pdo;
}

function gacha_ensure_schema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            created_at INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS characters (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            title TEXT DEFAULT '',
            rarity INTEGER NOT NULL,
            image_full TEXT DEFAULT '',
            image_half TEXT DEFAULT '',
            image_chibi TEXT DEFAULT '',
            color_a TEXT DEFAULT '#888888',
            color_b TEXT DEFAULT '#444444',
            avatar_text TEXT DEFAULT '',
            created_at INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS pools (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            subtitle TEXT DEFAULT '',
            description TEXT DEFAULT '',
            color_a TEXT DEFAULT '#5b8def',
            color_b TEXT DEFAULT '#c084fc',
            is_active INTEGER DEFAULT 1,
            sort_order INTEGER DEFAULT 0,
            created_at INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS pool_characters (
            pool_id INTEGER NOT NULL,
            character_id INTEGER NOT NULL,
            weight REAL DEFAULT 1.0,
            is_up INTEGER DEFAULT 0,
            PRIMARY KEY (pool_id, character_id)
        );

        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT
        );
    ");

    if ((int)$pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn() === 0) {
        gacha_seed_settings($pdo);
    }
}

function gacha_seed_settings(PDO $pdo): void {
    $defaults = [
        // 站点
        'site_title'      => '星海抽卡',
        'site_subtitle'   => 'GACHA SIMULATOR',
        'site_footer'     => '',
        'background_url'  => '',
        'default_pool_id' => '0',
        'timezone'        => 'Asia/Shanghai',
		'favicon_url'   => '',
		'custom_head'   => '',
		'custom_footer' => '',
		'custom_css'    => '',

        // 三档基础概率（合计必须 100）
        'rate_common' => '78',   // 普通档：1-3★
        'rate_rare'   => '20',   // 稀有档：4-5★
        'rate_legend' => '2',    // 传说档：6-9★

        // 各星级在档内的相对权重
        'weight_1' => '30',
        'weight_2' => '40',
        'weight_3' => '30',
        'weight_4' => '70',
        'weight_5' => '30',
        'weight_6' => '75',
        'weight_7' => '18',
        'weight_8' => '5.5',
        'weight_9' => '1.5',

        // 保底（只对 6★ 起）
        'pity_start' => '50',    // 从第 N 抽开始加概率
        'pity_step'  => '2',     // 每抽加多少百分点
        'pity_hard'  => '80',    // 硬保底抽数

        // UI
        'ten_pull_delay' => '160', // 十连过场单张停留毫秒
    ];
    $stmt = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)');
    foreach ($defaults as $k => $v) {
        $stmt->execute([$k, $v]);
    }
}

function gacha_is_installed(): bool {
    if (!file_exists(GACHA_DB_FILE)) return false;
    try {
        $pdo = gacha_pdo();
        return (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}