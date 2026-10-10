<?php
session_start();
require __DIR__ . '/config.php';

if (!isset($_SESSION['kullanici_id'])) { header("Location: index.php"); exit; }
$rol = $_SESSION['rol'];
if ($rol !== 'admin' && $rol !== 'yonetici') { header("Location: dashboard.php"); exit; }

$db->exec("CREATE TABLE IF NOT EXISTS secenekler (
    id       SERIAL PRIMARY KEY,
    kategori VARCHAR(50)  NOT NULL,
    deger    VARCHAR(200) NOT NULL,
    renk     VARCHAR(80)  NOT NULL DEFAULT '',
    sira     INTEGER      NOT NULL DEFAULT 0,
    UNIQUE (kategori, deger)
)");

// Özel sorular (2026-09-23, kullanıcı talebiyle: "soru ve cevap ekleyebileyim, ismini değiştirebileyim").
// Her soru müşteri formunda bir açılır liste olur; cevap şıkları secenekler tablosunda
// kategori = 'soru_<id>' olarak tutulur, müşterinin cevabı musteriler.soru_cevaplari JSONB'de.
$db->exec("CREATE TABLE IF NOT EXISTS musteri_sorulari (
    id        SERIAL PRIMARY KEY,
    baslik    VARCHAR(200) NOT NULL,
    sira      INTEGER NOT NULL DEFAULT 0,
    olusturma TIMESTAMP NOT NULL DEFAULT NOW()
)");
$db->exec("ALTER TABLE musteriler ADD COLUMN IF NOT EXISTS soru_cevaplari JSONB NOT NULL DEFAULT '{}'::jsonb");

