<?php
require __DIR__ . '/config.php';

$sql = "
ALTER TABLE kullanicilar
    ADD COLUMN IF NOT EXISTS totp_secret  VARCHAR(32)  DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS totp_enabled BOOLEAN      NOT NULL DEFAULT FALSE;
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
<meta charset="UTF-8"><title>2FA Migrasyon</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    </head><body class="bg-light"><div class="container py-5" style="max-width:500px">
    <div class="card p-4 border-0 shadow-sm">
    <h4 class="text-success mb-3">✓ 2FA Migrasyon Tamamlandı!</h4>
    <p><code>kullanicilar</code> tablosuna <code>totp_secret</code> ve <code>totp_enabled</code> sütunları eklendi.</p>
    <div class="alert alert-warning small">Güvenlik için bu dosyayı sunucudan silin: <strong>totp_migrasyon.php</strong></div>
    <a href="dashboard.php" class="btn btn-primary w-100">Panele Git →</a>
    </div></div></body></html>';
} catch (Exception $e) {
    echo '<div style="color:red;padding:2rem;font-family:sans-serif;">
    <h3>Hata!</h3><p>' . htmlspecialchars($e->getMessage()) . '</p></div>';
}
