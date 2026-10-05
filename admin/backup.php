<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/settings.php';

// ★ 让 date() 用 settings 里配的时区（备份文件名、manifest 都会跟着对）
settings_all();

auth_require();

$pdo = gacha_pdo();
$errors = [];
$preview = null;
$hasZip  = class_exists('ZipArchive');

/* ================= 导出（必须在 HTML 输出前） ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'export') {
    csrf_check();
    if (!$hasZip) {
        $errors[] = '服务器未安装 ZipArchive 扩展';
    } else {
        doExport($pdo, !empty($_POST['include_settings']), !empty($_POST['include_images']));
        exit;
    }
}

/* ================= 导入预览 ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'import_preview') {
    csrf_check();
    if (!$hasZip) {
        $errors[] = '服务器未安装 ZipArchive 扩展';
    } elseif (empty($_FILES['zip']['name'])) {
        $errors[] = '请选择要导入的 zip 文件';
    } else {
        $result = prepareImport($_FILES['zip'], $pdo);
        if (isset($result['error'])) $errors[] = $result['error'];
        else $preview = $result;
    }
}

/* ================= 导入确认 ================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'import_confirm') {
    csrf_check();
    $tempDir = (string)post('temp_dir');
    $result  = executeImport($pdo, $tempDir);
    if (isset($result['error'])) {
        $errors[] = $result['error'];
    } else {
        cleanupTemp($tempDir);
        redirect('backup.php?msg=imported&imgs=' . (int)($result['img_copied'] ?? 0));
    }
}

$msg = (string)q('msg', '');
$msgImgs = (int)q('imgs', 0);

$pageTitle  = '数据备份';
$pageActive = 'backup';
require __DIR__ . '/_header.php';

/* ============================================================
   以下是工具函数
   ============================================================ */

