# AISLE 部署步驟

以下指令以實際環境為例：

| 項目 | 值 |
|---|---|
| 程式來源 | GitHub `bboysevenwu/AISLE` 的 `main` branch |
| 部署位置 | `/var/www/html/aisle/`（Apache DocumentRoot 為 `/var/www/html`） |
| 對外網址 | `http://sccproxyauth-a-dc1-38.scc.f18.tsmc.com/D1C1/aisle/`（proxy 前綴 `/D1C1/`） |
| Web Server 執行身份 | `apache`（Debian/Ubuntu 為 `www-data`） |

網址的對應關係：

```
瀏覽器  http://sccproxyauth-a-dc1-38.scc.f18.tsmc.com/D1C1/aisle/login.php
  │ proxy 去掉 /D1C1 前綴後轉給主機
主機    http://<主機>/aisle/login.php
  │ /var/www/html/aisle/.htaccess 在內部轉進 public/
檔案    /var/www/html/aisle/public/login.php
```

網址不會出現 `public/`；`config/`、`core/` 等目錄經由網址存取一律 404。

---

## 1. 確認主機環境

| 項目 | 需求 | 確認指令 |
|---|---|---|
| PHP | 5.4（至少 5.3.7，bcrypt 需要） | `php -v` |
| PHP 擴充 | `pdo_mysql`、`openssl`、`json`、`session` | `php -m` |
| MariaDB | 5.5 以上（utf8mb4 需 5.5.3+） | `mysql --version` |
| MariaDB 用戶端 | `mysql`、`mysqldump`（建帳號、備份腳本用） | `which mysql mysqldump` |
| Apache | 2.2 或 2.4，需要 `mod_rewrite`；`mod_headers` 選用 | `httpd -v`、`httpd -M` |
| Python | 2.7（設定檢查腳本） | `python -V` |
| 其他 | `bash`、`curl` 或可從外網複製檔案 | |

`php.ini` 確認：`date.timezone` 有設定、`session.save_path` 目錄可寫入、正式環境 `display_errors = Off`。

## 2. 放置程式

```bash
cd /var/www/html
git clone -b main https://github.com/bboysevenwu/AISLE.git aisle
cd /var/www/html/aisle
```

主機不能連 GitHub 時，在其他電腦 clone 或下載 ZIP 後整包複製到 `/var/www/html/aisle/`。腳本在 git 裡已設為可執行，若是用 ZIP 複製則補一行 `chmod +x scripts/shell/*.sh scripts/python/*.py`。

之後更新程式：

```bash
cd /var/www/html/aisle && git pull origin main
```

`config/app.ini` 不在版控內，`git pull` 不會覆蓋它。

主機不能 `git pull`、也不能下載壓縮檔時，改用單檔更新，見第 18 步。

## 3. 建立資料庫與資料表

```bash
mysql -u root -p < sql/schema.sql
```

建立 `aisle` 資料庫與 `users`、`login_logs`、`audit_logs` 三張表。MariaDB 低於 5.5.3 時，先把 schema.sql 裡的 `utf8mb4` 全部改成 `utf8`。

## 4. 建立應用程式專用的資料庫帳號

不要讓網站使用 root。以 root 登入 MariaDB 後執行（密碼自訂）：

```sql
CREATE USER 'aisle_app'@'localhost' IDENTIFIED BY '強密碼';
GRANT SELECT, INSERT, UPDATE, DELETE ON `aisle`.* TO 'aisle_app'@'localhost';
FLUSH PRIVILEGES;
```

## 5. 匯入初始管理員

```bash
mysql -u root -p < sql/seed.sql
```

建立帳號 `admin`，密碼 `Neo@Zion2026`（第 13 步首次登入時會被要求修改）。

## 6. 設定 config/app.ini（主機與資料庫）

```bash
cp config/app.ini.example config/app.ini
vi config/app.ini
```

| 區段 | 項目 | 填寫 |
|---|---|---|
| `[app]` | `debug` | 正式環境 `0` |
| `[app]` | `base_url` | **`"/D1C1/aisle"`**（瀏覽器看到的路徑，要包含 proxy 前綴 `/D1C1`，結尾不加 `/`） |
| `[database]` | `user` / `password` | 第 4 步建立的 `aisle_app` 與密碼 |
| `[security]` | `cookie_secure` | 使用 HTTPS 時設 `1` |

