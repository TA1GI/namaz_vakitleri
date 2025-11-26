<?php
// =============================================================
// NAMAZ VAKİTLERİ OTOMASYON BOTU (Hata Ayıklama Modu)
// =============================================================

// AYARLAR
define('BATCH_LIMIT', 5); // Test için limiti düşürdüm (5)
define('DATA_DIR', '.');  // Dosyalar ana dizine (root) insin.

// BAŞLANGIÇ
@set_time_limit(0);
ignore_user_abort(true);
date_default_timezone_set('Europe/Istanbul');
ini_set('display_errors', 1);
error_reporting(E_ALL);

// =============================================================
// 1. ARŞİVLEME MODÜLÜ (YILBAŞI TEMİZLİĞİ)
// =============================================================
function archiveOldFiles() {
    if (date('m') != '01' || date('d') > 15) return;

    $current_year = (int)date('Y'); 
    $last_year = $current_year - 1; 
    $archive_folder = DATA_DIR . '/' . $last_year;
    
    if (is_dir($archive_folder)) return;

    echo "[ARŞİV] $last_year klasörü oluşturuluyor...\n";
    mkdir($archive_folder, 0755, true);
    
    $files = glob(DATA_DIR . '/*.json');
    foreach ($files as $file) {
        if (dirname($file) !== '.') continue;
        rename($file, $archive_folder . '/' . basename($file));
    }
    echo "[ARŞİV] Dosyalar taşındı.\n";
}

archiveOldFiles();

// =============================================================
// 2. İNDİRİLECEK YILI BELİRLEME
// =============================================================
// ŞU AN TEST İÇİN: Eğer 2026 verisi yoksa 2025 ile test edin.
// Test etmek için alttaki satırı açabilirsiniz:
// $target_year = 2025; 

$target_year = (int)date('Y') + 1; 
if (date('m') == '01') {
    $target_year = (int)date('Y');
}

echo "Hedef Yıl: $target_year\n";

// =============================================================
// 3. FONKSİYONLAR
// =============================================================

function fetchPrayerTimesHtml($district_id, $year) {
    $url = "https://namazvakitleri.diyanet.gov.tr/tr-TR/{$district_id}";
    $ch = curl_init($url);
    
    // Daha gerçekçi tarayıcı başlıkları
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'Accept-Language: tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7',
        'Referer: https://namazvakitleri.diyanet.gov.tr/',
        'Origin: https://namazvakitleri.diyanet.gov.tr',
        'Upgrade-Insecure-Requests: 1',
        'Connection: keep-alive'
    ];

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['year' => $year]));
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) return false;
    return $response;
}

function getPageTitle($html) {
    if (preg_match('/<title>(.*?)<\/title>/i', $html, $matches)) {
        return trim($matches[1]);
    }
    return "Başlık Bulunamadı";
}

function parsePrayerTimes($html) {
    if (empty($html)) return null;
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    $xpath = new DOMXPath($dom);
    $rows = $xpath->query('//div[@id="tab-2"]//table/tbody/tr');
    
    if ($rows->length == 0) return null;

    $data = [];
    foreach ($rows as $row) {
        $cells = $row->getElementsByTagName('td');
        if ($cells->length >= 8) {
            $data[] = [
                "miladiTarih" => trim($cells->item(0)->nodeValue),
                "hicriTarih"  => trim($cells->item(1)->nodeValue),
                "imsak"       => trim($cells->item(2)->nodeValue),
                "gunes"       => trim($cells->item(3)->nodeValue),
                "ogle"        => trim($cells->item(4)->nodeValue),
                "ikindi"      => trim($cells->item(5)->nodeValue),
                "aksam"       => trim($cells->item(6)->nodeValue),
                "yatsi"       => trim($cells->item(7)->nodeValue),
            ];
        }
    }
    return $data;
}

function sanitizeDistrictName($name) {
    $name = str_replace(' ', '', $name);
    $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    $name = preg_replace('/[^A-Za-z0-9]/', '', $name);
    return strtoupper($name);
}

// İlçe Listesi
$locations_json = '{
    "Adana": {"Adana":9146,"Aladağ":9147,"Ceyhan":9148,"Feke":9149,"İmamoğlu":9150,"Karaisalı":9151,"Karataş":9152,"Kozan":9153,"Pozantı":9154,"Saimbeyli":9155,"Tufanbeyli":9156,"Yumurtalık":9157}
}'; 
// Not: Test için listeyi kısalttım. Çalışırsa tam listeyi geri koyun.
// Tam liste çok uzun olduğu için buraya kısasını koydum, siz kendi tam listenizi kullanın.

// Eğer tam liste değişkeni elinizde varsa bu satırı silip onu kullanın.
// Test için Adana yeterli olacaktır.

// =============================================================
// 4. ANA İŞLEM DÖNGÜSÜ
// =============================================================

$downloaded_count = 0;
$locations = json_decode($locations_json, true); 
// DİKKAT: Yukarıdaki $locations_json değişkenini gerçek tam listenizle değiştirmeyi unutmayın! 
// Test ederken sadece Adana kalsın, hata sebebini görelim.

foreach ($locations as $province => $districts) {
    foreach ($districts as $district_name => $district_id) {
        
        if ($downloaded_count >= BATCH_LIMIT) {
            echo "--------------------------------------------------\n";
            echo "[LİMİT] Kota doldu, durduruluyor.\n";
            exit;
        }

        $sanitized_name = sanitizeDistrictName($district_name);
        $filename = "{$sanitized_name}_{$district_id}.json";
        $filepath = DATA_DIR . '/' . $filename;

        // Dosya kontrolü
        if (file_exists($filepath) && filesize($filepath) > 0) {
            $content = json_decode(file_get_contents($filepath), true);
            if (is_array($content) && !empty($content)) {
                $last_entry = end($content);
                if (isset($last_entry['miladiTarih']) && strpos($last_entry['miladiTarih'], (string)$target_year) !== false) {
                    continue;
                }
            }
        }

        echo "İndiriliyor: $province - $district_name ($district_id) ... ";
        $html = fetchPrayerTimesHtml($district_id, $target_year);
        
        if ($html) {
            $data = parsePrayerTimes($html);
            if ($data && count($data) > 10) { // En az 10 gün veri varsa
                file_put_contents($filepath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                echo "OK (" . count($data) . " gün)\n";
                $downloaded_count++;
                sleep(rand(2, 5)); // Bekleme süresini artırdım
            } else {
                // HATA DETAYI: Sayfa başlığını yazdır
                $title = getPageTitle($html);
                echo "HATA (Veri Yok) - Sayfa Başlığı: [$title]\n";
                
                // Eğer başlık "Namaz Vakitleri" ise ama veri yoksa, 2026 verisi henüz yayınlanmamış demektir.
                // Eğer başlık "Access Denied" veya "Cloudflare" gibiyse engellenmişizdir.
            }
        } else {
            echo "HATA (HTTP Bağlantı)\n";
        }
    }
}
?>
