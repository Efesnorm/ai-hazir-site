# AI Uyum Raporu ve AI Hazır rozeti

`compliance_report` anahtarı açıkken kullanılır. Raporun dolması için AI uyum taramasının
(`compliance_scan`) en az bir kez çalışmış olması gerekir.

```bash
wp eval 'AIHazirSite\Core\Features::set( "compliance_report", true );'
```

## Rapor

**AI Hazır Site → AI Uyum Raporu** sayfası şunları gösterir:

- **Özet:** son tarama puanı ve tarihi; ilk tarama puanı ve aradaki fark.
- **Kontroller:** yedi kriterin her biri için ağırlık, ilk ve son puan, değişim ve kalan kazanım.
  Puanlama yöntemi değiştiyse (farklı puan sürümü) önce/sonra karşılaştırması yapılmaz, bu belirtilir.
- **Son 28 günün AI ölçümü:** botlar, en çok okunan sayfalar, AI yönlendirmeleri, AI dosyaları (llms.txt vb.)
  ve MCP çağrıları.
- **Açık yetenekler:** o anda açık olan özellikler.

**PDF indir** düğmesi aynı içeriği A4 PDF olarak verir (Türkçe karakterler gömülü yazı tipiyle).
Rapordaki her sayı doğrudan kayıtlı tarama ve ölçüm verisinden gelir; ayrı bir hesap yoktur.

"İlk tarama", eklentinin kaydettiği ilk taramadır. 1.0.0'dan önce tarama yapılmışsa, saklanan
geçmişteki en eski tarama kullanılır.

### Semantik yapı (1.19.0, puana dahil değil)

Tarama, örnek sayfalarda sayfa dilini (`<html lang>`), başlığını (`<title>`), tek ana içerik alanını (`<main>`), tek
`<h1>`'i ve başlık seviyelerinin atlanmadığını denetler (WCAG 2.2 ve HTML standardı). Bulgular AI Uyum ekranında ve
raporda "Semantik yapı (öneri, puana dahil değil)" başlığıyla gösterilir; puanı değiştirmez. Düzeltme tema ve sayfa
oluşturucu tarafındadır: ana içerik alanını `<main>` olarak işaretleyen bir tema/ayar kullanın, her sayfada tek `<h1>`
olsun, başlıkları görünüş için değil sıraya göre seçin (h2'den sonra h3).

## Rozet

Son taramanın puanı eşiğe (varsayılan **70**) ulaşınca "AI Hazır" rozeti gösterilir. Eşiğin altında,
tarama yokken veya özellik kapalıyken hiçbir şey gösterilmez (kısa kodun kendisi de görünmez).
1.12.1'den beri rozet için ayrıca kontrollerin ağırlığının **en az %80'i ölçülmüş** olmalıdır: puan yalnızca
ölçülebilen kontrollerin üzerinden hesaplandığı için, çoğu kontrolü ölçülemeyen bir tarama yüksek puan gösterip yanıltıcı
bir rozet üretmesin. Tarama ekranı bu durumda uyarı gösterir.

- **Blok:** düzenleyicide "AI Hazır rozeti" bloğunu ekleyin (Widget'lar grubu).
- **Kısa kod:** `[aihs_rozet]`

Rozet puanı ve tarama tarihini gösteren bir SVG'dir ve sitenin kendi doğrulama sayfasına bağlanır.

Eşiği değiştirmek için (ör. tema `functions.php` dosyasında veya bir eklentide):

```php
add_filter( 'aihs_badge_threshold', fn() => 80 );
```

## Doğrulama sayfası

**https://siteniz.com/ai-hazir-dogrulama/** adresi, rozete tıklayan herkese site adını, son puanı, tarama
tarihini ve kontrol bazında durumu gösterir. Puan eşiğin altına düşerse sayfa "şu anda AI Hazır kriteri
karşılanmıyor" der.

Sayfa adresi çalışmıyorsa (404) Ayarlar → Kalıcı Bağlantılar sayfasını açıp **Değişiklikleri kaydet**'e basın.

Doğrulama, sitenin kendi taramasına dayanır; bağımsız bir kuruluşun onayı değildir.
