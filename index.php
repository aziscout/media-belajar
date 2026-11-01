<?php
/**
 * Dynamic OpenGraph Shell for SPA Routing & Social Sharing
 * Injects dynamic media og:title, og:description, og:image for Facebook, WhatsApp, Twitter, etc.
 */

require_once __DIR__ . '/api/config.php';

$html = file_get_contents(__DIR__ . '/index.html');

$isTurnstile = defined('TURNSTILE_ENABLED') && TURNSTILE_ENABLED ? 'true' : 'false';
$html = str_replace('</head>', "  <script>window.TURNSTILE_ENABLED = {$isTurnstile};</script>\n  </head>", $html);

$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($requestUri, PHP_URL_PATH);

// Match /media/{slug}
if (preg_match('#^/media/([a-zA-Z0-9_-]+)$#', $path, $matches)) {
    $slug = $matches[1];
    $configPath = __DIR__ . '/api/config.php';
    
    if (file_exists($configPath)) {
        require_once $configPath;
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM media WHERE slug = ? AND status = 'approved' LIMIT 1");
            $stmt->execute([$slug]);
            $media = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($media) {
                $judul = htmlspecialchars($media['judul'], ENT_QUOTES, 'UTF-8');
                $mapel = htmlspecialchars($media['mapel'], ENT_QUOTES, 'UTF-8');
                $jenjang = htmlspecialchars($media['jenjang'], ENT_QUOTES, 'UTF-8');
                $kontributor = htmlspecialchars($media['kontributor'], ENT_QUOTES, 'UTF-8');
                
                $rawDesc = !empty($media['deskripsi']) ? $media['deskripsi'] : "Media Pembelajaran $mapel ($jenjang) karya $kontributor.";
                $desc = htmlspecialchars(mb_strimwidth(preg_replace('/\s+/', ' ', strip_tags($rawDesc)), 0, 160, '...'), ENT_QUOTES, 'UTF-8');

                $thumb = $media['thumbnail'] ?? '';
                if ($thumb && !preg_match('#^https?://#i', $thumb)) {
                    $host = $_SERVER['HTTP_HOST'] ?? 'media-belajar.btikpsultra.com';
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $thumb = $scheme . '://' . $host . '/' . ltrim($thumb, '/');
                }

                // Replace title and open graph meta tags in HTML
                $html = preg_replace('/<title>.*?<\/title>/i', "<title>{$judul} — Media Belajar BTIKP Sultra</title>", $html);
                $html = preg_replace('/<meta property="og:title" content=".*?"\s*\/?>/i', '<meta property="og:title" content="' . $judul . '" />', $html);
                $html = preg_replace('/<meta property="og:description" content=".*?"\s*\/?>/i', '<meta property="og:description" content="' . $desc . '" />', $html);
                if ($thumb) {
                    $html = preg_replace('/<meta property="og:image" content=".*?"\s*\/?>/i', '<meta property="og:image" content="' . $thumb . '" />', $html);
                    $html = preg_replace('/<meta name="twitter:image" content=".*?"\s*\/?>/i', '<meta name="twitter:image" content="' . $thumb . '" />', $html);
                }
                $html = preg_replace('/<meta name="twitter:title" content=".*?"\s*\/?>/i', '<meta name="twitter:title" content="' . $judul . '" />', $html);
                $html = preg_replace('/<meta name="twitter:description" content=".*?"\s*\/?>/i', '<meta name="twitter:description" content="' . $desc . '" />', $html);
            }
        } catch (Exception $e) {
            // Silently fall back to default HTML on DB error
        }
    }
}

// Force Content-Type to text/html (overriding application/json set by api/config.php)
header('Content-Type: text/html; charset=utf-8', true);

echo $html;
