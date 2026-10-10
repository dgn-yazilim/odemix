<?php
// Online tahsilat başvuruları (2026-09-23): basvuru.php'den gelenler burada listelenir, tek tıkla müşteri olur.
session_start();
require __DIR__ . '/config.php';
require_once __DIR__ . '/basvuru_helper.php';

if (!isset($_SESSION['kullanici_id'])) { header("Location: index.php"); exit; }
$rol = $_SESSION['rol'];
if ($rol !== 'admin' && $rol !== 'yonetici') { header("Location: dashboard.php"); exit; }

$db->exec("CREATE TABLE IF NOT EXISTS basvurular (
    id SERIAL PRIMARY KEY, firma_unvani VARCHAR(250) NOT NULL, ortaklik_yapisi VARCHAR(50) NOT NULL DEFAULT '', yetkili VARCHAR(200) NOT NULL DEFAULT '',
    eposta VARCHAR(200) NOT NULL DEFAULT '', telefon VARCHAR(50) NOT NULL DEFAULT '', web_sitesi VARCHAR(250) NOT NULL DEFAULT '',
    odeme_yontemleri VARCHAR(100) NOT NULL DEFAULT '', aylik_ciro VARCHAR(50) NOT NULL DEFAULT '', sube_sayisi INTEGER, calisan_sayisi INTEGER,
    detay JSONB NOT NULL DEFAULT '{}'::jsonb, durum VARCHAR(20) NOT NULL DEFAULT 'yeni', musteri_id INTEGER REFERENCES musteriler(id) ON DELETE SET NULL,
    olusturma TIMESTAMP NOT NULL DEFAULT NOW()
)");
basvuru_tablolari_olustur($db);

if (isset($_GET['aktar'])) {
    csrf_dogrula();
    $id = (int)$_GET['aktar'];
    $t = $db->prepare("SELECT * FROM basvurular WHERE id = ? AND durum = 'yeni'"); $t->execute([$id]); $b = $t->fetch();
    if ($b) {
        $ilk_durum = $db->query("SELECT deger FROM secenekler WHERE kategori='durum' ORDER BY sira,id LIMIT 1")->fetchColumn() ?: '';
        $d = json_decode($b['detay'] ?? '{}', true) ?: [];
        $not = "Online başvuru — Ortaklık: {$b['ortaklik_yapisi']} · Ciro: {$b['aylik_ciro']}"
             . (!empty($d['faaliyet']) ? ' · Faaliyet: ' . $d['faaliyet'] : '');
        foreach (($d['alanlar'] ?? []) as $ac) $not .= ' · ' . $ac['ad'] . ': ' . $ac['deger'];
        // Ödeme yöntemi tek seçildiyse Çalışma Modeli olarak aktar ("Blokeli" / "Ertesi Gün"), ikisi de seçildiyse "Ertesi Gün ve Blokeli"
        $model = $b['odeme_yontemleri'];
        if (str_contains($model, ',')) $model = 'Ertesi Gün ve Blokeli';
        $ins = $db->prepare("INSERT INTO musteriler (ad_soyad, ticari_unvan, yetkili_ad_soyad, telefon, eposta, web_sayfasi, calisma_modeli, magaza_sayisi, calisan_sayisi, musteri_notu, durum)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?) RETURNING id");
        $ins->execute([$b['yetkili'], $b['firma_unvani'], $b['yetkili'], $b['telefon'], $b['eposta'], $b['web_sitesi'], $model, $b['sube_sayisi'], $b['calisan_sayisi'], $not, $ilk_durum]);
        $mid = (int)$ins->fetchColumn();
        // Başvuruyla yüklenen belgeleri müşterinin dosyalarına kopyala
        $bl = $db->prepare("SELECT * FROM basvuru_belgeleri WHERE basvuru_id = ? ORDER BY id"); $bl->execute([$id]);
        $hedef = __DIR__ . '/uploads/musteriler/' . $mid . '/';
        if (!is_dir($hedef)) @mkdir($hedef, 0755, true);
        $dins = $db->prepare("INSERT INTO musteri_dosyalari (musteri_id, dosya_adi, dosya_yolu, dosya_boyutu, yukleyen_id) VALUES (?,?,?,?,?)");
        foreach ($bl->fetchAll() as $bg) {
            $kaynak = __DIR__ . '/' . $bg['dosya_yolu'];
            if (!is_file($kaynak)) continue;
            $ad = time() . '_0_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basvuru_slug($bg['alan_adi']) . '-' . $bg['dosya_adi']);
            if (copy($kaynak, $hedef . $ad)) $dins->execute([$mid, $bg['alan_adi'] . ' — ' . $bg['dosya_adi'], 'uploads/musteriler/' . $mid . '/' . $ad, $bg['boyut'], (int)$_SESSION['kullanici_id']]);
        }
        $db->prepare("UPDATE basvurular SET durum='aktarildi', musteri_id=? WHERE id=?")->execute([$mid, $id]);
        header("Location: basvurular.php?msj=aktarildi&mid=$mid"); exit;
    }
    header("Location: basvurular.php"); exit;
}
if (isset($_GET['sil'])) {
    csrf_dogrula();
    $db->prepare("DELETE FROM basvurular WHERE id = ?")->execute([(int)$_GET['sil']]);
    header("Location: basvurular.php?msj=silindi"); exit;
}

