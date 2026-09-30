# Araştırma notu – ACP ve UCP (agent ticaret protokolleri)

Durum: **izlenecek alan**. Kod yok. Resmi belgeler 2026-10-01'de okundu (kaynaklar en altta).

## Kısa sonuç
- İki protokol de **perakende satın alma** (sepet + ödeme) için; ikisinde de **B2B, teklif (quote/RFQ) veya hizmet
  talebi kavramı yok.** Pilotlarımızın ihtiyacı olan "teklif iste" akışını karşılamıyorlar; bu alanda A2A + teklif
  kutumuz farkımız olarak kalıyor.
- **ACP bugün bize uygun değil:** ürün beslemesi herkese açık yayınlanmıyor, onaylı satıcıların OpenAI'ye SFTP ile
  gönderdiği bir dosya; katılım başvuruyla.
- **UCP teknik olarak bize en yakın olan:** açık keşif (`/.well-known/ucp`), MCP ve **A2A** bağları, ödemesiz
  "yalnızca katalog" müzakeresi mümkün. Ama Google'ın kendi yüzeyleri (AI Mode, Gemini) için Merchant Center hesabı ve
  ödeme için uygun ürün şartı var.
- **Öneri:** şimdi uygulama yok; aşağıdaki tetikleyiciler gerçekleşirse "UCP yalnızca katalog profili" denemesi.

## ACP – Agentic Commerce Protocol (resmi belgeler)
- Sahipler ve lisans: OpenAI ve Stripe ("Founding Maintainers"), Apache 2.0, katkı için CLA. Son kararlı sürüm klasörü
  `spec/2026-04-17/` (OpenAPI YAML + JSON Schema).
- Kapsam: Agentic Checkout (durumlu ödeme oturumu oluştur/güncelle/tamamla), Delegate Payment (tek kullanımlık, tutar ve
  süre sınırlı ödeme bilgisi), yetenek müzakeresi, ödeme işleyicileri, indirimler, sepet, siparişler, kimlik doğrulama,
  MCP entegrasyonu, ürün beslemesi.
- **Ürün beslemesi (OpenAI belgeleri):** "Push feeds to OpenAI via SFTP"; biçim parquet (tercihen), `jsonl.gz`,
  `csv.gz`, `tsv.gz`; en az günlük. Örnek alanlar: `item_id`, `title`, `price`, `availability`, `url`.
- **Katılım:** "Onboarding product feeds in ChatGPT is currently available to approved partners"; başvuru formu
  (chatgpt.com/merchants); gerekirse PCI uyum beyanı (AOC). Satıcı sorumluluğu satıcıda ("OpenAI … not the merchant of
  record"). Örnekler ABD odaklı; bölge listesi belgede yok.
- `/.well-known` benzeri açık keşif yok; agenticcommerce.dev SSS: keşif mekanizmaları "still being developed".
- B2B / teklif: yok.

**Bize uyumu: düşük.** Besleme herkese açık değil ve başvuru + ödeme uyumluluğu istiyor; pilotlarımız ödeme almıyor.
İleride onaylı bir satıcı olursa AI Katalog'dan ACP besleme dosyası (csv.gz/jsonl.gz) üretmek teknik olarak kolay; bugün
değeri yok.

## UCP – Universal Commerce Protocol (resmi belgeler)
- Lisans: Apache 2.0; bakımcılar MAINTAINERS.md; ayrı **uygunluk testi** deposu (`Universal-Commerce-Protocol/conformance`).
- Sürümleme tarihli (`YYYY-MM-DD`); belgelerdeki güncel sürüm `2026-08-25`.
- Roller: Platform (agent), Business (satıcı), PSP, Credential Provider.
- **Keşif:** satıcı `/.well-known/ucp` adresinde JSON profil yayınlar: `version`, `services` (zorunlu, boş olabilir),
  `capabilities`, `payment_handlers` (zorunlu), üst düzeyde `keys` (RFC 7517 JWK, HTTP Message Signatures için).
- **Taşıma bağları:** `rest` (OpenAPI 3.x şeması), `mcp` (OpenRPC, JSON-RPC 2.0), `a2a` (uç nokta =
  `/.well-known/agent-card.json`), `embedded`.
- **Yetenekler:** `dev.ucp.shopping.checkout`, `…catalog`, `…cart`, `…order`; uzantılar `discount`, `fulfillment`,
  `dev.ucp.common.identity_linking`, `…location` vb. Her yetenek ayrı müzakere edilir; **ödemesiz, yalnızca keşif
  modu mümkün.**
- **Katalog yeteneği:** işlemler `dev.ucp.shopping.catalog.search` ve `…lookup`. Ürün alanları: `id`, `title`,
  `description`, `url`, `categories`, `price_range`, `media`, `variants`…; varyant fiyatı `price.amount` **tam sayı,
  para biriminin küçük birimi** + `currency` (ISO 4217); `availability`, `seller`.
- **Google yüzeyleri:** "an active Merchant Center account", "products eligible for checkout" ve ilgi formu gerekli.
  Protokolün kendisi ise "autonomous discovery by platforms" hedefliyor (başka platformlar onaysız keşfedebilir).
- B2B / teklif: belgelerde yok.

**Bize uyumu: orta, geleceğe dönük.**
- Zaten sahip olduklarımız UCP'nin bağlarıyla örtüşüyor: MCP sunucusu (0.9.0), A2A kartviziti (1.6.0), REST + OpenAPI 3.1
  (1.18.0).
- "Yalnızca katalog" profili mümkün: `/.well-known/ucp` + `dev.ucp.shopping.catalog` (search/lookup) → mevcut ilan arama
  ve tek ilan okuma. Eşleme: yalnızca "satılan" ilanlar ürün olur (aranan/tedarik karşılığı yok); `price_min/max` →
  `price_range`, tutarlar küçük birime çevrilir, `valid_until` / stok → `availability`.
- Açık sorular (uygulamadan önce doğrulanmalı): `payment_handlers` boş olabilir mi; ödemesiz profilde `keys` (imza)
  zorunlu mu; MCP bağı için ayrı bir UCP MCP uç noktası mı gerekir (OpenRPC araç adları) yoksa mevcut sunucuya araç
  eklemek yeter mi.

## Öneri ve tetikleyiciler
- **Şimdi:** uygulama yok; bu not ve `GELISTIRME-ALANLARI.md` satırı.
- **UCP "yalnızca katalog" denemesini tetikleyecek durumlar:**
  1. Google veya başka bir platformun, ödemesiz (yalnızca katalog) UCP profillerini tükettiğine dair resmi duyuru; ya da
  2. ölçümümüzde `/.well-known/ucp` isteklerinin görülmesi (bugün izlenmiyor; ölçüme bu yolun eklenmesi ucuz bir ilk adım
     olabilir).
- **WooCommerce ile satış yapan bir pilot olursa:** sepet/ödeme için resmi entegrasyonlara (Google Merchant Center/UCP,
  Stripe/ACP) yönlendirme; eklenti ödeme yapmaz.
- Uygulanırsa: UCP uygunluk testi deposuyla doğrulama, anahtarlı ve varsayılan kapalı.

## Kaynaklar (resmi)
- ACP: https://www.agenticcommerce.dev/ · https://github.com/agentic-commerce-protocol/agentic-commerce-protocol ·
  https://developers.openai.com/commerce/llms-full.txt
- UCP: https://ucp.dev/specification/overview/ · https://ucp.dev/specification/shopping/catalog/ ·
  https://github.com/universal-commerce-protocol/ucp ·
  https://developers.googleblog.com/en/under-the-hood-universal-commerce-protocol-ucp/
