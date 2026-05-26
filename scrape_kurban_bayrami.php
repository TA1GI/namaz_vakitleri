<?php
// =============================================================
// KURBAN BAYRAMI NAMAZ VAKTİ OTOMASYON BOTU
// =============================================================
// GitHub Actions tarafından her gün çalıştırılır.
// Ancak internete SADECE Kurban Bayramı'na 7 gün veya daha az
// kaldığında bağlanır. Geri kalan ~358 gün hiçbir istek yapmaz,
// sadece lokal dosyaya bakıp saniyelik tarih kontrolü yapar.
//
// Akış:
//   1. ARNAVUTKOY_9535.json'dan "10 Zilhicce"yi bulup miladi tarihini al
//   2. Bugünle karşılaştır → 7 günden fazla varsa kapat
//   3. 7 gün veya az kaldıysa → Diyanet'ten kurban vakitlerini çek
//   4. Verileri mevcut bayram_namazi.json'a ekle (ramazan verisinin yanına)
// =============================================================

@set_time_limit(0);
ignore_user_abort(true);
date_default_timezone_set('Europe/Istanbul');

$repo_root = __DIR__;
$reference_file = $repo_root . '/ARNAVUTKOY_9535.json';
$bayram_file = $repo_root . '/bayram_namazi.json';

// =============================================================
// ADIM 1: REFERANS DOSYADAN KURBAN BAYRAMI TARİHİNİ BUL
// =============================================================
echo "=== KURBAN BAYRAMI NAMAZ VAKTİ BOTU ===\n\n";

if (!file_exists($reference_file)) {
    echo "❌ Referans dosya bulunamadı: ARNAVUTKOY_9535.json\n";
    echo "   Henüz yıllık namaz vakitleri çekilmemiş olabilir.\n";
    exit(0);
}

$data = json_decode(file_get_contents($reference_file), true);
if (!$data || !is_array($data)) {
    echo "❌ Referans dosya okunamadı veya geçersiz JSON.\n";
    exit(1);
}

// "10 Zilhicce" olan günü bul (Kurban Bayramı = 10 Zilhicce)
$kurban_miladi = null;
$current_year = date('Y');

foreach ($data as $entry) {
    if (isset($entry['hicriTarih']) && preg_match('/^10\s+Zilhicce/u', $entry['hicriTarih'])) {
        // Sadece bulunduğumuz yılın Kurban Bayramını dikkate al
        if (strpos($entry['miladiTarih'], $current_year) !== false) {
            $kurban_miladi = $entry['miladiTarih'];
            break;
        }
    }
}

if (!$kurban_miladi) {
    echo "❌ 10 Zilhicce (Kurban Bayramı) tarihi referans dosyada bulunamadı.\n";
    exit(0);
}

echo "📅 Kurban Bayramı (Hicri) : 10 Zilhicce\n";
echo "📅 Kurban Bayramı (Miladi): $kurban_miladi\n";

// =============================================================
// ADIM 2: MİLADİ TARİHİ PARSE ET VE GÜN FARKI HESAPLA
// =============================================================
$turkish_months = [
    'Ocak' => 1, 'Şubat' => 2, 'Mart' => 3, 'Nisan' => 4,
    'Mayıs' => 5, 'Haziran' => 6, 'Temmuz' => 7, 'Ağustos' => 8,
    'Eylül' => 9, 'Ekim' => 10, 'Kasım' => 11, 'Aralık' => 12
];

// Format: "27 Mayıs 2026 Çarşamba" veya "06 Haziran 2025 Cuma"
if (!preg_match('/(\d{1,2})\s+(\S+)\s+(\d{4})/u', $kurban_miladi, $m)) {
    echo "❌ Tarih parse edilemedi: $kurban_miladi\n";
    exit(1);
}

$day = (int)$m[1];
$month_name = $m[2];
$year = (int)$m[3];

if (!isset($turkish_months[$month_name])) {
    echo "❌ Ay adı tanınamadı: $month_name\n";
    exit(1);
}

$month = $turkish_months[$month_name];
$kurban_date = new DateTime("$year-$month-$day", new DateTimeZone('Europe/Istanbul'));
$today = new DateTime('now', new DateTimeZone('Europe/Istanbul'));
$today->setTime(0, 0, 0);

$diff = $today->diff($kurban_date);
$days_until = $diff->invert ? -$diff->days : $diff->days;

echo "📆 Bugün              : " . $today->format('d.m.Y') . "\n";
echo "⏳ Bayrama kalan gün  : $days_until\n\n";

