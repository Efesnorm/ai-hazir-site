# AI Hazır Site – Başlangıç

Bu belge eklentiyi kuran site sahibi içindir. Geliştirme ortamı için: [README.md](../../README.md).

## Kurulum

1. **Yedek alın.** Güncellemelerden önce de sitenin dosyalarının ve veritabanının yedeğini alın.
2. Eklentiler → Yeni Ekle → **Eklenti Yükle** ile `ai-hazir-site-<sürüm>.zip` dosyasını yükleyin ve etkinleştirin.
   Gereksinimler: WordPress 6.9+, PHP 8.1+.
3. Etkinleştirmeden sonra yalnızca **AI ölçümü** açıktır. Eklentinin bütün ekranları sol menüdeki **AI Hazır Site**
   menüsündedir (1.15.0; önceki Ayarlar/Araçlar adresleri oraya yönlendirilir). Diğer özellikleri **AI Hazır Site → Ayarlar**
   ekranından açın.

Güncelleme: yeni zip dosyasını aynı yoldan yükleyin, WordPress "mevcut eklentiyi değiştir" diye sorar.
Veriler ve ayarlar korunur; veritabanı değişiklikleri yalnızca ekleme şeklindedir ve kendiliğinden uygulanır.
Bir sayfa önbelleği eklentisi kullanıyorsanız (WP Rocket, LiteSpeed Cache vb.) güncellemeden sonra önbelleği bir kez
temizleyin. 1.6.1'den beri `/ai-katalog/` ve `/llms.txt` önbelleğe alınmaz; eski kopyalar ise temizlenene kadar kalır.

## Özellik anahtarları

Tüm özellikler **AI Hazır Site → Ayarlar** ekranından açılıp kapatılır (1.9.0; Eklentiler listesinde eklentinin
satırındaki **Ayarlar** bağlantısı da oraya gider). Ekranın başındaki **Önerilen kurulum** (1.11.0) site türünüze
uygun özellikleri tek seferde açar: ürün satıcısı/üretici/dağıtıcı, ihracatçı, tur operatörü, portal ya da yalnızca
okuma hizmeti. Önce açılacakları gösterir, hiçbir şeyi kapatmaz; teklif kutusunu ayrıca kendi ekranından açarsınız. Bir özelliğin önkoşulu kapalıysa ya da açık başka bir özellik ona
bağlıysa satırında yazar; hiçbir özellik kendiliğinden açılıp kapanmaz.

| Anahtar | Ne açar | Önkoşul | Not |
| --- | --- | --- | --- |
| `measurement` | AI bot ve yönlendirme ölçümü (AI Hazır Site → AI Ölçüm) | – | Açık gelir |
| `compliance_scan` | AI uyum taraması ve puanı (AI Hazır Site → AI Uyum) | – | |
| `compliance_wizard` | AI Uyum Sihirbazı | – | Sihirbaz gereken özellikleri kendisi açar |
| `compliance_report` | AI Uyum Raporu, rozet, doğrulama sayfası | `compliance_scan` | |
| `bot_access` | robots.txt'de AI bot izinleri (AI Hazır Site → AI Bot Erişimi) | – | |
| `catalog` | AI Katalog: firma profili ve ilanlar | – | |
| `templates` | Sektör şablonları | `catalog` | |
| `schema_output` | Schema.org JSON-LD ve /ai-katalog/ | `catalog` | |
| `llms_txt` | /llms.txt ve /ai-katalog/ | `catalog` | |
| `multilingual` | Çoklu dil ([coklu-dil.md](coklu-dil.md)) | `catalog` | |
| `rest_api` | `/wp-json/aihs/v1/` | `catalog` | |
| `abilities` | Katalog yetenekleri (Abilities API) | `catalog` | |
| `mcp` | MCP sunucusu `/wp-json/aihs/mcp` ([mcp-baglanti.md](mcp-baglanti.md)) | `abilities` | |
| `a2a` | A2A kartviziti ve agent ([a2a.md](a2a.md)) | `catalog` | |
| `discovery` | Sayfalardan llms.txt ve API'ye standart bağlantılar | `llms_txt` ya da `rest_api` | Görünür satır: Firma Profili → AI keşif |
| `inquiries` | Teklif kutusu | `catalog` | KVKK uyarısıyla Teklif Kutusu ekranından açılır |
| `matching` | Eşleştirme ([eslestirme.md](eslestirme.md)) | `catalog` | |
| `portal_mode` | Portal: birçok işletme ([portal.md](portal.md)) | `catalog` | |
| `bot_cache_bypass` | AI botlarına sayfa önbelleğinden kopya sunulmaz ([entegrasyonlar.md](entegrasyonlar.md)) | – | WP Rocket / LiteSpeed Cache'e yazar |
| `catalog_sitemap` | AI Katalog site haritasında | `schema_output` ya da `llms_txt` | |
| `indexnow` | Değişiklikleri IndexNow ile Bing ve diğerlerine bildirir ([entegrasyonlar.md](entegrasyonlar.md)) | `schema_output` ya da `llms_txt` | Dışarıya istek atar (yalnızca herkese açık adresler) |
| `remote_updates` | Merkezi güncelleme ([guncelleme.md](guncelleme.md)) | – | Sunucu adresi girilmedikçe istek atılmaz |
| `telemetry` | Rapor paneline haftalık özet | – | Gönderim için ayrıca açık onay (AI Hazır Site → Güncelleme) |

Aynı ekranda **"Eklentiyi silerken tüm verilerini de sil"** seçeneği de vardır.

WP-CLI ile de açılabilir (ör. otomasyon için; önkoşulları sizin açmanız gerekir):

```bash
wp eval 'AIHazirSite\Core\Features::set( "compliance_scan", true );'
```

## Önerilen sıra

1. **AI Uyum** taramasını açın ve puanınızı görün.
2. **AI Uyum Sihirbazı** ile eksikleri tamamlayın: firma profili, ilk ilan, Schema.org, llms.txt, bot erişimi.
3. İlanlarınızı **AI Hazır Site** menüsündeki ilan ekranlarından girin; gerekiyorsa bir sektör şablonu seçin.
4. AI asistanlarının kataloğu doğrudan sorgulaması için REST, Abilities ve MCP'yi açın:
   [mcp-baglanti.md](mcp-baglanti.md).
5. Talep almak istiyorsanız **Teklif Kutusu**'nu açın.
6. **AI Uyum Raporu** ve rozet: [rapor-ve-rozet.md](rapor-ve-rozet.md).

## Eklentiyi silme

Varsayılan olarak veriler korunur. **AI Hazır Site → Ayarlar** ekranının altındaki "Eklentiyi silerken tüm verilerini
de sil" seçeneğini işaretlerseniz silmede her şey kaldırılır. WP-CLI ile de açılabilir: silmede tüm verinin (tablolar, ayarlar, ilanlar ve çevirileri, talepler, işletmeler
ve işletme yetkililerinin kullanıcı bağları, önbellekler) kaldırılması için
silmeden önce şunu çalıştırın:

```bash
wp option update aihs_delete_data_on_uninstall 1
```
