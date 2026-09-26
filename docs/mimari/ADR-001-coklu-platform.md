# ADR-001: Çoklu platform – platformdan bağımsız çekirdek

- **Durum:** Kabul edildi (2026-09-27)
- **Karar veren:** Ürün sahibi
- **İlgili:** [PRD](../PRD.md), [Görev 02](../gorevler/02-cekirdek-ayirma.md)

## Bağlam

Ürün ilk olarak WordPress eklentisi olarak geliştiriliyor (PRD). Hedef, WordPress dışındaki yaygın
platformlarda da çalışmak: Türkiye'deki B2B ve e-ticaret KOBİ'lerinin önemli bir kısmı Ticimax,
IdeaSoft, ikas, Shopify gibi SaaS platformlarında.

Platformların verdiği erişim farklıdır:

| Platform türü | Örnek | Sunucu isteğini görme (A0 ölçümü) | Özel adres (`/llms.txt`) | Veri yayını (JSON-LD, MCP) |
|---|---|---|---|---|
| Açık PHP sistemleri | WordPress, Joomla, PrestaShop, OpenCart | Tam | Var | Var |
| Uygulama mağazalı SaaS | Shopify, ikas | Kısmi (app proxy) | Kısmi | Var |
| Kapalı site kurucular | Wix, Squarespace, Ticimax | Yok | Yok / kısmi | Yalnızca kod ekleme |

Kapalı platformlarda ölçüm ve özel adresler ancak uç sunucu (DNS'i bir edge katmanından geçirmek) veya
merkezi bir servisle mümkündür.

## Karar

1. **MVP ve pilot ağ WordPress'te kalır.** PRD takvimi ve "önce/sonra" ölçümü değişmez.
2. **Çekirdek şimdiden platformdan bağımsız yazılır.** `src/Core` altındaki kod WordPress'e (ve hiçbir
   platforma) doğrudan bağlı olmaz; ayarlar, önbellek, HTTP, saat ve sayaç deposu küçük arayüzlerin
   (`src/Core/Contracts`) arkasındadır. Her platform bu arayüzleri uygulayan ince bir adaptördür
   (`src/WordPress`, ileride `src/<Platform>`). Bu kural otomatik bir testle korunur.
3. **İkinci platform veriyle seçilir.** Pilot ağdaki ve hedef müşterilerdeki sitelerin platform dağılımı
   sayıldıktan sonra karar verilir. Mevcut tahmin: WooCommerce'ten (WordPress) sonra ikas veya Shopify.
4. **Merkezi servis (SaaS) ayrı bir ürün kararıdır.** Kapalı platformların kapısını açar ama iş modelini,
   KVKK sorumluluğunu ve altyapı maliyetini değiştirir. Bu ADR kapsamında değildir.

## Katmanlar

```
src/Core/          Platformdan bağımsız: iş kuralları ve arayüzler (Contracts)
src/WordPress/     WordPress adaptörü: kancalar, seçenekler, $wpdb, yönetim sayfası, WP-CLI
src/Adapters/      AI kanalları (MCP, llms.txt, ...) – çekirdeği sadece okur
```

- Çekirdek hiçbir platform katmanını bilmez; bağımlılık yönü her zaman platform → çekirdek.
- AI kanal adaptörleri de çekirdek arayüzleri üzerinden çalışır; böylece bir kanal (ör. MCP) başka bir
  platformda yeniden yazılmadan kullanılabilir. Platforma özgü kanallar (ör. WordPress Abilities API)
  platform katmanında kalır.

## Sonuçlar

- (+) Yeni platform = yeni adaptör; iş kuralları ve testleri yeniden yazılmaz.
- (+) Çekirdek WordPress olmadan, hızlı birim testleriyle uçtan uca sınanabilir.
- (−) Kısa vadede ek bir soyutlama katmanı ve bir defalık yeniden düzenleme (Görev 02).
- (−) Çekirdek PHP'dedir; PHP çalıştırmayan platformlar (Shopify, Wix) için çekirdek ya merkezi serviste
  çalışır ya da başka bir dile taşınır. Bu karar ikinci platform seçilirken verilir.