/* ── Soru ekle / yeniden adlandır / sil ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['soru_ekle'])) {
    csrf_dogrula_post();
    $baslik = trim($_POST['soru_baslik'] ?? '');
    if ($baslik !== '') {
        $sira = (int)$db->query("SELECT COALESCE(MAX(sira),-1)+1 FROM musteri_sorulari")->fetchColumn();
        $st = $db->prepare("INSERT INTO musteri_sorulari (baslik, sira) VALUES (?,?) RETURNING id");
        $st->execute([$baslik, $sira]);
        header("Location: secenekler.php?kat=soru_" . (int)$st->fetchColumn() . "&msj=soru_eklendi");
        exit;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['soru_adlandir'])) {
    csrf_dogrula_post();
    $sid = (int)$_POST['soru_id']; $baslik = trim($_POST['soru_baslik'] ?? '');
    if ($sid && $baslik !== '') {
        $db->prepare("UPDATE musteri_sorulari SET baslik=? WHERE id=?")->execute([$baslik, $sid]);
        header("Location: secenekler.php?kat=soru_{$sid}&msj=soru_guncellendi");
        exit;
    }
}
if (isset($_GET['soru_sil'])) {
    csrf_dogrula();
    $sid = (int)$_GET['soru_sil'];
    $db->prepare("DELETE FROM secenekler WHERE kategori=?")->execute(['soru_' . $sid]);
    $db->prepare("UPDATE musteriler SET soru_cevaplari = soru_cevaplari - ?")->execute([(string)$sid]);
    $db->prepare("DELETE FROM musteri_sorulari WHERE id=?")->execute([$sid]);
    header("Location: secenekler.php?msj=soru_silindi");
    exit;
}
$sorular = $db->query("SELECT * FROM musteri_sorulari ORDER BY sira, id")->fetchAll();

$kategoriler = [
    'durum'          => ['label' => 'Durum',              'sutun' => 'durum'],
    'teslimat'       => ['label' => 'Teslimat Süreci',    'sutun' => 'teslimat_sureci'],
    'calisma_modeli' => ['label' => 'Çalışma Modeli',     'sutun' => 'calisma_modeli'],
    'kanal'          => ['label' => 'Ulaşım Kanalı',      'sutun' => 'ulasim_kanali'],
    'temsilci'       => ['label' => 'Müşteri Temsilcisi', 'sutun' => 'musteri_temsilcisi'],
    // Bizim kendi müşteri temsilcimizden AYRI — ödeme kuruluşundaki (Paytr, Moka
    // vb.) ilgili kişi.
    'odeme_temsilcisi' => ['label' => 'Ödeme Kuruluşu Temsilcisi', 'sutun' => 'odeme_kurulusu_temsilcisi'],
];
foreach ($sorular as $soru) {
    $kategoriler['soru_' . $soru['id']] = ['label' => $soru['baslik'], 'sutun' => null, 'soru_id' => (int)$soru['id']];
}

$renkler = [
    ''                   => ['label' => 'Gri (varsayılan)',  'cls' => 'bg-secondary'],
    'bg-primary'         => ['label' => 'Mavi',              'cls' => 'bg-primary'],
    'bg-success'         => ['label' => 'Yeşil',             'cls' => 'bg-success'],
    'bg-danger'          => ['label' => 'Kırmızı',           'cls' => 'bg-danger'],
    'bg-warning text-dark' => ['label' => 'Sarı',            'cls' => 'bg-warning text-dark'],
    'bg-info text-dark'  => ['label' => 'Açık Mavi',         'cls' => 'bg-info text-dark'],
    'bg-dark'            => ['label' => 'Siyah',             'cls' => 'bg-dark'],
];

$msj  = '';
$msjT = 'success';

/* ── Ekle ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ekle'])) {
    csrf_dogrula_post();
    $kat   = $_POST['kategori'] ?? '';
    $deger = trim($_POST['deger'] ?? '');
    $renk  = $_POST['renk'] ?? '';
    if (isset($kategoriler[$kat]) && $deger !== '') {
        $sira = (int)$db->query("SELECT COALESCE(MAX(sira),-1)+1 FROM secenekler WHERE kategori='$kat'")->fetchColumn();
        try {
            $db->prepare("INSERT INTO secenekler (kategori,deger,renk,sira) VALUES (?,?,?,?)")
               ->execute([$kat, $deger, $renk, $sira]);
            header("Location: secenekler.php?kat=$kat&msj=eklendi");
            exit;
        } catch (PDOException $e) {
            $msj  = "Bu değer zaten mevcut.";
            $msjT = 'danger';
        }
    }
}

/* ── Güncelle ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guncelle'])) {
    csrf_dogrula_post();
    $id         = (int)$_POST['id'];
    $yeni_deger = trim($_POST['yeni_deger'] ?? '');
    $yeni_renk  = $_POST['yeni_renk'] ?? '';
    if ($yeni_deger !== '') {
        $stmt = $db->prepare("SELECT * FROM secenekler WHERE id = ?");
        $stmt->execute([$id]);
        $eski = $stmt->fetch();
        if ($eski) {
            try {
                $db->prepare("UPDATE secenekler SET deger=?,renk=? WHERE id=?")->execute([$yeni_deger,$yeni_renk,$id]);
                if ($eski['deger'] !== $yeni_deger) {
                    $sutun = $kategoriler[$eski['kategori']]['sutun'] ?? null;
                    if ($sutun) {
                        $db->prepare("UPDATE musteriler SET $sutun=? WHERE $sutun=?")->execute([$yeni_deger,$eski['deger']]);
                    } elseif (!empty($kategoriler[$eski['kategori']]['soru_id'])) {
                        $sid = (string)$kategoriler[$eski['kategori']]['soru_id'];
                        $db->prepare("UPDATE musteriler SET soru_cevaplari = soru_cevaplari || jsonb_build_object(?::text, ?::text) WHERE soru_cevaplari->>? = ?")
                           ->execute([$sid, $yeni_deger, $sid, $eski['deger']]);
                    }
                }
                header("Location: secenekler.php?kat={$eski['kategori']}&msj=guncellendi");
                exit;
            } catch (PDOException $e) {
                $msj  = "Bu değer zaten mevcut.";
                $msjT = 'danger';
            }
        }
    }
}

/* ── Sil ── */
if (isset($_GET['sil_id'])) {
    csrf_dogrula();
    $id   = (int)$_GET['sil_id'];
    $stmt = $db->prepare("SELECT * FROM secenekler WHERE id=?");
    $stmt->execute([$id]);
    $s = $stmt->fetch();
    if ($s) {
        $sutun = $kategoriler[$s['kategori']]['sutun'] ?? null;
        $kullanan = 0;
        if ($sutun) {
            $cs = $db->prepare("SELECT COUNT(*) FROM musteriler WHERE $sutun=? AND silindi_mi=FALSE");
            $cs->execute([$s['deger']]);
            $kullanan = (int)$cs->fetchColumn();
        } elseif (!empty($kategoriler[$s['kategori']]['soru_id'])) {
            $cs = $db->prepare("SELECT COUNT(*) FROM musteriler WHERE soru_cevaplari->>? = ? AND silindi_mi=FALSE");
            $cs->execute([(string)$kategoriler[$s['kategori']]['soru_id'], $s['deger']]);
            $kullanan = (int)$cs->fetchColumn();
        }
        if ($kullanan > 0) {
            header("Location: secenekler.php?kat={$s['kategori']}&msj=kullanimda");
            exit;
        }
        $db->prepare("DELETE FROM secenekler WHERE id=?")->execute([$id]);
        header("Location: secenekler.php?kat={$s['kategori']}&msj=silindi");
        exit;
    }
}

