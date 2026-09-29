# Onay listesi – 2026-09-29 gecesi

Gece hedefi: TranslatePress desteği, `AbilitiesTest` zamanlama hatası, A2A iki site demosu, Faz 3 yaygınlaştırma
altyapısı. Hepsi yapıldı; hiçbiri `main`'e birleştirilmedi, GitHub'a gönderilmedi. Dallar üst üste dizili
(her biri öncekinin üzerinde); onaylananlar sırayla birleştirilir, her biri CI'dan geçip etiketlenir.

| Sıra | Dal | Sürüm | İçerik | Eklenti kodu | Onay gereken |
| --- | --- | --- | --- | --- | --- |
| 1 | `indexnow-1.10.0` | 1.10.0 | IndexNow (dün akşam, planı ve 3 test değişikliği onaylı) | değişti | Birleştirme onayı |
| 2 | `duzeltme-hiz-siniri-testi` | – | `AbilitiesTest` ve `RestCachingTest` dakika dönümünde kırılmasın | **değişmedi** (yalnızca test) | Mevcut test değişikliği |
| 3 | `a2a-demo` | – | `tools/a2a-demo`: iki site, gerçek HTTPS, uçtan uca A2A | **değişmedi** (araç) | Birleştirme onayı |
| 4 | `faz3-yayginlastirma` | 1.11.0 | Önerilen kurulum (Ayarlar), `bin/tara`, `docs/yayginlastirma` | değişti (önerilen kurulum) | Özellik + birleştirme onayı |
| 5 | `translatepress` | 1.12.0 | TranslatePress'ten diller | değişti | Özellik + birleştirme onayı |

Doğrulama: her dalda `composer check` temiz (en üstte 364 birim, 212 entegrasyon; PHP 8.1 + WordPress 7.1.2). En üst
dal ayrıca yerelde **WordPress 6.9 + PHP 8.3** ile denendi: 364 birim, 212 entegrasyon, hepsi geçti. A2A demosu
(PHP 8.3 + WordPress son sürüm) yerelde iki kez baştan sona çalıştı.

## 1. IndexNow (1.10.0)
Dün akşam hazırdı; "birleştir ve gönder" onayı bekleniyor. Birleşince makedonya.tr'de "Şimdi bildir" ile gerçek yanıt
(200/202 ya da 403) görülecek.

## 2. Hız sınırı testleri
Neden: sınırlayıcı dakikalık pencere kullanıyor; test istekleri dakika dönümüne denk gelince iki pencereye düşüp hepsi
izinli sayılıyordu. Çözüm: test dakikanın son 5 saniyesindeyse bir sonraki dakikayı bekleyip başlar
(`tests/Support/RateLimitWindow`). Doğrulamalar aynı. `RestCachingTest` de aynı kalıptaydı, ikisi birlikte düzeltildi.
- [ ] Onay: mevcut iki test bu şekilde değişsin mi?

## 3. A2A iki site demosu
`tools/a2a-demo/demo.sh`: dagitici.test ve fabrika.test, Docker + Caddy yerel sertifika. Eşleşme (puan 100) → önizleme →
"Onaylıyorum, gönder" işleyicisi → fabrikanın agent'ı "Talebiniz firmaya iletildi" → talep fabrikanın Teklif
Kutusu'nda "yeni" → iki sitenin denetim kaydı. Eklenti kodu değişmedi; demo ortamına özel küçük bir zorunlu eklenti
yalnızca `.test` adları için WordPress'in özel ağ engelini ve yerel sertifika doğrulamasını kapatır. Çıktı:
`docs/demo/a2a-yerel-2026-09-29.md`.
- [ ] Onay: birleştirilsin mi?
- [ ] Karar: **canlı** demo çifti. Öneri (PRD Faz 4 ile birebir): intekarglobal.com (kablo fabrikası, satılan ilan) ↔
  voltkab.com (kablo dağıtıcı, aranan ilan + eşleştirme + A2A). Kurulum ve veri girişi sizde, adımlar `docs/kullanim/a2a.md`.

