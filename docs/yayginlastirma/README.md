# Faz 3 – Pilot sitelere yaygınlaştırma

PRD kapı şartı: **10 sitede sorunsuz çalışma ve ilk önce/sonra raporu.** Bu klasör her sitede aynı adımların
izlenmesi, sonuçların aynı yöntemle ölçülmesi için hazırlandı.

| Belge | Ne için |
| --- | --- |
| [siteler.md](siteler.md) | Pilot siteler, türleri, önerilen kurulum profili, durum |
| [kontrol-listesi.md](kontrol-listesi.md) | Bir siteye kurulumun adım adım listesi (kopyalanıp doldurulur) |
| [haftalik-10-soru.md](haftalik-10-soru.md) | Haftalık 10 soruluk AI testi (PRD başarı ölçütü) |
| [once-sonra-raporu.md](once-sonra-raporu.md) | Önce/sonra raporunun nasıl hazırlanacağı |
| `raporlar/` | `bin/tara` ile alınmış tarama raporları (site-tarih.md) |

## Süreç (her site için)

1. **Ön tarama (kurulumdan önce):** `php bin/tara https://site --md=docs/yayginlastirma/raporlar`. Eklentinin kendi
   puanlamasıyla, eklenti kurulmadan alınan "önce" puanı ve kurulum ön kontrolü (WordPress/PHP, önbellek, SEO ve
   çoklu dil eklentileri, sunucu bot koruması).
2. **Önce ölçümü:** PRD'ye göre eklentisiz en az 3 haftalık veri. Eklenti kurulunca yalnızca AI ölçümü açık gelir;
   bu, "önce" dönemidir. Diğer özellikleri ölçüm dönemi bitince açın.
3. **Yedek ve test kopyası:** Canlıdan önce test kopyasında (staging) deneyin.
4. **Kurulum:** zip → Eklentiler → Eklenti Yükle. **Ayarlar → AI Hazır Site** → site türüne uygun **önerilen kurulum**.
5. **Veri:** Firma profili (sektör şablonu), ilanlar. Kişisel veri girilmez.
6. **Entegrasyonlar:** Önbellek eklentisi varsa "AI botlarına önbellekten sayfa sunma", site haritasına AI Katalog,
   IndexNow.
7. **Doğrulama:** `bin/tara` yeniden; llms.txt, `/ai-katalog/`, REST, MCP, A2A kartı dışarıdan açılıyor mu.
8. **İzleme:** Haftalık AI Ölçüm ve 10 soru testi; ilk ay sonunda önce/sonra raporu.

## Kurulum sırası (PRD)
1. Kablo dağıtıcı (ilk pilot; her artım önce burada), 2. kablo fabrikası (A2A demosunun ikinci ucu),
3. ihracat firması, 4. turizm portalı ve turizm firması, 5. hukuk firması (yalnızca okuma, metni firma onaylar).

## Öğrenilenler (makedonya.tr denemesi, 1.6.1–1.10.0)
- Özellikleri açmak için WP-CLI gerekmiyor (1.9.0 Ayarlar ekranı).
- WP Rocket gibi önbellek eklentileri AI botlarını ölçümden gizler; "AI botlarına önbellekten sayfa sunma" açılmalı
  (açılınca doğrulanmış GPTBot ve OAI-SearchBot istekleri görünmeye başladı).
- Barındırma hız sınırı (makedonya.tr: ~20 istekten sonra 1 saat 429) botları da etkileyebilir; barındırma firmasından
  AI bot ve `/llms.txt`, `/wp-json/aihs/`, `/.well-known/` istisnası istenir.
- AI agentlar sitenin alt adreslerini kendiliğinden açmıyor; keşif (1.7.0), site haritası (1.8.0) ve IndexNow
  (1.10.0) bunun için. Agent'a adres verilince (GPT denemesi) tüm kanallar doğru kullanıldı.
- Agent raporlarına değil ölçüme güvenin: iki agent siteyi açmadan uydurma rapor yazdı.
