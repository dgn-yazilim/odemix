<?php
// ── VAPID yardımcıları (Web Push, kütüphane gerektirmez) ─────────────────────

// Portal panelin altından (sembolik bağ üzerinden) servis edildiğinde aynı dosya iki ayrı yolla
// yüklenebiliyor; require_once bunu yakalamadığı için tanımlar tekrar edip ölümcül hata veriyordu.
if (function_exists('b64u')) {
    return;
}

function b64u(string $d): string {
    return rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
}
function b64u_d(string $d): string {
    $pad = (4 - strlen($d) % 4) % 4;
    return base64_decode(strtr($d . str_repeat('=', $pad), '-_', '+/'));
}

// Anahtarları DB'den al, yoksa üret ve kaydet
function vapid_keys(PDO $db): array {
    // Tablo yoksa oluştur
    $db->exec("CREATE TABLE IF NOT EXISTS app_settings (
        k VARCHAR(100) PRIMARY KEY,
        v TEXT NOT NULL
    )");

    $row = $db->query("SELECT v FROM app_settings WHERE k = 'vapid'")->fetch();
    if ($row) return json_decode($row['v'], true);

    // EC P-256 anahtar çifti üret
    $key  = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $info = openssl_pkey_get_details($key);
    openssl_pkey_export($key, $priv_pem);

    // Sıkıştırılmamış public key: 0x04 || x || y
    $pub_raw = "\x04"
        . str_pad($info['ec']['x'], 32, "\x00", STR_PAD_LEFT)
        . str_pad($info['ec']['y'], 32, "\x00", STR_PAD_LEFT);

    $keys = ['public' => b64u($pub_raw), 'private_pem' => $priv_pem];
    $db->prepare("INSERT INTO app_settings (k, v) VALUES ('vapid', ?)")
       ->execute([json_encode($keys)]);

    return $keys;
}

// VAPID JWT oluştur (ES256)
function vapid_jwt(string $audience, string $private_pem): string {
    $header  = b64u('{"typ":"JWT","alg":"ES256"}');
    $payload = b64u(json_encode([
        'aud' => $audience,
        'exp' => time() + 43200,
        'sub' => 'mailto:portal@portal.local',
    ]));
    $input = $header . '.' . $payload;

    openssl_sign($input, $der, $private_pem, OPENSSL_ALGO_SHA256);

    // DER ECDSA → raw r||s (her biri 32 byte)
    $pos = 0;
    if (ord($der[$pos++]) !== 0x30) return '';
    $len = ord($der[$pos++]);
    if ($len & 0x80) $pos += $len & 0x7f; // uzun form atla
    if (ord($der[$pos++]) !== 0x02) return '';
    $rl = ord($der[$pos++]);
    $r  = substr($der, $pos, $rl);
    $pos += $rl;
    if (ord($der[$pos++]) !== 0x02) return '';
    $sl = ord($der[$pos++]);
    $s  = substr($der, $pos, $sl);

    $r = substr(str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT), -32);
    $s = substr(str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT), -32);

    return $input . '.' . b64u($r . $s);
}

// Push bildirim gönder (boş payload — SW içeride fetch ile içerik alır)
function push_send(array $sub, string $vapid_pub, string $vapid_priv): int {
    if (empty($sub['endpoint'])) return 0;
    $parsed   = parse_url($sub['endpoint']);
    $audience = $parsed['scheme'] . '://' . $parsed['host'];
    $jwt      = vapid_jwt($audience, $vapid_priv);

    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: vapid t=' . $jwt . ',k=' . $vapid_pub,
            'Content-Length: 0',
            'TTL: 86400',
        ],
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    return $code;
}
