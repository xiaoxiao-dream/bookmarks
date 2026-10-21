# 代码分析报告

对象：`index.php`（单文件书签管理器）
范围：架构、数据流、API 设计、安全性、健壮性、性能
结论：架构合理，已修复 2 个发布阻塞级缺陷

---

## 1. 架构总览

```
┌─────────────────────────────────────────────────┐
│  index.php                                      │
│                                                 │
│  ┌───────────┐   ┌───────────┐   ┌───────────┐  │
│  │ 配置区     │ → │ 认证门     │ → │ 存储层     │  │
│  │ password  │   │ session   │   │ load/save │  │
│  │ salt      │   │ 登录页     │   │ 原子写入   │  │
│  └───────────┘   └───────────┘   └───────────┘  │
│                                        ↓        │
│  ┌────────────────────────────────────────────┐ │
│  │ 路由：?action= 分发 11 个 API 分支           │ │
│  └────────────────────────────────────────────┘ │
│                                        ↓        │
│  ┌────────────────────────────────────────────┐ │
│  │ 页面：内联 CSS + 内联 JS（~630 行）          │ │
│  └────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────┘
                            ↓
                    bookmarks.json
```

**执行顺序**（关键）：

1. `session_set_cookie_params()` 加固 cookie，再 `session_start()`
2. 校验 `hash_equals($token, $_SESSION['bm_auth'])`
3. 未认证 → 渲染登录页 → `exit`
4. 已认证 → 定义存储层函数
5. `?action=` 有值 → 走 API 分支 → `jexit`
6. `?action=` 为空 → 渲染主页面

认证先于一切数据操作，API 分支全部在认证之后。

---

## 2. 数据流

### 读路径

```
前端 load()
  → fetch('?action=get')
  → loadData($dataFile)
      → file_exists 检查
      → fopen('r')
      → flock(LOCK_SH) + stream_get_contents($fp)   ← 锁和读同一句柄
      → flock(LOCK_UN) + fclose
      → json_decode → 非数组则返回 []
      → 逐条补默认值（category/desc/pinned/visits）
  → 渲染
```

### 写路径

```
前端 saveAll()
  → 校验空标题/空网址
  → fetch('?action=save_table', {updates, creates, deletes})
  → loadData() 读全量
      → 按 deletes 过滤
      → 按 updates 逐条改（normFields 归一 + 长度截断）
      → creates 转新对象 array_merge 到头部
  → saveData()
      → json_encode
      → file_put_contents($tmp, ..., LOCK_EX)        ← 写临时文件
      → chmod 跟随旧文件权限
      → rename($tmp, $filePath)                      ← 原子替换
  → saveOrDie() 失败则 500
```

---

## 3. 已修复的缺陷

### 🔴 P0-1 · 数据文件可直接下载

**原始问题**：`$dataFile = __DIR__ . '/bookmarks.json';`，而 `__DIR__` 就是 webroot。Web 服务器按静态文件规则直接返回，完全绕过 PHP 认证：

```bash
curl http://target/bookmarks.json
# → 返回全部书签 JSON
```

密码保护对这条路径**完全无效**。

**处理**：三层防御。

1. 项目自带 `.htaccess`：

```apache
<Files "bookmarks.json">
    Require all denied
</Files>
```

2. README 的 Nginx 配置里显式 `location = /bookmarks.json { deny all; }`
3. 配置区注释提示可把 `$dataFile` 指向 webroot 之外：`dirname(__DIR__) . '/data/bookmarks.json'`

> ⚠️ 代码无法自我防护这一条——静态文件由 Web 服务器决定。**部署时必须确认 curl 返回 403。**

---

### 🔴 P0-2 · `saveData()` 截断先于加锁 → 并发读空 → 整库被覆盖

**原始代码**：

```php
function saveData($filePath, $data)
{
    $fp = fopen($filePath, 'w');   // ← ① 这一刻文件已被清空为 0 字节
    flock($fp, LOCK_EX);           // ← ② 才拿到排他锁
    fwrite($fp, json_encode(...));
    ...
}
```

**问题链**：

1. `fopen('w')` 打开瞬间执行 `truncate(0)`
2. 此刻另一个请求的 `loadData()` 读到 0 字节
3. `json_decode('')` → `null` → `?: []` → 返回空数组
4. 前端显示「还没有书签」，用户以为数据没了，点新建 → 保存
5. `saveData` 用 1 条数据覆盖了整个文件

