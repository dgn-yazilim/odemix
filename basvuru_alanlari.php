<?php
// Başvuru evrak alanları yönetimi (2026-09-23): grup grup belge listeleri; ekle, düzenle, pasife al, sil, sırala.
session_start();
require __DIR__ . '/config.php';
require_once __DIR__ . '/basvuru_helper.php';

if (!isset($_SESSION['kullanici_id'])) { header("Location: index.php"); exit; }
$rol = $_SESSION['rol'];
if ($rol !== 'admin' && $rol !== 'yonetici') { header("Location: dashboard.php"); exit; }
basvuru_tablolari_olustur($db);

$grup = isset(BASVURU_GRUPLARI[$_GET['grup'] ?? '']) ? $_GET['grup'] : 'tuzel';
$msj = ''; $msjT = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_dogrula_post();
    $aksiyon = $_POST['aksiyon'] ?? '';
    if ($aksiyon === 'ekle' && trim($_POST['ad'] ?? '') !== '') {
        $tur = isset(BASVURU_ALAN_TURLERI[$_POST['tur'] ?? '']) ? $_POST['tur'] : 'belge';
        $sira = (int)$db->query("SELECT COALESCE(MAX(sira),0)+1 FROM basvuru_alanlari WHERE grup=" . $db->quote($grup))->fetchColumn();
        $db->prepare("INSERT INTO basvuru_alanlari (grup, ad, slug, tur, min_adet, max_adet, sira) VALUES (?,?,?,?,?,?,?)")
           ->execute([$grup, trim($_POST['ad']), basvuru_slug(trim($_POST['ad'])), $tur, max(0, (int)($_POST['min_adet'] ?? 1)), max(0, (int)($_POST['max_adet'] ?? 10)), $sira]);
        header("Location: basvuru_alanlari.php?grup=$grup&msj=eklendi"); exit;
    }
    if ($aksiyon === 'duzenle' && (int)($_POST['id'] ?? 0) && trim($_POST['ad'] ?? '') !== '') {
        $tur = isset(BASVURU_ALAN_TURLERI[$_POST['tur'] ?? '']) ? $_POST['tur'] : 'belge';
        $db->prepare("UPDATE basvuru_alanlari SET ad=?, slug=?, tur=?, min_adet=?, max_adet=? WHERE id=?")
           ->execute([trim($_POST['ad']), basvuru_slug(trim($_POST['ad'])), $tur, max(0, (int)($_POST['min_adet'] ?? 1)), max(0, (int)($_POST['max_adet'] ?? 10)), (int)$_POST['id']]);
        header("Location: basvuru_alanlari.php?grup=$grup&msj=guncellendi"); exit;
    }
    if ($aksiyon === 'sirala') {
        $st = $db->prepare("UPDATE basvuru_alanlari SET sira=? WHERE id=? AND grup=?");
        foreach ((array)($_POST['sira'] ?? []) as $id => $sira) $st->execute([(int)$sira, (int)$id, $grup]);
        header("Location: basvuru_alanlari.php?grup=$grup&msj=siralandi"); exit;
    }
}
if (isset($_GET['durum_degistir'])) {
    csrf_dogrula();
    $db->prepare("UPDATE basvuru_alanlari SET aktif = NOT aktif WHERE id=?")->execute([(int)$_GET['durum_degistir']]);
    header("Location: basvuru_alanlari.php?grup=$grup&msj=guncellendi"); exit;
}
if (isset($_GET['sil'])) {
    csrf_dogrula();
    $db->prepare("DELETE FROM basvuru_alanlari WHERE id=?")->execute([(int)$_GET['sil']]);
    header("Location: basvuru_alanlari.php?grup=$grup&msj=silindi"); exit;
}
$msj_map = ['eklendi' => ['success', 'Alan eklendi.'], 'guncellendi' => ['success', 'Alan güncellendi.'], 'silindi' => ['warning', 'Alan silindi.'], 'siralandi' => ['success', 'Sıralama kaydedildi.']];
if (isset($_GET['msj'], $msj_map[$_GET['msj']])) [$msjT, $msj] = $msj_map[$_GET['msj']];

$alanlar = basvuru_alanlari($db, $grup, false);
$sayilar = [];
foreach ($db->query("SELECT grup, COUNT(*) FILTER (WHERE aktif) AS aktif, COUNT(*) AS toplam FROM basvuru_alanlari GROUP BY grup")->fetchAll() as $r) $sayilar[$r['grup']] = $r;

$sayfa_basligi = 'Başvuru Alanları';
require __DIR__ . '/header.php';
?>
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <h5 class="mb-0 text-primary"><i class="bi bi-list-check me-2"></i>Başvuru Alanları</h5>
    <span class="text-muted small">— Online başvurunun 2. adımında istenen evraklar. Pasif alanlar yeni başvurularda gösterilmez.</span>
</div>
<?php if ($msj): ?><div class="alert alert-<?= $msjT ?> py-2 small"><?= htmlspecialchars($msj) ?></div><?php endif; ?>

<style>.grup-sekme .nav-link:not(.active) { color: var(--bs-body-color); background: var(--bs-tertiary-bg); }</style>
<ul class="nav nav-pills mb-3 gap-1 grup-sekme">
    <?php foreach (BASVURU_GRUPLARI as $g => $gb): ?>
    <li class="nav-item"><a class="nav-link <?= $g === $grup ? 'active' : '' ?>" href="?grup=<?= $g ?>"><?= htmlspecialchars($gb['label']) ?>
        <span class="badge <?= $g === $grup ? 'bg-white text-primary' : 'bg-secondary' ?> ms-1"><?= (int)($sayilar[$g]['aktif'] ?? 0) ?>/<?= (int)($sayilar[$g]['toplam'] ?? 0) ?></span></a></li>
    <?php endforeach; ?>
