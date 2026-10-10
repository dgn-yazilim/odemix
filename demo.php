<?php
// Herkese açık demo talebi sayfası (2026-09-23, kullanıcı talebiyle: odemeix.net.tr'deki demo
// sayfasının aynısı; gönderilen talepler portalda "Demo Talepleri" sekmesine düşer).
require __DIR__ . '/config.php';
require_once __DIR__ . '/metin_helper.php';

$db->exec("CREATE TABLE IF NOT EXISTS demo_talepleri (
    id         SERIAL PRIMARY KEY,
    ad_soyad   VARCHAR(200) NOT NULL,
    firma      VARCHAR(200) NOT NULL DEFAULT '',
    eposta     VARCHAR(200) NOT NULL DEFAULT '',
    telefon    VARCHAR(50)  NOT NULL DEFAULT '',
    kaynak     VARCHAR(100) NOT NULL DEFAULT '',
    notu       TEXT         NOT NULL DEFAULT '',
    durum      VARCHAR(20)  NOT NULL DEFAULT 'yeni',   -- yeni | aktarildi
    musteri_id INTEGER REFERENCES musteriler(id) ON DELETE SET NULL,
    olusturma  TIMESTAMP NOT NULL DEFAULT NOW()
)");

// "Bizi nereden buldunuz?" şıkları portaldaki Ulaşım Kanalı seçenekleriyle aynı
try {
    $kaynaklar = $db->query("SELECT deger FROM secenekler WHERE kategori='kanal' ORDER BY sira,id")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { $kaynaklar = []; }
if (!$kaynaklar) $kaynaklar = ['Tavsiye', 'Müşterinizde Gördüm', 'Facebook', 'Instagram', 'Google', 'YouTube Reklamı'];

$hata = ''; $gonderildi = false; $v = ['ad_soyad'=>'','firma'=>'','eposta'=>'','telefon'=>'','kaynak'=>'','notu'=>''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($v as $k => $_) $v[$k] = trim($_POST[$k] ?? '');
    $v['ad_soyad'] = tr_buyuk($v['ad_soyad']); $v['firma'] = tr_buyuk($v['firma']);
    if (!empty($_POST['web_adresi'])) {                       // bal küpü: botlar doldurur, insanlar görmez
        $gonderildi = true;
    } elseif ($v['ad_soyad'] === '' || $v['firma'] === '' || $v['eposta'] === '' || $v['telefon'] === '' || $v['kaynak'] === '' || $v['notu'] === '') {
        $hata = 'Lütfen tüm alanları doldurun.';
    } elseif (!filter_var($v['eposta'], FILTER_VALIDATE_EMAIL)) {
        $hata = 'Geçerli bir e-posta adresi girin.';
    } elseif (empty($_POST['onay'])) {
        $hata = 'Devam etmek için bilgilerin işlenmesini kabul etmeniz gerekir.';
    } else {
        $db->prepare("INSERT INTO demo_talepleri (ad_soyad, firma, eposta, telefon, kaynak, notu) VALUES (?,?,?,?,?,?)")
           ->execute([$v['ad_soyad'], $v['firma'], $v['eposta'], $v['telefon'], $v['kaynak'], $v['notu']]);
        $gonderildi = true;
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Demo Talebi — Ödemeix</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    :root { --mor: #5e35b1; --mor-koyu: #4527a0; --metin: #1a1633; --gri: #5f6577; --cizgi: #e6e6ef; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Inter', system-ui, -apple-system, sans-serif; color: var(--metin); background: #fff; }
    .hero { background: linear-gradient(180deg, #f4f2fb 0%, #fff 100%); padding: 56px 0 40px; }
    .kap { max-width: 1240px; margin: 0 auto; padding: 0 24px; }
    .etiket { font-size: 14px; letter-spacing: .12em; text-transform: uppercase; color: var(--metin); font-weight: 500; margin-bottom: 14px; }
    .hero-ic { display: flex; align-items: flex-start; justify-content: space-between; gap: 32px; }
    h1 { font-size: clamp(36px, 5vw, 64px); line-height: 1.05; font-weight: 800; letter-spacing: -.03em; margin: 0 0 22px; }
    h1 span { color: var(--mor); display: block; }
    .hero p { font-size: 20px; line-height: 1.7; color: var(--gri); max-width: 760px; margin: 0 0 26px; }
    .tikler { display: flex; flex-wrap: wrap; gap: 26px; font-weight: 700; font-size: 15px; }
    .tikler span::before { content: '✓ '; }
    .daire { flex: 0 0 auto; width: 240px; height: 240px; border-radius: 50%; background: var(--mor); color: #fff; display: flex; flex-direction: column;
             align-items: center; justify-content: center; text-align: center; box-shadow: 0 24px 60px rgba(94,53,177,.35); }
    .daire b { font-size: 40px; font-weight: 800; letter-spacing: -.02em; }
    .daire small { font-size: 15px; opacity: .9; margin-top: 8px; padding: 0 30px; line-height: 1.4; }
    .ust { background: linear-gradient(180deg, #f4f2fb 0%, #fff 100%); padding: 30px 0 18px; text-align: center; }
    .marka { color: var(--mor); font-weight: 800; font-size: 34px; letter-spacing: -.04em; line-height: 1; margin-bottom: 8px; }
    .ust .etiket { font-size: 12px; letter-spacing: .12em; text-transform: uppercase; font-weight: 500; margin-bottom: 10px; }
    .ust h1 { font-size: clamp(26px, 3.2vw, 38px); font-weight: 800; letter-spacing: -.03em; margin: 0 0 10px; }
    .ust h1 span { display: inline; color: var(--metin); }
    .ust p { font-size: 16px; line-height: 1.55; color: var(--gri); max-width: 760px; margin: 0 auto; }
    .govde { padding: 18px 0 50px; }
    .govde-ic { max-width: 720px; }
    h2 { font-size: clamp(30px, 3.6vw, 48px); line-height: 1.12; font-weight: 800; letter-spacing: -.03em; margin: 0 0 22px; }
    .neden p { font-size: 18px; line-height: 1.7; color: var(--gri); margin: 0 0 30px; }
    .adim { display: flex; gap: 22px; margin-bottom: 26px; }
    .adim .no { flex: 0 0 auto; width: 56px; height: 56px; border-radius: 50%; background: #ece8f8; color: var(--mor); font-weight: 700; font-size: 14px; display: flex; align-items: center; justify-content: center; }
    .adim b { display: block; font-size: 17px; margin-bottom: 6px; }
    .adim span { color: var(--gri); font-size: 17px; line-height: 1.6; }
    .kart { border: 1px solid var(--cizgi); border-radius: 16px; padding: 28px 36px 26px; background: #fff; box-shadow: 0 16px 60px rgba(26,22,51,.06); }
    .kart .ust-etiket { color: var(--mor); font-weight: 700; font-size: 12px; letter-spacing: .04em; margin-bottom: 6px; }
    .kart h3 { font-size: 26px; font-weight: 800; letter-spacing: -.02em; margin: 0 0 6px; }
    .kart .alt { color: var(--gri); font-size: 15px; margin: 0 0 20px; padding-bottom: 18px; border-bottom: 1px solid var(--cizgi); }
    .satir { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .alan { margin-bottom: 18px; }
    .alan label { display: block; font-weight: 700; font-size: 14px; margin-bottom: 8px; }
    .alan input, .alan select, .alan textarea { width: 100%; font: inherit; font-size: 15px; padding: 13px 16px; border: 1px solid #d9d9e3; border-radius: 10px; background: #fff; color: var(--metin); }
    .alan textarea { min-height: 130px; resize: vertical; }
    .buyuk { text-transform: uppercase; }
    .alan input:focus, .alan select:focus, .alan textarea:focus { outline: none; border-color: var(--mor); box-shadow: 0 0 0 4px rgba(94,53,177,.12); }
    .onay { display: flex; align-items: flex-start; gap: 10px; font-size: 14px; color: var(--gri); margin: 4px 0 18px; }
    .onay input { margin-top: 3px; width: 18px; height: 18px; }
    .btn { width: 100%; border: 0; border-radius: 10px; background: var(--mor); color: #fff; font: inherit; font-weight: 700; font-size: 16px; padding: 17px; cursor: pointer;
           box-shadow: 0 10px 30px rgba(94,53,177,.35); transition: background .15s; }
    .btn:hover { background: var(--mor-koyu); }
    .hata { background: #fde8e8; color: #b42318; border-radius: 12px; padding: 14px 18px; margin-bottom: 22px; font-size: 15px; }
    .tesekkur { text-align: center; padding: 40px 10px; }
    .tesekkur .ikon { width: 84px; height: 84px; border-radius: 50%; background: #ece8f8; color: var(--mor); font-size: 40px; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; }
    .bal { position: absolute; left: -9999px; }
    @media (max-width: 900px) {
        .hero { padding: 40px 0 30px; }
        .hero-ic { flex-direction: column; }
        .daire { width: 170px; height: 170px; } .daire b { font-size: 28px; } .daire small { font-size: 13px; }
        .ust { padding: 40px 0 20px; } .ust p { font-size: 17px; }
        .govde { padding: 10px 0 40px; }
        .kart { padding: 28px 22px; } .kart h3 { font-size: 26px; }
        .satir { grid-template-columns: 1fr; gap: 0; }
        .alan input, .alan select, .alan textarea { padding: 16px 18px; font-size: 16px; }
    }
</style>
</head>
<body>
<section class="ust"><div class="kap">
    <div class="marka">ödemeix</div>
    <h1>Demo Talebi</h1>
    <p>Mevcut tahsilat yapınızı dinleyelim, size uygun Ödemeix kullanım senaryosunu kısa bir demo ile gösterelim.</p>
</div></section>

<section class="govde"><div class="kap govde-ic">
    <div class="kart">
        <?php if ($gonderildi): ?>
        <div class="tesekkur">
            <div class="ikon">✓</div>
            <h3>Talebiniz alındı</h3>
            <p class="alt" style="border:0;padding:0;margin:0">Ekibimiz en kısa sürede sizinle iletişime geçecek.</p>
        </div>
        <?php else: ?>
        <div class="ust-etiket">Demo Talep Formu</div>
        <h3>Bilgilerinizi paylaşın</h3>
        <p class="alt">Size ulaşabilmemiz için aşağıdaki alanları eksiksiz doldurun.</p>
        <?php if ($hata): ?><div class="hata"><?= htmlspecialchars($hata) ?></div><?php endif; ?>
        <form method="POST" novalidate>
            <input type="text" name="web_adresi" class="bal" tabindex="-1" autocomplete="off">
            <div class="satir">
                <div class="alan"><label>İsim / Soyisim *</label><input type="text" name="ad_soyad" class="buyuk" placeholder="Adınız Soyadınız" value="<?= htmlspecialchars($v['ad_soyad']) ?>" required></div>
                <div class="alan"><label>Firma Adı *</label><input type="text" name="firma" class="buyuk" placeholder="Firma / Marka adı" value="<?= htmlspecialchars($v['firma']) ?>" required></div>
            </div>
            <div class="satir">
                <div class="alan"><label>E-Posta Adresi *</label><input type="email" name="eposta" placeholder="ornek@firma.com" value="<?= htmlspecialchars($v['eposta']) ?>" required></div>
                <div class="alan"><label>Telefon Numarası *</label><input type="tel" name="telefon" placeholder="+90 5__ ___ _ _" value="<?= htmlspecialchars($v['telefon']) ?>" required></div>
            </div>
            <div class="alan"><label>Bizi Nereden Buldunuz? *</label>
                <select name="kaynak" required>
                    <option value="">Seçiniz</option>
                    <?php foreach ($kaynaklar as $k): ?><option <?= $v['kaynak'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($k) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="alan"><label>Konu / Notunuz *</label><textarea name="notu" placeholder="Demo sırasında özellikle görmek istediğiniz özellikleri yazabilirsiniz." required><?= htmlspecialchars($v['notu']) ?></textarea></div>
            <label class="onay"><input type="checkbox" name="onay" value="1"> İletişim ve demo talebimin değerlendirilmesi için paylaştığım bilgilerin işlenmesini kabul ediyorum.</label>
            <button type="submit" class="btn">Demo Talebimi Gönder &nbsp;→</button>
        </form>
        <?php endif; ?>
    </div>
</div></section>
</body>
</html>
