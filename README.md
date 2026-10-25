# AISLE 系統架構

多功能、模組化的內部網頁系統。依「身份」(EE / PE) 決定每位使用者能看到哪些功能頁面，介面採用駭客任務 (The Matrix) 綠色終端機風格。

| 層級 | 技術 | 備註 |
|---|---|---|
| 後端 | PHP 5.4 | 不使用 5.5+ 語法；資料庫一律 PDO prepared statement |
| 資料庫 | MariaDB 5.5 / 10.x | InnoDB、utf8mb4 |
| 輔助腳本 | Python 2.7、Bash | 權限檢查、建帳號、備份、產生模組 |
| 前端 | HTML5、jQuery 1.12.4、原生 Canvas | 不需要 Node / 建置工具 |

---

## 1. 資料夾結構

```
AISLE/
├── .htaccess                  # 若 DocumentRoot 無法指向 public/，阻擋其他目錄
├── .gitignore
├── README.md
├── config/
│   ├── app.ini.example        # 主設定範本（DB 連線、安全參數）→ 複製成 app.ini
│   └── config.ini             # ★ 身份 → 可用頁面清單、頁面路由、註冊名單
├── core/                      # 框架核心（一般開發不需修改）
│   ├── bootstrap.php          # 載入類別、設定、Session
│   ├── auth_check.php         # ★ 共用權限檢查：所有功能頁面都經過這裡
│   ├── App.php                # 路由：index.php?m=<頁面>&a=<動作>，依 config.ini 的 route 載入
│   ├── Acl.php                # 讀 config.ini，判斷權限、產生選單
│   ├── Auth.php               # 登入 / 登出 / 失敗鎖定 / 閒置登出
│   ├── Registration.php       # 註冊驗證、依 EE 名單判定身份
│   ├── guard.php              # 頁面內細部判斷 require_role() / has_role() / csrf_field() …
│   ├── password_compat.php    # PHP 5.4 補上 password_hash() / password_verify()
│   ├── Password.php           # 包裝 password_hash()，成本讀 app.ini
│   ├── Security.php           # 安全亂數、常數時間比較、CSRF
│   ├── Database.php           # PDO 單例 + 查詢捷徑
│   ├── Controller.php         # 模組 Controller 基底類別
│   ├── View.php               # 純 PHP 樣板
│   └── helpers.php            # e() js() url() asset() audit() 等
├── modules/                   # 功能模組（route = "module:<資料夾>"）
│   ├── _template/             # 新模組樣板（_ 開頭不會被路由）
│   ├── dashboard/             # EE + PE
│   ├── shared_report/         # EE + PE（匯出限 PE，示範動作層級權限）
│   ├── profile/               # EE + PE（改密碼）
│   ├── ee_equipment/          # 只有 EE（示範 jQuery AJAX 刷新）
│   ├── pe_recipe/             # 只有 PE
│   └── admin_users/           # 只有 ADMIN（建帳號、停用、重設密碼）
│       ├── controller.php     #   class AdminUsersController extends Controller
│       └── views/index.php    #   該模組的畫面
├── pages/                     # 一般 PHP 頁面（route = "pages/xxx.php"）
│   └── ee_daily_check.php     #   範例：只有 EE
├── templates/
│   ├── layout.php             # 共用版面（頂部列、側邊選單、數位雨背景）
│   ├── error.php
│   └── auth/                  # 登入、註冊頁面樣板
├── public/                    # ★ Web Server DocumentRoot 指向這裡
│   ├── index.php              # 功能模組入口
│   ├── login.php              # 登入（GET 顯示 / POST 驗證，支援 AJAX）
│   ├── register.php           # 註冊（含帳號即時檢查）
│   ├── logout.php             # 登出（需 CSRF token）
│   ├── legacy/pe_notice.php   # 獨立頁面範例（route = "public/..."），第一行 require auth_check.php
│   ├── .htaccess
│   └── assets/
│       ├── css/matrix.css     # 駭客任務主題
│       ├── js/matrix-rain.js  # 數位雨 Canvas 動畫
│       ├── js/app.js          # jQuery 共用：CSRF、打字機效果、時鐘
│       ├── js/auth.js         # 登入 / 註冊的前端驗證與非同步送出
│       └── vendor/            # jquery-1.12.4.min.js（執行 fetch_vendor.sh 下載）
├── scripts/
│   ├── python/check_config.py # 檢查 config.ini：身份、頁面、route 檔案、EE 名單
│   └── shell/
│       ├── _common.sh         # 共用函式（讀 INI、產生暫存 mysql 設定）
│       ├── create_user.sh     # 建立使用者（互動輸入密碼）
│       ├── new_module.sh      # 由樣板產生新模組並登記到 config.ini
│       ├── backup_db.sh       # mysqldump 備份 + 自動清除舊檔
│       └── fetch_vendor.sh    # 下載 jQuery
├── sql/
│   ├── schema.sql             # 建表
│   └── seed.sql               # 初始管理員
├── storage/
│   ├── logs/                  # PHP 錯誤紀錄（需可寫入）
│   └── backups/               # 資料庫備份
└── docs/screenshots/          # 介面截圖
```