```bash
chown root:apache config/app.ini
chmod 640 config/app.ini
```

## 7. 設定 config/config.ini（身份、頁面、註冊名單）

1. `[ee_accounts]`：把範例 `neo / trinity / morpheus` 換成實際的 EE 帳號，一行一個
2. `[register]`：`unlisted_role` 決定名單外帳號的身份（`"PE"`，或 `""` 表示不開放註冊）；需要管理員審核時把 `require_approval` 設為 `On`
3. `[access]` / `[page.*]`：確認每個身份可用的頁面。範例頁面 `ee_daily_check`、`pe_notice` 若不需要，從 `[access]` 移除即可
4. 既有網站的舊頁面若要接入，見第 15 步

## 8. 檢查設定

```bash
python scripts/python/check_config.py
```

最後一行出現 `[OK] config.ini 檢查通過` 才往下做。`admin_users 只有 * 身份能使用` 這個警告是正常的。

## 9. 下載 jQuery、ECharts

```bash
bash scripts/shell/fetch_vendor.sh
```

主機不能連外網時，在可連外網的電腦下載以下檔案，複製到 `public/assets/vendor/`：

| 下載網址 | 存成 |
|---|---|
| `https://code.jquery.com/jquery-1.12.4.min.js` | `jquery-1.12.4.min.js` |
| `https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js` | `echarts-5.4.3.min.js` |

ECharts 只有圖表 Demo 頁使用；沒放本機檔案時頁面會改從 CDN 載入，使用者的瀏覽器需要能連到 cdn.jsdelivr.net。

## 10. 設定目錄權限

```bash
chown -R root:apache /var/www/html/aisle
chown -R apache:apache /var/www/html/aisle/storage
chmod -R 775 storage
```

`storage/logs` 要讓 Web Server 寫入 PHP 錯誤紀錄，`storage/backups` 給備份腳本使用，`storage/uploads` 存 Pending Issue 的圖片（程式會自動建立 `pending_issue/<年月>/` 子資料夾），`storage/cache` 存借機看板的 PM JSON 解析快取。

## 11. 設定 Apache

程式放在 `/var/www/html/aisle/`，靠專案根目錄的 `.htaccess` 把請求轉進 `public/`，所以需要：

1. 開啟 `mod_rewrite`：
   ```bash
   httpd -M | grep rewrite        # Debian/Ubuntu：a2enmod rewrite
   ```
2. 允許 `/var/www/html/aisle` 使用 `.htaccess`（寫在 `httpd.conf` 或 `conf.d/aisle.conf`）：
   ```apache
   <Directory /var/www/html/aisle>
       AllowOverride All
   </Directory>
   ```
3. 套用設定：
   ```bash
   apachectl configtest && apachectl graceful
   ```

沒有 `mod_rewrite` 時，根目錄 `.htaccess` 會拒絕整個 `/aisle/`（避免設定檔外洩），網站會是 403。

> 另一種做法：若可以為 AISLE 開一個獨立的 VirtualHost，可把 `DocumentRoot` 直接指向 `/var/www/html/aisle/public`，此時根目錄 `.htaccess` 不會被讀到，`base_url` 依對外路徑調整。

## 12. 確認網址與敏感檔案

先在主機上直接測（不經 proxy，路徑沒有 `/D1C1`）：

```bash
curl -I http://localhost/aisle/login.php             # 200
curl -I http://localhost/aisle/config/app.ini        # 404（不可是 200）
curl -I http://localhost/aisle/core/bootstrap.php    # 404
```

再從自己的電腦經 proxy 測：

```bash
P=http://sccproxyauth-a-dc1-38.scc.f18.tsmc.com/D1C1/aisle
curl -I $P/                      # 302，Location 為 /D1C1/aisle/login.php
curl -I $P/login.php             # 200，Set-Cookie 的 path=/D1C1/aisle/
curl -I $P/assets/css/matrix.css # 200
curl -I $P/config/app.ini        # 404
```

