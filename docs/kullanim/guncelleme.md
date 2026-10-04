# Merkezi güncelleme ve rapor paneli (1.3.0)

## Otomatik güncelleme (1.25.0)

En kolay yol: **Booster AI** (Ayarlar ekranı) güncelleme sunucusu olarak eklentinin GitHub sürümlerini yazar ve
WordPress'in kendi **eklenti otomatik güncellemesini** bu eklenti için açar. Her yeni sürüm etiketlendiğinde GitHub
Actions testleri yeniden çalıştırır, zip'i ve güncelleme bildirimini yayınlar:

```
https://github.com/Efesnorm/ai-hazir-site/releases/latest/download/ai-hazir-site.json
```

WordPress günde iki kez denetler ve yeni sürümü kendisi kurar. **Güncelleme** ekranında kanal seçilebilir: *pilot*
yeni sürümü hemen, *genel* 48 saat sonra alır (önerim: bir site pilot, diğerleri genel). Bir önceki sürüme dönmek
aynı ekrandan tek tıkla yapılır. Otomatik güncellemeyi kapatmak için Eklentiler ekranında "Otomatik güncellemeyi
devre dışı bırak" bağlantısını kullanın.

## Güncelleme sunucusu

`remote_updates` anahtarı açıkken eklenti, güncellemeleri kendi sunucumuzdan alır ve WordPress'in standart
**Güncellemeler** ekranında gösterir. Sunucu adresi verilmezse hiçbir istek atılmaz.

```bash
wp eval 'AIHazirSite\Core\Features::set( "remote_updates", true );'
```

Adres **AI Hazır Site → Güncelleme** ekranından ya da `wp-config.php` içinde verilir (sabit, ayarı geçersiz kılar):

```php
define( 'AIHS_UPDATE_SERVER', 'https://guncelleme.ornek.com/ai-hazir-site.json' );
```

### Bildirim dosyası (sunucuda)

Alan adları yaygın `wp-update-server` / Plugin Update Checker biçimindedir; kanarya ve geri alma için `releases`
listesi kullanılır. Her sürüm için `php bin/paketle` bir kayıt üretir (`dist/ai-hazir-site-<sürüm>.release.json`);
`download_url` ve `released_at` yayında düzeltilir.

```json
{
  "slug": "ai-hazir-site",
  "releases": [
    {
      "version": "1.3.0",
      "download_url": "https://guncelleme.ornek.com/ai-hazir-site-1.3.0.zip",
      "released_at": "2026-10-01T10:00:00Z",
      "db_version": 1200,
      "requires": "6.9",
      "requires_php": "8.1",
      "tested": "7.1",
      "sections": { "changelog": "<p>…</p>" }
    },
    { "version": "1.2.0", "download_url": "https://…/ai-hazir-site-1.2.0.zip", "released_at": "2026-09-28T00:00:00Z", "db_version": 1200 }
  ]
}
```

Paketler yalnızca `https` ile sunulur; kurallara uymayan kayıt yok sayılır.

### Kanarya

Her site ayar ekranında bir kanal seçer:

- **Pilot:** yeni sürümü yayın anında görür.
- **Genel:** yeni sürümü `released_at` zamanından **48 saat sonra** görür.

Pilot sitelerde sorun çıkarsa bildirimden sürüm kaldırılır ve genel siteler onu hiç görmez.

### Önceki sürüme dönüş

**AI Hazır Site → Güncelleme → Önceki sürüme dön** tek işlemdir. İki adımda yürür:

1. Veritabanı, önceki sürümün şemasına iner: yeni geçişlerin `down()` işlemi en yeniden başlanarak çalışır.
2. Önceki paket, WordPress'in kendi yükleyicisiyle kurulu eklentinin üzerine kurulur.

Kurulum başarısız olursa, çalışmaya devam eden yeni kod bir sonraki yüklemede şemayı yeniden ileri taşır; site
çalışmaya devam eder. **Önce yedek alın.**

## Rapor paneline haftalık özet

`telemetry` anahtarı açık **ve** site sahibi ayar ekranında **açık onay** verdiyse, sitenin haftalık özeti rapor
paneli adresine gönderilir. Onay ekranında gönderilecek verinin tam önizlemesi vardır.

Gönderilen alanlar:

- `site_id`: rastgele üretilir, site adresinden türetilmez.
- `sent_at`
- `score` ve `score_version`
- `ai_bot_reads_7d` ve `ai_bot_reads_verified_7d`
- `inquiries_7d`
- `plugin_version` ve `wp_version`

İlan metni, site adresi, e-posta, IP ya da kişisel veri gönderilmez. Onay istenildiği an geri alınır; gönderim
hemen durur. Bildirim metni değişirse (örneğin yeni bir alan eklenirse) yeniden onay istenir.

Panel adresi ayar ekranından ya da `define( 'AIHS_TELEMETRY_URL', 'https://…' );` ile verilir.
Panelin kendisi (özetleri toplayan sunucu uygulaması) bu eklentinin dışındadır.
