<?php
session_start();
require __DIR__ . '/config.php';
require_once __DIR__ . '/metin_helper.php';
require_once __DIR__ . '/push_bildirim.php';
require_once __DIR__ . '/basvuru_helper.php'; // evrak grupları + başvuru alanları (dosya penceresi)

// WhatsApp gönderimi 2026-09-23'te kaldırıldı (kullanıcı talebiyle).

// Bir kullanıcıya "müşteri atandı" bildirimi: push + seçili kanal (SMS/E-posta)
if (!function_exists('musteriyi_kullaniciya_bildir')) {
    function musteriyi_kullaniciya_bildir(PDO $db, array $kullanici, string $musteri_adi, string $aciklama, ?int $musteri_id, string $url): void {
        bildirim_gonder($db, 'Yeni Müşteri: ' . $musteri_adi, $aciklama, $url, 'musteri', $musteri_id, (int)$kullanici['id']);

        $kanal = $db->query("SELECT deger FROM sistem_ayarlari WHERE anahtar='kod_kanali'")->fetchColumn() ?: 'email';
        $bildirim_mt = "👤 Size yeni bir müşteri atandı!\n\n*{$musteri_adi}*\n{$aciklama}";
        if ($kanal === 'sms' && !empty($kullanici['telefon'])) {
            require_once __DIR__ . '/sms_helper.php';
            sms_gonder($db, $kullanici['telefon'], "Size yeni müşteri atandı: {$musteri_adi} — {$aciklama}");
        } elseif ($kanal === 'email' && !empty($kullanici['email'])) {
            require_once __DIR__ . '/mail_helper.php';
            $govde = "<p>Merhaba <b>" . htmlspecialchars($kullanici['ad_soyad']) . "</b>,</p>
                      <p>Size yeni bir müşteri atandı.</p>
                      <p><b>Müşteri:</b> " . htmlspecialchars($musteri_adi) . "<br>" . htmlspecialchars($aciklama) . "</p>";
            mail_gonder($db, $kullanici['email'], "Yeni Müşteri Atandı: {$musteri_adi}", $govde);
        }
    }
}

// Auth + rol (tüm handler'lardan önce, HTML çıkışından önce)
if (!isset($_SESSION['kullanici_id'])) { header("Location: index.php"); exit; }
$rol = $_SESSION['rol'];
if ($rol === 'muhasebe' || $rol === 'firma') { session_destroy(); header("Location: index.php?fis=1"); exit; } // fiş rolleri ayrı uygulamaya taşındı (2026-09-23)
// Müşteri silme / çöp kutusu yetkisi (2026-09-17, kullanıcı talebiyle: "admin dışında
// yönetici de müşteri silebilsin") — eskiden yalnızca admin'di.
$silme_yetkili = ($rol === 'admin' || $rol === 'yonetici');

// Bir müşteriye bu kullanıcının erişimi var mı? admin/yönetici tümüne; görevli/üye yalnız
// kendisine atanmış müşteriye. Müşteri listesi zaten bu kuralla filtreleniyor (aşağıda $where);
// dosya görüntüleme/listeleme/silme/yükleme uçlarında da aynı kuralı uygular (IDOR önlemi).
if (!function_exists('musteriye_erisim')) {
    function musteriye_erisim(PDO $db, int $mid, string $rol, int $uid): bool {
        if ($rol === 'admin' || $rol === 'yonetici') return true;
        if ($mid <= 0) return false;
        $s = $db->prepare("SELECT 1 FROM musteriler WHERE id = ? AND gorevli_id = ?");
        $s->execute([$mid, $uid]);
        return (bool) $s->fetchColumn();
    }
}

// ── DB kurulum: eksik tablolar ve sütunlar ────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS musteri_dosyalari (
    id             SERIAL PRIMARY KEY,
    musteri_id     INTEGER      NOT NULL REFERENCES musteriler(id) ON DELETE CASCADE,
    dosya_adi      VARCHAR(255) NOT NULL,
    dosya_yolu     VARCHAR(500) NOT NULL,
    dosya_boyutu   BIGINT,
    yukleme_tarihi TIMESTAMP    DEFAULT NOW(),
    yukleyen_id    INTEGER      REFERENCES kullanicilar(id) ON DELETE SET NULL
)");
// Tablolar zaten varsa eksik sütunları ekle
$db->exec("ALTER TABLE musteri_dosyalari ADD COLUMN IF NOT EXISTS dosya_boyutu   BIGINT");
$db->exec("ALTER TABLE musteri_dosyalari ADD COLUMN IF NOT EXISTS yukleme_tarihi TIMESTAMP DEFAULT NOW()");
$db->exec("ALTER TABLE musteri_dosyalari ADD COLUMN IF NOT EXISTS yukleyen_id    INTEGER");
$db->exec("ALTER TABLE musteriler        ADD COLUMN IF NOT EXISTS magaza_sayisi   INTEGER");
$db->exec("ALTER TABLE musteriler        ADD COLUMN IF NOT EXISTS calisan_sayisi  INTEGER");
$db->exec("ALTER TABLE musteriler        ADD COLUMN IF NOT EXISTS odeme_kurulusu  VARCHAR(100)");
$db->exec("ALTER TABLE musteriler        ADD COLUMN IF NOT EXISTS odeme_kurulusu_temsilcisi VARCHAR(100)");
$db->exec("ALTER TABLE musteriler        ADD COLUMN IF NOT EXISTS silindi_mi      BOOLEAN DEFAULT FALSE");
$db->exec("ALTER TABLE musteriler        ADD COLUMN IF NOT EXISTS silinme_tarihi  TIMESTAMP");
$db->exec("ALTER TABLE musteriler        ADD COLUMN IF NOT EXISTS eposta          VARCHAR(150)");
$db->exec("ALTER TABLE musteriler        ADD COLUMN IF NOT EXISTS asama_isaretleri JSONB NOT NULL DEFAULT '{}'::jsonb");
// Evrak takibi: müşterinin firma türü + her dosyanın hangi evrağa karşılık geldiği.
// Böylece dosya penceresinde neyin yüklendiği ve neyin eksik olduğu görünür.
$db->exec("ALTER TABLE musteriler        ADD COLUMN IF NOT EXISTS evrak_grubu     VARCHAR(20) NOT NULL DEFAULT ''");
$db->exec("ALTER TABLE musteri_dosyalari ADD COLUMN IF NOT EXISTS alan_id         INTEGER");
$db->exec("ALTER TABLE musteri_dosyalari ADD COLUMN IF NOT EXISTS alan_adi        VARCHAR(250) NOT NULL DEFAULT ''");
// 7 günden eski çöpteki kayıtları kalıcı sil
$db->exec("DELETE FROM musteriler WHERE silindi_mi = TRUE AND silinme_tarihi < NOW() - INTERVAL '7 days'");
$db->exec("CREATE TABLE IF NOT EXISTS odeme_kuruluslari (id SERIAL PRIMARY KEY, isim VARCHAR(100) NOT NULL UNIQUE)");
$db->exec("CREATE TABLE IF NOT EXISTS temsilci_istekleri (
    id               SERIAL PRIMARY KEY,
    musteri_id       INTEGER NOT NULL REFERENCES musteriler(id) ON DELETE CASCADE,
    talep_eden_id    INTEGER NOT NULL REFERENCES kullanicilar(id),
    musteri_adi      VARCHAR(200),
    mevcut_temsilci  VARCHAR(200),
    istenen_temsilci VARCHAR(200) NOT NULL,
    durum            VARCHAR(20) NOT NULL DEFAULT 'bekliyor',
    yanitlayan_id    INTEGER REFERENCES kullanicilar(id),
    olusturma        TIMESTAMP NOT NULL DEFAULT NOW()
)");

// ── Ödeme kuruluşu başvuru geçmişi (2026-09-12, kullanıcı talebiyle: "Paratika olumsuz
//    oldu, başka kuruluşa başvurduk — ilerde hatırlamak için nasıl göstersek") ───────
// musteriler.odeme_kurulusu tek alan olduğu için kuruluş değişince eski başvuru
// kayboluyordu. Artık her başvuru burada ayrı satır: kuruluş değiştirilince eski satır
// sonucuyla (olumsuz/onaylandı/vazgeçildi) kapanır, yeni kuruluş için 'bekliyor' satır açılır.
$db->exec("CREATE TABLE IF NOT EXISTS odeme_basvurulari (
    id           SERIAL PRIMARY KEY,
    musteri_id   INTEGER NOT NULL REFERENCES musteriler(id) ON DELETE CASCADE,
    kurulus      VARCHAR(100) NOT NULL,
    temsilci     VARCHAR(100) NOT NULL DEFAULT '',
    sonuc        VARCHAR(20)  NOT NULL DEFAULT 'bekliyor',  -- bekliyor | olumsuz | onaylandi | vazgecildi
    baslangic    TIMESTAMP,
    sonuc_tarihi TIMESTAMP,
    notu         TEXT NOT NULL DEFAULT '',
    olusturma    TIMESTAMP NOT NULL DEFAULT NOW()
)");
// Geri alma günlüğü (2026-09-17, kullanıcı talebiyle: "yanlış yaptığım işlemler olursa yanına
// geri tuşu koy, bir önceki harekete gitsin"). Her kullanıcı hareketi (düzenlemede kuruluş
// değişimi, Olumsuz adımı, yeni müşteri) bir islem_id alır; hareketin dokunduğu her başvuru
// satırı için burada bir kayıt tutulur: satır bu hareketle mi oluştu ('olustur') yoksa
// kapatıldı mı ('kapat', önceki değerleriyle). Geri Al en son islem_id'yi tersine uygular
// ve günlükten siler; tekrar basınca bir önceki harekete geçer (yığın).
$db->exec("CREATE TABLE IF NOT EXISTS odeme_basvuru_islemleri (
    id                  SERIAL PRIMARY KEY,
    musteri_id          INTEGER NOT NULL REFERENCES musteriler(id) ON DELETE CASCADE,
    islem_id            VARCHAR(32) NOT NULL,
    basvuru_id          INTEGER NOT NULL REFERENCES odeme_basvurulari(id) ON DELETE CASCADE,
    tur                 VARCHAR(10) NOT NULL,   -- olustur | kapat
    onceki_sonuc        VARCHAR(20),
    onceki_sonuc_tarihi TIMESTAMP,
    onceki_notu         TEXT,
    onceki_kurulus      VARCHAR(100),           -- müşterinin hareket öncesi ödeme kuruluşu
    zaman               TIMESTAMP NOT NULL DEFAULT NOW()
)");
$ODEME_SONUC_ETIKET = ['bekliyor' => 'Bekliyor', 'olumsuz' => 'Olumsuz', 'onaylandi' => 'Onaylandı', 'vazgecildi' => 'Vazgeçildi'];

// Açık (bekliyor) başvuruyu verilen sonuçla kapatır. Bu özellikten önce eklenmiş
// müşterilerde açık kayıt yoksa, eski kuruluş için kapanmış bir kayıt oluşturur ki geçmiş kaybolmasın.
function odeme_islem_id(): string { return bin2hex(random_bytes(8)); }

function odeme_islem_gunlukle(PDO $db, int $mid, string $islemId, int $basvuruId, string $tur, ?array $onceki, ?string $oncekiKurulus): void {
    $db->prepare("INSERT INTO odeme_basvuru_islemleri (musteri_id, islem_id, basvuru_id, tur, onceki_sonuc, onceki_sonuc_tarihi, onceki_notu, onceki_kurulus)
                  VALUES (?,?,?,?,?,?,?,?)")
       ->execute([$mid, $islemId, $basvuruId, $tur, $onceki['sonuc'] ?? null, $onceki['sonuc_tarihi'] ?? null, $onceki['notu'] ?? null, $oncekiKurulus]);
}

// Açık (bekliyor) başvuruyu verilen sonuçla kapatır ve günlükler.
function odeme_basvurusu_kapat(PDO $db, int $mid, string $kurulus, string $sonuc, string $not = '', ?string $islemId = null, ?string $oncekiKurulus = null): void {
    if ($kurulus === '') return;
    $islemId ??= odeme_islem_id();
    $a = $db->prepare("SELECT id, sonuc, sonuc_tarihi, notu FROM odeme_basvurulari WHERE musteri_id=? AND kurulus=? AND sonuc='bekliyor' ORDER BY id DESC LIMIT 1");
    $a->execute([$mid, $kurulus]);
    $acik = $a->fetch();
    if ($acik) {
        odeme_islem_gunlukle($db, $mid, $islemId, (int)$acik['id'], 'kapat', $acik, $oncekiKurulus);
        $db->prepare("UPDATE odeme_basvurulari SET sonuc=?, sonuc_tarihi=NOW(), notu=CASE WHEN ?<>'' THEN ? ELSE notu END WHERE id=?")
           ->execute([$sonuc, $not, $not, (int)$acik['id']]);
        return;
    }
    // Açık başvuru yok. Müşterinin HİÇ geçmişi yoksa (özellikten önce eklenmiş müşteri) eski
    // kuruluş için kapalı bir kayıt oluştur ki bilgi kaybolmasın. Geçmişi varsa hiçbir şey
    // yapma — eskiden burada koşulsuz INSERT vardı, "Olumsuz" adımına her tıklamada aynı
    // kuruluş için yeni bir kopya satır üretiyordu (2026-09-17 hatası).
    $g = $db->prepare("SELECT COUNT(*) FROM odeme_basvurulari WHERE musteri_id=?");
    $g->execute([$mid]);
    if ((int)$g->fetchColumn() === 0) {
        $st = $db->prepare("INSERT INTO odeme_basvurulari (musteri_id, kurulus, sonuc, sonuc_tarihi, notu) VALUES (?,?,?,NOW(),?) RETURNING id");
        $st->execute([$mid, $kurulus, $sonuc, $not]);
        odeme_islem_gunlukle($db, $mid, $islemId, (int)$st->fetchColumn(), 'olustur', null, $oncekiKurulus);
    }
}
// Yeni kuruluş için açık başvuru satırı ekler ve günlükler (aynı kuruluşa açık kayıt varsa eklemez).
function odeme_basvurusu_ac(PDO $db, int $mid, string $kurulus, string $temsilci = '', ?string $islemId = null, ?string $oncekiKurulus = null): void {
    if ($kurulus === '') return;
    $v = $db->prepare("SELECT 1 FROM odeme_basvurulari WHERE musteri_id=? AND kurulus=? AND sonuc='bekliyor'");
    $v->execute([$mid, $kurulus]);
    if ($v->fetchColumn()) return;
    $st = $db->prepare("INSERT INTO odeme_basvurulari (musteri_id, kurulus, temsilci, sonuc, baslangic) VALUES (?,?,?,'bekliyor',NOW()) RETURNING id");
    $st->execute([$mid, $kurulus, $temsilci]);
    odeme_islem_gunlukle($db, $mid, $islemId ?? odeme_islem_id(), (int)$st->fetchColumn(), 'olustur', null, $oncekiKurulus);
}
// Düzenleme formundan gelen kuruluş değişikliğini geçmişe işler (kapat + aç tek hareket).
function odeme_kurulusu_degisimi_isle(PDO $db, int $mid, string $eski, string $yeni, string $yeniTemsilci, string $eskiSonuc, string $not): void {
    if ($eski === $yeni) return;
    $izinli  = ['olumsuz', 'onaylandi', 'vazgecildi'];
    $islemId = odeme_islem_id();
    odeme_basvurusu_kapat($db, $mid, $eski, in_array($eskiSonuc, $izinli, true) ? $eskiSonuc : 'olumsuz', $not, $islemId, $eski);
    odeme_basvurusu_ac($db, $mid, $yeni, $yeniTemsilci, $islemId, $eski);
}
// Son hareketi geri alır: hareketin oluşturduğu satırlar silinir, kapattığı satırlar önceki
// değerlerine döner, müşterinin ödeme kuruluşu hareket öncesine çekilir, günlük kaydı silinir.
// Günlüğü olmayan (bu özellikten önce yazılmış) satırlarda her satır tek hareket sayılır.
function odeme_basvurusu_geri_al(PDO $db, int $mid): bool {
    $sonLog = $db->prepare("SELECT islem_id FROM odeme_basvuru_islemleri WHERE musteri_id=? ORDER BY id DESC LIMIT 1");
    $sonLog->execute([$mid]);
    $islemId = $sonLog->fetchColumn();

    if ($islemId) {
        $q = $db->prepare("SELECT * FROM odeme_basvuru_islemleri WHERE musteri_id=? AND islem_id=? ORDER BY id DESC");
        $q->execute([$mid, $islemId]);
        $oncekiKurulus = null;
        foreach ($q->fetchAll() as $log) {
            if ($log['onceki_kurulus'] !== null) $oncekiKurulus = $log['onceki_kurulus'];
            if ($log['tur'] === 'olustur') {
                $db->prepare("DELETE FROM odeme_basvurulari WHERE id=?")->execute([(int)$log['basvuru_id']]);
            } else {
                $db->prepare("UPDATE odeme_basvurulari SET sonuc=?, sonuc_tarihi=?, notu=? WHERE id=?")
                   ->execute([$log['onceki_sonuc'] ?: 'bekliyor', $log['onceki_sonuc_tarihi'], $log['onceki_notu'] ?? '', (int)$log['basvuru_id']]);
            }
        }
        $db->prepare("DELETE FROM odeme_basvuru_islemleri WHERE musteri_id=? AND islem_id=?")->execute([$mid, $islemId]);
        if ($oncekiKurulus !== null) {
            $db->prepare("UPDATE musteriler SET odeme_kurulusu=? WHERE id=?")->execute([$oncekiKurulus, $mid]);
        }
        return true;
    }

    // Günlük yok: en yeni satırı tek hareket say (eski kayıtlar)
    $son = $db->prepare("SELECT * FROM odeme_basvurulari WHERE musteri_id=? ORDER BY id DESC LIMIT 1");
    $son->execute([$mid]);
    $r = $son->fetch();
    if (!$r) return false;
    if ($r['sonuc'] === 'bekliyor' || $r['baslangic'] === null) {
        $db->prepare("DELETE FROM odeme_basvurulari WHERE id=?")->execute([(int)$r['id']]);
        if ($r['sonuc'] === 'bekliyor') {
            $k = $db->prepare("SELECT kurulus FROM odeme_basvurulari WHERE musteri_id=? AND sonuc='bekliyor' ORDER BY id DESC LIMIT 1");
            $k->execute([$mid]);
            $kalan = $k->fetchColumn();
            if ($kalan !== false) $db->prepare("UPDATE musteriler SET odeme_kurulusu=? WHERE id=?")->execute([$kalan, $mid]);
        }
    } else {
        $db->prepare("UPDATE odeme_basvurulari SET sonuc='bekliyor', sonuc_tarihi=NULL, notu='' WHERE id=?")->execute([(int)$r['id']]);
    }
    return true;
}

// ── Seçenekler tablosu ────────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS secenekler (
    id       SERIAL PRIMARY KEY,
    kategori VARCHAR(50)  NOT NULL,
    deger    VARCHAR(200) NOT NULL,
    renk     VARCHAR(80)  NOT NULL DEFAULT '',
    sira     INTEGER      NOT NULL DEFAULT 0,
    UNIQUE (kategori, deger)
)");
$kat_sayisi = [];
foreach ($db->query("SELECT kategori, COUNT(*) as cnt FROM secenekler GROUP BY kategori")->fetchAll() as $r) {
    $kat_sayisi[$r['kategori']] = (int)$r['cnt'];
}
$tohumlar = [
    'durum' => [
        ['Bilgilendirme Yapıldı','bg-info text-dark'],['Demo Açıldı','bg-primary'],
        ['Teklif Verildi','bg-warning text-dark'],['Evrak İstendi','bg-warning text-dark'],
        ['Ön Başvuru Yapıldı','bg-primary'],['Eksik Evrak Sürecinde','bg-danger'],
        ['Sözleşme Süreci ve Kimlik Doğrulama','bg-success'],['Banka Tanımlamasında','bg-success'],
    ],
    'teslimat'       => [['Teslim Edildi','bg-success'],['Eğitim Verildi','bg-primary'],['Test Çekim Yapıldı','bg-info text-dark']],
    'calisma_modeli' => [['Ertesi Gün',''],['Blokeli',''],['Ertesi Gün ve Blokeli','']],
    'kanal'          => [['Tavsiye',''],['Müşterinizde Gördüm',''],['Facebook',''],['Instagram',''],['Google',''],['YouTube Reklamı','']],
    'temsilci'       => [['Taner Doğan',''],['Abdullah Doğan',''],['Cengiz Doğan','']],
];
foreach ($tohumlar as $kat => $rows) {
    if (empty($kat_sayisi[$kat])) {
        foreach ($rows as $i => [$deger, $renk]) {
            $db->prepare("INSERT INTO secenekler (kategori,deger,renk,sira) VALUES (?,?,?,?) ON CONFLICT DO NOTHING")
               ->execute([$kat, $deger, $renk, $i]);
        }
    }
}
$durum_secenekleri   = $db->query("SELECT deger FROM secenekler WHERE kategori='durum'          ORDER BY sira,id")->fetchAll(PDO::FETCH_COLUMN);
$teslimat_secenekleri= $db->query("SELECT deger FROM secenekler WHERE kategori='teslimat'       ORDER BY sira,id")->fetchAll(PDO::FETCH_COLUMN);
$calisma_modeli_sec  = $db->query("SELECT deger FROM secenekler WHERE kategori='calisma_modeli' ORDER BY sira,id")->fetchAll(PDO::FETCH_COLUMN);
$kanal_secenekleri   = $db->query("SELECT deger FROM secenekler WHERE kategori='kanal'          ORDER BY sira,id")->fetchAll(PDO::FETCH_COLUMN);
$temsilciler         = $db->query("SELECT deger FROM secenekler WHERE kategori='temsilci'       ORDER BY sira,id")->fetchAll(PDO::FETCH_COLUMN);
// Ödeme kuruluşundaki (Paytr, Moka vb.) kişi — bizim kendi müşteri temsilcimizden
// AYRI bir alan, karışmasın diye ayrı kategori.
$odeme_temsilcileri  = $db->query("SELECT deger FROM secenekler WHERE kategori='odeme_temsilcisi' ORDER BY sira,id")->fetchAll(PDO::FETCH_COLUMN);
// Özel sorular (Seçenekler > Sorular'dan yönetilir): formda açılır liste, cevap müşteri kaydında JSONB
$db->exec("CREATE TABLE IF NOT EXISTS musteri_sorulari (id SERIAL PRIMARY KEY, baslik VARCHAR(200) NOT NULL, sira INTEGER NOT NULL DEFAULT 0, olusturma TIMESTAMP NOT NULL DEFAULT NOW())");
$db->exec("ALTER TABLE musteriler ADD COLUMN IF NOT EXISTS soru_cevaplari JSONB NOT NULL DEFAULT '{}'::jsonb");
$sorular = $db->query("SELECT id, baslik FROM musteri_sorulari ORDER BY sira, id")->fetchAll();
$soru_secenekleri = [];
foreach ($sorular as $soru) {
    $ss = $db->prepare("SELECT deger FROM secenekler WHERE kategori=? ORDER BY sira,id");
    $ss->execute(['soru_' . $soru['id']]);
    $soru_secenekleri[$soru['id']] = $ss->fetchAll(PDO::FETCH_COLUMN);
}
// Formdan gelen cevapları JSON'a çevirir (yalnız tanımlı sorular, yalnız dolu cevaplar)
function soru_cevaplari_json(array $sorular): string {
    $c = [];
    foreach ($sorular as $soru) {
        $v = trim((string)($_POST['soru'][$soru['id']] ?? ''));
        if ($v !== '') $c[(string)$soru['id']] = $v;
    }
    return json_encode($c, JSON_UNESCAPED_UNICODE);
}
$odeme_kuruluslari_sabit = ['Paytr', 'Moka', 'Rubikapara', 'Paratika', 'Esnekpos', 'Sipay', 'Parampos', 'Paratika/Tarım Kart'];
$ozel = $db->query("SELECT isim FROM odeme_kuruluslari ORDER BY isim")->fetchAll(PDO::FETCH_COLUMN);
$odeme_kuruluslari = array_unique(array_merge($odeme_kuruluslari_sabit, $ozel));
$izinli_uzantilar     = ['pdf','doc','docx','xls','xlsx','png','jpg','jpeg','gif','webp','txt','zip','rar'];

