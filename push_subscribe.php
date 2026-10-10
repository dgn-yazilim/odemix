<?php
session_start();
require __DIR__ . '/config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['kullanici_id'])) {
    echo json_encode(['ok' => false, 'hata' => 'oturum yok']);
    exit;
}

// push_subscriptions tablosu yoksa oluştur
$db->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
    id           SERIAL PRIMARY KEY,
    kullanici_id INTEGER NOT NULL REFERENCES kullanicilar(id) ON DELETE CASCADE,
    endpoint     TEXT    NOT NULL UNIQUE,
    p256dh       TEXT,
    auth         TEXT,
    olusturma    TIMESTAMP NOT NULL DEFAULT NOW()
)");

$uid  = (int)$_SESSION['kullanici_id'];
$data = json_decode(file_get_contents('php://input'), true);

if (!$data || empty($data['endpoint'])) {
    echo json_encode(['ok' => false]);
    exit;
}

$endpoint = $data['endpoint'];

// Abonelik silme
if (!empty($data['unsubscribe'])) {
    $db->prepare("DELETE FROM push_subscriptions WHERE endpoint = ? AND kullanici_id = ?")
       ->execute([$endpoint, $uid]);
    echo json_encode(['ok' => true]);
    exit;
}

$p256dh = $data['keys']['p256dh'] ?? '';
$auth   = $data['keys']['auth']   ?? '';

// Kaydet veya güncelle
$db->prepare("INSERT INTO push_subscriptions (kullanici_id, endpoint, p256dh, auth)
    VALUES (?,?,?,?)
    ON CONFLICT (endpoint)
    DO UPDATE SET kullanici_id = EXCLUDED.kullanici_id,
                  p256dh       = EXCLUDED.p256dh,
                  auth         = EXCLUDED.auth")
   ->execute([$uid, $endpoint, $p256dh, $auth]);

echo json_encode(['ok' => true]);
