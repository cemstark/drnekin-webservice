# DRN Servis Paneli

Bu klasor `panel.<domain>` subdomain'i icin hazirlanmistir. Hostinger hPanel'de subdomain document root olarak bu `panel` klasorunu secin ya da dosyalari subdomain'in kok dizinine yukleyin.

## Kurulum

1. Hostinger hPanel'de MySQL veritabani olusturun.
2. `panel/config.example.php` dosyasini `panel/config.php` olarak kopyalayin.
3. `config.php` icindeki veritabani bilgilerini ve `api_key` degerini degistirin. `base_path` varsayilan olarak otomatik algilanir.
4. `https://panel.<domain>/setup.php` adresinden ilk admin kullanicisini olusturun. Bu ekran veritabani tablolarini otomatik olusturmayi dener.
5. `https://panel.<domain>/login.php` adresinden giris yapin.

## Excel kolonlari

Ilk satir baslik satiri olmalidir. Desteklenen temel kolonlar:

- `Kayit No`
- `Plaka`
- `Ad Soyad`
- `Sigorta Sirketi`
- `Tamir Durumu`
- `Mini Onarim`
- `Mini Onarim Parca`
- `Servise Giris Tarihi`
- `Servisten Cikis Tarihi`

`Kayit No`, `Plaka`, `Ad Soyad` ve `Servise Giris Tarihi` zorunludur. Ayni `Kayit No` tekrar import edilirse paneldeki kayit guncellenir.

## Import API

Windows sync ajani su adrese `.xlsx` dosyasi gonderir:

```text
POST https://panel.<domain>/api/import.php
Header: X-Panel-Api-Key: config.php icindeki api_key
Form field: excel
```