（proxy 需要登入驗證時，用瀏覽器開啟 `$P/` 確認會跳到登入頁、畫面有綠色樣式即可。）

注意：`base_url` 設成 `/D1C1/aisle` 後，網頁內的連結都帶 `/D1C1` 前綴，**直接連主機 `http://<主機>/aisle/` 時頁面能開但連結會失效**，這是正常的，請一律從 proxy 網址使用。若 proxy 轉址後出現 `/D1C1/D1C1/...` 或少了 `/D1C1`，代表 proxy 對 `Location` 標頭有另外改寫，請告知 proxy 管理者或改 `base_url`。

## 13. 首次登入

1. 開啟 `http://sccproxyauth-a-dc1-38.scc.f18.tsmc.com/D1C1/aisle/`，會自動導向 `login.php`
2. 以 `admin` / `Neo@Zion2026` 登入
3. 系統強制進入「個人設定」修改密碼，改完才能使用其他功能

## 14. 驗證身份與權限

1. 登出後到 `register.php`，用一個 `[ee_accounts]` 名單內的帳號註冊 → 應顯示身份 EE
2. 再用名單外的帳號註冊 → 應顯示 PE（或依第 7 步設定被拒絕）
3. 分別登入，確認左側選單只出現 `[access]` 中該身份的頁面
4. 以 EE 帳號直接輸入 PE 頁面網址（例 `/D1C1/aisle/index.php?m=pe_recipe`）→ 應顯示 403
5. 以 admin 登入「使用者管理」，確認剛註冊的帳號與身份正確

也可用指令直接建立帳號（建立後首次登入需改密碼）：

```bash
bash scripts/shell/create_user.sh ee_chen EE "陳工程師"
```

## 15.（選用）接入既有網站的舊頁面

1. 把舊頁面放到 `public/` 底下，例如 `public/legacy/old_report.php`（網址為 `/D1C1/aisle/legacy/old_report.php`）
2. 在檔案第一行加入（依層數調整 `../`）：
   ```php
   <?php require_once dirname(__FILE__) . '/../../core/auth_check.php'; ?>
   ```
3. 在 config.ini 登記：
   ```ini
   [access]
   PE = "..., old_report"

   [page.old_report]
   title = "舊報表"
   route = "public/legacy/old_report.php"
   ```
4. 舊頁面有 POST 表單時，在 `<form>` 內加上 `<?php echo csrf_field(); ?>`；暫時無法修改的話，把 `[settings] direct_page_csrf` 設為 `Off`
5. 重新執行第 8 步的檢查

## 16. 設定每日備份

```bash
crontab -e
```

```cron
0 2 * * * /var/www/html/aisle/scripts/shell/backup_db.sh >> /var/www/html/aisle/storage/logs/backup.log 2>&1
```

備份檔放在 `storage/backups/`，預設保留 14 天。先手動執行一次 `bash scripts/shell/backup_db.sh` 確認能成功。

## 17.（選用）圖表接上實際資料

每張圖表（BSLr、M1BF…）是選單「圖表」底下的一頁，由 `charts/<名稱>.ini` 與 `charts/<名稱>.php` 定義（說明見 README 第 10 節）。內附的 M1BF 預設使用範例資料（`source = sample`），部署後即可看到畫面。

1. 每張圖表的資料庫連線寫在主機上的 `charts/<名稱>.ini` 的 `[db]`（`ip` 留空則使用 AISLE 自己的資料庫）：
   ```ini
   [db]
   ip       = "10.x.x.x"
   port     = 3306
   name     = "資料庫名稱"
   user     = "唯讀帳號"
   password = "密碼"
   charset  = "utf8mb4"
   ```
   帳號密碼只填在主機上，**不要 commit 到 GitHub**；之後的單檔更新若包含這個 ini，套用前先記下 `[db]`，套用後再填回（`apply_update.py` 會先備份原檔）。
   建議 `chmod 640 charts/*.ini`，並用只有 SELECT 權限的帳號：
   ```sql
   GRANT SELECT ON `資料庫`.* TO '唯讀帳號'@'AISLE 主機 IP' IDENTIFIED BY '密碼';
   ```
