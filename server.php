<?php

/**
 * Laravel - A PHP Framework For Web Artisans
 *
 * @package  Laravel
 * @author   Taylor Otwell <taylor@laravel.com>
 */

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

// This file allows us to emulate Apache's "mod_rewrite" functionality from the
// built-in PHP web server ("php artisan serve"). In this project the front
// controller lives in the project root (there is no "public" folder), so only
// static assets are served directly; everything else (including .env, logs and
// PHP files) goes through index.php.
$static = '/\.(ico|txt|css|js|map|png|jpe?g|gif|svg|webp|woff2?|ttf)$/i';

if ($uri !== '/' && ! str_contains($uri, '/.') && preg_match($static, $uri) && is_file(__DIR__.$uri)) {
    return false;
}

require_once __DIR__.'/index.php';
