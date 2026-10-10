-- ============================================================
--  Lead Merkezi — PostgreSQL Şema Referansı
--  Kurulum için kurulum.php çalıştırın (şifreyi hash'ler)
-- ============================================================

CREATE TABLE IF NOT EXISTS kullanicilar (
    id            SERIAL PRIMARY KEY,
    kullanici_adi VARCHAR(100) NOT NULL UNIQUE,
    sifre         VARCHAR(255) NOT NULL,           -- bcrypt hash
    ad_soyad      VARCHAR(150) NOT NULL,
    rol           VARCHAR(20)  NOT NULL DEFAULT 'uye', -- admin|yonetici|gorevli|uye
    email         VARCHAR(100),
    telefon       VARCHAR(20)
);

CREATE TABLE IF NOT EXISTS musteriler (
    id                 SERIAL PRIMARY KEY,
    ad_soyad           VARCHAR(200),
    ticari_unvan       VARCHAR(200),
    telefon            VARCHAR(30),
    web_sayfasi        VARCHAR(255),
    yetkili_ad_soyad   VARCHAR(200),
    eposta             VARCHAR(150),
    ili                VARCHAR(100),
    ilcesi             VARCHAR(100),
    calisma_modeli     VARCHAR(100),
    ulasim_kanali      VARCHAR(100),
    musteri_notu       TEXT,
    durum              VARCHAR(100),
    musteri_temsilcisi VARCHAR(100),
    teslimat_sureci    VARCHAR(100),
    gorevli_id         INTEGER REFERENCES kullanicilar(id) ON DELETE SET NULL,
    kayit_tarihi       TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS gruplar (
    id               SERIAL PRIMARY KEY,
    isim             VARCHAR(200) NOT NULL,
    olusturan_id     INTEGER REFERENCES kullanicilar(id) ON DELETE SET NULL,
    olusturma_tarihi TIMESTAMP   NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS grup_uyeler (
    id           SERIAL PRIMARY KEY,
    grup_id      INTEGER NOT NULL REFERENCES gruplar(id) ON DELETE CASCADE,
    kullanici_id INTEGER NOT NULL REFERENCES kullanicilar(id) ON DELETE CASCADE,
    UNIQUE (grup_id, kullanici_id)
);

CREATE TABLE IF NOT EXISTS ekip_mesajlari (
    id          SERIAL PRIMARY KEY,
    gonderen_id INTEGER   NOT NULL REFERENCES kullanicilar(id) ON DELETE CASCADE,
    alici_id    INTEGER   REFERENCES kullanicilar(id) ON DELETE CASCADE,   -- NULL = grup mesajı
    grup_id     INTEGER   REFERENCES gruplar(id) ON DELETE CASCADE,        -- NULL = birebir
    mesaj       TEXT,
    okundu      BOOLEAN   NOT NULL DEFAULT FALSE,
    tarih       TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Admin kullanıcısı otomatik oluşturmak için kurulum.php çalıştırın.
-- Giriş: admin / admin123
