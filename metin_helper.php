<?php
// İsim türü alanları Türkçe kurallarıyla büyük harfe çevirir (2026-09-24, kullanıcı talebiyle:
// "isim soyisim gibi şeyler küçük yazılsa bile büyük yazılsın"). mb_strtoupper 'i'yi 'I' yapar; önce i→İ.
function tr_buyuk(string $s): string {
    return mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], trim($s)), 'UTF-8');
}
