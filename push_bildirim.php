<?php
// Portal bildirim merkezi — push + WA bildirimleri

require_once __DIR__ . '/vapid.php';

// Bildirim DB tablosu
function bildirim_tablo_olustur(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS portal_bildirimler (
        id                 SERIAL PRIMARY KEY,
        tip                VARCHAR(50)  NOT NULL DEFAULT 'genel',
        baslik             VARCHAR(255) NOT NULL,
        govde              TEXT,
        url                VARCHAR(500),
        musteri_id         INTEGER,
        hedef_kullanici_id INTEGER,
        olusturma          TIMESTAMP    NOT NULL DEFAULT NOW()
    )");
    $db->exec("ALTER TABLE portal_bildirimler ADD COLUMN IF NOT EXISTS hedef_kullanici_id INTEGER");
    $db->exec("CREATE TABLE IF NOT EXISTS bildirim_okundu (
        bildirim_id  INTEGER NOT NULL,
        kullanici_id INTEGER NOT NULL,
        PRIMARY KEY (bildirim_id, kullanici_id)
    )");
}

// Belirli bir kullanıcıya push gönder
function push_kullaniciya_gonder(PDO $db, int $kullanici_id): void {
    try {
        $keys = vapid_keys($db);
        $subs = $db->prepare("SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE kullanici_id = ?");
        $subs->execute([$kullanici_id]);
        foreach ($subs->fetchAll() as $sub) {
            $code = push_send($sub, $keys['public'], $keys['private_pem']);
            if ($code === 410 || $code === 404) {
                $db->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?")
                   ->execute([$sub['endpoint']]);
            }
        }
    } catch (Exception) {}
}

// Tüm admin/yönetici push abonelerine bildirim gönder
function push_herkese_gonder(PDO $db, array $roller = ['admin', 'yonetici']): void {
    try {
        $keys = vapid_keys($db);
        $ph   = implode(',', array_fill(0, count($roller), '?'));
        $subs = $db->prepare(
            "SELECT ps.endpoint, ps.p256dh, ps.auth
             FROM push_subscriptions ps
             JOIN kullanicilar k ON k.id = ps.kullanici_id
             WHERE k.rol IN ($ph)"
        );
        $subs->execute($roller);
        foreach ($subs->fetchAll() as $sub) {
            $code = push_send($sub, $keys['public'], $keys['private_pem']);
            if ($code === 410 || $code === 404) {
                $db->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?")
                   ->execute([$sub['endpoint']]);
            }
        }
    } catch (Exception) {}
}

// Bildirim kaydet + push gönder
function bildirim_gonder(
    PDO $db,
    string $baslik,
    string $govde,
    string $url                = 'dashboard.php',
    string $tip                = 'musteri',
    ?int   $musteri_id         = null,
    ?int   $hedef_kullanici_id = null
): void {
    bildirim_tablo_olustur($db);

    $db->prepare("INSERT INTO portal_bildirimler (tip, baslik, govde, url, musteri_id, hedef_kullanici_id) VALUES (?,?,?,?,?,?)")
       ->execute([$tip, $baslik, $govde, $url, $musteri_id, $hedef_kullanici_id]);

    if ($hedef_kullanici_id) {
        push_kullaniciya_gonder($db, $hedef_kullanici_id);
    } else {
        push_herkese_gonder($db);
    }
}

// İsme göre temsilciyi bul ve ona bildirim gönder
function bildirim_temsilciye_gonder(
    PDO $db,
    string $temsilci_adi,
    string $baslik,
    string $govde,
    string $url        = 'dashboard.php',
    ?int   $musteri_id = null
): void {
    if (!$temsilci_adi) return;
    $stmt = $db->prepare("SELECT id FROM kullanicilar WHERE ad_soyad = ? LIMIT 1");
    $stmt->execute([$temsilci_adi]);
    $uid = $stmt->fetchColumn();
    if (!$uid) return;
    bildirim_gonder($db, $baslik, $govde, $url, 'musteri', $musteri_id, (int)$uid);
}

// ── SW endpoint: okunmamış bildirimleri döndür ───────────────────────────────
if (isset($_GET['api']) && $_GET['api'] === 'sw') {
    session_start();
    header('Content-Type: application/json');

    if (!isset($_SESSION['kullanici_id'])) { echo json_encode([]); exit; }

    require_once __DIR__ . '/config.php';
    bildirim_tablo_olustur($db);

    $uid  = (int)$_SESSION['kullanici_id'];
    $stmt = $db->prepare(
        "SELECT b.id, b.baslik, b.govde, b.url, b.tip
         FROM portal_bildirimler b
         WHERE (b.hedef_kullanici_id IS NULL OR b.hedef_kullanici_id = ?)
           AND NOT EXISTS (
               SELECT 1 FROM bildirim_okundu o
               WHERE o.bildirim_id = b.id AND o.kullanici_id = ?
           )
         ORDER BY b.olusturma DESC
         LIMIT 5"
    );
    $stmt->execute([$uid, $uid]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($rows) {
        $ins = $db->prepare("INSERT INTO bildirim_okundu(bildirim_id,kullanici_id) VALUES(?,?) ON CONFLICT DO NOTHING");
        foreach ($rows as $r) $ins->execute([$r['id'], $uid]);
    }

    echo json_encode($rows);
    exit;
}
