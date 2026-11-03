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

`storage/logs` 要讓 Web Server 寫入 PHP 錯誤紀錄，`storage/backups` 給備份腳本使用。

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

## 17.（選用）圖表 Demo 接上實際資料

圖表 Demo（`index.php?m=chart_demo`）預設使用範例資料，部署後即可看到畫面。接上實際資料：

1. 編輯 `config/chart_demo.ini`：設定哪些參數畫在同一張圖（`params`）、各組是否顯示單台 / 最新值排排站 / 歷史趨勢排排站（`single` / `newest` / `tbt`），以及 Y 軸上下限（`ymin` / `ymax`，或 `tbt_ymin` 等只套用到單一區塊）。參數名稱必須與資料中的名稱完全一致
2. 在 `config/app.ini` 加上（範本見 `app.ini.example` 的 `[chart_demo]`）：
   ```ini
   [chart_demo]
   source = "db"
   ```
3. 實作 `modules/chart_demo/ChartDataSource.php` 的「★ 部署者實作區 (db)」：`dbTools()`、`dbSeries()`，註解內附範例 SQL：
   - 資料表是「一列一個參數值」（例 `tool_data(tool_id, data_time, param, value)`）→ 查出後用 `self::pivot()` 轉換
   - 資料表是「一列一個時間點、每個參數一個欄位」→ 查出後用 `self::wide()` 轉換
4. 確認 `aisle_app` 對資料表有 `SELECT` 權限：
   ```sql
   GRANT SELECT ON `資料庫`.`資料表` TO 'aisle_app'@'localhost';
   ```
5. 要改用 Python 取資料時設 `source = "script"`，實作 `scripts/python/chart_data.py` 的部署者實作區；`python` 指定 Python 2.7 的路徑，並先測試：
   ```bash
   python scripts/python/chart_data.py series --start 2026-01-01 --end 2026-01-07 \
       --tools APAN01,APAP01 --params M1BF_Z_C1,M1BF_Z_C2
   ```
6. 以 EE 或 PE 帳號登入開啟頁面，各圖都有資料即完成；`debug = 1` 時錯誤訊息會直接顯示在圖表中
