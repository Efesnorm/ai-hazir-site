# Onay listesi (Görev 14–19)

Tek sayfada, sabah kararı verilecek her şey. Ayrıntılar ilgili `gorev-NN.md` belgelerinde.

> **Karar (2026-09-28 sabah):** tüm dallar birleştirilsin, bot listesi eklensin, test değişiklikleri onaylı.
> `main`'e sırayla birleştirildi: 1.0.1 (şablon sırası + PHP 8.4 test satırı), 1.1.0, 1.2.0, 1.3.0, 1.4.0 (araştırma +
> bot listesi), 1.4.1, 1.5.0, 1.6.0. 1.4.0'ın CI'ı kırıldı (aynı saniyedeki ilanların sırası sabit değildi, `PortalChannelsTest`);
> düzeltme 1.4.1 olarak eklendi, v1.4.0 etiketi konmadı. Görevlerin "onay bekleyenler"indeki temkinli seçimler olduğu gibi uygulandı.
>
> **Açık kalanlar:** güncelleme sunucusunun yeri (adres boş), `belge-u2-allow` ve `prd-ucp` dalları (ayrıca onay
> verilmedi), "walkthrough planı" hangi belge, `AbilitiesTest` zamanlama düzeltmesi, gerçek Polylang denemesi,
> A2A uçtan uca iki site demosu.

## Dallar (sırayla, her biri öncekinin üzerine)

| Dal | Sürüm | Birleştirme sırası |
| --- | --- | --- |
| `gorev-14` | 1.1.0 çoklu dil | 1 |
| `gorev-15` | 1.2.0 portal modu | 2 |
| `gorev-16` | 1.3.0 merkezi güncelleme, rapor paneli | 3 |
| `gorev-17` | (araştırma belgesi) | 4 |
| `gorev-17-bot-listesi` | 1.4.0 bot listesi, puanlama sürümü 3 | 5 |
| `gorev-18` | 1.5.0 eşleştirme | 6 |
| `gorev-19` | 1.6.0 A2A | 7 |

