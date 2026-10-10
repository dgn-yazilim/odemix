<?php
session_start();
require __DIR__ . '/config.php';
require __DIR__ . '/totp.php';

if (!isset($_SESSION['kullanici_id'])) {
    header("Location: index.php");
    exit;
}

$uid   = $_SESSION['kullanici_id'];
$stmt  = $db->prepare("SELECT * FROM kullanicilar WHERE id = ?");
$stmt->execute([$uid]);
$user  = $stmt->fetch();

$msg     = '';
$msgType = 'success';

/* ── 2FA'yı devre dışı bırak ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['devre_disi'])) {
    $db->prepare("UPDATE kullanicilar SET totp_enabled = FALSE, totp_secret = NULL WHERE id = ?")
       ->execute([$uid]);
    $user['totp_enabled'] = false;
    $user['totp_secret']  = null;
    $msg = "İki faktörlü doğrulama devre dışı bırakıldı.";
    $msgType = 'warning';
}

/* ── Yeni secret oluştur / mevcut secret göster ── */
if (empty($user['totp_secret'])) {
    $secret = totp_generate_secret();
    $db->prepare("UPDATE kullanicilar SET totp_secret = ?, totp_enabled = FALSE WHERE id = ?")
       ->execute([$secret, $uid]);
    $user['totp_secret']  = $secret;
    $user['totp_enabled'] = false;
} else {
    $secret = $user['totp_secret'];
}

