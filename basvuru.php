<?php
// Herkese açık online tahsilat başvurusu (2026-09-23, kullanıcı talebiyle: odemeix.net.tr'deki başvuru
// sayfasının aynısı; başvurular portalda "Başvurular" sekmesine düşer). 4 adım tek formda, adımlar JS ile.
require __DIR__ . '/config.php';
require_once __DIR__ . '/metin_helper.php';
require_once __DIR__ . '/basvuru_helper.php';

$db->exec("CREATE TABLE IF NOT EXISTS basvurular (
    id              SERIAL PRIMARY KEY,
    firma_unvani    VARCHAR(250) NOT NULL,
    ortaklik_yapisi VARCHAR(50)  NOT NULL DEFAULT '',
    yetkili         VARCHAR(200) NOT NULL DEFAULT '',
    eposta          VARCHAR(200) NOT NULL DEFAULT '',
    telefon         VARCHAR(50)  NOT NULL DEFAULT '',
    web_sitesi      VARCHAR(250) NOT NULL DEFAULT '',
    odeme_yontemleri VARCHAR(100) NOT NULL DEFAULT '',
    aylik_ciro      VARCHAR(50)  NOT NULL DEFAULT '',
    sube_sayisi     INTEGER,
    calisan_sayisi  INTEGER,
    detay           JSONB NOT NULL DEFAULT '{}'::jsonb,   -- 2. ve 3. adım cevapları
    durum           VARCHAR(20) NOT NULL DEFAULT 'yeni',   -- yeni | aktarildi
    musteri_id      INTEGER REFERENCES musteriler(id) ON DELETE SET NULL,
    olusturma       TIMESTAMP NOT NULL DEFAULT NOW()
)");

basvuru_tablolari_olustur($db);
$ORTAKLIK = ['Tüzel Kişilik', 'Şahıs Firması', 'Dernek ve Vakıf'];
// Sektör listesi (2026-09-24, kullanıcı talebiyle). Galeri → galeri evrak listesi, Aksesuar İletişim → iletişim
// evrak listesi; diğer sektörlerde liste ortaklık yapısına göre (tüzel / şahıs) seçilir.
$SEKTORLER = ['Perakende & E-Ticaret', 'Sanayi, İmalat & Endüstriyel Ürünler', 'İnşaat, Yapı & Gayrimenkul', 'Bilişim, Yazılım, Ajans & Medya',
              'Otomotiv, Lojistik & Ulaşım', 'Enerji, Akaryakıt & Kimya', 'Gıda, Tarım & Ağırlama', 'Profesyonel & Kurumsal Hizmetler', 'Galeri', 'Aksesuar İletişim', 'Diğer'];
$SEKTOR_GRUP = ['Galeri' => 'galeri', 'Aksesuar İletişim' => 'iletisim'];
$grup_alanlari = [];
foreach (BASVURU_GRUPLARI as $g => $_) $grup_alanlari[$g] = basvuru_alanlari($db, $g);
$YONTEM   = ['Blokeli', 'Ertesi Gün'];