只有 `public/` 對外開放，`config/`、`core/`、`modules/`、`pages/` 都在網站根目錄之外，設定檔與原始碼不會被直接下載。

---

## 2. 資料庫

`sql/schema.sql` 建立三張表：

| 資料表 | 用途 |
|---|---|
| `users` | 帳號 `account`、密碼雜湊 `password_hash`、身份 `role`、啟用狀態、失敗次數與鎖定時間、強制改密碼旗標、最後登入時間與 IP |
| `login_logs` | 每次登入嘗試（成功或失敗原因），供稽核與暴力破解追查 |
| `audit_logs` | 模組操作紀錄，例如匯出報表、建立帳號、重設密碼 |

設計重點：

- **`role` 用 VARCHAR 而非 ENUM**：新增身份只要改 `config.ini`，不必 `ALTER TABLE`。
- **`password_hash` 留 255 長度**：目前 bcrypt 固定 60 字元，保留未來更換演算法的空間。
- **`created_at` 用 DATETIME 由程式寫入**：MariaDB 5.5 一張表只能有一個 TIMESTAMP 使用 `CURRENT_TIMESTAMP`，這個位置留給 `updated_at`。
- 有索引的 VARCHAR 欄位長度 ≤ 191，避免舊版 InnoDB 在 utf8mb4 下超過 767 bytes 索引上限。

### 密碼雜湊（PHP 5.4 的做法）

`password_hash()` / `password_verify()` 是 PHP 5.5 才有的函式。`core/password_compat.php` 在函式不存在時補上同名函式：以 `crypt()` 搭配 `$2y$` bcrypt（PHP ≥ 5.3.7 支援），鹽來自 `openssl_random_pseudo_bytes`，驗證時使用常數時間比較。因此程式碼照標準 API 寫 `password_hash($pw, PASSWORD_BCRYPT)`，在 PHP 5.4 與 5.5+ 都能執行，雜湊格式也完全相同；日後升級 PHP 只要刪掉這個檔案，舊密碼不需重設。

---

## 3. 身份 → 頁面權限與路由（`config/config.ini` + `core/auth_check.php`）

### config.ini

```ini
[roles]
EE    = "EE 工程師"
PE    = "PE 工程師"
ADMIN = "系統管理員"

; ★ 身份 → 可用頁面清單。同一頁面同時列在 EE 與 PE 就是兩者都能看；"*" = 全部
[access]
EE    = "dashboard, shared_report, ee_equipment, ee_daily_check, profile"
PE    = "dashboard, shared_report, pe_recipe, pe_notice, profile"
ADMIN = "*"

; ★ 頁面與路由
[page.shared_report]
title = "共用報表 // REPORTS"
route = "module:shared_report"        ; modules/shared_report/controller.php
action.export = "PE"                  ; EE、PE 都能看，只有 PE 能匯出

[page.ee_daily_check]
title = "每日點檢 // DAILY CHECK"
route = "pages/ee_daily_check.php"    ; 一般 PHP 頁面

[page.pe_notice]
title = "製程公告 // NOTICE"
route = "public/legacy/pe_notice.php" ; 既有的獨立頁面
```

