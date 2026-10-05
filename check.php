<?php
header('Content-Type: text/plain; charset=utf-8');

echo "PHP 版本：" . PHP_VERSION . "\n";
echo "版本是否 ≥ 7.4：" . (version_compare(PHP_VERSION, '7.4.0', '>=') ? '✅' : '❌ 必须升级') . "\n";
echo "\n";

$exts = [
    'pdo_sqlite' => '必须',
    'sqlite3'    => '必须',
    'mbstring'   => '必须',
    'json'       => '必须',
    'session'    => '必须',
    'gd'         => '图片压缩',
    'zip'        => '备份功能',
    'fileinfo'   => 'MIME 检测',
    'openssl'    => 'HTTPS 下载',
];

echo "扩展检查：\n";
foreach ($exts as $ext => $need) {
    $ok = extension_loaded($ext) ? '✅' : '❌';
    echo "  [$ok] $ext （$need）\n";
}

echo "\n目录权限：\n";
$dirs = [
    'data'    => __DIR__ . '/data',
    'uploads' => __DIR__ . '/uploads',
];
foreach ($dirs as $name => $path) {
    if (!is_dir($path)) {
        echo "  [❌] $name 不存在：$path\n";
        continue;
    }
    echo "  [" . (is_writable($path) ? '✅' : '❌') . "] $name 可写：" . $path . "\n";
}

echo "\n实际报错测试：\n";
try {
    $pdo = new PDO('sqlite:' . __DIR__ . '/data/_test.sqlite');
    echo "  [✅] PDO SQLite 连接成功\n";
    @unlink(__DIR__ . '/data/_test.sqlite');
} catch (Throwable $e) {
    echo "  [❌] PDO SQLite 失败：" . $e->getMessage() . "\n";
}

try {
    $g = @imagecreatetruecolor(1, 1);
    echo "  [" . ($g ? '✅' : '❌') . "] GD 创建画布" . ($g ? '成功' : '失败') . "\n";
} catch (Throwable $e) {
    echo "  [❌] GD 报错：" . $e->getMessage() . "\n";
}

echo "\n如果你看到了这个页面，说明 PHP 本身在运行。\n";