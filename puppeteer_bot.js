const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

// PHP botunun hata aldığı ve sadece manuel CAPTCHA ile açılan ilçeler
const hata_veren_ilceler = {
    "Sultanhanı": 20069,
    "Adaklı": 17889,
    "Güroymak": 17887,
    "Kestel": 17893,
    "Tavas": 17900,
    "Kelkit": 16746,
    "Babaeski": 17903,
    "Meram": 17870,
    "Sarayönü": 17874
};

// PHP botu `.` yani ana dizine kaydediyordu, onunla aynı klasör olmalı.
const DATA_DIR = __dirname;

// PHP'deki sanitizeDistrictName fonksiyonunun JavaScript karşılığı
function sanitizeDistrictName(name) {
    const map = {
        'ç': 'c', 'ğ': 'g', 'i': 'i', 'ı': 'i', 'ö': 'o', 'ş': 's', 'ü': 'u',
        'Ç': 'C', 'Ğ': 'G', 'İ': 'I', 'I': 'I', 'Ö': 'O', 'Ş': 'S', 'Ü': 'U'
    };
    let cleanName = name.replace(/[çğiıöşüÇĞİIÖŞÜ]/g, match => map[match]);
    cleanName = cleanName.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
    return cleanName;
}

async function scrapePrayerTimes() {
    console.log("Puppeteer (Headless Browser) başlatılıyor...");

    // Tarayıcı ayarları: Bot korumasını olabildiğince atlatmak için yapılandırıldı
    const browser = await puppeteer.launch({
        headless: "new",
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-blink-features=AutomationControlled'
        ]
    });

    for (const [districtName, districtId] of Object.entries(hata_veren_ilceler)) {
        // Her ilçe için TAMAMEN izole edilmiş (çerez, önbellek sıfır) yeni bir gizli pencere aç (Session çakışmasını önler)
        const context = await browser.createBrowserContext();
        const page = await context.newPage();
        await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

        try {
            console.log(`\nİndiriliyor: ${districtName} (${districtId}) ...`);

            const url = `https://namazvakitleri.diyanet.gov.tr/tr-TR/${districtId}`;
            await page.goto(url, { waitUntil: 'networkidle2', timeout: 45000 });

            // Diyanet sayfası normalde ilk girişte POST isteği almazsa aylık (30 günlük) tabloyu gösteriyor.
            // Yıllık veriyi alabilmek için, tıpkı tarayıcıda olduğu gibi "Yıl" listesini bulup tıklamamız gerekiyor
            // Ancak, en garantili yol: POST ile veya AJAX benzeri form göndererek 2026 bilgisini yollamaktır.

            // Form yollama ve Sayfa yükleme senkronizasyonu
            await Promise.all([
                page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30000 }),
                page.evaluate(() => {
                    const form = document.createElement("form");
                    form.method = "POST";
                    // URL yönlendirme kayıplarını (POST -> GET dönüşümünü) engellemek için mevcut uzun adrese POST ediyoruz:
                    form.action = window.location.href;
                    const yrInput = document.createElement("input");
                    yrInput.name = "year";
                    yrInput.value = "2026";
                    form.appendChild(yrInput);
                    document.body.appendChild(form);
                    form.submit();
                })
            ]);

            // Yeni sayfanın tab-2'sinde YILLIK (en az 300 satır) gelene kadar bekle!
            // Bu sayede 30 günlük aylık sayfayı çekerse bot bunu tam çekemedim sayıp hata verdirecek.
            await page.waitForFunction(() => {
                const rows = document.querySelectorAll('#tab-2 table tbody tr');
                return rows.length > 300;
            }, { timeout: 15000 });

            // Sayfanın DOM yapısına erişip JSON verisini ayıkla
            const prayerData = await page.evaluate(() => {
                const rows = document.querySelectorAll('#tab-2 table tbody tr');
                const data = [];
                rows.forEach(row => {
                    const columns = row.querySelectorAll('td');
                    if (columns.length >= 8) {
                        data.push({
                            miladiTarih: columns[0].innerText.trim(),
                            hicriTarih: columns[1].innerText.trim(),
                            imsak: columns[2].innerText.trim(),
                            gunes: columns[3].innerText.trim(),
                            ogle: columns[4].innerText.trim(),
                            ikindi: columns[5].innerText.trim(),
                            aksam: columns[6].innerText.trim(),
                            yatsi: columns[7].innerText.trim()
                        });
                    }
                });
                return data;
            });

            if (prayerData.length > 0) {
                const sanitizedName = sanitizeDistrictName(districtName);
                const filename = `${sanitizedName}_${districtId}.json`;
                const filepath = path.join(DATA_DIR, filename);

                // Formatta tutarlılık için PHP ile aynı JSON çıktısını yarat
                fs.writeFileSync(filepath, JSON.stringify(prayerData, null, 4), 'utf8');
                console.log(`  -> BAŞARILI! ${prayerData.length} gün (yıllık) veri kaydedildi: ${filename}`);
            } else {
                console.log(`  -> HATA! Tablo var ama veri çıkartılamadı.`);
            }

            // Firewall'a peşpeşe asılmamak için aralara 3 saniye bekleme süresi koy.
            await new Promise(r => setTimeout(r, 3000));

        } catch (error) {
            console.log(`  -> HATA! Sayfa yüklenemedi veya CAPTCHA aşılamadı: ${error.message}`);
        } finally {
            // Memory leak (bellek sızıntısı) ve geçmiş cache çakışmasını engellemek için sekmeyi ve profili tamamen kapat
            await page.close();
            await context.close();
        }
    }

    await browser.close();
    console.log("\nPuppeteer işlemi tamamlandı.");
}

scrapePrayerTimes();
