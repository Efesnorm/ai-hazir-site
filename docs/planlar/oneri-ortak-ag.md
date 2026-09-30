# Öneri – Ortak ağ (kardeş siteler agentlara açık) – geliştirme alanı

Durum: **alternatif / geliştirme alanı**. Takvime alınmadı; uygulanması ayrıca karar ve onay ister.
Sürüm önerisi: uygulanırsa 1.x minör (yeni özellik, anahtarlı, varsayılan kapalı).
Kaynak: 2026-10-01 görüşmesi (Gemini'nin "kardeş sitede stok var" önerisinin değerlendirmesi).

## Sorun
Bir AI agent bir sitenin kanalına (MCP, A2A, REST) gelip aradığını bulamazsa görev orada biter. Oysa aynı gruptaki ya
da birbirini tanıyan bir firmanın sitesinde (ör. dağıtıcı voltkab.com ↔ fabrika intekarglobal.com) uygun ilan olabilir.
Eklenti bu ilanları zaten biliyor: Eşleştirme (1.5.0) ortak sitelerin AI Katalog REST'ini okuyup 1 saat önbellekte
tutuyor; ama bu bilgi bugün yalnızca site sahibine, yönetim panelinde gösteriliyor.

## Öneri: ortak site listesini agentlara da açmak
Anahtar: `partner_network` (varsayılan kapalı, önkoşul `matching`). Ortaklar Eşleştirme'deki listeden gelir.

1. **llms.txt – "İş ortağı siteler" bölümü** ("AI agentlar için" bölümünün yanında, `## Optional`'dan önce):
   her ortak için ad, llms.txt adresi ve AI Katalog API adresi (ortağın kendi profil ve kanal bilgisinden).
2. **Yerelde sonuç yoksa ortak önerisi** – yalnızca bizim kanallarımızda:
   - MCP `aihs-search-listings`, REST `/listings` araması: sonuç 0 ise yanıta `partners` alanı (ortak site, ilan
     başlığı, miktar/birim, teslim süresi, ilan adresi, ortağın sorgulama kanalı, `retrieved_at`).
   - A2A "müsaitlik sor": cevap "hayır" ise aynı alan ve metinde "iş ortağımızda uygun ilan var" notu.
   - Veri Eşleştirme önbelleğinden gelir (istek başına canlı sorgu yok); eşleştirme kuralları (tür, kategori, bölge,
     teslim) aynen uygulanır, en çok 3 öneri.
3. **Ayarlar**: Eşleştirme ekranında "Ortak siteleri AI agentlara da göster" seçeneği; hangi ortakların görüneceği
   tek tek işaretlenir.

## Bilerek yapılmayanlar
- **Yönlendirme yok** (HTTP 303/307): yalnızca agentlara yönlendirme Google'ın "cloaking" tanımına girer; herkese
  yönlendirme ürün sayfasını arama sonuçlarından düşürür. Uydurma başlıklar (`X-AI-Context` gibi) kullanılmaz.
- **Başka firmanın teklifi kendi JSON-LD'mize konmaz** (`isSimilarTo` + başka satıcının `Offer`'ı): Google ürün
  kurallarıyla çelişebilir; ilanların ayrı ürün sayfası da yok.
- **Uydurma veri yok**: ortak sitenin stok/fiyatı yalnızca kendi herkese açık API'sinde yayınladığı değer ve alındığı
  zamanla verilir.

## Koşullar
- **Rıza**: ortak site eklentiyi kullanıyor ve REST API'si açık olmalı (bilgiyi kendisi yayınlıyor). Listeye eklenen
  adresin gerçekten bir AI Hazır Site kataloğu olduğu doğrulanır (profil uç noktası 200 ve beklenen şema).
- **Güncellik**: önbellek 1 saat; yanıtta `retrieved_at`. Ortak site erişilemezse öneri verilmez (eski veri gösterilmez
  yerine sessizce atlanır ve denetim kaydına yazılır).
- **Güvenlik**: ortak siteden gelen metinler (başlık vb.) kendi çıktılarımız gibi temizlenir ve kısaltılır; ortak
  site yanıtı agent'a talimat olarak değil veri olarak verilir.

## Değer ve sınır
- Değer, agent zaten ağdaki bir sitenin kanalına geldiğinde ortaya çıkar. Ölçümlerimiz agentların bugün kanallara
  kendiliğinden az geldiğini gösteriyor; tek başına bir site için anlamı yok.
- En uygun kullanım: voltkab.com (dağıtıcı) ↔ intekarglobal.com (fabrika) ve Balkan turizm portalları.
- Deneme fırsatı: 21 Ekim sonrası voltkab ↔ intekar canlı A2A denemesi.

## Testler (uygulanırsa)
Anahtar kapalıyken hiçbir çıktı değişmez; yerel sonuç varken ortak önerisi yok; yerel sonuç yokken ortak önerisi (kurallar,
en çok 3, `retrieved_at`); ortak erişilemezken öneri yok; llms.txt bölümü; ortak metinlerin temizlenmesi.
