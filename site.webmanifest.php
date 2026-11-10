<?php
/** Web app manifest (icon and name used when the site is added to a phone's home screen) */
require_once __DIR__ . '/config/config.php';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=86400');
$icons = setting('favicon', 'en') !== ''
    ? [['src' => media_url(setting('favicon', 'en')), 'sizes' => 'any', 'type' => 'image/png']]
    : [
        ['src' => url('public/favicon/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => url('public/favicon/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
    ];
echo json_encode([
    'name' => setting('org_name', 'en'),
    'short_name' => 'Aadhunik Jana',
    'start_url' => url('index.php'),
    'display' => 'standalone',
    'background_color' => '#ffffff',
    'theme_color' => '#146b45',
    'icons' => $icons,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
