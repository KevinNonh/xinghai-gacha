<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

const GACHA_UPLOAD_MAX_SIZE = 5 * 1024 * 1024;   // 5MB
const GACHA_UPLOAD_MAX_EDGE = 1200;

function gacha_mime_map(): array {
    return [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
}

function upload_image(array $file, string $subdir): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'no_file'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => '上传失败（错误码 ' . $file['error'] . '）'];
    }
    if ($file['size'] > GACHA_UPLOAD_MAX_SIZE) {
        return ['ok' => false, 'error' => '文件超过 5MB'];
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return ['ok' => false, 'error' => '不是有效的图片文件'];
    }
    $map  = gacha_mime_map();
    $mime = $info['mime'];
    if (!isset($map[$mime])) {
        return ['ok' => false, 'error' => '只支持 JPG / PNG / WebP / GIF'];
    }

    $dir = GACHA_UPLOAD_DIR . '/' . $subdir;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_writable($dir)) {
        return ['ok' => false, 'error' => '上传目录不可写'];
    }

    $name = date('Ymd') . '_' . bin2hex(random_bytes(8));

    // 无 GD：原样保存
    if (!extension_loaded('gd')) {
        $ext   = $map[$mime];
        $final = $name . '.' . $ext;
        if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $final)) {
            return ['ok' => false, 'error' => '保存失败'];
        }
        return ['ok' => true, 'path' => 'uploads/' . $subdir . '/' . $final];
    }

    // 有 GD：压缩 + 转 webp
    $useWebp = function_exists('imagewebp');
    $final   = $name . '.' . ($useWebp ? 'webp' : 'jpg');
    $target  = $dir . '/' . $final;

    $ok = gd_process_image($file['tmp_name'], $mime, $target, GACHA_UPLOAD_MAX_EDGE, $useWebp);
    if (!$ok) {
        return ['ok' => false, 'error' => '图片处理失败'];
    }
    return ['ok' => true, 'path' => 'uploads/' . $subdir . '/' . $final];
}

function gd_process_image(string $src, string $mime, string $target, int $maxEdge, bool $webp): bool {
    switch ($mime) {
        case 'image/jpeg': $im = @imagecreatefromjpeg($src); break;
        case 'image/png':  $im = @imagecreatefrompng($src);  break;
        case 'image/webp': $im = @imagecreatefromwebp($src); break;
        case 'image/gif':  $im = @imagecreatefromgif($src);  break;
        default: return false;
    }
    if (!$im) return false;

    $w = imagesx($im);
    $h = imagesy($im);

    if ($w > $maxEdge || $h > $maxEdge) {
        $ratio = min($maxEdge / $w, $maxEdge / $h);
        $nw = max(1, (int)round($w * $ratio));
        $nh = max(1, (int)round($h * $ratio));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $trans = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $trans);
        imagealphablending($dst, true);
        imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($im);
        $im = $dst;
    }

    $ok = $webp ? imagewebp($im, $target, 85) : imagejpeg($im, $target, 88);
    imagedestroy($im);
    return $ok;
}

function delete_upload(string $relPath): void {
    if (!$relPath) return;
    if (strpos($relPath, 'uploads/') !== 0) return;
    $full = GACHA_ROOT . '/' . $relPath;
    if (is_file($full)) @unlink($full);
}

// 生成用于显示的图片地址（前台根路径）
function upload_url(string $relPath): string {
    if ($relPath === '') return '';
    // 完整外部 URL：直接返回
    if (preg_match('#^https?://#i', $relPath) || strpos($relPath, '//') === 0) {
        return $relPath;
    }
    // 以 / 开头：当作站内绝对路径
    if (strpos($relPath, '/') === 0) {
        return $relPath;
    }
    // 其余当作本地上传路径，加 ../ 前缀（后台页面用）
    return '../' . ltrim($relPath, '/');
}

/* ================= favicon 上传（不改尺寸不压缩） ================= */
function upload_favicon(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'no_file'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => '上传失败（' . $file['error'] . '）'];
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        return ['ok' => false, 'error' => '图标不能超过 2MB'];
    }

    $dir = GACHA_UPLOAD_DIR . '/icon';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_writable($dir)) return ['ok' => false, 'error' => '上传目录不可写'];

    $name = (string)($file['name'] ?? '');
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowedExt = ['png', 'webp', 'svg', 'ico', 'jpg', 'jpeg', 'gif'];
    if (!in_array($ext, $allowedExt, true)) {
        return ['ok' => false, 'error' => '只支持 PNG / WebP / SVG / ICO / JPG / GIF'];
    }

    // MIME 二次校验
    $allowedMimes = [
        'image/png', 'image/webp', 'image/svg+xml', 'image/x-icon',
        'image/vnd.microsoft.icon', 'image/jpeg', 'image/gif',
        'text/plain', 'text/html', // SVG 可能被识别为这两种，下面再加内容检查
    ];
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($fi, $file['tmp_name']);
        finfo_close($fi);
        if ($mime && !in_array($mime, $allowedMimes, true)) {
            return ['ok' => false, 'error' => '文件类型不合法（' . $mime . '）'];
        }
    }

    // SVG 内容安全检查：禁止 script / onload 等
    if ($ext === 'svg') {
        $content = @file_get_contents($file['tmp_name']);
        if ($content === false) return ['ok' => false, 'error' => '读取失败'];
        if (preg_match('/<script|onload\s*=|onerror\s*=|javascript:/i', $content)) {
            return ['ok' => false, 'error' => 'SVG 含有不安全的内容'];
        }
    }

    $newName = 'favicon_' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $target  = $dir . '/' . $newName;
    if (!@move_uploaded_file($file['tmp_name'], $target)) {
        return ['ok' => false, 'error' => '保存失败'];
    }
    @chmod($target, 0644);
    return ['ok' => true, 'path' => 'uploads/icon/' . $newName];
}

