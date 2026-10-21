<?php
// ==========================================
// 配置区【上 GitHub 前必须改这两行】
// ==========================================
$accessPassword = "CHANGE_ME_STRONG_PASSWORD";   // 12 位以上随机密码
$authSalt       = "CHANGE_ME_RANDOM_SALT";       // 随机串；改动后所有旧 session 立即失效
$dataFile       = __DIR__ . '/bookmarks.json';   // 建议移出 webroot: dirname(__DIR__) . '/data/bookmarks.json'

$secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => $secureCookie,
]);
session_start();

$token  = hash('sha256', $authSalt . '|' . $accessPassword);
$isAuth = isset($_SESSION['bm_auth']) && hash_equals($token, (string)$_SESSION['bm_auth']);

if (array_key_exists('passwd', $_POST)) {
    $submitted = is_string($_POST['passwd']) ? $_POST['passwd'] : '';

    $_SESSION['bm_fail'] = (int)($_SESSION['bm_fail'] ?? 0);
    if ($_SESSION['bm_fail'] >= 10) sleep(3);   // 粗糙但有效的爆破减速

    if ($submitted !== '' && hash_equals($accessPassword, $submitted)) {
        $_SESSION['bm_fail'] = 0;
        session_regenerate_id(true);            // 防 session fixation
        $_SESSION['bm_auth'] = $token;
        header('Location: ./');
        exit;
    }

    $_SESSION['bm_fail']++;
    sleep(1);
    $loginErr = "密码错误";
}