/* ── Kodu doğrula ve 2FA'yı etkinleştir ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dogrulama_kodu'])) {
    $kod = trim($_POST['dogrulama_kodu']);
    if (totp_verify($secret, $kod)) {
        $db->prepare("UPDATE kullanicilar SET totp_enabled = TRUE WHERE id = ?")
           ->execute([$uid]);
        $user['totp_enabled'] = true;
        $msg = "İki faktörlü doğrulama başarıyla etkinleştirildi!";
    } else {
        $msg = "Hatalı kod! Uygulamayı kontrol edip tekrar deneyin.";
        $msgType = 'danger';
    }
}

$qrUrl = totp_qr_url($secret, $user['kullanici_adi']);
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
    <title>İki Faktörlü Doğrulama — Ödemeix Portal</title>
    <script>
        const _t = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-bs-theme', _t);
    </script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        .secret-code {
            font-family: 'Courier New', monospace;
            letter-spacing: 3px;
            font-size: 1.1rem;
            font-weight: 700;
            background: var(--bs-tertiary-bg);
            border-radius: 8px;
            padding: .5rem 1rem;
            cursor: pointer;
            user-select: all;
            border: 1px dashed var(--bs-border-color);
        }
        .qr-wrap {
            background: #fff;
            border-radius: 16px;
            display: inline-flex;
            padding: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,.12);
        }
        .step-badge {
            width: 28px; height: 28px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: .85rem;
            flex-shrink: 0;
        }
        .totp-grid { display: flex; gap: .4rem; justify-content: center; }
        .totp-grid input {
            width: 46px; height: 54px;
            text-align: center;
            font-size: 1.4rem;
            font-weight: 700;
            border-radius: 10px;
            border: 2px solid var(--bs-border-color);
            background: transparent;
            color: var(--bs-body-color);
            transition: border-color .15s;
        }
        .totp-grid input:focus { outline: none; border-color: #5e35b1; box-shadow: 0 0 0 3px rgba(94,53,177,.2); }
        .totp-grid input.filled { border-color: #5e35b1; }
    </style>
</head>
<body>
<?php include __DIR__ . '/header.php'; ?>

<div class="container py-4" style="max-width:640px;">

    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="dashboard.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
        </a>
        <h4 class="mb-0"><i class="bi bi-shield-lock-fill text-primary me-2"></i>İki Faktörlü Doğrulama</h4>
        <?php if (!empty($user['totp_enabled'])): ?>
            <span class="badge bg-success ms-auto"><i class="bi bi-check-circle me-1"></i>Aktif</span>
        <?php else: ?>
            <span class="badge bg-secondary ms-auto">Pasif</span>
        <?php endif; ?>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msgType ?> py-2 small"><i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <?php if (!empty($user['totp_enabled'])): ?>
    <!-- ── 2FA Aktif görünümü ── -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body text-center py-4">
            <div class="mb-3">
                <i class="bi bi-shield-check-fill text-success" style="font-size:3rem;"></i>
            </div>
            <h5 class="mb-1">İki faktörlü doğrulama aktif</h5>
            <p class="text-muted small mb-4">Girişleriniz Google Authenticator veya benzeri bir uygulama ile korunuyor.</p>
            <form method="POST" onsubmit="return confirm('2FA\'yı devre dışı bırakmak istediğinizden emin misiniz?')">
                <button type="submit" name="devre_disi" value="1" class="btn btn-outline-danger">
                    <i class="bi bi-shield-x me-1"></i>Devre Dışı Bırak
                </button>
            </form>
        </div>
    </div>

    <?php else: ?>
    <!-- ── Kurulum adımları ── -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body p-4">

            <!-- Adım 1 -->
            <div class="d-flex gap-3 mb-4">
                <div class="step-badge bg-primary text-white">1</div>
                <div>
                    <div class="fw-semibold mb-1">Uygulamayı indirin</div>
                    <p class="text-muted small mb-0">
                        <a href="https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2" target="_blank">Google Authenticator</a>,
                        <a href="https://www.microsoft.com/tr-tr/security/mobile-authenticator-app" target="_blank">Microsoft Authenticator</a>
                        veya <a href="https://authy.com/" target="_blank">Authy</a> uygulamalarından birini kullanabilirsiniz.
                    </p>
                </div>
            </div>

            <!-- Adım 2 -->
            <div class="d-flex gap-3 mb-4">
                <div class="step-badge bg-primary text-white">2</div>
                <div class="w-100">
                    <div class="fw-semibold mb-2">QR kodu tarayın veya kodu manuel girin</div>
                    <div class="text-center mb-3">
                        <div class="qr-wrap">
                            <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR Kod" width="200" height="200"
                                 onerror="this.style.display='none';document.getElementById('qrFallback').style.display='block'">
                        </div>
                        <div id="qrFallback" style="display:none;" class="alert alert-warning small mt-2">QR kodu yüklenemedi. Lütfen manuel kodu kullanın.</div>
                    </div>
                    <div class="text-center">
                        <div class="text-muted small mb-1">Manuel giriş kodu:</div>
                        <div class="secret-code" id="secretCode" title="Kopyalamak için tıklayın">
                            <?= htmlspecialchars(wordwrap($secret, 4, ' ', true)) ?>
                        </div>
                        <div class="text-muted" style="font-size:.75rem;margin-top:.3rem;" id="copyMsg"></div>
                    </div>
                </div>
            </div>

            <!-- Adım 3 -->
            <div class="d-flex gap-3">
                <div class="step-badge bg-primary text-white">3</div>
                <div class="w-100">
                    <div class="fw-semibold mb-2">Doğrulama kodunu girin</div>
                    <form method="POST" id="verifyForm">
                        <input type="hidden" name="dogrulama_kodu" id="dogrulamaHidden">
                        <div class="totp-grid mb-2" id="verifyBoxes">
                            <input type="text" maxlength="1" inputmode="numeric" autocomplete="off">
                            <input type="text" maxlength="1" inputmode="numeric" autocomplete="off">
                            <input type="text" maxlength="1" inputmode="numeric" autocomplete="off">
                            <input type="text" maxlength="1" inputmode="numeric" autocomplete="off">
                            <input type="text" maxlength="1" inputmode="numeric" autocomplete="off">
                            <input type="text" maxlength="1" inputmode="numeric" autocomplete="off">
                        </div>
                        <div class="text-center">
                            <button type="submit" class="btn btn-primary px-4" id="verifyBtn" disabled>
                                <i class="bi bi-shield-check me-1"></i>Etkinleştir
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    /* ── Secret kod kopyalama ── */
    const secretEl = document.getElementById('secretCode');
    if (secretEl) {
        secretEl.addEventListener('click', () => {
            navigator.clipboard.writeText(secretEl.textContent.replace(/\s/g, '')).then(() => {
                document.getElementById('copyMsg').textContent = 'Kopyalandı!';
                setTimeout(() => document.getElementById('copyMsg').textContent = '', 2000);
            });
        });
    }

    /* ── 6 haneli kod navigasyonu ── */
    const boxes   = document.querySelectorAll('#verifyBoxes input');
    const hidden  = document.getElementById('dogrulamaHidden');
    const btn     = document.getElementById('verifyBtn');
    const form    = document.getElementById('verifyForm');

    if (boxes.length) {
        boxes[0].focus();
        boxes.forEach((box, idx) => {
            box.addEventListener('input', () => {
                box.value = box.value.replace(/\D/g, '').slice(-1);
                box.classList.toggle('filled', box.value !== '');
                if (box.value && idx < boxes.length - 1) boxes[idx + 1].focus();
                sync();
            });
            box.addEventListener('keydown', e => {
                if (e.key === 'Backspace' && !box.value && idx > 0) {
                    boxes[idx - 1].value = '';
                    boxes[idx - 1].classList.remove('filled');
                    boxes[idx - 1].focus();
                    sync();
                }
            });
            box.addEventListener('paste', e => {
                e.preventDefault();
                const p = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
                [...p].forEach((ch, i) => { if (boxes[i]) { boxes[i].value = ch; boxes[i].classList.add('filled'); } });
                boxes[Math.min(p.length, boxes.length - 1)].focus();
                sync();
            });
        });
        function sync() {
            const code = [...boxes].map(b => b.value).join('');
            hidden.value = code;
            btn.disabled = code.length < 6;
        }
    }
</script>
</body>
</html>
