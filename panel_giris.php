<?php
// Panelden tek giriş: Ödemeix panelindeki "Başvurular" sekmesi portalı bu adresle açar.
// Panel, iki taraftaki .env dosyalarında duran ortak sırla imzalanmış, 2 dakika geçerli bir adres
// üretir; burada imza doğrulanıp portal oturumu açılır, kullanıcıya ikinci kez şifre sorulmaz.
// İmza yoksa/geçersizse normal giriş ekranına yönlendirilir; portal kendi başına açıldığında
// her şey eskisi gibi çalışır.
require __DIR__ . '/config.php';

function panel_giris_hata(string $mesaj): void
{
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><div style="font:15px/1.6 -apple-system,sans-serif;text-align:center;margin-top:60px">'
        . '<p>' . htmlspecialchars($mesaj) . '</p>'
        . '<p><a href="index.php">Giriş ekranına git</a></p></div>';
    exit;
}

$secret = trim((string)($_ENV['PANEL_SSO_SECRET'] ?? ''));
if ($secret === '') {
    panel_giris_hata('Panelden giriş kapalı: PANEL_SSO_SECRET tanımlı değil.');
}

$eposta = mb_strtolower(trim((string)($_GET['e'] ?? '')));
$son    = (int)($_GET['t'] ?? 0);
$imza   = (string)($_GET['s'] ?? '');

if ($son < time()) {
    panel_giris_hata('Bağlantının süresi doldu. Panelde sayfayı yenileyin.');
}
if ($son > time() + 600) {
    panel_giris_hata('Bağlantı geçersiz.');
}
if (!hash_equals(hash_hmac('sha256', $eposta . '|' . $son, $secret), $imza)) {
    panel_giris_hata('Panelden giriş doğrulanamadı.');
}

// Önce panel kullanıcısının e-postasıyla eşleşen portal kullanıcısı; yoksa .env'de yazan karşılık.
$kullanici = null;
if ($eposta !== '') {
    $s = $db->prepare("SELECT * FROM kullanicilar WHERE lower(email) = ? LIMIT 1");
    $s->execute([$eposta]);
    $kullanici = $s->fetch() ?: null;
}
if (!$kullanici) {
    $varsayilan = trim((string)($_ENV['PANEL_SSO_KULLANICI'] ?? ''));
    if ($varsayilan !== '') {
        $s = $db->prepare("SELECT * FROM kullanicilar WHERE kullanici_adi = ? LIMIT 1");
        $s->execute([$varsayilan]);
        $kullanici = $s->fetch() ?: null;
    }
}
if (!$kullanici) {
    panel_giris_hata('Bu panel kullanıcısının portalda karşılığı yok.');
}

// Sonraki isteklerde de "panelden gelindi" bilinsin (oturum çerezi ayarı bundan önce belirlenir).
$panel_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
setcookie('portal_panelden', '1', [
    'expires'  => time() + 30 * 86400,
    'path'     => '/',
    'secure'   => $panel_https,
    'httponly' => false,
    'samesite' => $panel_https ? 'None' : 'Lax',
]);

session_regenerate_id(true);
$_SESSION['kullanici_id'] = (int)$kullanici['id'];
$_SESSION['ad_soyad']     = $kullanici['ad_soyad'];
$_SESSION['rol']          = $kullanici['rol'];
$_SESSION['giris_zamani'] = time();
$_SESSION['panelden']     = true;

// Panel hangi bölümü istediyse oraya; liste dışında bir şey gelirse ana ekrana.
$izinli = ['dashboard.php', 'demo_talepleri.php', 'basvurular.php', 'secenekler.php', 'basvuru_alanlari.php'];
$hedef  = (string)($_GET['h'] ?? 'dashboard.php');
if (!in_array($hedef, $izinli, true)) {
    $hedef = 'dashboard.php';
}

header('Location: ' . $hedef);
exit;
