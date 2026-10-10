<?php
// Demo talepleri (2026-09-23): demo.php'den gelen talepler burada listelenir, tek tıkla müşteri olarak aktarılır.
session_start();
require __DIR__ . '/config.php';

if (!isset($_SESSION['kullanici_id'])) { header("Location: index.php"); exit; }
$rol = $_SESSION['rol'];
if ($rol !== 'admin' && $rol !== 'yonetici') { header("Location: dashboard.php"); exit; }

$db->exec("CREATE TABLE IF NOT EXISTS demo_talepleri (
    id         SERIAL PRIMARY KEY,
    ad_soyad   VARCHAR(200) NOT NULL,
    firma      VARCHAR(200) NOT NULL DEFAULT '',
    eposta     VARCHAR(200) NOT NULL DEFAULT '',
    telefon    VARCHAR(50)  NOT NULL DEFAULT '',
    kaynak     VARCHAR(100) NOT NULL DEFAULT '',
    notu       TEXT         NOT NULL DEFAULT '',
    durum      VARCHAR(20)  NOT NULL DEFAULT 'yeni',
    musteri_id INTEGER REFERENCES musteriler(id) ON DELETE SET NULL,
    olusturma  TIMESTAMP NOT NULL DEFAULT NOW()
)");

// ── Müşteri olarak aktar ──
if (isset($_GET['aktar'])) {
    csrf_dogrula();
    $id = (int)$_GET['aktar'];
    $t = $db->prepare("SELECT * FROM demo_talepleri WHERE id = ? AND durum = 'yeni'"); $t->execute([$id]); $talep = $t->fetch();
    if ($talep) {
        $ilk_durum = $db->query("SELECT deger FROM secenekler WHERE kategori='durum' ORDER BY sira,id LIMIT 1")->fetchColumn() ?: '';
        $ins = $db->prepare("INSERT INTO musteriler (ad_soyad, ticari_unvan, telefon, eposta, ulasim_kanali, musteri_notu, durum)
                             VALUES (?,?,?,?,?,?,?) RETURNING id");
        $ins->execute([$talep['ad_soyad'], $talep['firma'], $talep['telefon'], $talep['eposta'], $talep['kaynak'],
                       "Demo talebi: " . $talep['notu'], $ilk_durum]);
        $mid = (int)$ins->fetchColumn();
        $db->prepare("UPDATE demo_talepleri SET durum='aktarildi', musteri_id=? WHERE id=?")->execute([$mid, $id]);
        header("Location: demo_talepleri.php?msj=aktarildi&mid=$mid"); exit;
    }
    header("Location: demo_talepleri.php"); exit;
}
// ── Sil ──
if (isset($_GET['sil'])) {
    csrf_dogrula();
    $db->prepare("DELETE FROM demo_talepleri WHERE id = ?")->execute([(int)$_GET['sil']]);
    header("Location: demo_talepleri.php?msj=silindi"); exit;
}

$talepler = $db->query("SELECT * FROM demo_talepleri ORDER BY (durum='yeni') DESC, id DESC")->fetchAll();
$yeni_sayisi = count(array_filter($talepler, fn($t) => $t['durum'] === 'yeni'));

$sayfa_basligi = 'Demo Talepleri';
require __DIR__ . '/header.php';
?>
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <h5 class="mb-0 text-primary"><i class="bi bi-rocket-takeoff me-2"></i>Demo Talepleri</h5>
    <?php if ($yeni_sayisi): ?><span class="badge bg-danger rounded-pill"><?= $yeni_sayisi ?> yeni</span><?php endif; ?>
    <a href="demo.php" target="_blank" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-box-arrow-up-right me-1"></i>Demo sayfasını aç</a>
</div>

<?php if (($_GET['msj'] ?? '') === 'aktarildi'): ?>
<div class="alert alert-success py-2 small">Talep müşteri olarak eklendi.
    <a href="dashboard.php?ac=<?= (int)($_GET['mid'] ?? 0) ?>" class="alert-link">Müşteriyi aç</a></div>
<?php elseif (($_GET['msj'] ?? '') === 'silindi'): ?>
<div class="alert alert-warning py-2 small">Talep silindi.</div>
<?php endif; ?>

<div class="card p-3">
<?php if (!$talepler): ?>
    <p class="text-muted text-center py-4 mb-0"><i class="bi bi-inbox d-block fs-3 mb-1"></i>Henüz demo talebi yok.</p>
<?php else: ?>
    <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light"><tr>
            <th>Tarih</th><th>İsim</th><th>Firma</th><th>İletişim</th><th>Kaynak</th><th>Not</th><th>Durum</th><th class="text-end">İşlem</th>
        </tr></thead>
        <tbody>
        <?php foreach ($talepler as $t): ?>
            <tr class="<?= $t['durum'] === 'yeni' ? 'fw-semibold' : 'text-muted' ?>">
                <td class="small text-nowrap"><?= date('d.m.Y H:i', strtotime($t['olusturma'])) ?></td>
                <td><?= htmlspecialchars($t['ad_soyad']) ?></td>
                <td><?= htmlspecialchars($t['firma']) ?></td>
                <td class="small"><i class="bi bi-telephone"></i> <?= htmlspecialchars($t['telefon']) ?><br><i class="bi bi-envelope"></i> <?= htmlspecialchars($t['eposta']) ?></td>
                <td><span class="badge bg-secondary"><?= htmlspecialchars($t['kaynak']) ?></span></td>
                <td class="small fw-normal" style="max-width:320px;white-space:pre-wrap;"><?= htmlspecialchars($t['notu']) ?></td>
                <td>
                    <?php if ($t['durum'] === 'yeni'): ?><span class="badge bg-danger">Yeni</span>
                    <?php else: ?><a href="dashboard.php?ac=<?= (int)$t['musteri_id'] ?>" class="badge bg-success text-decoration-none">Müşteri oldu</a><?php endif; ?>
                </td>
                <td class="text-end text-nowrap">
                    <?php if ($t['durum'] === 'yeni'): ?>
                    <a href="?aktar=<?= $t['id'] ?>&csrf=<?= $_SESSION['csrf_token'] ?>" class="btn btn-sm btn-primary" title="Müşteri olarak ekle"><i class="bi bi-person-plus"></i> Müşteri Yap</a>
                    <?php endif; ?>
                    <a href="?sil=<?= $t['id'] ?>&csrf=<?= $_SESSION['csrf_token'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Talep silinsin mi?')"><i class="bi bi-trash"></i></a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
