<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/auth.php';

auth_require();
header('Content-Type: application/json; charset=utf-8');

/* 扫描的上传子目录（都在 uploads/ 下） */
$scanDirs = [
    'library' => '素材库',
    'full'    => '立绘大图',
    'half'    => '半身立绘',
    'chibi'   => 'Q 版',
    'icon'    => '图标',
    'bg'      => '背景图',
];

$items = [];
$base  = GACHA_UPLOAD_DIR;

if (is_dir($base)) {
    foreach ($scanDirs as $cat => $label) {
        $dir = $base . '/' . $cat;
        if (!is_dir($dir)) continue;
        $files = @scandir($dir) ?: [];
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;
            $full = $dir . '/' . $f;
            if (!is_file($full)) continue;
            if (!preg_match('/\.(png|jpe?g|gif|webp|svg|avif|ico)$/i', $f)) continue;

            $items[] = [
                'path'  => 'uploads/' . $cat . '/' . $f,
                'thumb' => '../uploads/' . $cat . '/' . $f,
                'name'  => $f,
                'cat'   => $cat,
                'label' => $label,
                'size'  => filesize($full),
                'mtime' => filemtime($full),
            ];
        }
    }
}

usort($items, function ($a, $b) { return $b['mtime'] - $a['mtime']; });

echo json_encode([
    'items' => $items,
    'count' => count($items),
], JSON_UNESCAPED_UNICODE);