<?php
session_start();
require __DIR__ . '/config.php';
require __DIR__ . '/sms_helper.php';
require __DIR__ . '/mail_helper.php';

if (isset($_SESSION['kullanici_id'])) { header("Location: dashboard.php"); exit; }

$db->exec("ALTER TABLE kullanicilar ADD COLUMN IF NOT EXISTS sifre_sifirla_kod VARCHAR(10) DEFAULT NULL");
$db->exec("ALTER TABLE kullanicilar ADD COLUMN IF NOT EXISTS sifre_sifirla_son TIMESTAMP  DEFAULT NULL");
$db->exec("ALTER TABLE kullanicilar ADD COLUMN IF NOT EXISTS email VARCHAR(255) DEFAULT NULL");

// Seçilen kanaldan kodu gönder + WA/E-posta için DB'ye, SMS için session'a kaydet
function sifre_sifirla_kod_gonder_ve_ayarla(PDO $db, array $user, string $kanal): array {
    $sonuc = dogrulama_kodu_gonder($db, $user, $kanal);
    if ($sonuc['ok'] && !empty($sonuc['kod'])) {
        $son = date('Y-m-d H:i:s', strtotime('+10 minutes'));
        $db->prepare("UPDATE kullanicilar SET sifre_sifirla_kod=?, sifre_sifirla_son=? WHERE id=?")
           ->execute([$sonuc['kod'], $son, $user['id']]);
    }
    if ($sonuc['ok'] && !empty($sonuc['otp_id'])) {
        $_SESSION['sifre_otp_id'] = $sonuc['otp_id'];
    }
    return $sonuc;
}

// ── İptal ─────────────────────────────────────────────────────────────────────
if (isset($_GET['iptal'])) {
    unset($_SESSION['sifre_adim'], $_SESSION['sifre_uid'], $_SESSION['sifre_hedef'], $_SESSION['sifre_kanal'],
          $_SESSION['sifre_otp_id'], $_SESSION['sifre_kanal_secim_uid'], $_SESSION['sifre_kanal_secim_listesi']);
    header("Location: index.php"); exit;
}

$adim = $_SESSION['sifre_adim'] ?? 'telefon';
$hata = '';

