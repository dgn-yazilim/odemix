<?php
require __DIR__ . '/config.php';

$sql = "
DROP TABLE IF EXISTS ekip_mesajlari CASCADE;
DROP TABLE IF EXISTS grup_uyeler CASCADE;
DROP TABLE IF EXISTS gruplar CASCADE;

CREATE TABLE gruplar (
    id               SERIAL PRIMARY KEY,
    isim             VARCHAR(200) NOT NULL,
    olusturan_id     INTEGER REFERENCES kullanicilar(id) ON DELETE SET NULL,
    olusturma_tarihi TIMESTAMP   NOT NULL DEFAULT NOW()
);

CREATE TABLE grup_uyeler (
    id           SERIAL PRIMARY KEY,
    grup_id      INTEGER NOT NULL REFERENCES gruplar(id) ON DELETE CASCADE,
    kullanici_id INTEGER NOT NULL REFERENCES kullanicilar(id) ON DELETE CASCADE,
    UNIQUE (grup_id, kullanici_id)
);

CREATE TABLE ekip_mesajlari (
    id          SERIAL PRIMARY KEY,
    gonderen_id INTEGER   NOT NULL REFERENCES kullanicilar(id) ON DELETE CASCADE,
    alici_id    INTEGER   REFERENCES kullanicilar(id) ON DELETE CASCADE,
    grup_id     INTEGER   REFERENCES gruplar(id) ON DELETE CASCADE,
    mesaj       TEXT,
    okundu      BOOLEAN   NOT NULL DEFAULT FALSE,
    tarih       TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS musteri_dosyalari (
    id             SERIAL PRIMARY KEY,
    musteri_id     INTEGER      NOT NULL REFERENCES musteriler(id) ON DELETE CASCADE,
    dosya_adi      VARCHAR(255) NOT NULL,
    dosya_yolu     VARCHAR(500) NOT NULL,
    dosya_boyutu   BIGINT,
    yukleme_tarihi TIMESTAMP    NOT NULL DEFAULT NOW(),
    yukleyen_id    INTEGER      REFERENCES kullanicilar(id) ON DELETE SET NULL
);
";

try {
    $db->exec($sql);
    echo '<!DOCTYPE html><html lang="tr"><head>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-P3GQV5P79K"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-P3GQV5P79K');
</script>
<meta charset="UTF-8"><title>Migrasyon</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    </head><body class="bg-light"><div class="container py-5" style="max-width:500px">
    <div class="card p-4 border-0 shadow-sm">
    <h4 class="text-success mb-3">✓ Migrasyon Tamamlandı!</h4>
    <p>Eski sohbet tabloları kaldırıldı, yeni şema oluşturuldu.</p>
    <div class="alert alert-warning small">Bu dosyayı sunucudan silin: <strong>migrasyon.php</strong></div>
    <a href="dashboard.php" class="btn btn-primary w-100">Panele Git →</a>
    </div></div></body></html>';
} catch (Exception $e) {
    echo '<div style="color:red;padding:2rem;font-family:sans-serif;">
    <h3>Hata!</h3><p>' . htmlspecialchars($e->getMessage()) . '</p></div>';
}
