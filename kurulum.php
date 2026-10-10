<?php
require __DIR__ . '/config.php';

// Güvenlik: bu kurulum betiği canlıda erişilebilir kalıyor (silinmesi önerilir).
// Silininceye kadar rastgele üretilmiş bir anahtar olmadan hiçbir şey çalıştırmaz.
$kurulum_anahtari = '3e68b7415abb3f27c0e77bc2d3dcbb85a54f4b1c02e4cd79';
if (!hash_equals($kurulum_anahtari, $_GET['anahtar'] ?? '')) {
    http_response_code(403);
    die('Erişim reddedildi.');
}

$sql = "
CREATE TABLE IF NOT EXISTS kullanicilar (
    id            SERIAL PRIMARY KEY,
    kullanici_adi VARCHAR(100) NOT NULL UNIQUE,
    sifre         VARCHAR(255) NOT NULL,
    ad_soyad      VARCHAR(200) NOT NULL,
    rol           VARCHAR(30)  NOT NULL DEFAULT 'gorevli',
    email         VARCHAR(200),
    telefon       VARCHAR(30),
    olusturma_tarihi TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS musteriler (
    id                  SERIAL PRIMARY KEY,
    ad_soyad            VARCHAR(200) NOT NULL,
    ticari_unvan        VARCHAR(300),
    telefon             VARCHAR(30),
    web_sayfasi         VARCHAR(300),
    yetkili_ad_soyad    VARCHAR(200),
    ili                 VARCHAR(100),
    ilcesi              VARCHAR(100),
    calisma_modeli      VARCHAR(100),
    ulasim_kanali       VARCHAR(100),
    musteri_notu        TEXT,
    durum               VARCHAR(200),
    musteri_temsilcisi  VARCHAR(200),
    teslimat_sureci     VARCHAR(200),
    magaza_sayisi       INTEGER,
    calisan_sayisi      INTEGER,
    gorevli_id          INTEGER REFERENCES kullanicilar(id) ON DELETE SET NULL,
    kayit_tarihi        TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS musteri_dosyalari (
    id             SERIAL PRIMARY KEY,
    musteri_id     INTEGER      NOT NULL REFERENCES musteriler(id) ON DELETE CASCADE,
    dosya_adi      VARCHAR(255) NOT NULL,
    dosya_yolu     VARCHAR(500) NOT NULL,
    dosya_boyutu   BIGINT,
    yukleme_tarihi TIMESTAMP    DEFAULT NOW(),
    yukleyen_id    INTEGER      REFERENCES kullanicilar(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS gruplar (
    id               SERIAL PRIMARY KEY,
    isim             VARCHAR(200) NOT NULL,
    olusturan_id     INTEGER REFERENCES kullanicilar(id) ON DELETE SET NULL,
    olusturma_tarihi TIMESTAMP   NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS grup_uyeler (
    id           SERIAL PRIMARY KEY,
    grup_id      INTEGER NOT NULL REFERENCES gruplar(id) ON DELETE CASCADE,
    kullanici_id INTEGER NOT NULL REFERENCES kullanicilar(id) ON DELETE CASCADE,
    UNIQUE (grup_id, kullanici_id)
);

CREATE TABLE IF NOT EXISTS ekip_mesajlari (
    id          SERIAL PRIMARY KEY,
    gonderen_id INTEGER   NOT NULL REFERENCES kullanicilar(id) ON DELETE CASCADE,
    alici_id    INTEGER   REFERENCES kullanicilar(id) ON DELETE CASCADE,
    grup_id     INTEGER   REFERENCES gruplar(id) ON DELETE CASCADE,
    mesaj       TEXT,
    okundu      BOOLEAN   NOT NULL DEFAULT FALSE,
    tarih       TIMESTAMP NOT NULL DEFAULT NOW()
);
";

try {
    $db->exec($sql);

    $mevcut = $db->query("SELECT COUNT(*) FROM kullanicilar WHERE kullanici_adi = 'admin'")->fetchColumn();
    if (!$mevcut) {
        $db->prepare("INSERT INTO kullanicilar (kullanici_adi, sifre, ad_soyad, rol) VALUES (?,?,?,?)")
           ->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT), 'Sistem Yöneticisi', 'admin']);
    }

    echo '<!DOCTYPE html><html lang="tr"><head>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-P3GQV5P79K"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-P3GQV5P79K');
</script>
<meta charset="UTF-8"><title>Kurulum</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    </head><body class="bg-light"><div class="container py-5" style="max-width:520px">
    <div class="card p-4 border-0 shadow-sm">
    <h4 class="text-success mb-3">✓ Kurulum Tamamlandı!</h4>
    <p>Tüm tablolar oluşturuldu.</p>
    <div class="alert alert-info small">
        <strong>Giriş:</strong> kullanıcı adı <code>admin</code> / şifre <code>admin123</code><br>
        Giriş yaptıktan sonra şifrenizi değiştirin.
    </div>
    <div class="alert alert-warning small">Güvenlik için bu dosyayı silin: <strong>kurulum.php</strong></div>
    <a href="index.php" class="btn btn-primary w-100">Giriş Sayfasına Git →</a>
    </div></div></body></html>';
} catch (Exception $e) {
    echo '<div style="color:red;padding:2rem;font-family:sans-serif;">
    <h3>Hata!</h3><p>' . htmlspecialchars($e->getMessage()) . '</p></div>';
}
