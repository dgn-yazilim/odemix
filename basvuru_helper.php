<?php
// Online başvuru evrak alanları (2026-09-23, kullanıcı talebiyle: "başvuruda 2. adım evrak listesi olsun").
// Gruplar: tuzel (Ltd./A.Ş.), sahis (şahıs işletmesi), iletisim (iletişim firmaları), galeri (galeri/otomotiv).
// Alan türleri: belge (dosya yükleme, min/max adet), bilgi (metin), not (sadece bilgilendirme satırı).
// Pasif alanlar yeni başvurularda gösterilmez. Listeler Ayarlar > Başvuru Alanları'ndan yönetilir.

const BASVURU_GRUPLARI = [
    'tuzel'    => ['label' => 'Tüzel (Ltd. Şti. ve A.Ş.)',  'kisa' => 'Tüzel'],
    'sahis'    => ['label' => 'Şahıs İşletmeleri',           'kisa' => 'Şahıs'],
    'iletisim' => ['label' => 'İletişim Firmaları',          'kisa' => 'İletişim'],
    'galeri'   => ['label' => 'Galeri ve Otomotiv Firmaları','kisa' => 'Galeri'],
];
const BASVURU_ALAN_TURLERI = ['belge' => 'Belge', 'bilgi' => 'Bilgi', 'not' => 'Not'];
const BASVURU_IZINLI_UZANTI = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
// Dosya başına sınır (MB). Form metni ve kontrol aynı yerden okur.
const BASVURU_MAKS_MB = 15;

