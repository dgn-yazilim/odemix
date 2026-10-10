# Kurulum — Başvurular portalını portal.odemeix.com içine alma

**Amaç:** Başvurular portalını (müşteri havuzu / demo / başvuru) **portal.odemeix.com içinde, tam bağımsız** sunmak.
Arayüz ve işlev **birebir aynı** kalır. Kurulum doğrulandıktan sonra eski portal.odemeix.net.tr silinir.

## Önemli
- Portal kodu değişmedi → arayüz birebir aynı (müşteri havuzu + aşama çizelgesi).
- Veri: portalın kendi veritabanı (`portal_odemeix`).
- Bağımsızlık doğrulandı: net.tr klasörü kaldırıldığında bile portal açılıyor.

## Adımlar (portal.odemeix.com kod tabanının olduğu yerde)

```bash
# 1) Portalın GERÇEK kopyası (symlink değil), API'nin içine
cp -R <portal-klasoru> <api-koku>/basvurular-portal

# 2) portal.env dosyasını kopyanın bir ÜST klasörüne (API kökü) koy — web dışında kalsın
#    config.php, dirname(__DIR__)/portal.env okur.
#    İçerik: DB_* (canlı portal DB'si) + PANEL_SSO_SECRET + PANEL_SSO_KULLANICI
cp portal.env <api-koku>/portal.env

# 3) Serving'i kopyaya yönelt (net.tr'ye DEĞİL)
rm -f <api-koku>/public/basvurular
ln -s ../basvurular-portal <api-koku>/public/basvurular

# 4) Doğrula
#    portal.odemeix.com/basvurular açılmalı; panelde Başvurular → Müşteriler müşteri havuzunu (veriyle) göstermeli

# 5) SONRA eski portalı sil
#    /var/www/portal.odemeix.net.tr (+ varsa ayrı vhost/deploy)
```

## Notlar
- `portal.env` **web'den okunmamalı** (API kökünde, public dışında — güvenli).
- Panelde `navigation.php` → "Başvurular" zaten `/applications` (iframe ile `/basvurular`'ı açar); **değişiklik gerekmez.**
- "symlink değil" isteniyorsa: içerik **gerçek kopya**; `public/basvurular` yalnız o kopyaya işaret eden bağdır (net.tr'ye değil). İstenirse düz klasör de olabilir; o durumda `portal.env` yolunu güvenli tutmaya dikkat.
- Canlıda `www-data:www-data` sahiplik + doğru izinler; `.env`/`portal.env` nginx'te engellenmiş olmalı.
- Depoda **yok:** `uploads/` (veri — sunucuda var), `.user.ini` (canlıyı kilitleyebilir), sabit sır.

## Bağımsızlık testi
net.tr klasörünü geçici kenara al → portal hâlâ açılıyorsa kopya bağımsızdır. Sonra net.tr silinebilir.
