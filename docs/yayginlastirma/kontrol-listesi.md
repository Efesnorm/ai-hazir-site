# Site kurulum kontrol listesi

Her site için bu dosyayı kopyalayın (`kontrol-<site>.md`) ve doldurun.

**Site:** …………… **Tür:** …………… **Sorumlu:** …………… **Tarih:** ……………

## 1. Kurulumdan önce
- [ ] `php bin/tara https://<site> --md=docs/yayginlastirma/raporlar` çalıştırıldı; "önce" puanı: …/100
- [ ] WordPress 6.9+ ve PHP 8.1+ (ön kontrol tablosu)
- [ ] Önbellek eklentisi: ……… (WP Rocket / LiteSpeed ise entegrasyon açılacak; W3TC / WP Super Cache ise elle)
- [ ] SEO eklentisi: ……… (Rank Math / Yoast ise JSON-LD çakışması eklentice önlenir, site haritası entegrasyonu çalışır)
- [ ] Çoklu dil eklentisi: ……… (Polylang / WPML destekli; TranslatePress / GTranslate için bkz. bilinen sınırlar)
- [ ] Sunucu bot koruması / hız sınırı var mı: ……… (varsa barındırmaya AI bot istisnası talebi)
- [ ] Firmanın yazılı onayı: AI'a açılacak bilgiler ve (varsa) teklif kutusu için KVKK metni
- [ ] Site yedeği alındı; test kopyası var mı: ………

## 2. Kurulum
- [ ] Zip yüklendi, etkinleştirildi (yalnızca AI ölçümü açık gelir)
- [ ] **Önce dönemi:** en az 3 hafta yalnızca ölçüm (başlangıç: ……… bitiş: ………)
- [ ] Ayarlar → AI Hazır Site → önerilen kurulum: ……… uygulandı
- [ ] Firma profili: ad, ülke, diller, kurumsal e-posta, sektör şablonu
- [ ] İlanlar girildi (satılan / aranan / tedarik): … adet; geçerlilik tarihleri doğru
- [ ] Teklif kutusu gerekiyorsa: KVKK aydınlatma metni girildi, uyarı okunup açıldı
- [ ] Entegrasyonlar: önbellek ☐ site haritası ☐ IndexNow ☐ ("Şimdi bildir" yanıtı: ………)
- [ ] Sayfa önbelleği bir kez temizlendi

## 3. Doğrulama
- [ ] `bin/tara` yeniden: "sonra" puanı …/100, ölçülemeyen kontrol yok
- [ ] Dışarıdan: `/llms.txt` ☐ `/ai-katalog/` ☐ `/wp-json/aihs/v1/listings` ☐ `/.well-known/agent-card.json` ☐
- [ ] AI Ölçüm'de bot istekleri görünüyor (önbellek entegrasyonu açıksa)
- [ ] Bir AI asistanına adres verilerek soru soruldu, cevap veriyle eşleşti
- [ ] Yönetim ekranlarında PHP hatası yok; sitenin görünümü değişmedi

## 4. İzleme
- [ ] Haftalık 10 soru testi başladı (bkz. haftalik-10-soru.md)
- [ ] 4. hafta sonunda önce/sonra raporu (bkz. once-sonra-raporu.md)

**Notlar / sorunlar:**