/* ---------- 导出 ---------- */
function doExport(PDO $pdo, bool $withSettings, bool $withImages): void {
    $tmp = tempnam(sys_get_temp_dir(), 'gacha_export_');
    if ($tmp === false) {
        header('Content-Type: text/plain; charset=utf-8');
        exit('无法创建临时文件');
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        header('Content-Type: text/plain; charset=utf-8');
        exit('无法创建 zip');
    }

    // manifest
    $zip->addFromString('manifest.json', json_encode([
        'app'         => 'gacha',
        'version'     => '1.0',
        'exported_at' => date('c'),
        'with_settings' => $withSettings,
        'with_images'   => $withImages,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // data.json
    $data = collectData($pdo, $withSettings);
    $zip->addFromString('data.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // 图片
    if ($withImages) addImagesToZip($zip);

    $zip->close();

    $filename = 'gacha-backup-' . date('Ymd-His') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

function collectData(PDO $pdo, bool $withSettings): array {
    $characters = [];
    foreach ($pdo->query('SELECT * FROM characters ORDER BY id')->fetchAll() as $c) {
        $characters[] = [
            'code'        => (string)$c['code'],
            'name'        => (string)$c['name'],
            'title'       => (string)$c['title'],
            'rarity'      => (int)$c['rarity'],
            'image_full'  => (string)$c['image_full'],
            'image_half'  => (string)$c['image_half'],
            'image_chibi' => (string)$c['image_chibi'],
            'color_a'     => (string)$c['color_a'],
            'color_b'     => (string)$c['color_b'],
            'avatar_text' => (string)$c['avatar_text'],
        ];
    }

    $pools = [];
    $stmtPool = $pdo->query('SELECT * FROM pools ORDER BY id');
    $stmtPC   = $pdo->prepare('
        SELECT c.code, pc.weight, pc.is_up
        FROM pool_characters pc
        JOIN characters c ON c.id = pc.character_id
        WHERE pc.pool_id = ?
    ');
    foreach ($stmtPool->fetchAll() as $p) {
        $stmtPC->execute([$p['id']]);
        $pcs = [];
        foreach ($stmtPC->fetchAll() as $r) {
            $pcs[] = [
                'code'   => (string)$r['code'],
                'weight' => (float)$r['weight'],
                'is_up'  => (int)$r['is_up'],
            ];
        }
        $pools[] = [
            'code'        => (string)$p['code'],
            'name'        => (string)$p['name'],
            'subtitle'    => (string)$p['subtitle'],
            'description' => (string)$p['description'],
            'color_a'     => (string)$p['color_a'],
            'color_b'     => (string)$p['color_b'],
            'is_active'   => (int)$p['is_active'],
            'sort_order'  => (int)$p['sort_order'],
            'characters'  => $pcs,
        ];
    }

    $out = [
        'characters' => $characters,
        'pools'      => $pools,
    ];
    if ($withSettings) {
        $settings = [];
        foreach ($pdo->query('SELECT key, value FROM settings') as $r) {
            $settings[$r['key']] = (string)$r['value'];
        }
        $out['settings'] = $settings;
    }
    return $out;
}

function addImagesToZip(ZipArchive $zip): void {
    $base = GACHA_UPLOAD_DIR;
    if (!is_dir($base)) return;
    foreach (['library','full','half','chibi','icon','bg'] as $sub) {
        $dir = $base . '/' . $sub;
        if (!is_dir($dir)) continue;
        $files = @scandir($dir) ?: [];
        foreach ($files as $f) {
            if ($f === '.' || $f === '..') continue;
            $full = $dir . '/' . $f;
            if (!is_file($full)) continue;
            $zip->addFile($full, 'uploads/' . $sub . '/' . $f);
        }
    }
}

/* ---------- 导入预览 ---------- */
function prepareImport(array $file, PDO $pdo): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => '上传失败（错误码 ' . $file['error'] . '）'];
    }
    if ($file['size'] > 300 * 1024 * 1024) {
        return ['error' => '文件超过 300MB'];
    }

    $zip = new ZipArchive();
    if ($zip->open($file['tmp_name']) !== true) {
        return ['error' => '不是有效的 zip 文件'];
    }

    $manifestRaw = $zip->getFromName('manifest.json');
    $dataRaw     = $zip->getFromName('data.json');
    if ($dataRaw === false) {
        $zip->close();
        return ['error' => 'zip 里缺少 data.json，可能不是本系统的备份'];
    }
    $manifest = $manifestRaw ? (json_decode($manifestRaw, true) ?: []) : [];
    $data     = json_decode($dataRaw, true);
    if (!is_array($data)) {
        $zip->close();
        return ['error' => 'data.json 格式错误'];
    }

    // 解压
    $tempDir = GACHA_DATA_DIR . '/import_' . bin2hex(random_bytes(8));
    if (!@mkdir($tempDir, 0755, true)) {
        $zip->close();
        return ['error' => '无法创建临时目录'];
    }
    if (!$zip->extractTo($tempDir)) {
        $zip->close();
        cleanupTemp($tempDir);
        return ['error' => '解压失败'];
    }
    $zip->close();

    $stats = computeDiff($pdo, $data, $tempDir);

    return [
        'temp_dir' => $tempDir,
        'manifest' => $manifest,
        'stats'    => $stats,
    ];
}

function computeDiff(PDO $pdo, array $data, string $tempDir): array {
    $charNew = 0; $charUpdate = 0;
    foreach ($data['characters'] ?? [] as $c) {
        $stmt = $pdo->prepare('SELECT id FROM characters WHERE code = ?');
        $stmt->execute([$c['code'] ?? '']);
        if ($stmt->fetch()) $charUpdate++;
        else                $charNew++;
    }

    $poolNew = 0; $poolUpdate = 0;
    foreach ($data['pools'] ?? [] as $p) {
        $stmt = $pdo->prepare('SELECT id FROM pools WHERE code = ?');
        $stmt->execute([$p['code'] ?? '']);
        if ($stmt->fetch()) $poolUpdate++;
        else                $poolNew++;
    }

    $imgTotal = 0; $imgNew = 0;
    $uploadSrc = $tempDir . '/uploads';
    if (is_dir($uploadSrc)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadSrc, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $imgTotal++;
            $rel = substr($f->getPathname(), strlen($uploadSrc) + 1);
            if (!file_exists(GACHA_UPLOAD_DIR . '/' . $rel)) $imgNew++;
        }
    }

    return [
        'char_new'      => $charNew,
        'char_update'   => $charUpdate,
        'pool_new'      => $poolNew,
        'pool_update'   => $poolUpdate,
        'has_settings'  => isset($data['settings']),
        'img_total'     => $imgTotal,
        'img_new'       => $imgNew,
    ];
}

/* ---------- 导入执行 ---------- */
function executeImport(PDO $pdo, string $tempDir): array {
    if ($tempDir === '' || !is_dir($tempDir) || strpos($tempDir, GACHA_DATA_DIR) !== 0) {
        return ['error' => '临时目录无效，请重新上传'];
    }
    $dataFile = $tempDir . '/data.json';
    if (!is_file($dataFile)) {
        return ['error' => '临时数据丢失，请重新上传'];
    }
    $data = json_decode((string)file_get_contents($dataFile), true);
    if (!is_array($data)) {
        return ['error' => 'data.json 解析失败'];
    }

    $pdo->beginTransaction();
    try {
        /* 角色 */
        $codeToId = [];
        foreach ($data['characters'] ?? [] as $c) {
            $code = (string)($c['code'] ?? '');
            if ($code === '') continue;

            $stmt = $pdo->prepare('SELECT id FROM characters WHERE code = ?');
            $stmt->execute([$code]);
            $row = $stmt->fetch();

            $params = [
                (string)($c['name'] ?? ''),
                (string)($c['title'] ?? ''),
                max(1, min(9, (int)($c['rarity'] ?? 3))),
                (string)($c['image_full'] ?? ''),
                (string)($c['image_half'] ?? ''),
                (string)($c['image_chibi'] ?? ''),
                (string)($c['color_a'] ?? '#888888'),
                (string)($c['color_b'] ?? '#444444'),
                (string)($c['avatar_text'] ?? ''),
            ];

            if ($row) {
                $params[] = (int)$row['id'];
                $stmt = $pdo->prepare('
                    UPDATE characters SET
                        name=?, title=?, rarity=?,
                        image_full=?, image_half=?, image_chibi=?,
                        color_a=?, color_b=?, avatar_text=?
                    WHERE id=?
                ');
                $stmt->execute($params);
                $codeToId[$code] = (int)$row['id'];
            } else {
                $params[] = time();
                $stmt = $pdo->prepare('
                    INSERT INTO characters
                        (code, name, title, rarity, image_full, image_half, image_chibi, color_a, color_b, avatar_text, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                // 注意顺序：code 在最前
                $stmt->execute(array_merge([$code], array_slice($params, 0, 9), [$params[9]]));
                $codeToId[$code] = (int)$pdo->lastInsertId();
            }
        }

        /* 卡池 */
        foreach ($data['pools'] ?? [] as $p) {
            $code = (string)($p['code'] ?? '');
            if ($code === '') continue;

            $stmt = $pdo->prepare('SELECT id FROM pools WHERE code = ?');
            $stmt->execute([$code]);
            $row = $stmt->fetch();

            $poolParams = [
                (string)($p['name'] ?? ''),
                (string)($p['subtitle'] ?? ''),
                (string)($p['description'] ?? ''),
                (string)($p['color_a'] ?? '#5b8def'),
                (string)($p['color_b'] ?? '#c084fc'),
                !empty($p['is_active']) ? 1 : 0,
                (int)($p['sort_order'] ?? 0),
            ];

            if ($row) {
                $poolId = (int)$row['id'];
                $stmt = $pdo->prepare('
                    UPDATE pools SET
                        name=?, subtitle=?, description=?,
                        color_a=?, color_b=?, is_active=?, sort_order=?
                    WHERE id=?
                ');
                $stmt->execute(array_merge($poolParams, [$poolId]));
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO pools
                        (code, name, subtitle, description, color_a, color_b, is_active, sort_order, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute(array_merge([$code], $poolParams, [time()]));
                $poolId = (int)$pdo->lastInsertId();
            }

            /* 重建关联 */
            $pdo->prepare('DELETE FROM pool_characters WHERE pool_id = ?')->execute([$poolId]);
            $ins = $pdo->prepare('INSERT INTO pool_characters (pool_id, character_id, weight, is_up) VALUES (?, ?, ?, ?)');
            foreach ($p['characters'] ?? [] as $pc) {
                $cc = (string)($pc['code'] ?? '');
                if (!isset($codeToId[$cc])) continue;
                $ins->execute([
                    $poolId,
                    $codeToId[$cc],
                    max(0.01, (float)($pc['weight'] ?? 1.0)),
                    !empty($pc['is_up']) ? 1 : 0,
                ]);
            }
        }

        /* 设置 */
        if (isset($data['settings']) && is_array($data['settings'])) {
            $stmt = $pdo->prepare('
                INSERT INTO settings (key, value) VALUES (?, ?)
                ON CONFLICT(key) DO UPDATE SET value = excluded.value
            ');
            foreach ($data['settings'] as $k => $v) {
                if (!is_string($k) || $k === '') continue;
                $stmt->execute([$k, (string)$v]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['error' => '导入失败：' . $e->getMessage()];
    }

    /* 图片复制（事务外，耗时但不会破坏数据） */
    $imgCopied = 0;
    $uploadSrc = $tempDir . '/uploads';
    if (is_dir($uploadSrc)) {
        $imgCopied = copyUploads($uploadSrc, GACHA_UPLOAD_DIR);
    }

    return ['ok' => true, 'img_copied' => $imgCopied];
}

function copyUploads(string $src, string $dst): int {
    $count = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $rel = substr($item->getPathname(), strlen($src) + 1);
        if ($rel === '' || strpos($rel, '..') !== false || $rel[0] === '/') continue;

        $target = $dst . '/' . $rel;
        if ($item->isDir()) {
            if (!is_dir($target)) @mkdir($target, 0755, true);
            continue;
        }
        if (file_exists($target)) continue;   // 已存在跳过
        $dir = dirname($target);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (@copy($item->getPathname(), $target)) $count++;
    }
    return $count;
}

function cleanupTemp(string $dir): void {
    if ($dir === '' || !is_dir($dir) || strpos($dir, GACHA_DATA_DIR) !== 0) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        if ($item->isDir()) @rmdir($item->getPathname());
        else                @unlink($item->getPathname());
    }
    @rmdir($dir);
}
?>

<!-- ============ 页面内容 ============ -->
<?php if (!$hasZip): ?>
  <div class="flash err">
    ⚠ 服务器未安装 <code>ZipArchive</code> 扩展，无法使用导入导出功能。<br>
    在 1Panel 里安装 PHP 扩展 <code>zip</code> 后重启 PHP 容器即可。
  </div>
<?php endif; ?>

<?php if ($msg === 'imported'): ?>
  <div class="flash ok">
    ✓ 导入完成<?= $msgImgs > 0 ? '，新增 ' . $msgImgs . ' 张图片' : '' ?>
  </div>
<?php endif; ?>

<?php if ($errors): ?>
  <div class="flash err">
    <?php foreach ($errors as $e): ?>· <?= h($e) ?><br><?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="bk-grid">
  <div class="bk-card">
    <h2>📤 导出备份</h2>
    <p class="bk-desc">
      把角色、卡池、关联关系打包成 zip。可选包含站点设置和图片文件。
    </p>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="do" value="export">

      <label class="bk-opt">
        <input type="checkbox" name="include_settings" value="1">
        <span>包含站点设置（标题、背景图、概率配置等）</span>
      </label>
      <label class="bk-opt">
        <input type="checkbox" name="include_images" value="1">
        <span>包含图片文件（zip 会比较大，取决于素材库大小）</span>
      </label>

      <button type="submit" class="btn primary" <?= $hasZip ? '' : 'disabled' ?>>
        下载备份 zip
      </button>
    </form>
  </div>

  <div class="bk-card">
    <h2>📥 导入备份</h2>
    <p class="bk-desc">
      上传之前导出的 zip。<strong>合并模式</strong>：按 <code>code</code> 匹配，同 code 的角色/卡池会更新，新 code 会新增。<br>
      <span style="color:#fca5a5">⚠ 导入会覆盖同 code 的角色/卡池数据。</span>
    </p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="do" value="import_preview">

      <input type="file" name="zip" accept=".zip,application/zip" required
             style="display:block;width:100%;padding:10px;margin-bottom:12px;
                    border:1px dashed var(--border);border-radius:10px;
                    background:var(--input-bg);color:var(--sub);font-size:12.5px;box-sizing:border-box">

      <button type="submit" class="btn primary" <?= $hasZip ? '' : 'disabled' ?>>
        上传并预览
      </button>
    </form>
    <p class="bk-hint">
      💡 如果上传失败，检查 Nginx <code>client_max_body_size</code> 和 PHP <code>upload_max_filesize</code>，
      建议至少 200M。
    </p>
  </div>
</div>

<?php if ($preview): ?>
  <?php $s = $preview['stats']; $man = $preview['manifest']; ?>
  <div class="bk-preview">
    <h2>🔍 导入预览</h2>
    <p class="bk-desc">
      备份文件导出时间：<?= h($man['exported_at'] ?? '未知') ?>
      <?php if (!empty($man['with_images'])): ?>· 含图片<?php endif; ?>
      <?php if (!empty($man['with_settings'])): ?>· 含设置<?php endif; ?>
    </p>

    <div class="bk-stats">
      <div class="bk-stat">
        <div class="num"><?= (int)$s['char_new'] ?></div>
        <div class="lbl">新增角色</div>
      </div>
      <div class="bk-stat">
        <div class="num"><?= (int)$s['char_update'] ?></div>
        <div class="lbl">更新角色</div>
      </div>
      <div class="bk-stat">
        <div class="num"><?= (int)$s['pool_new'] ?></div>
        <div class="lbl">新增卡池</div>
      </div>
      <div class="bk-stat">
        <div class="num"><?= (int)$s['pool_update'] ?></div>
        <div class="lbl">更新卡池</div>
      </div>
      <div class="bk-stat">
        <div class="num"><?= (int)$s['img_total'] ?></div>
        <div class="lbl">图片总数</div>
      </div>
      <div class="bk-stat">
        <div class="num"><?= (int)$s['img_new'] ?></div>
        <div class="lbl">图片新增</div>
      </div>
      <?php if (!empty($s['has_settings'])): ?>
        <div class="bk-stat">
          <div class="num">✓</div>
          <div class="lbl">含站点设置</div>
        </div>
      <?php endif; ?>
    </div>

    <div class="bk-actions">
      <form method="post" onsubmit="return confirm('确认导入？同 code 的角色和卡池会被更新。')">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="do" value="import_confirm">
        <input type="hidden" name="temp_dir" value="<?= h($preview['temp_dir']) ?>">
        <button type="submit" class="btn primary">✓ 确认导入</button>
      </form>
      <a class="btn" href="backup.php">取消</a>
    </div>
  </div>
<?php endif; ?>

<style>
.bk-grid {
  display: grid; grid-template-columns: 1fr 1fr; gap: 20px;
  margin-bottom: 22px;
}
.bk-card {
  background: var(--panel); border: 1px solid var(--border);
  border-radius: 14px; padding: 24px;
  transition: background .25s, border-color .25s;
}
.bk-card h2 { margin: 0 0 10px; font-size: 16px; color: var(--text); }
.bk-desc {
  color: var(--sub); font-size: 12.5px; margin: 0 0 18px;
  line-height: 1.7;
}
.bk-desc code {
  background: var(--overlay); padding: 1px 6px;
  border-radius: 4px; font-family: ui-monospace, monospace;
  font-size: 11.5px; color: var(--gold);
}
.bk-opt {
  display: flex; gap: 10px; align-items: flex-start;
  padding: 10px 0; font-size: 13px; cursor: pointer;
  line-height: 1.5;
}
.bk-opt input[type=checkbox] { width: 18px; height: 18px; margin-top: 2px; }
.bk-card .btn { margin-top: 14px; }
.bk-card input[type=file] {
  display: block; width: 100%; padding: 10px; margin-bottom: 12px;
  border: 1px dashed var(--border); border-radius: 10px;
  background: var(--input-bg); color: var(--sub); font-size: 12.5px; box-sizing: border-box;
  transition: background .25s, border-color .25s;
}
.bk-hint {
  color: var(--sub); font-size: 11.5px; margin-top: 14px;
  line-height: 1.7;
}
.bk-hint code {
  background: var(--overlay); padding: 1px 5px;
  border-radius: 4px; font-family: ui-monospace, monospace;
  color: var(--gold);
}

.bk-preview {
  background: var(--panel); border: 1px solid var(--border);
  border-radius: 14px; padding: 24px;
  transition: background .25s, border-color .25s;
}
.bk-preview h2 { margin: 0 0 10px; font-size: 16px; color: var(--text); }
.bk-stats {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
  gap: 12px; margin: 18px 0;
}
.bk-stat {
  padding: 14px 16px; border-radius: 10px;
  background: var(--panel-2); border: 1px solid var(--border);
  text-align: center;
  transition: background .25s, border-color .25s;
}
.bk-stat .num {
  font-size: 24px; font-weight: 800; color: var(--gold);
  line-height: 1.1;
}
.bk-stat .lbl { font-size: 11.5px; color: var(--sub); margin-top: 4px; }
.bk-actions { display: flex; gap: 10px; margin-top: 10px; }

@media (max-width: 720px) {
  .bk-grid { grid-template-columns: 1fr; }
}
</style>

<?php require __DIR__ . '/_footer.php'; ?>