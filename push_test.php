<?php
session_start();
require __DIR__ . '/config.php';
require __DIR__ . '/vapid.php';

// Tanı bilgileri (kayıtlı push aboneleri, sunucu durumu) herkese açık olmamalı — yalnız yönetim görebilir.
if (!isset($_SESSION['kullanici_id']) || !in_array($_SESSION['rol'] ?? '', ['admin', 'yonetici'], true)) {
    http_response_code(403);
    exit('Bu sayfayı görüntüleme yetkiniz yok.');
}

// Tabloları oluştur
$db->exec("CREATE TABLE IF NOT EXISTS app_settings (k VARCHAR(100) PRIMARY KEY, v TEXT NOT NULL)");
$db->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
    id SERIAL PRIMARY KEY,
    kullanici_id INTEGER NOT NULL,
    endpoint TEXT NOT NULL UNIQUE,
    p256dh TEXT, auth TEXT,
    olusturma TIMESTAMP NOT NULL DEFAULT NOW()
)");

echo '<style>body{font-family:sans-serif;padding:2rem;max-width:700px;}
.ok{color:green;} .fail{color:red;} .warn{color:orange;}
pre{background:#f5f5f5;padding:1rem;border-radius:6px;font-size:.85rem;}
</style>';
echo '<h2>🔔 Push Bildirim Test</h2>';

// 1. HTTPS kontrolü
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
echo '<p>' . ($https ? '<b class="ok">✓ HTTPS aktif</b>' : '<b class="fail">✗ HTTPS YOK — Service Worker çalışmaz! Siteyi https:// ile açın.</b>') . '</p>';

// 2. OpenSSL EC kontrolü
$ec_ok = false;
try {
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $ec_ok = ($k !== false);
} catch(Exception $e) {}
echo '<p>' . ($ec_ok ? '<b class="ok">✓ OpenSSL EC desteği var</b>' : '<b class="fail">✗ OpenSSL EC desteği YOK</b>') . '</p>';

// 3. VAPID key üret / al
$vapid_ok = false;
$vapid_pub = '';
try {
    $keys = vapid_keys($db);
    $vapid_pub = $keys['public'];
    $vapid_ok = strlen($vapid_pub) > 10;
} catch(Exception $e) {
    echo '<p class="fail">VAPID hata: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
echo '<p>' . ($vapid_ok
    ? '<b class="ok">✓ VAPID anahtarlar hazır</b>'
    : '<b class="fail">✗ VAPID anahtar üretilemedi</b>') . '</p>';
if ($vapid_pub) echo '<pre>Public Key: ' . $vapid_pub . '</pre>';

// 4. cURL kontrolü
$curl_ok = function_exists('curl_init');
echo '<p>' . ($curl_ok ? '<b class="ok">✓ cURL aktif</b>' : '<b class="fail">✗ cURL YOK — push gönderilemez</b>') . '</p>';

// 5. Kayıtlı aboneler
$subs = [];
try {
    $subs = $db->query("SELECT kullanici_id, LEFT(endpoint,60) AS ep, olusturma FROM push_subscriptions ORDER BY id DESC LIMIT 20")->fetchAll();
} catch(Exception $e) {}
echo '<p><b>Kayıtlı aboneler: ' . count($subs) . '</b></p>';
if ($subs) {
    echo '<pre>';
    foreach ($subs as $s) echo 'Kullanıcı #' . $s['kullanici_id'] . ' — ' . $s['ep'] . '... — ' . $s['olusturma'] . "\n";
    echo '</pre>';
}

// 6. Test push gönder (oturum açıksa)
if (isset($_SESSION['kullanici_id']) && isset($_GET['test_push'])) {
    $uid = (int)$_SESSION['kullanici_id'];
    $sub_stmt = $db->prepare("SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE kullanici_id = ?");
    $sub_stmt->execute([$uid]);
    $mysubs = $sub_stmt->fetchAll();
    if (!$mysubs) {
        echo '<p class="warn">⚠ Sizin için kayıtlı abonelik yok. Önce siteye normal girin ve bildirimlere izin verin.</p>';
    }
    foreach ($mysubs as $sub) {
        $code = push_send($sub, $keys['public'], $keys['private_pem']);
        echo '<p>Push gönderildi → HTTP <b>' . $code . '</b> ';
        echo $code === 201 ? '<span class="ok">(Başarılı ✓)</span>' : '';
        echo ($code === 410 || $code === 404) ? '<span class="fail">(Abonelik geçersiz — silinecek)</span>' : '';
        echo '</p>';
        if ($code === 410 || $code === 404) {
            $db->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?")->execute([$sub['endpoint']]);
        }
    }
}

echo '<hr>';
if (!isset($_SESSION['kullanici_id'])) {
    echo '<p class="warn">Test push göndermek için önce <a href="index.php">giriş yapın</a>.</p>';
} else {
    echo '<p><a href="?test_push=1" style="background:#5e35b1;color:#fff;padding:.5rem 1.2rem;border-radius:8px;text-decoration:none;">🔔 Kendime Test Push Gönder</a></p>';
}

echo '<hr><p style="font-size:.8rem;color:#888;">Bu dosyayı test sonrası silin: <b>push_test.php</b></p>';