</ul>

<div class="card p-3 mb-3">
    <h6 class="fw-semibold mb-1"><?= htmlspecialchars(BASVURU_GRUPLARI[$grup]['label']) ?> Alanları</h6>
    <form method="POST" id="siraForm">
        <input type="hidden" name="csrf" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="aksiyon" value="sirala">
        <?php foreach ($alanlar as $a): ?>
        <div class="d-flex align-items-center gap-3 border rounded p-2 my-2 <?= $a['aktif'] ? '' : 'opacity-50' ?>">
            <input type="number" name="sira[<?= $a['id'] ?>]" value="<?= (int)$a['sira'] ?>" class="form-control form-control-sm text-center" style="width:64px;">
            <div class="flex-grow-1">
                <div class="fw-semibold"><?= htmlspecialchars($a['ad']) ?></div>
                <div class="text-muted small"><?= htmlspecialchars($a['slug']) ?> · <?= BASVURU_ALAN_TURLERI[$a['tur']] ?? $a['tur'] ?><?= $a['tur'] === 'belge' ? " · Min {$a['min_adet']} / Max {$a['max_adet']}" : '' ?></div>
            </div>
            <span class="badge <?= $a['aktif'] ? 'bg-success' : 'bg-secondary' ?>"><?= $a['aktif'] ? 'Aktif' : 'Pasif' ?></span>
            <div class="d-flex gap-1">
                <button type="button" class="btn btn-sm btn-outline-secondary alan-duzenle" data-id="<?= $a['id'] ?>" data-ad="<?= htmlspecialchars($a['ad'], ENT_QUOTES) ?>" data-tur="<?= $a['tur'] ?>" data-min="<?= (int)$a['min_adet'] ?>" data-max="<?= (int)$a['max_adet'] ?>" data-bs-toggle="modal" data-bs-target="#alanModal">Düzenle</button>
                <a href="?grup=<?= $grup ?>&durum_degistir=<?= $a['id'] ?>&csrf=<?= $_SESSION['csrf_token'] ?>" class="btn btn-sm btn-outline-secondary"><?= $a['aktif'] ? 'Pasife Al' : 'Aktif Et' ?></a>
                <a href="?grup=<?= $grup ?>&sil=<?= $a['id'] ?>&csrf=<?= $_SESSION['csrf_token'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Bu alan silinsin mi?')">Sil</a>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$alanlar): ?><p class="text-muted small my-3">Bu grupta alan yok.</p><?php endif; ?>
        <button class="btn btn-outline-primary btn-sm mt-2"><i class="bi bi-sort-numeric-down me-1"></i>Sıralamayı Kaydet</button>
    </form>
</div>

<div class="card p-3">
    <h6 class="fw-semibold mb-2"><i class="bi bi-plus-circle me-1"></i>Yeni Alan Ekle</h6>
    <form method="POST" class="row g-2 align-items-end">
        <input type="hidden" name="csrf" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="aksiyon" value="ekle">
        <div class="col-md-6"><label class="form-label small text-muted mb-1">Alan adı</label><input type="text" name="ad" class="form-control form-control-sm" required></div>
        <div class="col-md-2"><label class="form-label small text-muted mb-1">Tür</label>
            <select name="tur" class="form-select form-select-sm"><?php foreach (BASVURU_ALAN_TURLERI as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="col-md-1"><label class="form-label small text-muted mb-1">Min</label><input type="number" name="min_adet" value="1" min="0" class="form-control form-control-sm"></div>
        <div class="col-md-1"><label class="form-label small text-muted mb-1">Max</label><input type="number" name="max_adet" value="10" min="0" class="form-control form-control-sm"></div>
        <div class="col-md-2"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-plus-lg me-1"></i>Ekle</button></div>
    </form>
</div>

<div class="modal fade" id="alanModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header py-2"><h6 class="modal-title">Alanı Düzenle</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><div class="modal-body">
        <input type="hidden" name="csrf" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="aksiyon" value="duzenle"><input type="hidden" name="id" id="mId">
        <div class="mb-2"><label class="form-label small text-muted mb-1">Alan adı</label><input type="text" name="ad" id="mAd" class="form-control form-control-sm" required></div>
        <div class="row g-2">
            <div class="col-6"><label class="form-label small text-muted mb-1">Tür</label><select name="tur" id="mTur" class="form-select form-select-sm"><?php foreach (BASVURU_ALAN_TURLERI as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
            <div class="col-3"><label class="form-label small text-muted mb-1">Min</label><input type="number" name="min_adet" id="mMin" min="0" class="form-control form-control-sm"></div>
            <div class="col-3"><label class="form-label small text-muted mb-1">Max</label><input type="number" name="max_adet" id="mMax" min="0" class="form-control form-control-sm"></div>
        </div>
    </div>
    <div class="modal-footer py-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">İptal</button><button class="btn btn-sm btn-primary">Kaydet</button></div></form>
</div></div></div>
<?php require __DIR__ . '/footer.php'; ?>
<script>
document.querySelectorAll('.alan-duzenle').forEach(b => b.addEventListener('click', () => {
    mId.value = b.dataset.id; mAd.value = b.dataset.ad; mTur.value = b.dataset.tur; mMin.value = b.dataset.min; mMax.value = b.dataset.max;
}));
</script>
