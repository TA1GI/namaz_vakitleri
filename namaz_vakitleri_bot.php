<?php
// =============================================================
// NAMAZ VAKİTLERİ OTOMASYON BOTU (FİNAL PRODUCTION - v4.0)
// =============================================================

// AYARLAR
define('BATCH_LIMIT', 1000); // Tek seferde tüm Türkiye'yi (810+) denesin.
define('DATA_DIR', '.');     // Ana dizine kaydet.
define('MAX_CONSECUTIVE_ERRORS', 5); // Üst üste 5 hata alırsa ban yedik demektir, DUR.

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
    // Sadece Ocak ayının ilk 15 gününde çalışsın
    if (date('m') != '01' || date('d') > 15) return;

    $current_year = (int)date('Y'); 
    $last_year = $current_year - 1; 
    $archive_folder = DATA_DIR . '/' . $last_year;
    
    // Arşiv klasörü zaten varsa işlem yapma
    if (is_dir($archive_folder)) return;

    echo "[ARŞİV] $last_year klasörü oluşturuluyor...\n";
    mkdir($archive_folder, 0755, true);
    
    $files = glob(DATA_DIR . '/*.json');
    foreach ($files as $file) {
        // Alt klasörleri hariç tut
        if (dirname($file) !== '.') continue;
        rename($file, $archive_folder . '/' . basename($file));
    }
    echo "[ARŞİV] Eski dosyalar taşındı.\n";
}

archiveOldFiles();

// =============================================================
// 2. YIL BELİRLEME
// =============================================================
$target_year = (int)date('Y') + 1; 
// Ocak ayındaysak o yılı (2026) indir
if (date('m') == '01') {
    $target_year = (int)date('Y');
}

// TEST İÇİN MANUEL AYAR (İşiniz bitince bu satırı silin veya yorum yapın)
// $target_year = 2025; 

echo "Hedef Yıl: $target_year\n";
echo "Limit: " . BATCH_LIMIT . "\n";

// =============================================================
// 3. FONKSİYONLAR
// =============================================================

function fetchPrayerTimesHtml($district_id, $year) {
    $url = "https://namazvakitleri.diyanet.gov.tr/tr-TR/{$district_id}";
    $ch = curl_init($url);
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Referer: https://namazvakitleri.diyanet.gov.tr/',
        'Connection: keep-alive'
    ];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['year' => $year]));
    
    // Cookie simülasyonu
    curl_setopt($ch, CURLOPT_COOKIEJAR, 'cookie.txt');
    curl_setopt($ch, CURLOPT_COOKIEFILE, 'cookie.txt');

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

