# راهنمای نصب دیتابیس روی سرور

## ۱. وارد MySQL شو
```bash
mysql -u root -p
```

## ۲. دیتابیس و یوزر بساز
```sql
CREATE DATABASE football_game CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'fbuser'@'localhost' IDENTIFIED BY 'StrongPass123!';
GRANT ALL PRIVILEGES ON football_game.* TO 'fbuser'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

## ۳. includes/config.php رو ویرایش کن
```
define('DB_HOST', 'localhost');
define('DB_NAME', 'football_game');
define('DB_USER', 'fbuser');
define('DB_PASS', 'StrongPass123!');
```

## ۴. install.php رو اجرا کن
```
https://bonobeauty.fr/football/install.php
```

## ۵. install.php رو حذف کن
```bash
rm /var/www/bonobeauty/football/install.php
```

## پسورد ادمین پیش‌فرض: bonobeauty2024
