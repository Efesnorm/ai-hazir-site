# Görev 18 – A11 Eşleştirme motoru: plan (onay bekliyor)

> Bu `gorev-18-botsuz` dalıdır: `gorev-18`'in aynısı, bot listesi (`gorev-17-bot-listesi`) olmadan doğrudan
> `gorev-17` üzerine taşındı; sürüm 1.4.0. Bot listesi reddedilirse `gorev-18` yerine bu dal birleştirilir.
> Kullanıcı uyurken hazırlandı; `main`'e birleştirilmedi, etiket yok. **Hiçbir ortak siteye istek atılmadı.**

## 1. Bu görevde güncellenmesi gerekenler (taslağa göre)

- **"Standart" alanı** yalnızca `product` şablonunda var (`standart`, liste). Kesin süzgeç: arananın istediği her
  standart adayda da olmalı. Diğer şablonlarda bu süzgeç uygulanmaz.
- **"Teslim süresi sınırı"**: aranan ilandaki `lead_time_days` "en geç kaç günde" anlamında kullanılır; aday daha
  uzun süre veriyorsa elenir. Süre bilinmiyorsa elenmez, teslim uyumu 0,5 sayılır.
- **Fiyat örtüşmesi** yalnızca aynı para biriminde hesaplanır (kur çevrimi yok); farklıysa 0,5 sayılır.
- **Ortak siteler**: A5 REST çıktısı okunur (`/wp-json/aihs/v1/listings`); ortak adresi elle girilir, otomatik keşif yok.

## 2. Plan

Anahtar: `matching` (varsayılan kapalı).

**Çekirdek** `src/Core/Matching/`:
- `MatchWeights`: w1 özellik, w2 miktar, w3 teslim, w4 fiyat; toplam 1'e normalleştirilir; başlangıçta eşit (0,25).
- `HardFilter`: tür (aranan ↔ satılan/tedarik), kategori, şablon, standart, bölge, teslim süresi sınırı; elenen için neden.
- `Matcher`: S = Σ wᵢ·sᵢ (0–100); her sonuçta ölçüt başına değer ve puan (açıklama); toplam puanla birebir.
- `Candidate`: ilan + kaynak (yerel ya da ortak site adresi).
- `PartnerListings`: ortak sitenin REST yanıtını `Listing`'e çevirir (güvenilmeyen veri: yalnızca bilinen alanlar).

**WordPress**:
- **AI Katalog → Eşleşmeler**: aranan ilan seçilir, adaylar puan sırasıyla ve ölçüt açıklamasıyla listelenir;
  ağırlık ayarı; ortak site listesi (yalnızca https). Eşleşmeler öneridir; teklif/mesaj gönderilmez.
- Ortak site okuması 1 saat önbellekli; erişilemezse atlanır ve ekranda belirtilir, yerel eşleştirme sürer.

## 3. Kaynaklar
- A5 REST sözleşmesi (`/schema/listings`), şablon alanları (`data/templates/product.json`).

## 4. Onay bekleyenler (temkinli seçimle uygulandı)
1. Eksik bilgide nötr değer 0,5 (miktar, teslim, fiyat).
2. Fiyat örtüşmesi: aralıklar kesişiyorsa 1; kesişmiyorsa aradaki farkın bütçenin üst sınırına oranıyla azalır.
3. Bölge: iki tarafta da doluysa aynı olmalı (kesin süzgeç); boşsa elenmez.
4. Eşleşmeler yalnızca yönetim ekranında; REST/MCP'ye açılmadı.
5. Mevcut bir testte değişiklik: `FeaturesTest::test_mvp_feature_defaults` anahtar listesine `matching => false`.

## 5. Sonuç (uygulandı)
- 2 işleme: çekirdek (+ birim kabul testleri) → WordPress ekranı, ortak siteler, belgeler, sürüm 1.5.0.
- `composer check` temiz: 334 birim, 171 entegrasyon testi.
- Kabul: veri setinde beklenen eşleşmeler beklenen sırada; kesin süzgece uymayan hiçbir sonuç yok; açıklama puanla
  tutarlı; ortak site erişilemezken yerel eşleştirme sürüyor (birim + entegrasyon, HTTP taklidiyle).