## 4. Faz 3 altyapısı (1.11.0)
- **Önerilen kurulum** (eklenti özelliği): Ayarlar ekranında site türü → önizleme → Uygula. Beş profil (PRD pilot
  tablosu): ürün, ihracat (+ çoklu dil), tur, portal (+ portal modu), hizmet – yalnızca okuma (A2A yok). Yalnızca açar,
  hiçbir şeyi kapatmaz; teklif kutusu ve eşleştirme hiçbir profilde yok. Yeni anahtar yok (Ayarlar ekranı istisnası).
  Plan: `docs/planlar/1.11.0-onerilen-kurulum.md`.
- **`bin/tara`**: PRD'nin "eklenti kurulmadan, yalnızca adresle tarama" hedefi; eklentinin kendi tarayıcısını
  kullanır + kurulum ön kontrolü. 6 pilot site tarandı: `docs/yayginlastirma/raporlar/`.
- **`docs/yayginlastirma/`**: süreç, kontrol listesi, haftalık 10 soru testi, önce/sonra raporu, pilot siteler.
- [ ] Onay: önerilen kurulum özelliği ve profillerin içeriği (özellikle önbellek entegrasyonu ve IndexNow'un
  profillerde olması; ikisi de önizlemede açıkça yazar).
- [ ] Karar: kuzeymakedonya.com.tr ve kosova.org.tr tur operatörü mü, portal mı?
- [x] Düzeltildi (kullanıcı): hukuk firması erenlegal.com.tr (WordPress, kurulabilir); kablo dağıtıcı voltkab.com;
  kablo fabrikası intekarglobal.com. `docs/yayginlastirma/siteler.md` güncellendi, iki yeni site tarandı.
- [ ] Bilgi: PRD 10 site hedefi için listede 7 site var; diğer gezi portallarının adresleri eklenecek.

## 5. TranslatePress (1.12.0)
Diller TranslatePress'ten okunur (resmi `trp_custom_language_switcher()`, ayarlardaki varsayılan dil, `$TRP_LANGUAGE`).
Testler resmi API'yi taklit eder; gerçek eklentiyle denenmedi.
- [ ] Onay: birleştirilsin mi?
- [ ] Canlı deneme: balkantrade.com.tr'ye kurulunca Çeviriler ekranında dillerin TranslatePress'ten okunduğu ve
  `/en/ai-katalog/` adresinin davranışı kontrol edilmeli.

## Kapsam dışı bulgular (not)
- `bin/tara`: WordPress sitelerin hepsinde başka bir MCP sunucusu var ve bizim puanlama onu "makine arayüzü" olarak tam
  puanla sayıyor; eklentisiz puanlar (82–85) bu yüzden yüksek. Puanlama yöntemi değişikliği ayrı karar (puanlama
  sürümünü değiştirir).

## Gündüz (2026-09-29) – dal `duzeltme-1.12.1`, onay bekliyor

| İş | Ne | Karar |
| --- | --- | --- |
| Adres kuralları | Devre dışı bırakınca `/ai-katalog/`, doğrulama ve işletme sayfası kuralları artık temizleniyor (Eklenti El Kitabı yöntemi: önce unut, sonra yenile). Test eski kodda kırılıyor, yenide geçiyor. | Birleştirme onayı |
| Rozet kapsamı | Rozet için ağırlığın en az %80'i ölçülmüş olmalı; kısmi ölçümde tarama ekranında uyarı. %80 değeri bir karar. | Onay + eşik (%80 uygun mu?) |
| Çeviri şablonu | `languages/ai-hazir-site.pot` (536 metin, WP-CLI) ve `composer pot`. | Birleştirme onayı |
| Puanlama sürümü 4 | Yalnızca plan: `docs/planlar/puanlama-4-oneri.md`. Başka eklentinin kimlik doğrulama isteyen MCP sunucusu (voltkab'da doğrulandı: 401) ve çekirdek REST API bugün tam puan alıyor. **Pilot "önce" taramalarından önce karar verilmeli.** | Seçenek 1/2/3 |

Doğrulama: `composer check` temiz (365 birim, 213 entegrasyon).
