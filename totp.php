<?php
// RFC 6238 TOTP – harici kütüphane gerektirmez

function totp_generate_secret(int $length = 16): string {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, 31)];
    }
    return $out;
}

function totp_base32_decode(string $secret): string {
    $secret   = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret));
    $chars    = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $buf      = '';
    $bits     = 0;
    $bitCount = 0;
    for ($i = 0, $len = strlen($secret); $i < $len; $i++) {
        $pos = strpos($chars, $secret[$i]);
        if ($pos === false) continue;
        $bits      = ($bits << 5) | $pos;
        $bitCount += 5;
        if ($bitCount >= 8) {
            $bitCount -= 8;
            $buf .= chr(($bits >> $bitCount) & 0xFF);
        }
    }
    return $buf;
}

function totp_hotp(string $secret, int $counter): string {
    $key    = totp_base32_decode($secret);
    $msg    = "\0\0\0\0" . pack('N', $counter);
    $hash   = hash_hmac('sha1', $msg, $key, true);
    $offset = ord($hash[19]) & 0xF;
    $code   = (
        ((ord($hash[$offset])     & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8)  |
         (ord($hash[$offset + 3]) & 0xFF)
    ) % 1000000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

function totp_verify(string $secret, string $code, int $window = 1): bool {
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code)) return false;
    $time = (int)(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (totp_hotp($secret, $time + $i) === $code) return true;
    }
    return false;
}

function totp_qr_url(string $secret, string $user, string $issuer = 'Portal'): string {
    $otpauth = 'otpauth://totp/'
        . rawurlencode($issuer . ':' . $user)
        . '?secret=' . $secret
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
    return 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . rawurlencode($otpauth);
}