**后果**：**全部书签永久丢失**。本项目最严重的缺陷。

**修复**（临时文件 + 原子 `rename()`）：

```php
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

    if (!@rename($tmp, $filePath)) {   // 原子替换
        @unlink($tmp);
        return false;
    }
    return true;
}
```

**原理**：同分区 `rename()` 是原子系统调用。读者要么看到完整的旧文件，要么看到完整的新文件，**不存在中间态**。文件永远不会处于「空」或「半截」。

**附带修复**：新增 `saveOrDie()`，写失败返回 500 而不是静默成功：

```php
function saveOrDie($filePath, $data)
{
    if (!saveData($filePath, $data)) {
        jexit(['status' => 'error', 'msg' => '写入失败，请检查目录权限'], 500);
    }
}
```

---

### 🟠 P1-1 · `loadData()` 的共享锁加在错误的句柄上

**原始代码**：

```php
$fp = fopen($filePath, 'r');
flock($fp, LOCK_SH);
$raw = file_get_contents($filePath);   // ← 另开文件描述符读取
flock($fp, LOCK_UN);
```

锁加在 `$fp` 上，实际读取用的是 `file_get_contents` 内部新开的 fd。语义上是错的。

**修复**：锁和读用同一句柄，且 `json_decode` 结果不是数组时返回空数组而非报错：

```php
$fp = @fopen($filePath, 'r');
if (!$fp) return [];

$raw = '';
if (flock($fp, LOCK_SH)) {
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
}
fclose($fp);

$data = json_decode($raw, true);
if (!is_array($data)) return [];   // 空文件 / 损坏 JSON 都退化为空，绝不覆盖
```

---

### 🟠 P1-2 · 认证强度不足

**原始问题**：

| 问题 | 原代码 |
|---|---|
| 密码明文 `===` 比较，非常量时间 | `if ($_POST['passwd'] === $accessPassword)` |
| session 令牌是裸 `md5($password)`，无盐 | `$_SESSION['bm_auth'] = md5($accessPassword)` |
| 登录成功未 `session_regenerate_id` | 无 |
| session cookie 未加固 | 无 |
| 无登录限速 | 无 |

**修复**：

```php
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
```

Cookie 加固：

```php
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => $secureCookie,   // HTTPS 下为 true
]);
session_start();
```

---

### 🟠 P2-1 · 输入无长度上限

**原始问题**：所有分支直接 `trim()` 后落库。单条 `title` 可以塞几十 MB。因为每次请求都全量 `json_decode`，文件膨胀后会直接打爆 `memory_limit`。

**修复**：新增两个 helper，所有入口统一走它们：

```php
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
```

`import` 分支额外加了条数上限：

```php
$items = array_slice($items, 0, 5000);   // 单次导入上限
```

---

### 🟡 P2-2 · `normUrl()` 不校验 scheme

**原始代码**：

```php
if (!preg_match('#^[a-zA-Z][a-zA-Z\d+\-.]*://#', $url)) $url = 'https://' . $url;
return $url;
```

`javascript:alert(1)` 因为没有 `://` 会被补成 `https://javascript:alert(1)`（不可利用），但 `data://` 这类带 `://` 的 scheme 会被原样保留。

**修复**：白名单 + host 必须存在：

```php
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
```

---

### 🟡 P3-1 · 前端未保存改动无离开保护

**原始问题**：`S.dirty` / `S.news` / `S.dels` 只在 `Esc` 或点「退出编辑」时二次确认。直接关标签页 / 刷新 → 改动全丢。

**修复**：

```js
window.addEventListener('beforeunload', e => {
  if(S.edit && dirtyCount()){ e.preventDefault(); e.returnValue = ''; }
});
```

---

### 🟡 P3-2 · 登录页 `$loginErr` 未转义

**原始代码**：

```php
<?php if(!empty($loginErr)) echo '<div class="err">'.$loginErr.'</div>'; ?>
```

`$loginErr` 目前是硬编码常量，无实际风险。但加了转义防止将来被改成用户输入时埋雷：

```php
<?php if(!empty($loginErr)) echo '<div class="err">'.htmlspecialchars($loginErr, ENT_QUOTES, 'UTF-8').'</div>'; ?>
```

