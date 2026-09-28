# AI Hazır Site – Başlangıç

Bu belge eklentiyi kuran site sahibi içindir. Geliştirme ortamı için: [README.md](../../README.md).

## Kurulum

1. **Yedek alın.** Güncellemelerden önce de sitenin dosyalarının ve veritabanının yedeğini alın.
2. Eklentiler → Yeni Ekle → **Eklenti Yükle** ile `ai-hazir-site-<sürüm>.zip` dosyasını yükleyin ve etkinleştirin.
   Gereksinimler: WordPress 6.9+, PHP 8.1+.
3. Etkinleştirmeden sonra yalnızca **AI ölçümü** açıktır. Diğer her özellik bir anahtarla açılır.

Güncelleme: yeni zip dosyasını aynı yoldan yükleyin, WordPress "mevcut eklentiyi değiştir" diye sorar.
Veriler ve ayarlar korunur; veritabanı değişiklikleri yalnızca ekleme şeklindedir ve kendiliğinden uygulanır.
Bir sayfa önbelleği eklentisi kullanıyorsanız (WP Rocket, LiteSpeed Cache vb.) güncellemeden sonra önbelleği bir kez
temizleyin. 1.6.1'den beri `/ai-katalog/` ve `/llms.txt` önbelleğe alınmaz; eski kopyalar ise temizlenene kadar kalır.

## Özellik anahtarları

| Anahtar | Ne açar | Nereden açılır |
| --- | --- | --- |
| `measurement` | AI bot ve yönlendirme ölçümü (Araçlar → AI Ölçüm) | Açık gelir; AI Ölçüm sayfasından kapatılır |
| `compliance_scan` | AI uyum taraması ve puanı (Araçlar → AI Uyum) | WP-CLI ya da sihirbaz |
| `compliance_wizard` | AI Uyum Sihirbazı | WP-CLI |
| `catalog` | AI Katalog: firma profili ve ilanlar | WP-CLI ya da sihirbaz |
| `templates` | Sektör şablonları | WP-CLI |
| `bot_access` | robots.txt'de AI bot izinleri (Araçlar → AI Bot Erişimi) | WP-CLI ya da sihirbaz |
| `schema_output` | Schema.org JSON-LD ve /ai-katalog/ | WP-CLI ya da sihirbaz |
| `llms_txt` | /llms.txt ve /ai-katalog/ | WP-CLI ya da sihirbaz |
| `rest_api` | `/wp-json/aihs/v1/` | WP-CLI |
| `abilities` | Katalog yetenekleri (Abilities API) | WP-CLI |
| `mcp` | MCP sunucusu `/wp-json/aihs/mcp` | WP-CLI |
| `inquiries` | Teklif kutusu | AI Katalog → Teklif Kutusu (uyarı ve onayla) |
| `compliance_report` | AI Uyum Raporu, rozet, doğrulama sayfası | WP-CLI |
| `multilingual` | Çoklu dil: çeviriler ve dile göre çıktılar ([coklu-dil.md](coklu-dil.md)) | WP-CLI |
| `portal_mode` | Portal: birçok işletme, işletme yetkilileri ([portal.md](portal.md)) | WP-CLI |
| `remote_updates` | Merkezi güncelleme, kanarya, önceki sürüme dönüş ([guncelleme.md](guncelleme.md)) | WP-CLI |
| `matching` | Eşleştirme: aranan ↔ satılan/tedarik ([eslestirme.md](eslestirme.md)) | WP-CLI |
| `a2a` | A2A kartviziti ve agent, onaylı giden teklif isteği ([a2a.md](a2a.md)) | WP-CLI |
| `discovery` | Sayfalardan llms.txt ve API'ye standart bağlantılar; isteğe bağlı görünür satır | AI Katalog → Firma Profili → AI keşif |
| `telemetry` | Rapor paneline haftalık özet (ayrıca açık onay gerekir) | WP-CLI + Ayarlar → AI Hazır Güncelleme |

WP-CLI ile açma (örnek: tarama, sihirbaz ve rapor):

```bash
wp eval 'foreach ( array( "compliance_scan", "compliance_wizard", "compliance_report" ) as $k ) { AIHazirSite\Core\Features::set( $k, true ); }'
```

Kapatmak için `true` yerine `false` yazın. WP-CLI'ye erişiminiz yoksa barındırma firmanızdan destek isteyin
(genel bir ayar ekranı henüz yok; bkz. [bilinen sınırlar](../bilinen-sinirlar.md)).

## Önerilen sıra

1. **AI Uyum** taramasını açın ve puanınızı görün.
2. **AI Uyum Sihirbazı** ile eksikleri tamamlayın: firma profili, ilk ilan, Schema.org, llms.txt, bot erişimi.
3. İlanlarınızı **AI Katalog** menüsünden girin; gerekiyorsa bir sektör şablonu seçin.
4. AI asistanlarının kataloğu doğrudan sorgulaması için REST, Abilities ve MCP'yi açın:
   [mcp-baglanti.md](mcp-baglanti.md).
5. Talep almak istiyorsanız **Teklif Kutusu**'nu açın.
6. **AI Uyum Raporu** ve rozet: [rapor-ve-rozet.md](rapor-ve-rozet.md).

## Eklentiyi silme

Varsayılan olarak veriler korunur. Silmede tüm verinin (tablolar, ayarlar, ilanlar ve çevirileri, talepler, işletmeler
ve işletme yetkililerinin kullanıcı bağları, önbellekler) kaldırılması için
silmeden önce şunu çalıştırın:

```bash
wp option update aihs_delete_data_on_uninstall 1
```