$basvurular = $db->query("SELECT * FROM basvurular ORDER BY (durum='yeni') DESC, id DESC")->fetchAll();
$belgeler = [];
foreach ($db->query("SELECT * FROM basvuru_belgeleri ORDER BY id")->fetchAll() as $bg) $belgeler[(int)$bg['basvuru_id']][] = $bg;
$yeni_sayisi = count(array_filter($basvurular, fn($b) => $b['durum'] === 'yeni'));
$sayfa_basligi = 'Başvurular';
require __DIR__ . '/header.php';
?>
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <h5 class="mb-0 text-primary"><i class="bi bi-file-earmark-text me-2"></i>Online Tahsilat Başvuruları</h5>
    <?php if ($yeni_sayisi): ?><span class="badge bg-danger rounded-pill"><?= $yeni_sayisi ?> yeni</span><?php endif; ?>
    <a href="basvuru.php" target="_blank" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-box-arrow-up-right me-1"></i>Başvuru sayfasını aç</a>
</div>
<?php if (($_GET['msj'] ?? '') === 'aktarildi'): ?>
<div class="alert alert-success py-2 small">Başvuru müşteri olarak eklendi. <a href="dashboard.php?ac=<?= (int)($_GET['mid'] ?? 0) ?>" class="alert-link">Müşteriyi aç</a></div>
<?php elseif (($_GET['msj'] ?? '') === 'silindi'): ?>
<div class="alert alert-warning py-2 small">Başvuru silindi.</div>
<?php endif; ?>

<div class="card p-3">
<?php if (!$basvurular): ?>
    <p class="text-muted text-center py-4 mb-0"><i class="bi bi-inbox d-block fs-3 mb-1"></i>Henüz başvuru yok.</p>
