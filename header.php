<?php
// Panel içinde (Başvurular sekmesi) açıldığında portalın kendi yan menüsü ve üst çubuğu
// gizlenir; panelin menüsü zaten üstte. Portal kendi adresinden açıldığında hiçbir şey değişmez.
$gomulu = !empty($_SESSION['panelden']);

if (!isset($_SESSION['kullanici_id'])) {
    require_once __DIR__ . '/auth_helpers.php';
    $k_cookie = hatirla_cookie_kontrol($db);
    if ($k_cookie) {
        session_regenerate_id(true);
        $_SESSION['kullanici_id'] = $k_cookie['id'];
        $_SESSION['ad_soyad']     = $k_cookie['ad_soyad'];
        $_SESSION['rol']          = $k_cookie['rol'];
        $_SESSION['giris_zamani'] = time();
        hatirla_cookie_set($db, $k_cookie['id']); // 30 günü yenile
    } else {
        header("Location: index.php");
        exit;
    }
}

// "Herkesi At" sonrası: oturum bu işlemden önce açılmışsa zorla çıkış yap
try {
    $zorla_zaman = $db->query("SELECT deger FROM sistem_ayarlari WHERE anahtar='zorla_cikis_zamani'")->fetchColumn();
    if ($zorla_zaman && ($_SESSION['giris_zamani'] ?? 0) < strtotime($zorla_zaman)) {
        require_once __DIR__ . '/auth_helpers.php';
        hatirla_cookie_clear($db, (int)$_SESSION['kullanici_id']);
        session_unset();
        session_destroy();
        header("Location: index.php?atildiniz=1");
        exit;
    }
} catch (Exception $e) {}

$rol      = $_SESSION['rol'];
$ad_soyad = $_SESSION['ad_soyad'];
$sayfa    = basename($_SERVER['PHP_SELF'], '.php');

// Bekleyen demo talebi sayısı (menü rozeti) — tablo demo.php ilk çağrılana kadar olmayabilir
$demo_yeni = 0; $basvuru_yeni = 0;
if ($rol === 'admin' || $rol === 'yonetici') {
    try { $demo_yeni = (int)$db->query("SELECT COUNT(*) FROM demo_talepleri WHERE durum='yeni'")->fetchColumn(); } catch (Exception $e) {}
    try { $basvuru_yeni = (int)$db->query("SELECT COUNT(*) FROM basvurular WHERE durum='yeni'")->fetchColumn(); } catch (Exception $e) {}
}

// Fiş okuma (muhasebe/firma rolleri) 2026-09-23'te fis.odemeix.net.tr projesine ayrıldı.

// Ekip mesajlaşması 2026-09-23'te kaldırıldı (kullanıcı talebiyle).

// VAPID public key (Web Push için tarayıcıya gönderilir)
require_once __DIR__ . '/vapid.php';
try {
    $vapid_keys = vapid_keys($db);
    $vapid_public_key = $vapid_keys['public'];
} catch (Exception $e) {
    $vapid_public_key = '';
}