// TAM LİSTE
$locations_json = '{
    "Adana": {"Adana":9146,"Aladağ":9147,"Ceyhan":9148,"Feke":9149,"İmamoğlu":9150,"Karaisalı":9151,"Karataş":9152,"Kozan":9153,"Pozantı":9154,"Saimbeyli":9155,"Tufanbeyli":9156,"Yumurtalık":9157},
    "Adıyaman": {"Adıyaman":9158,"Besni":9159,"Çelikhan":9160,"Gerger":9161,"Gölbaşı":9162,"Kahta":9163,"Samsat":9164,"Sincik":9165,"Tut":9166},
    "Afyonkarahisar": {"Afyonkarahisar":9167,"Başmakçı":9168,"Bayat":9169,"Bolvadin":9170,"Çay":9171,"Çobanlar":9172,"Dazkırı":9173,"Dinar":9174,"Emirdağ":9175,"Evciler":9176,"Hocalar":9177,"İhsaniye":9178,"İscehisar":9179,"Kızılören":9180,"Sandıklı":9181,"Sinanpaşa":9182,"Şuhut":9183,"Sultandağı":9184},
    "Ağrı": {"Ağrı":9185,"Diyadin":9186,"Doğubayazıt":9187,"Eleşkirt":9188,"Patnos":9189,"Taşlıçay":9190,"Tutak":9191},
    "Aksaray": {"Ağaçören":9192,"Aksaray":9193,"Eskil":9194,"Gülağaç":9195,"Güzelyurt":9196,"Ortaköy":17877,"Sarıyahşi":9197,"Sultanhanı":20069},
    "Amasya": {"Amasya":9198,"Göynücek":9199,"Gümüşhacıköy":9200,"Hamamözü":9201,"Merzifon":9202,"Suluova":9203,"Taşova":9204},
    "Ankara": {"Akyurt":9205,"Ankara":9206,"Ayaş":9207,"Bala":9208,"Beypazarı":9209,"Çamlıdere":9210,"Çubuk":9211,"Elmadağ":9212,"Evren":9213,"Güdül":9214,"Haymana":9215,"Kahramankazan":9217,"Kalecik":9216,"Kızılcahamam":9218,"Nallıhan":9219,"Polatlı":9220,"Şereflikoçhisar":9221},
    "Antalya": {"Akseki":9222,"Aksu":9223,"Alanya":9224,"Antalya":9225,"Demre":9226,"Elmalı":9227,"Finike":9228,"Gazipaşa":9229,"Gündoğmuş":9230,"İbradı":9231,"Kaş":9232,"Kemer":9233,"Korkuteli":9234,"Kumluca":9235,"Manavgat":9236,"Serik":9237},
    "Ardahan": {"Ardahan":9238,"Çıldır":9239,"Damal":9240,"Göle":9241,"Hanak":9242,"Posof":9243},
    "Artvin": {"Ardanuç":9244,"Arhavi":9245,"Artvin":9246,"Borçka":9247,"Hopa":9248,"Kemalpaşa":20070,"Murgul":9249,"Şavşat":9250,"Yusufeli":9251},
    "Aydın": {"Aydın":9252,"Bozdoğan":9253,"Buharkent":9254,"Çine":9255,"Didim":9256,"Germencik":9257,"İncirliova":9258,"Karacasu":9259,"Karpuzlu":9260,"Koçarlı":9261,"Köşk":9262,"Kuşadası":9263,"Kuyucak":9264,"Nazilli":9265,"Söke":9266,"Sultanhisar":9267,"Yenipazar":9268},
    "Balıkesir": {"Ayvalık":9269,"Balıkesir":9270,"Balya":9271,"Bandırma":17917,"Bigadiç":9272,"Burhaniye":9273,"Dursunbey":9274,"Edremit":9275,"Erdek":17881,"Gömeç":9276,"Gönen":9277,"Havran":9278,"İvrindi":9279,"Kepsut":9280,"Manyas":17918,"Marmara":9281,"Savaştepe":9282,"Sındırgı":9283,"Susurluk":9284},
    "Bartın": {"Bartın":9285,"Kurucaşile":9286,"Ulus":9287},
    "Batman": {"Batman":9288,"Beşiri":9289,"Gercüş":9290,"Hasankeyf":9291,"Kozluk":9292,"Sason":9293},
    "Bayburt": {"Aydıntepe":9294,"Bayburt":9295,"Demirözü":9296},
    "Bilecik": {"Bilecik":9297,"Bozüyük":9298,"Gölpazarı":9299,"İnhisar":9300,"Osmaneli":17895,"Pazaryeri":17896,"Söğüt":9301,"Yenipazar":9302},
    "Bingöl": {"Adaklı":17889,"Bingöl":9303,"Karlıova":9304,"Kiğı":9305,"Solhan":9306,"Yayladere":9307,"Yedisu":9308},
    "Bitlis": {"Adilcevaz":9309,"Ahlat":9310,"Bitlis":9311,"Güroymak":17887,"Hizan":9312,"Mutki":9313,"Tatvan":9314},
    "Bolu": {"Bolu":9315,"Dörtdivan":9316,"Gerede":9317,"Göynük":9318,"Kıbrıscık":9319,"Mengen":9320,"Mudurnu":9321,"Seben":9322,"Yeniçağa":9323},
    "Burdur": {"Ağlasun":9324,"Altınyayla":9325,"Bucak":9326,"Burdur":9327,"Çavdır":9328,"Çeltikçi":9329,"Gölhisar":9330,"Karamanlı":9331,"Kemer":9332,"Tefenni":9333,"Yeşilova":9334},
    "Bursa": {"Bursa":9335,"Büyükorhan":9336,"Gemlik":9337,"Harmancık":9338,"İnegöl":9339,"İznik":9340,"Karacabey":9341,"Keles":9342,"Kestel":17893,"Mudanya":9343,"Mustafakemalpaşa":9344,"Orhaneli":17894,"Orhangazi":9345,"Yenişehir":9346},
    "Çanakkale": {"Ayvacık":9347,"Bayramiç":9348,"Biga":9349,"Bozcaada":9350,"Çan":9351,"Çanakkale":9352,"Ezine":17882,"Gelibolu":9353,"Gökçeada":9354,"Lapseki":9355,"Yenice":9356},
    "Çankırı": {"Atkaracalar":9357,"Bayramören":9358,"Çankırı":9359,"Çerkeş":9360,"Ilgaz":9361,"Kızılırmak":9362,"Kurşunlu":9363,"Orta":9364,"Şabanözü":9365,"Yapraklı":9366},
    "Çorum": {"Alaca":9367,"Bayat":9368,"Boğazkale":9369,"Çorum":9370,"Dodurga":9371,"İskilip":9372,"Kargı":9373,"Laçin":9374,"Mecitözü":9375,"Oğuzlar":9376,"Ortaköy":9377,"Osmancık":9378,"Sungurlu":9379,"Uğurludağ":9380},
    "Denizli": {"Acıpayam":19020,"Babadağ":9382,"Baklan":9383,"Bekilli":9384,"Beyağaç":9385,"Bozkurt":9386,"Buldan":9387,"Çal":9388,"Çameli":9389,"Çardak":9390,"Çivril":9391,"Denizli":9392,"Güney":9381,"Honaz":9393,"Kale":17899,"Sarayköy":9395,"Serinhisar":9396,"Tavas":17900},
    "Diyarbakır": {"Bismil":9397,"Çermik":9398,"Çınar":9399,"Çüngüş":9400,"Dicle":9401,"Diyarbakır":9402,"Eğil":9403,"Ergani":9404,"Hani":9405,"Hazro":9406,"Kocaköy":9407,"Kulp":9408,"Lice":9409,"Silvan":9410},
    "Düzce": {"Akçakoca":9411,"Çilimli":9412,"Cumayeri":9413,"Düzce":9414,"Gölyaka":9415,"Gümüşova":9416,"Kaynaşlı":9417,"Yığılca":9418},
    "Edirne": {"Edirne":9419,"Enez":9420,"Havsa":9421,"İpsala":9422,"Keşan":9423,"Lalapaşa":9424,"Meriç":9425,"Süloğlu":9426,"Uzunköprü":9427},
    "Elazığ": {"Ağın":9428,"Alacakaya":9429,"Arıcak":9430,"Baskil":9431,"Elazığ":9432,"Karakoçan":9433,"Keban":9434,"Kovancılar":9435,"Maden":9436,"Palu":9437,"Sivrice":9438},
    "Erzincan": {"Çayırlı":9439,"Erzincan":9440,"İliç":9441,"Kemah":9442,"Kemaliye":9443,"Otlukbeli":9444,"Refahiye":9445,"Tercan":9446,"Üzümlü":9447},
    "Erzurum": {"Aşkale":9448,"Aziziye":9449,"Çat":9450,"Erzurum":9451,"Hınıs":9452,"Horasan":9453,"İspir":9454,"Karaçoban":9455,"Karayazı":9456,"Köprüköy":9457,"Narman":9458,"Oltu":9459,"Olur":9460,"Pasinler":9461,"Pazaryolu":9462,"Şenkaya":9463,"Tekman":9464,"Tortum":9465,"Uzundere":9466},
    "Eskişehir": {"Alpu":9467,"Beylikova":9468,"Çifteler":9469,"Eskişehir":9470,"Günyüzü":9471,"Han":9472,"İnönü":9473,"Mahmudiye":9474,"Mihalgazi":33292,"Mihalıççık":9475,"Sarıcakaya":17919,"Seyitgazi":9476,"Sivrihisar":9477},
    "Gaziantep": {"Araban":9478,"Gaziantep":9479,"İslahiye":9480,"Karkamış":9481,"Nizip":9482,"Nurdağı":9483,"Oğuzeli":9484,"Yavuzeli":9485},
    "Giresun": {"Alucra":9486,"Bulancak":9487,"Çamoluk":9488,"Çanakçı":9489,"Dereli":9490,"Doğankent":9491,"Espiye":9492,"Eynesil":9493,"Giresun":9494,"Görele":9495,"Güce":9496,"Keşap":9497,"Piraziz":9498,"Şebinkarahisar":16706,"Tirebolu":9499,"Yağlıdere":9500},
    "Gümüşhane": {"Gümüşhane":9501,"Kelkit":16746,"Köse":9502,"Kürtün":9503,"Şiran":9504,"Torul":9505},
    "Hakkari": {"Çukurca":9506,"Derecik":20067,"Hakkari":9507,"Şemdinli":9508,"Yüksekova":9509},
    "Hatay": {"Altınözü":9510,"Arsuz":9515,"Belen":9511,"Dörtyol":9512,"Erzin":9513,"Hassa":9514,"Hatay":20089,"İskenderun":9516,"Kırıkhan":9517,"Kumlu":9518,"Payas":17810,"Reyhanlı":9519,"Samandağ":9520,"Yayladağı":16730},
    "Iğdır": {"Aralık":9521,"Iğdır":9522,"Karakoyunlu":9523,"Tuzluca":9524},
    "Isparta": {"Aksu":9525,"Atabey":17891,"Eğirdir":9526,"Gelendost":9527,"Gönen":17892,"Isparta":9528,"Keçiborlu":9529,"Şarkikaraağaç":9530,"Senirkent":17816,"Sütçüler":9531,"Uluborlu":9532,"Yalvaç":9533,"Yenişarbademli":9534},
    "İstanbul": {"Arnavutköy":9535,"Avcılar":17865,"Başakşehir":17866,"Beylikdüzü":9536,"Büyükçekmece":9537,"Çatalca":9538,"Çekmeköy":9539,"Esenyurt":9540,"İstanbul":9541,"Kartal":9542,"Küçükçekmece":9543,"Maltepe":9544,"Pendik":9545,"Sancaktepe":9546,"Şile":9547,"Silivri":9548,"Sultanbeyli":9549,"Sultangazi":9550,"Tuzla":9551},
    "İzmir": {"Aliağa":9552,"Bayındır":9553,"Bergama":9554,"Beydağ":9555,"Çeşme":9556,"Dikili":9557,"Foça":9558,"Güzelbahçe":9559,"İzmir":9560,"Karaburun":9561,"Kemalpaşa":9562,"Kınık":9563,"Kiraz":9564,"Menderes":17868,"Menemen":17869,"Ödemiş":9565,"Seferihisar":9566,"Selçuk":9567,"Tire":9568,"Torbalı":9569,"Urla":9570},
    "Kahramanmaraş": {"Afşin":9571,"Andırın":9572,"Çağlayancerit":9573,"Ekinözü":9574,"Elbistan":9575,"Göksun":9576,"Kahramanmaraş":9577,"Nurhak":9578,"Pazarcık":9579,"Türkoğlu":17908},
    "Karabük": {"Eflani":9580,"Eskipazar":17890,"Karabük":9581,"Ovacık":9582,"Yenice":9583},
    "Karaman": {"Ayrancı":9584,"Başyayla":9585,"Ermenek":9586,"Karaman":9587,"Kazımkarabekir":9588,"Sarıveliler":9589},
    "Kars": {"Akyaka":9590,"Arpaçay":9591,"Digor":9592,"Kağızman":9593,"Kars":9594,"Sarıkamış":9595,"Selim":9596,"Susuz":17880},
    "Kastamonu": {"Abana":9597,"Ağlı":9598,"Araç":9599,"Azdavay":9600,"Bozkurt":9601,"Çatalzeytin":9602,"Cide":9603,"Daday":9604,"Devrekani":17885,"Doğanyurt":9605,"Hanönü":9606,"İhsangazi":9607,"İnebolu":9608,"Kastamonu":9609,"Küre":9610,"Pınarbaşı":9611,"Şenpazar":9612,"Seydiler":17886,"Taşköprü":9613,"Tosya":9614},
    "Kayseri": {"Akkışla":9615,"Bünyan":9616,"Develi":9617,"Felahiye":9618,"İncesu":9619,"Kayseri":9620,"Özvatan":9621,"Pınarbaşı":9622,"Sarıoğlan":9623,"Sarız":9624,"Tomarza":9625,"Yahyalı":9626,"Yeşilhisar":9627},
    "Kilis": {"Elbeyli":9628,"Kilis":9629,"Musabeyli":9630,"Polateli":17907},
    "Kırıkkale": {"Balışeyh":9631,"Çelebi":9632,"Delice":9633,"Karakeçili":9634,"Keskin":17897,"Kırıkkale":9635,"Sulakyurt":9636},
    "Kırklareli": {"Babaeski":17903,"Demirköy":9637,"Kırklareli":9638,"Lüleburgaz":9639,"Pehlivanköy":9640,"Pınarhisar":9641,"Vize":9642},
    "Kırşehir": {"Akçakent":20039,"Akpınar":9643,"Çiçekdağı":9644,"Kaman":9645,"Kırşehir":9646,"Mucur":9647},
    "Kocaeli": {"Çayırova":9648,"Darıca":9649,"Dilovası":9650,"Gebze":9651,"Kandıra":9652,"Karamürsel":9653,"Kartepe":17902,"Kocaeli":9654,"Körfez":9655},
    "Konya": {"Ahırlı":9656,"Akören":9657,"Akşehir":9658,"Altınekin":9659,"Beyşehir":9660,"Bozkır":9661,"Çeltik":9662,"Cihanbeyli":9663,"Çumra":9664,"Derbent":9665,"Derebucak":9666,"Doğanhisar":9667,"Emirgazi":9668,"Ereğli":9669,"Güneysınır":9670,"Hadim":16704,"Halkapınar":9671,"Hüyük":9672,"Ilgın":9673,"Kadınhanı":9674,"Karapınar":9675,"Karatay":17872,"Konya":9676,"Kulu":9677,"Meram":17870,"Sarayönü":17874,"Selçuklu":17871,"Seydişehir":9678,"Taşkent":17873,"Tuzlukçu":9679,"Yalıhüyük":9680,"Yunak":9681},
    "Kütahya": {"Altıntaş":9682,"Aslanapa":9683,"Çavdarhisar":9684,"Domaniç":9685,"Dumlupınar":17906,"Emet":9686,"Gediz":9687,"Hisarcık":9688,"Kütahya":9689,"Pazarlar":9690,"Şaphane":9691,"Simav":9692,"Tavşanlı":9693},
    "Malatya": {"Akçadağ":9694,"Arapgir":9695,"Arguvan":9696,"Darende":9697,"Doğanşehir":9698,"Doğanyol":9699,"Hekimhan":9700,"Kale":9701,"Kuluncak":9702,"Malatya":9703,"Pütürge":9704,"Yazıhan":9705,"Yeşilyurt":9706},
    "Manisa": {"Ahmetli":9707,"Akhisar":9708,"Alaşehir":9709,"Demirci":9710,"Gölmarmara":9711,"Gördes":9712,"Kırkağaç":9713,"Köprübaşı":9714,"Kula":9715,"Manisa":9716,"Salihli":9717,"Sarıgöl":9718,"Saruhanlı":9719,"Selendi":9720,"Soma":9721,"Turgutlu":9722},
    "Mardin": {"Dargeçit":9723,"Derik":9724,"Kızıltepe":9725,"Mardin":9726,"Mazıdağı":9727,"Midyat":9728,"Nusaybin":9729,"Ömerli":9730,"Savur":17901},
    "Mersin": {"Anamur":9731,"Aydıncık":9732,"Bozyazı":9733,"Çamlıyayla":9734,"Erdemli":9735,"Gülnar":9736,"Mersin":9737,"Mut":9738,"Silifke":9739,"Tarsus":9740},
    "Muğla": {"Bodrum":9741,"Dalaman":9742,"Datça":9743,"Fethiye":9744,"Köyceğiz":9745,"Marmaris":17883,"Milas":9746,"Muğla":9747,"Ortaca":9748,"Seydikemer":17884,"Ula":9749,"Yatağan":9750},
    "Muş": {"Bulanık":9751,"Hasköy":9752,"Korkut":9753,"Malazgirt":9754,"Muş":9755,"Varto":9756},
    "Nevşehir": {"Acıgöl":9757,"Avanos":17878,"Hacıbektaş":9758,"Kozaklı":9759,"Nevşehir":9760,"Ürgüp":9761},
    "Niğde": {"Altunhisar":9762,"Bor":9763,"Çamardı":9764,"Çiftlik":9765,"Niğde":9766,"Ulukışla":9767},
    "Ordu": {"Akkuş":9768,"Aybastı":9769,"Çamaş":9770,"Çatalpınar":9771,"Çaybaşı":9772,"Fatsa":9773,"Gölköy":9774,"Gülyalı":9775,"Gürgentepe":9776,"İkizce":9777,"Kabataş":9778,"Korgan":9779,"Kumru":9780,"Mesudiye":9781,"Ordu":9782,"Ünye":9783},
    "Osmaniye": {"Bahçe":9784,"Düziçi":9785,"Hasanbeyli":9786,"Kadirli":9787,"Osmaniye":9788,"Sumbas":9789,"Toprakkale":9790},
    "Rize": {"Ardeşen":9791,"Çamlıhemşin":9792,"Çayeli":9793,"Fındıklı":9794,"Hemşin":9795,"İkizdere":9796,"İyidere":9797,"Pazar":9798,"Rize":9799},
    "Sakarya": {"Akyazı":9800,"Geyve":9801,"Hendek":9802,"Karasu":9803,"Kaynarca":9804,"Kocaali":9805,"Pamukova":9806,"Sakarya":9807,"Taraklı":9808},
    "Samsun": {"19 Mayıs":9809,"Alaçam":9810,"Asarcık":9811,"Atakum":17911,"Ayvacık":9812,"Bafra":9813,"Çarşamba":9814,"Havza":9815,"Kavak":9816,"Ladik":9817,"Salıpazarı":9818,"Samsun":9819,"Tekkeköy":9820,"Terme":9821,"Vezirköprü":9822,"Yakakent":9823},
    "Şanlıurfa": {"Akçakale":9824,"Birecik":9825,"Bozova":9826,"Ceylanpınar":9827,"Halfeti":9828,"Harran":9829,"Hilvan":9830,"Şanlıurfa":9831,"Siverek":9832,"Suruç":9833,"Viranşehir":9834},
    "Siirt": {"Baykan":9835,"Eruh":9836,"Kurtalan":9837,"Pervari":9838,"Siirt":9839,"Şirvan":17888},
    "Sinop": {"Ayancık":9840,"Boyabat":9841,"Dikmen":9842,"Durağan":9843,"Erfelek":9844,"Gerze":9845,"Saraydüzü":9846,"Sinop":9847,"Türkeli":9848},
    "Şırnak": {"Beytüşşebap":9849,"Cizre":9850,"Güçlükonak":9851,"İdil":9852,"Silopi":9853,"Şırnak":9854,"Uludere":9855},
    "Sivas": {"Akıncılar":9856,"Altınyayla":9857,"Divriği":9858,"Doğanşar":9859,"Gemerek":9860,"Gölova":9861,"Gürün":9862,"Hafik":9863,"İmranlı":9864,"Kangal":9865,"Koyulhisar":9866,"Şarkışla":9867,"Sivas":9868,"Suşehri":9869,"Ula":17920,"Yıldızeli":9870,"Zara":9871},
    "Tekirdağ": {"Çerkezköy":9872,"Çorlu":9873,"Ergene":17904,"Hayrabolu":9874,"Kapaklı":17905,"Marmaraereğlisi":9875,"Malkara":9876,"Saray":9877,"Şarköy":9878,"Tekirdağ":9879},
    "Tokat": {"Almus":9880,"Artova":9881,"Başçiftlik":9882,"Erbaa":17910,"Niksar":9883,"Pazar":9884,"Reşadiye":9885,"Sulusaray":9886,"Tokat":9887,"Turhal":9888,"Yeşilyurt":9889,"Zile":9890},
    "Trabzon": {"Akçaabat":9891,"Araklı":9892,"Arsin":9893,"Beşikdüzü":9894,"Çarşıbaşı":9895,"Çaykara":9896,"Dernekpazarı":9897,"Düzköy":9898,"Hayrat":9899,"Köprübaşı":9900,"Of":9901,"Şalpazarı":9902,"Sürmene":9903,"Tonya":9904,"Trabzon":9905,"Vakfıkebir":9906,"Yomra":9907},
    "Tunceli": {"Çemişgezek":9908,"Hozat":9909,"Nazımiye":9910,"Ovacık":9911,"Pertek":9912,"Pülümür":9913,"Tunceli":9914},
    "Uşak": {"Banaz":9915,"Eşme":9916,"Karahallı":9917,"Sivaslı":9918,"Ulubey":17909,"Uşak":9919},
    "Van": {"Bahçesaray":9920,"Başkale":9921,"Çaldıran":9922,"Çatak":9923,"Edremit":9924,"Erciş":9925,"Gevaş":9926,"Gürpınar":17912,"Muradiye":9927,"Özalp":9928,"Saray":9929,"Van":9930},
    "Yalova": {"Altınova":9931,"Armutlu":9932,"Çınarcık":9933,"Termal":9934,"Yalova":9935},
    "Yozgat": {"Akdağmadeni":9936,"Aydıncık":9937,"Boğazlıyan":9938,"Çandır":9939,"Çayıralan":9940,"Çekerek":9941,"Kadışehri":9942,"Saraykent":9943,"Sarıkaya":9944,"Şefaatli":17879,"Sorgun":9946,"Yenifakılı":9947,"Yerköy":9948,"Yozgat":9949},
    "Zonguldak": {"Alaplı":9950,"Çaycuma":9951,"Devrek":9952,"Gökçebey":9953,"Karadeniz Ereğli":9954,"Zonguldak":9955}
}';
$locations = json_decode($locations_json, true);