/* ── Yukarı / Aşağı ── */
foreach (['yukari_id' => 'DESC', 'asagi_id' => 'ASC'] as $param => $dir) {
    if (isset($_GET[$param])) {
        $id = (int)$_GET[$param];
        $stmt = $db->prepare("SELECT * FROM secenekler WHERE id=?"); $stmt->execute([$id]); $curr = $stmt->fetch();
        if ($curr) {
            $cmp  = $dir === 'DESC' ? '<' : '>';
            $stmt2 = $db->prepare("SELECT * FROM secenekler WHERE kategori=? AND sira $cmp ? ORDER BY sira $dir LIMIT 1");
            $stmt2->execute([$curr['kategori'], $curr['sira']]);
            $other = $stmt2->fetch();
            if ($other) {
                $db->prepare("UPDATE secenekler SET sira=? WHERE id=?")->execute([$other['sira'], $id]);
                $db->prepare("UPDATE secenekler SET sira=? WHERE id=?")->execute([$curr['sira'], $other['id']]);
            }
        }
        header("Location: secenekler.php?kat=" . ($curr['kategori'] ?? ''));
        exit;
    }
}

$aktif_kat = $_GET['kat'] ?? 'durum';
if (!isset($kategoriler[$aktif_kat])) $aktif_kat = 'durum';

$msj_map = [
    'eklendi'    => ['success', 'Seçenek eklendi.'],
    'guncellendi'=> ['success', 'Seçenek güncellendi.'],
    'silindi'    => ['warning', 'Seçenek silindi.'],
    'kullanimda' => ['danger',  'Bu seçenek müşteri kayıtlarında kullanılıyor, önce müşterileri güncelleyin.'],
    'soru_eklendi'     => ['success', 'Soru eklendi. Şimdi cevap şıklarını girin.'],
    'soru_guncellendi' => ['success', 'Soru adı değiştirildi.'],
    'soru_silindi'     => ['warning', 'Soru ve cevapları silindi.'],
];
if (isset($_GET['msj'], $msj_map[$_GET['msj']])) {
    [$msjT, $msj] = $msj_map[$_GET['msj']];
}

// Kategorideki tüm seçenekleri getir
$secenekler = $db->prepare("SELECT * FROM secenekler WHERE kategori=? ORDER BY sira,id");
$secenekler->execute([$aktif_kat]);
$secenekler = $secenekler->fetchAll();

// Her kategorinin kaç kaydı var
$kat_sayilari = [];
foreach ($db->query("SELECT kategori, COUNT(*) as cnt FROM secenekler GROUP BY kategori")->fetchAll() as $r) {
    $kat_sayilari[$r['kategori']] = (int)$r['cnt'];
}

$sayfa_basligi = 'Seçenek Yönetimi';
require __DIR__ . '/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-4">
    <a href="dashboard.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
    <h5 class="mb-0 text-primary"><i class="bi bi-sliders me-2"></i>Seçenek Yönetimi</h5>
    <span class="text-muted small ms-1">— Dropdown listelerini buradan düzenleyin</span>
</div>

