<?php
// SMTP e-posta gönderici (harici kütüphane gerektirmez)

function mail_ayar_oku(PDO $db, string $anahtar, string $varsayilan = ''): string
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

function smtp_satir_oku($fp): string
{
    $satir = '';
    while (!feof($fp)) {
        $satir = fgets($fp, 515);
        if ($satir[3] === ' ') break; // çok satırlı yanıt sonu
    }
    return $satir;
}

function smtp_gonder_raw(
    string $host, int $port, string $user, string $pass,
    string $from_email, string $from_name,
    string $to_email, string $konu, string $govde_html
): bool {
    $ssl    = ($port === 465);
    $prefix = $ssl ? 'ssl://' : '';
    $errno  = 0; $errstr = '';

    $fp = @stream_socket_client("{$prefix}{$host}:{$port}", $errno, $errstr, 15,
                                STREAM_CLIENT_CONNECT,
                                stream_context_create(['ssl' => [
                                    'verify_peer'      => false,
                                    'verify_peer_name' => false,
                                ]]));
    if (!$fp) return false;

    stream_set_timeout($fp, 10);

    $oku  = fn() => smtp_satir_oku($fp);
    $yaz  = fn(string $s) => fputs($fp, $s . "\r\n");

    $oku(); // 220 greeting

    $yaz('EHLO ' . (gethostname() ?: 'localhost'));
    $oku();

    if (!$ssl) {
        $yaz('STARTTLS');
        $oku(); // 220
        stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
        $yaz('EHLO ' . (gethostname() ?: 'localhost'));
        $oku();
    }

    $yaz('AUTH LOGIN');
    $oku(); // 334 VXNlcm5hbWU6
    $yaz(base64_encode($user));
    $oku(); // 334 UGFzc3dvcmQ6
    $yaz(base64_encode($pass));
    $auth = $oku(); // 235 veya hata
    if ((int)$auth !== 235) { fclose($fp); return false; }

    $yaz("MAIL FROM:<{$from_email}>");
    $oku();
    $yaz("RCPT TO:<{$to_email}>");
    $rcpt = $oku();
    if ((int)$rcpt !== 250) { fclose($fp); return false; }

    $yaz('DATA');
    $oku(); // 354

    $enc_konu = '=?UTF-8?B?' . base64_encode($konu) . '?=';
    $enc_isim = '=?UTF-8?B?' . base64_encode($from_name) . '?=';
    $body_b64 = chunk_split(base64_encode($govde_html));

    $mesaj = "From: {$enc_isim} <{$from_email}>\r\n"
           . "To: <{$to_email}>\r\n"
           . "Subject: {$enc_konu}\r\n"
           . "MIME-Version: 1.0\r\n"
           . "Content-Type: text/html; charset=UTF-8\r\n"
           . "Content-Transfer-Encoding: base64\r\n"
           . "\r\n"
           . $body_b64;

    $yaz($mesaj);
    $yaz('.');
    $son = $oku(); // 250 OK

    $yaz('QUIT');
    fclose($fp);

    return (int)$son === 250;
}

function mail_gonder(PDO $db, string $to_email, string $konu, string $govde_html): bool
{
    $host      = mail_ayar_oku($db, 'smtp_host');
    $port      = (int)mail_ayar_oku($db, 'smtp_port', '587');
    $user      = mail_ayar_oku($db, 'smtp_user');
    $pass      = mail_ayar_oku($db, 'smtp_pass');
    $from      = mail_ayar_oku($db, 'smtp_from');
    $from_name = mail_ayar_oku($db, 'smtp_from_name', 'PORTAL');

    if (!$host || !$user || !$from) return false;

    return smtp_gonder_raw($host, $port, $user, $pass, $from, $from_name, $to_email, $konu, $govde_html);
}
