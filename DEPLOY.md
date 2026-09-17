# Deployment

## Requirements

- PHP 8.4.1 or newer
- PHP extensions: ctype, dom, fileinfo, filter, iconv, mbstring, openssl, PDO MySQL, phpredis, session, tokenizer
- MySQL and Redis reachable with the values configured in `.env`

## Install

Upload and extract the package, point the web server document root to `public`, then run:

```bash
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

Ensure `storage` and `bootstrap/cache` are writable by the web-server user.

## Queue worker

Keep this command running under Supervisor or systemd:

```bash
php artisan queue:work redis --sleep=3 --tries=3
```

The included `.env` contains deployment credentials and must remain private.
This package uses staging mode, public registration, and log-based OTP delivery.