<?php if ($msj): ?>
    <div class="alert alert-<?= $msjT ?> alert-dismissible fade show py-2 small mb-3">
        <i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($msj) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <!-- Sol: Kategori menüsü -->
    <div class="col-md-3 mb-3">
        <div class="card p-0">
            <div class="list-group list-group-flush rounded" style="border-radius:14px!important;overflow:hidden;">
                <?php foreach ($kategoriler as $k => $info): if (!empty($info['soru_id'])) continue; ?>
                <a href="secenekler.php?kat=<?= $k ?>"
                   class="list-group-item list-group-item-action d-flex justify-content-between align-items-center
                          <?= $aktif_kat === $k ? 'active' : '' ?>">
                    <span><i class="bi bi-tag me-2 opacity-75"></i><?= $info['label'] ?></span>
                    <span class="badge <?= $aktif_kat === $k ? 'bg-white text-primary' : 'bg-primary' ?> rounded-pill">
                        <?= $kat_sayilari[$k] ?? 0 ?>
                    </span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Sorular: müşteri formuna eklenen özel soru & cevaplar -->
        <div class="card p-0 mt-3">
            <div class="px-3 pt-3 pb-2 small fw-semibold text-muted"><i class="bi bi-question-circle me-1"></i>SORULAR</div>
            <div class="list-group list-group-flush">
                <?php foreach ($sorular as $soru): $k = 'soru_' . $soru['id']; ?>
                <div class="list-group-item d-flex align-items-center gap-1 px-2 <?= $aktif_kat === $k ? 'active' : '' ?>">
                    <a href="secenekler.php?kat=<?= $k ?>" class="flex-grow-1 text-decoration-none <?= $aktif_kat === $k ? 'text-white' : '' ?>">
                        <i class="bi bi-chat-square-text me-1 opacity-75"></i><?= htmlspecialchars($soru['baslik']) ?>
                        <span class="badge <?= $aktif_kat === $k ? 'bg-white text-primary' : 'bg-primary' ?> rounded-pill ms-1"><?= $kat_sayilari[$k] ?? 0 ?></span>
                    </a>
                    <button type="button" class="btn btn-sm py-0 px-1 <?= $aktif_kat === $k ? 'text-white' : 'text-secondary' ?> soru-adlandir-btn"
                            data-id="<?= $soru['id'] ?>" data-baslik="<?= htmlspecialchars($soru['baslik'], ENT_QUOTES) ?>"
                            data-bs-toggle="modal" data-bs-target="#soruAdModal" title="Adını değiştir"><i class="bi bi-pencil"></i></button>
                    <a href="?soru_sil=<?= $soru['id'] ?>&csrf=<?= $_SESSION['csrf_token'] ?>" class="btn btn-sm py-0 px-1 <?= $aktif_kat === $k ? 'text-white' : 'text-danger' ?>"
                       title="Soruyu sil" onclick="return confirm('Soru ve tüm cevap şıkları silinecek, müşterilerdeki cevaplar da kaldırılacak. Emin misiniz?')"><i class="bi bi-x-lg"></i></a>
                </div>
                <?php endforeach; ?>
                <?php if (!$sorular): ?><div class="list-group-item small text-muted">Henüz soru yok.</div><?php endif; ?>
            </div>
            <form method="POST" class="p-2 d-flex gap-1 border-top">
                <input type="hidden" name="csrf" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="text" name="soru_baslik" class="form-control form-control-sm" placeholder="Yeni soru (ör. Kaç yıldır faal?)" required>
                <button type="submit" name="soru_ekle" class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-plus-lg"></i></button>
            </form>
        </div>
    </div>

    <!-- Sağ: Seçenek listesi + ekle formu -->
    <div class="col-md-9">
        <div class="card p-4">
            <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                <h6 class="mb-0 text-primary">
                    <i class="bi bi-list-ul me-1"></i><?= htmlspecialchars($kategoriler[$aktif_kat]['label']) ?> <?= !empty($kategoriler[$aktif_kat]['soru_id']) ? 'Cevapları' : 'Seçenekleri' ?>
                </h6>
                <span class="text-muted small"><?= count($secenekler) ?> seçenek</span>
            </div>

            <?php if (empty($secenekler)): ?>
                <p class="text-muted text-center py-3 small"><i class="bi bi-inbox d-block fs-4 mb-1"></i>Henüz seçenek yok.</p>
            <?php else: ?>
            <div class="table-responsive mb-4">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:36px;">#</th>
                            <th>Seçenek Değeri</th>
                            <th>Renk</th>
                            <th style="width:130px;">Sıra</th>
                            <th style="width:90px;">İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($secenekler as $i => $s): ?>
                        <tr>
                            <td class="text-muted small"><?= $i+1 ?></td>
                            <td>
                                <?php $cls = $renkler[$s['renk']]['cls'] ?? 'bg-secondary'; ?>
                                <span class="badge <?= $cls ?>"><?= htmlspecialchars($s['deger']) ?></span>
                            </td>
                            <td class="small text-muted"><?= $renkler[$s['renk']]['label'] ?? 'Gri' ?></td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a href="?yukari_id=<?= $s['id'] ?>&kat=<?= $aktif_kat ?>"
                                       class="btn btn-xs btn-outline-secondary py-0 px-1 <?= $i===0?'disabled':'' ?>"
                                       title="Yukarı">↑</a>
                                    <a href="?asagi_id=<?= $s['id'] ?>&kat=<?= $aktif_kat ?>"
                                       class="btn btn-xs btn-outline-secondary py-0 px-1 <?= $i===count($secenekler)-1?'disabled':'' ?>"
                                       title="Aşağı">↓</a>
                                </div>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-secondary me-1 duzenle-btn"
                                        data-id="<?= $s['id'] ?>"
                                        data-deger="<?= htmlspecialchars($s['deger'], ENT_QUOTES) ?>"
                                        data-renk="<?= htmlspecialchars($s['renk'], ENT_QUOTES) ?>"
                                        data-bs-toggle="modal" data-bs-target="#duzenleModal">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <a href="?sil_id=<?= $s['id'] ?>&kat=<?= $aktif_kat ?>&csrf=<?= $_SESSION['csrf_token'] ?>"
                                   class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Bu seçeneği silmek istediğinize emin misiniz?')">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Yeni Ekle -->
            <div class="border-top pt-3">
                <h6 class="small fw-semibold text-muted mb-2"><i class="bi bi-plus-circle me-1"></i>Yeni Seçenek Ekle</h6>
                <form method="POST" class="d-flex gap-2 flex-wrap align-items-end">
                    <input type="hidden" name="csrf" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="kategori" value="<?= $aktif_kat ?>">
                    <div style="flex:1;min-width:180px;">
                        <label class="form-label small text-muted mb-1">Değer</label>
                        <input type="text" name="deger" class="form-control form-control-sm" placeholder="Yeni seçenek..." required>
                    </div>
                    <div style="min-width:160px;">
                        <label class="form-label small text-muted mb-1">Renk</label>
                        <select name="renk" class="form-select form-select-sm" id="yeniRenkSec">
                            <?php foreach ($renkler as $val => $info): ?>
                                <option value="<?= $val ?>"><?= $info['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label small text-muted mb-1">Önizleme</label><br>
                        <span class="badge bg-secondary" id="yeniOnizleme">Örnek</span>
                    </div>
                    <div>
                        <button type="submit" name="ekle" class="btn btn-primary btn-sm">
                            <i class="bi bi-plus-lg me-1"></i>Ekle
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Düzenle Modal -->
<div class="modal fade" id="duzenleModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title"><i class="bi bi-pencil-fill text-primary me-2"></i>Seçenek Düzenle</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body pb-2">
                    <input type="hidden" name="csrf" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="id" id="duzId">
                    <div class="mb-3">
                        <label class="form-label small text-muted mb-1">Değer</label>
                        <input type="text" name="yeni_deger" id="duzDeger" class="form-control form-control-sm" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small text-muted mb-1">Renk</label>
                        <select name="yeni_renk" id="duzRenk" class="form-select form-select-sm">
                            <?php foreach ($renkler as $val => $info): ?>
                                <option value="<?= $val ?>"><?= $info['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <span class="badge bg-secondary" id="duzOnizleme">Önizleme</span>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" name="guncelle" class="btn btn-sm btn-primary">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Soru adını değiştir -->
<div class="modal fade" id="soruAdModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title"><i class="bi bi-pencil-fill text-primary me-2"></i>Soru Adını Değiştir</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body pb-2">
                    <input type="hidden" name="csrf" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="soru_id" id="soruAdId">
                    <label class="form-label small text-muted mb-1">Soru</label>
                    <input type="text" name="soru_baslik" id="soruAdBaslik" class="form-control form-control-sm" required>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" name="soru_adlandir" class="btn btn-sm btn-primary">Kaydet</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const renkHaritasi = <?= json_encode(array_map(fn($v) => $v['cls'], $renkler)) ?>;

/* Yeni ekle önizleme */
const yeniRenk     = document.getElementById('yeniRenkSec');
const yeniOnizleme = document.getElementById('yeniOnizleme');
function yeniOnizlemeGuncelle() {
    const cls = renkHaritasi[yeniRenk.value] || 'bg-secondary';
    yeniOnizleme.className = 'badge ' + cls;
}
yeniRenk.addEventListener('change', yeniOnizlemeGuncelle);

/* Soru adı modalı */
document.querySelectorAll('.soru-adlandir-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('soruAdId').value     = btn.dataset.id;
        document.getElementById('soruAdBaslik').value = btn.dataset.baslik;
    });
});

/* Düzenle modal */
document.querySelectorAll('.duzenle-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('duzId').value    = btn.dataset.id;
        document.getElementById('duzDeger').value = btn.dataset.deger;
        const renkSec = document.getElementById('duzRenk');
        renkSec.value = btn.dataset.renk;
        duzOnizlemeGuncelle();
    });
});
const duzRenk     = document.getElementById('duzRenk');
const duzOnizleme = document.getElementById('duzOnizleme');
function duzOnizlemeGuncelle() {
    if (!duzRenk) return;
    const cls = renkHaritasi[duzRenk.value] || 'bg-secondary';
    duzOnizleme.className  = 'badge ' + cls;
    duzOnizleme.textContent = document.getElementById('duzDeger')?.value || 'Önizleme';
}
duzRenk?.addEventListener('change', duzOnizlemeGuncelle);
document.getElementById('duzDeger')?.addEventListener('input', duzOnizlemeGuncelle);
</script>
</body>
</html>