// ── POST işlemleri ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* Adım 1 – Telefon ile kimlik tespiti */
    if ($adim === 'telefon') {
        unset($_SESSION['sifre_kod_deneme']); // yeni akış → deneme sayacı sıfırlanır
        $tel = preg_replace('/\D/', '', trim($_POST['telefon'] ?? ''));
        if (strlen($tel) < 10) {
            $hata = "Geçerli bir telefon numarası girin.";
        } else {
            $son10 = substr($tel, -10);
            $stmt  = $db->prepare(
                "SELECT * FROM kullanicilar
                 WHERE REGEXP_REPLACE(telefon,'[^0-9]','','g') LIKE ?"
            );
            $stmt->execute(['%' . $son10]);
            $user = $stmt->fetch();

            if (!$user || empty($user['telefon'])) {
                $hata = "Bu telefon numarasına kayıtlı kullanıcı bulunamadı.";
            } else {
                $kanallar = dogrulama_kanallari($db, $user);
                if (count($kanallar) === 0) {
                    $hata = "Hesabınızda doğrulama için kullanılabilecek iletişim yöntemi yok. Yöneticinizle iletişime geçin.";
                } elseif (count($kanallar) === 1) {
                    $sonuc = sifre_sifirla_kod_gonder_ve_ayarla($db, $user, $kanallar[0]);
                    if ($sonuc['ok']) {
                        $_SESSION['sifre_adim']  = 'kod';
                        $_SESSION['sifre_uid']   = $user['id'];
                        $_SESSION['sifre_hedef'] = $sonuc['hedef'];
                        $_SESSION['sifre_kanal'] = $kanallar[0];
                        header("Location: sifre_sifirla.php"); exit;
                    } else {
                        $hata = "Doğrulama kodu gönderilemedi.";
                    }
                } else {
                    $_SESSION['sifre_kanal_secim_uid']     = $user['id'];
                    $_SESSION['sifre_kanal_secim_listesi'] = $kanallar;
                    $_SESSION['sifre_adim'] = 'kanal_sec';
                    header("Location: sifre_sifirla.php"); exit;
                }
            }
        }
    }

    /* Adım 1b – Doğrulama yöntemi seçimi */
    elseif ($adim === 'kanal_sec') {
        $uid     = (int)($_SESSION['sifre_kanal_secim_uid'] ?? 0);
        $izinli  = $_SESSION['sifre_kanal_secim_listesi']   ?? [];
        $secilen = $_POST['kanal_secildi'] ?? '';

        if (!$uid || !in_array($secilen, $izinli, true)) {
            header("Location: sifre_sifirla.php?iptal=1"); exit;
        }
        $stmt = $db->prepare("SELECT * FROM kullanicilar WHERE id = ?");
        $stmt->execute([$uid]);
        $user = $stmt->fetch();

        $sonuc = sifre_sifirla_kod_gonder_ve_ayarla($db, $user, $secilen);
        if ($sonuc['ok']) {
            unset($_SESSION['sifre_kanal_secim_uid'], $_SESSION['sifre_kanal_secim_listesi']);
            $_SESSION['sifre_adim']  = 'kod';
            $_SESSION['sifre_uid']   = $user['id'];
            $_SESSION['sifre_hedef'] = $sonuc['hedef'];
            $_SESSION['sifre_kanal'] = $secilen;
            header("Location: sifre_sifirla.php"); exit;
        } else {
            $hata = "Kod gönderilemedi, lütfen başka bir yöntem deneyin.";
        }
    }

    /* Adım 2 – Doğrulama kodu */
    elseif ($adim === 'kod') {
        // Kaba kuvvet / hesap ele geçirme önlemi: bir kod için en çok 6 yanlış deneme.
        // Aşılırsa sıfırlama akışı iptal edilir; kullanıcı baştan başlar ve yeni kod üretilir
        // (6 haneli kod + yeniden üretim gerekliliği, kodu tahmin etmeyi pratikte imkânsız kılar).
        $_SESSION['sifre_kod_deneme'] = ($_SESSION['sifre_kod_deneme'] ?? 0) + 1;
        if ($_SESSION['sifre_kod_deneme'] > 6) {
            unset($_SESSION['sifre_adim'], $_SESSION['sifre_uid'], $_SESSION['sifre_hedef'],
                  $_SESSION['sifre_kanal'], $_SESSION['sifre_otp_id'], $_SESSION['sifre_kod_deneme']);
            $hata = "Çok fazla hatalı deneme. Lütfen baştan başlayın.";
        } else {
            $girilen = preg_replace('/\D/', '', trim($_POST['sifre_kodu'] ?? ''));
            $uid     = (int)($_SESSION['sifre_uid'] ?? 0);
            $kanal_k = $_SESSION['sifre_kanal'] ?? 'email';

            if ($uid && strlen($girilen) >= 4) {
                $gecerli = false;

                if ($kanal_k === 'sms' && !empty($_SESSION['sifre_otp_id'])) {
                    // TurkeySMS OTP doğrulama
                    $gecerli = otp_dogrula($db, $_SESSION['sifre_otp_id'], $girilen);
                } else {
                    // WA / E-posta — DB'deki kod ile karşılaştır
                    $stmt = $db->prepare(
                        "SELECT id FROM kullanicilar
                         WHERE id=? AND sifre_sifirla_kod=? AND sifre_sifirla_son > NOW()"
                    );
                    $stmt->execute([$uid, $girilen]);
                    $gecerli = (bool)$stmt->fetchColumn();
                }

                if ($gecerli) {
                    unset($_SESSION['sifre_otp_id'], $_SESSION['sifre_kod_deneme']);
                    $_SESSION['sifre_adim'] = 'yeni_sifre';
                    header("Location: sifre_sifirla.php"); exit;
                } else {
                    $hata = "Hatalı veya süresi dolmuş kod!";
                }
            } else {
                $hata = "Lütfen kodu eksiksiz girin.";
            }
        }
    }

    /* Adım 3 – Yeni şifre */
    elseif ($adim === 'yeni_sifre') {
        $uid   = (int)($_SESSION['sifre_uid'] ?? 0);
        $yeni  = trim($_POST['yeni_sifre']  ?? '');
        $yeni2 = trim($_POST['yeni_sifre2'] ?? '');

        if (strlen($yeni) < 6) {
            $hata = "Şifre en az 6 karakter olmalıdır.";
        } elseif ($yeni !== $yeni2) {
            $hata = "Şifreler eşleşmiyor!";
        } elseif ($uid) {
            $hash = password_hash($yeni, PASSWORD_DEFAULT);
            $db->prepare(
                "UPDATE kullanicilar
                 SET sifre=?, sifre_acik=?, sifre_sifirla_kod=NULL, sifre_sifirla_son=NULL
                 WHERE id=?"
            )->execute([$hash, $yeni, $uid]);

            unset($_SESSION['sifre_adim'], $_SESSION['sifre_uid'], $_SESSION['sifre_hedef'], $_SESSION['sifre_kanal']);
            header("Location: index.php?sifre_degisti=1"); exit;
        }
    }
}

