<?php
// =============================================================
// NAMAZ VAKİTLERİ OTOMASYON BOTU (DEBUG SÜRÜMÜ)
// =============================================================
// HATA AYIKLAMA İÇİN HAZIRLANMIŞTIR.
// BAŞARISIZ OLURSA SAYFA BAŞLIĞINI YAZAR.

define('BATCH_LIMIT', 5); // Test için az sayıda ilçe
define('DATA_DIR', '.');

@set_time_limit(0);
ignore_user_abort(true);
date_default_timezone_set('Europe/Istanbul');
ini_set('display_errors', 1);
error_reporting(E_ALL);

// TEST İÇİN: 2025 verisini çekmeyi dene (Çünkü 2026 boş olabilir)
$target_year = 2025; 

echo "--------------------------------------------------\n";
echo "DEBUG MODU AKTİF\n";
echo "Hedef Yıl: $target_year\n";
echo "--------------------------------------------------\n";

// =============================================================
// FONKSİYONLAR
// =============================================================

function fetchPrayerTimesHtml($district_id, $year) {
    $url = "https://namazvakitleri.diyanet.gov.tr/tr-TR/{$district_id}";
    $ch = curl_init($url);
    
    // Tarayıcı gibi görünmek için detaylı başlıklar
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7',
        'Referer: https://namazvakitleri.diyanet.gov.tr/',
        'Origin: https://namazvakitleri.diyanet.gov.tr',
        'Connection: keep-alive',
        'Upgrade-Insecure-Requests: 1',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: same-origin',
        'Sec-Fetch-User: ?1'
    ];

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    // Bazı durumlarda cookie gerekebilir, basit bir cookie jar simülasyonu
    curl_setopt($ch, CURLOPT_COOKIEJAR, 'cookie.txt');
    curl_setopt($ch, CURLOPT_COOKIEFILE, 'cookie.txt');

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
    return "Başlık Yok";
}

function parsePrayerTimes($html) {
    if (empty($html)) return null;
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    $xpath = new DOMXPath($dom);
    
    // Tabloyu bulmaya çalış
    $rows = $xpath->query('//div[@id="tab-2"]//table/tbody/tr');
    
    if ($rows->length == 0) return null;

    $data = [];
    foreach ($rows as $row) {
        $cells = $row->getElementsByTagName('td');
        if ($cells->length >= 8) {
            $data[] = [
                "miladiTarih" => trim($cells->item(0)->nodeValue),
                "imsak"       => trim($cells->item(2)->nodeValue)
            ];
        }
    }
    return $data;
}

// İlçe Listesi (Sadece Test İçin Kısaltılmış)
$locations = json_decode('{
    "Adana": {"Adana":9146,"Aladağ":9147,"Ceyhan":9148},
    "Adıyaman": {"Adıyaman":9158}
}', true);

// =============================================================
// ANA DÖNGÜ
// =============================================================

$downloaded_count = 0;

foreach ($locations as $province => $districts) {
    foreach ($districts as $district_name => $district_id) {
        
        echo "İşleniyor: $province - $district_name ($district_id)... ";
        
        // 1-3 Saniye bekle (Seri istek atıp ban yememek için)
        sleep(rand(1, 3));

        $html = fetchPrayerTimesHtml($district_id, $target_year);
        
        if ($html) {
            $data = parsePrayerTimes($html);
            if ($data && count($data) > 10) { 
                echo "BAŞARILI (" . count($data) . " gün veri)\n";
            } else {
                // VERİ YOKSA NEDEN YOK?
                $title = getPageTitle($html);
                echo "HATA! (Veri tablosu bulunamadı)\n";
                echo "   -> Gelen Sayfa Başlığı: [$title]\n";
                echo "   -> (Eğer başlık 'Just a moment...', 'Attention Required' ise engellendik demektir.)\n";
            }
        } else {
            echo "HATA (Sunucuya Bağlanılamadı)\n";
        }
    }
}
?>