// =============================================================
// 4. ANA İŞLEM DÖNGÜSÜ
// =============================================================

$downloaded_count = 0;
$consecutive_errors = 0; // Üst üste hata sayacı

foreach ($locations as $province => $districts) {
    foreach ($districts as $district_name => $district_id) {
        
        // Kotayı ve Hata Limitini kontrol et
        if ($downloaded_count >= BATCH_LIMIT) {
            echo "--------------------------------------------------\n";
            echo "[LİMİT] Kota doldu ($downloaded_count ilçe). İşlem durduruluyor.\n";
            exit;
        }
        
        if ($consecutive_errors >= MAX_CONSECUTIVE_ERRORS) {
            echo "--------------------------------------------------\n";
            echo "[HATA] Üst üste $consecutive_errors kez hata alındı (IP Engeli olabilir). İşlem durduruluyor.\n";
            exit;
        }

        $sanitized_name = sanitizeDistrictName($district_name);
        $filename = "{$sanitized_name}_{$district_id}.json";
        $filepath = DATA_DIR . '/' . $filename;

        // İÇERİK KONTROLÜ
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
            if ($data && count($data) > 10) { 
                if(file_put_contents($filepath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
                    echo "OK (" . count($data) . " gün veri)\n";
                    $downloaded_count++;
                    $consecutive_errors = 0; // Başarılı olunca sayacı sıfırla
                } else {
                    echo "HATA (Dosya yazılamadı)\n";
                }
                sleep(rand(2, 5));
            } else {
                echo "HATA (Veri Yok)\n";
                $consecutive_errors++; // Hata sayacını artır
            }
        } else {
            echo "HATA (Bağlantı)\n";
            $consecutive_errors++; // Hata sayacını artır
        }
    }
}

if ($downloaded_count == 0) {
    echo "Tüm ilçeler zaten güncel!\n";
}
?>