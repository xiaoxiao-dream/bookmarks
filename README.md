# 书签 · 单文件自托管书签管理器

> 一个 PHP 文件，一个 JSON 文件。密码保护、表格/卡片双视图、批量编辑、拖拽排序、JSON 导入导出。没有数据库，没有 Composer，没有 CDN。

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-blue)
![Single File](https://img.shields.io/badge/single--file-index.php-success)
![No Dependencies](https://img.shields.io/badge/dependencies-0-brightgreen)

---

## 特性

### 访问控制
- 单密码保护，`session` 维持登录态
- 密码 + 盐做 SHA-256 派生令牌，改密码/改盐后旧 session 立即失效
- `hash_equals()` 常量时间比较，登录成功 `session_regenerate_id(true)` 防会话固定
- 连续失败递增延迟（1s → 3s）
- Cookie 加固：`HttpOnly` / `SameSite=Lax` / HTTPS 下 `Secure`
- 未登录时只渲染登录页，API 分支不执行

### 双视图
- **表格视图** — 适合大批量整理，支持行内编辑、全选、批量操作
- **卡片视图** — 适合日常浏览，悬停显示操作按钮
- 视图偏好写入 `localStorage`，下次打开保持

### 编辑体验
- **行内编辑** — 点「批量编辑」直接改单元格，`Tab` 跳下一格、`Enter` 跳下一行、`Ctrl/⌘+S` 保存、`Esc` 退出
- **脏数据标记** — 改动行高亮，底部悬浮条显示改动计数
- **新增行 / 标记删除** — 全部走前端暂存，一次 `save_table` 请求原子提交
- **未保存保护** — 有改动时退出会二次确认；直接关标签页也会被 `beforeunload` 拦住

### 数据管理
- 分类自动聚合，分类色按名称哈希生成
- 置顶（分类内优先排序）
- 访问计数（点击链接时上报）
- 5 种排序：最新 / 最早 / 标题 / 访问量 / 自定义
- 自定义顺序支持**拖拽排序**
- 批量改分类、批量删除
- JSON 导入导出，导入按 URL 去重
- 全文搜索（标题 / 描述 / 网址 / 分类），命中关键词高亮

### 工程细节
- **原子写入** — 临时文件 + `rename()`，读者永远看到完整的旧版本或完整的新版本
- **输入上限** — 标题 200 / 描述 500 / 分类 50 字符，导入单次上限 5000 条
- **URL 白名单** — 仅允许 `http` / `https` / `ftp` / `ftps`
- 全部图标内联 SVG sprite，**零外部资源请求**
- 苹果风视觉：`backdrop-filter` 毛玻璃顶栏、`-apple-system` 字体栈、`tabular-nums` 数字对齐
- 响应式，720px 以下自动折叠
- 快捷键：`/` 聚焦搜索、`n` 新建、`Esc` 逐级退出

---

## 快速开始

### 环境要求

| 项目 | 要求 |
|---|---|
| PHP | >= 7.4（推荐 8.1+） |
| 扩展 | 无特殊扩展（`json`、`session`、`mbstring` 为常见内置） |
| 数据库 | 不需要 |
| Web 服务器 | Apache / Nginx / Caddy 任意 |

### 部署

```bash
git clone https://github.com/<your-name>/bookmarks.git
cd bookmarks
```

1. 把目录放到 webroot，或新建虚拟主机指向该目录
2. 确保目录可写（PHP 进程用户需要写 `bookmarks.json`）：

```bash
chmod 755 .
touch bookmarks.json && chmod 664 bookmarks.json
```

3. 编辑 `index.php` 顶部配置区：

```php
$accessPassword = "CHANGE_ME_STRONG_PASSWORD";   // 12 位以上随机密码
$authSalt       = "CHANGE_ME_RANDOM_SALT";       // 随机串；改动后所有旧 session 立即失效
$dataFile       = __DIR__ . '/bookmarks.json';   // 建议移出 webroot
```

4. 浏览器访问，输入密码进入

### Nginx 示例

```nginx
server {
    listen 80;
    server_name bookmarks.example.com;
    root /var/www/bookmarks;
    index index.php;

    # ★ 关键：禁止直接访问数据文件
    location = /bookmarks.json { deny all; }
    location ~ /\. { deny all; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

### Apache 示例

项目自带 `.htaccess`，或手动加：

```apache
<Files "bookmarks.json">
    Require all denied
</Files>
```

---

## 目录结构

```
bookmarks/
├── index.php          # 全部逻辑：认证 + API + 页面
├── bookmarks.json     # 数据文件（首次访问自动创建，勿提交到 git）
├── .htaccess          # 阻止直接访问数据文件
├── README.md
├── LICENSE
└── docs/
    └── ANALYSIS.md    # 代码分析 / 已知问题 / 补丁
```

---

## 数据模型

`bookmarks.json` 是一个对象数组：

```json
[
  {
    "id": "65f1c3a9b2e4f",
    "title": "GitHub",
    "url": "https://github.com",
    "desc": "代码托管平台",
    "category": "开发",
    "created_at": 1700000000,
    "pinned": false,
    "visits": 42
  }
]
```

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | string | `uniqid('', true)` 生成，全局唯一 |
| `title` | string | 标题，必填，上限 200 字符 |
| `url` | string | 网址，必填；无协议时自动补 `https://` |
| `desc` | string | 描述，可空，上限 500 字符 |
| `category` | string | 分类，空值归一为 `默认`，上限 50 字符 |
| `created_at` | int | Unix 时间戳（秒） |
| `pinned` | bool | 是否置顶 |
| `visits` | int | 访问次数 |

---

## API

所有接口通过 `?action=<name>` 分发，POST 请求体为 JSON，响应为 JSON。

认证：需要有效的 session，未登录访问 `?action=` 会走到未知操作分支返回 404。

| action | 方法 | 请求体 | 响应 |
|---|---|---|---|
| `get` | GET | — | `Bookmark[]` |
| `save_table` | POST | `{updates:[], creates:[], deletes:[]}` | `{status, updated, created, deleted}` |
| `add` | POST | `{title, url, desc?, category?}` | `{status, data}` |
| `edit` | POST | `{id, title, url, desc?, category?, pinned?}` | `{status, found}` |
| `visit` | POST | `{id}` | `{status, visits}` |
| `pin` | POST | `{id, pinned}` | `{status, pinned}` |
| `batch_category` | POST | `{ids:[], category}` | `{status}` |
| `batch_delete` | POST | `{ids:[]}` | `{status}` |
| `delete` | POST | `{id}` | `{status}` |
| `reorder` | POST | `{ids:[]}` | `{status}` |
| `import` | POST | `{items:[]}` | `{status, added, skipped}` |

### 示例

```bash
# 登录（表单 POST，获取 session cookie）
curl -c cookies.txt -d "passwd=YOUR_PASSWORD" http://localhost/

# 读取全部
curl -b cookies.txt http://localhost/?action=get

# 新增
curl -b cookies.txt -H "Content-Type: application/json" \
     -d '{"title":"GitHub","url":"github.com","category":"开发"}' \
     http://localhost/?action=add
```

---

## 快捷键

| 键 | 作用 |
|---|---|
| `/` | 聚焦搜索框 |
| `n` | 新建书签 |
| `Tab` | 编辑态：下一单元格 |
| `Enter` | 编辑态：下一行（末行则新增） |
| `Ctrl/⌘ + S` | 编辑态：保存全部 |
| `Esc` | 逐级退出：弹窗 → 编辑态 → 选中态 |

---

## ⚠️ 安全须知

**这是个人自用工具，不是多用户系统。** 部署前务必确认：

1. **禁止直接访问数据文件** — `bookmarks.json` 默认在 webroot，`GET /bookmarks.json` 会直接下载全部内容。必须用上面的 Nginx/Apache 配置或 `.htaccess` 拦住，或者干脆把 `$dataFile` 指到 webroot 之外。
2. **必须用 HTTPS** — 密码以明文表单提交，HTTP 下可被中间人截获。
3. **改掉默认密码和盐** — `CHANGE_ME_STRONG_PASSWORD` / `CHANGE_ME_RANDOM_SALT` 只是占位。
4. **单密码无用户体系** — 没有注册、没有权限分级，不要拿它当团队共享服务。
5. **无账号锁定** — 只有递增延迟，没有硬锁定。请用 12 位以上随机密码。
6. **favicon 走第三方** — 默认从 `api.iowen.cn` 拉图标，会向第三方暴露你所有书签的域名。介意的话改成本地生成。

完整问题清单与补丁见 [`docs/ANALYSIS.md`](docs/ANALYSIS.md)。

---

## 已知限制

- **全量读写** — 每次请求都 `json_decode` / `json_encode` 整个文件，书签超过 ~5000 条会明显变慢
- **无版本号 / 乐观锁** — 两个标签页同时保存，后写覆盖先写
- **无分页** — 一次性渲染全部，几千条时首屏压力大
- **无回收站** — 删除即永久删除
- **单文件 1300+ 行** — 后端逻辑与前端混排，二次开发需要一点耐心

---

## Roadmap

- [ ] SQLite 存储后端（PDO，带索引）
- [ ] 乐观锁版本号，彻底消灭丢失更新
- [ ] 服务端 favicon 缓存，去掉第三方依赖
- [ ] 书签回收站
- [ ] 分页 / 虚拟滚动
- [ ] 多用户 + 权限
- [ ] 浏览器书签 HTML 格式导入
- [ ] 拆分 `api.php` / `index.php` / `assets/`

---

## 贡献

欢迎 PR。提交前请：

1. 跑一遍 `php -l index.php` 语法检查
2. 说明改动的动机与影响面
3. 涉及存储层的改动请附带并发/异常场景说明

---

## 许可

[MIT](LICENSE)