$hata = ''; $gonderildi = false;
$v = ['firma_unvani'=>'','ortaklik_yapisi'=>'','yetkili'=>'','eposta'=>'','telefon'=>'','web_sitesi'=>'','aylik_ciro'=>'','sube_sayisi'=>'','calisan_sayisi'=>''];
$yontemler = []; $sektor = ''; $bilgiler = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($v as $k => $_) $v[$k] = trim($_POST[$k] ?? '');
    $v['firma_unvani'] = tr_buyuk($v['firma_unvani']); $v['yetkili'] = tr_buyuk($v['yetkili']);
    $yontemler = array_values(array_intersect((array)($_POST['odeme_yontemleri'] ?? []), $YONTEM));
    $sektor    = in_array($_POST['sektor'] ?? '', $SEKTORLER, true) ? $_POST['sektor'] : '';
    $bilgiler  = array_map('trim', (array)($_POST['bilgi'] ?? []));
    $grup      = basvuru_grubu_sec($v['ortaklik_yapisi'], $SEKTOR_GRUP[$sektor] ?? 'genel');
    $alanlar   = $grup_alanlari[$grup] ?? [];
    if (!empty($_POST['web_adresi'])) {                     // bal küpü
        $gonderildi = true;
    } elseif ($v['firma_unvani'] === '' || !in_array($v['ortaklik_yapisi'], $ORTAKLIK, true) || $v['yetkili'] === '' || $v['eposta'] === ''
              || $v['telefon'] === '' || !$yontemler || $v['aylik_ciro'] === '' || $v['sube_sayisi'] === '' || $v['calisan_sayisi'] === '' || $sektor === '') {
        $hata = 'Lütfen zorunlu alanları doldurun (1. adım firma bilgileri, 2. adım sektör ve ortaklık yapısı).';
    } elseif (!filter_var($v['eposta'], FILTER_VALIDATE_EMAIL)) {
        $hata = 'Geçerli bir e-posta adresi girin.';
    } elseif (empty($_POST['kvkk'])) {
        $hata = 'Devam etmek için KVKK metnini onaylamanız gerekir.';
    } else {
        // 2. adım: yüklenen belgeleri alan bazında topla ve min/max adet kontrolü yap
        $dosya_siniri = (int)ini_get('max_file_uploads') ?: 20;
        $gelen_toplam = 0;
        foreach ((array)($_FILES['belge']['name'] ?? []) as $liste_) $gelen_toplam += count(array_filter((array)$liste_, fn($n) => $n !== ''));
        if ($gelen_toplam >= $dosya_siniri) {
            $hata = "Sunucu tek seferde en fazla {$dosya_siniri} dosya kabul ediyor; daha az dosya seçin (ör. birden çok sayfayı tek PDF'te birleştirin).";
        }
        $yuklenen = []; // alan_id => [[tmp, ad, boyut], ...]
        foreach ($hata === '' ? $alanlar : [] as $a) {
            if ($a['tur'] !== 'belge') continue;
            $liste = [];
            $f = $_FILES['belge']['name'][$a['id']] ?? null;
            if (is_array($f)) {
                foreach ($f as $i => $ad) {
                    if (($_FILES['belge']['error'][$a['id']][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                    $uz = strtolower(pathinfo($ad, PATHINFO_EXTENSION));
                    if (!in_array($uz, BASVURU_IZINLI_UZANTI, true)) { $hata = "\"{$a['ad']}\" için yalnız PDF, JPG, PNG veya WEBP yükleyebilirsiniz."; break 2; }
                    if ($_FILES['belge']['size'][$a['id']][$i] > BASVURU_MAKS_MB * 1024 * 1024) { $hata = "\"{$a['ad']}\" için dosya ".BASVURU_MAKS_MB." MB'dan büyük olamaz."; break 2; }
                    $liste[] = [$_FILES['belge']['tmp_name'][$a['id']][$i], $ad, (int)$_FILES['belge']['size'][$a['id']][$i], $uz];
                }
            }
            if (count($liste) < (int)$a['min_adet']) { $hata = "\"{$a['ad']}\" için en az {$a['min_adet']} dosya yükleyin."; break; }
            if (count($liste) > (int)$a['max_adet']) { $hata = "\"{$a['ad']}\" için en fazla {$a['max_adet']} dosya yükleyebilirsiniz."; break; }
            $yuklenen[$a['id']] = $liste;
        }
        if ($hata === '') {
            $alan_cevaplari = [];
            foreach ($alanlar as $a) if ($a['tur'] === 'bilgi' && ($bilgiler[$a['id']] ?? '') !== '') $alan_cevaplari[] = ['ad' => $a['ad'], 'deger' => $bilgiler[$a['id']]];
            $detay = ['faaliyet' => $sektor, 'sektor' => $sektor, 'grup' => $grup, 'alanlar' => $alan_cevaplari];
            $st = $db->prepare("INSERT INTO basvurular (firma_unvani, ortaklik_yapisi, yetkili, eposta, telefon, web_sitesi, odeme_yontemleri, aylik_ciro, sube_sayisi, calisan_sayisi, detay)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?::jsonb) RETURNING id");
            $st->execute([$v['firma_unvani'], $v['ortaklik_yapisi'], $v['yetkili'], $v['eposta'], $v['telefon'], $v['web_sitesi'],
                          implode(', ', $yontemler), $v['aylik_ciro'], (int)$v['sube_sayisi'], (int)$v['calisan_sayisi'], json_encode($detay, JSON_UNESCAPED_UNICODE)]);
            $bid = (int)$st->fetchColumn();
            $dizin = __DIR__ . '/uploads/basvurular/' . $bid . '/';
            if (!is_dir($dizin)) @mkdir($dizin, 0755, true);
            $bins = $db->prepare("INSERT INTO basvuru_belgeleri (basvuru_id, alan_id, alan_adi, dosya_adi, dosya_yolu, boyut) VALUES (?,?,?,?,?,?)");
            $alan_adlari = array_column($alanlar, 'ad', 'id');
            foreach ($yuklenen as $alan_id => $liste) {
                foreach ($liste as $i => [$tmp, $ad, $boyut, $uz]) {
                    $guvenli = basvuru_slug($alan_adlari[$alan_id] ?? 'belge') . '-' . ($i + 1) . '-' . bin2hex(random_bytes(3)) . '.' . $uz;
                    if (move_uploaded_file($tmp, $dizin . $guvenli)) {
                        $bins->execute([$bid, $alan_id, $alan_adlari[$alan_id] ?? '', $ad, 'uploads/basvurular/' . $bid . '/' . $guvenli, $boyut]);
                    }
                }
            }
            $gonderildi = true;
        }
    }
}
$adimlar = ['Firma Bilgileri', 'Evraklar'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Online Tahsilat Başvurusu — Ödemeix</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    :root { --mor: #5e35b1; --mor-koyu: #4527a0; --metin: #1a1633; --gri: #5f6577; --cizgi: #e6e6ef; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Inter', system-ui, -apple-system, sans-serif; color: var(--metin); background: #fff; }
    .kap { max-width: 1240px; margin: 0 auto; padding: 0 24px; }
    .ust { background: linear-gradient(180deg, #f4f2fb 0%, #fff 100%); padding: 30px 0 20px; text-align: center; }
    .marka { color: var(--mor); font-weight: 800; font-size: 34px; letter-spacing: -.04em; line-height: 1; margin-bottom: 8px; }
    .etiket { font-size: 12px; letter-spacing: .12em; text-transform: uppercase; font-weight: 500; margin-bottom: 10px; }
    h1 { font-size: clamp(26px, 3.2vw, 38px); font-weight: 800; letter-spacing: -.03em; margin: 0 0 10px; }
    .ust p { font-size: 16px; line-height: 1.55; color: var(--gri); max-width: 760px; margin: 0 auto 24px; }
    .adimlar { display: flex; align-items: flex-start; justify-content: center; max-width: 1180px; margin: 0 auto; }
    .adim-nokta { flex: 0 0 auto; width: 120px; text-align: center; }
    .adim-nokta .d { width: 40px; height: 40px; font-size: 14px; border-radius: 50%; border: 2px solid #d9d9e3; color: #9a9aab; font-weight: 700; display: flex; align-items: center; justify-content: center; margin: 0 auto 8px; background: #fff; transition: all .2s; }
    .adim-nokta span { font-size: 13px; font-weight: 600; color: #9a9aab; }
    .adim-nokta.aktif .d { background: var(--mor); border-color: var(--mor); color: #fff; box-shadow: 0 8px 20px rgba(94,53,177,.35); }
    .adim-nokta.aktif span { color: var(--mor); }
    .adim-nokta.tamam .d { border-color: var(--mor); color: var(--mor); }
    .adim-cizgi { flex: 1 1 auto; height: 2px; background: #e3e3ec; margin-top: 20px; max-width: 220px; }
    .govde { padding: 26px 0 50px; }
    .govde-ic { max-width: 860px; }
    .yan { background: #f3effa; border: 1px solid #e3ddf3; border-radius: 22px; padding: 40px; position: sticky; top: 24px; }
    .yan .adim-no { color: var(--mor); font-weight: 700; font-size: 13px; letter-spacing: .1em; margin-bottom: 18px; }
    .yan h2 { font-size: 30px; font-weight: 800; letter-spacing: -.02em; margin: 0 0 14px; }
    .yan p { color: var(--gri); font-size: 17px; line-height: 1.7; margin: 0; }
    .yan .yardim { border-top: 1px solid #ddd6ef; margin-top: 30px; padding-top: 28px; }
    .yan .yardim b { display: block; font-size: 17px; margin-bottom: 8px; }
    .yan .yardim small { color: var(--gri); font-size: 14px; }
    .kart { border: 1px solid var(--cizgi); border-radius: 16px; padding: 28px 36px 26px; background: #fff; box-shadow: 0 16px 60px rgba(26,22,51,.06); position: relative; }
    .kart .ust-etiket { color: var(--mor); font-weight: 700; font-size: 12px; letter-spacing: .04em; margin-bottom: 6px; }
    .kart h3 { font-size: 26px; font-weight: 800; letter-spacing: -.02em; margin: 0 0 6px; }
    .kart .alt { color: var(--gri); font-size: 15px; margin: 0 0 20px; padding-bottom: 18px; border-bottom: 1px solid var(--cizgi); }
    .satir { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .satir3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }
    .alan { margin-bottom: 18px; }
    .alan label { display: block; font-weight: 700; font-size: 14px; margin-bottom: 8px; }
    .alan input, .alan select, .alan textarea { width: 100%; font: inherit; font-size: 15px; padding: 13px 16px; border: 1px solid #d9d9e3; border-radius: 10px; background: #fff; color: var(--metin); }
    .alan textarea { min-height: 150px; resize: vertical; }
    .buyuk { text-transform: uppercase; }
    .alan input:focus, .alan select:focus, .alan textarea:focus { outline: none; border-color: var(--mor); box-shadow: 0 0 0 4px rgba(94,53,177,.12); }
    .secim { display: grid; gap: 12px; }
    .secim.c3 { grid-template-columns: repeat(3, 1fr); } .secim.c2 { grid-template-columns: repeat(2, 1fr); } .secim.c4 { grid-template-columns: repeat(4, 1fr); }
    .secim label { display: block; border: 1px solid #d9d9e3; border-radius: 10px; padding: 14px 16px; font-weight: 700; font-size: 15px; cursor: pointer; margin: 0; transition: all .15s; }
    .secim label:has(input:checked) { border-color: var(--mor); background: #f3effa; color: var(--mor); }
    .secim input { position: absolute; opacity: 0; pointer-events: none; }
    .onay { display: flex; align-items: center; gap: 10px; font-size: 14px; color: var(--gri); margin: 0; }
    .alt-satir { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-top: 8px; }
    .onay input { width: 18px; height: 18px; }
    .butonlar { display: flex; justify-content: flex-end; gap: 14px; }
    .btn { border: 0; border-radius: 10px; background: var(--mor); color: #fff; font: inherit; font-weight: 700; font-size: 15px; padding: 14px 26px; cursor: pointer; box-shadow: 0 10px 30px rgba(94,53,177,.35); }
    .btn:hover { background: var(--mor-koyu); }
    .btn.geri { background: #fff; color: var(--metin); border: 1px solid #d9d9e3; box-shadow: none; }
    .hata { background: #fde8e8; color: #b42318; border-radius: 12px; padding: 14px 18px; margin-bottom: 22px; font-size: 15px; }
    .adim-panel { display: none; } .adim-panel.aktif { display: block; }
    .ozet { border: 1px solid var(--cizgi); border-radius: 14px; overflow: hidden; margin-bottom: 30px; }
    .ozet div { display: grid; grid-template-columns: 240px 1fr; gap: 16px; padding: 14px 20px; border-bottom: 1px solid var(--cizgi); font-size: 15px; }
    .ozet div:last-child { border-bottom: 0; } .ozet div b { color: var(--gri); font-weight: 600; } .ozet .baslik { background: #f7f5fc; font-weight: 700; display: block; color: var(--mor); }
    .tesekkur { text-align: center; padding: 40px 10px; }
    .tesekkur .ikon { width: 84px; height: 84px; border-radius: 50%; background: #ece8f8; color: var(--mor); font-size: 40px; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; }
    .bal { position: absolute; left: -9999px; }
    .evrak-baslik { font-weight: 700; color: var(--mor); font-size: 14px; letter-spacing: .04em; text-transform: uppercase; margin: 6px 0 16px; }
    .evrak { display: flex; gap: 14px; border: 1px solid var(--cizgi); border-radius: 10px; padding: 12px 14px; margin-bottom: 10px; }
    .evrak.not { background: #f7f5fc; border-style: dashed; }
    .evrak-no { flex: 0 0 auto; width: 34px; height: 34px; border-radius: 8px; font-size: 14px; background: var(--mor); color: #fff; font-weight: 700; display: flex; align-items: center; justify-content: center; }
    .evrak.not .evrak-no { background: #b9a9e2; }
    .evrak-ic { flex: 1 1 auto; min-width: 0; }
    .evrak-ad { font-weight: 700; font-size: 14px; line-height: 1.4; } .evrak-ad .zorunlu { color: #e5484d; }
    .evrak-alt { color: var(--gri); font-size: 13px; margin: 3px 0 8px; }
    .evrak input[type=file] { font-size: 13px; padding: 9px 12px; border: 1px dashed #c9c4dc; border-radius: 10px; width: 100%; background: #fbfaff; }
    .evrak input[type=text] { font-size: 14px; padding: 11px 14px; border: 1px solid #d9d9e3; border-radius: 10px; width: 100%; margin-top: 8px; }
    .evrak-secili { font-size: 13px; color: var(--mor); margin-top: 8px; }
    .evrak.eksik { border-color: #e5484d; box-shadow: 0 0 0 3px rgba(229,72,77,.12); }
    @media (max-width: 1000px) {
        .ust { padding: 40px 0 26px; } .ust p { font-size: 17px; margin-bottom: 30px; }
        .adim-nokta { width: 80px; } .adim-nokta span { font-size: 12px; } .adim-nokta .d { width: 40px; height: 40px; } .adim-cizgi { margin-top: 20px; }
        .govde { padding: 30px 0 60px; }
        .kart { padding: 28px 22px; } .kart h3 { font-size: 28px; }
        .satir, .satir3, .secim.c3, .secim.c2, .secim.c4 { grid-template-columns: 1fr; gap: 0; } .secim { gap: 12px; }
        .alan input, .alan select, .alan textarea { padding: 16px 18px; font-size: 16px; } .secim label { padding: 16px 18px; }
        .butonlar { flex-direction: column-reverse; } .btn { width: 100%; } .alt-satir { flex-direction: column; align-items: stretch; }
        .ozet div { grid-template-columns: 1fr; gap: 4px; }
    }
</style>
</head>
<body>
<section class="ust"><div class="kap">
    <div class="marka">ödemeix</div>
    <h1>Online Tahsilat Başvurusu</h1>
    <p>İşletmenize uygun tahsilat altyapısını oluşturmak için başvurunuzu birkaç kısa adımda tamamlayın.</p>
    <div class="adimlar" id="adimGosterge">
        <?php foreach ($adimlar as $i => $ad): ?>
            <?php if ($i > 0): ?><div class="adim-cizgi"></div><?php endif; ?>
            <div class="adim-nokta <?= $i === 0 ? 'aktif' : '' ?>" data-adim="<?= $i + 1 ?>"><div class="d"><?= $i + 1 ?></div><span><?= $ad ?></span></div>
        <?php endforeach; ?>
    </div>
</div></section>

<section class="govde"><div class="kap govde-ic">
    <div class="kart">
    <?php if ($gonderildi): ?>
        <div class="tesekkur"><div class="ikon">✓</div><h3>Başvurunuz alındı</h3><p class="alt" style="border:0;padding:0;margin:0">Ekibimiz başvurunuzu inceleyip en kısa sürede sizinle iletişime geçecek.</p></div>
    <?php else: ?>
        <?php if ($hata): ?><div class="hata"><?= htmlspecialchars($hata) ?></div><?php endif; ?>
        <form method="POST" id="basvuruForm" enctype="multipart/form-data" novalidate>
            <input type="text" name="web_adresi" class="bal" tabindex="-1" autocomplete="off">

            <!-- ADIM 1: Firma Bilgileri (odemeix.net.tr ile birebir) -->
            <div class="adim-panel aktif" data-adim="1">
                <div class="ust-etiket">Üye İşyeri Bilgi Formu</div>
                <h3>Firma bilgilerinizi girin</h3>
                <p class="alt">Başvurunun ilk adımında işletmenizi ve yetkili kişiyi tanıyalım.</p>
                <div class="alan"><label>Firma Ünvanı *</label><input type="text" name="firma_unvani" class="buyuk" value="<?= htmlspecialchars($v['firma_unvani']) ?>" required></div>
                <div class="satir">
                    <div class="alan"><label>Yetkili Kişi Adı Soyadı *</label><input type="text" name="yetkili" class="buyuk" value="<?= htmlspecialchars($v['yetkili']) ?>" required></div>
                    <div class="alan"><label>E-posta *</label><input type="email" name="eposta" value="<?= htmlspecialchars($v['eposta']) ?>" required></div>
                </div>
                <div class="satir">
                    <div class="alan"><label>Cep Telefonu *</label><input type="tel" name="telefon" value="<?= htmlspecialchars($v['telefon']) ?>" required></div>
                    <div class="alan"><label>Web Sitesi</label><input type="url" name="web_sitesi" placeholder="https://" value="<?= htmlspecialchars($v['web_sitesi']) ?>"></div>
                </div>
                <div class="alan"><label>Talep Edilen Ödeme Yöntemleri *</label>
                    <div class="secim c2"><?php foreach ($YONTEM as $y): ?><label><input type="checkbox" name="odeme_yontemleri[]" value="<?= $y ?>" <?= in_array($y, $yontemler, true) ? 'checked' : '' ?>><?= $y ?></label><?php endforeach; ?></div>
                </div>
                <div class="satir3">
                    <div class="alan"><label>Aylık Ciro Ortalama *</label><input type="text" name="aylik_ciro" placeholder="Örn. 500.000 TL" value="<?= htmlspecialchars($v['aylik_ciro']) ?>" required></div>
                    <div class="alan"><label>İşyeri Şube Sayısı *</label><input type="number" name="sube_sayisi" min="0" value="<?= htmlspecialchars($v['sube_sayisi']) ?>" required></div>
                    <div class="alan"><label>SGK'lı Çalışan Sayısı *</label><input type="number" name="calisan_sayisi" min="0" value="<?= htmlspecialchars($v['calisan_sayisi']) ?>" required></div>
                </div>
                <div class="alt-satir">
                    <label class="onay"><input type="checkbox" name="kvkk" value="1" required> KVKK metnini okudum, onaylıyorum.</label>
                    <div class="butonlar"><button type="button" class="btn ileri">Sonraki Adım &nbsp;→</button></div>
                </div>
            </div>

            <!-- ADIM 2: Evraklar (ortaklık yapısı + faaliyet alanına göre liste) -->
            <div class="adim-panel" data-adim="2">
                <div class="ust-etiket">Başvuru Evrak Listesi</div>
                <h3>Gerekli belgeleri yükleyin</h3>
                <p class="alt">Başvurunuzun hızlı sonuçlanabilmesi için belgelerin güncel ve okunaklı olmasına dikkat ediniz. <?= strtoupper(implode(', ', BASVURU_IZINLI_UZANTI)) ?>; dosya başına en fazla <?= BASVURU_MAKS_MB ?> MB.</p>
                <div class="alan"><label>Sektörünüz *</label>
                    <select name="sektor" id="sektorSec" required>
                        <option value="">Seçiniz</option>
                        <?php foreach ($SEKTORLER as $sk): ?><option value="<?= htmlspecialchars($sk, ENT_QUOTES) ?>" data-grup="<?= $SEKTOR_GRUP[$sk] ?? '' ?>" <?= $sektor === $sk ? 'selected' : '' ?>><?= htmlspecialchars($sk) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="alan" id="ortaklikAlani"><label>Ortaklık Yapısı *</label>
                    <div class="secim c3"><?php foreach ($ORTAKLIK as $o): ?><label><input type="radio" name="ortaklik_yapisi" value="<?= $o ?>" <?= $v['ortaklik_yapisi'] === $o ? 'checked' : '' ?> required><?= $o ?></label><?php endforeach; ?></div>
                </div>
                <?php foreach (BASVURU_GRUPLARI as $g => $gb): ?>
                <div class="evrak-grup" data-grup="<?= $g ?>" style="display:none">
                    <div class="evrak-baslik"><?= htmlspecialchars($gb['label']) ?> — Gerekli Belgeler</div>
                    <?php $no = 0; foreach ($grup_alanlari[$g] as $a): $no++; ?>
                    <div class="evrak <?= $a['tur'] ?>">
                        <div class="evrak-no"><?= $no ?></div>
                        <div class="evrak-ic">
                            <div class="evrak-ad"><?= htmlspecialchars($a['ad']) ?><?php if ($a['tur'] === 'belge' && (int)$a['min_adet'] > 0): ?> <span class="zorunlu">*</span><?php endif; ?></div>
                            <?php if ($a['tur'] === 'belge'): ?>
                                <div class="evrak-alt">Minimum <?= (int)$a['min_adet'] ?> dosya &bull; <?= strtoupper(implode(', ', BASVURU_IZINLI_UZANTI)) ?> &bull; Maksimum <?= BASVURU_MAKS_MB ?> MB / dosya</div>
                                <input type="file" name="belge[<?= $a['id'] ?>][]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp" data-min="<?= (int)$a['min_adet'] ?>" data-max="<?= (int)$a['max_adet'] ?>" class="belge-girdi">
                                <div class="evrak-secili"></div>
                            <?php elseif ($a['tur'] === 'bilgi'): ?>
                                <input type="text" name="bilgi[<?= $a['id'] ?>]" value="<?= htmlspecialchars($bilgiler[$a['id']] ?? '') ?>" placeholder="Bilgiyi yazın">
                            <?php else: ?>
                                <div class="evrak-alt">Bu adımda dosya gerekmez, bilginiz için.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
                <div class="butonlar"><button type="button" class="btn geri">← Geri</button><button type="submit" class="btn">Başvuruyu Gönder &nbsp;→</button></div>
            </div>

        </form>
    <?php endif; ?>
    </div>
</div></section>

<script>
(function () {
    const form = document.getElementById('basvuruForm'); if (!form) return;
    const paneller = [...form.querySelectorAll('.adim-panel')];
    const yan = { 1: ['Firma ve İşyeri Bilgileri', 'Başvurunuzun doğru değerlendirilmesi için alanları mümkün olduğunca eksiksiz doldurun.'],
                  2: ['Başvuru Evrakları', 'Firma türünüze göre gerekli belgeleri yükleyin. Yıldızlı belgeler zorunludur.'],
                };
    let adim = 1;
    // Ortaklık yapısı + faaliyet alanı → gösterilecek evrak grubu
    function aktifGrup() {
        const sel = document.getElementById('sektorSec');
        const f = sel && sel.selectedOptions[0] ? (sel.selectedOptions[0].dataset.grup || '') : '';
        if (f === 'iletisim' || f === 'galeri') return f;
        const o = (form.querySelector('input[name="ortaklik_yapisi"]:checked') || {}).value || '';
        return o === 'Şahıs Firması' ? 'sahis' : 'tuzel';
    }
    function grupGoster() {
        const g = aktifGrup();
        form.querySelectorAll('.evrak-grup').forEach(el => { el.style.display = el.dataset.grup === g ? '' : 'none'; });
    }
    form.addEventListener('change', e => {
        if (e.target.name === 'sektor' || e.target.name === 'ortaklik_yapisi') grupGoster();
        if (e.target.classList.contains('belge-girdi')) {
            const n = e.target.files.length;
            e.target.nextElementSibling.textContent = n ? n + ' dosya seçildi: ' + [...e.target.files].map(f => f.name).join(', ') : '';
            e.target.closest('.evrak').classList.remove('eksik');
        }
    });
    const DOSYA_SINIRI = <?= (int)ini_get('max_file_uploads') ?: 20 ?>;
    function evraklarGecerliMi() {
        let ok = true;
        const sektor = document.getElementById('sektorSec');
        if (!sektor.value) { sektor.style.borderColor = '#e5484d'; sektor.scrollIntoView({ behavior: 'smooth', block: 'center' }); return false; }
        sektor.style.borderColor = '';
        const ortaklik = document.getElementById('ortaklikAlani');
        if (!form.querySelector('input[name="ortaklik_yapisi"]:checked')) { ortaklik.querySelector('.secim').style.outline = '2px solid #e5484d'; ortaklik.scrollIntoView({ behavior: 'smooth', block: 'center' }); return false; }
        ortaklik.querySelector('.secim').style.outline = '';
        const grup = form.querySelector('.evrak-grup[data-grup="' + aktifGrup() + '"]');
        grup.querySelectorAll('.belge-girdi').forEach(inp => {
            const n = inp.files.length, min = +inp.dataset.min, max = +inp.dataset.max;
            const kotu = n < min || n > max;
            inp.closest('.evrak').classList.toggle('eksik', kotu);
            if (kotu) ok = false;
        });
        if (!ok) { const ilk = grup.querySelector('.evrak.eksik'); if (ilk) ilk.scrollIntoView({ behavior: 'smooth', block: 'center' }); return false; }
        let toplam = 0; grup.querySelectorAll('.belge-girdi').forEach(i => toplam += i.files.length);
        if (toplam >= DOSYA_SINIRI) { alert('Tek seferde en fazla ' + (DOSYA_SINIRI - 1) + ' dosya yüklenebiliyor (şu an ' + toplam + '). Birden çok sayfayı tek PDF\'te birleştirip tekrar deneyin.'); return false; }
        return ok;
    }
    grupGoster();
    function goster(n) {
        adim = n;
        paneller.forEach(p => p.classList.toggle('aktif', +p.dataset.adim === n));
        document.querySelectorAll('#adimGosterge .adim-nokta').forEach(d => { const k = +d.dataset.adim; d.classList.toggle('aktif', k === n); d.classList.toggle('tamam', k < n); });
        window.scrollTo({ top: document.querySelector('.govde').offsetTop - 20, behavior: 'smooth' });
    }
    function gecerliMi(panel) {
        let ok = true;
        panel.querySelectorAll('input[required], select[required]').forEach(el => {
            let dolu = el.type === 'radio' ? !!form.querySelector(`input[name="${el.name}"]:checked`) : (el.type === 'checkbox' ? el.checked : el.value.trim() !== '');
            if (el.type === 'email' && dolu) dolu = /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(el.value);
            const hedef = el.type === 'radio' || el.type === 'checkbox' ? el.closest('label') : el;
            hedef.style.borderColor = dolu ? '' : '#e5484d';
            if (!dolu) ok = false;
        });
        const yontem = panel.querySelector('input[name="odeme_yontemleri[]"]');
        if (yontem && !form.querySelector('input[name="odeme_yontemleri[]"]:checked')) { ok = false; yontem.closest('.secim').style.outline = '2px solid #e5484d'; }
        else if (yontem) yontem.closest('.secim').style.outline = '';
        return ok;
    }
    form.addEventListener('click', e => {
        if (e.target.closest('.ileri')) { if (gecerliMi(paneller[adim - 1])) goster(adim + 1); }
        if (e.target.closest('.geri')) goster(adim - 1);
    });
    form.addEventListener('submit', e => {
        if (!gecerliMi(paneller[0])) { e.preventDefault(); goster(1); return; }
        if (!evraklarGecerliMi()) { e.preventDefault(); goster(2); return; }
        const b = form.querySelector('button[type=submit]'); b.disabled = true; b.textContent = 'Gönderiliyor…';
    });
})();
</script>
</body>
</html>
