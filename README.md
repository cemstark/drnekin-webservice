# DRN Ekin Webservice

Bu repo, `panel.<domain>` alt domaininde calisacak servis takip panelini ve bilgisayardaki Excel dosyasini panele senkronlayan Windows ajanini icerir.

## Klasorler

- `panel/`: PHP + MySQL servis takip paneli.
- `sync-agent/`: Excel dosyasi kaydedildikce panele gonderen Windows/Python ajan.

## Hizli kurulum

1. Hostinger'da alt domain olusturun.
2. Alt domain document root olarak mumkunse `panel` klasorunu secin. Secemiyorsaniz repo kokunu yukleyin; kok `index.php` otomatik `panel/` klasorune yonlendirir.
3. Hostinger'da MySQL veritabani olusturun.
4. `panel/config.example.php` dosyasini `panel/config.php` olarak kopyalayip veritabani bilgilerini ve `api_key` degerini doldurun.
5. `https://panel.<domain>/setup.php` ile ilk admin kullanicisini olusturun. Bu ekran veritabani tablolarini otomatik olusturmayi dener.
6. `sync-agent/.env.example` dosyasini `sync-agent/.env` olarak kopyalayip Excel yolu, API URL, UPDATES URL ve API anahtarini doldurun.
7. Bilgisayarda `sync-agent/install_windows_task.ps1` script'i ile ajanı Windows acilisinda calisacak sekilde kurun.
