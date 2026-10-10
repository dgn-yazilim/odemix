<?php
// "Beni Hatırla" cookie yönetimi

function hatirla_cookie_set(PDO $db, int $uid): void {
    $token   = bin2hex(random_bytes(32));
    $hash    = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
    $db->prepare("UPDATE kullanicilar SET hatirla_token = ?, hatirla_expires = ? WHERE id = ?")
       ->execute([$hash, $expires, $uid]);
    setcookie('hatirla_beni', $uid . ':' . $token, [
        'expires'  => time() + 30 * 86400,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function hatirla_cookie_clear(PDO $db, int $uid): void {
    $db->prepare("UPDATE kullanicilar SET hatirla_token = NULL, hatirla_expires = NULL WHERE id = ?")
       ->execute([$uid]);
    setcookie('hatirla_beni', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
}

function hatirla_cookie_kontrol(PDO $db): ?array {
    $cookie = $_COOKIE['hatirla_beni'] ?? '';
    if (!$cookie || !str_contains($cookie, ':')) return null;
    [$uid, $token] = explode(':', $cookie, 2);
    $uid  = (int)$uid;
    $hash = hash('sha256', $token);
    $stmt = $db->prepare("SELECT * FROM kullanicilar WHERE id = ? AND hatirla_token = ? AND hatirla_expires > NOW()");
    $stmt->execute([$uid, $hash]);
    return $stmt->fetch() ?: null;
}
