# Not – ACP ve UCP (agent ticaret protokolleri) – geliştirme alanı

Durum: **izlenecek alan / araştırma**. Kod yok; uygulanması ayrıca araştırma notu, plan ve onay ister.
Kaynak: 2026-10-01 görüşmesi. Bilgiler web aramasından (ikincil kaynaklar); resmi belgeler henüz okunmadı.

## Protokoller

| | ACP – Agentic Commerce Protocol | UCP – Universal Commerce Protocol |
| --- | --- | --- |
| Kim | OpenAI + Stripe; 29 Eylül 2025; açık standart (Apache 2.0) | Google + Shopify; 11 Ocak 2026 (NRF); ilk ortaklar Etsy, Wayfair, Target, Walmart |
| Amaç | ChatGPT içinde satın alma ("Instant Checkout"): agent ödeme seçimini alır, satıcıya dar yetkili ödeme belirteci verir, satıcı kendi ödeme sağlayıcısıyla tahsil eder, satıcı sorumluluğu satıcıda kalır | Keşiften ödemeye ve satış sonrasına tüm alışveriş yolculuğu (Google AI Mode / Gemini) |
| Gelişim | Ödeme ile başladı; ürün beslemesi, sepet, kimlik doğrulama, sipariş güncellemeleri eklendi; son kararlı sürüm 2026-04-17 (GitHub). PayPal ödeme sağlayıcısı (Ekim 2025); Stripe Agentic Commerce Suite (Aralık 2025) | Mart 2026: sepet, ürün kataloğu erişimi, kolay satıcı katılımı |
| Taşıma / ilişki | Ödeme sağlayıcısından bağımsız | REST, **MCP** ve **A2A** bağları; ödeme için AP2 (Agent Payments Protocol) |

İsim karışıklığı: IBM'in "Agent Communication Protocol" (da ACP) 2025'te A2A'ya katıldı; buradaki ACP OpenAI/Stripe'ın
ticaret protokolüdür.

## Bizimle ilişkisi

- **Doğru yöndeyiz:** UCP'nin MCP ve A2A üzerinden çalışması, MCP sunucumuzun (0.9.0), A2A agent'ımızın (1.6.0) ve
  REST + OpenAPI 3.1'in (1.18.0) aynı yolları kullandığını gösteriyor.
- **Doğrudan uygulama önerilmiyor (şimdilik):**
  1. Bizim modelimiz **teklif**, onlarınki **ödeme**: pilotlar B2B / hizmet (kablo, fabrika, turizm, hukuk); ürün
     sepete atılıp kartla ödenmiyor.
  2. Ödeme ağır sorumluluk (PCI, satıcı sorumluluğu, iade, vergi). WooCommerce ile satan sitelerde bu iş Stripe,
     PayPal ya da WooCommerce'in resmi entegrasyonlarının; eklentinin tekerleği yeniden icat etmesi yanlış.
  3. Coğrafya: Instant Checkout ve Google'ın agent alışverişi ilk olarak ABD'de; Türkiye ve Balkanlar'da kullanılabilirlik
     doğrulanmadı.

## Değer üretebileceğimiz yerler (araştırılacak)
1. **Katalog beslemesi:** iki protokolün de ürün kataloğu/besleme bölümü var. İlanları bu biçimlerde yayınlamak agentların
   kataloğu ticaret protokolüyle okumasını sağlar. Önce: herkese açık besleme mi, başvuruyla katılınan program mı?
2. **Uyum taraması yönlendirmesi:** site WooCommerce ile satış yapıyorsa "ACP/UCP için resmi entegrasyonlar mevcut"
   önerisi (sepet/ödeme bizde değil).
3. **Teklif akışı eşlemesi:** UCP'de (ya da ACP'de) B2B / teklif (quote) akışı var mı? Varsa teklif kutumuz o protokolle
   de konuşabilir.

## Sonraki adım (onay gerekirse)
Resmi belgeleri (ACP GitHub 2026-04-17 sürümü, UCP belgeleri) okuyup 1–3 için kanıtlı kısa araştırma notu; sonuç bu
dosyaya ve `GELISTIRME-ALANLARI.md`'ye işlenir.

## Kaynaklar (ikincil)
- ACP: https://stellagent.ai/insights/openai-acp-agentic-commerce-protocol,
  https://www.fynd.com/blog/agentic-commerce-protocol-acp-what-it-is-and-how-it-works,
  https://eco.com/support/en/articles/14845478-acp-agentic-commerce-protocol-explained
- UCP: https://stellagent.ai/insights/google-ucp-open-rails-agentic-commerce,
  https://www.moin.ai/en/chatbot-wiki/universal-commerce-protocol,
  https://forkast.news/glossary/universal-commerce-protocol-ucp/