// Geçici duyuru özelliği 2026-09-23'te kaldırıldı (kullanıcı talebiyle).
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-P3GQV5P79K"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-P3GQV5P79K');
</script>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($sayfa_basligi ?? 'Ödemeix Portal') ?></title>
    <script>
        const _t = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-bs-theme', _t);
    </script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        :root {
            --portal-blue: #9575cd;
            --portal-mid:  #5e35b1;
            --portal-dark: #4527a0;
        }

        [data-bs-theme="light"] body { background: #f3f0fa; }
        [data-bs-theme="dark"]  body { background: #0a0a0a; }

        [data-bs-theme="light"] .navbar {
            background: linear-gradient(135deg, var(--portal-dark) 0%, var(--portal-mid) 100%);
        }
        [data-bs-theme="dark"] .navbar {
            background: #0a0a0a;
            border-bottom: 1px solid #2a2a2a;
        }

        .nav-link { color: rgba(255,255,255,.78) !important; }
        .nav-link:hover, .nav-link.active { color: #fff !important; }

        .navbar-brand .brand-stack {
            line-height: 1.15;
        }
        .navbar-brand .brand-odemeix {
            color: #a855f7;
            letter-spacing: 3px;
            font-weight: 800;
            font-size: .78rem;
        }
        .navbar-brand .portal-name {
            color: var(--portal-blue);
            letter-spacing: 3px;
            font-weight: 800;
            font-size: .78rem;
        }
        .navbar-brand i { color: rgba(255,255,255,.85); }

        [data-bs-theme="light"] .card {
            border: none;
            border-radius: 14px;
            box-shadow: 0 2px 16px rgba(94,53,177,.10);
        }
        [data-bs-theme="dark"] .card {
            border: 1px solid #2a2a2a;
            border-radius: 14px;
            box-shadow: none;
        }

        /* İstatistik kartları */
        .stat-card {
            border-radius: 14px;
            padding: 1rem 1.2rem;
            display: flex;
            align-items: center;
            gap: .9rem;
        }
        .stat-card .stat-icon {
            width: 46px; height: 46px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem; flex-shrink: 0;
        }
        .stat-card .stat-val { font-size: 1.4rem; font-weight: 800; line-height: 1; }
        .stat-card .stat-lbl { font-size: .72rem; opacity: .7; margin-top: 2px; }

        /* Açık mavi primary */
        .btn-primary {
            background: linear-gradient(135deg, var(--portal-dark), var(--portal-mid)) !important;
            border-color: var(--portal-mid) !important;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, var(--portal-mid), var(--portal-blue)) !important;
            border-color: var(--portal-blue) !important;
            opacity: .95;
        }
        .btn-outline-primary {
            color: var(--portal-mid) !important;
            border-color: var(--portal-mid) !important;
        }
        .btn-outline-primary:hover {
            background: var(--portal-mid) !important;
            color: #fff !important;
        }
        .text-primary  { color: var(--portal-mid) !important; }
        .badge.bg-primary { background: var(--portal-mid) !important; }
        a { color: var(--portal-mid); }

        /* Dropdown taşma engeli */
        .dropdown-menu { max-height: min(80vh, 380px); overflow-y: auto; }

        /* ── Mobil & Responsive ───────────────────────────────────────── */

        /* Tablo kaydırma izi */
        .table-responsive { position: relative; }
        @media (max-width: 767px) {
            .table-responsive::after {
                content: '';
                position: absolute; top: 0; right: 0;
                width: 18px; height: 100%;
                background: linear-gradient(to right, transparent, rgba(0,0,0,.05));
                pointer-events: none;
            }
        }

        /* Sol yan menü — Sigortaix menüsüyle aynı görünüm (2026-09-23, kullanıcı talebiyle
           "bunun gibi olsun"): tam boy siyah menü, logo alanı, grup başlıkları, mor sol çizgili
           aktif öğe, sağda kırmızı rozet, altta kullanıcı bloğu; menü kenarında mor kare
           hamburger. Masaüstünde açık başlar ve içeriği sağa iter (tercih hatırlanır), telefonda
           kapalı başlar ve içeriğin üstüne açılır. */
        body { margin-left: 250px; transition: margin-left .2s; }
        body.menu-kapali { margin-left: 0; }
        /* Panel içinde: yan menü ve üst çubuk yok, içerik tam genişlik. */
        body.gomulu { margin-left: 0 !important; background: #fff; }
        body.gomulu .ana-icerik { padding-top: 16px; }
        .navbar { position: sticky; top: 0; z-index: 1030; }
        .navbar-right-btns { display: flex; align-items: center; gap: .5rem; margin-left: auto; }
        @media (max-width: 575px) { .navbar-right-btns .kullanici-adi { display: none; } }
        .sx-sidebar {
            position: fixed; top: 0; left: 0; bottom: 0; width: 250px; z-index: 1040;
            background: #0a0a0a; color: #cbd5e1; display: flex; flex-direction: column;
            overflow-y: auto; transition: transform .2s;
        }
        body.menu-kapali .sx-sidebar { transform: translateX(-250px); }
        .sx-sidebar .sx-brand { padding: 18px 20px; display: flex; align-items: center; gap: 12px; border-bottom: 1px solid rgba(255,255,255,.08); text-decoration: none; }
        .sx-sidebar .sx-brand .sx-logo { width: 36px; height: 36px; border-radius: 9px; background: linear-gradient(135deg, #7c3aed, #a855f7); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.15rem; flex-shrink: 0; }
        .sx-sidebar .sx-brand span { color: #fff; font-weight: 700; font-size: 1.05rem; line-height: 1.15; }
        .sx-sidebar .sx-brand small { display: block; color: #94a3b8; font-weight: 500; font-size: .72rem; letter-spacing: .06em; }
        .sx-group-title { font-size: .68rem; letter-spacing: .08em; text-transform: uppercase; color: #64748b; padding: 16px 20px 6px; }
        .sx-sidebar a.sx-link {
            display: flex; align-items: center; gap: 10px; padding: 9px 20px; color: #cbd5e1;
            text-decoration: none; font-size: .88rem; border-left: 3px solid transparent;
        }
        .sx-sidebar a.sx-link i { width: 18px; text-align: center; opacity: .85; }
        .sx-sidebar a.sx-link:hover { background: rgba(124,58,237,.15); color: #fff; }
        .sx-sidebar a.sx-link.active { background: rgba(124,58,237,.25); color: #fff; border-left-color: #a855f7; font-weight: 600; }
        .sx-sidebar .sx-badge { margin-left: auto; background: #ef4444; color: #fff; font-size: .68rem; border-radius: 999px; padding: 1px 7px; }
        .sx-sidebar .sx-ok { margin-left: auto; font-size: .7rem; opacity: .7; transition: transform .2s; width: auto; }
        .sx-sidebar a.sx-toggle.acik { color: #fff; }
        .sx-sidebar a.sx-toggle.acik .sx-ok, .sx-sidebar a.sx-ana.acik .sx-ok { transform: rotate(180deg); }
        .sx-sidebar a.sx-ana .sx-ok { padding: 4px 6px; border-radius: 6px; }
        .sx-sidebar a.sx-ana .sx-ok:hover { background: rgba(255,255,255,.15); opacity: 1; }
        .sx-sidebar .sx-alt { display: none; background: rgba(255,255,255,.03); }
        .sx-sidebar .sx-alt.acik { display: block; }
        .sx-sidebar .sx-alt a.sx-link { padding-left: 46px; font-size: .84rem; }
        .sx-sidebar .sx-user { margin-top: auto; padding: 14px 20px; border-top: 1px solid rgba(255,255,255,.08); font-size: .82rem; }
        .sx-toggle-btn {
            position: fixed; top: 12px; left: 262px; z-index: 1050; background: #7c3aed; color: #fff;
            border: none; width: 32px; height: 32px; border-radius: 8px; transition: left .2s;
            display: flex; align-items: center; justify-content: center; font-size: 1.1rem;
        }
        .sx-toggle-btn:hover { background: #6d28d9; color: #fff; }
        body.menu-kapali .sx-toggle-btn { left: 12px; }
        .navbar .container-fluid { padding-left: 56px !important; }
        @media (max-width: 768px) {
            body { margin-left: 0; }
            .sx-sidebar { transform: translateX(-250px); box-shadow: 4px 0 18px rgba(0,0,0,.35); }
            .sx-toggle-btn { left: 12px; }
            body.menu-acik-mobil .sx-sidebar { transform: translateX(0); }
        }
        /* Container padding — dar ekran */
        @media (max-width: 575px) {
            .container-fluid.px-4 { padding-left: .5rem !important; padding-right: .5rem !important; }
            nav.navbar .container-fluid { padding-left: .75rem !important; padding-right: .75rem !important; }
        }

        /* Stat kartları — mobil */
        @media (max-width: 575px) {
            .stat-card { padding: .6rem .75rem; gap: .6rem; }
            .stat-card .stat-icon { width: 36px; height: 36px; font-size: 1rem; border-radius: 9px; }
            .stat-card .stat-val  { font-size: 1.1rem; }
            .stat-card .stat-lbl  { font-size: .63rem; }
        }

        /* Kart padding — mobil */
        @media (max-width: 575px) {
            .card.p-3, .card.p-md-4 { padding: .6rem !important; }
        }

        /* Offcanvas tam ekran — mobil */
        @media (max-width: 575px) {
            .offcanvas { width: 100% !important; max-width: 100% !important; }
        }

        /* Müşteri tablosu — mobil */
        @media (max-width: 767px) {
            #musteriTablosu th,
            #musteriTablosu td { padding: .35rem .4rem; font-size: .82rem; }
            #musteriTablosu .badge { font-size: .63rem; padding: .2em .45em; }
        }

        /* Teslim edildi satırı — tüm satır yeşile döner (2026-08-31, kullanıcı talebiyle) */
        [data-bs-theme="light"] #musteriTablosu tr.teslim-edildi-satir > td { background-color: #d1f7dd; }
        [data-bs-theme="dark"]  #musteriTablosu tr.teslim-edildi-satir > td { background-color: #123a24; }

        /* Aşama çizelgesi — Durum + Teslimat Süreci birleşik ilerleme noktaları
           (2026-08-31, kullanıcı talebiyle: "değiştirebilir yuvarlak kutular ... ne kadar
           tamamlandığını görmek için"). GÖRSEL YENİDEN TASARIM (2026-09-01, kullanıcının
           verdiği "Başvuru Durumu" referans görseline göre): büyük tikli daireler, dikey
           istifte kalın etiket + durum metni, bağlantı çizgisi ve bir "%X tamamlandı"
           rozeti. 11 aşama tek satırda dar ekrana sığmayabilir — sarmak (wrap) yerine
           yatay kaydırma (overflow-x) kullanıyoruz ki referanstaki gibi TEK sıra kalsın. */
        .asama-satiri > td { padding-top: 0 !important; border-top: none !important; }
        .asama-kart {
            position: relative;
            border-radius: 8px;
            padding: .4rem .5rem .35rem;
        }
        [data-bs-theme="light"] .asama-kart { background: #f7fafd; border: 1px solid #e3eaf2; }
        [data-bs-theme="dark"]  .asama-kart { background: #131313; border: 1px solid #2a2a2a; }
        .asama-yuzde-rozet {
            position: absolute;
            top: .35rem; right: .45rem;
            font-size: .56rem; font-weight: 700;
            padding: .14rem .42rem;
            border-radius: 20px;
        }
        [data-bs-theme="light"] .asama-yuzde-rozet { background: #e3f9ec; color: #16a34a; }
        [data-bs-theme="dark"]  .asama-yuzde-rozet { background: #0f2e1c; color: #4ade80; }
        .asama-cizelgesi {
            display: flex;
            align-items: flex-start;
            overflow-x: auto;
            padding: .1rem 2.8rem .1rem 0;
        }
        .asama-adim-sarmal {
            display: flex;
            flex-direction: column;
            align-items: center;
            width: 46px;
            flex-shrink: 0;
        }
        .asama-adim {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: .15rem;
            width: 46px;
            flex-shrink: 0;
            text-decoration: none;
            cursor: pointer;
        }
        span.asama-adim { cursor: default; }
        /* Müşteriye özel işaret rozeti (2026-09-01) — Durum/Teslimat rozetleriyle aynı
           "alta açılan buton" deseni (kullanıcı isteği). durum/teslimat_sureci'yi
           DEĞİŞTİRMEZ, sadece bu tek müşteride o noktayı işaretler. */
        .asama-isaretle-rozet {
            display: inline-block;
            min-width: 12px;
            height: 12px;
            margin-top: .25rem;
            padding: 0;
            border-radius: 4px;
            cursor: pointer;
        }
        span.asama-isaretle-rozet:not(.dropdown-toggle) { cursor: default; }
        .asama-nokta {
            width: 18px; height: 18px;
            min-width: 18px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            transition: transform .12s ease;
        }
        a.asama-adim:hover .asama-nokta { transform: scale(1.08); }
        .asama-etiket { font-size: .54rem; font-weight: 700; text-align: center; line-height: 1.1; }
        .asama-durum-metni { font-size: .48rem; font-weight: 600; }
        .asama-cizgi { height: 2px; flex: 1 0 8px; margin-top: 8px; min-width: 8px; }

        [data-bs-theme="light"] .asama-nokta,
        [data-bs-theme="light"] .asama-cizgi { background: #dde5ee; }
        [data-bs-theme="dark"]  .asama-nokta,
        [data-bs-theme="dark"]  .asama-cizgi { background: #2a2a2a; }
        [data-bs-theme="light"] .asama-etiket { color: #1c2b3f; }
        [data-bs-theme="dark"]  .asama-etiket { color: #dce6f2; }
        [data-bs-theme="light"] .asama-adim.bekliyor .asama-etiket { color: #9aa9bb; }
        [data-bs-theme="dark"]  .asama-adim.bekliyor .asama-etiket { color: #5b6d87; }

        .asama-adim.tamam .asama-nokta { background: var(--portal-mid); color: #fff; }
        [data-bs-theme="dark"] .asama-adim.tamam .asama-nokta { background: var(--portal-blue); color: #050505; }
        .asama-cizgi.tamam { background: linear-gradient(90deg, var(--portal-mid), #22c55e); }
        [data-bs-theme="dark"] .asama-cizgi.tamam { background: linear-gradient(90deg, var(--portal-blue), #22c55e); }

        .asama-adim.aktif .asama-nokta { background: #22c55e; box-shadow: 0 0 0 2px rgba(34,197,94,.22); }
        .asama-adim.aktif .asama-ic-nokta { width: 5px; height: 5px; border-radius: 50%; background: #fff; }
        .asama-nokta svg { width: 9px; height: 9px; }
        .asama-adim.aktif .asama-durum-metni { color: #22c55e; }
        .asama-adim.tamam .asama-durum-metni { color: var(--portal-mid); }
        [data-bs-theme="dark"] .asama-adim.tamam .asama-durum-metni { color: var(--portal-blue); }

        /* İşlem sütunu buton metni — küçük ekranda sadece ikon */
        @media (max-width: 575px) {
            .btn-islem-text { display: none; }
        }

        /* Tüm modallarda scroll fix */
        .modal-body { overscroll-behavior: contain; }
    </style>
</head>
<body<?= $gomulu ? ' class="gomulu"' : '' ?>>
<script>
// Menü durumunu ilk boyamadan önce uygula (masaüstü: hatırlanan tercih, yoksa açık; telefon: kapalı)
(function(){ try {
    if (window.innerWidth > 768 && localStorage.getItem('yanMenu') === 'kapali') document.body.classList.add('menu-kapali');
} catch(e){} })();
</script>
<?php if (!$gomulu): ?>
<button class="sx-toggle-btn" id="menuToggle" type="button" title="Menüyü Aç/Kapat" aria-label="Menüyü aç/kapat"><i class="bi bi-list"></i></button>

<nav class="sx-sidebar" id="yanMenu" aria-label="Ana menü">
    <a class="sx-brand" href="dashboard.php">
        <span class="sx-logo"><i class="bi bi-headset"></i></span>
        <span>Ödemeix<small>PORTAL</small></span>
    </a>

    <div class="sx-group-title">Ana Menü</div>

                <?php
                    // Müşteriler ana sekme: tıklayınca müşteri listesi açılır, altında Demo Talepleri ve
                    // Başvurular alt sekmeleri (2026-09-24, kullanıcı talebiyle). Ok simgesi yalnız açıp kapatır.
                    $musteri_grubu_acik = in_array($sayfa, ['dashboard', 'demo_talepleri', 'basvurular'], true);
                    $yonetim_mi = ($rol === 'admin' || $rol === 'yonetici');
                ?>
                <a class="sx-link sx-ana <?= $sayfa === 'dashboard' ? 'active' : '' ?> <?= $musteri_grubu_acik ? 'acik' : '' ?>" href="dashboard.php" data-hedef="sxMusteriler">
                        <i class="bi bi-people-fill"></i> Müşteriler
                        <?php if ($yonetim_mi): ?><i class="bi bi-chevron-down sx-ok" title="Alt sekmeleri aç/kapat"></i><?php endif; ?>
                    </a>
                <?php if ($yonetim_mi): ?>
                <div class="sx-alt <?= $musteri_grubu_acik ? 'acik' : '' ?>" id="sxMusteriler">
                    <a class="sx-link <?= $sayfa === 'demo_talepleri' ? 'active' : '' ?>" href="demo_talepleri.php">
                        <i class="bi bi-rocket-takeoff"></i> Demo Talepleri
                        <?php if ($demo_yeni > 0): ?><span class="sx-badge"><?= $demo_yeni ?></span><?php endif; ?>
                    </a>
                    <a class="sx-link <?= $sayfa === 'basvurular' ? 'active' : '' ?>" href="basvurular.php">
                        <i class="bi bi-file-earmark-text"></i> Başvurular
                        <?php if ($basvuru_yeni > 0): ?><span class="sx-badge"><?= $basvuru_yeni ?></span><?php endif; ?>
                    </a>
                </div>
                <?php endif; ?>
                <?php if ($rol === 'admin' || $rol === 'yonetici'):
                    // Ayarlar açılır grup (2026-09-23, kullanıcı talebiyle: "Ayarlar'a dokununca
                    // Seçenekler alt sekmede açılsın"). Seçenekler sayfasındayken açık gelir.
                    $ayar_acik = in_array($sayfa, ['secenekler', 'ayarlar', 'basvuru_alanlari'], true);
                ?>
                <div class="sx-group-title">Yönetim</div>
                <a class="sx-link sx-toggle <?= $ayar_acik ? 'acik' : '' ?>" href="#" data-hedef="sxAyarlar" aria-expanded="<?= $ayar_acik ? 'true' : 'false' ?>">
                        <i class="bi bi-gear-fill"></i> Ayarlar <i class="bi bi-chevron-down sx-ok"></i>
                    </a>
                <div class="sx-alt <?= $ayar_acik ? 'acik' : '' ?>" id="sxAyarlar">
                    <a class="sx-link <?= $sayfa === 'secenekler' ? 'active' : '' ?>" href="secenekler.php"><i class="bi bi-sliders"></i> Seçenekler</a>
                    <a class="sx-link <?= $sayfa === 'basvuru_alanlari' ? 'active' : '' ?>" href="basvuru_alanlari.php"><i class="bi bi-list-check"></i> Başvuru Alanları</a>
                </div>
                <?php endif; ?>
            
    <div class="sx-user">
        <div class="fw-bold text-white"><?= htmlspecialchars($ad_soyad) ?></div>
        <div class="text-secondary"><?= htmlspecialchars(ucfirst($rol)) ?></div>
        <a href="logout.php" class="d-block mt-2 text-danger text-decoration-none"><i class="bi bi-box-arrow-right"></i> Çıkış Yap</a>
    </div>
</nav>

<nav class="navbar navbar-dark mb-2 mb-md-4">
    <div class="container-fluid px-3 px-md-4 flex-nowrap">
        <div class="d-flex align-items-center gap-2 navbar-right-btns">
                <?php
                // Bekleyen temsilci istekleri (admin/yönetici için)
                $bekleyen_istek = 0;
                if ($rol === 'admin' || $rol === 'yonetici') {
                    try {
                        $bekleyen_istek = (int)$db->query("SELECT COUNT(*) FROM temsilci_istekleri WHERE durum='bekliyor'")->fetchColumn();
                    } catch (Exception $e) {}
                }
                ?>
                <?php if ($rol === 'admin' || $rol === 'yonetici'): ?>
                <a href="dashboard.php?istekler=1" class="btn btn-sm btn-outline-light border-0 px-2 position-relative" title="Temsilci İstekleri">
                    <i class="bi bi-bell-fill"></i>
                    <?php if ($bekleyen_istek > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem;">
                            <?= $bekleyen_istek ?>
                        </span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                <button id="themeToggle" class="btn btn-sm btn-outline-light border-0 px-2" title="Tema Değiştir">
                    <i class="bi bi-moon-fill" id="themeIcon"></i>
                </button>
                <span class="text-white-50 small kullanici-adi">
                    <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($ad_soyad) ?>
                    <span class="badge bg-warning text-dark ms-1"><?= strtoupper($rol) ?></span>
                </span>
                <a href="logout.php" class="btn btn-danger btn-sm px-3">
                    <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
    </div>
</nav>
<?php endif; ?>
<div class="container-fluid px-4 ana-icerik">


<script>
// ── Yan menü ─────────────────────────────────────────────────────────────────
(function () {
    const mobil = () => window.innerWidth <= 768;
    document.getElementById('menuToggle')?.addEventListener('click', () => {
        if (mobil()) {
            document.body.classList.toggle('menu-acik-mobil');
        } else {
            document.body.classList.toggle('menu-kapali');
            try { localStorage.setItem('yanMenu', document.body.classList.contains('menu-kapali') ? 'kapali' : 'acik'); } catch (e) {}
        }
    });
    // Açılır gruplar (Ayarlar): tıklayınca alt öğeler açılır/kapanır
    document.querySelectorAll('#yanMenu .sx-toggle').forEach(t => t.addEventListener('click', e => {
        e.preventDefault();
        const hedef = document.getElementById(t.dataset.hedef);
        const acik = t.classList.toggle('acik');
        if (hedef) hedef.classList.toggle('acik', acik);
        t.setAttribute('aria-expanded', acik ? 'true' : 'false');
    }));
    // Ana sekmedeki ok: sayfaya gitmeden alt sekmeleri aç/kapat
    document.querySelectorAll('#yanMenu .sx-ana .sx-ok').forEach(ok => ok.addEventListener('click', e => {
        e.preventDefault(); e.stopPropagation();
        const a = ok.closest('.sx-ana'); const hedef = document.getElementById(a.dataset.hedef);
        const acik = a.classList.toggle('acik'); if (hedef) hedef.classList.toggle('acik', acik);
    }));
    // Telefonda bir bağlantıya tıklayınca menü kapansın
    document.querySelectorAll('#yanMenu .sx-link:not(.sx-toggle)').forEach(a => a.addEventListener('click', () => { if (mobil()) document.body.classList.remove('menu-acik-mobil'); }));
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && mobil()) document.body.classList.remove('menu-acik-mobil'); });
})();

// ── Tema ──────────────────────────────────────────────────────────────────────
(function () {
    function applyIcon(theme) {
        const icon = document.getElementById('themeIcon');
        if (!icon) return;
        icon.className = theme === 'dark' ? 'bi bi-sun-fill' : 'bi bi-moon-fill';
    }
    applyIcon(localStorage.getItem('theme') || 'light');
    document.getElementById('themeToggle').addEventListener('click', function () {
        const html = document.documentElement;
        const next = html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-bs-theme', next);
        localStorage.setItem('theme', next);
        applyIcon(next);
    });
})();

// ── Service Worker + Web Push Bildirimleri ───────────────────────────────────
(function () {
    const VAPID_PUB = '<?= htmlspecialchars($vapid_public_key, ENT_QUOTES) ?>';
    if (!VAPID_PUB || !('serviceWorker' in navigator) || !('PushManager' in window)) return;

    function urlb64ToUint8(b64) {
        const pad  = '='.repeat((4 - b64.length % 4) % 4);
        const raw  = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
    }

    async function aboneOl(reg) {
        let sub = await reg.pushManager.getSubscription();

        if (!sub) {
            const perm = await Notification.requestPermission();
            if (perm !== 'granted') return;

            sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlb64ToUint8(VAPID_PUB)
            });
        }

        // Mevcut abonelik de olsa, sunucuya tekrar kaydet —
        // aynı tarayıcıda farklı kullanıcı girişi yapıldığında abonelik o kullanıcıya yeniden bağlanır.
        await fetch('push_subscribe.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(sub.toJSON())
        });
    }

    navigator.serviceWorker.register('/sw.js', { scope: '/' })
        .then(function (reg) {
            // SW güncelleme kontrolü
            reg.update();
            aboneOl(reg).catch(() => {});
        })
        .catch(function () {});

})();
</script>
