<?php
// Müşteri dosyaları ve başvuru belgeleri yalnız buradan servis edilir.
//
// Dosyalar `uploads/` altında duruyor ve web sunucusu onları doğrudan verebiliyordu: adresi bilen
// herkes kimlik, vergi levhası gibi belgeleri oturum açmadan indirebiliyordu. Artık bağlantılar
// bu dosyaya gidiyor; burada oturum kontrol edilir, dosya yolu veritabanından okunur ve içerik
// akıtılır. `uploads/` dizinine doğrudan erişim sunucu ayarıyla kapatılmalıdır.
require __DIR__ . '/config.php';

if (!isset($_SESSION['kullanici_id'])) {
    http_response_code(403);
    exit('Bu belgeyi görmek için giriş yapmalısınız.');
}

$tur = ($_GET['tur'] ?? 'musteri') === 'basvuru' ? 'basvuru' : 'musteri';
$id  = (int) ($_GET['id'] ?? 0);
$indir = isset($_GET['indir']);

if ($id <= 0) {
    http_response_code(404);
    exit('Belge bulunamadı.');
}

// Yetki: oturum var ama her belgeye erişim serbest değil. Başvuru belgeleri yalnız yönetim içindir;
// müşteri belgelerini görevli/üye yalnız kendisine atanmış müşteride görebilir (IDOR önlemi —
// dashboard.php müşteri listesini de aynı kuralla filtreliyor).
$rol      = $_SESSION['rol'] ?? '';
$uid      = (int) $_SESSION['kullanici_id'];
$yonetici = ($rol === 'admin' || $rol === 'yonetici');

if ($tur === 'basvuru') {
    if (!$yonetici) {
        http_response_code(403);
        exit('Bu belgeyi görme yetkiniz yok.');
    }
    $s = $db->prepare("SELECT dosya_adi, dosya_yolu FROM basvuru_belgeleri WHERE id = ?");
    $s->execute([$id]);
    $kayit = $s->fetch();
} else {
    $s = $db->prepare("SELECT dosya_adi, dosya_yolu, musteri_id FROM musteri_dosyalari WHERE id = ?");
    $s->execute([$id]);
    $kayit = $s->fetch();
    if ($kayit && !$yonetici) {
        $sahip = $db->prepare("SELECT 1 FROM musteriler WHERE id = ? AND gorevli_id = ?");
        $sahip->execute([(int) $kayit['musteri_id'], $uid]);
        if (!$sahip->fetchColumn()) {
            http_response_code(403);
            exit('Bu belgeyi görme yetkiniz yok.');
        }
    }
}

if (!$kayit) {
    http_response_code(404);
    exit('Belge bulunamadı.');
}

// Yol veritabanından geliyor; yine de uploads dışına çıkılmadığını doğruluyoruz.
$kok = realpath(__DIR__ . '/uploads');
$tam = realpath(__DIR__ . '/' . ltrim((string) $kayit['dosya_yolu'], '/'));

if ($kok === false || $tam === false || !str_starts_with($tam, $kok . DIRECTORY_SEPARATOR) || !is_file($tam)) {
    http_response_code(404);
    exit('Belge dosyası bulunamadı.');
}

$uzanti = strtolower(pathinfo((string) $kayit['dosya_adi'], PATHINFO_EXTENSION));
$turler = [
    'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif', 'webp' => 'image/webp', 'txt' => 'text/plain; charset=utf-8',
    'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'zip' => 'application/zip', 'rar' => 'application/vnd.rar',
];
$icerikTuru = $turler[$uzanti] ?? 'application/octet-stream';

// Tarayıcıda açılabilenler dışında her şey indirilir; HTML/SVG asla satır içi gösterilmez.
$satirIci = !$indir && in_array($uzanti, ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'txt'], true);

header('Content-Type: ' . $icerikTuru);
header('Content-Length: ' . filesize($tam));
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; object-src \'none\'');
header('Cache-Control: private, max-age=0, no-store');
header(sprintf(
    '%s; filename="%s"',
    $satirIci ? 'Content-Disposition: inline' : 'Content-Disposition: attachment',
    preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $kayit['dosya_adi'])
));

readfile($tam);