function basvuru_tablolari_olustur(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS basvuru_alanlari (
        id        SERIAL PRIMARY KEY,
        grup      VARCHAR(20)  NOT NULL,
        ad        VARCHAR(250) NOT NULL,
        slug      VARCHAR(100) NOT NULL,
        tur       VARCHAR(10)  NOT NULL DEFAULT 'belge',
        min_adet  INTEGER      NOT NULL DEFAULT 1,
        max_adet  INTEGER      NOT NULL DEFAULT 10,
        sira      INTEGER      NOT NULL DEFAULT 0,
        aktif     BOOLEAN      NOT NULL DEFAULT TRUE,
        olusturma TIMESTAMP    NOT NULL DEFAULT NOW()
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS basvuru_belgeleri (
        id          SERIAL PRIMARY KEY,
        basvuru_id  INTEGER NOT NULL,
        alan_id     INTEGER,
        alan_adi    VARCHAR(250) NOT NULL DEFAULT '',
        dosya_adi   VARCHAR(255) NOT NULL,
        dosya_yolu  VARCHAR(500) NOT NULL,
        boyut       BIGINT,
        olusturma   TIMESTAMP NOT NULL DEFAULT NOW()
    )");
    if ((int)$db->query("SELECT COUNT(*) FROM basvuru_alanlari")->fetchColumn() === 0) {
        basvuru_alanlarini_tohumla($db);
    }
}

function basvuru_slug(string $s): string
{
    $s = strtr(mb_strtolower($s, 'UTF-8'), ['ç'=>'c','ğ'=>'g','ı'=>'i','ö'=>'o','ş'=>'s','ü'=>'u','İ'=>'i']);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim(substr($s, 0, 90), '-');
}

// Ödemeix evrak listesi görsellerindeki maddeler. 1. adımda zaten sorulan telefon / şube / SGK
// satırları listeye alınmadı.
function basvuru_alanlarini_tohumla(PDO $db): void
{
    $vergi    = ['Güncel Vergi Levhası (2025 Takvim Yılı)', 'belge', 1, 10];
    $kimlik   = ['Kimlik ön ve arka görüntüleri (varsa ortaklarınızın da)', 'belge', 2, 10];
    $yerlesim = ['Yerleşim Yeri Belgesi (son 1 aya ait, e-Devlet)', 'belge', 1, 10];
    $sirkuler = ['İmza Sirküleri', 'belge', 1, 10];
    $faaliyet = ['Güncel Faaliyet Belgesi', 'belge', 1, 10];
    $dekont   = ['Banka Dekontu (ödemenin yatacağı hesaba ait alıcı dekontu)', 'belge', 1, 10];
    $findeks  = ['Findeks Raporu', 'belge', 1, 10];
    $kimlikDg = ['Kimlik doğrulama işlemi (mobil uygulama üzerinden, size gönderilecek link ile)', 'not', 0, 0];
    $ortaklar = ['Ortakların e-posta adresi ve cep telefonu numarası (ortaklı işletmelerde)', 'bilgi', 0, 1];
    $alisSatis= ['2 adet alış ve 2 adet satış faturası', 'belge', 4, 10];
    $sozlesme = ['Sözleşme İmzalama (evraklar onaylanınca ekibimiz sizinle imza için iletişime geçer)', 'not', 0, 0];
    $listeler = [
        'tuzel' => [
            $vergi, ['Ticaret Sicil Gazetesi', 'belge', 1, 10], ['Yetkililerin ve ortakların kimlik ön/arka görüntüleri', 'belge', 2, 10],
            $yerlesim, $sirkuler, $faaliyet, ['Ödemenin aktarılacağı hesaba ait banka dekontu', 'belge', 1, 10], $kimlikDg, $findeks,
            $ortaklar, ['2 adet güncel satış faturası', 'belge', 2, 10],
        ],
        'sahis' => [
            $vergi, $kimlik, $yerlesim, ['İmza Beyannamesi', 'belge', 1, 10], $dekont, $kimlikDg, $findeks, $ortaklar,
            ['2 adet satış faturası', 'belge', 2, 10], ['Oda Sicil Kayıt Sureti', 'belge', 1, 10],
        ],
        'iletisim' => [
            $vergi, $kimlik, $yerlesim, $sirkuler, ['Güncel Faaliyet Belgesi (Ltd. Şti. ve A.Ş. için)', 'belge', 1, 10], $alisSatis, $dekont,
            ['İşletme Fotoğrafları: 3 adet iç mekân (satış yapılacak ürünler görünecek) ve 3 adet dış tabela fotoğrafı', 'belge', 6, 12],
            $sozlesme, ['95.10.02 NACE Kodu: İletişim araç ve gereçlerinin onarımı (kablosuz telefonlar, telsizler, cep telefonları, çağrı cihazları, ticari kameralar vb.) faaliyet kodunuz arasında olmalıdır', 'not', 0, 0],
        ],
        'galeri' => [
            $vergi, $kimlik, $yerlesim, $sirkuler, ['Güncel Faaliyet Belgesi (Ltd. Şti. ve A.Ş. için)', 'belge', 1, 10], $alisSatis, $dekont,
            ['Araç Satış Yetki Belgesi', 'belge', 1, 10], ['Araç ruhsatı (en az 4 adet)', 'belge', 4, 20],
            ['Galeri fotoğrafları: 3 adet iç mekân ve 3 adet dış tabela fotoğrafı', 'belge', 6, 12],
            $sozlesme, ['46.72.14 NACE Kodu: Motorlu kara taşıtlarının aksesuarlarının toptan ticareti (motosiklet aksesuarları hariç) faaliyet kodunuz arasında olmalıdır', 'not', 0, 0],
        ],
    ];
    $ins = $db->prepare("INSERT INTO basvuru_alanlari (grup, ad, slug, tur, min_adet, max_adet, sira) VALUES (?,?,?,?,?,?,?)");
    foreach ($listeler as $grup => $alanlar) {
        foreach ($alanlar as $i => [$ad, $tur, $min, $max]) $ins->execute([$grup, $ad, basvuru_slug($ad), $tur, $min, $max, $i + 1]);
    }
}

// Bir grubun alanları (varsayılan: yalnız aktif olanlar), sıraya göre
function basvuru_alanlari(PDO $db, string $grup, bool $sadeceAktif = true): array
{
    $st = $db->prepare("SELECT * FROM basvuru_alanlari WHERE grup = ?" . ($sadeceAktif ? " AND aktif = TRUE" : "") . " ORDER BY sira, id");
    $st->execute([$grup]);
    return $st->fetchAll();
}

// 1. adımdaki ortaklık yapısı + 2. adımdaki faaliyet alanı → evrak grubu
function basvuru_grubu_sec(string $ortaklik, string $faaliyet): string
{
    if ($faaliyet === 'iletisim') return 'iletisim';
    if ($faaliyet === 'galeri')   return 'galeri';
    return $ortaklik === 'Şahıs Firması' ? 'sahis' : 'tuzel';
}