**Bot listesi reddedilirse (alternatif zincir, hazır):** `gorev-17`'den sonra `gorev-17-bot-listesi`, `gorev-18`,
`gorev-19` yerine **`gorev-18-botsuz`** (1.4.0 eşleştirme) ve **`gorev-19-botsuz`** (1.5.0 A2A) birleştirilir.
Kod aynıdır; yalnızca bot listesi, puanlama sürümü 3, ilgili test beklentileri ve sürüm numaraları farklıdır. Bu
iki dalda `composer check` temiz (PHP 8.1 + WordPress 7.1.2; 339 birim ve 174 entegrasyon testi `gorev-19-botsuz`'da).
`gorev-19-botsuz`'daki A2A 3/3 işlemesinin mesajı taşınmadan kaldığı için "1.6.0" der; doğru sürüm 1.5.0'dır.

## Doğrulama

- Her işlemede `composer check` temiz (PHP 8.1 + WordPress 7.1.2).
- `gorev-19` (tüm kod) yerelde CI matrisinin diğer ortamlarında da denendi, ayrı bir wp-env örneğinde:
  - PHP 8.1 + WordPress 6.9: 174/174 entegrasyon testi geçti.
  - PHP 8.3 + WordPress 7.1.2: 339 birim + 174 entegrasyon testi geçti.
  - PHP 8.3 + WordPress 6.9: 174/174 entegrasyon testi geçti.
- Ara dallar da (`gorev-14`, `gorev-15`, `gorev-16`, `gorev-17-bot-listesi`, `gorev-18`, `gorev-18-botsuz`,
  `duzeltme-sablon-sirasi`) PHP 8.3 + WordPress 6.9'da birim ve entegrasyon testleriyle yeşil; her biri ayrı
  birleştirilip CI'dan geçeceği için önceden denendi.
- **Deneme birleşimi:** `birlesim-deneme` dalı = `duzeltme-sablon-sirasi` (1.0.1) + `gorev-19` zinciri. Çakışmalar
  yalnızca CHANGELOG, sürüm satırı ve `readme.txt`'de, çözüldü; `composer check` temiz (340 birim, 182 entegrasyon).
  CI matrisinin dört kombinasyonunda da yeşil (PHP 8.1/8.3 × WordPress 6.9/7.1.2; yerelde, ayrı wp-env örneğinde).
  Onaylanırsa `main` bu hâle getirilebilir.
  Bot listesi reddedilirse: `birlesim-deneme-botsuz` (1.0.1 + `gorev-19-botsuz`, sürüm 1.5.0); `composer check` temiz
  (340 birim, 182 entegrasyon).
- GitHub CI bu dallarda çalışmadı (yalnızca `main` ve PR'larda çalışır); birleştirmeden sonra çalışacak.

## Karar bekleyen konular

### Mevcut test değişiklikleri (kural: onaysız değiştirilmez)
- [ ] Her görevde `FeaturesTest::test_mvp_feature_defaults` anahtar listesine yeni anahtar (`multilingual`,
      `portal_mode`, `remote_updates`, `telemetry`, `matching`, `a2a`; hepsi `false`).
- [ ] `gorev-17-bot-listesi`: robots.txt anlık görüntüleri (yalnızca yeni satırlar), bot sayısına bağlı 3 oran,
      `SCORE_VERSION` 3, "Puanlama sürümü: 3", IP listesi sayısı 8 → 11.
- [ ] `gorev-19`: `TelemetrySendsOnlyWithConsentTest` izinli giden POST dosyalarına `A2AOutbox`.

### Görev 14 – çoklu dil
- [ ] Çevrilen alanlar: ilan başlık/açıklama/kategori/bölge, profil sektörü.
- [ ] llms.txt dil seçimi `?lang=` ile.
- [ ] JSON-LD'de alan bazında eksik çeviri işareti yok (yalnızca `inLanguage`).
- [ ] Gerçek Polylang ile deneme (wp-env'e Polylang eklemek, wordpress.org'dan indirme).

### Görev 15 – portal
- [ ] İşletmeler seçenekte (`aihs_businesses`), yüzlerce işletmeye kadar.
- [ ] Kalıcı rol yok; `user_has_cap` ile anlık yetki.
- [ ] İşletme sayfası adresi `/ai-katalog/isletme/{kısaltma}/`.
- [ ] Kaldırmada işletme yetkilisinin kullanıcı bağı (`aihs_business`) ve eklentinin tüm `aihs_*` geçici verileri de
      silinsin. Gece yapılan bir testle bu verilerin kaldığı bulundu; düzeltme `gorev-15`'e eklendi ve üstteki dallar
      yeniden taşındı (yeni testler: `PortalUninstallTest`, `UninstallAllFeaturesTest`).

### Görev 16 – güncelleme ve rapor paneli
- [ ] **Güncelleme sunucusu nerede barınacak?** (PRD açık kararı; şimdilik adres boş.)
- [ ] Kendi güncelleme istemcimiz mi, hazır Plugin Update Checker kütüphanesi mi?
- [ ] Kanal seçimi sitede (pilot/genel) mi, sunucuda pilot site listesi mi?
- [ ] Gönderilen özet alanları ve haftalık sıklık.

### Görev 17 – yeni standartlar
- [ ] WebMCP, IETF AIPREF, Content Signals puanlamaya **eklenmesin** (taslak / standart değil).
- [ ] llms.txt ağırlığı 10 → 5, yapılandırılmış veri 20 → 25 önerisi (uygulanmadı).
- [ ] 6 yeni bot ve puanlama sürümü 3 (`gorev-17-bot-listesi`).

### Görev 18 – eşleştirme
- [ ] Eksik bilgide nötr değer 0,5; fiyat örtüşmesi formülü; bölge kesin şartı.
- [ ] Eşleşmeler yalnızca yönetim ekranında (REST/MCP'ye açık değil).

### Görev 19 – A2A
- [ ] Kimlik doğrulama şeması yok (herkese açık + hız sınırı).
- [ ] Görevler saklanmıyor (anında sonuç).
- [ ] `A2A-Version` başlığı olmayan (0.3) istemciler reddediliyor.
- [ ] Uçtan uca iki site demosu (elle; adımlar `docs/kullanim/a2a.md`).

## Kapsam dışı bulgu ve düzeltme önerisi
- `RestContractTest::test_full_catalog` ara sıra kırılıyordu (şablon sırası ilanların kaydedildiği saniyeye bağlı;
  Görev 10'dan beri). Düzeltmesi ayrı dalda: **`duzeltme-sablon-sirasi`** (`main` üzerine, sürüm 1.0.1).
  - [ ] Onay: `GET /templates` sırası "genel, profilin şablonu, sonra ilanların şablonları kimliğe göre" olsun.
  - Mevcut testler değişmedi; yeni `TemplateOrderTest` eski kodda kırılıyor, düzeltmeyle geçiyor; `composer check`
    temiz, `RestContractTest` arka arkaya 5 kez geçti.
  - Birleştirme sırası: önce bu dal (1.0.1), sonra `gorev-14`. `gorev-14`'te CHANGELOG ve sürüm satırlarında basit
    çakışma çıkar (1.1.0 girdisi 1.0.1'in üstüne gelir).

## PRD: ikinci ürün adayı UCP
- [ ] `prd-ucp` dalı (`main` üzerine, yalnızca `docs/PRD.md`): UCP özeti, ürünümüze uyum tablosu, önerilen artımlar
      (C1 profil + katalog, C2 WooCommerce checkout, C3 sipariş ve kimlik bağlama, C4 AP2 ve konaklama, U6 uyum
      kontrolü), riskler ve açık kararlar. Görev dosyası oluşturulmadı.
- [ ] "Walkthrough planı" hangi belge? UCP oraya da eklenecek.

## Kapsam dışı bulgu: `AbilitiesTest::test_rate_limit` zamanlaması
- Test sabit IP ve gerçek saatle 3 istek atıyor; hız sınırlayıcı dakikalık pencere kullandığından istekler arasında
  dakika dönerse test ara sıra kırılır (Görev 11'den beri). Düzeltmesi testte sahte saat kullanmayı gerektirir; mevcut
  test değişikliği olduğu için yapılmadı.
  - [ ] Onay: bu test sahte saatle yeniden yazılsın mı?

## U2 "Allow: /" notu (düzeltildi: hata değil)
- [ ] `belge-u2-allow` dalı (`main` üzerine, yalnızca belge): eskiden "bilinen hata" denen `Allow: /` yokluğu bilinçli.
      Denendi: eklenirse başka eklentinin adlı bot engeli RFC 9309 eşitlik kuralıyla kalkıyor ve iki mevcut test
      kırılıyor. Kod değişmedi; bilinen sınırlar metni gerekçeyle düzeltildi. Zincirle birleşirken
      `docs/bilinen-sinirlar.md`'de küçük bir çakışma çıkar.
