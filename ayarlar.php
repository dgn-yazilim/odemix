<?php
session_start();
require __DIR__ . '/config.php';

$sayfa_basligi = 'Sistem Ayarları';
require __DIR__ . '/header.php';

if ($rol !== 'admin') {
    echo '<div class="alert alert-danger">Bu sayfaya erişim yetkiniz yok.</div>';
    require __DIR__ . '/footer.php'; exit;
}

$db->exec("CREATE TABLE IF NOT EXISTS sistem_ayarlari (
    anahtar VARCHAR(100) PRIMARY KEY,
    deger   TEXT NOT NULL DEFAULT ''
)");

$mesaj = '';
$hata  = '';

// Duyuru, şifre sıfırlama kanalı, TurkeySMS ve Gmail SMTP ayarları 2026-09-23'te kaldırıldı (kullanıcı talebiyle).
?>

<div class="row justify-content-center">
<div class="col-12 col-lg-8 col-xl-6">

<div class="d-flex align-items-center gap-3 mb-4">
    <div style="width:46px;height:46px;border-radius:12px;background:linear-gradient(135deg,#4527a0,#5e35b1);
                display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:#fff;">
        <i class="bi bi-gear-fill"></i>
    </div>
    <div>
        <h4 class="mb-0 fw-bold">Sistem Ayarları</h4>
        <span class="text-muted small">Genel ayarlar</span>
    </div>
</div>

<?php if ($mesaj): ?>
<div class="alert alert-success alert-dismissible fade show py-2">
    <i class="bi bi-check-circle me-2"></i><?= $mesaj ?>
    <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($hata): ?>
<div class="alert alert-danger alert-dismissible fade show py-2">
    <i class="bi bi-exclamation-triangle me-2"></i><?= $hata ?>
    <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── Hızlı Bağlantılar ──────────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-body d-flex flex-wrap gap-2">
        <a href="secenekler.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-sliders me-1"></i>Seçenekler
        </a>
    </div>
</div>

<!-- Tehlikeli Bölge (Herkesi Panelden At) kaldırıldı — 2026-09-23, kullanıcı talebiyle -->

</div><!-- col -->
</div><!-- row -->


<?php require __DIR__ . '/footer.php'; ?>
