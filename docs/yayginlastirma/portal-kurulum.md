# Balkan turizm portalları – kurulum kılavuzu (Faz 0)

Portallar: kosova.org.tr, yunanistan.org.tr, makedonya.org.tr (anne site), arnavutluk.org.tr, sirbistan.org.tr.
Her sektöre açık ülke rehberleri (2026-10-04 kararı).
Durum (2026-10-04): beşinde de eklenti kurulu değil; hepsi LiteSpeed sunucu önbelleğinin arkasında (intekarglobal.com
ile aynı yapı); makedonya.org.tr ve arnavutluk.org.tr'de Rank Math var.
Bu adım kod gerektirmez. Ortak çalışma (ağ) Faz 1'de gelir: [oneri-portal-agi.md](../planlar/oneri-portal-agi.md).

## Her portal için sıra
1. **Yedek alın** (dosyalar + veritabanı).
2. **Kurun:** Eklentiler → Yeni Ekle → Eklenti Yükle → `ai-hazir-site-<son sürüm>.zip` → Etkinleştir.
3. **Önerilen kurulum: "portal".** AI Hazır Site → Ayarlar → Önerilen kurulum → **portal** → önizle → uygula.
   Açılanlar: ölçüm, uyum taraması ve raporu, bot erişimi, AI Katalog, sektör şablonları, Schema.org, llms.txt,
   REST API, yetenekler, MCP, keşif, önbellek entegrasyonu, katalog site haritası, IndexNow, A2A, **portal modu**.
   (Merkezi güncelleme, rapor paneli, çoklu dil, eşleştirme kapalı kalır; teklif kutusu ayrı açılır.)
4. **LiteSpeed sunucu önbelleği:** AI Hazır Site → Entegrasyonlar → **LiteSpeed sunucu önbelleği → Aç**
   (yönetim ekranından; WP-CLI ile açılırsa `.htaccess` yazılmaz).
5. **Firma profili = portalın kendisi:** AI Hazır Site → Firma Profili: portal adı, sektör (ör. "Ülke iş rehberi"),
   ülke, diller, iletişim. Şablon: **genel** (portallar her sektöre açık; şablon ilan bazında seçilir).
6. **İşletmeler:** AI Hazır Site → İşletmeler: her otel / tur firması / rehber için profil. İsterseniz işletme
   yetkilisi kullanıcısını bağlayın (yalnızca kendi ilanlarını görür: "İşletmem").
7. **İlanlar:** AI Hazır Site → Satılanlar (veya Arananlar / Tedarik) → yeni ilan → işletmenin sektörüne uygun şablon
   (**ürün**, **hizmet**, **tur**, **ihracat ürünü** ya da **genel**); ilanı ilgili işletmeye atayın (İşletmeler ekranı →
   "İlanların işletmeleri"). Kategori, fiyat/para birimi, bölge ve geçerlilik tarihi girin.
8. **Teklif kutusu (isteğe bağlı):** önce sitenin e-posta gönderebildiğini doğrulayın (barındırma `mail()`'i kapatmış
   olabilir → WP Mail SMTP kurup "Email Test"). Sonra AI Hazır Site → Teklif Kutusu → KVKK uyarısını okuyup açın.
9. **Önbellekleri temizleyin** (WP Rocket / LiteSpeed Cache ve sunucu önbelleği): eski kopyalar JSON-LD ve keşif
   bağlantılarını taşımaz.
10. **Uyum taraması:** AI Hazır Site → AI Uyum → Tara.
11. **Haber verin:** canlı testi (dışarıdan, test belirteciyle) yaparım.

## Notlar
- Rank Math olan sitelerde "Organization şemasını Rank Math üretiyor" bilgisi çıkar; normaldir (ilanlar yine
  yayınlanır). Bilgi yalnızca eklenti ekranlarında görünür ve kapatılabilir.
- Imunify360: AI botları doğrulanmışsa dakikada 10 istek hakkı vardır (Balanced). "Strict" seçiliyse Balanced'a çekin.
- Ölçüm verisini temiz tutmak için kurulumdan sonraki ilk haftaların AI Ölçüm CSV'sini saklayın; Faz 1 öncesi/sonrası
  karşılaştırması için gerekir.