`route` 有三種寫法：

| route | 程式位置 | 網址 | 適合 |
|---|---|---|---|
| `module:<資料夾>` | `modules/<資料夾>/controller.php` | `index.php?m=<代號>&a=<動作>` | 有多個動作、AJAX 的功能 |
| `pages/xxx.php` | `pages/xxx.php` | `index.php?m=<代號>` | 單純頁面，不用寫 Controller，自動套用版面 |
| `public/xxx.php` | `public/xxx.php` | 直接開啟該檔案 | 既有網站的舊頁面，只需在第一行加一行 require |

### auth_check.php：所有頁面共用同一套檢查

```
使用者開啟頁面
  ├─ index.php?m=xxx  →  App 路由 ──────────┐
  └─ public/legacy/xxx.php（第一行 require）─┤
                                             ▼
                              core/auth_check.php
                    1. 未登入           → 導向 login.php（AJAX 回 401）
                    2. 頁面未登記在 config.ini → 403
                    3. 首次登入未改密碼 → 導向個人設定
                    4. 身份不在 [access] 清單、或不符 action.* → 403
                                             ▼
                          通過 → 依 route 載入頁面程式
```

既有頁面接上權限只要在第一行加入（依檔案位置調整 `../`）：

```php
<?php require_once dirname(__FILE__) . '/../../core/auth_check.php';
// 通過檢查後才會執行到這裡；可用 $AUTH_USER、$AUTH_PAGE
```

auth_check.php 用「目前執行的檔案」對照 config.ini 的 route 找出頁面代號，所以頁面本身不用寫自己的代號或身份。檔案若沒登記在 config.ini，即使加了 require 也一律拒絕，避免漏設權限的頁面被看到。獨立頁面送出 POST 時會檢查 CSRF，舊表單請加上 `<?php echo csrf_field(); ?>`；若一時無法修改，可把 `[settings] direct_page_csrf` 設為 `Off`。

權限一律在伺服器端檢查，隱藏選單只是介面效果；直接輸入網址也會得到 403。目前範例的結果：

| 頁面 | EE | PE | ADMIN |
|---|:-:|:-:|:-:|
| 主控台 dashboard | ✓ | ✓ | ✓ |
| 共用報表 shared_report | ✓（不可匯出） | ✓ | ✓ |
| 個人設定 profile | ✓ | ✓ | ✓ |
| 設備狀態 ee_equipment | ✓ | | ✓ |
| 每日點檢 ee_daily_check（pages/） | ✓ | | ✓ |
| 製程配方 pe_recipe | | ✓ | ✓ |
| 製程公告 pe_notice（獨立頁面） | | ✓ | ✓ |
| 使用者管理 admin_users | | | ✓ |

修改 config.ini 後執行 `python scripts/python/check_config.py`，會列出每個身份可用的頁面，並檢查拼字、route 檔案是否存在、獨立頁面有沒有 require auth_check.php。

---

## 4. 註冊、登入、登出與權限檢查

### 流程

| 檔案 | 方法 | 行為 |
|---|---|---|
| `public/register.php` | GET | 註冊頁 |
| | GET `?check_account=xxx`（AJAX） | 回傳帳號是否可用 |
| | POST | 驗證 → 依 EE 名單判定身份 → 寫入 `users`，並在 `audit_logs` 記錄身份來源 |
| `public/login.php` | GET / POST | 驗證帳密，成功後 `session_regenerate_id()` 並把使用者與身份寫入 `$_SESSION['user']` |
| `public/logout.php` | POST（或 `?_csrf=`） | 清除 Session 與 Cookie，導回 `login.php?bye=1` |

