# Puanlama sürümü 4 – öneri (karar bekliyor, kod yazılmadı)

## Sorun
Pilot taramalarında (2026-09-29, `bin/tara`) eklentisiz WordPress siteleri "Makine arayüzü (REST / MCP)" kontrolünden
**20/20** aldı (voltkab, balkantrade, kuzeymakedonya, intekarglobal). Nedeni `MachineInterfaceCheck`:

| Bugün | Sonuç |
| --- | --- |
| REST: `wp-json/` keşfedilebiliyorsa 0,5 | Her WordPress sitesinde çekirdek API (`wp/v2`: yazılar, sayfalar) vardır; ürün/ilan verisi olup olmadığına bakılmaz. |
| MCP: `mcp/mcp-adapter-default-server` 200, 400, **401, 403**, 405, 406 dönerse 0,5 | Pilot sitelerin hepsinde bu sunucu var ve **kimlik doğrulama istiyor** (401). Kimliği olmayan bir AI agent'ı hiçbir veri alamaz, ama tam puan veriliyor. |

Sonuç: puan "AI agentlar bu sitenin iş verisine makine yoluyla erişebiliyor mu" sorusunu değil, "sitede WordPress 7 var mı"
sorusunu ölçüyor. Faz 3'ün **önce/sonra raporu** bu yüzden eklentinin katkısını olduğundan küçük gösterir.

## Öneri (sürüm 4)
Ağırlık aynı (20), yalnızca oranlar:

| Durum | Bugün | Öneri |
| --- | --- | --- |
| Herkese açık **veri** API'si (katalog/ürün): ör. `aihs/v1`, WooCommerce Store API `wc/store/v1` | 0,5 | 0,5 |
| Yalnızca çekirdek REST (`wp/v2`) | 0,5 | 0,25 |
| MCP: kimlik doğrulamasız `initialize` başarılı (JSON-RPC sonucu, araç listesi var) | 0,5 | 0,5 |
| MCP: uç nokta var ama 401/403 | 0,5 | 0,1 ("var ama agentlara kapalı" bulgusu) |

Kaynaklar: MCP oturum açma (`initialize`) ve kimlik doğrulama davranışı MCP şartnamesinde (2025-06-18 Streamable HTTP,
Authorization); WooCommerce Store API herkese açık ürün uç noktaları (`/wc/store/v1/products`).

## Bedeli
- `Scanner::SCORE_VERSION` 3 → 4. Bilinen sınır: puanlama sürümü değişince eski taramalarla önce/sonra karşılaştırması
  yapılmaz. **Bu yüzden karar pilot sitelerin "önce" taramalarından önce verilmeli**: şu an elimizdeki 7 tarama
  sürüm 3; sürüm 4 seçilirse `bin/tara` ile yeniden alınır (dakikalar sürer).
- Mevcut testlerde makine arayüzü oranları değişir (onay gerekir).

## Seçenekler
1. **Önerilen:** Sürüm 4'ü şimdi yap, pilot "önce" taramalarını sürüm 4 ile yeniden al.
2. Sürüm 3'te kal; raporlarda makine arayüzü satırına "iş verisi değil, çekirdek API" notu düşülür.
3. Yalnızca MCP 401/403 düzeltmesi (en bariz hata), REST aynı kalır.