---

## 4. 未修复 / 已知限制

以下问题在单用户个人工具的量级下可接受，故保留：

| 编号 | 问题 | 影响 | 建议 |
|---|---|---|---|
| L-1 | 无乐观锁版本号 | 两标签页同时保存，后写覆盖先写 | 数据改 `{rev, items}`，保存前比对 rev，冲突返回 409 |
| L-2 | favicon 依赖第三方 `api.iowen.cn` | 向第三方暴露全部书签域名 | 服务端拉取 + 本地缓存到 `cache/favicon/<md5(host)>.png` |
| L-3 | `uniqid('', true)` 做 id | 基于微秒时间戳，可预测 | 多用户场景换 `bin2hex(random_bytes(16))` |
| L-4 | 无 CSRF token | 风险低（JSON Content-Type 触发 preflight，无 CORS 头则被拦） | 加 token 更稳 |
| L-5 | 全量读写 + 全量渲染 | > 5000 条明显卡顿 | SQLite + 分页 |
| L-6 | 无回收站 | 删除即永久删除 | 软删除 + 定时清理 |
| L-7 | `get` 分支不检查 REQUEST_METHOD | 任意方法都能读 | 加 `$_SERVER['REQUEST_METHOD'] === 'GET'` |
| L-8 | `esc()` 不转义单引号 | 当前所有属性都用双引号，安全 | 改单引号属性前先补 |

---

## 5. 安全性评估总结

| 攻击面 | 状态 |
|---|---|
| 存储型 XSS | ✅ 防护到位（`esc()` 全量转义 + `hl()` 先转义后插 `<mark>`） |
| 反射型 XSS | ✅ 无用户输入直接回显；登录错误信息已转义 |
| SQL 注入 | ✅ 无数据库 |
| 路径穿越 | ✅ 无用户可控路径 |
| 认证绕过（API） | ✅ API 分支全部在认证之后 |
| 认证绕过（静态文件） | ✅ 已由 `.htaccess` + 部署文档封堵（**需部署时验证**） |
| CSRF | 🟡 风险低，未加 token |
| 暴力破解 | 🟡 已加递增延迟，无硬锁定 |
| 会话固定 | ✅ `session_regenerate_id(true)` |
| 数据完整性 | ✅ 原子写入，无截断窗口 |
| DoS（内存） | ✅ 输入长度与导入条数均有限制 |

---

## 6. 性能评估

| 场景 | 表现 |
|---|---|
| < 500 条 | 无感 |
| 500 ~ 2000 条 | 首屏可感知（全量 `json_decode` + 全量 DOM 渲染） |
| > 5000 条 | 明显卡顿 |

**优化方向**：

1. 存储换 SQLite（PDO + `title/url` 索引 + 分页查询）
2. 前端虚拟滚动或分页
3. 搜索下推到服务端
4. 全量重绘改增量 DOM patch

---

## 7. 部署自检清单

上线前逐条打勾：

- [ ] `php -l index.php` 无语法错误
- [ ] `curl http://host/bookmarks.json` 返回 **403**
- [ ] 未登录 `curl http://host/?action=get` 返回登录页 HTML，不吐数据
- [ ] 错密码连试 3 次 → 每次延迟 1s；第 11 次起 3s
- [ ] 改 `$authSalt` 后旧 session 立即失效
- [ ] 两个标签页同时保存 → 不出现空库
- [ ] 输入 500 字标题 → 落库被截到 200
- [ ] `bookmarks.json` 不在 git 索引里（`git status` 干净）
- [ ] HTTPS 下 cookie 带 `Secure`

---

## 8. 结论

架构选型合理——单文件 + JSON 存储对「个人书签」这个量级是恰当的取舍，换数据库反而是过度设计。

代码质量整体中上：命名清晰、函数职责单一、前端 XSS 防护完整、交互细节（脏标记、快捷键、脏数据确认）考虑周到。

**两个发布阻塞级缺陷（P0-1 静态文件暴露、P0-2 截断式写入）已修复。** 前者靠部署层（`.htaccess` / nginx）封堵，后者靠临时文件 + 原子 `rename()` 从代码层根除。剩余的 L-1 ~ L-8 属于量级可接受的取舍，写进了 Roadmap。