登入與註冊表單由 `js/auth.js` 以 jQuery 做即時驗證，並用 `$.ajax` 非同步送出，伺服器回 JSON。瀏覽器關閉 JavaScript 時表單照常以一般 POST 送出，伺服器端也會做完整驗證。

### 註冊時的身份判定（`config.ini` 的 `[register]` 與 `[ee_accounts]`）

註冊表單只填帳號、顯示名稱與密碼。身份在註冊當下依 INI 名單判定，直接寫入 `users.role`：

```ini
[register]
enabled          = On
require_approval = Off     ; On = 註冊後需管理員啟用
listed_role      = "EE"    ; 名單上的帳號
unlisted_role    = "PE"    ; 名單外的帳號；設為 "" 則名單外不能註冊

[ee_accounts]              ; EE 正面表列，一行一個帳號，不分大小寫
account[] = "neo"
account[] = "trinity"
```

- 名單只在註冊時讀取。之後增減名單，不會改變已經註冊的人的身份，要調整請直接改資料庫的 `users.role`。
- ADMIN 永遠無法經由註冊取得；`listed_role`、`unlisted_role` 若設成 superuser 角色會被忽略。
- `audit_logs` 會記錄每個帳號的身份來源（`ee_accounts` 或 `unlisted_role`）。
- 改完名單可以執行 `python scripts/python/check_config.py`，它會列出名單內容，並檢查帳號格式與重複。

### 共用權限檢查函式（`core/guard.php`）

```php
require_login();                    // 未登入 → 導向 login.php；AJAX 回 401
require_role('PE');                 // 身份不符 → 403（ADMIN 一律通過）
require_role('EE,PE');              // 多個身份用逗號
if (has_role('EE,PE')) { ... }      // 只判斷、不中止
if (can_access('shared_report', 'export')) { ... }   // 依 [access] 與 [page.xxx] 判斷
echo csrf_field();                  // 表單的 CSRF 隱藏欄位
current_user();  current_role();  is_logged_in();
```

頁面層級的權限由 `auth_check.php` 處理；這些函式用在頁面內更細的判斷，例如只對某身份顯示某個按鈕。

---

## 5. 新增功能頁面

只需要改 config.ini，核心程式（`core/`）不用動：

1. 放好頁面程式（擇一）：`pages/xxx.php`、`public/xxx.php`（第一行 require auth_check.php），或建立模組
2. 在 `[access]` 把頁面代號加進能使用它的身份
3. 新增 `[page.xxx]`，寫上 `title` 與 `route`

例：新增一個 EE、PE 都能看的聯絡人頁面

```ini
[access]
EE = "dashboard, ..., team_contacts"
PE = "dashboard, ..., team_contacts"

[page.team_contacts]
title = "聯絡人 // CONTACTS"
route = "pages/team_contacts.php"
order = 60
```

需要多個動作或 AJAX 時用模組，腳本會產生程式骨架並自動完成上面第 2、3 步：

```bash
# 產生 modules/ee_alarm/，加入 EE、PE 的 [access] 清單並新增 [page.ee_alarm]
bash scripts/shell/new_module.sh ee_alarm "EE,PE" "警報中心 // ALARMS" 35
```

產生的骨架：

```php
<?php
class EeAlarmController extends Controller
{
    public function indexAction()          // index.php?m=ee_alarm
    {
        $this->render('index', array('message' => '...'));
    }

    public function listAction()           // index.php?m=ee_alarm&a=list
    {
        $rows = Database::fetchAll('SELECT ... WHERE x = ?', array($x));
        $this->json(array('ok' => true, 'rows' => $rows));
    }
}
```

Controller 可用的方法：`render()`、`json()`、`redirect()`、`can('動作')`、`isPost()`、`input()`、`query()`、`flash()`。View 中輸出變數一律用 `e($var)`；要放進 `<script>` 用 `js($var)`。

---

## 6. 安全機制

