<?php
require_once __DIR__ . '/db.php';

function settings_all(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $pdo = gacha_pdo();
    $rows = $pdo->query('SELECT key, value FROM settings')->fetchAll();
    $cache = [];
    foreach ($rows as $r) $cache[$r['key']] = $r['value'];

    // ★ 应用时区：只要 settings 被加载，PHP 后续 date() 就用这个时区
    if (!empty($cache['timezone'])) {
        @date_default_timezone_set($cache['timezone']);
    }

    return $cache;
}

function setting(string $key, $default = '') {
    $all = settings_all();
    return $all[$key] ?? $default;
}

function settings_set_many(array $items): void {
    $pdo = gacha_pdo();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO settings (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        );
        foreach ($items as $k => $v) {
            $stmt->execute([$k, (string)$v]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}