2. 新增圖表：`bash scripts/shell/new_chart.sh BSLr`，建立 `charts/BSLr.ini`、`charts/BSLr.php`
3. 編輯 `charts/BSLr.ini`：`[plot.xxx]` 設定哪些參數畫在同一張圖、各區塊開關、Y 軸上下限與 spec 線（`upper_oos` / `upper_ooc` / `lower_ooc` / `lower_oos`，可留空）；`[page]` 設定機型規則、預設天數。參數名稱必須與 `BSLr.php` 回傳的名稱完全一致
4. 編輯 `charts/BSLr.php`：在 `tools()`、`series()` 寫 SQL，用 `pivot()` / `wide()` 轉換、不同 table 用 `merge()` 合併（檔案內附三種寫法），寫好後把 ini 的 `source` 改成 `php`
5. 把腳本印出的 `[page.bslr]` 區段貼進 `config/config.ini`，並把 `bslr` 加到 `[access]` 要看得到的身份
6. `python scripts/python/check_config.py` 檢查，再以 EE 或 PE 帳號登入開啟頁面；`debug = 1` 時 SQL 錯誤會直接顯示在圖表中

由使用 `config/app.ini` `[charts_db]` 的版本升級時：把連線設定搬到每張圖表 ini 的 `[db]`（`host` 改名為 `ip`），`[charts_db]` 可以刪除。

由舊版圖表 Demo 升級時：`config/app.ini` 的 `[chart_demo]` 區段可以刪除；舊網址 `index.php?m=chart_demo` 改為 `index.php?m=m1bf`。

## 17-1.（選用）啟用 Pending Issue Control Table

1. 建立資料表（只需一次，重複執行不影響既有資料）：
   ```bash
   mysql -u root -p aisle < sql/migrations/20261110_pending_issue.sql
   ```
2. 建立圖片資料夾並讓 Apache 可寫入：
   ```bash
   mkdir -p storage/uploads
   chown -R apache:apache storage/uploads
   chmod -R 775 storage/uploads
   ```
3. 確認 `php.ini`：`file_uploads = On`，`upload_max_filesize`、`post_max_size` 大於 `config/pending_issue.ini` 的 `max_mb`（預設 5 MB，建議設 8M）
4. `config/pending_issue.ini`：在 `[editors]` 列出可新增 / 編輯的 EE 帳號（範例 `neo` 請換成實際帳號），依需要調整機型、分類、Sponsor 身份、匯出開關；`idle_seconds` 要小於 `app.ini` 的 `session_idle`
5. `python scripts/python/check_config.py`，再以 EE 帳號開啟「Pending Issue // CONTROL TABLE」

## 17-2.（選用）啟用機台借機與 PM 排程看板

1. 建立資料表（只需一次，重複執行不影響既有資料）：
   ```bash
   mysql -u root -p aisle < sql/migrations/20261111_tool_board.sql
   ```
2. 讓 PM 解析快取資料夾可寫入：
   ```bash
   mkdir -p storage/cache
   chown -R apache:apache storage/cache
   chmod -R 775 storage/cache
   ```
3. 每個機型的設定檔 `config/tool_board/PAN.ini`、`PAP.ini`、`POV.ini`：
   - `[tools]` 換成實際機台（`tool[] = "APAN01"`，一行一台，同一台不能列在兩個機型）
   - `[editors]` 填可以編輯這個機型的 EE 帳號（`account[] = "neo"`）；ADMIN 不必列
   - `[wip.<代碼>]` 依機型需要增減 WIP 處理方式
4. `config/tool_board.ini`：
   - `[pm] json_path` 填 PM JSON 檔在主機上的絕對路徑（Apache 要能讀），空白 = 不顯示 PM
   - 依需要調整類別顏色、可用率門檻；`[sync] idle_seconds` 要小於 `app.ini` 的 `session_idle`（程式也會自動調小）
5. `python scripts/python/check_config.py`，再以 EE 帳號開啟「借機 / PM 排程 // TOOL BOARD」

## 18. 不能 git pull 時的更新方式（單檔更新）

公司內只能開 GitHub 網頁複製文字時使用。每次更新會在 repo 的 `updates/` 放一個純文字更新檔，裡面包含這次新增、修改、刪除的所有檔案；在主機上貼成一個檔案，執行一次 `apply_update.py` 就全部更新完。