$adim = $_SESSION['sifre_adim'] ?? 'telefon';
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
    <title>Şifre Sıfırla — Ödemeix Portal</title>
    <script>
        const _t = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-bs-theme', _t);
    </script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        * { box-sizing: border-box; }
        body {
            min-height: 100vh; display: flex; align-items: center;
            justify-content: center; padding: 1rem; position: relative; overflow: hidden;
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
            padding: 2rem 2rem 1.6rem; text-align: center;
        }
        .login-icon {
            width:64px;height:64px;background:rgba(255,255,255,.15);border-radius:50%;
            display:inline-flex;align-items:center;justify-content:center;
            font-size:1.8rem;color:#fff;margin-bottom:.8rem;backdrop-filter:blur(4px);
        }
        .login-odemeix  { color:#a855f7;font-size:1.4rem;font-weight:800;letter-spacing:6px;text-transform:uppercase; }
        .login-portal   { color:#9575cd;font-size:1.4rem;font-weight:800;letter-spacing:6px;text-transform:uppercase; }
        .login-subtitle { color:rgba(255,255,255,.65);font-size:.82rem;margin-top:.3rem;letter-spacing:1px; }
        .login-body     { padding:2rem; }

        .login-body .form-control { border-radius:10px;padding:.65rem 1rem;font-size:.92rem; }
        .login-body .input-group-text { border-radius:10px 0 0 10px;background:transparent;color:var(--bs-secondary); }
        .login-body .input-group .form-control { border-left:none;border-radius:0 10px 10px 0; }

        .btn-login {
            border-radius:10px;padding:.7rem;font-weight:700;font-size:.95rem;
            background:linear-gradient(135deg,#4527a0,#5e35b1);border:none;letter-spacing:.5px;transition:.2s;
        }
        .btn-login:hover { opacity:.9;transform:translateY(-1px); }

        /* Adım göstergesi */
        .adim-bar { display:flex;gap:0;margin-bottom:1.4rem; }
        .adim-item {
            flex:1;text-align:center;padding:.4rem .2rem;font-size:.72rem;font-weight:600;
            color:var(--bs-secondary);border-bottom:2px solid var(--bs-border-color);transition:.2s;
        }
        .adim-item.aktif { color:#5e35b1;border-color:#5e35b1; }
        .adim-item.tamam { color:#16a34a;border-color:#16a34a; }

        /* 6 haneli kod kutuları */
        .totp-grid { display:flex;gap:.5rem;justify-content:center; }
        .totp-grid input {
            width:48px;height:56px;text-align:center;font-size:1.5rem;font-weight:700;
            border-radius:10px;border:2px solid var(--bs-border-color);
            background:transparent;color:var(--bs-body-color);transition:border-color .15s;
        }
        .totp-grid input:focus { outline:none;border-color:#5e35b1;box-shadow:0 0 0 3px rgba(94,53,177,.2); }
        .totp-grid input.filled { border-color:#5e35b1; }

        .theme-toggle { position:fixed;top:1rem;right:1rem;z-index:10; }
        @media(max-width:480px) { .login-wrap{max-width:100%;} .login-body{padding:1.5rem;} }
    </style>
</head>
<body>

<button id="themeBtn" class="theme-toggle btn btn-sm btn-outline-light" style="opacity:.7;"></button>

<div class="login-wrap">
    <div class="login-card">
        <div class="login-top">
            <div class="login-icon">
                <?php if ($adim === 'yeni_sifre'): ?>
                    <i class="bi bi-key-fill"></i>
                <?php elseif ($adim === 'kod'): ?>
                    <?php $k = $_SESSION['sifre_kanal'] ?? 'email'; ?>
                    <i class="bi bi-<?= $k==='email'?'envelope-fill':'phone-fill' ?>"></i>
                <?php elseif ($adim === 'kanal_sec'): ?>
                    <i class="bi bi-send-check-fill"></i>
                <?php else: ?>
                    <i class="bi bi-phone-fill"></i>
                <?php endif; ?>
            </div>
            <div class="login-odemeix">ÖDEMEİX</div>
            <div class="login-portal">PORTAL</div>
            <div class="login-subtitle">Şifre Sıfırlama</div>
        </div>

        <div class="login-body">

            <!-- Adım göstergesi -->
            <div class="adim-bar">
                <div class="adim-item <?= $adim==='telefon'?'aktif':($adim!=='telefon'?'tamam':'') ?>">
                    <i class="bi bi-<?= $adim==='telefon'?'circle-fill':'check-circle-fill' ?> me-1"></i>Telefon
                </div>
                <div class="adim-item <?= $adim==='kod'?'aktif':($adim==='yeni_sifre'?'tamam':'') ?>">
                    <i class="bi bi-<?= $adim==='yeni_sifre'?'check-circle-fill':'circle'.($adim==='kod'?'-fill':'') ?> me-1"></i>Kod
                </div>
                <div class="adim-item <?= $adim==='yeni_sifre'?'aktif':'' ?>">
                    <i class="bi bi-circle me-1"></i>Yeni Şifre
                </div>
            </div>

            <?php if ($hata): ?>
                <div class="alert alert-danger py-2 small text-center mb-3" style="border-radius:10px;">
                    <i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($hata) ?>
                </div>
            <?php endif; ?>

            <?php if ($adim === 'telefon'): ?>
            <!-- ── Adım 1: Giriş ── -->
            <p class="text-muted small text-center mb-3">
                Kayıtlı telefon numaranızı girin, hesabınıza uygun doğrulama yöntemini seçeceksiniz.
            </p>
            <form method="POST">
                <div class="mb-4">
                    <label class="form-label small fw-semibold text-muted mb-1">Telefon Numarası</label>
                    <div class="input-group">
                        <span class="input-group-text border-end-0"><i class="bi bi-phone-fill"></i></span>
                        <input type="tel" name="telefon" class="form-control"
                               placeholder="05xx xxx xx xx" required autofocus>
                    </div>
                </div>
                <button type="submit" class="btn btn-login btn-primary w-100">
                    <i class="bi bi-arrow-right-circle me-2"></i>Devam Et
                </button>
            </form>

            <?php elseif ($adim === 'kanal_sec'): ?>
            <!-- ── Adım 1b: Doğrulama yöntemi seçimi ── -->
            <p class="text-center small text-muted mb-3">
                Doğrulama kodunu hangi yöntemle almak istersiniz?
            </p>
            <?php
                $kanal_etiket = ['sms' => ['SMS ile Gönder', 'bi-phone-fill', 'btn-outline-primary'],
                                  'email' => ['E-posta ile Gönder', 'bi-envelope-fill', 'btn-outline-danger']];
                $kanal_secenekleri_reset = $_SESSION['sifre_kanal_secim_listesi'] ?? [];
            ?>
            <form method="POST" class="d-flex flex-column gap-2">
                <?php foreach ($kanal_secenekleri_reset as $kk): ?>
                    <?php [$etiket, $ikon, $renk] = $kanal_etiket[$kk]; ?>
                    <button type="submit" name="kanal_secildi" value="<?= $kk ?>"
                            class="btn <?= $renk ?> w-100 d-flex align-items-center justify-content-center gap-2 py-2">
                        <i class="bi <?= $ikon ?>"></i><?= $etiket ?>
                    </button>
                <?php endforeach; ?>
            </form>

            <?php elseif ($adim === 'kod'): ?>
            <!-- ── Adım 2: Doğrulama kodu ── -->
            <?php
                $k_kanal  = $_SESSION['sifre_kanal'] ?? 'email';
                $k_hedef  = htmlspecialchars($_SESSION['sifre_hedef'] ?? '');
            ?>
            <p class="text-muted small text-center mb-3">
                <?php if ($k_kanal === 'email'): ?>
                    <i class="bi bi-envelope-fill text-danger me-1"></i>
                    <strong><?= $k_hedef ?></strong> adresine 6 haneli kod gönderildi.
                <?php elseif ($k_kanal === 'sms'): ?>
                    <i class="bi bi-phone-fill text-primary me-1"></i>
                    <strong><?= $k_hedef ?></strong> numaralı hatta SMS ile 6 haneli kod gönderildi.
                <?php endif; ?>
            </p>
            <form method="POST" id="kodForm">
                <input type="hidden" name="sifre_kodu" id="kodHidden">
                <div class="totp-grid mb-2" id="kodBoxes">
                    <input type="text" maxlength="1" inputmode="numeric" autocomplete="off">
                    <input type="text" maxlength="1" inputmode="numeric" autocomplete="off">
                    <input type="text" maxlength="1" inputmode="numeric" autocomplete="off">
                    <input type="text" maxlength="1" inputmode="numeric" autocomplete="off">
                    <input type="text" maxlength="1" inputmode="numeric" autocomplete="off">
                    <input type="text" maxlength="1" inputmode="numeric" autocomplete="off">
                </div>
                <p class="text-muted text-center mb-4" style="font-size:.78rem;">
                    <i class="bi bi-clock me-1"></i>Kod 10 dakika geçerlidir
                </p>
                <button type="submit" class="btn btn-login btn-primary w-100 mb-2" id="kodBtn" disabled>
                    <i class="bi bi-check-lg me-2"></i>Doğrula
                </button>
            </form>

            <?php elseif ($adim === 'yeni_sifre'): ?>
            <!-- ── Adım 3: Yeni şifre ── -->
            <p class="text-muted small text-center mb-3">Kimliğiniz doğrulandı. Yeni şifrenizi belirleyin.</p>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted mb-1">Yeni Şifre</label>
                    <div class="input-group">
                        <span class="input-group-text border-end-0"><i class="bi bi-lock-fill"></i></span>
                        <input type="password" name="yeni_sifre" id="yeniSifre" class="form-control"
                               placeholder="En az 6 karakter" required minlength="6" autofocus>
                        <button type="button" class="btn btn-outline-secondary border-start-0"
                                style="border-radius:0 10px 10px 0;"
                                onclick="const i=document.getElementById('yeniSifre');i.type=i.type==='password'?'text':'password'">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label small fw-semibold text-muted mb-1">Şifre Tekrar</label>
                    <div class="input-group">
                        <span class="input-group-text border-end-0"><i class="bi bi-lock-fill"></i></span>
                        <input type="password" name="yeni_sifre2" id="yeniSifre2" class="form-control"
                               placeholder="••••••••" required minlength="6">
                        <button type="button" class="btn btn-outline-secondary border-start-0"
                                style="border-radius:0 10px 10px 0;"
                                onclick="const i=document.getElementById('yeniSifre2');i.type=i.type==='password'?'text':'password'">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn-login btn-primary w-100">
                    <i class="bi bi-check-circle me-2"></i>Şifreyi Güncelle
                </button>
            </form>
            <?php endif; ?>

            <a href="sifre_sifirla.php?iptal=1" class="d-block text-center small text-muted mt-3">
                <i class="bi bi-arrow-left me-1"></i>Girişe dön
            </a>
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
        btn.innerHTML = t==='dark'?'<i class="bi bi-sun-fill"></i>':'<i class="bi bi-moon-fill"></i>';
    }
    guncelle(localStorage.getItem('theme') || 'light');
    btn.addEventListener('click', () => {
        const next = document.documentElement.getAttribute('data-bs-theme')==='dark'?'light':'dark';
        localStorage.setItem('theme', next); guncelle(next);
    });

    /* 6 haneli kod navigasyonu */
    const boxes  = document.querySelectorAll('#kodBoxes input');
    const hidden = document.getElementById('kodHidden');
    const kodBtn = document.getElementById('kodBtn');
    const form   = document.getElementById('kodForm');
    if (boxes.length) {
        boxes[0].focus();
        boxes.forEach((box, idx) => {
            box.addEventListener('input', () => {
                box.value = box.value.replace(/\D/g,'').slice(-1);
                box.classList.toggle('filled', box.value !== '');
                if (box.value && idx < boxes.length-1) boxes[idx+1].focus();
                sync();
            });
            box.addEventListener('keydown', e => {
                if (e.key==='Backspace' && !box.value && idx>0) {
                    boxes[idx-1].value=''; boxes[idx-1].classList.remove('filled'); boxes[idx-1].focus(); sync();
                }
                if (e.key==='ArrowLeft'  && idx>0)             boxes[idx-1].focus();
                if (e.key==='ArrowRight' && idx<boxes.length-1) boxes[idx+1].focus();
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
            const code = [...boxes].map(b=>b.value).join('');
            hidden.value = code;
            kodBtn.disabled = code.length < 6;
            if (code.length===6) form.submit();
        }
    }
</script>
</body>
</html>
