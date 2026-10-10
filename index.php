<?php
session_start();
require __DIR__ . '/config.php';
require __DIR__ . '/totp.php';
require __DIR__ . '/auth_helpers.php';
require __DIR__ . '/sms_helper.php';
require __DIR__ . '/mail_helper.php';

// Sütunları garantile
$db->exec("ALTER TABLE kullanicilar ADD COLUMN IF NOT EXISTS hatirla_token   VARCHAR(64)  DEFAULT NULL");
$db->exec("ALTER TABLE kullanicilar ADD COLUMN IF NOT EXISTS hatirla_expires TIMESTAMP    DEFAULT NULL");

// Zaten oturumu açık
if (isset($_SESSION['kullanici_id'])) {
    header("Location: dashboard.php");
    exit;
}

// Cookie ile otomatik giriş
$k_cookie = hatirla_cookie_kontrol($db);
if ($k_cookie) {
    session_regenerate_id(true);
    $_SESSION['kullanici_id'] = $k_cookie['id'];
    $_SESSION['ad_soyad']     = $k_cookie['ad_soyad'];
    $_SESSION['rol']          = $k_cookie['rol'];
    $_SESSION['giris_zamani'] = time();
    hatirla_cookie_set($db, $k_cookie['id']); // 30 günü yenile
    header("Location: dashboard.php");
    exit;
}

if (isset($_GET['iptal'])) {
    unset($_SESSION['totp_pending_id'], $_SESSION['mesaj_pending_id'], $_SESSION['mesaj_kanal'],
          $_SESSION['mesaj_otp_id'], $_SESSION['mesaj_kod'], $_SESSION['mesaj_kod_son'],
          $_SESSION['mesaj_hedef'], $_SESSION['hatirla_istendi'],
          $_SESSION['kanal_secim_pending_id'], $_SESSION['kanal_secim_listesi']);
    header("Location: index.php");
    exit;
}

$hata                 = isset($_GET['fis']) ? 'Bu hesap Ödemeix Fiş uygulamasına taşındı. Lütfen fiş uygulamasından giriş yapın.' : '';
$adim                 = 'giris';
$totp_user            = '';
$mesaj_hedef          = '';
$mesaj_kanal_aktif    = '';
$kanal_secenekleri_login = [];

if (isset($_SESSION['totp_pending_id'])) {
    $adim = 'totp';
    $stmt = $db->prepare("SELECT kullanici_adi FROM kullanicilar WHERE id = ?");
    $stmt->execute([$_SESSION['totp_pending_id']]);
    $totp_user = $stmt->fetchColumn() ?: '';
} elseif (isset($_SESSION['mesaj_pending_id'])) {
    $adim              = 'mesaj_kod';
    $mesaj_hedef       = $_SESSION['mesaj_hedef'] ?? '';
    $mesaj_kanal_aktif = $_SESSION['mesaj_kanal']  ?? 'email';
} elseif (isset($_SESSION['kanal_secim_pending_id'])) {
    $adim                    = 'kanal_sec';
    $kanal_secenekleri_login = $_SESSION['kanal_secim_listesi'] ?? [];
}