### 18-1. 第一次：把 apply_update.py 放到主機上

在 GitHub 打開 `scripts/python/apply_update.py`，按 **Raw**，全選複製，然後在主機上：

```bash
cd /var/www/html/aisle
cat > scripts/python/apply_update.py     # 貼上內容，最後按 Enter 再按 Ctrl-D
chmod +x scripts/python/apply_update.py
python scripts/python/apply_update.py -h # 出現用法說明即可
```

之後的更新檔若包含新版的 `apply_update.py`，會自動一起更新，不需要再手動複製。

### 18-2. 每次更新

1. 在 GitHub 打開 `updates/` 底下這次的更新檔（例 `update_20261104_7d8729a_xxxxxxx.txt`），按 **Raw**，`Ctrl-A` 全選、`Ctrl-C` 複製
2. 貼到主機上，放在網站目錄以外（例 `~/`）：
   ```bash
   cat > ~/update.txt     # 貼上，最後按 Enter 再按 Ctrl-D
   ```
   用 `vi` 貼上時，先輸入 `:set paste` 再進入插入模式，否則 vi 會自動加縮排，內容會對不上
3. 先預覽（不會改任何檔案）：
   ```bash
   cd /var/www/html/aisle
   python scripts/python/apply_update.py ~/update.txt
   ```
   會列出每個檔案是 `[新增]`、`[覆蓋]`、`[刪除]` 還是 `[相同]`，以及備份檔名
4. 確認後實際套用：
   ```bash
   python scripts/python/apply_update.py ~/update.txt --apply
   ```
5. 新增了 config.ini 的頁面時，照第 8 步執行 `check_config.py`

### 腳本會做的檢查

| 狀況 | 結果 |
|---|---|
| 複製時漏了一段、貼了兩次、更新檔被截斷 | 每個檔案都有 sha1，結尾有項目數；任何一個對不上就整批不寫入 |
| vi 自動縮排、Windows 換行（CRLF）、UTF-8 BOM | 換行與 BOM 會自動處理；縮排被改會被 sha1 擋下 |
| 主機上的檔案被直接改過（和更新前的版本不同） | 列為「衝突」並停止。確認可以覆蓋後加 `--force`（仍會先備份） |
| 寫入到一半失敗（權限不足等） | 自動把已經改過的檔案還原 |
| 同一個更新檔執行兩次 | 第二次全部顯示 `[相同]`，不會再改 |

### 備份與還原

- 被覆蓋的檔案先複製一份在原位置，檔名後面加上 `_<日期>_<時間>`，例 `core/Auth.php_20261104_204500`；被刪除的檔案改名成同樣格式，不會真的刪掉
- 同一次執行共用同一個時間，紀錄寫在 `storage/logs/update_<時間>.json`
- 根目錄與 `public/` 的 `.htaccess` 會擋掉這種備份檔名（403），避免舊版程式碼被當純文字下載
- 查看紀錄與主機目前的版本：
  ```bash
  python scripts/python/apply_update.py --list
  ```
- 還原某次更新（先預覽，再加 `--apply`；`last` 代表最近一次）：
  ```bash
  python scripts/python/apply_update.py --rollback 20261104_204500
  python scripts/python/apply_update.py --rollback 20261104_204500 --apply
  ```
  還原會把備份檔改回原檔名，並移除那次新增的檔案
- 備份檔確認不需要後可以手動刪除，例 `find . -name '*_20261104_204500' -delete`

### 產生更新檔（開發端）

在有 git 的電腦上，指定主機目前的版本（`--list` 最後一筆的「版本 → 」右邊，或部署時的 commit）：

```bash
python scripts/python/make_update.py 7d8729a HEAD --note "這次更新的說明"
```

輸出到 `updates/update_<日期>_<舊>_<新>.txt`，commit 後即可在 GitHub 打開。預設不打包 `updates/` 與 `docs/screenshots/`；圖片等非文字檔會用 base64 存放。主機版本不明時可用 `--all` 打包全部檔案（不檢查衝突）。`config/app.ini` 不在 git 裡，不會被更新檔覆蓋。