// =============================================================
// ADIM 3: ZAMAN PENCERESİ KONTROLÜ
// =============================================================
if ($days_until > 7) {
    echo "😴 Kurban Bayramı'na daha $days_until gün var. Henüz erken.\n";
    echo "   7 gün veya daha az kalınca otomatik çalışacak.\n";
    exit(0);
}

if ($days_until < -3) {
    echo "✅ Kurban Bayramı geçmiş. Bu yıl için işlem tamamlanmış.\n";
    exit(0);
}

echo "🚀 Kurban Bayramı'na $days_until gün kaldı! Kontrol başlıyor...\n\n";

// =============================================================
// ADIM 4: BAYRAM_NAMAZI.JSON KONTROLÜ
// =============================================================
if (!file_exists($bayram_file)) {
    echo "❌ bayram_namazi.json dosyası bulunamadı.\n";
    echo "   Önce Ramazan Bayramı verilerinin çekilmiş olması gerekir.\n";
    exit(1);
}

$bayram_data = json_decode(file_get_contents($bayram_file), true);
if (!$bayram_data || !is_array($bayram_data)) {
    echo "❌ bayram_namazi.json okunamadı veya geçersiz JSON.\n";
    exit(1);
}

// Kurban verisi zaten eklenmişse tekrar çekme
$sample_key = array_key_first($bayram_data);
if (isset($bayram_data[$sample_key]['kurban']) && !empty($bayram_data[$sample_key]['kurban'])) {
    echo "✅ Kurban Bayramı vakitleri zaten bayram_namazi.json'da mevcut.\n";
    echo "   Tekrar çekmeye gerek yok.\n";
    exit(0);
}

$district_ids = array_keys($bayram_data);
$total = count($district_ids);
echo "📋 bayram_namazi.json'dan $total ilçe ID'si okundu.\n";
echo "🌐 Diyanet'ten Kurban Bayramı vakitleri çekiliyor...\n\n";

// =============================================================
// ADIM 5: DİYANET'TEN KURBAN VAKİTLERİNİ ÇEK
// =============================================================
function fetchPageHtml($district_id) {
    $url = "https://namazvakitleri.diyanet.gov.tr/tr-TR/{$district_id}";
    $ch = curl_init($url);
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Referer: https://namazvakitleri.diyanet.gov.tr/',
        'Connection: keep-alive'
    ];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($http_code === 200) ? $response : false;
}

$success = 0;
$errors = 0;
$consecutive_errors = 0;

foreach ($district_ids as $idx => $district_id) {
    $num = $idx + 1;
    echo "[$num/$total] İlçe $district_id ... ";
    
    $html = fetchPageHtml($district_id);
    
    if ($html && preg_match('/data-bayram="kurban".*?bayram-info-value-top">([^<]+)<\/span>.*?bayram-info-value-top">([\d:]+)[^<]*<\/span>/s', $html, $matches)) {
        $tarih = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
        $vakit = trim($matches[2]);
        
        // Saat formatını normalize et: "06:12:00" → "06:12"
        if (substr_count($vakit, ':') === 2) {
            $vakit = substr($vakit, 0, 5);
        }
        
        $bayram_data[$district_id]['kurban'] = $vakit;
        $bayram_data[$district_id]['kurban_tarih'] = $tarih;
        
        echo "✅ $vakit\n";
        $success++;
        $consecutive_errors = 0;
    } else {
        echo "❌\n";
        $errors++;
        $consecutive_errors++;
    }
    
    // Her 50 ilçede bir ara kayıt (çökmeye karşı koruma)
    if ($num % 50 === 0) {
        file_put_contents($bayram_file, json_encode($bayram_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "--- Ara kayıt yapıldı ($num/$total) ---\n";
    }
    
    // Üst üste 10 hata → muhtemelen IP banı, dur
    if ($consecutive_errors >= 10) {
        echo "\n❌ Üst üste 10 hata! Muhtemelen IP banı. Durduruluyor.\n";
        file_put_contents($bayram_file, json_encode($bayram_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        exit(1);
    }
    
    // İstekler arası 200ms bekleme (Diyanet'i yormamak için)
    usleep(200000);
}

// =============================================================
// ADIM 6: SONUÇLARI KAYDET
// =============================================================
file_put_contents($bayram_file, json_encode($bayram_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\n" . str_repeat('=', 50) . "\n";
echo "✅ İşlem Tamamlandı!\n";
echo "   Başarılı: $success / $total\n";
echo "   Hata: $errors\n";
echo "   Kaydedildi: bayram_namazi.json\n";
echo str_repeat('=', 50) . "\n";
?>