<?php else: ?>
    <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light"><tr><th>Tarih</th><th>Firma</th><th>Yetkili / İletişim</th><th>Ödeme Yöntemi</th><th>Ciro</th><th>Şube / Çalışan</th><th>Durum</th><th class="text-end">İşlem</th></tr></thead>
        <tbody>
        <?php foreach ($basvurular as $b): $d = json_decode($b['detay'] ?? '{}', true) ?: []; ?>
            <tr class="<?= $b['durum'] === 'yeni' ? 'fw-semibold' : 'text-muted' ?>">
                <td class="small text-nowrap"><?= date('d.m.Y H:i', strtotime($b['olusturma'])) ?></td>
                <td><?= htmlspecialchars($b['firma_unvani']) ?><br><span class="badge bg-secondary fw-normal"><?= htmlspecialchars($b['ortaklik_yapisi']) ?></span>
                    <?php if ($b['web_sitesi']):
                        // Web sitesi herkese açık başvuru formundan geliyor; yalnız http/https tıklanabilir yapılır.
                        // (Aksi halde "javascript:" gibi bir şema, bağlantıya tıklayan yöneticide XSS çalıştırabilirdi.)
                        $ws = trim($b['web_sitesi']);
                        $ws_link = preg_match('#^https?://#i', $ws) ? $ws : '';
                    ?><br>
                        <?php if ($ws_link): ?><a href="<?= htmlspecialchars($ws_link) ?>" target="_blank" rel="noopener noreferrer nofollow" class="small"><?= htmlspecialchars($ws) ?></a>
                        <?php else: ?><span class="small text-muted"><?= htmlspecialchars($ws) ?></span><?php endif; ?>
                    <?php endif; ?></td>
                <td class="small"><?= htmlspecialchars($b['yetkili']) ?><br><i class="bi bi-telephone"></i> <?= htmlspecialchars($b['telefon']) ?><br><i class="bi bi-envelope"></i> <?= htmlspecialchars($b['eposta']) ?></td>
                <td><?= htmlspecialchars($b['odeme_yontemleri']) ?></td>
                <td><?= htmlspecialchars($b['aylik_ciro']) ?></td>
                <td class="small"><?= (int)$b['sube_sayisi'] ?> şube / <?= (int)$b['calisan_sayisi'] ?> çalışan</td>
                <td>
                    <?php if ($b['durum'] === 'yeni'): ?><span class="badge bg-danger">Yeni</span>
                    <?php else: ?><a href="dashboard.php?ac=<?= (int)$b['musteri_id'] ?>" class="badge bg-success text-decoration-none">Müşteri oldu</a><?php endif; ?>
                </td>
                <td class="text-end text-nowrap">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#detay<?= $b['id'] ?>" title="Detay"><i class="bi bi-eye"></i></button>
                    <?php if ($b['durum'] === 'yeni'): ?>
                    <a href="?aktar=<?= $b['id'] ?>&csrf=<?= $_SESSION['csrf_token'] ?>" class="btn btn-sm btn-primary"><i class="bi bi-person-plus"></i> Müşteri Yap</a>
                    <?php endif; ?>
                    <a href="?sil=<?= $b['id'] ?>&csrf=<?= $_SESSION['csrf_token'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Başvuru silinsin mi?')"><i class="bi bi-trash"></i></a>
                </td>
            </tr>
            <tr class="collapse" id="detay<?= $b['id'] ?>"><td colspan="8" class="small bg-body-tertiary">
                <div class="mb-2"><b>Evraklar (<?= htmlspecialchars(BASVURU_GRUPLARI[$d['grup'] ?? '']['label'] ?? '—') ?><?= !empty($d['faaliyet']) ? ' · ' . htmlspecialchars($d['faaliyet']) : '' ?>):</b>
                    <?php $bgl = $belgeler[(int)$b['id']] ?? []; if (!$bgl): ?> <span class="text-muted">belge yüklenmemiş</span><?php else: ?>
                    <ul class="mb-1 ps-3"><?php foreach ($bgl as $bg): ?><li><?= htmlspecialchars($bg['alan_adi']) ?>: <a href="dosya_goster.php?tur=basvuru&id=<?= (int)$bg['id'] ?>" target="_blank"><?= htmlspecialchars($bg['dosya_adi']) ?></a> <span class="text-muted">(<?= round(($bg['boyut'] ?? 0) / 1024) ?> KB)</span></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                    <?php foreach (($d['alanlar'] ?? []) as $ac): ?><div><?= htmlspecialchars($ac['ad']) ?>: <b><?= htmlspecialchars($ac['deger']) ?></b></div><?php endforeach; ?>
                </div>
                </div>
            </td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
