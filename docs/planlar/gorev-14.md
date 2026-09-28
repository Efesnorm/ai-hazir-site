# Görev 14 – A8 Çoklu dil: plan (onay bekliyor)

> Bu dal (`gorev-14`) kullanıcı uyurken, "onayları sabaha ertele" talimatıyla hazırlandı.
> `main`'e birleştirilmedi, etiket atılmadı. Aşağıdaki "Onay bekleyenler" onaylanınca birleştirilir.

## 1. Bu görevde güncellenmesi gerekenler (taslağa göre)

- **Çevrilecek alanlar net değil.** Kodda çevrilebilir metin alanları: ilanda `title`, `description`, `category`,
  `region`; profilde `sector`. Firma adı, e-posta, telefon, sertifika, sayılar ve şablon alan değerleri
  (ör. "3x2,5 mm²", "stokta") dilden bağımsız sayılır ve çevrilmez. Öneri: bu beş alan.
- **Şablon etiketleri** (ör. "Kalan yer") şablon JSON'larında Türkçe. Onların çevirisi şablon biçimini değiştirir;
  bu göreve alınmadı (bilinen sınır).
- **Eklentinin kendi metinleri** (llms.txt başlıkları, katalog sayfası etiketleri) WordPress çeviri sistemiyle
  (`__()`) gelir; İngilizce `.mo` dosyası henüz yok. İstenen dilde veriler çevrilir, kalıp metinler Türkçe kalır.
- **Pilot sonucu yok**, bu yüzden dil listesi varsayılanı: sitenin dili + `en`.

## 2. Plan

Anahtar: `multilingual` (varsayılan kapalı). Kapalıyken **veya tek dil tanımlıyken** hiçbir çıktı değişmez.

**Çekirdek** (`src/Core/I18n/`, platformdan bağımsız):
- `LanguageSettings`: varsayılan dil + dil listesi (ISO 639-1, iki harf; BCP 47 birincil alt etiketi).
- `LanguageNegotiator`: istenen dil = açık parametre (`lang`) → `Accept-Language` (RFC 9110 §12.5.4, q değerleri;
  RFC 4647 "lookup": `en-GB` → `en`) → varsayılan dil.
- `Localizer`: ilan ve profili istenen dile çevirir; çevirisi olmayan alan varsayılan dilde kalır ve
  `missing` listesine yazılır. Otomatik çeviri yok.
- Sözleşmeler: `ListingTranslationRepository`, `ProfileTranslationRepository`. Yazma yalnızca
  `CatalogService::save_listing_translation()` / `save_profile_translation()` üzerinden (doğrulama: dil listede ve
  varsayılan değil, alan izinli, uzunluk sınırı). Yeni mimari testi bunu denetler.

**WordPress**:
- Saklama: ilan çevirileri ilan kaydının meta verisinde (`_aihs_translations`; ilan silinince kendiliğinden silinir),
  profil çevirisi `aihs_profile_translations` seçeneğinde; dil ayarı `aihs_languages`. Yeni tablo yok.
- `LanguageSource`: Polylang veya WPML etkinse dil listesi ve varsayılan dil onlardan okunur (bizim ayar
  ekranı salt okunur olur); ana sayfa JSON-LD'si onların "şu anki dil" bilgisini kullanır.
  - Polylang: `pll_languages_list()`, `pll_default_language()`, `pll_current_language()` (polylang.pro/doc/function-reference).
  - WPML: `wpml_active_languages`, `wpml_default_language`, `wpml_current_language` süzgeçleri
    (wpml.org/documentation/support/wpml-coding-api/wpml-hooks-reference).
- Yönetim: **AI Katalog → Çeviriler**: dil ayarı, profil çevirisi, ilan başına dil sekmeleriyle çeviri formu,
  ilan listesinde dil başına "çevrildi / eksik" durumu. Mevcut formlara dokunulmaz.

**Kanallar** (yalnızca anahtar açık ve en az iki dil varken):
- REST: `lang` parametresi veya `Accept-Language`; yanıtta `Content-Language` ve `Vary: Accept-Language`;
  gövdede `language` ve her kayıtta `translation: {language, missing[]}`. Şema belgeleri (`/schema/*`)
  bu alanları yalnızca anahtar açıkken içerir.
- Abilities / MCP: isteğe bağlı `lang` girdisi; çıktıda aynı işaretler.
- llms.txt: `/llms.txt?lang=en`; varsayılan metinde diğer dillerin adresleri listelenir; eksik çeviriler
  satırında "(çeviri yok: açıklama – tr)".
- /ai-katalog/: `?lang=en`, `<html lang>`, `hreflang` alternatifleri; varsayılana düşen metin
  `<span lang="tr">` ile işaretlenir (HTML standardı). JSON-LD `DataFeed`'e `inLanguage`.

## 3. Kaynaklar
- RFC 9110 §12.5.4 Accept-Language, §12.5.5 Vary; RFC 4647 §3.4 Lookup; BCP 47.
- HTML Living Standard, `lang` özniteliği; Google "hreflang" belgesi (developers.google.com/search/docs/specialty/international/localized-versions).
- Schema.org `inLanguage` (CreativeWork → Dataset → DataFeed).
- Polylang ve WPML resmi API belgeleri (yukarıda).

## 4. Onay bekleyenler (temkinli seçimle uygulandı)
1. Çevrilen alanlar: ilan `title`, `description`, `category`, `region`; profil `sector`.
2. llms.txt için dil seçimi `?lang=` parametresiyle (llmstxt.org dil konusunda bir kural koymuyor).
3. JSON-LD'de eksik çeviri alan bazında işaretlenmiyor (yalnızca `inLanguage`); alan bazında işaret için
   JSON-LD 1.1 `{"@value", "@language"}` kullanılabilir, ama Google'ın desteği belirsiz ve doğrulayıcının
   gevşetilmesi gerekir. Öneri: şimdilik yok.
4. Çakışma testi gerçek Polylang/WPML kurulmadan, onların resmi fonksiyon/süzgeç arayüzünü taklit eden
   testlerle yapıldı. Gerçek Polylang'ı wp-env'e eklemek (wordpress.org'dan indirme) onayınıza bırakıldı.
5. Kalıp metinlerin (etiketler) İngilizce çevirisi (`.pot`/`.mo`) ayrı bir iş olarak önerilir.
6. Mevcut bir testte değişiklik: `FeaturesTest::test_mvp_feature_defaults` anahtar listesine `multilingual => false`
   eklendi (yeni anahtar; gevşetme değil).

## 5. Sonuç (uygulandı)
- 4 işleme: çekirdek → WordPress saklama/ekran → kanallar → belgeler/sürüm 1.1.0.
- `composer check` temiz: 320 birim, 159 entegrasyon testi.
- Elle deneme (geliştirme sitesi): `Accept-Language: en` ile REST yanıtı `Content-Language: en`, çevrilen alanlar
  İngilizce, eksik açıklama `translation.missing` içinde; `/llms.txt?lang=en` işaretli.
- Birleştirme, etiket (v1.1.0) ve CI onaydan sonra.
