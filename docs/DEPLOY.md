# AISLE 部署步驟

以下指令以 Linux + Apache、程式放在 `/var/www/aisle`、Web Server 執行身份為 `apache`（Debian/Ubuntu 為 `www-data`）為例，請依實際環境替換。

---

## 1. 確認主機環境

| 項目 | 需求 | 確認指令 |
|---|---|---|
| PHP | 5.4（至少 5.3.7，bcrypt 需要） | `php -v` |
| PHP 擴充 | `pdo_mysql`、`openssl`、`json`、`session` | `php -m` |
| MariaDB | 5.5 以上（utf8mb4 需 5.5.3+） | `mysql --version` |
| MariaDB 用戶端 | `mysql`、`mysqldump`（建帳號、備份腳本用） | `which mysql mysqldump` |
| Apache | 2.2 或 2.4，開啟 `mod_rewrite` 不需要；`mod_headers` 選用 | `httpd -v` |
| Python | 2.7（設定檢查腳本） | `python -V` |
| 其他 | `bash`、`curl` 或可從外網複製檔案 | |

`php.ini` 確認：`date.timezone` 有設定、`session.save_path` 目錄可寫入、正式環境 `display_errors = Off`。

## 2. 放置程式

```bash
cp -R AISLE /var/www/aisle
cd /var/www/aisle
chmod +x scripts/shell/*.sh scripts/python/*.py
```

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
| `[app]` | `base_url` | 網站在網域根目錄留空 `""`；放在子目錄（例 `http://host/aisle/`）填 `"/aisle"` |
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

## 9. 下載 jQuery

```bash
bash scripts/shell/fetch_vendor.sh
```

主機不能連外網時，在可連外網的電腦下載
`https://code.jquery.com/jquery-1.12.4.min.js`，
複製到 `public/assets/vendor/jquery-1.12.4.min.js`。

## 10. 設定目錄權限

```bash
chown -R root:apache /var/www/aisle
chown -R apache:apache storage
chmod -R 775 storage
```

`storage/logs` 要讓 Web Server 寫入 PHP 錯誤紀錄，`storage/backups` 給備份腳本使用。

## 11. 設定 Apache

**建議：DocumentRoot 指向 `public/`**，其他目錄完全不對外。

```apache
<VirtualHost *:80>
    ServerName aisle.example.local
    DocumentRoot /var/www/aisle/public

    <Directory /var/www/aisle/public>
        AllowOverride All
        # Apache 2.4
        Require all granted
        # Apache 2.2 改用：
        # Order allow,deny
        # Allow from all
    </Directory>
</VirtualHost>
```

無法修改 DocumentRoot 時（例如只能放在既有網站的子目錄）：保留專案根目錄的 `.htaccess`，它會擋掉 `config/`、`core/`、`modules/` 等目錄，只開放 `public/`；同時把第 6 步的 `base_url` 設為 `"/aisle/public"`。這種方式需要 `AllowOverride All`。

```bash
apachectl configtest && apachectl graceful
```

## 12. 確認敏感檔案無法下載

```bash
curl -I http://aisle.example.local/config/app.ini        # 應回 404（config/ 不在網站目錄內）
curl -I http://aisle.example.local/index.php             # 應回 302 → login.php
```

（使用子目錄方式時改測 `http://host/aisle/config/app.ini`，應回 403。）

## 13. 首次登入

1. 開啟網站，會自動導向 `login.php`
2. 以 `admin` / `Neo@Zion2026` 登入
3. 系統強制進入「個人設定」修改密碼，改完才能使用其他功能

## 14. 驗證身份與權限

1. 登出後到 `register.php`，用一個 `[ee_accounts]` 名單內的帳號註冊 → 應顯示身份 EE
2. 再用名單外的帳號註冊 → 應顯示 PE（或依第 7 步設定被拒絕）
3. 分別登入，確認左側選單只出現 `[access]` 中該身份的頁面
4. 以 EE 帳號直接輸入 PE 頁面網址（例 `index.php?m=pe_recipe`）→ 應顯示 403
5. 以 admin 登入「使用者管理」，確認剛註冊的帳號與身份正確

也可用指令直接建立帳號（建立後首次登入需改密碼）：

```bash
bash scripts/shell/create_user.sh ee_chen EE "陳工程師"
```

## 15.（選用）接入既有網站的舊頁面

1. 把舊頁面放到 `public/` 底下，例如 `public/legacy/old_report.php`
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
0 2 * * * /var/www/aisle/scripts/shell/backup_db.sh >> /var/www/aisle/storage/logs/backup.log 2>&1
```

備份檔放在 `storage/backups/`，預設保留 14 天。先手動執行一次 `bash scripts/shell/backup_db.sh` 確認能成功。