if (!$isAuth) {
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>书签 · 验证访问</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:#F5F5F7;font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Segoe UI",Roboto,sans-serif;padding:20px}
.login{width:100%;max-width:340px}
.lock{width:48px;height:48px;border-radius:14px;background:#1C1C1E;color:#fff;display:flex;align-items:center;justify-content:center;margin-bottom:20px}
h2{font-size:19px;font-weight:600;letter-spacing:-.01em;margin-bottom:6px}
p{font-size:13px;color:#8A8A8E;margin-bottom:20px}
.err{color:#D70015;font-size:13px;margin-bottom:10px}
input{width:100%;background:#fff;border:1px solid #E5E5E7;border-radius:10px;padding:11px 13px;font-size:14px;outline:none}
input:focus{border-color:#1C1C1E}
button{margin-top:10px;width:100%;padding:11px;border:none;border-radius:10px;background:#1C1C1E;color:#fff;font-size:14px;font-weight:500;cursor:pointer}
button:hover{background:#000}
</style>
</head>
<body>
<form class="login" method="POST">
    <div class="lock"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2.5"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></div>
    <h2>请输入访问密码</h2>
    <p>此书签已加密</p>
    <?php if(!empty($loginErr)) echo '<div class="err">'.htmlspecialchars($loginErr, ENT_QUOTES, 'UTF-8').'</div>'; ?>
    <input name="passwd" type="password" autocomplete="off" placeholder="密码" autofocus>
    <button type="submit">进入</button>
</form>
</body>
</html>
<?php
exit;
}

// ==========================================
// 存储层
// ==========================================
function loadData($filePath)
{
    if (!file_exists($filePath)) return [];

    $fp = @fopen($filePath, 'r');
    if (!$fp) return [];

    $raw = '';
    if (flock($fp, LOCK_SH)) {          // 锁和读用同一个句柄
        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);

    $data = json_decode($raw, true);
    if (!is_array($data)) return [];    // 空文件 / 损坏 JSON 都退化为空，绝不覆盖

    foreach ($data as &$item) {
        if (!is_array($item)) continue;
        if (!isset($item['category'])) $item['category'] = '默认';
        if (!isset($item['desc']))     $item['desc'] = '';
        if (!isset($item['pinned']))   $item['pinned'] = false;
        if (!isset($item['visits']) || !is_numeric($item['visits'])) $item['visits'] = 0;
        $item['visits'] = max(0, (int)$item['visits']);
    }
    unset($item);
    return $data;
}

function saveData($filePath, $data)
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) return false;

    $dir = dirname($filePath);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    // 同目录临时文件，保证 rename 在同一分区（原子）
    $tmp = $filePath . '.tmp.' . bin2hex(random_bytes(6));
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;

    // 权限跟随旧文件，避免 umask 变化导致 web 进程后续写不进去
    if (file_exists($filePath)) @chmod($tmp, fileperms($filePath) & 0777);

    if (!@rename($tmp, $filePath)) {   // 原子替换：读者要么看到旧全量，要么看到新全量
        @unlink($tmp);
        return false;
    }
    return true;
}

function normUrl($url)
{
    $url = trim((string)$url);
    if ($url === '') return '';
    if (!preg_match('#^[a-zA-Z][a-zA-Z\d+\-.]*://#', $url)) $url = 'https://' . $url;

    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https', 'ftp', 'ftps'], true)) return '';

    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return '';
    return $url;
}

function jexit($arr, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function rawInput()
{
    return json_decode(file_get_contents('php://input'), true) ?: [];
}

function cut($s, $n)
{
    $s = trim((string)$s);
    if ($s === '') return '';
    return mb_strlen($s, 'UTF-8') > $n ? mb_substr($s, 0, $n, 'UTF-8') : $s;
}

function normFields(array $src)
{
    return [
        'title'    => cut($src['title']    ?? '', 200),
        'url'      => normUrl($src['url']  ?? ''),
        'desc'     => cut($src['desc']     ?? '', 500),
        'category' => cut($src['category'] ?? '', 50),
    ];
}

function saveOrDie($filePath, $data)
{
    if (!saveData($filePath, $data)) {
        jexit(['status' => 'error', 'msg' => '写入失败，请检查目录权限'], 500);
    }
}

$action = $_GET['action'] ?? '';

// ---------- 读取 ----------
if ($action === 'get') {
    jexit(loadData($dataFile));
}

// ---------- 表格整体保存(增 / 改 / 删 一次写入) ----------
if ($action === 'save_table' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = rawInput();
    $updates = is_array($input['updates'] ?? null) ? $input['updates'] : [];
    $creates = is_array($input['creates'] ?? null) ? $input['creates'] : [];
    $deletes = is_array($input['deletes'] ?? null) ? $input['deletes'] : [];

    $data = loadData($dataFile);

    // 删除
    $deleted = 0;
    if ($deletes) {
        $before = count($data);
        $data = array_values(array_filter($data, function ($it) use ($deletes) {
            return !in_array($it['id'] ?? '', $deletes, true);
        }));
        $deleted = $before - count($data);
    }

    // 修改
    $updMap = [];
    foreach ($updates as $u) {
        if (is_array($u) && !empty($u['id'])) $updMap[(string)$u['id']] = $u;
    }
    $updated = 0;
    foreach ($data as &$item) {
        $id = (string)($item['id'] ?? '');
        if (!isset($updMap[$id])) continue;
        $f = normFields($updMap[$id]);
        if ($f['title'] === '' || $f['url'] === '') continue; // 标题/网址为空则跳过该行
        $item['title']    = $f['title'];
        $item['url']      = $f['url'];
        $item['desc']     = $f['desc'];
        $item['category'] = $f['category'] === '' ? '默认' : $f['category'];
        $updated++;
    }
    unset($item);

    // 新增
    $created = 0;
    $newItems = [];
    foreach ($creates as $c) {
        if (!is_array($c)) continue;
        $f = normFields($c);
        if ($f['title'] === '' || $f['url'] === '') continue;
        $newItems[] = [
            'id'         => uniqid('', true),
            'title'      => $f['title'],
            'url'        => $f['url'],
            'desc'       => $f['desc'],
            'category'   => $f['category'] === '' ? '默认' : $f['category'],
            'created_at' => time(),
            'pinned'     => false,
            'visits'     => 0,
        ];
        $created++;
    }
    if ($newItems) $data = array_merge($newItems, $data);

    saveOrDie($dataFile, $data);
    jexit(['status' => 'success', 'updated' => $updated, 'created' => $created, 'deleted' => $deleted]);
}

// ---------- 新增 ----------
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = rawInput();
    $f = normFields($input);
    if ($f['title'] === '' || $f['url'] === '') jexit(['status' => 'error', 'msg' => '标题和网址不能为空'], 400);
    $data = loadData($dataFile);
    $new = [
        'id'         => uniqid('', true),
        'title'      => $f['title'],
        'url'        => $f['url'],
        'desc'       => $f['desc'],
        'category'   => $f['category'] === '' ? '默认' : $f['category'],
        'created_at' => time(),
        'pinned'     => false,
        'visits'     => 0,
    ];
    array_unshift($data, $new);
    saveOrDie($dataFile, $data);
    jexit(['status' => 'success', 'data' => $new]);
}

// ---------- 编辑单条(兼容) ----------
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = rawInput();
    $id = (string)($input['id'] ?? '');
    $f = normFields($input);
    if ($id === '' || $f['title'] === '' || $f['url'] === '') jexit(['status' => 'error', 'msg' => '参数不全'], 400);
    $data = loadData($dataFile);
    $found = false;
    foreach ($data as &$item) {
        if ((string)($item['id'] ?? '') === $id) {
            $item['title']    = $f['title'];
            $item['url']      = $f['url'];
            $item['desc']     = $f['desc'];
            $item['category'] = $f['category'] === '' ? '默认' : $f['category'];
            if (array_key_exists('pinned', $input)) $item['pinned'] = (bool)$input['pinned'];
            $found = true;
            break;
        }
    }
    unset($item);
    saveOrDie($dataFile, $data);
    jexit(['status' => 'success', 'found' => $found]);
}

// ---------- 访问计数 ----------
if ($action === 'visit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = rawInput();
    $id = is_string($input['id'] ?? null) ? trim($input['id']) : '';
    if ($id === '') jexit(['status' => 'error', 'code' => 'INVALID_ID'], 400);
    $data = loadData($dataFile);
    $found = false;
    $visits = 0;
    foreach ($data as &$item) {
        if ((string)($item['id'] ?? '') === $id) {
            $item['visits'] = ((int)($item['visits'] ?? 0)) + 1;
            $visits = $item['visits'];
            $found = true;
            break;
        }
    }
    unset($item);
    if (!$found) jexit(['status' => 'error', 'code' => 'NOT_FOUND'], 404);
    saveOrDie($dataFile, $data);
    jexit(['status' => 'success', 'visits' => $visits]);
}

// ---------- 置顶 ----------
if ($action === 'pin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = rawInput();
    $id = (string)($input['id'] ?? '');
    $pinned = !empty($input['pinned']);
    $data = loadData($dataFile);
    foreach ($data as &$item) {
        if ((string)($item['id'] ?? '') === $id) { $item['pinned'] = $pinned; break; }
    }
    unset($item);
    saveOrDie($dataFile, $data);
    jexit(['status' => 'success', 'pinned' => $pinned]);
}

// ---------- 批量改分类 ----------
if ($action === 'batch_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = rawInput();
    $ids = $input['ids'] ?? [];
    $newCat = cut($input['category'] ?? '', 50);
    if (!is_array($ids) || !$ids) jexit(['status' => 'error'], 400);
    $data = loadData($dataFile);
    foreach ($data as &$item) {
        if (in_array($item['id'] ?? '', $ids, true)) $item['category'] = $newCat === '' ? '默认' : $newCat;
    }
    unset($item);
    saveOrDie($dataFile, $data);
    jexit(['status' => 'success']);
}

// ---------- 批量删除 ----------
if ($action === 'batch_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = rawInput();
    $ids = $input['ids'] ?? [];
    if (!is_array($ids) || !$ids) jexit(['status' => 'error'], 400);
    $data = loadData($dataFile);
    $data = array_values(array_filter($data, function ($item) use ($ids) {
        return !in_array($item['id'] ?? '', $ids, true);
    }));
    saveOrDie($dataFile, $data);
    jexit(['status' => 'success']);
}

// ---------- 单条删除(兼容) ----------
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = rawInput();
    $delId = (string)($input['id'] ?? '');
    $data = loadData($dataFile);
    $data = array_values(array_filter($data, function ($item) use ($delId) {
        return (string)($item['id'] ?? '') !== $delId;
    }));
    saveOrDie($dataFile, $data);
    jexit(['status' => 'success']);
}

// ---------- 排序 ----------
if ($action === 'reorder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = rawInput();
    $ids = is_array($input['ids'] ?? null) ? $input['ids'] : [];
    $data = loadData($dataFile);
    $map = [];
    foreach ($data as $it) $map[(string)($it['id'] ?? '')] = $it;
    $new = [];
    foreach ($ids as $id) {
        $id = (string)$id;
        if (isset($map[$id])) { $new[] = $map[$id]; unset($map[$id]); }
    }
    foreach ($map as $it) $new[] = $it;
    saveOrDie($dataFile, $new);
    jexit(['status' => 'success']);
}

// ---------- 导入 ----------
if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = rawInput();
    $items = $input['items'] ?? [];
    if (!is_array($items)) $items = [];
    $items = array_slice($items, 0, 5000);   // 单次导入上限
    $data = loadData($dataFile);
    $existing = [];
    foreach ($data as $it) $existing[strtolower((string)($it['url'] ?? ''))] = true;
    $added = 0;
    $skipped = 0;
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $f = normFields($it);
        if ($f['title'] === '' || $f['url'] === '') { $skipped++; continue; }
        $key = strtolower($f['url']);
        if (isset($existing[$key])) { $skipped++; continue; }
        $existing[$key] = true;
        $data[] = [
            'id'         => uniqid('', true),
            'title'      => $f['title'],
            'url'        => $f['url'],
            'desc'       => $f['desc'],
            'category'   => $f['category'] === '' ? '默认' : $f['category'],
            'created_at' => isset($it['created_at']) ? (int)$it['created_at'] : time(),
            'pinned'     => !empty($it['pinned']),
            'visits'     => isset($it['visits']) && is_numeric($it['visits']) ? max(0, (int)$it['visits']) : 0,
        ];
        $added++;
    }
    saveOrDie($dataFile, $data);
    jexit(['status' => 'success', 'added' => $added, 'skipped' => $skipped]);
}

// 只有明确传了 ?action= 才走 API 分支;裸访问继续往下渲染页面
if ($action !== '') {
    jexit(['status' => 'error', 'msg' => '未知操作'], 404);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>书签</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{
  --bg:#FAFAFB; --card:#fff; --text:#1C1C1E; --muted:#8A8A8E; --faint:#B8B8BC;
  --line:#ECECEE; --line2:#F4F4F6; --accent:#0A84FF; --danger:#D70015;
  --r:10px;
}
html{-webkit-text-size-adjust:100%}
body{background:var(--bg);color:var(--text);font:13.5px/1.5 -apple-system,BlinkMacSystemFont,"SF Pro Text","PingFang SC","Segoe UI",Roboto,sans-serif;-webkit-font-smoothing:antialiased}
button,input,select{font-family:inherit;font-size:inherit;color:inherit}
button{border:none;background:none;cursor:pointer;display:inline-flex;align-items:center;gap:6px}
a{color:inherit;text-decoration:none}
svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round;flex:none}
::selection{background:rgba(10,132,255,.18)}
mark{background:rgba(255,204,0,.42);border-radius:3px;padding:0 1px}
::-webkit-scrollbar{width:9px;height:9px}
::-webkit-scrollbar-thumb{background:#D6D6DA;border-radius:9px;border:2px solid transparent;background-clip:content-box}
::-webkit-scrollbar-thumb:hover{background:#BDBDC2;background-clip:content-box}
::-webkit-scrollbar-track{background:transparent}

.wrap{max-width:1160px;margin:0 auto;padding:0 24px}

/* ---------- 顶栏 ---------- */
.top{position:sticky;top:0;z-index:30;background:rgba(250,250,251,.86);backdrop-filter:saturate(180%) blur(18px);-webkit-backdrop-filter:saturate(180%) blur(18px);border-bottom:1px solid var(--line)}
.top-in{max-width:1160px;margin:0 auto;padding:14px 24px;display:flex;align-items:center;gap:12px}
.brand{display:flex;align-items:center;gap:10px;margin-right:auto;min-width:0}
.logo{width:32px;height:32px;border-radius:9px;background:var(--text);color:#fff;display:flex;align-items:center;justify-content:center;flex:none}
.logo svg{width:16px;height:16px}
.brand h1{font-size:15px;font-weight:600;letter-spacing:-.01em;line-height:1.2}
.brand .sub{font-size:11.5px;color:var(--muted);font-variant-numeric:tabular-nums}
.search{position:relative;flex:0 1 260px}
.search input{width:100%;background:#EFEFF1;border:1px solid transparent;border-radius:9px;padding:7px 30px 7px 32px;outline:none;transition:.15s}
.search input:focus{background:#fff;border-color:var(--accent);box-shadow:0 0 0 3px rgba(10,132,255,.12)}
.search>svg{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--faint);width:14px;height:14px;pointer-events:none}
.search .clr{position:absolute;right:6px;top:50%;transform:translateY(-50%);width:22px;height:22px;border-radius:50%;justify-content:center;color:var(--muted)}
.search .clr:hover{background:rgba(0,0,0,.06);color:var(--text)}
.seg{display:flex;background:#EFEFF1;border-radius:9px;padding:2px;flex:none}
.seg button{padding:5px 11px;border-radius:7px;color:var(--muted);font-size:12.5px;font-weight:500}
.seg button svg{width:14px;height:14px}
.seg button.on{background:#fff;color:var(--text);box-shadow:0 1px 3px rgba(0,0,0,.08)}
.icon{width:32px;height:32px;border-radius:8px;justify-content:center;color:var(--muted);flex:none}
.icon:hover{background:rgba(0,0,0,.055);color:var(--text)}
.icon.on{background:rgba(10,132,255,.1);color:var(--accent)}
.btn{padding:7px 13px;border-radius:8px;font-size:12.5px;font-weight:500;border:1px solid var(--line);background:#fff;transition:.15s;white-space:nowrap}
.btn:hover{border-color:#D6D6DA;background:#FCFCFD}
.btn.primary{background:var(--text);color:#fff;border-color:var(--text)}
.btn.primary:hover{background:#000}
.btn.danger{color:var(--danger);border-color:rgba(215,0,21,.25)}
.btn.danger:hover{background:rgba(215,0,21,.06)}
.btn[disabled]{opacity:.45;pointer-events:none}
.btn svg{width:14px;height:14px}

/* 下拉菜单 */
.menu-wrap{position:relative;flex:none}
.menu{position:absolute;right:0;top:38px;min-width:168px;background:#fff;border:1px solid var(--line);border-radius:11px;padding:5px;box-shadow:0 12px 32px rgba(0,0,0,.1);z-index:50}
.menu button,.menu label{display:flex;align-items:center;gap:9px;width:100%;padding:7px 9px;border-radius:7px;font-size:13px;color:var(--text);cursor:pointer;text-align:left}
.menu button:hover,.menu label:hover{background:#F4F4F6}
.menu svg{width:15px;height:15px;color:var(--muted)}

/* ---------- 分类 ---------- */
.cats{display:flex;gap:6px;overflow-x:auto;padding:12px 0 0;scrollbar-width:none}
.cats::-webkit-scrollbar{display:none}
.cat{flex:none;padding:5px 11px;border-radius:7px;font-size:12.5px;color:var(--muted);font-weight:500}
.cat:hover{background:#F1F1F3;color:var(--text)}
.cat.on{background:var(--text);color:#fff}
.cat .n{opacity:.5;margin-left:4px;font-size:11px;font-variant-numeric:tabular-nums}

/* ---------- 工具栏 ---------- */
.bar{display:flex;align-items:center;gap:10px;padding:16px 0 10px;flex-wrap:wrap}
.meta{font-size:12.5px;color:var(--muted);margin-right:auto}
.meta b{color:var(--text);font-weight:600}
.sel-sort{position:relative;flex:none}
.sel-sort select{appearance:none;-webkit-appearance:none;background:#fff;border:1px solid var(--line);border-radius:8px;padding:7px 28px 7px 11px;font-size:12.5px;cursor:pointer;outline:none}
.sel-sort select:focus{border-color:var(--accent)}
.sel-sort svg{position:absolute;right:8px;top:50%;transform:translateY(-50%);width:12px;height:12px;color:var(--faint);pointer-events:none}

/* ---------- 表格 ---------- */
.panel{background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden}
.tw{overflow:auto;max-height:calc(100vh - 250px)}
table{width:100%;border-collapse:separate;border-spacing:0;min-width:820px}
thead th{position:sticky;top:0;z-index:5;background:#FCFCFD;border-bottom:1px solid var(--line);padding:9px 12px;text-align:left;font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--faint);white-space:nowrap}
tbody td{border-bottom:1px solid var(--line2);padding:9px 12px;vertical-align:middle}
tbody tr:hover td{background:#FCFCFD}
tbody tr:last-child td{border-bottom:none}
tbody tr.dirty td{background:rgba(10,132,255,.045)}
tbody tr.del{opacity:.42}
tbody tr.del .ti-t{text-decoration:line-through}
tbody tr.drag{opacity:.35}
.col-chk{width:36px;padding-left:14px!important}
.col-cat{width:112px}
.col-visit{width:66px}
.col-time{width:96px}
.col-act{width:104px;text-align:right}
td.col-visit,td.col-time{color:var(--muted);font-size:12px;font-variant-numeric:tabular-nums;white-space:nowrap}

.chk{width:17px;height:17px;border-radius:5px;border:1.5px solid #D2D2D6;background:#fff;justify-content:center;color:#fff;padding:0;transition:.12s}
.chk:hover{border-color:var(--muted)}
.chk.on{background:var(--accent);border-color:var(--accent)}
.chk svg{width:11px;height:11px;stroke-width:3}

.ti{display:flex;align-items:center;gap:9px;min-width:0}
.fav{position:relative;width:26px;height:26px;border-radius:7px;overflow:hidden;background:#F1F1F3;flex:none}
.fav img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.fav img[data-fail]{display:none}
.fav span{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:600;font-size:11px}
.ti-t{font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ti-d{font-size:11.5px;color:var(--faint);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
a.link:hover .ti-t{color:var(--accent)}
.u{color:var(--muted);font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;max-width:100%}
.tag{display:inline-block;font-size:11px;font-weight:500;padding:2px 8px;border-radius:6px;background:#F1F1F3;color:var(--muted);max-width:96px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pin{color:var(--accent);width:14px;height:14px;flex:none}
.acts{display:flex;gap:2px;justify-content:flex-end;opacity:0;transition:opacity .15s}
tr:hover .acts{opacity:1}
.acts .icon{width:27px;height:27px;border-radius:7px}
.acts .icon svg{width:14.5px;height:14.5px}
.acts .icon.pinned{color:var(--accent)}
.acts .icon.rm:hover{color:var(--danger);background:rgba(215,0,21,.08)}
.acts .pin-on{color:var(--accent)}

/* 单元格可编辑态 */
td input.cell{width:100%;background:transparent;border:1px solid transparent;border-radius:7px;padding:5px 7px;outline:none;font-size:13px}
td input.cell:hover{background:#F7F7F8}
td input.cell:focus{background:#fff;border-color:var(--accent);box-shadow:0 0 0 3px rgba(10,132,255,.12)}
td input.cell.u{color:var(--muted)}
td input.cell.bad{border-color:var(--danger);box-shadow:0 0 0 3px rgba(215,0,21,.1)}
tr.new td{background:rgba(52,199,89,.05)}
.hint{font-size:11.5px;color:var(--faint);padding:9px 14px;border-bottom:1px solid var(--line2);display:flex;gap:14px;flex-wrap:wrap;align-items:center}
kbd{font:inherit;font-size:11px;background:#F1F1F3;border-radius:4px;padding:1px 5px;color:var(--muted)}

/* ---------- 卡片视图 ---------- */
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px}
.card{position:relative;background:#fff;border:1px solid var(--line);border-radius:13px;padding:14px;display:flex;flex-direction:column;gap:9px;transition:.18s}
.card:hover{border-color:#DEDEE2;box-shadow:0 8px 24px rgba(0,0,0,.05)}
.card .hit{position:absolute;inset:0;border-radius:13px;z-index:1}
.card .acts{position:absolute;right:10px;top:10px;z-index:3;background:rgba(255,255,255,.9);border-radius:8px;padding:2px;backdrop-filter:blur(6px)}
.card:hover .acts{opacity:1}
.ct{display:flex;gap:10px;align-items:flex-start}
.ct h3{font-size:14px;font-weight:600;line-height:1.35;word-break:break-word}
.cd{font-size:12.5px;color:var(--muted);line-height:1.45;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word}
.cf{display:flex;align-items:center;gap:9px;margin-top:auto;color:var(--faint);font-size:11.5px}
.cf .sp{margin-left:auto;display:flex;align-items:center;gap:3px}
.cf svg{width:13px;height:13px}

/* ---------- 空态 ---------- */
.empty{text-align:center;padding:76px 20px;color:var(--muted)}
.empty svg{width:34px;height:34px;color:#D2D2D6;margin-bottom:12px;stroke-width:1.4}
.empty p{font-size:13px;margin-bottom:14px}

/* ---------- 底部条 ---------- */
.bars{position:fixed;left:50%;bottom:22px;transform:translateX(-50%);z-index:60;display:flex;gap:10px;align-items:center;background:rgba(28,28,30,.96);color:#fff;border-radius:12px;padding:9px 10px 9px 16px;box-shadow:0 16px 40px rgba(0,0,0,.24);font-size:13px;backdrop-filter:blur(12px)}
.bars .cb{display:flex;gap:6px}
.bars button{padding:5px 11px;border-radius:8px;font-size:12.5px;color:#fff;background:rgba(255,255,255,.14)}
.bars button:hover{background:rgba(255,255,255,.24)}
.bars button.ok{background:#fff;color:#1C1C1E;font-weight:500}
.bars button.ok:hover{background:#E8E8EA}
.bars button.no{background:rgba(215,0,21,.85)}
.bars button.no:hover{background:#D70015}
.bars .sep{width:1px;height:18px;background:rgba(255,255,255,.18)}

/* ---------- 弹窗 / Toast ---------- */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.28);z-index:70;display:flex;align-items:center;justify-content:center;padding:20px;animation:fin .18s ease}
.box{background:#fff;border-radius:15px;padding:22px;max-width:352px;width:100%;box-shadow:0 24px 60px rgba(0,0,0,.2);animation:pin .2s cubic-bezier(.2,.8,.3,1)}
.box h3{font-size:15px;font-weight:600;margin-bottom:6px}
.box p{font-size:13px;color:var(--muted);margin-bottom:16px}
.box .field{margin-bottom:12px}
.box label{display:block;font-size:11.5px;color:var(--muted);margin-bottom:5px;font-weight:500}
.box input{width:100%;background:#F7F7F8;border:1px solid transparent;border-radius:8px;padding:9px 11px;outline:none}
.box input:focus{background:#fff;border-color:var(--accent);box-shadow:0 0 0 3px rgba(10,132,255,.12)}
.box .end{display:flex;gap:8px;justify-content:flex-end;margin-top:18px}
.box .end .btn{padding:8px 15px}
.toast{position:fixed;left:50%;top:18px;transform:translateX(-50%);z-index:90;background:rgba(28,28,30,.94);color:#fff;border-radius:10px;padding:9px 15px;font-size:13px;display:flex;align-items:center;gap:8px;box-shadow:0 10px 30px rgba(0,0,0,.2);animation:pin .2s}
.toast svg{width:15px;height:15px}
@keyframes fin{from{opacity:0}}
@keyframes pin{from{opacity:0;transform:translateY(8px) scale(.98)}}

@media (max-width:720px){
  .wrap,.top-in{padding-left:14px;padding-right:14px}
  .top-in{gap:9px;flex-wrap:wrap}
  .search{flex:1 1 100%;order:5}
  .brand .sub{display:none}
  .tw{max-height:none}
  table{min-width:640px}
  .hide-sm{display:none}
  .bars{left:12px;right:12px;transform:none;justify-content:space-between}
}
</style>
</head>
<body>

<!-- 图标 sprite:全部内联,不依赖任何 CDN -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <symbol id="i-bookmark" viewBox="0 0 24 24"><path d="M6 3h12a1 1 0 0 1 1 1v17l-7-4.5L5 21V4a1 1 0 0 1 1-1z"/></symbol>
  <symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.6-3.6"/></symbol>
  <symbol id="i-x" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></symbol>
  <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
  <symbol id="i-table" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2.5"/><path d="M3 9h18M3 15h18M9 3v18"/></symbol>
  <symbol id="i-grid" viewBox="0 0 24 24"><rect x="3" y="3" width="7.5" height="7.5" rx="2"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="2"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="2"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="2"/></symbol>
  <symbol id="i-more" viewBox="0 0 24 24"><circle cx="12" cy="5.5" r="1.4" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.4" fill="currentColor" stroke="none"/><circle cx="12" cy="18.5" r="1.4" fill="currentColor" stroke="none"/></symbol>
  <symbol id="i-shuffle" viewBox="0 0 24 24"><path d="M16 3h5v5M4 20L21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/></symbol>
  <symbol id="i-down" viewBox="0 0 24 24"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></symbol>
  <symbol id="i-up" viewBox="0 0 24 24"><path d="M12 17V5M7 10l5-5 5 5M5 21h14"/></symbol>
  <symbol id="i-pin" viewBox="0 0 24 24"><path d="M9 3h6l-1 6 3 3v2H7v-2l3-3-1-6zM12 14v7"/></symbol>
  <symbol id="i-copy" viewBox="0 0 24 24"><rect x="9" y="9" width="12" height="12" rx="2.5"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></symbol>
  <symbol id="i-edit" viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/></symbol>
  <symbol id="i-trash" viewBox="0 0 24 24"><path d="M3 6h18M8 6V4h8v2M6.5 6l1 14h9l1-14"/></symbol>
  <symbol id="i-check" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></symbol>
  <symbol id="i-chevron" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></symbol>
  <symbol id="i-eye" viewBox="0 0 24 24"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></symbol>
  <symbol id="i-save" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M8 21v-8h8v8M8 3v5h6"/></symbol>
  <symbol id="i-undo" viewBox="0 0 24 24"><path d="M3 8V3h5"/><path d="M3.5 8a9 9 0 1 1-1 7"/></symbol>
  <symbol id="i-link" viewBox="0 0 24 24"><path d="M14 4h6v6M20 4l-8.5 8.5"/><path d="M18 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h5"/></symbol>
  <symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.2 2"/></symbol>
  <symbol id="i-folder" viewBox="0 0 24 24"><path d="M3 6.5a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></symbol>
  <symbol id="i-warn" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.2v.2"/></symbol>
</svg>

<div class="top">
  <div class="top-in">
    <div class="brand">
      <div class="logo"><svg><use href="#i-bookmark"/></svg></div>
      <div>
        <h1>书签</h1>
        <div class="sub" id="sub">—</div>
      </div>
    </div>

    <div class="search">
      <svg><use href="#i-search"/></svg>
      <input id="q" placeholder="搜索标题 / 网址 / 分类">
      <button class="clr" id="clr" hidden><svg><use href="#i-x"/></svg></button>
    </div>

    <div class="seg" id="seg">
      <button data-view="table" class="on"><svg><use href="#i-table"/></svg><span class="hide-sm">表格</span></button>
      <button data-view="card"><svg><use href="#i-grid"/></svg><span class="hide-sm">卡片</span></button>
    </div>

    <div class="menu-wrap">
      <button class="icon" id="moreBtn" title="更多"><svg><use href="#i-more"/></svg></button>
      <div class="menu" id="moreMenu" hidden>
        <button data-act="random"><svg><use href="#i-shuffle"/></svg>随便看看</button>
        <button data-act="export"><svg><use href="#i-down"/></svg>导出 JSON</button>
        <label><svg><use href="#i-up"/></svg>导入 JSON<input type="file" id="impFile" accept=".json,application/json" hidden></label>
      </div>
    </div>

    <button class="btn primary" id="addBtn"><svg><use href="#i-plus"/></svg>新建</button>
  </div>
</div>

<div class="wrap">
  <div class="cats" id="cats"></div>

  <div class="bar">
    <div class="meta" id="meta"></div>
    <div class="sel-sort">
      <select id="sort">
        <option value="new">最新添加</option>
        <option value="old">最早添加</option>
        <option value="title">按标题</option>
        <option value="visits">按访问量</option>
        <option value="custom">自定义顺序</option>
      </select>
      <svg><use href="#i-chevron"/></svg>
    </div>
    <button class="btn" id="editBtn"><svg><use href="#i-edit"/></svg>批量编辑</button>
  </div>

  <div id="main"></div>
</div>

<div id="bars"></div>

<script>
/* ================= 基础工具 ================= */
const $ = s => document.querySelector(s);
const esc = s => (s == null ? '' : String(s)).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const icon = (n, cls='') => `<svg class="${cls}"><use href="#i-${n}"/></svg>`;
const host = u => { try { return new URL(/^[a-z]+:\/\//i.test(u) ? u : 'https://'+u).hostname.replace(/^www\./,''); } catch(e){ return u||''; } };
const path = u => { try { const x = new URL(/^[a-z]+:\/\//i.test(u) ? u : 'https://'+u); return (x.hostname.replace(/^www\./,'') + (x.pathname==='/'?'':x.pathname)).slice(0,60); } catch(e){ return u||''; } };
const favOf = u => { const h = host(u); return h ? 'https://api.iowen.cn/favicon/'+h+'.png' : ''; };
const letter = t => ((t||'书').trim().charAt(0)||'#').toUpperCase();
const hue = t => { let h=0; const s=t||'#'; for(let i=0;i<s.length;i++) h=(h*31+s.charCodeAt(i))>>>0; return h%360; };
const catColor = n => ({background:`hsl(${hue(n)},62%,96%)`, color:`hsl(${hue(n)},45%,40%)`});
const ago = ts => {
  if(!ts) return '';
  const d = Date.now()/1000 - ts;
  if(d<60) return '刚刚';
  if(d<3600) return Math.floor(d/60)+'分前';
  if(d<86400) return Math.floor(d/3600)+'小时前';
  if(d<172800) return '昨天';
  if(d<604800) return Math.floor(d/86400)+'天前';
  const t = new Date(ts*1000);
  return t.getFullYear()+'/'+String(t.getMonth()+1).padStart(2,'0')+'/'+String(t.getDate()).padStart(2,'0');
};

let toastTimer;
function toast(msg, bad){
  const el = document.createElement('div');
  el.className = 'toast';
  el.innerHTML = icon(bad ? 'warn' : 'check') + '<span>' + esc(msg) + '</span>';
  document.body.appendChild(el);
  setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .2s'; setTimeout(() => el.remove(), 220); }, 2200);
}

/* ================= 接口层 ================= */
async function api(action, payload){
  const res = await fetch('?action=' + action, {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(payload || {})
  });
  return res.json();
}

/* ================= 状态 ================= */
const S = {
  items: [], q: '', cat: 'all', sort: 'new', view: 'table',
  edit: false, dirty: {}, news: [], dels: [], sel: new Set(), loading: true, failed: false
};
try {
  S.view = localStorage.getItem('bm_view') || 'table';
  S.sort = localStorage.getItem('bm_sort') || 'new';
} catch(e){}

const isNew = it => it.__new === true;
const catOf = c => (!c || c === '默认') ? '未分类' : c;
/* 按 id 找表格行(不用属性选择器,避免 id 里的特殊字符) */
const rowOf = id => [...document.querySelectorAll('tr[data-id],tr[data-key]')]
  .find(tr => (tr.dataset.id || tr.dataset.key) === id);

/* ================= 视图数据 ================= */
function visible(){
  let list = S.items;
  if(S.cat !== 'all') list = list.filter(b => catOf(b.category) === S.cat);
  const q = S.q.trim().toLowerCase();
  if(q) list = list.filter(b => [b.title,b.desc,b.url,b.category].some(v => (v||'').toLowerCase().includes(q)));
  list = list.slice();
  const pin = (a,b) => (b.pinned?1:0) - (a.pinned?1:0);
  if(S.sort === 'new') list.sort((a,b) => pin(a,b) || (b.created_at||0)-(a.created_at||0));
  else if(S.sort === 'old') list.sort((a,b) => pin(a,b) || (a.created_at||0)-(b.created_at||0));
  else if(S.sort === 'title') list.sort((a,b) => pin(a,b) || (a.title||'').localeCompare(b.title||'','zh'));
  else if(S.sort === 'visits') list.sort((a,b) => pin(a,b) || (b.visits||0)-(a.visits||0));
  return list;
}
function cats(){
  const m = new Map();
  S.items.forEach(b => { const k = catOf(b.category); m.set(k, (m.get(k)||0)+1); });
  return [...m.entries()].map(([name,count]) => ({name,count}))
    .sort((a,b) => (a.name==='未分类'?-1:0)-(b.name==='未分类'?-1:0) || b.count-a.count || a.name.localeCompare(b.name,'zh'));
}
const dirtyCount = () => Object.keys(S.dirty).length + S.news.length + S.dels.length;

/* ================= 渲染 ================= */
function render(){
  const list = visible();
  const all = S.items.length;
  const wk = S.items.filter(b => (b.created_at||0) >= Date.now()/1000 - 7*86400).length;

  $('#sub').textContent = all + ' 个书签 · ' + cats().length + ' 个分类';

  // 分类
  $('#cats').innerHTML =
    `<button class="cat ${S.cat==='all'?'on':''}" data-cat="all">全部<span class="n">${all}</span></button>` +
    cats().map(c => `<button class="cat ${S.cat===c.name?'on':''}" data-cat="${esc(c.name)}">${esc(c.name)}<span class="n">${c.count}</span></button>`).join('');

  // 工具栏
  $('#meta').innerHTML = (S.cat==='all' ? '全部书签' : esc(S.cat)) + ' · <b>' + list.length + '</b> 条' +
    (wk ? ' · 本周新增 ' + wk : '') +
    (S.edit ? ' · <span style="color:var(--accent)">编辑中,改完点右下角保存</span>' : '');

  // 视图切换
  document.querySelectorAll('#seg button').forEach(b => b.classList.toggle('on', b.dataset.view === S.view));
  const dl = $('#catList');
  if(dl) dl.innerHTML = cats().filter(c => c.name !== '未分类').map(c => `<option value="${esc(c.name)}">`).join('');
  $('#editBtn').innerHTML = S.edit ? icon('x') + '退出编辑' : icon('edit') + '批量编辑';
  $('#editBtn').classList.toggle('primary', S.edit);

  // 主体
  const main = $('#main');
  if(S.failed && !S.items.length){
    main.innerHTML = `<div class="panel"><div class="empty">${icon('warn')}<p>数据加载失败</p><button class="btn" onclick="load()">重新加载</button></div></div>`;
  } else if(!list.length && !S.news.length){
    const filtered = S.q || S.cat !== 'all';
    main.innerHTML = `<div class="panel"><div class="empty">${icon(filtered?'search':'bookmark')}
      <p>${filtered ? '没有匹配的书签' : '还没有书签,点右上角「新建」开始'}</p>
      ${filtered ? '<button class="btn" id="clrFilter">清除筛选</button>' : ''}</div></div>`;
    const cf = $('#clrFilter');
    if(cf) cf.onclick = () => { S.q=''; S.cat='all'; $('#q').value=''; $('#clr').hidden = true; render(); };
  } else {
    main.innerHTML = S.view === 'table' ? tableHtml(list) : cardHtml(list);
    bindMain();
  }
  renderBars();
}

/* ---------- 表格 ---------- */
function tableHtml(list){
  const head = `<tr>
    <th class="col-chk"><button class="chk" id="chkAll" title="全选">${icon('check')}</button></th>
    <th>标题</th><th class="hide-sm">网址</th><th class="col-cat hide-sm">分类</th>
    <th class="col-visit hide-sm">访问</th><th class="col-time hide-sm">添加</th><th class="col-act"></th>
  </tr>`;

  const newRows = S.news.map((r, i) => editRow({
    id: r.key, __new: true, title: r.title, url: r.url, desc: r.desc, category: r.category
  }, i)).join('');

  const rows = list.map(it => S.edit ? editRow(it) : viewRow(it)).join('');

  const hint = S.edit ? `<div class="hint">${icon('edit')}
      <span><kbd>Tab</kbd> 下一个单元格 · <kbd>Enter</kbd> 下一行 · <kbd>⌘/Ctrl+S</kbd> 保存 · <kbd>Esc</kbd> 退出</span>
      <span style="margin-left:auto;display:flex;gap:8px">
        <button class="btn" id="addRow">${icon('plus')}新增一行</button>
      </span>
    </div>` : '';

  return `<div class="panel">${hint}<div class="tw"><table>
    <thead>${head}</thead>
    <tbody>${newRows}${rows}</tbody>
  </table></div></div>`;
}

function viewRow(it){
  const c = catOf(it.category);
  const pinned = !!it.pinned;
  const cs = catColor(c);
  return `<tr data-id="${it.id}" ${S.sort==='custom'?'draggable="true"':''}>
    <td class="col-chk"><button class="chk ${S.sel.has(it.id)?'on':''}" data-act="sel">${icon('check')}</button></td>
    <td>
      <a class="link ti" href="${esc(it.url)}" target="_blank" rel="noopener" data-act="open">
        <div class="fav"><img src="${favOf(it.url)}" onerror="this.setAttribute('data-fail','1')" alt=""><span style="background:hsl(${hue(it.title)},55%,60%)">${esc(letter(it.title))}</span></div>
        <div style="min-width:0">
          <div class="ti-t">${pinned?icon('pin','pin'):''}${hl(it.title)}</div>
          ${it.desc?`<div class="ti-d">${hl(it.desc)}</div>`:''}
        </div>
      </a>
    </td>
    <td class="hide-sm"><span class="u" title="${esc(it.url)}">${hl(path(it.url))}</span></td>
    <td class="col-cat hide-sm">${c==='未分类'?'':`<span class="tag" style="${cs.background};${cs.color}">${esc(c)}</span>`}</td>
    <td class="col-visit hide-sm">${it.visits||0}</td>
    <td class="col-time hide-sm">${ago(it.created_at)}</td>
    <td class="col-act"><div class="acts">
      <button class="icon ${pinned?'pin-on':''}" data-act="pin" title="${pinned?'取消置顶':'置顶'}">${icon('pin')}</button>
      <button class="icon" data-act="copy" title="复制链接">${icon('copy')}</button>
      <button class="icon" data-act="row-edit" title="编辑这一行">${icon('edit')}</button>
      <button class="icon rm" data-act="del" title="删除">${icon('trash')}</button>
    </div></td>
  </tr>`;
}

function editRow(it, idx){
  const d = S.dirty[it.id] || {};
  const v = f => esc(d[f] !== undefined ? d[f] : (it[f] || ''));
  const bad = f => (d[f] !== undefined ? !String(d[f]).trim() : !String(it[f]||'').trim());
  const del = S.dels.includes(it.id);
  const tag = isNew(it) ? 'new' : '';
  const key = isNew(it) ? `data-key="${it.id}"` : `data-id="${it.id}"`;
  return `<tr class="${tag} ${del?'del':''}" ${key} data-row="1">
    <td class="col-chk">
      <button class="chk" data-act="drop" title="${isNew(it)?'移除这一行':'标记删除'}">${isNew(it)?icon('x'):icon('trash')}</button>
    </td>
    <td><div class="ti"><div class="fav"><img src="${favOf(d.url!==undefined?d.url:it.url)}" onerror="this.setAttribute('data-fail','1')" alt=""><span style="background:hsl(${hue(it.title)},55%,60%)">${esc(letter(it.title))}</span></div>
      <input class="cell ${bad('title')?'bad':''}" data-f="title" value="${v('title')}" placeholder="标题">
    </div></td>
    <td class="hide-sm"><input class="cell u ${bad('url')?'bad':''}" data-f="url" value="${v('url')}" placeholder="example.com"></td>
    <td class="col-cat hide-sm"><input class="cell" data-f="category" value="${v('category')}" placeholder="未分类" list="catList"></td>
    <td class="col-visit hide-sm">${isNew(it)?'—':(it.visits||0)}</td>
    <td class="col-time hide-sm">${isNew(it)?'待保存':ago(it.created_at)}</td>
    <td class="col-act"><div class="acts" style="opacity:1">
      ${isNew(it)?`<button class="icon" data-act="insert" title="新增一行">${icon('plus')}</button>`:''}
    </div></td>
  </tr>`;
}

function hl(text){
  const safe = esc(text);
  const q = S.q.trim();
  if(!q) return safe;
  const re = new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + ')','gi');
  return safe.replace(re,'<mark>$1</mark>');
}

/* ---------- 卡片 ---------- */
function cardHtml(list){
  return `<div class="grid">` + list.map(it => {
    const c = catOf(it.category);
    return `<div class="card" data-id="${it.id}">
      <a class="hit" href="${esc(it.url)}" target="_blank" rel="noopener" data-act="open"></a>
      <div class="acts">
        <button class="icon ${it.pinned?'pin-on':''}" data-act="pin" title="置顶">${icon('pin')}</button>
        <button class="icon" data-act="copy" title="复制">${icon('copy')}</button>
        <button class="icon" data-act="row-edit" title="编辑">${icon('edit')}</button>
        <button class="icon rm" data-act="del" title="删除">${icon('trash')}</button>
      </div>
      <div class="ct">
        <div class="fav" style="width:32px;height:32px;border-radius:9px"><img src="${favOf(it.url)}" onerror="this.setAttribute('data-fail','1')" alt=""><span style="background:hsl(${hue(it.title)},55%,60%)">${esc(letter(it.title))}</span></div>
        <h3>${hl(it.title)}</h3>
      </div>
      ${it.desc?`<div class="cd">${hl(it.desc)}</div>`:''}
      <div class="cf">
        ${c==='未分类'?'':`<span class="tag" style="${catColor(c).background};${catColor(c).color}">${esc(c)}</span>`}
        <span>${ago(it.created_at)}</span>
        <span class="sp">${icon('eye')}${it.visits||0}</span>
      </div>
    </div>`;
  }).join('') + `</div>` +
  (S.edit ? `<div style="padding:14px 0"><button class="btn" id="addRow">${icon('plus')}新增书签</button></div>` : '');
}

/* ---------- 底部条 ---------- */
function renderBars(){
  const bars = $('#bars');
  const parts = [];

  if(S.sel.size){
    parts.push(`<div class="bars"><span>已选 <b>${S.sel.size}</b> 项</span>
      <span class="sep"></span>
      <div class="cb">
        <button data-bar="cat">改分类</button>
        <button data-bar="rm" class="no">删除</button>
        <button data-bar="clr">取消</button>
      </div></div>`);
  }
  if(S.edit && dirtyCount()){
    parts.push(`<div class="bars"><span>已改动 <b>${dirtyCount()}</b> 项</span>
      <span class="sep"></span>
      <div class="cb">
        <button data-bar="add">${icon('plus')}新增</button>
        <button data-bar="undo">${icon('undo')}放弃</button>
        <button data-bar="save" class="ok">${icon('save')}保存</button>
      </div></div>`);
  } else if(S.edit){
    parts.push(`<div class="bars"><span>编辑模式</span><span class="sep"></span>
      <div class="cb"><button data-bar="add">${icon('plus')}新增一行</button><button data-bar="exit">完成</button></div></div>`);
  }
  if(!parts.length) parts.push(S.view==='table' && S.sort==='custom'
    ? `<div class="bars" style="background:rgba(28,28,30,.9)"><span>拖动行可调整顺序</span><span class="sep"></span><button data-bar="exit-sort">知道了</button></div>` : '');
  bars.innerHTML = parts.join('');
}

/* ================= 编辑逻辑 ================= */
function startEdit(){
  S.edit = true; S.dirty = {}; S.dels = []; S.news = [];
  S.sel.clear();
  render();
}
function exitEdit(force){
  if(!force && dirtyCount()){
    ask('放弃改动?', '有 ' + dirtyCount() + ' 项未保存的改动,退出将丢失。', '放弃', () => {
      S.edit = false; S.dirty = {}; S.dels = []; S.news = []; render();
    });
    return;
  }
  S.edit = false; S.dirty = {}; S.dels = []; S.news = []; render();
}
function addRow(){
  S.news.unshift({key:'n'+Date.now()+Math.random().toString(36).slice(2,6), title:'', url:'', desc:'', category: S.cat==='all'?'':S.cat});
  if(!S.edit) S.edit = true;
  render();
  const first = document.querySelector('tr.new input.cell');
  if(first) first.focus();
}
function setField(id, f, val){
  if(id.startsWith('n')){ const r = S.news.find(x => x.key === id); if(r) r[f] = val; }
  else { (S.dirty[id] = S.dirty[id] || {})[f] = val; }
  const tr = rowOf(id);
  if(tr && !id.startsWith('n')) tr.classList.add('dirty');
  renderBars();
}
async function saveAll(){
  // 校验:标题/网址不能为空
  const badRow = [...S.news.map(r => ({title:(r.title||'').trim(), url:(r.url||'').trim(), key:r.key}))]
    .concat(Object.entries(S.dirty).map(([id,f]) => {
      const it = S.items.find(x => x.id === id) || {};
      return { title: String(f.title !== undefined ? f.title : (it.title||'')).trim(),
               url: String(f.url !== undefined ? f.url : (it.url||'')).trim(), key:id };
    }))
    .find(r => !r.title || !r.url);
  if(badRow){
    toast('有行的标题或网址为空,请先补全', 1);
    render();
    const tr = rowOf(badRow.key);
    const inp = tr && tr.querySelector('input.cell.bad') || (tr && tr.querySelector('input.cell'));
    if(inp) inp.focus();
    return;
  }

  const updates = Object.entries(S.dirty).map(([id, f]) => {
    const it = S.items.find(x => x.id === id) || {};
    return { id, title:f.title!==undefined?f.title:it.title, url:f.url!==undefined?f.url:it.url,
             desc:f.desc!==undefined?f.desc:(it.desc||''), category:f.category!==undefined?f.category:(it.category||'') };
  }).filter(u => String(u.title||'').trim() && String(u.url||'').trim());

  const creates = S.news
    .map(r => ({title:(r.title||'').trim(), url:(r.url||'').trim(), desc:(r.desc||'').trim(), category:(r.category||'').trim()}))
    .filter(r => r.title && r.url);

  const dropped = S.news.length - creates.length;
  const res = await api('save_table', { updates, creates, deletes: S.dels });
  if(res.status !== 'success'){ toast(res.msg || '保存失败', 1); return; }
  toast('已保存 · 改 ' + (res.updated||0) + ' 增 ' + (res.created||0) + ' 删 ' + (res.deleted||0) + (dropped?' · 忽略空行 '+dropped:''));
  S.edit = false; S.dirty = {}; S.dels = []; S.news = [];
  await load();
}
async function quickRowEdit(id){
  S.edit = true; S.dirty = {}; S.dels = []; S.news = [];
  render();
  const tr = rowOf(id);
  if(tr){ tr.scrollIntoView({block:'center'}); const i = tr.querySelector('input.cell'); if(i) i.focus(); }
}

/* ================= 弹窗 ================= */
function ask(title, msg, okText, onOk, danger){
  const ov = document.createElement('div');
  ov.className = 'overlay';
  ov.innerHTML = `<div class="box"><h3>${esc(title)}</h3><p>${esc(msg||'')}</p>
    <div class="end"><button class="btn" data-x>取消</button>
    <button class="btn ${danger?'danger':'primary'}" data-ok>${esc(okText||'确定')}</button></div></div>`;
  document.body.appendChild(ov);
  const close = () => ov.remove();
  ov.onclick = e => { if(e.target === ov) close(); };
  ov.querySelector('[data-x]').onclick = close;
  ov.querySelector('[data-ok]').onclick = () => { close(); onOk && onOk(); };
  return ov;
}
function prompt2(title, msg, val, onOk){
  const ov = document.createElement('div');
  ov.className = 'overlay';
  ov.innerHTML = `<div class="box"><h3>${esc(title)}</h3><p>${esc(msg||'')}</p>
    <div class="field"><input id="pv" list="catList" value="${esc(val||'')}" placeholder="分类名称"></div>
    <div class="end"><button class="btn" data-x>取消</button><button class="btn primary" data-ok>应用</button></div></div>`;
  document.body.appendChild(ov);
  const inp = ov.querySelector('#pv');
  setTimeout(() => inp.focus(), 30);
  const done = () => { const v = inp.value.trim(); if(!v) return; ov.remove(); onOk(v); };
  inp.onkeydown = e => { if(e.key === 'Enter') done(); if(e.key === 'Escape') ov.remove(); };
  ov.onclick = e => { if(e.target === ov) ov.remove(); };
  ov.querySelector('[data-x]').onclick = () => ov.remove();
  ov.querySelector('[data-ok]').onclick = done;
}

/* ================= 数据 ================= */
async function load(){
  try {
    S.items = await api('get');
    S.failed = false;
  } catch(e){ S.failed = true; }
  S.loading = false;
  render();
}

/* ================= 交互绑定 ================= */
function bindMain(){
  const main = $('#main');

  main.querySelectorAll('[data-act="open"]').forEach(a => {
    a.addEventListener('click', e => {
      const hostEl = a.closest('[data-id]');
      const it = hostEl && S.items.find(x => x.id === hostEl.dataset.id);
      if(!it || S.edit){ e.preventDefault(); return; }
      it.visits = (it.visits||0) + 1;
      api('visit', {id: it.id}).catch(() => { it.visits = Math.max(0,(it.visits||1)-1); });
    });
  });

  main.querySelectorAll('[data-act]').forEach(btn => {
    const act = btn.dataset.act;
    if(act === 'open') return;
    btn.addEventListener('click', e => {
      e.preventDefault(); e.stopPropagation();
      const host = btn.closest('[data-id],[data-key]');
      const id = host ? (host.dataset.id || host.dataset.key) : null;
      const it = id && S.items.find(x => x.id === id);

      if(act === 'sel'){
        S.sel.has(id) ? S.sel.delete(id) : S.sel.add(id);
        btn.classList.toggle('on', S.sel.has(id));
        const all = visible();
        const ca = $('#chkAll');
        if(ca) ca.classList.toggle('on', all.length && all.every(x => S.sel.has(x.id)));
        renderBars();
        return;
      }
      if(act === 'pin'){
        if(!it) return;
        const v = !it.pinned; it.pinned = v;
        api('pin', {id, pinned:v}).then(r => { if(r.status!=='success') throw 0; toast(v?'已置顶':'已取消置顶'); render(); })
          .catch(() => { it.pinned = !v; toast('操作失败',1); render(); });
        return;
      }
      if(act === 'copy'){
        if(!it) return;
        navigator.clipboard.writeText(it.url).then(() => toast('已复制链接'))
          .catch(() => { const ta = document.createElement('textarea'); ta.value = it.url; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove(); toast('已复制链接'); });
        return;
      }
      if(act === 'row-edit'){ if(it) quickRowEdit(it.id); return; }
      if(act === 'del'){
        if(!it) return;
        ask('删除书签', '「' + it.title + '」将被永久删除。', '删除', async () => {
          await api('batch_delete', {ids:[it.id]});
          toast('已删除'); load();
        }, true);
        return;
      }
      if(act === 'drop'){
        if(!id) return;
        if(id.startsWith('n')){ S.news = S.news.filter(x => x.key !== id); }
        else {
          const i = S.dels.indexOf(id);
          i > -1 ? S.dels.splice(i,1) : S.dels.push(id);
        }
        render();
        return;
      }
      if(act === 'insert'){
        if(!id) return;
        const r = S.news.find(x => x.key === id);
        S.news = S.news.filter(x => x.key !== id);
        if(r) S.news.unshift({key:'n'+Date.now()+Math.random().toString(36).slice(2,6), title:'', url:'', desc:'', category:r.category||''});
        render();
        return;
      }
    });
  });

  // 单元格输入
  main.querySelectorAll('input.cell').forEach(inp => {
    const host = inp.closest('[data-id],[data-key]');
    if(!host) return;
    const id = host.dataset.id || host.dataset.key;
    const f = inp.dataset.f;
    inp.addEventListener('input', () => {
      setField(id, f, inp.value);
      inp.classList.toggle('bad', (f==='title'||f==='url') && !inp.value.trim());
      if(f === 'url'){ // 网址一变,图标跟着预览
        const img = inp.closest('td').querySelector('.fav img');
        if(img){ img.removeAttribute('data-fail'); img.src = favOf(inp.value); }
      }
    });
    inp.addEventListener('keydown', e => {
      if(e.key === 'Enter'){
        e.preventDefault();
        const tr = inp.closest('tr');
        const next = tr.nextElementSibling;
        const nInp = next && next.querySelector('input.cell');
        if(nInp) nInp.focus(); else addRow();
      }
      if(e.key === 'Escape'){ e.preventDefault(); inp.blur(); }
    });
  });

  // 全选
  const ca = $('#chkAll');
  if(ca) ca.onclick = () => {
    const all = visible();
    const on = all.length && all.every(x => S.sel.has(x.id));
    all.forEach(x => on ? S.sel.delete(x.id) : S.sel.add(x.id));
    render();
  };

  const ar = $('#addRow');
  if(ar) ar.onclick = addRow;

  // 拖拽排序
  if(S.sort === 'custom' && !S.edit){
    let dragId = null;
    main.querySelectorAll('tr[data-id]').forEach(tr => {
      tr.addEventListener('dragstart', () => { dragId = tr.dataset.id; tr.classList.add('drag'); });
      tr.addEventListener('dragend', () => tr.classList.remove('drag'));
      tr.addEventListener('dragover', e => e.preventDefault());
      tr.addEventListener('drop', e => {
        e.preventDefault();
        if(!dragId || dragId === tr.dataset.id) return;
        const vis = visible().map(x => x.id);
        const from = vis.indexOf(dragId), to = vis.indexOf(tr.dataset.id);
        vis.splice(to, 0, vis.splice(from,1)[0]);
        const map = {}; S.items.forEach(x => map[x.id] = x);
        const rest = S.items.map(x => x.id).filter(id => !vis.includes(id));
        S.items = rest.concat(vis).map(id => map[id]).filter(Boolean);
        dragId = null;
        render();
        api('reorder', {ids: S.items.map(x => x.id)}).catch(() => toast('排序保存失败',1));
      });
    });
  }
}

/* ---------- 顶栏 / 底部条事件 ---------- */
$('#cats').addEventListener('click', e => {
  const b = e.target.closest('.cat');
  if(!b) return;
  S.cat = b.dataset.cat;
  render();
});
$('#q').addEventListener('input', e => {
  S.q = e.target.value;
  $('#clr').hidden = !S.q;
  render();
});
$('#clr').onclick = () => { S.q=''; $('#q').value=''; $('#clr').hidden = true; render(); };

$('#seg').addEventListener('click', e => {
  const b = e.target.closest('button'); if(!b) return;
  S.view = b.dataset.view;
  try { localStorage.setItem('bm_view', S.view); } catch(err){}
  render();
});
$('#sort').value = S.sort;
$('#sort').onchange = e => {
  S.sort = e.target.value;
  try { localStorage.setItem('bm_sort', S.sort); } catch(err){}
  render();
};
$('#editBtn').onclick = () => S.edit ? exitEdit() : startEdit();
$('#addBtn').onclick = () => { if(!S.edit) startEdit(); addRow(); };

$('#moreBtn').onclick = e => {
  e.stopPropagation();
  const m = $('#moreMenu');
  m.hidden = !m.hidden;
  $('#moreBtn').classList.toggle('on', !m.hidden);
};
document.addEventListener('click', () => { $('#moreMenu').hidden = true; $('#moreBtn').classList.remove('on'); });
$('#moreMenu').onclick = e => {
  const b = e.target.closest('button[data-act]');
  if(!b) return;
  if(b.dataset.act === 'random'){
    const l = visible(); if(!l.length) return;
    window.open(l[Math.floor(Math.random()*l.length)].url, '_blank');
  }
  if(b.dataset.act === 'export'){
    const blob = new Blob([JSON.stringify(S.items, null, 2)], {type:'application/json'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'bookmarks-' + new Date().toISOString().slice(0,10) + '.json';
    a.click(); URL.revokeObjectURL(a.href);
    toast('已导出 ' + S.items.length + ' 条');
  }
};
$('#impFile').onchange = async e => {
  const f = e.target.files[0]; if(!f) return;
  try {
    const arr = JSON.parse(await f.text());
    if(!Array.isArray(arr)) throw 0;
    const r = await api('import', {items: arr});
    await load();
    toast('导入完成 · 新增 ' + (r.added||0) + (r.skipped ? ' · 跳过重复 ' + r.skipped : ''));
  } catch(err){ toast('导入失败:文件格式不正确', 1); }
  e.target.value = '';
};

$('#bars').addEventListener('click', e => {
  const b = e.target.closest('button[data-bar]'); if(!b) return;
  const a = b.dataset.bar;
  if(a === 'save') saveAll();
  if(a === 'undo'){ S.dirty = {}; S.dels = []; S.news = []; render(); toast('已放弃改动'); }
  if(a === 'exit') exitEdit();
  if(a === 'add') addRow();
  if(a === 'clr'){ S.sel.clear(); render(); }
  if(a === 'rm'){
    const ids = [...S.sel];
    ask('删除 ' + ids.length + ' 条书签', '此操作无法撤销。', '删除', async () => {
      await api('batch_delete', {ids});
      S.sel.clear(); toast('已删除 ' + ids.length + ' 条'); load();
    }, true);
  }
  if(a === 'cat'){
    prompt2('批量修改分类', '应用到已选的 ' + S.sel.size + ' 条书签', '', async v => {
      await api('batch_category', {ids:[...S.sel], category:v});
      S.sel.clear(); toast('已更新分类'); load();
    });
  }
  if(a === 'exit-sort'){ S.sort = 'new'; $('#sort').value = 'new'; render(); }
});

/* ---------- 离开保护:有未保存改动时拦一下 ---------- */
window.addEventListener('beforeunload', e => {
  if(S.edit && dirtyCount()){ e.preventDefault(); e.returnValue = ''; }
});

/* ---------- 快捷键 ---------- */
document.addEventListener('keydown', e => {
  const t = (e.target.tagName || '').toLowerCase();
  const typing = t === 'input' || t === 'textarea' || t === 'select';
  if((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's' && S.edit){ e.preventDefault(); saveAll(); return; }
  if(e.key === 'Escape'){
    if(document.querySelector('.overlay')) return;
    if(S.edit){ exitEdit(); return; }
    if(S.sel.size){ S.sel.clear(); render(); }
    return;
  }
  if(typing) return;
  if(e.key === '/'){ e.preventDefault(); $('#q').focus(); }
  if(e.key.toLowerCase() === 'n'){ e.preventDefault(); $('#addBtn').click(); }
});

load();
</script>
<datalist id="catList"></datalist>
</body>
</html>
