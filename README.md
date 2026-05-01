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

## Police bitis hatirlatma sistemi

Police bitisine 30 gun veya daha az kalan kayitlar icin `policy_reminder.recipient` (default: `ekin@ekinotoizmit.com`) adresine otomatik HTML mail gonderilir. Bir kayit icin ayni police donemi boyunca tek mail gider; bitis tarihi degisirse hatirlatma flag'i sifirlanir.

Iki tetikleme yolu vardir, ikisi birden kullanilabilir (ayni gun ikinci kez calismaz):

### A. Otomatik (sifir konfig)
Panel her acildiginda, son calisma tarihi bugun degilse arka planda calisir. Hangi bilgisayardan girilirse girilsin yeterlidir; kayitli kullanici sayisi onemli degil. Tek dezavantaji: hicbir kullanici bugun girmediyse o gun mail gitmez.

### B. Sunucu tarafli cron (onerilir)
Boylece kullanici hic giris yapmasa bile mail gider.

1. `panel/config.php` icinde `policy_reminder.cron_token` degerine uzun rastgele bir string atayin.
2. Hostinger panelinde **Cron Jobs** > yeni gorev:
   - Komut: `curl -s "https://panel.<domain>/cron/run.php?token=<TOKEN>"`
   - Sıklık: gunde 1 kez (ornegin 08:00).
3. Alternatif: [cron-job.org](https://cron-job.org) gibi ucretsiz harici servis ayni URL'i tetikler.

### SMTP konfigurasyonu
`panel/config.php` icinde `mail` blogu doldurulmalidir (host, port, username, password, from). Hostinger e-posta hesaplari ile kolaylikla calisir; ornek:
```php
'mail' => [
    'host'      => 'smtp.hostinger.com',
    'port'      => 465,
    'secure'    => 'ssl',
    'username'  => 'no-reply@<domain>',
    'password'  => '<smtp-password>',
    'from'      => 'no-reply@<domain>',
    'from_name' => 'DRN Panel',
],
```

### Migration
Yeni surume gectiginizde bir kez `https://panel.<domain>/install/migrate.php` adresini admin olarak ziyaret edin; eksik kolonlar (`policy_start_date`, `policy_end_date`, `policy_reminder_sent_at`) ve yeni tablolar (`service_attachments`, `cron_runs`) idempotent olarak eklenir.
