# Görev 15 – A9 Portal modu: plan (onay bekliyor)

> `gorev-15` dalı, `gorev-14` üzerine kuruldu. Kullanıcı uyurken, "onayları sabaha ertele" talimatıyla
> hazırlandı. `main`'e birleştirilmedi, etiket atılmadı.

## 1. Bu görevde güncellenmesi gerekenler (taslağa göre)

- **"CompanyProfile birden çok olabilir"**: mevcut tek profil (`aihs_profile`) **portalın kendi profili**
  olarak kalır; işletmeler ayrı kayıtlardır ve her biri bir `CompanyProfile` taşır. Böylece tek firmalı
  sitelerde hiçbir şey değişmez.
- **İlan ↔ işletme bağı** `Listing` nesnesine alan olarak eklenmedi (her kanalın ve testin kullandığı
  nesne); çevirilerde olduğu gibi ilan kaydının meta verisinde (`_aihs_business`) tutulur. İşletmesi
  olmayan ilan portalın kendi ilanıdır.
- **İşletme bazında AI ölçümü** için her işletmeye ayrı bir adres gerekir: A0 ölçümü sorgu dizesini
  saklamaz. Bu yüzden işletme sayfaları `/ai-katalog/isletme/{slug}/` biçiminde (sorgu dizesi değil yol).
- **Yetkili kullanıcı**: WordPress'e kalıcı yeni rol eklemek yerine, kullanıcıya `aihs_business` meta
  verisi atanır; portal modu açıkken bu kullanıcıya yalnızca `aihs_manage_business` yetkisi verilir
  (`user_has_cap` süzgeci). Portal yöneticisi kullanıcıyı WordPress'in Kullanıcılar ekranında
  (ör. Abone rolüyle) oluşturur, İşletmeler ekranından bir işletmeye bağlar.
- Yazma kuralı: işletme ve ilan-işletme yazmaları tek bir çekirdek servisten (`PortalService`) geçer;
  WordPress'te ilan meta verisine yine yalnızca `WpListingRepository` yazar (mevcut mimari testi değişmedi).

## 2. Plan

Anahtar: `portal_mode` (varsayılan kapalı).

**Çekirdek** `src/Core/Portal/`:
- `Business` (id, slug, `CompanyProfile`, updated_at), `BusinessValidator` (profil kuralları + slug).
- Sözleşmeler: `BusinessRepository` (işletmeler), `ListingBusinessRepository` (ilan → işletme).
- `PortalService` (tek yazma noktası): işletme kaydet/sil (ilanı olan işletme silinmez), ilanı işletmeye bağla,
  işletme yetkilisi için `save_business_listing()` / `delete_business_listing()`: başka işletmenin ilanına
  dokunulamaz (kontrol çekirdekte).
- `PortalReport`: işletme başına AI bot okuması (işletme sayfası yolu) ve talep sayısı; işletmeler + portal =
  toplam.

**WordPress**:
- `WpBusinessRepository` (`aihs_businesses` seçeneği), `WpListingRepository` ilan-işletme bağı.
- `PortalModule`: `user_has_cap`, `/ai-katalog/isletme/{slug}/` yeniden yazma kuralı, ekranlar.
- **AI Katalog → İşletmeler** (yönetici): işletme ekle/düzenle/sil, yetkili kullanıcı bağla, ilanları işletmeye bağla,
  işletme bazında rapor.
- **İşletmem** (yetkili): yalnızca kendi ilanlarını listeler, ekler, düzenler, siler.

**Çıktılar** (yalnızca `portal_mode` açıkken):
- REST: her ilanda `business {id, slug, name}`; `/listings?business={slug}`; `GET /businesses`.
- Abilities/MCP: `aihs/search-listings` girdisinde `business`; yeni `aihs/list-businesses`.
- Schema.org: portal `Organization` aynı kalır; katalogda her ilanın satıcısı (`seller`/`offeredBy`) işletmenin
  kendi `Organization` düğümü; işletme sayfasında işletme `Organization` + ilanları `DataFeed`.
- llms.txt: "İşletmeler" dizini (işletme sayfası bağlantılarıyla); ilan satırında işletme adı.
- Tek sorguda tüm işletmelerde arama: `business` verilmezse arama tüm işletmeleri kapsar (mevcut davranış).

## 3. Kaynaklar
- WordPress: `user_has_cap` süzgeci, `add_rewrite_rule`, `get_user_meta` (developer.wordpress.org).
- Schema.org: `Organization`, `Offer.seller`, `Demand.seller`, `Service.provider`, `DataFeed`.
- llmstxt.org biçimi (bölüm başlıkları ve bağlantı listeleri).

## 4. Onay bekleyenler (temkinli seçimle uygulandı)
1. İşletmeler seçenekte (`aihs_businesses`), ayrı tablo/içerik türü değil. Yüzlerce işletmeye kadar yeterli;
   binlerce işletmeli portal için ayrı tablo önerilir.
2. Yetkili kullanıcı için kalıcı rol yok; `user_has_cap` ile anlık yetki.
3. İşletme sayfası adresi `/ai-katalog/isletme/{slug}/`.
4. İşletme bazlı ölçümde REST/MCP çağrıları işletmeye atfedilemez (yol aynı); yalnızca işletme sayfası okumaları
   ve talepler sayılır.