$gorevliler = $db->query("SELECT id, ad_soyad FROM kullanicilar WHERE rol IN ('gorevli','uye') ORDER BY ad_soyad")->fetchAll();

// ── AJAX: Yeni ödeme kuruluşu ekle ───────────────────────────────────────────
if (isset($_GET['api']) && $_GET['api'] === 'odeme_kurulusu_ekle') {
    header('Content-Type: application/json');
    $yetkili = ($rol === 'admin' || $rol === 'yonetici' || $_SESSION['kullanici_adi'] === 'azzet');
    if (!$yetkili) { echo json_encode(['ok' => false]); exit; }
    $isim = trim($_POST['isim'] ?? '');
    if ($isim) {
        $db->prepare("INSERT INTO odeme_kuruluslari (isim) VALUES (?) ON CONFLICT (isim) DO NOTHING")->execute([$isim]);
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false]);
    }
    exit;
}

// ── AJAX: Dosya listesi ───────────────────────────────────────────────────────
if (isset($_GET['api']) && $_GET['api'] === 'dosyalar') {
    header('Content-Type: application/json');
    $mid  = (int)$_GET['musteri_id'];
    if (!musteriye_erisim($db, $mid, $rol, (int)$_SESSION['kullanici_id'])) { echo json_encode(['hata' => 'yetkisiz']); exit; }
    $stmt = $db->prepare("SELECT d.id, d.dosya_adi, d.dosya_yolu, d.dosya_boyutu, d.alan_id, d.alan_adi,
        d.yukleme_tarihi, k.ad_soyad AS yukleyen
        FROM musteri_dosyalari d
        LEFT JOIN kullanicilar k ON d.yukleyen_id = k.id
        WHERE d.musteri_id = ? ORDER BY d.id DESC");
    $stmt->execute([$mid]);
    $dosyalar = $stmt->fetchAll();
    foreach ($dosyalar as &$d) {
        $d['tarih']   = $d['yukleme_tarihi'] ? date('d.m.Y H:i', strtotime($d['yukleme_tarihi'])) : '';
        $d['boyut']   = $d['dosya_boyutu']   ? round($d['dosya_boyutu'] / 1024, 1) . ' KB' : '';
        $d['uzanti']  = strtolower(pathinfo($d['dosya_adi'], PATHINFO_EXTENSION));
        $d['alan_id'] = $d['alan_id'] !== null ? (int)$d['alan_id'] : null;
    }
    unset($d);

    // Müşterinin firma türü ve o türde istenen evrak listesi (başvuru alanlarından).
    $grup_stmt = $db->prepare("SELECT evrak_grubu FROM musteriler WHERE id = ?");
    $grup_stmt->execute([$mid]);
    $grup = (string)($grup_stmt->fetchColumn() ?: '');

    $belgeler = [];
    if ($grup !== '') {
        $alan_stmt = $db->prepare("SELECT id, ad, min_adet, max_adet FROM basvuru_alanlari
            WHERE grup = ? AND aktif = TRUE AND tur = 'belge' ORDER BY sira, id");
        $alan_stmt->execute([$grup]);
        foreach ($alan_stmt->fetchAll() as $a) {
            $ait = array_values(array_filter($dosyalar, fn($d) => $d['alan_id'] === (int)$a['id']));
            $belgeler[] = [
                'alan_id'  => (int)$a['id'],
                'ad'       => $a['ad'],
                'min_adet' => (int)$a['min_adet'],
                'max_adet' => (int)$a['max_adet'],
                'dosyalar' => $ait,
                'eksik'    => count($ait) < (int)$a['min_adet'],
            ];
        }
    }

    echo json_encode([
        'grup'      => $grup,
        'gruplar'   => BASVURU_GRUPLARI,
        'belgeler'  => $belgeler,
        'digerleri' => array_values(array_filter($dosyalar, fn($d) => $d['alan_id'] === null)),
        'secenek'   => array_map(fn($b) => ['id' => $b['alan_id'], 'ad' => $b['ad']], $belgeler),
    ]);
    exit;
}

// ── Müşterinin firma türü (hangi evrakların isteneceğini belirler) ───────────
if (isset($_POST['evrak_grubu_kaydet'])) {
    csrf_dogrula_post();
    header('Content-Type: application/json');
    $mid  = (int)($_POST['musteri_id'] ?? 0);
    if (!musteriye_erisim($db, $mid, $rol, (int)$_SESSION['kullanici_id'])) { echo json_encode(['ok' => false]); exit; }
    $grup = (string)($_POST['evrak_grubu'] ?? '');
    if ($grup !== '' && !isset(BASVURU_GRUPLARI[$grup])) $grup = '';
    $db->prepare("UPDATE musteriler SET evrak_grubu = ? WHERE id = ?")->execute([$grup, $mid]);
    echo json_encode(['ok' => true, 'grup' => $grup]);
    exit;
}

// ── Yüklenmiş bir dosyayı evrak listesindeki bir belgeye bağla ───────────────
if (isset($_POST['dosya_alan_ata'])) {
    csrf_dogrula_post();
    header('Content-Type: application/json');
    $did  = (int)($_POST['dosya_id'] ?? 0);
    // Görevli/üye yalnız kendi müşterisinin dosyasını başka belgeye bağlayabilir (IDOR önlemi).
    $sahip_stmt = $db->prepare("SELECT musteri_id FROM musteri_dosyalari WHERE id = ?");
    $sahip_stmt->execute([$did]);
    $sahip_mid = (int)($sahip_stmt->fetchColumn() ?: 0);
    if (!musteriye_erisim($db, $sahip_mid, $rol, (int)$_SESSION['kullanici_id'])) { echo json_encode(['ok' => false]); exit; }
    $aid  = (int)($_POST['alan_id'] ?? 0) ?: null;
    $adi  = '';
    if ($aid) {
        $a = $db->prepare("SELECT ad FROM basvuru_alanlari WHERE id = ?");
        $a->execute([$aid]);
        $adi = (string)($a->fetchColumn() ?: '');
        if ($adi === '') $aid = null;
    }
    $db->prepare("UPDATE musteri_dosyalari SET alan_id = ?, alan_adi = ? WHERE id = ?")->execute([$aid, $adi, $did]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Dosya yükle ───────────────────────────────────────────────────────────────
if (isset($_POST['dosya_yukle'])) {
    csrf_dogrula_post();
    $mid      = (int)$_POST['musteri_id'];
    if (!musteriye_erisim($db, $mid, $rol, (int)$_SESSION['kullanici_id'])) { header("Location: dashboard.php"); exit; }
    $yuklendi = 0;
    $hata_msj = '';

    // Hangi evrak için yükleniyor (boşsa listede olmayan dosya sayılır).
    $alan_id  = (int)($_POST['alan_id'] ?? 0) ?: null;
    $alan_adi = '';
    if ($alan_id) {
        $a = $db->prepare("SELECT ad FROM basvuru_alanlari WHERE id = ?");
        $a->execute([$alan_id]);
        $alan_adi = (string)($a->fetchColumn() ?: '');
        if ($alan_adi === '') $alan_id = null;
    }

    if ($mid && isset($_FILES['dosyalar']) && !empty($_FILES['dosyalar']['name'][0])) {
        $upload_dir = __DIR__ . '/uploads/musteriler/' . $mid . '/';
        if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

        foreach ($_FILES['dosyalar']['name'] as $i => $orijinal) {
            if (empty($orijinal)) continue;
            if ($_FILES['dosyalar']['error'][$i] !== UPLOAD_ERR_OK) continue;

            $uzanti = strtolower(pathinfo($orijinal, PATHINFO_EXTENSION));
            if (!in_array($uzanti, $izinli_uzantilar)) { $hata_msj = 'izin_disi'; continue; }
            if ($_FILES['dosyalar']['size'][$i] > 20 * 1024 * 1024) { $hata_msj = 'boyut'; continue; }

            $guvenli = time() . '_' . $i . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $orijinal);
            $db_yolu = 'uploads/musteriler/' . $mid . '/' . $guvenli;

            if (move_uploaded_file($_FILES['dosyalar']['tmp_name'][$i], $upload_dir . $guvenli)) {
                $db->prepare("INSERT INTO musteri_dosyalari
                    (musteri_id, dosya_adi, dosya_yolu, dosya_boyutu, yukleyen_id, alan_id, alan_adi)
                    VALUES (?,?,?,?,?,?,?)")
                   ->execute([$mid, $orijinal, $db_yolu, $_FILES['dosyalar']['size'][$i], $_SESSION['kullanici_id'], $alan_id, $alan_adi]);
                $yuklendi++;
            }
        }
    }

    $msj = $yuklendi > 0 ? 'dosya_yuklendi' : ($hata_msj ?: 'dosya_bos');
    header("Location: dashboard.php?msj=$msj&ac=$mid");
    exit;
}

// ── Dosya sil ─────────────────────────────────────────────────────────────────
if (isset($_GET['dosya_sil'])) {
    csrf_dogrula();
    $did  = (int)$_GET['dosya_sil'];
    $stmt = $db->prepare("SELECT * FROM musteri_dosyalari WHERE id = ?");
    $stmt->execute([$did]);
    $d = $stmt->fetch();
    if ($d) {
        // Görevli/üye yalnız kendi müşterisinin dosyasını silebilir (IDOR önlemi).
        if (!musteriye_erisim($db, (int)$d['musteri_id'], $rol, (int)$_SESSION['kullanici_id'])) { header("Location: dashboard.php"); exit; }
        @unlink(__DIR__ . '/' . $d['dosya_yolu']);
        $db->prepare("DELETE FROM musteri_dosyalari WHERE id = ?")->execute([$did]);
        header("Location: dashboard.php?msj=dosya_silindi&ac=" . $d['musteri_id']);
        exit;
    }
}

// ── Tekli müşteri sil (çöp kutusuna taşı) ────────────────────────────────────
if (isset($_GET['sil_id']) && $silme_yetkili) {
    csrf_dogrula();
    $db->prepare("UPDATE musteriler SET silindi_mi=TRUE, silinme_tarihi=NOW() WHERE id=?")->execute([(int)$_GET['sil_id']]);
    header("Location: dashboard.php?msj=silindi");
    exit;
}

// ── Toplu sil (çöp kutusuna taşı) ────────────────────────────────────────────
if (isset($_POST['toplu_sil']) && $silme_yetkili) {
    $ids = array_filter(array_map('intval', (array)($_POST['secilen'] ?? [])));
    if (!empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("UPDATE musteriler SET silindi_mi=TRUE, silinme_tarihi=NOW() WHERE id IN ($ph)")->execute($ids);
    }
    header("Location: dashboard.php?msj=silindi");
    exit;
}

// ── Çöp kutusundan geri al ────────────────────────────────────────────────────
if (isset($_GET['geri_al']) && $silme_yetkili) {
    csrf_dogrula();
    $db->prepare("UPDATE musteriler SET silindi_mi=FALSE, silinme_tarihi=NULL WHERE id=?")->execute([(int)$_GET['geri_al']]);
    header("Location: dashboard.php?cop=1&msj=geri_alindi");
    exit;
}

// ── Kalıcı sil ────────────────────────────────────────────────────────────────
if (isset($_GET['kalici_sil']) && $silme_yetkili) {
    csrf_dogrula();
    $db->prepare("DELETE FROM musteriler WHERE id=? AND silindi_mi=TRUE")->execute([(int)$_GET['kalici_sil']]);
    header("Location: dashboard.php?cop=1&msj=kalici_silindi");
    exit;
}

// ── Durum / Teslimat güncelle ─────────────────────────────────────────────────
if (isset($_GET['durum_yap'], $_GET['id']) && ($rol === 'admin' || $rol === 'yonetici')) {
    csrf_dogrula();
    // teslimat_sureci de sıfırlanıyor (2026-09-01, kullanıcı bulgusuyla: aşama
    // çizelgesinde "geriye dönülmüyor" — sebebi buydu: teslimat_sureci doluyken
    // asama_zinciri_indeksi() HER ZAMAN onu önceliklendiriyor, durum'u geriye alsan
    // bile çizelge teslimat aşamasında takılı kalıyordu). Durum'u değiştirmek satış
    // sürecine "geri dönmek" demek — önceki teslimat durumu artık geçerli değil.
    $db->prepare("UPDATE musteriler SET durum = ?, teslimat_sureci = NULL WHERE id = ?")->execute([$_GET['durum_yap'], (int)$_GET['id']]);
    // "Ödeme Kuruluşundan Olumsuz Döndü" aşamasına alınınca mevcut kuruluşun açık
    // başvurusu da geçmişte "olumsuz" olarak kapansın — ayrıca düzenlemeye gerek kalmasın.
    if (mb_stripos($_GET['durum_yap'], 'olumsuz') !== false) {
        $mid_o  = (int)$_GET['id'];
        $ok = $db->prepare("SELECT odeme_kurulusu FROM musteriler WHERE id = ?");
        $ok->execute([$mid_o]);
        $mevcut_kurulus = (string)($ok->fetchColumn() ?: '');
        $islem_o = odeme_islem_id();
        odeme_basvurusu_kapat($db, $mid_o, $mevcut_kurulus, 'olumsuz', '', $islem_o, $mevcut_kurulus);
        // Olumsuz seçilirken açılan pencereden gelen karar (2026-09-17, kullanıcı talebiyle:
        // "olumsuz döndüyü seçersek sor: yeni ödeme kuruluşunu seçin ya da başvuru yapılmayacak"):
        // yeni kuruluş seçildiyse müşteri o kuruluşa geçer ve yeni açık başvuru aynı hareketin
        // parçası olarak açılır (Geri Al ikisini birden geri alır). "Başvuru yapılmayacak" ise
        // sadece kapanış kalır.
        $yeni_kurulus = trim($_GET['yeni_kurulus'] ?? '');
        if ($yeni_kurulus !== '' && $yeni_kurulus !== $mevcut_kurulus) {
            $db->prepare("UPDATE musteriler SET odeme_kurulusu = ? WHERE id = ?")->execute([$yeni_kurulus, $mid_o]);
            odeme_basvurusu_ac($db, $mid_o, $yeni_kurulus, '', $islem_o, $mevcut_kurulus);
        }
    }
    header("Location: dashboard.php");
    exit;
}

// ── Başvuru geçmişinde son hareketi geri al ───────────────────────────────────
if (isset($_GET['basvuru_geri']) && ($rol === 'admin' || $rol === 'yonetici')) {
    if (function_exists('csrf_dogrula')) csrf_dogrula(); // canlı config.php'de henüz yok
    $geri_ok = odeme_basvurusu_geri_al($db, (int)$_GET['basvuru_geri']);
    header("Location: dashboard.php?msj=" . ($geri_ok ? 'basvuru_geri_alindi' : 'basvuru_geri_yok'));
    exit;
}
if (isset($_GET['teslimat_yap'], $_GET['id']) && ($rol === 'admin' || $rol === 'yonetici')) {
    csrf_dogrula();
    $db->prepare("UPDATE musteriler SET teslimat_sureci = ? WHERE id = ?")->execute([$_GET['teslimat_yap'], (int)$_GET['id']]);
    header("Location: dashboard.php");
    exit;
}
// Aşama çizelgesindeki her noktanın altına eklenen, sadece o müşteriyi etkileyen
// işaret rozeti (2026-09-01, kullanıcı talebiyle: "her yuvarlak kutunun altına
// seçme kutucuğu ekle" — sonra Durum rozetindeki gibi "alta açılan buton" istendiği
// netleşti, kutucuktan rozet+dropdown'a çevrildi). durum/teslimat_sureci'yi
// DEĞİŞTİRMEZ, sadece o noktayı müşteriye özel işaretler/kaldırır — asama_isaretleri
// JSONB sütununda alan|değer anahtarıyla saklanır. Durum/Teslimat dropdown'larıyla
// aynı desen: tüm seçenekler listelenir, mevcut değer "active" ile işaretlenir.
if (isset($_GET['asama_isaretle'], $_GET['id']) && ($rol === 'admin' || $rol === 'yonetici')) {
    csrf_dogrula();
    $id = (int)$_GET['id'];
    $anahtar = $_GET['asama_isaretle'];
    $yeni_deger = ($_GET['deger'] ?? '0') === '1';
    $mevcut_satir = $db->prepare("SELECT asama_isaretleri FROM musteriler WHERE id = ?");
    $mevcut_satir->execute([$id]);
    $isaretler = json_decode($mevcut_satir->fetchColumn() ?: '{}', true) ?: [];
    $isaretler[$anahtar] = $yeni_deger;
    $db->prepare("UPDATE musteriler SET asama_isaretleri = ? WHERE id = ?")
       ->execute([json_encode($isaretler, JSON_UNESCAPED_UNICODE), $id]);
    header("Location: dashboard.php");
    exit;
}

// ── Müşteri düzenle (görevli dahil) ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['musteri_duzenle'])) {
    $mid = (int)$_POST['musteri_id'];
    // Temsilci değişikliği admin/yöneticide burada yapılır
    if ($rol === 'admin' || $rol === 'yonetici') {
        $eski = $db->prepare("SELECT ad_soyad, musteri_temsilcisi, odeme_kurulusu FROM musteriler WHERE id=?");
        $eski->execute([$mid]);
        $eski_kayit  = $eski->fetch(PDO::FETCH_ASSOC);
        $eski_temsilci = $eski_kayit['musteri_temsilcisi'] ?? '';
        $eski_odeme    = (string)($eski_kayit['odeme_kurulusu'] ?? '');

        $magaza  = $_POST['magaza_sayisi']  !== '' ? (int)$_POST['magaza_sayisi']  : null;
        $calisan = $_POST['calisan_sayisi'] !== '' ? (int)$_POST['calisan_sayisi'] : null;
        $yeni_temsilci = $_POST['musteri_temsilcisi'] ?? '';
        $db->prepare("UPDATE musteriler SET
            ad_soyad=?, ticari_unvan=?, telefon=?, web_sayfasi=?, yetkili_ad_soyad=?, eposta=?,
            ili=?, ilcesi=?, calisma_modeli=?, ulasim_kanali=?, musteri_notu=?,
            magaza_sayisi=?, calisan_sayisi=?, odeme_kurulusu=?, odeme_kurulusu_temsilcisi=?, musteri_temsilcisi=?, soru_cevaplari=?::jsonb
            WHERE id=?")
           ->execute([
               tr_buyuk($_POST['ad_soyad']), tr_buyuk($_POST['ticari_unvan']??''),
               trim($_POST['telefon']??''), trim($_POST['web_sayfasi']??''),
               tr_buyuk($_POST['yetkili_ad_soyad']??''), trim($_POST['eposta']??''), trim($_POST['ili']??''),
               trim($_POST['ilcesi']??''), $_POST['calisma_modeli']??'',
               $_POST['kanal']??'', trim($_POST['not']??''),
               $magaza, $calisan, $_POST['odeme_kurulusu']??'', $_POST['odeme_kurulusu_temsilcisi']??'',
               $yeni_temsilci, soru_cevaplari_json($sorular), $mid,
           ]);
        odeme_kurulusu_degisimi_isle($db, $mid, $eski_odeme, (string)($_POST['odeme_kurulusu'] ?? ''),
            (string)($_POST['odeme_kurulusu_temsilcisi'] ?? ''), (string)($_POST['eski_odeme_sonuc'] ?? ''), trim($_POST['eski_odeme_not'] ?? ''));

        if ($yeni_temsilci !== '' && $yeni_temsilci !== $eski_temsilci) {
            $tk = $db->prepare("SELECT * FROM kullanicilar WHERE ad_soyad = ? LIMIT 1");
            $tk->execute([$yeni_temsilci]);
            $tb = $tk->fetch(PDO::FETCH_ASSOC);
            if ($tb) {
                musteriyi_kullaniciya_bildir($db, $tb, trim($_POST['ad_soyad']),
                    "Atayan: " . ($_SESSION['ad_soyad'] ?? 'Bilinmiyor'), $mid, "dashboard.php?ac={$mid}");
            }
        }
    } else {
        // Görevli: temsilci hariç
        $magaza  = $_POST['magaza_sayisi']  !== '' ? (int)$_POST['magaza_sayisi']  : null;
        $calisan = $_POST['calisan_sayisi'] !== '' ? (int)$_POST['calisan_sayisi'] : null;
        $eo = $db->prepare("SELECT odeme_kurulusu FROM musteriler WHERE id=?");
        $eo->execute([$mid]);
        $eski_odeme = (string)($eo->fetchColumn() ?: '');
        $db->prepare("UPDATE musteriler SET
            ad_soyad=?, ticari_unvan=?, telefon=?, web_sayfasi=?, yetkili_ad_soyad=?, eposta=?,
            ili=?, ilcesi=?, calisma_modeli=?, ulasim_kanali=?, musteri_notu=?,
            magaza_sayisi=?, calisan_sayisi=?, odeme_kurulusu=?, odeme_kurulusu_temsilcisi=?, soru_cevaplari=?::jsonb
            WHERE id=?")
           ->execute([
               tr_buyuk($_POST['ad_soyad']), tr_buyuk($_POST['ticari_unvan']??''),
               trim($_POST['telefon']??''), trim($_POST['web_sayfasi']??''),
               tr_buyuk($_POST['yetkili_ad_soyad']??''), trim($_POST['eposta']??''), trim($_POST['ili']??''),
               trim($_POST['ilcesi']??''), $_POST['calisma_modeli']??'',
               $_POST['kanal']??'', trim($_POST['not']??''),
               $magaza, $calisan, $_POST['odeme_kurulusu']??'', $_POST['odeme_kurulusu_temsilcisi']??'', soru_cevaplari_json($sorular), $mid,
           ]);
        odeme_kurulusu_degisimi_isle($db, $mid, $eski_odeme, (string)($_POST['odeme_kurulusu'] ?? ''),
            (string)($_POST['odeme_kurulusu_temsilcisi'] ?? ''), (string)($_POST['eski_odeme_sonuc'] ?? ''), trim($_POST['eski_odeme_not'] ?? ''));
    }
    header("Location: dashboard.php?msj=guncellendi");
    exit;
}

// ── Temsilci değişiklik isteği ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['temsilci_iste'])) {
    $mid              = (int)$_POST['musteri_id'];
    $istenen          = trim($_POST['istenen_temsilci'] ?? '');
    $musteri_adi      = trim($_POST['musteri_adi'] ?? '');
    $mevcut_temsilci  = trim($_POST['mevcut_temsilci'] ?? '');
    if ($istened = $istenen) {
        $db->prepare("INSERT INTO temsilci_istekleri
            (musteri_id, talep_eden_id, musteri_adi, mevcut_temsilci, istenen_temsilci)
            VALUES (?,?,?,?,?)")
           ->execute([$mid, $_SESSION['kullanici_id'], $musteri_adi, $mevcut_temsilci, $istenen]);
    }
    header("Location: dashboard.php?msj=istek_gonderildi");
    exit;
}

// ── İstek onayla / reddet ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['istek_yanit']) && ($rol === 'admin' || $rol === 'yonetici')) {
    $iid   = (int)$_POST['istek_id'];
    $yanit = $_POST['yanit']; // 'onaylandi' veya 'reddedildi'
    $db->prepare("UPDATE temsilci_istekleri SET durum=?, yanitlayan_id=? WHERE id=?")
       ->execute([$yanit, $_SESSION['kullanici_id'], $iid]);
    if ($yanit === 'onaylandi') {
        $istek = $db->prepare("SELECT * FROM temsilci_istekleri WHERE id=?");
        $istek->execute([$iid]);
        $i = $istek->fetch();
        if ($i) {
            $db->prepare("UPDATE musteriler SET musteri_temsilcisi=? WHERE id=?")
               ->execute([$i['istenen_temsilci'], $i['musteri_id']]);

            $tk = $db->prepare("SELECT * FROM kullanicilar WHERE ad_soyad = ? LIMIT 1");
            $tk->execute([$i['istenen_temsilci']]);
            $tb = $tk->fetch(PDO::FETCH_ASSOC);
            if ($tb) {
                musteriyi_kullaniciya_bildir($db, $tb, $i['musteri_adi'] ?: '',
                    "Temsilcilik isteğiniz onaylandı.", $i['musteri_id'], "dashboard.php?ac={$i['musteri_id']}");
            }
        }
    }
    header("Location: dashboard.php?istekler=1&msj=istek_yanit");
    exit;
}

// ── Görevli ata ───────────────────────────────────────────────────────────────
if (isset($_POST['atama_yap']) && ($rol === 'admin' || $rol === 'yonetici')) {
    $gid = $_POST['gorevli_id'] !== '' ? (int)$_POST['gorevli_id'] : null;
    $mid = (int)$_POST['musteri_id'];
    $db->prepare("UPDATE musteriler SET gorevli_id = ? WHERE id = ?")->execute([$gid, $mid]);

    if ($gid) {
        $g = $db->prepare("SELECT * FROM kullanicilar WHERE id = ?");
        $g->execute([$gid]);
        $gorevli = $g->fetch(PDO::FETCH_ASSOC);
        if ($gorevli) {
            $m_adi = $db->prepare("SELECT ad_soyad FROM musteriler WHERE id = ?");
            $m_adi->execute([$mid]);
            $musteri_adi = $m_adi->fetchColumn() ?: '';
            $atayan = $_SESSION['ad_soyad'] ?? 'Bilinmiyor';
            musteriyi_kullaniciya_bildir($db, $gorevli, $musteri_adi, "Atayan: {$atayan}", $mid, "dashboard.php?ac={$mid}");
        }
    }
    header("Location: dashboard.php");
    exit;
}

// ── Müşteri ekle ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['musteri_kaydet'])) {
    if ($rol === 'admin' || $rol === 'yonetici') {
        $magaza  = $_POST['magaza_sayisi']  !== '' ? (int)$_POST['magaza_sayisi']  : null;
        $calisan = $_POST['calisan_sayisi'] !== '' ? (int)$_POST['calisan_sayisi'] : null;

        $stmt = $db->prepare("INSERT INTO musteriler
            (ad_soyad, ticari_unvan, telefon, web_sayfasi, yetkili_ad_soyad, eposta, ili, ilcesi,
             calisma_modeli, ulasim_kanali, musteri_notu, durum, musteri_temsilcisi,
             teslimat_sureci, magaza_sayisi, calisan_sayisi, odeme_kurulusu, odeme_kurulusu_temsilcisi, soru_cevaplari)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?::jsonb) RETURNING id");
        $stmt->execute([
            tr_buyuk($_POST['ad_soyad']),
            tr_buyuk($_POST['ticari_unvan']      ?? ''),
            trim($_POST['telefon']           ?? ''),
            trim($_POST['web_sayfasi']       ?? ''),
            tr_buyuk($_POST['yetkili_ad_soyad']  ?? ''),
            trim($_POST['eposta']            ?? ''),
            trim($_POST['ili']               ?? ''),
            trim($_POST['ilcesi']            ?? ''),
            $_POST['calisma_modeli']         ?? '',
            $_POST['kanal']                  ?? '',
            trim($_POST['not']               ?? ''),
            $_POST['durum']                  ?? '',
            $_POST['musteri_temsilcisi']     ?? '',
            $_POST['teslimat_sureci']        ?? '',
            $magaza,
            $calisan,
            $_POST['odeme_kurulusu']         ?? '',
            $_POST['odeme_kurulusu_temsilcisi'] ?? '',
            soru_cevaplari_json($sorular),
        ]);
        $yeni_id   = (int)$stmt->fetchColumn();
        odeme_basvurusu_ac($db, $yeni_id, (string)($_POST['odeme_kurulusu'] ?? ''), (string)($_POST['odeme_kurulusu_temsilcisi'] ?? ''), odeme_islem_id(), '');
        $m_adi     = trim($_POST['ad_soyad']);
        $m_durum   = $_POST['durum'] ?? '';
        $ekleyen   = $_SESSION['ad_soyad'] ?? 'Bilinmiyor';

        // ── Bildirimler ───────────────────────────────────────────────────
        $m_temsilci = trim($_POST['musteri_temsilcisi'] ?? '');

        // Push bildirimi
        bildirim_temsilciye_gonder($db, $m_temsilci, 'Yeni Müşteri: ' . $m_adi,
            "Durum: {$m_durum} — Ekleyen: {$ekleyen}", "dashboard.php?ac={$yeni_id}", $yeni_id);

        // Seçili kanal üzerinden temsilciye mesaj
        if ($m_temsilci) {
            $kanal = $db->query("SELECT deger FROM sistem_ayarlari WHERE anahtar='kod_kanali'")->fetchColumn() ?: 'email';
            $ts = $db->prepare("SELECT telefon, email FROM kullanicilar WHERE ad_soyad = ? LIMIT 1");
            $ts->execute([$m_temsilci]);
            $tb = $ts->fetch(PDO::FETCH_ASSOC);
            if ($tb) {
                $bildirim_mt = "👤 Yeni müşteri talebi!\n\n*{$m_adi}*\nDurum: {$m_durum}\nEkleyen: {$ekleyen}";
                if ($kanal === 'sms' && !empty($tb['telefon'])) {
                    require_once __DIR__ . '/sms_helper.php';
                    sms_gonder($db, $tb['telefon'], "Yeni müşteri: {$m_adi} — Ekleyen: {$ekleyen}");
                } elseif ($kanal === 'email' && !empty($tb['email'])) {
                    require_once __DIR__ . '/mail_helper.php';
                    $govde = "<p>Merhaba <b>{$m_temsilci}</b>,</p>
                              <p>Yeni bir müşteri talebi oluşturuldu.</p>
                              <p><b>Müşteri:</b> {$m_adi}<br>
                              <b>Durum:</b> {$m_durum}<br>
                              <b>Ekleyen:</b> {$ekleyen}</p>";
                    mail_gonder($db, $tb['email'], "Yeni Müşteri Talebi: {$m_adi}", $govde);
                }
            }
        }
        // ─────────────────────────────────────────────────────────────────

        // Dosya varsa hemen yükle
        if (!empty($_FILES['dosyalar']['name'][0])) {
            $upload_dir = __DIR__ . '/uploads/musteriler/' . $yeni_id . '/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            foreach ($_FILES['dosyalar']['name'] as $i => $orijinal) {
                if ($_FILES['dosyalar']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $uzanti = strtolower(pathinfo($orijinal, PATHINFO_EXTENSION));
                if (!in_array($uzanti, $izinli_uzantilar)) continue;
                $guvenli = time() . '_' . $i . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $orijinal);
                $db_yolu = 'uploads/musteriler/' . $yeni_id . '/' . $guvenli;
                if (move_uploaded_file($_FILES['dosyalar']['tmp_name'][$i], $upload_dir . $guvenli)) {
                    $db->prepare("INSERT INTO musteri_dosyalari
                        (musteri_id, dosya_adi, dosya_yolu, dosya_boyutu, yukleyen_id)
                        VALUES (?,?,?,?,?)")
                       ->execute([$yeni_id, $orijinal, $db_yolu, $_FILES['dosyalar']['size'][$i], $_SESSION['kullanici_id']]);
                }
            }
        }

        header("Location: dashboard.php?msj=eklendi&ac=$yeni_id");
        exit;
    }
}

// ── Liste sorgusu ─────────────────────────────────────────────────────────────
$ara          = trim($_GET['ara']         ?? '');
$fil_durum    = trim($_GET['fil_durum']   ?? '');
$fil_teslimat = trim($_GET['fil_teslimat']?? '');
$fil_odeme    = trim($_GET['fil_odeme']   ?? '');
$fil_model    = trim($_GET['fil_model']   ?? '');
$fil_kanal    = trim($_GET['fil_kanal']   ?? '');
$fil_temsilci = trim($_GET['fil_temsilci']?? '');
$cop_modu     = isset($_GET['cop']) && $silme_yetkili;
$params       = [];

if ($cop_modu) {
    $where = "WHERE m.silindi_mi = TRUE";
} elseif ($rol === 'gorevli' || $rol === 'uye') {
    $where    = "WHERE m.silindi_mi = FALSE AND m.gorevli_id = ?";
    $params[] = $_SESSION['kullanici_id'];
} else {
    $where = "WHERE m.silindi_mi = FALSE";
}

if ($ara !== '' && !$cop_modu) {
    $where   .= " AND (m.ad_soyad ILIKE ? OR m.telefon ILIKE ? OR m.ticari_unvan ILIKE ?)";
    $params[] = "%$ara%"; $params[] = "%$ara%"; $params[] = "%$ara%";
}
if ($fil_durum !== '')    { $where .= " AND m.durum = ?";             $params[] = $fil_durum; }
// Teslimat filtresi üç hâlli (2026-08-31, kullanıcı talebiyle: "teslim edilmeyen
// seçeneği ekle varsayılan olarak o seçili olsun hepsi diğince hepsi gelsin"):
// - fil_teslimat boş (varsayılan, hiç seçim yapılmamış) → "Teslim Edilmeyen" davranışı
// - fil_teslimat === 'hepsi' → hiçbir kısıtlama yok, teslim edilenler dahil HERKES
// - fil_teslimat === gerçek bir değer (ör. "Teslim Edildi") → sadece o değer
if ($fil_teslimat === 'hepsi') {
    // Kısıtlama yok — herkes gösterilir.
} elseif ($fil_teslimat !== '') {
    $where .= " AND m.teslimat_sureci = ?";
    $params[] = $fil_teslimat;
} elseif (!$cop_modu) {
    // IS DISTINCT FROM kullanılıyor çünkü teslimat_sureci NULL olan müşteriler (çoğu
    // müşteri) "!=" ile yanlışlıkla dışlanırdı — NULL hiçbir zaman "!=" ile eşleşmez.
    $where .= " AND (m.teslimat_sureci IS DISTINCT FROM 'Teslim Edildi')";
}
if ($fil_odeme !== '')    { $where .= " AND m.odeme_kurulusu = ?";    $params[] = $fil_odeme; }
if ($fil_model !== '')    { $where .= " AND m.calisma_modeli = ?";    $params[] = $fil_model; }
if ($fil_kanal !== '')    { $where .= " AND m.ulasim_kanali = ?";     $params[] = $fil_kanal; }
if ($fil_temsilci !== '') { $where .= " AND m.musteri_temsilcisi = ?";$params[] = $fil_temsilci; }

$aktif_filtre = array_filter([$fil_durum, $fil_teslimat, $fil_odeme, $fil_model, $fil_kanal, $fil_temsilci, $ara]);

$stmt = $db->prepare("SELECT m.*, k.ad_soyad AS atanan_kisi,
    (SELECT COUNT(*) FROM musteri_dosyalari WHERE musteri_id = m.id) AS dosya_sayisi
    FROM musteriler m
    LEFT JOIN kullanicilar k ON m.gorevli_id = k.id
    $where ORDER BY m.id DESC");
$stmt->execute($params);
$musteriler = $stmt->fetchAll();

// Listedeki müşterilerin ödeme kuruluşu başvuru geçmişi (tek sorgu, müşteri id'sine göre gruplu)
$odeme_gecmisi = [];
if ($musteriler) {
    $ids = array_column($musteriler, 'id');
    $yt  = implode(',', array_fill(0, count($ids), '?'));
    $og  = $db->prepare("SELECT * FROM odeme_basvurulari WHERE musteri_id IN ($yt) ORDER BY musteri_id, id");
    $og->execute($ids);
    foreach ($og->fetchAll() as $b) $odeme_gecmisi[(int)$b['musteri_id']][] = $b;
}

// Çöpteki kayıt sayısı (badge için)
$cop_sayisi = $silme_yetkili ? (int)$db->query("SELECT COUNT(*) FROM musteriler WHERE silindi_mi=TRUE")->fetchColumn() : 0;

// ── İstatistikler ─────────────────────────────────────────────────────────────
$toplam_musteri = count($musteriler);
$teslim_edildi  = count(array_filter($musteriler, fn($m) => ($m['teslimat_sureci'] ?? '') === 'Teslim Edildi'));
$sozlesme_banka = count(array_filter($musteriler, fn($m) =>
    in_array($m['durum'] ?? '', ['Sözleşme Süreci ve Kimlik Doğrulama', 'Banka Tanımlamasında'])
));
$aktif_lead = $toplam_musteri - $teslim_edildi;

$durum_renk = [];
foreach ($db->query("SELECT deger,renk FROM secenekler WHERE kategori='durum' AND renk!='' ")->fetchAll() as $r) {
    $durum_renk[$r['deger']] = $r['renk'];
}
$teslimat_renk = [];
foreach ($db->query("SELECT deger,renk FROM secenekler WHERE kategori='teslimat' AND renk!='' ")->fetchAll() as $r) {
    $teslimat_renk[$r['deger']] = $r['renk'];
}

// ── Birleşik aşama zinciri (2026-08-31, kullanıcı talebiyle: "Banka Tanımlanmasından
// sonra son olarak Teslimat kısmını sorsun") — Durum (satış süreci) + Teslimat Süreci
// TEK bir ilerleme çizelgesinde birleştiriliyor.
// ARTIK TAMAMEN DİNAMİK (2026-09-01, kullanıcı talebiyle: "buna ekleme çıkarma
// düzenlemeyi seçenekler kısmından yapabilelim") — $durum_secenekleri/$teslimat_secenekleri
// zaten yukarıda `secenekler` tablosundan (sira sütununa göre) okunuyordu, artık zincir
// de DOĞRUDAN bunlardan kuruluyor. Admin secenekler.php'den bir değeri eklerse/silerse/
// yeniden sıralarsa çizelge OTOMATİK güncellenir, koda dokunmaya gerek YOK.
// ÖNEMLİ: Teslimat kategorisinin `sira` değerleri şu an mantıksal akışla (Test Çekim →
// Eğitim Verildi → Teslim Edildi) eşleşmiyorsa secenekler.php'den düzeltilmeli — zincir
// artık körü körüne sira sırasını izliyor.
$ASAMA_ZINCIRI = array_merge(
    array_map(fn($d) => ['alan' => 'durum', 'deger' => $d], $durum_secenekleri),
    array_map(fn($t) => ['alan' => 'teslimat_sureci', 'deger' => $t], $teslimat_secenekleri)
);
/** Müşterinin zincirdeki güncel konumunu bulur — teslimat_sureci doluysa (satış süreci
 * bitip teslimat başlamış demektir) önce ona, boşsa durum'a bakılır. Zincirde
 * eşleşmeyen bir değer varsa (ör. ret durumu) -1 döner, o zaman hiçbir nokta
 * doldurulmaz — yanlış bir ilerleme izlenimi vermemek için bilerek böyle. */
function asama_zinciri_indeksi(array $zincir, array $m): int {
    $aranan_alan = !empty($m['teslimat_sureci']) ? 'teslimat_sureci' : 'durum';
    $aranan_deger = $m[$aranan_alan] ?? '';
    foreach ($zincir as $i => $a) {
        if ($a['alan'] === $aranan_alan && $a['deger'] === $aranan_deger) return $i;
    }
    return -1;
}

$auto_ac = isset($_GET['ac']) ? (int)$_GET['ac'] : 0;

// ── HTML çıktısı başlıyor ─────────────────────────────────────────────────────
$sayfa_basligi = 'Müşteri Yönetimi';
// İstekler listesi
$bekleyen_istekler = [];
if (($rol === 'admin' || $rol === 'yonetici') && isset($_GET['istekler'])) {
    $bekleyen_istekler = $db->query("
        SELECT ti.*, m.ad_soyad AS musteri_ad, k.ad_soyad AS talep_eden_ad
        FROM temsilci_istekleri ti
        LEFT JOIN musteriler m ON m.id = ti.musteri_id
        LEFT JOIN kullanicilar k ON k.id = ti.talep_eden_id
        WHERE ti.durum = 'bekliyor'
        ORDER BY ti.olusturma DESC
    ")->fetchAll();
}

require __DIR__ . '/header.php';
// Ödeme kuruluşu başvuru geçmişi rozetleri/tablosu
echo '<style>
.odeme-gecmis-rozet { background:#3a3f45; color:#dce6f2; text-decoration:line-through; }
.odeme-gecmis-rozet.olumsuz { background:#3a1010; color:#f28b8b; }
[data-bs-theme="light"] .odeme-gecmis-rozet { background:#e9ecef; color:#495057; }
[data-bs-theme="light"] .odeme-gecmis-rozet.olumsuz { background:#fde2e2; color:#b91c1c; }
.odeme-gecmis-kutu { border:1px dashed #2a2a2a; border-radius:8px; padding:8px 12px; margin-bottom:8px; }
[data-bs-theme="light"] .odeme-gecmis-kutu { border-color:#dde5ee; }
.odeme-gecmis-tablo { font-size:.75rem; border-collapse:collapse; }
.odeme-gecmis-tablo td { padding:2px 14px 2px 0; vertical-align:middle; }
.odeme-gecmis-tablo .sonuc-olumsuz { color:#f87171; font-weight:600; }
.odeme-gecmis-tablo .sonuc-bekliyor { color:#fbbf24; font-weight:600; }
.odeme-gecmis-tablo .sonuc-onaylandi { color:#4ade80; font-weight:600; }
.odeme-gecmis-tablo .sonuc-vazgecildi { color:#9ca3af; font-weight:600; }
/* Seçenek 3: çizelgede olumsuz adım + yeniden başvuru köprüsü */
.asama-adim.olumsuz .asama-nokta { background:#dc2626; color:#fff; box-shadow:0 0 0 2px rgba(220,38,38,.22); }
.asama-adim.olumsuz .asama-etiket { color:#f87171; }
.asama-adim.olumsuz .asama-durum-metni { color:#f87171; }
.asama-alt-not { font-size:.5rem; font-weight:700; color:#f87171; margin-top:1px; }
.asama-etiket-kutu { font-size:.5rem; font-weight:700; color:#fbbf24; background:rgba(251,191,36,.15); border-radius:4px; padding:1px 5px; margin-top:2px; white-space:nowrap; }
.asama-cizelgesi.yeniden-basvuru { position:relative; padding-top:30px; }
.asama-kopru { position:absolute; top:16px; height:12px; border:2px solid #f59e0b; border-bottom:none; border-radius:8px 8px 0 0; pointer-events:none; }
.asama-kopru-etiket { position:absolute; top:-12px; left:50%; transform:translateX(-50%); font-size:.55rem; font-weight:700; color:#f59e0b; white-space:nowrap; }
/* Masaüstü: her müşteri (ana satır + çizelge satırı) tek çerçevede, aralarında boşluk
   (2026-09-23, kullanıcı talebiyle: "müşteriler arasında boşluk bırak, çerçeve ekle, kimin
   kim olduğu belli olsun"). Tablo satırlarına kenarlık verilemediği için çerçeve hücrelere
   parça parça çiziliyor: ana satır üst+yanlar, çizelge satırı alt+yanlar, aradaki boşluk
   ayrı bir "musteri-bosluk" satırı. */
@media (min-width: 768px) {
  #musteriTablosu { border-collapse: separate; border-spacing: 0; --musteri-cerceve: #111; }
  /* koyu temada siyah çerçeve zeminde kaybolur; açık gri kullanılıyor */
  [data-bs-theme="dark"] #musteriTablosu { --musteri-cerceve: #c8c8c8; }
  #musteriTablosu > tbody > tr.satir-sec > td { border-top: 2px solid var(--musteri-cerceve); border-bottom: 0; padding-top: .75rem; background-clip: padding-box; }
  #musteriTablosu > tbody > tr.satir-sec > td:first-child { border-left: 2px solid var(--musteri-cerceve); border-top-left-radius: 12px; padding-left: .75rem; }
  #musteriTablosu > tbody > tr.satir-sec > td:last-child  { border-right: 2px solid var(--musteri-cerceve); border-top-right-radius: 12px; padding-right: .75rem; }
  #musteriTablosu > tbody > tr.asama-satiri > td { border: 2px solid var(--musteri-cerceve); border-top: 0; border-radius: 0 0 12px 12px; padding: 0 .75rem .6rem !important; background-clip: padding-box; }
  #musteriTablosu > tbody > tr.musteri-bosluk > td { border: 0 !important; padding: 0 !important; height: 16px; background: transparent !important; box-shadow: none !important; }
}
@media (max-width: 767px) { #musteriTablosu > tbody > tr.musteri-bosluk { display: none !important; } }
/* Telefon: müşteri tablosu kart görünümü (2026-09-23, kullanıcı talebiyle "portalı daha
   kullanışlı hale getir" → seçilen madde: telefonda kart görünümü). Aynı işaretleme, sadece
   CSS: başlık satırı gizlenir, her satır bir kart olur, masaüstünde gizlenen hücreler
   (ünvan, konum, model, mecra, temsilci, teslimat) etiketleriyle görünür, boş olanlar
   (bos-hucre) hiç yer kaplamaz, işlem butonları yan yana dizilir, çizelge kartın altında
   yatay kaydırılabilir kalır. */
@media (max-width: 767px) {
  #tableWrap.table-responsive { overflow: visible; }
  #tableWrap.table-responsive::after { display: none; }
  #musteriTablosu thead { display: none; }
  #musteriTablosu, #musteriTablosu > tbody, #musteriTablosu > tbody > tr, #musteriTablosu > tbody > tr > td { display: block; width: 100%; }
  #musteriTablosu > tbody > tr.satir-sec {
    position: relative; border: 1px solid var(--bs-border-color); border-radius: 12px;
    padding: .7rem .8rem .55rem; margin-bottom: .6rem; background: var(--bs-body-bg);
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
  }
  #musteriTablosu > tbody > tr.satir-sec > td { border: 0 !important; padding: .25rem 0 !important; background: transparent !important; box-shadow: none !important; font-size: .86rem; }
  #musteriTablosu > tbody > tr.satir-sec > td.d-none { display: block !important; }
  #musteriTablosu > tbody > tr.satir-sec > td.bos-hucre { display: none !important; }
  #musteriTablosu > tbody > tr.satir-sec > td[data-etiket]::before {
    content: attr(data-etiket); display: block; font-size: .6rem; font-weight: 700; letter-spacing: .04em;
    text-transform: uppercase; color: var(--bs-secondary-color); margin-bottom: .15rem;
  }
  #musteriTablosu > tbody > tr.satir-sec > td.alan-hucre { display: inline-block !important; width: 50%; vertical-align: top; padding-right: .5rem !important; }
  #musteriTablosu > tbody > tr.satir-sec > td.alan-hucre.bos-hucre { display: none !important; }
  #musteriTablosu > tbody > tr.satir-sec > td.secim-hucre { position: absolute; top: .65rem; right: .8rem; width: auto; padding: 0 !important; }
  #musteriTablosu > tbody > tr.satir-sec > td.secim-hucre .form-check-input { width: 1.15rem; height: 1.15rem; }
  #musteriTablosu > tbody > tr.satir-sec > td.musteri-hucre { padding-right: 2rem !important; }
  #musteriTablosu > tbody > tr.satir-sec > td.musteri-hucre strong { font-size: 1.02rem; }
  #musteriTablosu > tbody > tr.satir-sec > td.unvan-hucre { border-top: 1px dashed var(--bs-border-color) !important; margin-top: .3rem; padding-top: .45rem !important; }
  #musteriTablosu > tbody > tr.satir-sec > td .badge[style*="max-width"] { max-width: 100% !important; }
  #musteriTablosu > tbody > tr.satir-sec > td.islem-hucre { border-top: 1px solid var(--bs-border-color) !important; margin-top: .45rem; padding-top: .55rem !important; }
  #musteriTablosu > tbody > tr.satir-sec > td.islem-hucre > div { width: 100% !important; flex-direction: row !important; flex-wrap: wrap; }
  #musteriTablosu > tbody > tr.satir-sec > td.islem-hucre > div > div { flex: 0 0 auto; }
  #musteriTablosu > tbody > tr.satir-sec > td.islem-hucre .btn { width: auto !important; flex: 1 1 auto; white-space: nowrap; }
  #musteriTablosu > tbody > tr.satir-sec > td.islem-hucre .btn-islem-text { display: inline !important; }
  #musteriTablosu > tbody > tr.satir-sec > td.islem-hucre small { width: 100%; }
  #musteriTablosu > tbody > tr.asama-satiri { margin: -.75rem 0 .9rem; padding: 0 .35rem; }
  #musteriTablosu > tbody > tr.asama-satiri > td { padding: 0 !important; border: 0 !important; background: transparent !important; box-shadow: none !important; }
  #musteriTablosu > tbody > tr.asama-satiri .asama-kart { border-top-left-radius: 0; border-top-right-radius: 0; margin-top: 0; }
  #musteriTablosu > tbody > tr.asama-satiri .odeme-gecmis-kutu { border-radius: 0; }
  /* yüzde rozeti çizelge etiketlerine binmesin: telefonda çizelgenin üstünde durur */
  #musteriTablosu > tbody > tr.asama-satiri .asama-yuzde-rozet { position: static; display: inline-block; margin: 0 0 .35rem; }
  /* başvuru geçmişi: satırlar bölünmesin, gerekirse yatay kaydır */
  #musteriTablosu > tbody > tr.asama-satiri .odeme-gecmis-kutu { overflow-x: auto; }
  #musteriTablosu > tbody > tr.asama-satiri .odeme-gecmis-tablo td { white-space: nowrap; }
  [data-bs-theme="light"] #musteriTablosu > tbody > tr.satir-sec.teslim-edildi-satir { background: #d1f7dd; }
  [data-bs-theme="dark"]  #musteriTablosu > tbody > tr.satir-sec.teslim-edildi-satir { background: #123a24; }
  [data-bs-theme="dark"]  #musteriTablosu > tbody > tr.satir-sec { box-shadow: none; }
}
</style>';
?>

<?php if (isset($_GET['istekler']) && ($rol === 'admin' || $rol === 'yonetici')): ?>
<div class="card mb-4 border-warning">
    <div class="card-header bg-warning bg-opacity-10 d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="bi bi-bell-fill text-warning me-2"></i>Temsilci Değişiklik İstekleri
            <span class="badge bg-warning text-dark ms-1"><?= count($bekleyen_istekler) ?></span>
        </h6>
        <a href="dashboard.php" class="btn btn-sm btn-outline-secondary">✕ Kapat</a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($bekleyen_istekler)): ?>
            <p class="text-muted text-center py-4">Bekleyen istek yok.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Tarih</th>
                        <th>Talep Eden</th>
                        <th>Müşteri</th>
                        <th>Mevcut Temsilci</th>
                        <th>İstenen Temsilci</th>
                        <th>İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bekleyen_istekler as $istek): ?>
                    <tr>
                        <td class="small text-muted"><?= date('d.m.Y H:i', strtotime($istek['olusturma'])) ?></td>
                        <td><?= htmlspecialchars($istek['talep_eden_ad']) ?></td>
                        <td><?= htmlspecialchars($istek['musteri_adi'] ?: $istek['musteri_ad']) ?></td>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars($istek['mevcut_temsilci'] ?: '—') ?></span></td>
                        <td><span class="badge bg-primary"><?= htmlspecialchars($istek['istenen_temsilci']) ?></span></td>
                        <td>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="istek_id" value="<?= $istek['id'] ?>">
                                <input type="hidden" name="yanit" value="onaylandi">
                                <button type="submit" name="istek_yanit" class="btn btn-sm btn-success me-1"
                                        onclick="return confirm('Onayla?')">
                                    <i class="bi bi-check-lg"></i> Onayla
                                </button>
                            </form>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="istek_id" value="<?= $istek['id'] ?>">
                                <input type="hidden" name="yanit" value="reddedildi">
                                <button type="submit" name="istek_yanit" class="btn btn-sm btn-outline-danger"
                                        onclick="return confirm('Reddet?')">
                                    <i class="bi bi-x-lg"></i> Reddet
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (isset($_GET['msj'])): ?>
    <?php $msj_map = [
        'eklendi'        => ['success', 'Müşteri eklendi.'],
        'silindi'        => ['warning', 'Müşteri çöp kutusuna taşındı.'],
        'guncellendi'        => ['success', 'Müşteri güncellendi.'],
        'istek_gonderildi'   => ['info',    'Temsilci değişiklik isteği gönderildi.'],
        'istek_yanit'        => ['success', 'İstek yanıtlandı.'],
        'geri_alindi'    => ['success', 'Müşteri geri alındı.'],
        'basvuru_geri_alindi' => ['success', 'Son başvuru hareketi geri alındı.'],
        'basvuru_geri_yok'    => ['warning', 'Geri alınacak başvuru hareketi yok.'],
        'kalici_silindi' => ['danger',  'Müşteri kalıcı olarak silindi.'],
        'dosya_yuklendi' => ['success', 'Dosya(lar) yüklendi.'],
        'dosya_silindi'  => ['warning', 'Dosya silindi.'],
        'dosya_bos'      => ['warning', 'Lütfen yüklemek için bir dosya seçin.'],
        'izin_disi'      => ['danger',  'İzin verilmeyen dosya türü.'],
        'boyut'          => ['danger',  'Dosya 20 MB sınırını aşıyor.'],
    ]; ?>
    <?php if (isset($msj_map[$_GET['msj']])): [$t,$m] = $msj_map[$_GET['msj']]; ?>
        <div class="alert alert-<?= $t ?> alert-dismissible fade show py-2 mb-3">
            <i class="bi bi-check-circle me-1"></i><?= $m ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- ── Offcanvas: Müşteri Ekle ───────────────────────────────────────────────── -->
<?php if ($rol === 'admin' || $rol === 'yonetici'): ?>
<div class="offcanvas offcanvas-start" tabindex="-1" id="musteriEklePanel" style="width:min(340px,100vw);">
    <div class="offcanvas-header border-bottom">
        <h6 class="offcanvas-title text-primary mb-0">
            <i class="bi bi-person-plus-fill me-1"></i>Müşteri Ekle
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body">
        <form method="POST" enctype="multipart/form-data">
            <div class="mb-2">
                <label class="form-label small text-muted mb-1">Ad Soyad *</label>
                <input type="text" name="ad_soyad" class="form-control form-control-sm" required>
            </div>
            <div class="mb-2">
                <label class="form-label small text-muted mb-1">Ticari Ünvan</label>
                <input type="text" name="ticari_unvan" class="form-control form-control-sm">
            </div>
            <div class="mb-2">
                <label class="form-label small text-muted mb-1">Telefon</label>
                <input type="text" name="telefon" class="form-control form-control-sm">
            </div>
            <div class="mb-2">
                <label class="form-label small text-muted mb-1">Web Sayfası</label>
                <input type="text" name="web_sayfasi" class="form-control form-control-sm" placeholder="www.ornek.com">
            </div>
            <div class="mb-2">
                <label class="form-label small text-muted mb-1">Yetkili Adı</label>
                <input type="text" name="yetkili_ad_soyad" class="form-control form-control-sm">
            </div>
            <div class="mb-2">
                <label class="form-label small text-muted mb-1">E-posta</label>
                <input type="email" name="eposta" class="form-control form-control-sm" placeholder="ornek@sirket.com">
            </div>
            <div class="row g-2 mb-2">
                <div class="col-6">
                    <label class="form-label small text-muted mb-1">İl</label>
                    <input type="text" name="ili" class="form-control form-control-sm">
                </div>
                <div class="col-6">
                    <label class="form-label small text-muted mb-1">İlçe</label>
                    <input type="text" name="ilcesi" class="form-control form-control-sm">
                </div>
            </div>
            <div class="row g-2 mb-2">
                <div class="col-6">
                    <label class="form-label small text-muted mb-1">
                        <i class="bi bi-shop me-1"></i>Mağaza Sayısı
                    </label>
                    <input type="number" name="magaza_sayisi" class="form-control form-control-sm" min="0" placeholder="0">
                </div>
                <div class="col-6">
                    <label class="form-label small text-muted mb-1">
                        <i class="bi bi-people me-1"></i>Çalışan Sayısı
                    </label>
                    <input type="number" name="calisan_sayisi" class="form-control form-control-sm" min="0" placeholder="0">
                </div>
            </div>
            <div class="mb-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label class="form-label small text-muted mb-0">
                        <i class="bi bi-credit-card me-1"></i>Ödeme Kuruluşu
                    </label>
                    <?php if ($rol === 'admin' || $rol === 'yonetici' || ($_SESSION['kullanici_adi'] ?? '') === 'azzet'): ?>
                    <button type="button" class="btn btn-outline-success py-0 px-2"
                            style="font-size:.7rem;line-height:1.6;"
                            onclick="document.getElementById('yeniKurulusDiv').classList.toggle('d-none')"
                            title="Yeni kuruluş ekle">
                        <i class="bi bi-plus-lg"></i>
                    </button>
                    <?php endif; ?>
                </div>
                <div id="yeniKurulusDiv" class="d-none mb-1">
                    <div class="input-group input-group-sm">
                        <input type="text" id="yeniKurulusInput" class="form-control"
                               placeholder="Kuruluş adı...">
                        <button type="button" class="btn btn-success" onclick="yeniKurulusKaydet()">
                            <i class="bi bi-check-lg"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary"
                                onclick="document.getElementById('yeniKurulusDiv').classList.add('d-none')">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
                <select name="odeme_kurulusu" id="odemeKurulusuSec" class="form-select form-select-sm">
                    <option value="">— Seçin —</option>
                    <?php foreach ($odeme_kuruluslari as $ok): ?>
                        <option><?= htmlspecialchars($ok) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-2">
                <label class="form-label small text-muted mb-1">Çalışma Modeli</label>
                <select name="calisma_modeli" class="form-select form-select-sm">
                    <option value="">— Seçin —</option>
                    <?php foreach ($calisma_modeli_sec as $cm): ?>
                        <option><?= $cm ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-2">
                <label class="form-label small text-muted mb-1">Ulaştığı Mecra</label>
                <select name="kanal" class="form-select form-select-sm">
                    <option value="">— Seçin —</option>
                    <?php foreach ($kanal_secenekleri as $k): ?>
                        <option><?= $k ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-2">
                <label class="form-label small text-muted mb-1">Müşteri Temsilcisi</label>
                <select name="musteri_temsilcisi" class="form-select form-select-sm">
                    <option value="">— Seçin —</option>
                    <?php foreach ($temsilciler as $t): ?>
                        <option><?= $t ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-2">
                <label class="form-label small text-muted mb-1">Ödeme Kuruluşu Temsilcisi</label>
                <select name="odeme_kurulusu_temsilcisi" class="form-select form-select-sm">
                    <option value="">— Seçin —</option>
                    <?php foreach ($odeme_temsilcileri as $ot): ?>
                        <option><?= $ot ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-2">
                <label class="form-label small text-muted mb-1">Durum</label>
                <select name="durum" class="form-select form-select-sm">
                    <option value="">— Seçin —</option>
                    <?php foreach ($durum_secenekleri as $d): ?>
                        <option><?= $d ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-2">
                <label class="form-label small text-muted mb-1">Teslimat Süreci</label>
                <select name="teslimat_sureci" class="form-select form-select-sm">
                    <option value="">— Seçin —</option>
                    <?php foreach ($teslimat_secenekleri as $ts): ?>
                        <option><?= $ts ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php foreach ($sorular as $soru): ?>
            <div class="mb-2">
                <label class="form-label small text-muted mb-1"><i class="bi bi-chat-square-text me-1"></i><?= htmlspecialchars($soru['baslik']) ?></label>
                <select name="soru[<?= $soru['id'] ?>]" class="form-select form-select-sm">
                    <option value="">— Seçin —</option>
                    <?php foreach ($soru_secenekleri[$soru['id']] as $cv): ?><option><?= htmlspecialchars($cv) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php endforeach; ?>
            <div class="mb-2">
                <label class="form-label small text-muted mb-1">Not</label>
                <textarea name="not" class="form-control form-control-sm" rows="2" placeholder="Müşteri notu..."></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label small text-muted mb-1">
                    <i class="bi bi-paperclip me-1"></i>Dosya Ekle (opsiyonel)
                </label>
                <input type="file" name="dosyalar[]" class="form-control form-control-sm" multiple
                       accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.gif,.webp,.txt,.zip,.rar">
                <div class="form-text" style="font-size:.7rem;">PDF, Word, Excel, resim, ZIP — maks 20 MB</div>
            </div>
            <button type="submit" name="musteri_kaydet" class="btn btn-primary w-100 mt-1">
                <i class="bi bi-send-fill me-1"></i>Havuza Gönder
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ── Müşteri Düzenle Offcanvas (tüm roller) ────────────────────────────────── -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="musteriDuzenlePanel" style="width:min(360px,100vw);">
    <div class="offcanvas-header border-bottom">
        <h6 class="offcanvas-title text-primary mb-0">
            <i class="bi bi-pencil-fill me-1"></i>Müşteri Düzenle
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body">
        <form method="POST" id="duzenleForm">
            <input type="hidden" name="musteri_id" id="duz_musteri_id">
            <div class="mb-2"><label class="form-label small text-muted mb-1">Ad Soyad *</label>
                <input type="text" name="ad_soyad" id="duz_ad_soyad" class="form-control form-control-sm" required>
            </div>
            <div class="mb-2"><label class="form-label small text-muted mb-1">Ticari Ünvan</label>
                <input type="text" name="ticari_unvan" id="duz_ticari_unvan" class="form-control form-control-sm">
            </div>
            <div class="mb-2"><label class="form-label small text-muted mb-1">Telefon</label>
                <input type="text" name="telefon" id="duz_telefon" class="form-control form-control-sm">
            </div>
            <div class="mb-2"><label class="form-label small text-muted mb-1">Web Sayfası</label>
                <input type="text" name="web_sayfasi" id="duz_web" class="form-control form-control-sm">
            </div>
            <div class="mb-2"><label class="form-label small text-muted mb-1">Yetkili Adı</label>
                <input type="text" name="yetkili_ad_soyad" id="duz_yetkili" class="form-control form-control-sm">
            </div>
            <div class="mb-2"><label class="form-label small text-muted mb-1">E-posta</label>
                <input type="email" name="eposta" id="duz_eposta" class="form-control form-control-sm">
            </div>
            <div class="row g-2 mb-2">
                <div class="col-6"><label class="form-label small text-muted mb-1">İl</label>
                    <input type="text" name="ili" id="duz_il" class="form-control form-control-sm">
                </div>
                <div class="col-6"><label class="form-label small text-muted mb-1">İlçe</label>
                    <input type="text" name="ilcesi" id="duz_ilce" class="form-control form-control-sm">
                </div>
            </div>
            <div class="row g-2 mb-2">
                <div class="col-6"><label class="form-label small text-muted mb-1">Mağaza Sayısı</label>
                    <input type="number" name="magaza_sayisi" id="duz_magaza" class="form-control form-control-sm" min="0">
                </div>
                <div class="col-6"><label class="form-label small text-muted mb-1">Çalışan Sayısı</label>
                    <input type="number" name="calisan_sayisi" id="duz_calisan" class="form-control form-control-sm" min="0">
                </div>
            </div>
            <div class="mb-2"><label class="form-label small text-muted mb-1"><i class="bi bi-credit-card me-1"></i>Ödeme Kuruluşu</label>
                <select name="odeme_kurulusu" id="duz_odeme" class="form-select form-select-sm">
                    <option value="">— Seçin —</option>
                    <?php foreach ($odeme_kuruluslari as $ok): ?>
                        <option><?= htmlspecialchars($ok) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" id="duz_odeme_eski" value="">
                <!-- Kuruluş değiştirilince görünür: eski başvuru geçmişe hangi sonuçla düşsün -->
                <div id="duz_odeme_gecis" class="border rounded p-2 mt-2" style="display:none;">
                    <div class="small mb-1"><i class="bi bi-clock-history me-1"></i>Önceki kuruluş <b id="duz_odeme_eski_ad"></b> geçmişe alınacak. Sonucu:</div>
                    <select name="eski_odeme_sonuc" class="form-select form-select-sm mb-1">
                        <option value="olumsuz">Olumsuz döndü</option>
                        <option value="onaylandi">Onaylandı</option>
                        <option value="vazgecildi">Vazgeçildi</option>
                    </select>
                    <input type="text" name="eski_odeme_not" class="form-control form-control-sm" placeholder="Not (isteğe bağlı, ör. Findeks skoru düşük)">
                </div>
            </div>
            <div class="mb-2"><label class="form-label small text-muted mb-1">Ödeme Kuruluşu Temsilcisi</label>
                <select name="odeme_kurulusu_temsilcisi" id="duz_odeme_temsilcisi" class="form-select form-select-sm">
                    <option value="">— Seçin —</option>
                    <?php foreach ($odeme_temsilcileri as $ot): ?>
                        <option><?= $ot ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-2"><label class="form-label small text-muted mb-1">Çalışma Modeli</label>
                <select name="calisma_modeli" id="duz_model" class="form-select form-select-sm">
                    <option value="">— Seçin —</option>
                    <?php foreach ($calisma_modeli_sec as $cm): ?>
                        <option><?= $cm ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-2"><label class="form-label small text-muted mb-1">Ulaştığı Mecra</label>
                <select name="kanal" id="duz_kanal" class="form-select form-select-sm">
                    <option value="">— Seçin —</option>
                    <?php foreach ($kanal_secenekleri as $k): ?>
                        <option><?= $k ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Temsilci: Admin/Yönetici doğrudan değiştirir, Görevli istek gönderir -->
            <div class="mb-2">
                <label class="form-label small text-muted mb-1">Müşteri Temsilcisi</label>
                <?php if ($rol === 'admin' || $rol === 'yonetici'): ?>
                <select name="musteri_temsilcisi" id="duz_temsilci" class="form-select form-select-sm">
                    <option value="">— Seçin —</option>
                    <?php foreach ($temsilciler as $t): ?>
                        <option><?= $t ?></option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <div class="d-flex align-items-center gap-2">
                    <span id="duz_temsilci_goster" class="badge bg-primary small"></span>
                    <button type="button" class="btn btn-sm btn-outline-warning"
                            onclick="temsilciIsteModal()">
                        <i class="bi bi-arrow-repeat me-1"></i>Değişiklik İste
                    </button>
                </div>
                <input type="hidden" id="duz_temsilci_mevcut" name="_temsilci_bilgi" value="">
                <?php endif; ?>
            </div>

            <?php foreach ($sorular as $soru): ?>
            <div class="mb-2">
                <label class="form-label small text-muted mb-1"><i class="bi bi-chat-square-text me-1"></i><?= htmlspecialchars($soru['baslik']) ?></label>
                <select name="soru[<?= $soru['id'] ?>]" class="form-select form-select-sm duz-soru" data-soru="<?= $soru['id'] ?>">
                    <option value="">— Seçin —</option>
                    <?php foreach ($soru_secenekleri[$soru['id']] as $cv): ?><option><?= htmlspecialchars($cv) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php endforeach; ?>
            <div class="mb-3"><label class="form-label small text-muted mb-1">Not</label>
                <textarea name="not" id="duz_not" class="form-control form-control-sm" rows="2"></textarea>
            </div>
            <button type="submit" name="musteri_duzenle" class="btn btn-primary w-100">
                <i class="bi bi-check-lg me-1"></i>Kaydet
            </button>
        </form>
    </div>
</div>

<!-- Temsilci Değişiklik İste Modalı (Görevli için) -->
<!-- "Olumsuz Döndü" seçilince: yeni kuruluş mu, başvuru yok mu? -->
<div class="modal fade" id="olumsuzKurulusModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title"><i class="bi bi-x-octagon text-danger me-1"></i>Ödeme Kuruluşundan Olumsuz Döndü</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="small mb-2"><b id="olumsuzMevcutKurulus">—</b> başvurusu olumsuz olarak kapatılacak. Sonraki adım?</p>
        <select id="olumsuzYeniKurulus" class="form-select form-select-sm">
            <option value="">Başvuru yapılmayacak</option>
            <?php foreach ($odeme_kuruluslari as $ok): ?>
                <option value="<?= htmlspecialchars($ok, ENT_QUOTES) ?>"><?= htmlspecialchars($ok) ?> — yeni başvuru</option>
            <?php endforeach; ?>
        </select>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Vazgeç</button>
        <button type="button" class="btn btn-danger btn-sm" id="olumsuzOnayla">Kaydet</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="temsilciIsteModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title"><i class="bi bi-arrow-repeat text-warning me-2"></i>Temsilci Değişiklik İsteği</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="musteri_id" id="istek_musteri_id">
                <input type="hidden" name="musteri_adi" id="istek_musteri_adi">
                <input type="hidden" name="mevcut_temsilci" id="istek_mevcut_temsilci">
                <div class="modal-body pb-2">
                    <p class="small text-muted mb-2">Mevcut: <strong id="istek_mevcut_goster"></strong></p>
                    <label class="form-label small fw-semibold">İstenen Temsilci</label>
                    <select name="istenen_temsilci" class="form-select form-select-sm" required>
                        <option value="">— Seçin —</option>
                        <?php foreach ($temsilciler as $t): ?>
                            <option><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" name="temsilci_iste" class="btn btn-sm btn-warning">
                        <i class="bi bi-send me-1"></i>İstek Gönder
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- İstatistik kartları kaldırıldı (2026-09-23, kullanıcı talebiyle) -->

<div class="row">
    <div class="col-12">
        <div class="card p-3 p-md-4">

            <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2 flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <?php if ($cop_modu): ?>
                    <h5 class="mb-0 text-danger">
                        <i class="bi bi-trash3-fill me-1"></i>Çöp Kutusu
                        <span class="badge bg-danger ms-1"><?= $toplam_musteri ?></span>
                    </h5>
                    <a href="dashboard.php" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>Geri
                    </a>
                    <?php else: ?>
                    <h5 class="mb-0 text-primary">
                        <?= ($rol === 'admin' || $rol === 'yonetici') ? 'Tüm Müşteri Havuzu' : 'Bana Atanan Müşteriler' ?>
                        <span class="badge bg-primary ms-1"><?= $toplam_musteri ?></span>
                    </h5>
                    <?php if ($rol === 'admin' || $rol === 'yonetici'): ?>
                    <button class="btn btn-success btn-sm" data-bs-toggle="offcanvas" data-bs-target="#musteriEklePanel">
                        <i class="bi bi-person-plus-fill me-1"></i>Müşteri Ekle
                    </button>
                    <?php endif; ?>
                    <?php if ($silme_yetkili): ?>
                    <a href="dashboard.php?cop=1" class="btn btn-sm btn-outline-danger">
                        <i class="bi bi-trash3 me-1"></i>Çöp Kutusu
                        <?php if ($cop_sayisi > 0): ?>
                            <span class="badge bg-danger ms-1"><?= $cop_sayisi ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <form method="GET" class="d-flex gap-1" style="max-width:280px;width:100%;">
                    <input type="text" name="ara" class="form-control form-control-sm"
                           placeholder="Ad, telefon, ünvan..." value="<?= htmlspecialchars($ara) ?>">
                    <button type="submit" class="btn btn-outline-primary btn-sm">Ara</button>
                    <?php if ($ara || $aktif_filtre): ?>
                        <a href="dashboard.php" class="btn btn-sm btn-light text-danger" title="Filtreleri Temizle">✕</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if (!$cop_modu): ?>
            <!-- ── Filtre Çubuğu ── -->
            <form method="GET" class="mb-3">
                <?php if ($ara): ?><input type="hidden" name="ara" value="<?= htmlspecialchars($ara) ?>"><?php endif; ?>
                <div class="row g-2 align-items-end p-2 rounded" style="background:var(--bs-secondary-bg);border:1px solid var(--bs-border-color);">
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label small text-muted mb-1">Durum</label>
                        <select name="fil_durum" class="form-select form-select-sm">
                            <option value="">Hepsi</option>
                            <?php foreach ($durum_secenekleri as $d): ?>
                                <option <?= $fil_durum===$d?'selected':'' ?>><?= htmlspecialchars($d) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label small text-muted mb-1">Teslimat</label>
                        <select name="fil_teslimat" class="form-select form-select-sm">
                            <option value="hepsi" <?= $fil_teslimat==='hepsi'?'selected':'' ?>>Hepsi</option>
                            <option value="" <?= $fil_teslimat===''?'selected':'' ?>>Teslim Edilmeyen</option>
                            <?php foreach ($teslimat_secenekleri as $t): ?>
                                <option <?= $fil_teslimat===$t?'selected':'' ?>><?= htmlspecialchars($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label small text-muted mb-1">Ödeme Kuruluşu</label>
                        <select name="fil_odeme" class="form-select form-select-sm">
                            <option value="">Hepsi</option>
                            <?php foreach ($odeme_kuruluslari as $o): ?>
                                <option <?= $fil_odeme===$o?'selected':'' ?>><?= htmlspecialchars($o) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label small text-muted mb-1">Çalışma Modeli</label>
                        <select name="fil_model" class="form-select form-select-sm">
                            <option value="">Hepsi</option>
                            <?php foreach ($calisma_modeli_sec as $c): ?>
                                <option <?= $fil_model===$c?'selected':'' ?>><?= htmlspecialchars($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2">
                        <label class="form-label small text-muted mb-1">Temsilci</label>
                        <select name="fil_temsilci" class="form-select form-select-sm">
                            <option value="">Hepsi</option>
                            <?php foreach ($temsilciler as $t): ?>
                                <option <?= $fil_temsilci===$t?'selected':'' ?>><?= htmlspecialchars($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-4 col-lg-2 d-flex gap-1">
                        <button type="submit" class="btn btn-primary btn-sm flex-fill">
                            <i class="bi bi-funnel-fill me-1"></i>Filtrele
                        </button>
                        <?php if ($aktif_filtre): ?>
                        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm" title="Temizle">✕</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($aktif_filtre): ?>
                <div class="mt-2 d-flex flex-wrap gap-1">
                    <small class="text-muted me-1">Filtreler:</small>
                    <?php if ($fil_durum):    ?><span class="badge bg-info text-dark"><?= htmlspecialchars($fil_durum) ?></span><?php endif; ?>
                    <?php if ($fil_teslimat): ?><span class="badge bg-success"><?= htmlspecialchars($fil_teslimat === 'hepsi' ? 'Teslimat: Hepsi' : $fil_teslimat) ?></span><?php endif; ?>
                    <?php if ($fil_odeme):    ?><span class="badge bg-primary"><?= htmlspecialchars($fil_odeme) ?></span><?php endif; ?>
                    <?php if ($fil_model):    ?><span class="badge bg-secondary"><?= htmlspecialchars($fil_model) ?></span><?php endif; ?>
                    <?php if ($fil_temsilci): ?><span class="badge bg-warning text-dark"><?= htmlspecialchars($fil_temsilci) ?></span><?php endif; ?>
                    <?php if ($ara):          ?><span class="badge bg-dark">"<?= htmlspecialchars($ara) ?>"</span><?php endif; ?>
                    <span class="text-muted small ms-1"><?= $toplam_musteri ?> sonuç</span>
                </div>
                <?php endif; ?>
            </form>
            <?php endif; ?>

            <!-- Toplu seçim çubuğu -->
            <?php if ($silme_yetkili): ?>
            <div id="secimBar" class="d-none mb-3">
                <form method="POST" id="topluSilForm">
                    <div class="d-flex flex-wrap align-items-center gap-2 p-2 rounded"
                         style="background:var(--bs-danger-bg-subtle);border:1px solid var(--bs-danger-border-subtle);">
                        <span class="small fw-semibold text-danger">
                            <i class="bi bi-check2-square me-1"></i>
                            <span id="secimSayisi">0</span> kayıt seçildi
                        </span>
                        <div id="secimIdleri"></div>
                        <div class="ms-auto d-flex gap-1">
                            <button type="submit" name="toplu_sil" class="btn btn-danger btn-sm"
                                    onclick="return confirm('Seçili kayıtlar silinsin mi?')">
                                <i class="bi bi-trash-fill me-1"></i>Seçilenleri Sil
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="secimTemizle()">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <div class="table-responsive" id="tableWrap">
                <table class="table table-hover table-sm align-middle" id="musteriTablosu">
                    <thead class="table-light">
                        <tr>
                            <?php if ($silme_yetkili): ?>
                            <th style="width:36px;">
                                <input type="checkbox" class="form-check-input" id="hepsiniSec" title="Tümünü Seç">
                            </th>
                            <?php endif; ?>
                            <th>Müşteri</th>
                            <th class="d-none d-md-table-cell">Ticari Ünvan</th>
                            <th class="d-none d-lg-table-cell">Konum</th>
                            <th class="d-none d-xl-table-cell">Model</th>
                            <th class="d-none d-xl-table-cell">Mecra</th>
                            <th class="d-none d-xl-table-cell">Temsilci</th>
                            <th>Durum</th>
                            <th class="d-none d-lg-table-cell">Teslimat</th>
                            <th class="text-end">İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($musteriler as $m): ?>
                        <tr class="satir-sec<?= (($m['teslimat_sureci'] ?? '') === 'Teslim Edildi') ? ' teslim-edildi-satir' : '' ?>"
                            data-musteri-id="<?= $m['id'] ?>"
                            data-musteri-adi="<?= htmlspecialchars($m['ad_soyad'] ?? '', ENT_QUOTES) ?>"
                            data-m='<?= htmlspecialchars(json_encode([
                                'id'               => $m['id'],
                                'ad_soyad'         => $m['ad_soyad'] ?? '',
                                'ticari_unvan'     => $m['ticari_unvan'] ?? '',
                                'telefon'          => $m['telefon'] ?? '',
                                'web_sayfasi'      => $m['web_sayfasi'] ?? '',
                                'yetkili_ad_soyad' => $m['yetkili_ad_soyad'] ?? '',
                                'eposta'           => $m['eposta'] ?? '',
                                'ili'              => $m['ili'] ?? '',
                                'ilcesi'           => $m['ilcesi'] ?? '',
                                'magaza_sayisi'    => $m['magaza_sayisi'] ?? '',
                                'calisan_sayisi'   => $m['calisan_sayisi'] ?? '',
                                'odeme_kurulusu'   => $m['odeme_kurulusu'] ?? '',
                                'odeme_kurulusu_temsilcisi' => $m['odeme_kurulusu_temsilcisi'] ?? '',
                                'calisma_modeli'   => $m['calisma_modeli'] ?? '',
                                'ulasim_kanali'    => $m['ulasim_kanali'] ?? '',
                                'musteri_temsilcisi' => $m['musteri_temsilcisi'] ?? '',
                                'musteri_notu'     => $m['musteri_notu'] ?? '',
                                'soru_cevaplari'   => json_decode($m['soru_cevaplari'] ?? '{}', true) ?: new stdClass(),
                            ]), ENT_QUOTES) ?>'>

                            <?php if ($silme_yetkili): ?>
                            <td class="secim-hucre" onclick="event.stopPropagation()">
                                <input type="checkbox" class="form-check-input satir-cb" value="<?= $m['id'] ?>">
                            </td>
                            <?php endif; ?>

                            <td class="musteri-hucre">
                                <strong class="d-block"><?= htmlspecialchars($m['ad_soyad'] ?? '') ?></strong>
                                <?php if (!empty($m['telefon'])): ?>
                                    <span class="text-muted small"><i class="bi bi-telephone"></i> <?= htmlspecialchars($m['telefon']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($m['yetkili_ad_soyad'])): ?>
                                    <span class="text-muted d-block small"><i class="bi bi-person"></i> <?= htmlspecialchars($m['yetkili_ad_soyad']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($m['web_sayfasi'])): ?>
                                    <span class="small text-muted"><i class="bi bi-globe2"></i> <?= htmlspecialchars($m['web_sayfasi']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($m['musteri_notu'])): ?>
                                    <span class="d-block small text-muted fst-italic">
                                        <i class="bi bi-sticky"></i>
                                        <?= htmlspecialchars(mb_substr($m['musteri_notu'], 0, 40)) ?><?= mb_strlen($m['musteri_notu'] ?? '') > 40 ? '…' : '' ?>
                                    </span>
                                <?php endif; ?>
                                <?php $m_cevaplar = json_decode($m['soru_cevaplari'] ?? '{}', true) ?: [];
                                      foreach ($sorular as $soru): if (empty($m_cevaplar[(string)$soru['id']])) continue; ?>
                                    <span class="d-block small text-muted"><i class="bi bi-chat-square-text"></i> <?= htmlspecialchars($soru['baslik']) ?>: <b><?= htmlspecialchars($m_cevaplar[(string)$soru['id']]) ?></b></span>
                                <?php endforeach; ?>
                                <?php if (!empty($m['magaza_sayisi']) || !empty($m['calisan_sayisi'])): ?>
                                    <span class="d-flex gap-1 mt-1 flex-wrap">
                                        <?php if (!empty($m['magaza_sayisi'])): ?>
                                            <span class="badge bg-info text-dark" style="font-size:.63rem;">
                                                <i class="bi bi-shop"></i> <?= (int)$m['magaza_sayisi'] ?> mağaza
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($m['calisan_sayisi'])): ?>
                                            <span class="badge bg-secondary" style="font-size:.63rem;">
                                                <i class="bi bi-people"></i> <?= (int)$m['calisan_sayisi'] ?> çalışan
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <?php
                                // Geçmiş başvurular (kapanmış olanlar) üstü çizili kırmızı, mevcut kuruluş mavi.
                                $gecmis_basvurular = array_values(array_filter($odeme_gecmisi[(int)$m['id']] ?? [], fn($b) => $b['sonuc'] !== 'bekliyor'));
                                $basvuru_sayisi    = count($odeme_gecmisi[(int)$m['id']] ?? []);
                                $unvan_bos = empty($m['ticari_unvan']) && empty($m['odeme_kurulusu']) && !$gecmis_basvurular && empty($m['odeme_kurulusu_temsilcisi']);
                            ?>
                            <td class="d-none d-md-table-cell unvan-hucre<?= $unvan_bos ? ' bos-hucre' : '' ?>" data-etiket="Ticari Ünvan / Ödeme Kuruluşu">
                                <?= htmlspecialchars($m['ticari_unvan'] ?? '—') ?>
                                <?php if (!empty($m['odeme_kurulusu']) || $gecmis_basvurular): ?>
                                    <br><span class="d-inline-flex align-items-center flex-wrap gap-1 mt-1">
                                    <?php foreach ($gecmis_basvurular as $b):
                                        $ipucu = $b['kurulus'] . ' — ' . ($ODEME_SONUC_ETIKET[$b['sonuc']] ?? $b['sonuc'])
                                            . ($b['baslangic'] ? ' · Başvuru: ' . date('d.m.Y', strtotime($b['baslangic'])) : '')
                                            . ($b['sonuc_tarihi'] ? ' · Sonuç: ' . date('d.m.Y', strtotime($b['sonuc_tarihi'])) : '')
                                            . ($b['temsilci'] !== '' ? ' · Temsilci: ' . $b['temsilci'] : '')
                                            . ($b['notu'] !== '' ? ' · Not: ' . $b['notu'] : '');
                                    ?>
                                        <span class="badge odeme-gecmis-rozet <?= $b['sonuc'] === 'olumsuz' ? 'olumsuz' : '' ?>" style="font-size:.63rem;" title="<?= htmlspecialchars($ipucu, ENT_QUOTES) ?>">
                                            <i class="bi bi-credit-card me-1"></i><?= htmlspecialchars($b['kurulus']) ?>
                                        </span><span class="text-muted" style="font-size:.6rem;">→</span>
                                    <?php endforeach; ?>
                                    <?php if (!empty($m['odeme_kurulusu'])): ?>
                                        <span class="badge bg-primary" style="font-size:.63rem;">
                                            <i class="bi bi-credit-card me-1"></i><?= htmlspecialchars($m['odeme_kurulusu']) ?>
                                        </span>
                                        <?php if ($basvuru_sayisi > 1): ?>
                                            <span class="badge bg-secondary" style="font-size:.58rem;"><?= $basvuru_sayisi ?>. başvuru</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($m['odeme_kurulusu_temsilcisi'])): ?>
                                    <br><span class="badge bg-info text-dark mt-1" style="font-size:.63rem;">
                                        <i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($m['odeme_kurulusu_temsilcisi']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td class="d-none d-lg-table-cell alan-hucre<?= empty($m['ili']) ? ' bos-hucre' : '' ?>" data-etiket="Konum">
                                <?php if (!empty($m['ili'])): ?>
                                    <span class="small"><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($m['ili']) ?></span>
                                    <?php if (!empty($m['ilcesi'])): ?>
                                        <span class="text-muted d-block small"><?= htmlspecialchars($m['ilcesi']) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>—<?php endif; ?>
                            </td>

                            <td class="d-none d-xl-table-cell alan-hucre<?= empty($m['calisma_modeli']) ? ' bos-hucre' : '' ?>" data-etiket="Çalışma Modeli">
                                <?php if (!empty($m['calisma_modeli'])): ?>
                                    <span class="badge bg-info text-dark"><?= htmlspecialchars($m['calisma_modeli']) ?></span>
                                <?php else: ?>—<?php endif; ?>
                            </td>

                            <td class="d-none d-xl-table-cell alan-hucre<?= empty($m['ulasim_kanali']) ? ' bos-hucre' : '' ?>" data-etiket="Mecra">
                                <span class="badge bg-secondary"><?= htmlspecialchars($m['ulasim_kanali'] ?? '—') ?></span>
                            </td>

                            <td class="d-none d-xl-table-cell alan-hucre" data-etiket="Temsilci" onclick="event.stopPropagation()">
                                <?php if ($rol === 'admin' || $rol === 'yonetici'): ?>
                                <form method="POST" class="m-0">
                                    <input type="hidden" name="musteri_id" value="<?= $m['id'] ?>">
                                    <input type="hidden" name="atama_yap" value="1">
                                    <select name="gorevli_id" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:100px;">
                                        <option value="">— Temsilci —</option>
                                        <?php foreach ($gorevliler as $g): ?>
                                            <option value="<?= $g['id'] ?>" <?= $m['gorevli_id'] == $g['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($g['ad_soyad']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                                <?php else: ?>
                                    <?php if (!empty($m['musteri_temsilcisi'])): ?>
                                        <span class="small fw-semibold text-primary"><?= htmlspecialchars($m['musteri_temsilcisi']) ?></span>
                                    <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
                                <?php endif; ?>
                            </td>

                            <td class="alan-hucre" data-etiket="Durum" onclick="event.stopPropagation()">
                                <?php $dr = $durum_renk[$m['durum'] ?? ''] ?? 'bg-secondary'; ?>
                                <?php if ($rol === 'admin' || $rol === 'yonetici'): ?>
                                <div class="dropdown">
                                    <span class="badge <?= $dr ?> dropdown-toggle" data-bs-toggle="dropdown"
                                          style="white-space:normal;max-width:130px;display:inline-block;cursor:pointer;">
                                        <?= htmlspecialchars($m['durum'] ?? '—') ?>
                                    </span>
                                    <ul class="dropdown-menu shadow-sm">
                                        <?php foreach ($durum_secenekleri as $ds): ?>
                                        <li><a class="dropdown-item <?= ($m['durum'] === $ds) ? 'active' : '' ?>"
                                               href="?durum_yap=<?= urlencode($ds) ?>&id=<?= $m['id'] ?>&csrf=<?= $_SESSION['csrf_token'] ?>">
                                            <?= htmlspecialchars($ds) ?>
                                        </a></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <?php else: ?>
                                <span class="badge <?= $dr ?>" style="white-space:normal;max-width:130px;display:inline-block;">
                                    <?= htmlspecialchars($m['durum'] ?? '—') ?>
                                </span>
                                <?php endif; ?>
                            </td>

                            <td class="d-none d-lg-table-cell alan-hucre" data-etiket="Teslimat" onclick="event.stopPropagation()">
                                <?php if ($rol === 'admin' || $rol === 'yonetici'): ?>
                                <div class="dropdown">
                                    <?php $tr_ = $teslimat_renk[$m['teslimat_sureci'] ?? ''] ?? 'bg-secondary'; ?>
                                    <span class="badge <?= $tr_ ?> dropdown-toggle" data-bs-toggle="dropdown"
                                          style="white-space:normal;max-width:110px;display:inline-block;cursor:pointer;">
                                        <?= htmlspecialchars($m['teslimat_sureci'] ?? '—') ?>
                                    </span>
                                    <ul class="dropdown-menu shadow-sm">
                                        <?php foreach ($teslimat_secenekleri as $ts): ?>
                                        <li><a class="dropdown-item <?= (($m['teslimat_sureci'] ?? '') === $ts) ? 'active' : '' ?>"
                                               href="?teslimat_yap=<?= urlencode($ts) ?>&id=<?= $m['id'] ?>&csrf=<?= $_SESSION['csrf_token'] ?>">
                                            <?= htmlspecialchars($ts) ?>
                                        </a></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <?php else: ?>
                                    <?php if (!empty($m['teslimat_sureci'])): ?>
                                        <?php $tr_ = $teslimat_renk[$m['teslimat_sureci']] ?? 'bg-secondary'; ?>
                                        <span class="badge <?= $tr_ ?>" style="white-space:normal;max-width:110px;display:inline-block;">
                                            <?= htmlspecialchars($m['teslimat_sureci']) ?>
                                        </span>
                                    <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
                                <?php endif; ?>
                            </td>


                            <td class="islem-hucre" onclick="event.stopPropagation()">
                                <div class="d-flex flex-column gap-1 ms-auto" style="width:76px;">
                                <?php if (!$cop_modu): ?>
                                <!-- Düzenle butonu -->
                                <button class="btn btn-sm btn-outline-primary w-100 duzenle-btn"
                                        data-id="<?= $m['id'] ?>" title="Düzenle">
                                    <i class="bi bi-pencil"></i><span class="btn-islem-text ms-1">Düzenle</span>
                                </button>
                                <?php endif; ?>
                                <?php if ($cop_modu): ?>
                                    <!-- Çöp kutusu modunda: Geri Al + Kalıcı Sil -->
                                    <a href="?geri_al=<?= $m['id'] ?>&csrf=<?= $_SESSION['csrf_token'] ?>" class="btn btn-sm btn-outline-success w-100">
                                        <i class="bi bi-arrow-counterclockwise"></i><span class="btn-islem-text ms-1">Geri Al</span>
                                    </a>
                                    <a href="?kalici_sil=<?= $m['id'] ?>&csrf=<?= $_SESSION['csrf_token'] ?>"
                                       class="btn btn-sm btn-danger w-100"
                                       onclick="return confirm('Kalıcı olarak silinecek, geri alınamaz!')">
                                        <i class="bi bi-trash3-fill"></i><span class="btn-islem-text ms-1">Sil</span>
                                    </a>
                                    <small class="text-muted text-center" style="font-size:.65rem;">
                                        <?= $m['silinme_tarihi'] ? date('d.m.Y', strtotime($m['silinme_tarihi'])) : '' ?>
                                    </small>
                                <?php else: ?>
                                    <!-- Normal mod: WA + Dosya + Sil -->
                                    <div class="d-flex gap-1">
                                        <button class="btn btn-sm btn-outline-secondary flex-fill dosya-btn"
                                                data-id="<?= $m['id'] ?>"
                                                data-adi="<?= htmlspecialchars($m['ad_soyad'] ?? '', ENT_QUOTES) ?>"
                                                title="Dosyalar">
                                            <i class="bi bi-paperclip"></i>
                                            <?php if ((int)$m['dosya_sayisi'] > 0): ?>
                                                <span class="badge bg-primary rounded-pill" style="font-size:.55rem;"><?= $m['dosya_sayisi'] ?></span>
                                            <?php endif; ?>
                                        </button>
                                    </div>
                                    <?php if ($silme_yetkili): ?>
                                    <a href="?sil_id=<?= $m['id'] ?>&csrf=<?= $_SESSION['csrf_token'] ?>"
                                       class="btn btn-sm btn-outline-danger w-100"
                                       onclick="return confirm('Çöp kutusuna taşınsın mı?')">
                                        <i class="bi bi-trash"></i><span class="btn-islem-text ms-1">Sil</span>
                                    </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php
                            // Birleşik aşama çizelgesi — Durum + Teslimat Süreci tek satırda (bkz.
                            // $ASAMA_ZINCIRI notu). Sadece admin/yönetici noktalara tıklayıp aşama
                            // değiştirebilir; diğer roller sadece görür (tıklanamaz span).
                            // GÖRSEL YENİDEN TASARIM (2026-09-01, kullanıcının verdiği referans
                            // görsele göre — "Başvuru Durumu" kartı): büyük tikli daireler + bağlantı
                            // çizgisi + kalın etiket + durum metni + tamamlanma yüzdesi rozeti.
                            $aktif_idx = asama_zinciri_indeksi($ASAMA_ZINCIRI, $m);
                            $duzenlenebilir_mi = ($rol === 'admin' || $rol === 'yonetici');
                            $toplam_adim = count($ASAMA_ZINCIRI);
                            $yuzde = $aktif_idx >= 0 ? (int) round((($aktif_idx + 1) / $toplam_adim) * 100) : 0;
                            $asama_isaretleri = json_decode($m['asama_isaretleri'] ?? '{}', true) ?: [];
                            // Seçenek 3 (2026-09-16): başvuru geçmişi çizelgeye de yansır — "Olumsuz Döndü"
                            // adımı kırmızı işaretlenip altına reddeden kuruluş yazılır; yeniden başvuru
                            // varsa "Ön Başvuru" adımına yeni kuruluş etiketi gelir ve iki adım arasına
                            // JS ile turuncu bir "N. başvuru" köprüsü çizilir.
                            $mus_basvurular   = $odeme_gecmisi[(int)$m['id']] ?? [];
                            $olumsuz_basvurus = array_values(array_filter($mus_basvurular, fn($b) => $b['sonuc'] === 'olumsuz'));
                            $acik_basvuru     = null;
                            foreach ($mus_basvurular as $b) if ($b['sonuc'] === 'bekliyor') $acik_basvuru = $b;
                            $yeniden_basvuru  = $olumsuz_basvurus && $acik_basvuru && count($mus_basvurular) > 1;
                        ?>
                        <tr class="asama-satiri<?= (($m['teslimat_sureci'] ?? '') === 'Teslim Edildi') ? ' teslim-edildi-satir' : '' ?>" onclick="event.stopPropagation()">
                            <td colspan="12" class="pt-0">
                                <?php $tum_basvurular = $odeme_gecmisi[(int)$m['id']] ?? [];
                                      $kapali_var = (bool)array_filter($tum_basvurular, fn($b) => $b['sonuc'] !== 'bekliyor');
                                      if (count($tum_basvurular) > 1 || $kapali_var): ?>
                                <div class="odeme-gecmis-kutu">
                                    <div class="d-flex align-items-center mb-1">
                                        <span class="text-muted" style="font-size:.7rem;"><i class="bi bi-clock-history me-1"></i>Ödeme kuruluşu başvuru geçmişi</span>
                                        <?php if ($rol === 'admin' || $rol === 'yonetici'): ?>
                                        <a href="?basvuru_geri=<?= $m['id'] ?>&csrf=<?= $_SESSION['csrf_token'] ?>"
                                           class="btn btn-outline-warning btn-sm py-0 ms-2" style="font-size:.65rem;"
                                           title="Geçmişteki son hareketi geri alır; tekrar basınca bir öncekine gider"
                                           onclick="return confirm('Başvuru geçmişindeki son hareket geri alınsın mı?')">
                                            <i class="bi bi-arrow-counterclockwise"></i> Geri Al
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                    <table class="odeme-gecmis-tablo">
                                        <?php foreach ($tum_basvurular as $b): ?>
                                        <tr>
                                            <td><span class="badge <?= $b['sonuc'] === 'bekliyor' ? 'bg-primary' : 'odeme-gecmis-rozet ' . ($b['sonuc'] === 'olumsuz' ? 'olumsuz' : '') ?>" style="font-size:.63rem;"><?= htmlspecialchars($b['kurulus']) ?></span></td>
                                            <td><?= $b['baslangic'] ? date('d.m.Y', strtotime($b['baslangic'])) : '—' ?> → <?= $b['sonuc_tarihi'] ? date('d.m.Y', strtotime($b['sonuc_tarihi'])) : '—' ?></td>
                                            <td class="sonuc-<?= htmlspecialchars($b['sonuc']) ?>"><?= htmlspecialchars($ODEME_SONUC_ETIKET[$b['sonuc']] ?? $b['sonuc']) ?></td>
                                            <td><?= htmlspecialchars($b['temsilci'] ?: '—') ?></td>
                                            <td class="text-muted"><?= htmlspecialchars($b['notu']) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </div>
                                <?php endif; ?>
                                <div class="asama-kart">
                                    <?php if ($aktif_idx >= 0): ?>
                                    <span class="asama-yuzde-rozet">%<?= $yuzde ?> tamamlandı</span>
                                    <?php endif; ?>
                                    <div class="asama-cizelgesi<?= $yeniden_basvuru ? ' yeniden-basvuru' : '' ?>" <?= $yeniden_basvuru ? 'data-kopru-etiket="↩ ' . count($mus_basvurular) . '. başvuru: ' . htmlspecialchars($acik_basvuru['kurulus'], ENT_QUOTES) . '"' : '' ?>>
                                        <?php foreach ($ASAMA_ZINCIRI as $i => $a):
                                            $nokta_durumu = $i < $aktif_idx ? 'tamam' : ($i === $aktif_idx ? 'aktif' : 'bekliyor');
                                            $baglanti = $a['alan'] === 'durum'
                                                ? '?durum_yap=' . urlencode($a['deger']) . '&id=' . $m['id'] . '&csrf=' . $_SESSION['csrf_token']
                                                : '?teslimat_yap=' . urlencode($a['deger']) . '&id=' . $m['id'] . '&csrf=' . $_SESSION['csrf_token'];
                                            $etiket = htmlspecialchars($a['deger']);
                                            $durum_metni = $nokta_durumu === 'tamam' ? 'Tamamlandı' : ($nokta_durumu === 'aktif' ? 'Bekleniyor' : '');
                                            $ic_html = $nokta_durumu === 'tamam'
                                                ? '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5 10 17.5 19 7"/></svg>'
                                                : ($nokta_durumu === 'aktif' ? '<span class="asama-ic-nokta"></span>' : '');
                                            $isaret_anahtari = $a['alan'] . '|' . $a['deger'];
                                            $isaretli = !empty($asama_isaretleri[$isaret_anahtari]);
                                            $asama_rol = ''; $ek_html = '';
                                            if ($olumsuz_basvurus && mb_stripos($a['deger'], 'olumsuz') !== false) {
                                                $asama_rol    = 'olumsuz';
                                                $nokta_durumu = 'olumsuz';
                                                $son_olumsuz  = end($olumsuz_basvurus);
                                                $durum_metni  = $son_olumsuz['sonuc_tarihi'] ? date('d.m.Y', strtotime($son_olumsuz['sonuc_tarihi'])) : 'Olumsuz';
                                                $ic_html      = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>';
                                                $ek_html      = '<span class="asama-alt-not">' . htmlspecialchars(implode(', ', array_unique(array_column($olumsuz_basvurus, 'kurulus')))) . '</span>';
                                            } elseif ($yeniden_basvuru && mb_stripos($a['deger'], 'ön başvuru') !== false) {
                                                $asama_rol = 'onbasvuru';
                                                $ek_html   = '<span class="asama-etiket-kutu">' . htmlspecialchars($acik_basvuru['kurulus']) . ' · ' . count($mus_basvurular) . '. başvuru</span>';
                                            }
                                        ?>
                                            <?php if ($i > 0): ?><span class="asama-cizgi <?= $i <= $aktif_idx ? 'tamam' : '' ?>"></span><?php endif; ?>
                                            <div class="asama-adim-sarmal">
                                            <?php if ($duzenlenebilir_mi): ?>
                                            <a href="<?= htmlspecialchars($baglanti) ?>" class="asama-adim <?= $nokta_durumu ?>" <?= $asama_rol ? 'data-asama-rol="' . $asama_rol . '"' : '' ?>>
                                                <span class="asama-nokta"><?= $ic_html ?></span>
                                                <span class="asama-etiket"><?= $etiket ?></span>
                                                <?php if ($durum_metni): ?><span class="asama-durum-metni"><?= $durum_metni ?></span><?php endif; ?>
                                                <?= $ek_html ?>
                                            </a>
                                            <div class="dropdown">
                                                <span class="badge <?= $isaretli ? 'bg-success' : 'bg-secondary' ?> dropdown-toggle asama-isaretle-rozet"
                                                      data-bs-toggle="dropdown" title="Bu müşteriye özel işaret"></span>
                                                <ul class="dropdown-menu shadow-sm">
                                                    <li><a class="dropdown-item <?= $isaretli ? '' : 'active' ?>"
                                                           href="?asama_isaretle=<?= urlencode($isaret_anahtari) ?>&deger=0&id=<?= $m['id'] ?>&csrf=<?= $_SESSION['csrf_token'] ?>">İşaretsiz</a></li>
                                                    <li><a class="dropdown-item <?= $isaretli ? 'active' : '' ?>"
                                                           href="?asama_isaretle=<?= urlencode($isaret_anahtari) ?>&deger=1&id=<?= $m['id'] ?>&csrf=<?= $_SESSION['csrf_token'] ?>">İşaretli</a></li>
                                                </ul>
                                            </div>
                                            <?php else: ?>
                                            <span class="asama-adim <?= $nokta_durumu ?>" <?= $asama_rol ? 'data-asama-rol="' . $asama_rol . '"' : '' ?>>
                                                <span class="asama-nokta"><?= $ic_html ?></span>
                                                <span class="asama-etiket"><?= $etiket ?></span>
                                                <?php if ($durum_metni): ?><span class="asama-durum-metni"><?= $durum_metni ?></span><?php endif; ?>
                                                <?= $ek_html ?>
                                            </span>
                                            <span class="badge <?= $isaretli ? 'bg-success' : 'bg-secondary' ?> asama-isaretle-rozet" title="Bu müşteriye özel işaret"></span>
                                            <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <!-- Müşteriler arası boşluk (çerçeveli grup görünümü, 2026-09-23 kullanıcı talebi) -->
                        <tr class="musteri-bosluk" aria-hidden="true"><td colspan="12"></td></tr>
                        <?php endforeach; ?>

                        <?php if (empty($musteriler)): ?>
                        <tr>
                            <td colspan="12" class="text-center text-muted py-5">
                                <i class="bi bi-inbox fs-2 d-block mb-2"></i>Kayıt bulunamadı.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ── Dosya Yönetimi Modal ───────────────────────────────────────────────────── -->
<div class="modal fade" id="dosyaModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">
                    <i class="bi bi-paperclip me-1"></i>Dosyalar —
                    <span id="dosyaMusteriAdi" class="text-primary"></span>
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Firma türü: hangi evrakların isteneceğini belirler -->
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <label class="small fw-semibold mb-0" for="evrakGrubu">Firma türü:</label>
                    <select id="evrakGrubu" class="form-select form-select-sm" style="width:auto;">
                        <option value="">Seçilmedi</option>
                        <?php foreach (BASVURU_GRUPLARI as $g_k => $g_v): ?>
                            <option value="<?= $g_k ?>"><?= htmlspecialchars($g_v['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span id="evrakSayac" class="badge ms-auto"></span>
                </div>

                <div id="belgeListesi" class="mb-3"></div>

                <div id="dosyaListesi" class="mb-3 text-center text-muted py-3 small">
                    <div class="spinner-border spinner-border-sm me-1"></div> Yükleniyor...
                </div>

                <hr>

                <form method="POST" action="dashboard.php" enctype="multipart/form-data" id="dosyaYukleForm">
                    <input type="hidden" name="musteri_id" id="dosyaMusteriId">
                    <input type="hidden" name="csrf" value="<?= $_SESSION['csrf_token'] ?>">
                    <label class="form-label small fw-semibold mb-2">
                        <i class="bi bi-cloud-upload me-1"></i>Listede olmayan dosya yükle
                    </label>
                    <div id="dropArea" class="border rounded p-3 text-center mb-2 position-relative"
                         style="border-style:dashed!important;min-height:90px;transition:.2s;">
                        <i class="bi bi-cloud-arrow-up fs-3 text-muted d-block mb-1"></i>
                        <span class="small text-muted d-block mb-2">Buraya sürükle bırak <strong>ya da seç</strong></span>
                        <input type="file" name="dosyalar[]" id="dosyaInput"
                               class="form-control form-control-sm" multiple
                               accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.gif,.webp,.txt,.zip,.rar"
                               style="max-width:280px;margin:0 auto;">
                        <div id="seciliDosyalar" class="mt-2"></div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="form-text flex-grow-1 mb-0" style="font-size:.7rem;">
                            PDF, Word, Excel, resim, ZIP/RAR — maks 20 MB
                        </span>
                        <button type="submit" name="dosya_yukle" class="btn btn-primary btn-sm px-3">
                            <i class="bi bi-upload me-1"></i>Yükle
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// ── Dosya Modal (lazy init — Bootstrap yüklendikten sonra çalışır) ────────────
const dosyaModalEl = document.getElementById('dosyaModal');
function getDosyaModal() {
    return bootstrap.Modal.getOrCreateInstance(dosyaModalEl);
}

const ikonMap = {
    pdf:'bi-file-earmark-pdf text-danger', doc:'bi-file-earmark-word text-primary',
    docx:'bi-file-earmark-word text-primary', xls:'bi-file-earmark-excel text-success',
    xlsx:'bi-file-earmark-excel text-success', png:'bi-file-earmark-image text-info',
    jpg:'bi-file-earmark-image text-info', jpeg:'bi-file-earmark-image text-info',
    gif:'bi-file-earmark-image text-info', webp:'bi-file-earmark-image text-info',
    zip:'bi-file-earmark-zip text-warning', rar:'bi-file-earmark-zip text-warning',
    txt:'bi-file-earmark-text text-secondary',
};
function dosyaIkonu(uzanti) { return 'bi ' + (ikonMap[uzanti] || 'bi-file-earmark text-secondary'); }

function dosyaModalAc(musteriId, musteriAdi) {
    document.getElementById('dosyaMusteriId').value = musteriId;
    document.getElementById('dosyaMusteriAdi').textContent = musteriAdi;
    document.getElementById('belgeListesi').innerHTML = '';
    document.getElementById('evrakSayac').textContent = '';
    document.getElementById('dosyaListesi').innerHTML =
        '<div class="text-center text-muted py-3 small"><div class="spinner-border spinner-border-sm me-1"></div> Yükleniyor...</div>';
    document.getElementById('seciliDosyalar').innerHTML = '';
    getDosyaModal().show();
    dosyalariGetir(musteriId);
}

// Müşterinin evrak listesi + yüklenen dosyalar
function dosyalariGetir(musteriId) {
    fetch('dashboard.php?api=dosyalar&musteri_id=' + musteriId)
        .then(r => r.json())
        .then(veri => {
            document.getElementById('evrakGrubu').value = veri.grup || '';
            document.getElementById('evrakGrubu').dataset.musteriId = musteriId;
            belgeleriCiz(musteriId, veri);
            digerDosyalariCiz(veri.digerleri || [], veri.secenek || [], musteriId);
        });
}

// İstenen evraklar: yüklü olanlar dosyasıyla, eksikler uyarı rengiyle
function belgeleriCiz(musteriId, veri) {
    const el = document.getElementById('belgeListesi');
    const sayac = document.getElementById('evrakSayac');
    const belgeler = veri.belgeler || [];

    if (!belgeler.length) {
        sayac.textContent = '';
        sayac.className = 'badge ms-auto';
        el.innerHTML = veri.grup
            ? '<p class="small text-muted mb-0">Bu firma türü için tanımlı belge yok (Ayarlar > Başvuru Alanları).</p>'
            : '<p class="small text-muted mb-0"><i class="bi bi-info-circle me-1"></i>Firma türü seçilince istenen belgeler burada listelenir.</p>';
        return;
    }

    const tamam = belgeler.filter(b => !b.eksik).length;
    sayac.textContent = tamam + '/' + belgeler.length + ' belge tamam';
    sayac.className = 'badge ms-auto ' + (tamam === belgeler.length ? 'text-bg-success' : 'text-bg-warning');

    el.innerHTML = belgeler.map((b, i) => {
        // Yüklü dosyalar: adı, boyutu ve aç/indir/sil düğmeleri
        const dosyaSatirlari = b.dosyalar.map(d => `
            <div class="d-flex align-items-center gap-2 mt-2 ps-2 py-1 rounded bg-body-secondary" style="font-size:.75rem;">
                <i class="${dosyaIkonu(d.uzanti)}"></i>
                <span class="text-truncate flex-grow-1" title="${d.dosya_adi}">${d.dosya_adi}</span>
                <span class="text-muted">${d.boyut || ''}</span>
                <a href="dosya_goster.php?id=${d.id}" target="_blank" class="btn btn-sm btn-outline-success py-0 px-1" title="Aç"><i class="bi bi-eye"></i></a>
                <a href="dosya_goster.php?id=${d.id}&indir=1" class="btn btn-sm btn-outline-primary py-0 px-1" title="İndir"><i class="bi bi-download"></i></a>
                <a href="?dosya_sil=${d.id}&csrf=<?= $_SESSION['csrf_token'] ?>" class="btn btn-sm btn-outline-danger py-0 px-1"
                   title="Sil" onclick="return confirm('Dosya silinsin mi?')"><i class="bi bi-trash"></i></a>
            </div>`).join('');

        const bilgi = `Belge · Min ${b.min_adet} / Max ${b.max_adet} · `
            + (b.dosyalar.length ? `${b.dosyalar.length} dosya yüklü` : 'dosya yok');

        const durum = b.eksik
            ? `<span class="badge text-bg-warning">Eksik${b.min_adet > 1 ? ` ${b.dosyalar.length}/${b.min_adet}` : ''}</span>`
            : '<span class="badge text-bg-success">Tamam</span>';

        return `
        <div class="p-3 mb-2 rounded border ${b.eksik ? 'border-warning-subtle' : ''}">
            <div class="d-flex align-items-center gap-3">
                <span class="border rounded d-flex align-items-center justify-content-center flex-shrink-0"
                      style="width:44px;height:38px;font-size:.85rem;">${i + 1}</span>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="fw-semibold text-truncate" title="${b.ad}">${b.ad}</div>
                    <div class="text-muted" style="font-size:.78rem;">${bilgi}</div>
                </div>
                ${durum}
                <form method="POST" action="dashboard.php" enctype="multipart/form-data" class="d-inline">
                    <input type="hidden" name="musteri_id" value="${musteriId}">
                    <input type="hidden" name="alan_id" value="${b.alan_id}">
                    <input type="hidden" name="dosya_yukle" value="1">
                    <input type="hidden" name="csrf" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="file" name="dosyalar[]" class="d-none" onchange="this.form.submit()"
                           accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.gif,.webp,.txt,.zip,.rar">
                    <button type="button" class="btn btn-sm ${b.eksik ? 'btn-warning' : 'btn-outline-secondary'}"
                            onclick="this.previousElementSibling.click()">
                        <i class="bi bi-upload me-1"></i>${b.eksik ? 'Yükle' : 'Ekle'}
                    </button>
                </form>
            </div>
            ${dosyaSatirlari}
        </div>`;
    }).join('');
}

// Belge listesine bağlı olmayan dosyalar
function digerDosyalariCiz(dosyalar, secenekler, musteriId) {
    const el = document.getElementById('dosyaListesi');
    if (!dosyalar.length) {
        el.innerHTML = '<p class="text-muted small mb-0"><i class="bi bi-inbox me-1"></i>Listede olmayan dosya yok.</p>';
        return;
    }
    const resimler = ['png','jpg','jpeg','gif','webp'];
    const tarayici = ['png','jpg','jpeg','gif','webp','pdf'];

    el.innerHTML = '<p class="small fw-semibold text-muted mb-2">Listede olmayan dosyalar</p>' + dosyalar.map(d => {
        const onizleme = resimler.includes(d.uzanti)
            ? `<img src="dosya_goster.php?id=${d.id}" alt="${d.dosya_adi}"
                   style="width:52px;height:52px;object-fit:cover;border-radius:8px;flex-shrink:0;cursor:pointer;border:1px solid var(--bs-border-color);"
                   onclick="window.open('dosya_goster.php?id=${d.id}','_blank')" title="Büyütmek için tıkla">`
            : `<i class="${dosyaIkonu(d.uzanti)} fs-3 flex-shrink-0" style="width:52px;text-align:center;"></i>`;

        const acBtn = tarayici.includes(d.uzanti)
            ? `<a href="dosya_goster.php?id=${d.id}" target="_blank" class="btn btn-sm btn-outline-success py-0 px-2" title="Tarayıcıda Aç"><i class="bi bi-eye"></i></a>`
            : '';

        return `
        <div class="d-flex align-items-center gap-2 mb-2 p-2 rounded border">
            ${onizleme}
            <div class="flex-grow-1 overflow-hidden">
                <div class="small fw-semibold text-truncate" title="${d.dosya_adi}">${d.dosya_adi}</div>
                <div class="text-muted" style="font-size:.7rem;">
                    ${d.tarih}${d.boyut ? ' · ' + d.boyut : ''}${d.yukleyen ? ' · ' + d.yukleyen : ''}
                </div>
            </div>
            ${secenekler.length ? `
            <select class="form-select form-select-sm" style="width:auto;max-width:190px;"
                    title="Bu dosya hangi belge?" onchange="dosyaAlanAta(${d.id}, this.value, ${musteriId})">
                <option value="">Belge seç…</option>
                ${secenekler.map(o => `<option value="${o.id}">${o.ad}</option>`).join('')}
            </select>` : ''}
            ${acBtn}
            <a href="dosya_goster.php?id=${d.id}&indir=1" class="btn btn-sm btn-outline-primary py-0 px-2" title="İndir">
                <i class="bi bi-download"></i>
            </a>
            <a href="?dosya_sil=${d.id}&csrf=<?= $_SESSION['csrf_token'] ?>" class="btn btn-sm btn-outline-danger py-0 px-2"
               title="Sil" onclick="return confirm('Dosya silinsin mi?')"><i class="bi bi-trash"></i></a>
        </div>`;
    }).join('');
}

// Yüklenmiş bir dosyayı evrak listesindeki belgeye bağlar
function dosyaAlanAta(dosyaId, alanId, musteriId) {
    if (!alanId) return;
    const govde = new FormData();
    govde.append('dosya_alan_ata', '1');
    govde.append('csrf', '<?= $_SESSION['csrf_token'] ?>');
    govde.append('dosya_id', dosyaId);
    govde.append('alan_id', alanId);
    fetch('dashboard.php', { method: 'POST', body: govde })
        .then(r => r.json())
        .then(() => dosyalariGetir(musteriId));
}

// Firma türü değişince evrak listesi yenilenir
document.getElementById('evrakGrubu')?.addEventListener('change', function () {
    const musteriId = this.dataset.musteriId;
    const govde = new FormData();
    govde.append('evrak_grubu_kaydet', '1');
    govde.append('csrf', '<?= $_SESSION['csrf_token'] ?>');
    govde.append('musteri_id', musteriId);
    govde.append('evrak_grubu', this.value);
    fetch('dashboard.php', { method: 'POST', body: govde })
        .then(r => r.json())
        .then(() => dosyalariGetir(musteriId));
});

// Dosya butonları
document.querySelectorAll('.dosya-btn').forEach(btn => {
    btn.addEventListener('click', () => dosyaModalAc(btn.dataset.id, btn.dataset.adi));
});

// Satıra tıkla → modal aç
document.querySelectorAll('.satir-sec').forEach(row => {
    row.style.cursor = 'pointer';
    row.addEventListener('click', function (e) {
        if (e.target.closest('select,a,button,.dropdown,input')) return;
        dosyaModalAc(this.dataset.musteriId, this.dataset.musteriAdi);
    });
});

// Seçili dosyaları göster
document.getElementById('dosyaInput').addEventListener('change', function () {
    const el = document.getElementById('seciliDosyalar');
    if (!this.files.length) { el.innerHTML = ''; return; }
    el.innerHTML = '<div class="d-flex flex-wrap gap-1 justify-content-center">' +
        Array.from(this.files).map(f =>
            `<span class="badge bg-primary"><i class="bi bi-file-earmark me-1"></i>${f.name}</span>`
        ).join('') + '</div>';
});

// Drag & Drop
const dropArea = document.getElementById('dropArea');
['dragover','dragenter'].forEach(ev => dropArea.addEventListener(ev, e => {
    e.preventDefault();
    dropArea.style.background = 'var(--bs-primary-bg-subtle)';
    dropArea.style.borderColor = 'var(--bs-primary)';
}));
['dragleave','drop'].forEach(ev => dropArea.addEventListener(ev, e => {
    e.preventDefault();
    dropArea.style.background = '';
    dropArea.style.borderColor = '';
}));
dropArea.addEventListener('drop', e => {
    const inp = document.getElementById('dosyaInput');
    inp.files = e.dataTransfer.files;
    inp.dispatchEvent(new Event('change'));
});

// ── Dropdown: position:fixed ile overflow kırpma engeli ──────────────────────
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.dropdown-toggle').forEach(function (el) {
        const old = bootstrap.Dropdown.getInstance(el);
        if (old) old.dispose();
        new bootstrap.Dropdown(el, {
            popperConfig: function (defaultConfig) {
                defaultConfig.strategy = 'fixed';
                return defaultConfig;
            }
        });
    });
});

// ── Toplu Seçim ───────────────────────────────────────────────────────────────
<?php if ($silme_yetkili): ?>
const hepsiniSecCB = document.getElementById('hepsiniSec');
const secimBar     = document.getElementById('secimBar');
const secimSayisi  = document.getElementById('secimSayisi');
const secimIdleri  = document.getElementById('secimIdleri');

function secimGuncelle() {
    const secili = document.querySelectorAll('.satir-cb:checked');
    const sayi   = secili.length;
    secimBar.classList.toggle('d-none', sayi === 0);
    secimSayisi.textContent = sayi;
    secimIdleri.innerHTML = '';
    secili.forEach(cb => {
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'secilen[]'; inp.value = cb.value;
        secimIdleri.appendChild(inp);
    });
    const hepsi = document.querySelectorAll('.satir-cb');
    hepsiniSecCB.indeterminate = sayi > 0 && sayi < hepsi.length;
    hepsiniSecCB.checked       = sayi > 0 && sayi === hepsi.length;
}

function secimTemizle() {
    document.querySelectorAll('.satir-cb').forEach(cb => cb.checked = false);
    hepsiniSecCB.checked = hepsiniSecCB.indeterminate = false;
    secimGuncelle();
}

hepsiniSecCB.addEventListener('change', function () {
    document.querySelectorAll('.satir-cb').forEach(cb => cb.checked = this.checked);
    secimGuncelle();
});
document.querySelectorAll('.satir-cb').forEach(cb => cb.addEventListener('change', secimGuncelle));
<?php endif; ?>

// ── Yeni ödeme kuruluşu ───────────────────────────────────────────────────────
async function yeniKurulusKaydet() {
    const input = document.getElementById('yeniKurulusInput');
    const isim  = input.value.trim();
    if (!isim) return;
    const res  = await fetch('dashboard.php?api=odeme_kurulusu_ekle', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'isim=' + encodeURIComponent(isim)
    });
    const data = await res.json();
    if (data.ok) {
        const sel = document.getElementById('odemeKurulusuSec');
        const opt = document.createElement('option');
        opt.value = isim; opt.textContent = isim; opt.selected = true;
        sel.appendChild(opt);
        input.value = '';
        document.getElementById('yeniKurulusDiv').classList.add('d-none');
    }
}
document.getElementById('yeniKurulusInput')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') { e.preventDefault(); yeniKurulusKaydet(); }
});

// ── Müşteri Düzenle Offcanvas ─────────────────────────────────────────────────
document.querySelectorAll('.duzenle-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        const tr = this.closest('tr');
        const m  = JSON.parse(tr.dataset.m);

        document.getElementById('duz_musteri_id').value    = m.id;
        document.getElementById('duz_ad_soyad').value      = m.ad_soyad;
        document.getElementById('duz_ticari_unvan').value  = m.ticari_unvan;
        document.getElementById('duz_telefon').value       = m.telefon;
        document.getElementById('duz_web').value           = m.web_sayfasi;
        document.getElementById('duz_yetkili').value       = m.yetkili_ad_soyad;
        document.getElementById('duz_eposta').value        = m.eposta;
        document.getElementById('duz_il').value            = m.ili;
        document.getElementById('duz_ilce').value          = m.ilcesi;
        document.getElementById('duz_magaza').value        = m.magaza_sayisi;
        document.getElementById('duz_calisan').value       = m.calisan_sayisi;
        document.getElementById('duz_not').value           = m.musteri_notu;
        document.querySelectorAll('.duz-soru').forEach(sel => { sel.value = (m.soru_cevaplari && m.soru_cevaplari[sel.dataset.soru]) || ''; });

        document.getElementById('duz_odeme_eski').value = m.odeme_kurulusu || '';
        document.getElementById('duz_odeme_gecis').style.display = 'none';

        // Select alanları
        ['duz_odeme','duz_odeme_temsilcisi','duz_model','duz_kanal'].forEach(id => {
            const sel = document.getElementById(id);
            if (!sel) return;
            const keys = {duz_odeme:'odeme_kurulusu', duz_odeme_temsilcisi:'odeme_kurulusu_temsilcisi', duz_model:'calisma_modeli', duz_kanal:'ulasim_kanali'};
            Array.from(sel.options).forEach(o => o.selected = (o.value===m[keys[id]]||o.text===m[keys[id]]));
        });

        const duzTemsilci = document.getElementById('duz_temsilci');
        if (duzTemsilci) {
            Array.from(duzTemsilci.options).forEach(o => o.selected = (o.text===m.musteri_temsilcisi));
        }
        const duzTemsilciGoster = document.getElementById('duz_temsilci_goster');
        if (duzTemsilciGoster) {
            duzTemsilciGoster.textContent = m.musteri_temsilcisi || '—';
            document.getElementById('duz_temsilci_mevcut').value = m.musteri_temsilcisi;
        }

        // İstek modalı için
        document.getElementById('istek_musteri_id').value       = m.id;
        document.getElementById('istek_musteri_adi').value      = m.ad_soyad;
        document.getElementById('istek_mevcut_temsilci').value  = m.musteri_temsilcisi;
        if (document.getElementById('istek_mevcut_goster'))
            document.getElementById('istek_mevcut_goster').textContent = m.musteri_temsilcisi || '—';

        bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('musteriDuzenlePanel')).show();
    });
});

// "Olumsuz Döndü" seçimini yakala (çizelge noktası ve Durum menüsü): önce yeni kuruluş sorulur,
// karar URL'ye eklenip öyle gidilir.
(function () {
    let olumsuzHedefUrl = null;
    document.addEventListener('click', e => {
        const a = e.target.closest('a[href*="durum_yap="]');
        if (!a) return;
        let deger = '';
        try { deger = new URL(a.href, location.href).searchParams.get('durum_yap') || ''; } catch (_) { return; }
        if (!/olumsuz/i.test(deger)) return;
        e.preventDefault(); e.stopPropagation();
        olumsuzHedefUrl = a.href;
        // Müşterinin mevcut kuruluşu: linkin bulunduğu satır ya da bir üstündeki satırın data-m'i
        let tr = a.closest('tr'); let m = null;
        while (tr && !m) { if (tr.dataset && tr.dataset.m) m = JSON.parse(tr.dataset.m); else tr = tr.previousElementSibling; }
        document.getElementById('olumsuzMevcutKurulus').textContent = (m && m.odeme_kurulusu) ? m.odeme_kurulusu : 'Mevcut kuruluş';
        const sel = document.getElementById('olumsuzYeniKurulus');
        sel.value = '';
        Array.from(sel.options).forEach(o => { o.disabled = !!(m && o.value && o.value === m.odeme_kurulusu); });
        bootstrap.Modal.getOrCreateInstance(document.getElementById('olumsuzKurulusModal')).show();
    }, true);
    document.getElementById('olumsuzOnayla')?.addEventListener('click', () => {
        if (!olumsuzHedefUrl) return;
        const u = new URL(olumsuzHedefUrl, location.href);
        u.searchParams.set('yeni_kurulus', document.getElementById('olumsuzYeniKurulus').value);
        location.href = u.toString();
    });
})();

// Seçenek 3: "Olumsuz Döndü" adımından "Ön Başvuru" adımına turuncu yeniden-başvuru köprüsü.
// Adım genişlikleri dinamik olduğu için konum ölçülerek çiziliyor; pencere boyutu değişince yenilenir.
function asamaKoprusuCiz() {
    document.querySelectorAll('.asama-cizelgesi.yeniden-basvuru').forEach(cz => {
        cz.querySelectorAll('.asama-kopru').forEach(e => e.remove());
        const bas = cz.querySelector('[data-asama-rol="onbasvuru"] .asama-nokta');
        const son = cz.querySelector('[data-asama-rol="olumsuz"] .asama-nokta');
        if (!bas || !son) return;
        const czR = cz.getBoundingClientRect(), bR = bas.getBoundingClientRect(), sR = son.getBoundingClientRect();
        const x1 = bR.left + bR.width / 2 - czR.left + cz.scrollLeft;
        const x2 = sR.left + sR.width / 2 - czR.left + cz.scrollLeft;
        const k = document.createElement('div');
        k.className = 'asama-kopru';
        k.style.left  = Math.min(x1, x2) + 'px';
        k.style.width = Math.abs(x2 - x1) + 'px';
        k.innerHTML = '<span class="asama-kopru-etiket">' + (cz.dataset.kopruEtiket || '').replace(/</g,'&lt;') + '</span>';
        cz.appendChild(k);
    });
}
asamaKoprusuCiz();
window.addEventListener('resize', asamaKoprusuCiz);

// Ödeme kuruluşu değiştirilince eski başvurunun sonucunu sor (düzenleme paneli)
(function () {
    const odemeSec = document.getElementById('duz_odeme');
    if (!odemeSec) return;
    odemeSec.addEventListener('change', () => {
        const eski = document.getElementById('duz_odeme_eski').value;
        const degisti = eski !== '' && odemeSec.value !== eski;
        document.getElementById('duz_odeme_gecis').style.display = degisti ? '' : 'none';
        document.getElementById('duz_odeme_eski_ad').textContent = eski;
    });
})();

function temsilciIsteModal() {
    bootstrap.Offcanvas.getInstance(document.getElementById('musteriDuzenlePanel'))?.hide();
    setTimeout(() => {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('temsilciIsteModal')).show();
    }, 300);
}

// ── Ekleme sonrası otomatik modal aç ─────────────────────────────────────────
<?php if ($auto_ac): ?>
document.addEventListener('DOMContentLoaded', function () {
    const satir = document.querySelector('[data-musteri-id="<?= $auto_ac ?>"]');
    if (satir) dosyaModalAc(<?= $auto_ac ?>, satir.dataset.musteriAdi);
});
<?php endif; ?>
</script>

<?php require __DIR__ . '/footer.php'; ?>
