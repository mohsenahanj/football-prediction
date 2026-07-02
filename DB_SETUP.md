# راهنمای نصب دیتابیس روی سرور

## ۱. وارد MySQL شو
```bash
mysql -u root -p
```

## ۲. دیتابیس و یوزر بساز
```sql
CREATE DATABASE football_game CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'user'@'localhost' IDENTIFIED BY 'StrongPass1234!';
GRANT ALL PRIVILEGES ON football_game.* TO 'user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

## ۳. includes/config.php رو ویرایش کن
```
define('DB_HOST', 'localhost');
define('DB_NAME', 'football_game');
define('DB_USER', 'user');
define('DB_PASS', 'StrongPass1234!');
```

## ۴. install.php رو اجرا کن
```
https://yourdomain.com/football/install.php
```

## ۵. install.php رو حذف کن
```bash
rm /var/www/yourdomain/football/install.php
```

## پسورد ادمین پیش‌فرض: bonobeauty2024
