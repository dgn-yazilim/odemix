<?php
// Tüm sayfalar için güvenli varsayılan HTTP başlıkları (her sayfa config.php'yi yükler).
// X-Content-Type-Options: tarayıcının içerik türünü tahmin etmesini (MIME sniffing) engeller.
// Referrer-Policy: dış sitelere tam adres/sorgu sızmasını azaltır.
// NOT: X-Frame-Options / katı CSP bilerek EKLENMEDİ — portal, panel içinde iframe ile açılıyor
// (çerçeveleme gerekli) ve sayfalarda satır içi script/style + CDN kullanılıyor; katı CSP sayfaları bozardı.
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

if (session_status() === PHP_SESSION_NONE) {
    // Portal panel içinde (iframe) açıldığında, panel ayrı bir alan adındaysa tarayıcı oturum
    // çerezini varsayılan ayarla göndermez; portal her seferinde giriş isterdi. Bu durumda çerez
    // SameSite=None + Secure olmalı. Yalnızca HTTPS altında ve panelden gelen isteklerde uygulanır;
    // portal kendi adresinden açıldığında ve yerelde (http) hiçbir şey değişmez.
    // Oturum varsayılan olarak 24 dakikada düşüyordu; panel içinde çerçevede açık dururken
    // portala istek gitmediği için süre doluyor ve yenilemede giriş ekranı çıkıyordu. 8 saat.
    $oturum_omru = 8 * 3600;
    ini_set('session.gc_maxlifetime', (string) $oturum_omru);

    $panel_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $panel_ici = (isset($_GET['s'], $_GET['t']) && basename($_SERVER['SCRIPT_NAME'] ?? '') === 'panel_giris.php')
        || (($_COOKIE['portal_panelden'] ?? '') === '1');

    $mevcut = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => $oturum_omru,          // tarayıcı kapanınca da oturum düşmesin
        'path' => $mevcut['path'],
        'domain' => $mevcut['domain'],
        // Panel ayrı alan adındaysa çerçeve içindeki isteklerde çerez ancak böyle gönderilir.
        'secure' => $panel_https && $panel_ici,
        'httponly' => true,
        'samesite' => ($panel_https && $panel_ici) ? 'None' : 'Lax',
    ]);

    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/** POST işlemleri için CSRF kontrolü (gövdede ya da başlıkta token). */
function csrf_dogrula_post(): void {
    $gelen = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($gelen === '' || !hash_equals($_SESSION['csrf_token'] ?? '', $gelen)) {
        http_response_code(419);
        header('Content-Type: application/json');
        echo json_encode(['hata' => 'Güvenlik anahtarı geçersiz. Sayfayı yenileyip tekrar deneyin.']);
        exit;
    }
}

function csrf_dogrula() {
    if (!isset($_GET['csrf']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf'])) {
        die("<h3 style='color:red; text-align:center; margin-top:50px;'>Güvenlik hatası: Geçersiz veya süresi geçmiş işlem bağlantısı. Sayfayı yenileyip tekrar deneyin.</h3>");
    }
}

// Ayarlar: normalde proje içindeki .env. Portal panelin public dizininden servis edildiğinde
// .env web'den okunabilir olurdu; o kurulumda dosya bir üst klasörde `portal.env` adıyla durur.
$envFile = __DIR__ . '/.env';
if (!is_file($envFile)) {
    $envFile = dirname(__DIR__) . '/portal.env';
}
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] === '#') continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}
$host   = $_ENV['DB_HOST'] ?? 'localhost';
$port   = $_ENV['DB_PORT'] ?? '5432';
$dbname = $_ENV['DB_NAME'] ?? 'portal_odemeix';
$user   = $_ENV['DB_USER'] ?? 'portal_odemeix';
$pass   = $_ENV['DB_PASS'] ?? '';

try {
    $db = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Veritabanı bağlantı hatası!");
}