- bcrypt 密碼雜湊，提高 `bcrypt_cost` 後使用者下次登入自動升級雜湊
- 連續失敗 5 次鎖定 15 分鐘（`app.ini` 可調），帳號不存在時也執行一次 bcrypt，避免從回應時間猜出帳號
- 登入成功 `session_regenerate_id()`，閒置 30 分鐘自動登出，Cookie 設 HttpOnly
- 所有 POST（含 jQuery AJAX，透過 `X-CSRF-Token` 標頭）都檢查 CSRF token；登出也需要 token，避免被外部連結強制登出
- 註冊無法取得 ADMIN 身份；可開啟 `require_approval` 讓新帳號需管理員啟用，或把 `unlisted_role` 設為空字串只開放名單內帳號註冊
- 首次登入或被管理員重設密碼後強制改密碼
- 所有 SQL 使用 PDO prepared statement，`ATTR_EMULATE_PREPARES = false`

---

## 7. PHP 5.4 相容規範

可以用：`array()` 與 `[]` 短陣列、closure、`http_response_code()`、`JSON_UNESCAPED_UNICODE`、`session_status()`。

不能用：

| 不能用 | 版本 | 替代寫法 |
|---|---|---|
| `password_hash()` / `password_verify()` | 5.5 | 已由 `core/password_compat.php` 補上，可直接使用 |
| `finally`、`yield`、`::class`、`array_column()`、`empty(函式())` | 5.5 | try/catch、迴圈、字串類別名稱、先存變數 |
| `hash_equals()`、`...$args`、`const` 運算式 | 5.6 | `Security::equals()` |
| `??`、`<=>`、純量 type hint、回傳型別、`random_bytes()` | 7.0 | `array_get()`、`Security::randomBytes()` |
| `?->`、`match`、`fn() =>` | 8.0 | 一般寫法 |

---

## 8. 部署步驟

完整的逐步說明（環境需求、Apache 設定、驗證方式）見 [docs/DEPLOY.md](docs/DEPLOY.md)。順序摘要：

1. 確認 PHP 5.4（pdo_mysql、openssl）、MariaDB、Apache、Python 2.7
2. 放置程式
3. `mysql -u root -p < sql/schema.sql`
4. 建立 `aisle_app` 資料庫帳號
5. `mysql -u root -p < sql/seed.sql`
6. `config/app.ini`（複製範本，填 DB 帳密、base_url）
7. `config/config.ini`（EE 名單、註冊設定、各身份頁面）
8. `python scripts/python/check_config.py`
9. `bash scripts/shell/fetch_vendor.sh`（下載 jQuery）
10. 目錄權限（storage 可寫）
11. Apache DocumentRoot 指向 `public/`
12. 確認設定檔無法從網址下載
13. `admin` / `Neo@Zion2026` 首次登入並改密碼
14. 註冊測試帳號，驗證 EE / PE 權限
15.（選用）接入既有舊頁面
16. 設定每日備份 crontab

---

## 9. 駭客任務主題

| 元素 | 檔案 | 說明 |
|---|---|---|
| 數位雨背景 | `js/matrix-rain.js` | Canvas 半形片假名雨，約 20fps；系統設定「減少動態」時改為靜態畫面 |
| CRT 掃描線 | `css/matrix.css` `.crt-overlay` | 全畫面細橫線疊層 |
| 終端機面板 | `.panel` `.terminal` | 綠框黑底、亮綠標題列 |
| 打字機效果 | `AISLE.typewriter()` | 登入頁標語、主控台訊息 |
| 標題故障效果 | `.glitch` | 滑鼠移上時文字抖動 |
| 狀態燈 | `.led-run/idle/down` | 綠、暗綠、閃爍紅 |
| 身份徽章 | `.role-EE/PE/ADMIN` | EE 亮綠、PE 薄荷綠、ADMIN 紅藥丸 |

色票：`#00ff41` 主綠、`#00b82e` 中綠、`#008f11` 暗綠、`#001a07` 面板底、`#ff3b3b` 警示紅。CSS 未使用 CSS 變數，以相容較舊的瀏覽器。

截圖見 `docs/screenshots/`。