// Pending kullanıcı için seçilen kanaldan kod gönder + oturum durumunu ayarla
function index_kod_gonder_ve_ayarla(PDO $db, array $k, string $kanal): array {
    $sonuc = dogrulama_kodu_gonder($db, $k, $kanal);
    if ($sonuc['ok']) {
        $_SESSION['mesaj_pending_id'] = $k['id'];
        $_SESSION['mesaj_kanal']      = $kanal;
        $_SESSION['mesaj_hedef']      = $sonuc['hedef'];
        if (!empty($sonuc['kod']))    $_SESSION['mesaj_kod']     = $sonuc['kod'];
        if (!empty($sonuc['kod']))    $_SESSION['mesaj_kod_son'] = time() + 600;
        if (!empty($sonuc['otp_id'])) $_SESSION['mesaj_otp_id']  = $sonuc['otp_id'];
    }
    return $sonuc;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* ── Adım 2a: Mesajla gönderilen doğrulama kodu (WA/SMS/E-posta) ── */
    if (isset($_POST['mesaj_kodu'])) {
        $pending_id = $_SESSION['mesaj_pending_id'] ?? null;
        if (!$pending_id) { header("Location: index.php"); exit; }
        $girilen = preg_replace('/\D/', '', trim($_POST['mesaj_kodu']));
        $kanal_k = $_SESSION['mesaj_kanal'] ?? 'email';
        $gecerli = false;

        if ($kanal_k === 'sms' && !empty($_SESSION['mesaj_otp_id'])) {
            $gecerli = otp_dogrula($db, $_SESSION['mesaj_otp_id'], $girilen);
        } else {
            $gecerli = !empty($_SESSION['mesaj_kod']) && $girilen === $_SESSION['mesaj_kod']
                       && time() < ($_SESSION['mesaj_kod_son'] ?? 0);
        }

        if ($gecerli) {
            $stmt = $db->prepare("SELECT * FROM kullanicilar WHERE id = ?");
            $stmt->execute([$pending_id]);
            $k       = $stmt->fetch();
            $hatirla = !empty($_SESSION['hatirla_istendi']);
            unset($_SESSION['mesaj_pending_id'], $_SESSION['mesaj_kanal'], $_SESSION['mesaj_otp_id'],
                  $_SESSION['mesaj_kod'], $_SESSION['mesaj_kod_son'], $_SESSION['mesaj_hedef'], $_SESSION['hatirla_istendi']);
            session_regenerate_id(true);
            $_SESSION['kullanici_id'] = $k['id'];
            $_SESSION['ad_soyad']     = $k['ad_soyad'];
            $_SESSION['rol']          = $k['rol'];
            $_SESSION['giris_zamani'] = time();
            if ($hatirla) hatirla_cookie_set($db, $k['id']);
            header("Location: dashboard.php");
            exit;
        }
        $hata              = "Hatalı doğrulama kodu!";
        $adim              = 'mesaj_kod';
        $mesaj_hedef       = $_SESSION['mesaj_hedef'] ?? '';
        $mesaj_kanal_aktif = $kanal_k;

    /* ── Adım 2b: TOTP kodu ── */
    } elseif (isset($_POST['totp_kodu'])) {
        $pending_id = $_SESSION['totp_pending_id'] ?? null;
        if (!$pending_id) { header("Location: index.php"); exit; }
        $stmt = $db->prepare("SELECT * FROM kullanicilar WHERE id = ?");
        $stmt->execute([$pending_id]);
        $k = $stmt->fetch();
        if ($k && totp_verify($k['totp_secret'], trim($_POST['totp_kodu']))) {
            $hatirla = !empty($_SESSION['hatirla_istendi']);
            unset($_SESSION['totp_pending_id'], $_SESSION['hatirla_istendi']);
            session_regenerate_id(true);
            $_SESSION['kullanici_id'] = $k['id'];
            $_SESSION['ad_soyad']     = $k['ad_soyad'];
            $_SESSION['rol']          = $k['rol'];
            $_SESSION['giris_zamani'] = time();
            if ($hatirla) hatirla_cookie_set($db, $k['id']);
            header("Location: dashboard.php");
            exit;
        }
        $hata      = "Hatalı doğrulama kodu!";
        $adim      = 'totp';
        $totp_user = $k['kullanici_adi'] ?? '';

    /* ── Adım 2b-alt: TOTP yerine farklı yöntemle giriş ── */
    } elseif (isset($_POST['farkli_yontem'])) {
        $pending_id = $_SESSION['totp_pending_id'] ?? null;
        if (!$pending_id) { header("Location: index.php"); exit; }
        $stmt = $db->prepare("SELECT * FROM kullanicilar WHERE id = ?");
        $stmt->execute([$pending_id]);
        $k        = $stmt->fetch();
        $kanallar = dogrulama_kanallari($db, $k);

        if (count($kanallar) === 0) {
            $hata      = "Alternatif doğrulama için hesabınızda telefon veya e-posta tanımlı değil.";
            $adim      = 'totp';
            $totp_user = $k['kullanici_adi'] ?? '';
        } elseif (count($kanallar) === 1) {
            $sonuc = index_kod_gonder_ve_ayarla($db, $k, $kanallar[0]);
            if ($sonuc['ok']) {
                unset($_SESSION['totp_pending_id']);
                $adim              = 'mesaj_kod';
                $mesaj_hedef       = $sonuc['hedef'];
                $mesaj_kanal_aktif = $kanallar[0];
            } else {
                $hata      = "Kod gönderilemedi, lütfen doğrulama uygulamanızı kullanın.";
                $adim      = 'totp';
                $totp_user = $k['kullanici_adi'] ?? '';
            }
        } else {
            unset($_SESSION['totp_pending_id']);
            $_SESSION['kanal_secim_pending_id'] = $k['id'];
            $_SESSION['kanal_secim_listesi']    = $kanallar;
            $adim                    = 'kanal_sec';
            $kanal_secenekleri_login = $kanallar;
        }

    /* ── Adım 2c: Kanal seçimi (birden fazla yöntem mevcutsa) ── */
    } elseif (isset($_POST['kanal_secildi'])) {
        $pending_id = $_SESSION['kanal_secim_pending_id'] ?? null;
        $izinli     = $_SESSION['kanal_secim_listesi']    ?? [];
        $secilen    = $_POST['kanal_secildi'];
        if (!$pending_id || !in_array($secilen, $izinli, true)) { header("Location: index.php"); exit; }

        $stmt = $db->prepare("SELECT * FROM kullanicilar WHERE id = ?");
        $stmt->execute([$pending_id]);
        $k = $stmt->fetch();

        $sonuc = index_kod_gonder_ve_ayarla($db, $k, $secilen);
        if ($sonuc['ok']) {
            unset($_SESSION['kanal_secim_pending_id'], $_SESSION['kanal_secim_listesi']);
            $adim              = 'mesaj_kod';
            $mesaj_hedef       = $sonuc['hedef'];
            $mesaj_kanal_aktif = $secilen;
        } else {
            $hata                    = "Kod gönderilemedi, lütfen başka bir yöntem deneyin.";
            $adim                    = 'kanal_sec';
            $kanal_secenekleri_login = $izinli;
        }

    /* ── Adım 1: Kullanıcı adı + şifre ── */
    } else {
        $kadi    = trim($_POST['kullanici_adi'] ?? '');
        $sifre   = trim($_POST['sifre'] ?? '');
        $hatirla = !empty($_POST['hatirla']);
        $stmt = $db->prepare("SELECT * FROM kullanicilar WHERE kullanici_adi = ?");
        $stmt->execute([$kadi]);
        $k = $stmt->fetch();
        if ($k && password_verify($sifre, $k['sifre'])) {
            if (!empty($k['totp_enabled'])) {
                // TOTP (authenticator app) admin tarafından bu kullanıcı için açılmışsa istenir.
                session_regenerate_id(true);
                $_SESSION['totp_pending_id']  = $k['id'];
                $_SESSION['hatirla_istendi']  = $hatirla;
                $adim      = 'totp';
                $totp_user = $k['kullanici_adi'];
            } elseif ($k['rol'] === 'muhasebe' || $k['rol'] === 'firma') {
                // Fiş okuma 2026-09-23'te fis.odemeix.net.tr'ye ayrıldı; bu roller portala giremez.
                $hata = 'Bu hesap Ödemeix Fiş uygulamasına taşındı. Lütfen fiş uygulamasından giriş yapın.';
            } else {
                // SMS/WhatsApp/e-posta ile ikinci adım doğrulaması kaldırıldı — şifre yeterli.
                // İsteğe bağlı ek güvenlik için admin, Personel sayfasından kullanıcı bazlı TOTP açabilir.
                session_regenerate_id(true);
                $_SESSION['kullanici_id'] = $k['id'];
                $_SESSION['ad_soyad']     = $k['ad_soyad'];
                $_SESSION['rol']          = $k['rol'];
                $_SESSION['giris_zamani'] = time();
                if ($hatirla) hatirla_cookie_set($db, $k['id']);
                header("Location: dashboard.php");
                exit;
            }
        } else {
            $hata = "Hatalı kullanıcı adı veya şifre!";
        }
    }
}
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
    <title>Giriş — Ödemeix Portal</title>
    <script>
        const _t = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-bs-theme', _t);
    </script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        * { box-sizing: border-box; }
        body {
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            padding: 1rem; position: relative; overflow: hidden;
        }
        [data-bs-theme="light"] body { background: linear-gradient(135deg,#4527a0 0%,#5e35b1 55%,#9575cd 100%); }
        [data-bs-theme="dark"]  body { background: linear-gradient(135deg,#0a0a0a 0%,#0d0d0d 55%,#111111 100%); }
        body::before,body::after { content:'';position:fixed;border-radius:50%;opacity:.12;pointer-events:none; }
        body::before { width:500px;height:500px;background:#9575cd;top:-150px;right:-100px; }
        body::after  { width:350px;height:350px;background:#fff;bottom:-100px;left:-80px; }

        .login-wrap  { width:100%;max-width:420px;position:relative;z-index:1; }
        .login-card  { border:none;border-radius:20px;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.4); }
        [data-bs-theme="light"] .login-card { background:#fff; }
        [data-bs-theme="dark"]  .login-card { background:#131313; }

        .login-top {
            background: linear-gradient(135deg,#4527a0,#5e35b1);
            padding: 2.2rem 2rem 1.8rem; text-align:center;
        }
        .login-icon {
            width:64px;height:64px;background:rgba(255,255,255,.15);border-radius:50%;
            display:inline-flex;align-items:center;justify-content:center;
            font-size:1.8rem;color:#fff;margin-bottom:.8rem;backdrop-filter:blur(4px);
        }
        .login-odemeix  { color:#a855f7;font-size:1.4rem;font-weight:800;letter-spacing:6px;text-transform:uppercase;line-height:1; }
        .login-portal   { color:#9575cd;font-size:1.4rem;font-weight:800;letter-spacing:6px;text-transform:uppercase;line-height:1; }
        .login-subtitle { color:rgba(255,255,255,.65);font-size:.82rem;margin-top:.3rem;letter-spacing:1px; }
        .login-body     { padding:2rem; }

        .login-body .form-control { border-radius:10px;padding:.65rem 1rem;font-size:.92rem; }
        .login-body .input-group-text { border-radius:10px 0 0 10px;background:transparent;color:var(--bs-secondary); }
        .login-body .input-group .form-control { border-left:none;border-radius:0 10px 10px 0; }

        .btn-login {
            border-radius:10px;padding:.7rem;font-weight:700;font-size:.95rem;
            background:linear-gradient(135deg,#4527a0,#5e35b1);border:none;
            letter-spacing:.5px;transition:.2s;
        }
        .btn-login:hover { opacity:.9;transform:translateY(-1px); }

        .totp-grid { display:flex;gap:.5rem;justify-content:center; }
        .totp-grid input {
            width:48px;height:56px;text-align:center;font-size:1.5rem;font-weight:700;
            border-radius:10px;border:2px solid var(--bs-border-color);
            background:transparent;color:var(--bs-body-color);transition:border-color .15s;
        }
        .totp-grid input:focus { outline:none;border-color:#5e35b1;box-shadow:0 0 0 3px rgba(94,53,177,.2); }
        .totp-grid input.filled { border-color:#5e35b1; }
        .totp-hint { font-size:.8rem;text-align:center;color:var(--bs-secondary);margin-top:.5rem; }

        .theme-toggle { position:fixed;top:1rem;right:1rem;z-index:10; }

        @media(max-width:480px) {
            .login-wrap { max-width:100%; }
            .login-body { padding:1.5rem; }
            .totp-grid input { width:42px;height:50px;font-size:1.3rem; }
        }
    </style>
</head>
<body>

<button id="themeBtn" class="theme-toggle btn btn-sm btn-outline-light" style="opacity:.7;"></button>

<div class="login-wrap">
    <div class="login-card">

        <div class="login-top">
            <div class="login-icon">
                <?php if ($adim === 'totp'): ?>
                    <i class="bi bi-shield-lock-fill"></i>
                <?php elseif ($adim === 'mesaj_kod'): ?>
                    <i class="bi bi-<?= $mesaj_kanal_aktif==='email'?'envelope-fill':'phone-fill' ?>"></i>
                <?php elseif ($adim === 'kanal_sec'): ?>
                    <i class="bi bi-send-check-fill"></i>
                <?php else: ?>
                    <i class="bi bi-headset"></i>
                <?php endif; ?>
            </div>
            <div class="login-odemeix">ÖDEMEİX</div>
            <div class="login-portal">PORTAL</div>
            <div class="login-subtitle">
                <?php if ($adim === 'totp'): ?>İki Faktörlü Doğrulama
                <?php elseif ($adim === 'mesaj_kod'): ?>Doğrulama Kodu
                <?php elseif ($adim === 'kanal_sec'): ?>Doğrulama Yöntemi Seçin
                <?php else: ?>Müşteri Yönetim Sistemi
                <?php endif; ?>
            </div>
        </div>

        <div class="login-body">
            <?php if (!empty($_GET['sifre_degisti'])): ?>
                <div class="alert alert-success py-2 small text-center mb-3" style="border-radius:10px;">
                    <i class="bi bi-check-circle me-1"></i>Şifreniz başarıyla güncellendi. Giriş yapabilirsiniz.
                </div>
            <?php endif; ?>
            <?php if (!empty($_GET['atildiniz'])): ?>
                <div class="alert alert-warning py-2 small text-center mb-3" style="border-radius:10px;">
                    <i class="bi bi-door-open me-1"></i>Oturumunuz yönetici tarafından sonlandırıldı. Lütfen tekrar giriş yapın.
                </div>
            <?php endif; ?>
            <?php if ($hata): ?>
                <div class="alert alert-danger py-2 small text-center mb-3" style="border-radius:10px;">
                    <i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($hata) ?>
                </div>
            <?php endif; ?>

            <?php if ($adim === 'giris'): ?>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted mb-1">Kullanıcı Adı</label>
                    <div class="input-group">
                        <span class="input-group-text border-end-0"><i class="bi bi-person-fill"></i></span>
                        <input type="text" name="kullanici_adi" class="form-control"
                               placeholder="kullanıcı adınızı girin" required autocomplete="off"
                               value="<?= htmlspecialchars($_POST['kullanici_adi'] ?? '') ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted mb-1">Şifre</label>
                    <div class="input-group">
                        <span class="input-group-text border-end-0"><i class="bi bi-lock-fill"></i></span>
                        <input type="password" name="sifre" id="sifreInput" class="form-control" placeholder="••••••••" required>
                        <button type="button" class="btn btn-outline-secondary border-start-0"
                                style="border-radius:0 10px 10px 0;"
                                onclick="const i=document.getElementById('sifreInput');i.type=i.type==='password'?'text':'password';this.querySelector('i').className=i.type==='password'?'bi bi-eye':'bi bi-eye-slash';">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" name="hatirla" value="1" id="hatirlaCheck"
                               <?= !empty($_POST['hatirla']) ? 'checked' : '' ?>>
                        <label class="form-check-label small text-muted" for="hatirlaCheck">
                            Beni hatırla <span class="text-muted" style="font-size:.75rem;">(30 gün)</span>
                        </label>
                    </div>
                    <a href="sifre_sifirla.php" class="small text-muted" style="font-size:.8rem;">
                        <i class="bi bi-question-circle me-1"></i>Şifremi unuttum
                    </a>
                </div>
                <button type="submit" class="btn btn-login btn-primary w-100">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Giriş Yap
                </button>
            </form>

            <?php elseif ($adim === 'totp'): ?>
            <p class="text-center small text-muted mb-3">
                <i class="bi bi-person-circle me-1"></i>
                <strong><?= htmlspecialchars($totp_user) ?></strong> olarak giriş yapıyorsunuz.
            </p>
            <p class="text-center small mb-3" style="color:var(--bs-secondary);">
                Doğrulama uygulamanızdaki 6 haneli kodu girin.
            </p>
            <form method="POST" id="totpForm">
                <input type="hidden" name="totp_kodu" id="totpHidden">
                <div class="totp-grid mb-2" id="totpBoxes">
                    <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                    <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                    <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                    <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                    <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                    <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                </div>
                <p class="totp-hint mb-4"><i class="bi bi-clock me-1"></i>Kod 30 saniyede bir yenilenir</p>
                <button type="submit" class="btn btn-login btn-primary w-100 mb-2" id="totpBtn" disabled>
                    <i class="bi bi-shield-check me-2"></i>Doğrula
                </button>
            </form>
            <form method="POST" class="mt-2">
                <button type="submit" name="farkli_yontem" value="1"
                        class="btn btn-outline-secondary btn-sm w-100">
                    <i class="bi bi-arrow-repeat me-1"></i>Farklı yöntemle giriş yap
                </button>
            </form>
            <a href="index.php?iptal=1" class="d-block text-center small text-muted mt-2">
                <i class="bi bi-arrow-left me-1"></i>Farklı hesapla giriş yap
            </a>

            <?php elseif ($adim === 'kanal_sec'): ?>
            <p class="text-center small text-muted mb-3">
                Doğrulama kodunu hangi yöntemle almak istersiniz?
            </p>
            <?php
                $kanal_etiket = ['sms' => ['SMS ile Gönder', 'bi-phone-fill', 'btn-outline-primary'],
                                  'email' => ['E-posta ile Gönder', 'bi-envelope-fill', 'btn-outline-danger']];
            ?>
            <form method="POST" class="d-flex flex-column gap-2">
                <?php foreach ($kanal_secenekleri_login as $kk): ?>
                    <?php [$etiket, $ikon, $renk] = $kanal_etiket[$kk]; ?>
                    <button type="submit" name="kanal_secildi" value="<?= $kk ?>"
                            class="btn <?= $renk ?> w-100 d-flex align-items-center justify-content-center gap-2 py-2">
                        <i class="bi <?= $ikon ?>"></i><?= $etiket ?>
                    </button>
                <?php endforeach; ?>
            </form>
            <a href="index.php?iptal=1" class="d-block text-center small text-muted mt-3">
                <i class="bi bi-arrow-left me-1"></i>Farklı hesapla giriş yap
            </a>

            <?php elseif ($adim === 'mesaj_kod'): ?>
            <p class="text-center small text-muted mb-3">
                <?php if ($mesaj_kanal_aktif === 'email'): ?>
                    <i class="bi bi-envelope-fill text-danger me-1"></i>
                    <strong><?= htmlspecialchars($mesaj_hedef) ?></strong> adresine 6 haneli kod gönderildi.
                <?php elseif ($mesaj_kanal_aktif === 'sms'): ?>
                    <i class="bi bi-phone-fill text-primary me-1"></i>
                    <strong><?= htmlspecialchars($mesaj_hedef) ?></strong> numaralı hatta SMS ile 6 haneli kod gönderildi.
                <?php endif; ?>
            </p>
            <form method="POST" id="mesajKodForm">
                <input type="hidden" name="mesaj_kodu" id="mesajKodHidden">
                <div class="totp-grid mb-2" id="mesajKodBoxes">
                    <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                    <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                    <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                    <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                    <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                    <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                </div>
                <p class="totp-hint mb-4"><i class="bi bi-clock me-1"></i>Kod 10 dakika geçerlidir</p>
                <button type="submit" class="btn btn-login btn-primary w-100 mb-2" id="mesajKodBtn" disabled>
                    <i class="bi bi-shield-check me-2"></i>Doğrula
                </button>
            </form>
            <a href="index.php?iptal=1" class="d-block text-center small text-muted mt-2">
                <i class="bi bi-arrow-left me-1"></i>Farklı hesapla giriş yap
            </a>
            <?php endif; ?>
        </div>
    </div>

    <p class="text-center mt-3 small" style="color:rgba(255,255,255,.4);">
        &copy; <?= date('Y') ?> Ödemeix Portal — Tüm hakları saklıdır
    </p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const btn = document.getElementById('themeBtn');
    function guncelle(t) {
        document.documentElement.setAttribute('data-bs-theme', t);
        btn.innerHTML = t === 'dark' ? '<i class="bi bi-sun-fill"></i>' : '<i class="bi bi-moon-fill"></i>';
    }
    guncelle(localStorage.getItem('theme') || 'light');
    btn.addEventListener('click', () => {
        const next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
        localStorage.setItem('theme', next);
        guncelle(next);
    });

    function kodKutulariKur(boxesId, hiddenId, btnId, formId) {
        const boxes = document.querySelectorAll('#' + boxesId + ' input');
        const hidden = document.getElementById(hiddenId);
        const btn    = document.getElementById(btnId);
        const form   = document.getElementById(formId);
        if (!boxes.length) return;
        boxes[0].focus();
        boxes.forEach((box, idx) => {
            box.addEventListener('input', () => {
                box.value = box.value.replace(/\D/g,'').slice(-1);
                box.classList.toggle('filled', box.value !== '');
                if (box.value && idx < boxes.length - 1) boxes[idx + 1].focus();
                sync();
            });
            box.addEventListener('keydown', e => {
                if (e.key === 'Backspace' && !box.value && idx > 0) {
                    boxes[idx-1].value = ''; boxes[idx-1].classList.remove('filled'); boxes[idx-1].focus(); sync();
                }
                if (e.key === 'ArrowLeft'  && idx > 0)              boxes[idx-1].focus();
                if (e.key === 'ArrowRight' && idx < boxes.length-1) boxes[idx+1].focus();
            });
            box.addEventListener('paste', e => {
                e.preventDefault();
                const p = (e.clipboardData||window.clipboardData).getData('text').replace(/\D/g,'');
                [...p].forEach((ch,i) => { if(boxes[i]){boxes[i].value=ch;boxes[i].classList.add('filled');} });
                boxes[Math.min(p.length, boxes.length-1)].focus();
                sync();
            });
        });
        function sync() {
            const code = [...boxes].map(b => b.value).join('');
            hidden.value = code;
            btn.disabled = code.length < 6;
            if (code.length === 6) form.submit();
        }
    }
    kodKutulariKur('totpBoxes', 'totpHidden', 'totpBtn', 'totpForm');
    kodKutulariKur('mesajKodBoxes', 'mesajKodHidden', 'mesajKodBtn', 'mesajKodForm');
</script>
</body>
</html>