/* ================= 从 URL 导入图片 ================= */
function import_image_from_url(string $url, string $subdir = 'library'): array {
    $url = trim($url);
    if ($url === '') return ['ok' => false, 'error' => 'URL 为空'];

    if (!preg_match('#^https?://#i', $url)) {
        return ['ok' => false, 'error' => '只支持 http / https 链接'];
    }

    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) {
        return ['ok' => false, 'error' => 'URL 格式不正确'];
    }
    if (!empty($parts['user']) || !empty($parts['pass'])) {
        return ['ok' => false, 'error' => 'URL 不允许携带用户名密码'];
    }

    // SSRF 防护：解析所有 A / AAAA 记录，禁止内网 / 保留地址
    $host = strtolower($parts['host']);
    $ips  = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $r) {
                if (!empty($r['ip']))   $ips[] = $r['ip'];
                if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
            }
        }
    }
    if (empty($ips)) {
        return ['ok' => false, 'error' => '无法解析主机名'];
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['ok' => false, 'error' => '不允许访问内网地址'];
        }
    }

    // 下载
    $ctx = stream_context_create([
        'http' => [
            'timeout'        => 15,
            'user_agent'     => 'GachaImporter/1.0',
            'follow_location'=> 1,
            'max_redirects'  => 3,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false) {
        return ['ok' => false, 'error' => '下载失败，请确认链接可直接访问'];
    }
    if (strlen($data) > GACHA_UPLOAD_MAX_SIZE) {
        $mb = round(GACHA_UPLOAD_MAX_SIZE / 1048576, 1);
        return ['ok' => false, 'error' => "文件超过 {$mb}MB"];
    }

    // 类型识别
    $info = @getimagesizefromstring($data);
    if ($info === false) {
        return ['ok' => false, 'error' => '下载的内容不是有效的图片'];
    }
    $mimeMap = gacha_mime_map();
    $mime    = $info['mime'];
    if (!isset($mimeMap[$mime])) {
        return ['ok' => false, 'error' => '不支持的图片格式：' . $mime];
    }

    // 写入临时文件
    $dir = GACHA_UPLOAD_DIR . '/' . $subdir;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_writable($dir)) return ['ok' => false, 'error' => '上传目录不可写'];

    $tmp = tempnam(sys_get_temp_dir(), 'gacha_imp_');
    if ($tmp === false || @file_put_contents($tmp, $data) === false) {
        if ($tmp) @unlink($tmp);
        return ['ok' => false, 'error' => '临时文件写入失败'];
    }

    $name    = date('Ymd') . '_' . bin2hex(random_bytes(8));
    $useWebp = extension_loaded('gd') && function_exists('imagewebp');
    $final   = $name . '.' . ($useWebp ? 'webp' : $mimeMap[$mime]);
    $target  = $dir . '/' . $final;

    if (extension_loaded('gd')) {
        $ok = gd_process_image($tmp, $mime, $target, GACHA_UPLOAD_MAX_EDGE, $useWebp);
        @unlink($tmp);
        if (!$ok) return ['ok' => false, 'error' => '图片处理失败'];
    } else {
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            return ['ok' => false, 'error' => '保存失败'];
        }
    }
    @chmod($target, 0644);
    return ['ok' => true, 'path' => 'uploads/' . $subdir . '/' . $final];
}

/* ================= 检查文件被谁引用 ================= */
function find_file_refs(string $rel, int $excludeCharId = 0): array {
    if ($rel === '' || strpos($rel, 'uploads/') !== 0) return [];
    $pdo = gacha_pdo();
    $refs = [];

    // 角色引用（排除指定 ID，通常是"当前正在编辑的角色"）
    $stmt = $pdo->prepare(
        'SELECT id, name FROM characters
         WHERE (image_full = ? OR image_half = ? OR image_chibi = ?)
           AND id != ?'
    );
    $stmt->execute([$rel, $rel, $rel, $excludeCharId]);
    foreach ($stmt->fetchAll() as $r) {
        $refs[] = [
            'type'  => 'character',
            'id'    => (int)$r['id'],
            'label' => '角色「' . $r['name'] . '」',
        ];
    }

    // 站点设置引用
    $stmt = $pdo->prepare(
        "SELECT key FROM settings
         WHERE value = ? AND key IN ('background_url','favicon_url')"
    );
    $stmt->execute([$rel]);
    $map = ['background_url' => '站点背景图', 'favicon_url' => '站点图标'];
    foreach ($stmt->fetchAll() as $r) {
        $refs[] = [
            'type'  => 'setting',
            'id'    => $r['key'],
            'label' => $map[$r['key']] ?? $r['key'],
        ];
    }

    return $refs;
}