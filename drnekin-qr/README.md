## QR Kod Üreten Python Uygulaması

Bu uygulama çalıştığında bir web sayfası açar ve **QR kodu** üretir.

- **Mod 1 (`info_page`)**: QR → bu uygulamanın `/info` sayfasını açar (bilgilerinizi burada gösterirsiniz).
- **Mod 2 (`target_url`)**: QR → `target_url` alanındaki siteyi açar.

## Müşteriler Online Görsün (Önerilen Akış)

- **Render**: Sadece “bilgi sayfası host” (müşteri burayı görür)
- **Sizin PC**: QR üretir (Masaüstüne `qr.png`) ve isterseniz metni Render’a gönderir

## Yeni Sistem: Müşteri Bazlı Kalıcı QR + Geçmiş

Bu sürümle artık her **müşteri için ayrı QR** oluşturabilirsiniz. Müşteri tekrar geldiğinde, aynı QR ile:

- araç/müşteri bilgilerini
- önceki ziyaretleri
- yapılan işlemleri

görebilir.

### Admin ekranları

- Müşteri listesi/ekleme: `http://127.0.0.1:8000/admin?token=...`
- Ayarlar (eski /info vb.): `http://127.0.0.1:8000/admin/config?token=...`

### Müşteri QR link formatı

- `/c/<public_id>?k=<secret>`

Bu link, müşteri için **kalıcıdır** (siz yeni müşteri oluşturmadıkça değişmez).

Render URL'nizi `config.json` içine yazın:
- `public_base_url`: `https://SIZIN-URL.onrender.com`  (QR bunu encode eder)
- `remote_base_url`: `https://SIZIN-URL.onrender.com`
- `remote_admin_token`: Render’da Environment’a yazdığınız `ADMIN_TOKEN`
- `remote_sync_enabled`: `true`

Metni değiştirince Render'a göndermek için:
- `python sync_remote.py`

### Benim için otomatik ayarla (kolay yol)

1) `config.json` yoksa örnekten kopyalayın:
- `config.example.json` → `config.json`

2) Kurulum sihirbazı:
- `python setup_remote.py`
  - Render URL’nizi ve `ADMIN_TOKEN` değerini sorar, `config.json`’ı otomatik doldurur.

3) Metni host’a gönderme:
- `python sync_remote.py`

### Kurulum (Windows / PowerShell)

`qr-uygulama` klasörüne girin:

```powershell
cd "$env:USERPROFILE\OneDrive\Masaüstü\qr-uygulama"
python -m venv .venv
.\.venv\Scripts\Activate.ps1
pip install -r requirements.txt
```

### Çalıştırma

```powershell
.\.venv\Scripts\Activate.ps1
python .\app.py
```

Tarayıcıdan açın:

- Ana sayfa: `http://127.0.0.1:8000/`
- Admin: `http://127.0.0.1:8000/admin?token=...`

Uygulama açılırken terminale admin linkini de yazdırır.

### Bilgileri Nereden Düzenleyeceğim?

İki seçenek var:

- **Web üzerinden**: Admin sayfasından (`/admin?token=...`) başlık/metin/hedef URL düzenleyin.
- **Cursor / dosya üzerinden**: `config.json` içindeki alanları değiştirin.

### config.json Alanları

- **app_mode**: `"full"` (lokal) / `"host_only"` (Render için)
- **qr_mode**: `"info_page"` veya `"target_url"`
- **info_title**: `/info` sayfa başlığı
- **info_body**: `/info` sayfasında görünen metin (çok satır olabilir)
- **target_url**: dış site (opsiyonel)
- **append_run_id_to_target_url**: `true` ise `target_url` modunda URL’ye `rid=...` ekler
- **admin_token**: admin sayfasına giriş anahtarı (boşsa uygulama otomatik üretir)
- **db_path**: (opsiyonel) SQLite dosya yolu. Render gibi ortamlarda bunun yerine env `QR_DB_PATH` önerilir.


