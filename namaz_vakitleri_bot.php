<?php
// =============================================================
// NAMAZ VAKİTLERİ OTOMASYON BOTU (TEST SÜRÜMÜ)
// =============================================================
// BU SÜRÜM SADECE SİSTEMİN ÇALIŞTIĞINI GÖRMEK İÇİNDİR.
// TARİH KONTROLLERİ DEVRE DIŞI BIRAKILMIŞTIR.

// AYARLAR
define('BATCH_LIMIT', 5); // Test için sadece 5 ilçe indirsin (Hızlı bitsin)
define('DATA_DIR', '.');

// BAŞLANGIÇ
@set_time_limit(0);
ignore_user_abort(true);
date_default_timezone_set('Europe/Istanbul');
ini_set('display_errors', 1);
error_reporting(E_ALL);

// =============================================================
// 1. ARŞİVLEME MODÜLÜ (ZORLA ÇALIŞTIRMA)
// =============================================================
function archiveOldFiles() {
    // NORMALDE: if (date('m') != '01') return;
    // TEST İÇİN: Bu kontrolü kaldırdık. Her zaman çalışacak.

    // Simülasyon: Sanki 2026 yılındayız ve 2025 dosyalarını arşivliyoruz.
    $archive_folder_name = '2025'; 
    $archive_folder = DATA_DIR . '/' . $archive_folder_name;
    
    // Klasör yoksa oluştur
    if (!is_dir($archive_folder)) {
        echo "[TEST] '$archive_folder_name' arşiv klasörü oluşturuluyor...\n";
        mkdir($archive_folder, 0755, true);
    } else {
        echo "[BİLGİ] Arşiv klasörü zaten var.\n";
    }
    
    // Ana dizindeki .json dosyalarını bul ve taşı
    $files = glob(DATA_DIR . '/*.json');
    $moved_count = 0;

    foreach ($files as $file) {
        // Zaten arşiv klasöründeyse veya alt klasördeyse atla
        if (dirname($file) !== '.') continue;
        
        $filename = basename($file);
        // Dosyayı taşı
        if(rename($file, $archive_folder . '/' . $filename)) {
            $moved_count++;
        }
    }
    
    if ($moved_count > 0) {
        echo "[TEST] $moved_count adet dosya '$archive_folder' içine taşındı. Ana dizin temizlendi.\n";
    } else {
        echo "[BİLGİ] Taşınacak dosya bulunamadı (Zaten taşınmış olabilir).\n";
    }
}

// Arşivlemeyi hemen başlat
archiveOldFiles();

// =============================================================
// 2. İNDİRİLECEK YILI BELİRLEME (TEST)
// =============================================================
// Normalde: $target_year = 2026;
// TEST İÇİN: Diyanet'te 2026 verisi olmadığı için 2025 verisini indiriyoruz.
// Böylece "Dosya indirildi" logunu görebileceğiz.
$target_year = 2025; 

echo "--------------------------------------------------\n";
echo "TEST MODU AKTİF\n";
echo "Hedef Yıl (Simüle): $target_year\n";
echo "Limit: " . BATCH_LIMIT . " ilçe\n";
echo "--------------------------------------------------\n";

// =============================================================
// 3. FONKSİYONLAR
// =============================================================

function fetchPrayerTimesHtml($district_id, $year) {
    $url = "https://namazvakitleri.diyanet.gov.tr/tr-TR/{$district_id}";
    $ch = curl_init($url);
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'Referer: https://namazvakitleri.diyanet.gov.tr/',
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
    return ($http_code === 200) ? $response : false;
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

// TAM LİSTE (Test için Adana ve Adıyaman yeterli, ama tam liste kalabilir)
$locations_json = '{
    "Adana": {"Adana":9146,"Aladağ":9147,"Ceyhan":9148,"Feke":9149,"İmamoğlu":9150,"Karaisalı":9151,"Karataş":9152,"Kozan":9153,"Pozantı":9154,"Saimbeyli":9155,"Tufanbeyli":9156,"Yumurtalık":9157},
    "Adıyaman": {"Adıyaman":9158,"Besni":9159,"Çelikhan":9160,"Gerger":9161,"Gölbaşı":9162,"Kahta":9163,"Samsat":9164,"Sincik":9165,"Tut":9166}
}'; 
// Not: Hızlı test için listeyi kısalttım. Çalıştığını görünce tam listeyi "Prod Sürüm"den alırsınız.
$locations = json_decode($locations_json, true);

// =============================================================
// 4. ANA İŞLEM DÖNGÜSÜ
// =============================================================

$downloaded_count = 0;

foreach ($locations as $province => $districts) {
    foreach ($districts as $district_name => $district_id) {
        
        if ($downloaded_count >= BATCH_LIMIT) {
            echo "--------------------------------------------------\n";
            echo "[TEST BİTTİ] Limit ($downloaded_count) kadar dosya indirildi.\n";
            exit;
        }

        $sanitized_name = sanitizeDistrictName($district_name);
        $filename = "{$sanitized_name}_{$district_id}.json";
        $filepath = DATA_DIR . '/' . $filename;

        // Dosya zaten varsa (Arşivlemeden sonra ana dizine yeni indi demek)
        if (file_exists($filepath) && filesize($filepath) > 0) {
            continue;
        }

        echo "İndiriliyor: $province - $district_name ($district_id) ... ";
        $html = fetchPrayerTimesHtml($district_id, $target_year);
        
        if ($html) {
            $data = parsePrayerTimes($html);
            if ($data && count($data) > 10) { 
                file_put_contents($filepath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                echo "OK (Yeni İndirildi - " . count($data) . " gün)\n";
                $downloaded_count++;
                sleep(rand(1, 3)); 
            } else {
                echo "HATA (Veri Yok)\n";
            }
        } else {
            echo "HATA (Bağlantı)\n";
        }
    }
}
?>
