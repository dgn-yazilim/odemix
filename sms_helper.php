<?php
// TurkeySMS (turkeysms.com.tr) entegrasyonu
// API v3 GET: https://turkeysms.com.tr/api/v3/get/get.php

function sms_ayar_oku(PDO $db, string $anahtar, string $varsayilan = ''): string
{
    try {
        $s = $db->prepare("SELECT deger FROM sistem_ayarlari WHERE anahtar = ?");
        $s->execute([$anahtar]);
        $v = $s->fetchColumn();
        return $v !== false ? $v : $varsayilan;
    } catch (PDOException) {
        return $varsayilan;
    }
}

function sms_telefon_formatla(string $tel): string
{
    $t = preg_replace('/\D/', '', $tel);
    if (strlen($t) === 11 && $t[0] === '0') $t = '9' . $t;
    if (str_starts_with($t, '+')) $t = ltrim($t, '+');
    return $t;
}

function _sms_get(string $api_key, string $baslik, string $telefon, string $mesaj): array
{
    $url = 'https://turkeysms.com.tr/api/v3/get/get.php?' . http_build_query([
        'api_key'       => $api_key,
        'mobile'        => sms_telefon_formatla($telefon),
        'title'         => $baslik,
        'text'          => $mesaj,
        'report'        => 1,
        'lang'          => 1,
        'response_type' => 'json',
        'content_type'  => 1,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $res = curl_exec($ch);

    if (!$res) return ['ok' => false, 'kod' => '', 'mt' => 'Bağlantı kurulamadı.'];

    $json = json_decode($res, true);

    // TurkeySMS v3 GET başarılı yanıt: {"status":1,...} veya {"result":true,...}
    $ok = !empty($json['status']) || (!empty($json['result']) && ($json['result_code'] ?? '') === 'TS-1024');
    $kod = $json['result_code'] ?? ($json['code'] ?? '');
    $mt  = $json['result_message'] ?? ($json['message'] ?? $res);

    return ['ok' => $ok, 'kod' => $kod, 'mt' => $mt, 'raw' => $res];
}

function sms_gonder(PDO $db, string $telefon, string $mesaj): bool
{
    if (sms_ayar_oku($db, 'sms_aktif', '0') !== '1') return false;
    $api_key = sms_ayar_oku($db, 'sms_api_key');
    $baslik  = sms_ayar_oku($db, 'sms_baslik');
    if (!$api_key || !$baslik) return false;
    return _sms_get($api_key, $baslik, $telefon, $mesaj)['ok'];
}

function sms_test_gonder(PDO $db, string $telefon, string $mesaj): array
{
    $api_key = sms_ayar_oku($db, 'sms_api_key');
    $baslik  = sms_ayar_oku($db, 'sms_baslik');

    if (!$api_key) return ['ok' => false, 'hata' => 'API key girilmemiş.'];
    if (!$baslik)  return ['ok' => false, 'hata' => 'Sender ID girilmemiş.'];

    $ret = _sms_get($api_key, $baslik, $telefon, $mesaj);
    if ($ret['ok']) return ['ok' => true, 'hata' => ''];

    $aciklamalar = [
        'TS-1027' => 'Yetersiz bakiye.',
        'TS-1028' => 'Sender ID aktif değil.',
        'TS-1031' => 'Geçersiz API key.',
        'TS-1060' => 'İstek limiti aşıldı.',
        'TS-1065' => 'Bu key\'de gönderme yetkisi yok.',
    ];
    $kod = $ret['kod'];
    $ac  = $aciklamalar[$kod] ?? '';
    return ['ok' => false, 'hata' => trim("$ac [Kod: $kod — {$ret['mt']}]")];
}

// ── Kullanıcıya doğrulama kodu: kanal seçimi + gönderim ────────────────────────

// Bir kullanıcı için hangi kanallar gerçekten kullanılabilir (iletişim bilgisi + sistem ayarı)
function dogrulama_kanallari(PDO $db, array $kullanici): array {
    $kanallar = [];
    if (!empty($kullanici['telefon'])) {
        if (sms_ayar_oku($db, 'sms_aktif', '0') === '1' && sms_ayar_oku($db, 'sms_api_key', '')) {
            $kanallar[] = 'sms';
        }
    }
    if (!empty($kullanici['email']) && function_exists('mail_ayar_oku') && mail_ayar_oku($db, 'smtp_user', '')) {
        $kanallar[] = 'email';
    }
    return $kanallar;
}

// Seçilen kanaldan doğrulama kodu gönder → ['ok'=>bool,'hedef'=>string,'kod'=>?string,'otp_id'=>?string]
function dogrulama_kodu_gonder(PDO $db, array $kullanici, string $kanal): array {
    if ($kanal === 'email' && !empty($kullanici['email']) && function_exists('mail_gonder')) {
        $kod   = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $govde = "<div style='font-family:sans-serif;max-width:480px;margin:0 auto;padding:24px;'>
            <h2 style='color:#4527a0;'>PORTAL</h2>
            <p>Doğrulama kodunuz:</p>
            <div style='font-size:36px;font-weight:bold;letter-spacing:8px;color:#5e35b1;padding:16px 0;'>{$kod}</div>
            <p style='color:#666;font-size:13px;'>Bu kod 10 dakika geçerlidir.</p>
        </div>";
        if (mail_gonder($db, $kullanici['email'], 'PORTAL – Doğrulama Kodu', $govde)) {
            [$yerel, $domain] = explode('@', $kullanici['email']);
            return ['ok' => true, 'hedef' => substr($yerel, 0, 1) . '***@' . $domain, 'kod' => $kod];
        }
    } elseif ($kanal === 'sms' && !empty($kullanici['telefon'])) {
        $ret = otp_gonder($db, $kullanici['telefon']);
        if ($ret['ok']) {
            return ['ok' => true, 'hedef' => '****' . substr(preg_replace('/\D/', '', $kullanici['telefon']), -4), 'otp_id' => $ret['otp_id']];
        }
    }
    return ['ok' => false];
}

// ── TurkeySMS OTP REST API ─────────────────────────────────────────────────────

function otp_telefon_formatla(string $tel): string {
    $t = preg_replace('/\D/', '', $tel);
    if (strlen($t) === 11 && $t[0] === '0') $t = '9' . $t;
    return '+' . ltrim($t, '+');
}

// OTP gönder → başarıda ['ok'=>true, 'otp_id'=>'...'], başarısızda ['ok'=>false, 'hata'=>'...']
function otp_gonder(PDO $db, string $telefon): array {
    $api_key = sms_ayar_oku($db, 'sms_api_key');
    if (!$api_key) return ['ok' => false, 'hata' => 'API key girilmemiş.'];

    $payload = json_encode([
        'to'   => otp_telefon_formatla($telefon),
        'ttl'  => 300,
        'from' => 'TurkeySMS',
    ]);

    $ch = curl_init('https://api.turkeysms.com.tr/otp/send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json',
        ],
    ]);
    $res = curl_exec($ch);
    if (!$res) return ['ok' => false, 'hata' => 'Bağlantı kurulamadı.'];

    $json = json_decode($res, true);
    if (($json['status'] ?? '') === 'success' && !empty($json['otp_id'])) {
        return ['ok' => true, 'otp_id' => $json['otp_id']];
    }
    return ['ok' => false, 'hata' => $json['message'] ?? $res];
}

