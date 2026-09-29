# Önce/sonra raporu

Faz 3 kapı şartının ikinci yarısı. Her pilot site için, eklenti özellikleri açılmadan önceki dönem ile sonraki dönem
**aynı yöntemlerle** karşılaştırılır.

## Kaynaklar
| Ölçüt (PRD) | Kaynak | Önce | Sonra |
| --- | --- | --- | --- |
| AI bot ziyaretleri (haftalık) | Araçlar → AI Ölçüm (doğrulanmış / doğrulanmamış, bota göre) | ölçüm dönemi (yalnızca ölçüm açık) | özellikler açıldıktan sonra aynı süre |
| AI platformlarından gelen insanlar | AI Ölçüm → "AI platformlarından gelen insan ziyaretleri" | aynı | aynı |
| AI dosyalarının okunması | AI Ölçüm → llms.txt, AI katalog, MCP çağrıları | yok (dosya yok) | sayılar |
| AI uyum puanı | `bin/tara` (eklenti kurulmadan) ve Araçlar → AI Uyum | kurulum öncesi | kurulum sonrası |
| AI cevaplarında görünme | [haftalik-10-soru.md](haftalik-10-soru.md) | ölçüm dönemi haftaları | sonraki haftalar |
| AI kaynaklı talep | Teklif Kutusu (kaynak: AI agent) | – | sayı |

Dikkat:
- **Önbellek:** Önbellek eklentisi olan sitelerde "AI botlarına önbellekten sayfa sunma" açılmadan bot sayıları eksik
  çıkar. Adil karşılaştırma için bu entegrasyon **ölçüm döneminin başında** açılmalıdır (yalnızca ölçüme yarar, AI'a
  yeni veri açmaz).
- **Puanlama sürümü:** Karşılaştırılan iki taramanın puanlama sürümü aynı olmalı (raporda yazar).
- **Mevsim ve kampanya:** Aynı uzunlukta ve mümkünse ardışık dönemler; özel kampanya haftaları not edilir.

## Rapor iskeleti (site başına)
1. Site, tür, kurulum tarihi, açılan özellikler.
2. AI uyum puanı: önce … → sonra … (kontrol bazında tablo, `bin/tara` raporlarından).
3. AI bot ziyaretleri: haftalık ortalama, bota göre, doğrulanmış oran.
4. AI dosyalarının okunması ve MCP / A2A çağrıları.
5. 10 soru testi: görünme oranı önce → sonra, asistana göre.
6. AI kaynaklı talepler.
7. Sorunlar ve öğrenilenler.

Müşteriye verilecek PDF için Araçlar → AI Uyum Raporu (U4) kullanılır; bu belge ağ geneli iç rapor içindir.