// OTP doğrula → true/false
function otp_dogrula(PDO $db, string $otp_id, string $kod): bool {
    $api_key = sms_ayar_oku($db, 'sms_api_key');
    if (!$api_key) return false;

    $payload = json_encode(['otp_id' => $otp_id, 'code' => $kod]);

    $ch = curl_init('https://api.turkeysms.com.tr/otp/verify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json',
        ],
    ]);
    $res = curl_exec($ch);
    if (!$res) return false;

    $json = json_decode($res, true);
    return ($json['status'] ?? '') === 'success';
}

// Toplu gönder — her numaraya ayrı istek (v3 GET tek numara destekler)
function sms_toplu_gonder(PDO $db, array $telefonlar, string $mesaj): array
{
    if (sms_ayar_oku($db, 'sms_aktif', '0') !== '1')
        return ['gonderildi' => 0, 'basarisiz' => count($telefonlar), 'hata' => 'SMS gönderimi aktif değil.'];

    $api_key = sms_ayar_oku($db, 'sms_api_key');
    $baslik  = sms_ayar_oku($db, 'sms_baslik');

    if (!$api_key || !$baslik)
        return ['gonderildi' => 0, 'basarisiz' => count($telefonlar), 'hata' => 'API key veya başlık eksik.'];

    $gonderildi = 0; $basarisiz = 0; $son_hata = '';

    foreach ($telefonlar as $tel) {
        $t = sms_telefon_formatla($tel);
        if (!$t) { $basarisiz++; continue; }
        $ret = _sms_get($api_key, $baslik, $t, $mesaj);
        $ret['ok'] ? $gonderildi++ : $basarisiz++;
        if (!$ret['ok']) $son_hata = $ret['mt'];
    }

    return ['gonderildi' => $gonderildi, 'basarisiz' => $basarisiz, 'hata' => $son_hata];
}
