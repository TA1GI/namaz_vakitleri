#!/usr/bin/env python3
"""
Bayram Namazı Vakitleri Scraper
===============================
Diyanet İşleri Başkanlığı'nın imsakiye sayfasından
tüm il/ilçeler için bayram namazı vakitlerini çeker.

Kaynak: https://kurul.diyanet.gov.tr/Sayfalar/Imsakiye.aspx

Çıktı: bayram_namazi.json
Format: { "ilçe_id": { "ramazan": "07:23", "tarih": "20 Mart 2026 Cuma" }, ... }
"""

import requests
import re
import json
import time
import sys
import html
import os
import shutil
from html.parser import HTMLParser

URL = "https://kurul.diyanet.gov.tr/Sayfalar/Imsakiye.aspx"

# Tüm HTTP isteklerinde kullanılacak headers
HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7",
    "Content-Type": "application/x-www-form-urlencoded",
    "Origin": "https://kurul.diyanet.gov.tr",
    "Referer": "https://kurul.diyanet.gov.tr/Sayfalar/Imsakiye.aspx"
}

# ASP.NET form field isimleri
FIELD_COUNTRY = "ctl00$ctl00$cphMainSlider$solIcerik$ddlUlkeler"
FIELD_CITY = "ctl00$ctl00$cphMainSlider$solIcerik$ddlSehirler"
FIELD_DISTRICT = "ctl00$ctl00$cphMainSlider$solIcerik$ddlIlceler"
FIELD_EVENTTARGET = "__EVENTTARGET"
FIELD_EVENTARGUMENT = "__EVENTARGUMENT"
FIELD_VIEWSTATE = "__VIEWSTATE"
FIELD_VIEWSTATEGENERATOR = "__VIEWSTATEGENERATOR"
FIELD_EVENTVALIDATION = "__EVENTVALIDATION"


def extract_hidden_fields(page_html):
    """Sayfadan ASP.NET gizli form alanlarını çıkarır."""
    fields = {}
    for field_name in [FIELD_VIEWSTATE, FIELD_VIEWSTATEGENERATOR, FIELD_EVENTVALIDATION]:
        pattern = rf'id="{field_name}"\s+value="([^"]*)"'
        match = re.search(pattern, page_html)
        if match:
            fields[field_name] = match.group(1)
        else:
            # name ile de deneyelim
            pattern2 = rf'name="{re.escape(field_name)}"\s[^>]*value="([^"]*)"'
            match2 = re.search(pattern2, page_html)
            if match2:
                fields[field_name] = match2.group(1)
    return fields


def extract_select_options(page_html, select_name):
    """Bir <select> elemanının option'larını çıkarır."""
    # Select bloğunu bul
    pattern = rf'<select[^>]*name="{re.escape(select_name)}"[^>]*>(.*?)</select>'
    match = re.search(pattern, page_html, re.DOTALL)
    if not match:
        return []
    
    select_html = match.group(1)
    options = []
    # Her option'ı parse et
    for opt_match in re.finditer(r'<option\s+(?:selected="selected"\s+)?value="(\d+)"[^>]*>([^<]+)</option>', select_html):
        value = opt_match.group(1)
        label = html.unescape(opt_match.group(2)).strip()
        options.append((value, label))
    
    return options


def extract_bayram_info(page_html):
    """Sayfadan bayram namazı bilgisini çıkarır.
    
    Örnek HTML:
    <b>20 Mart 2026 Cuma<span> Ramazan Bayramının 1.Günüdür </span><br /><br />
    <span>Bayram Namazı :</span>07:23</b>
    """
    # Bayram Namazı saatini bul
    namaz_match = re.search(
        r'Bayram\s+Namaz[ıi]\s*:\s*</span>\s*(\d{2}:\d{2})',
        page_html,
        re.IGNORECASE
    )
    
    # Tarih bilgisini bul
    tarih_match = re.search(
        r'<b>\s*(\d{1,2}\s+\w+\s+\d{4}\s+\w+)\s*<span>\s*Ramazan\s+Bayram',
        page_html,
        re.IGNORECASE
    )
    
    if namaz_match:
        result = {"ramazan": namaz_match.group(1)}
        if tarih_match:
            result["tarih"] = tarih_match.group(1).strip()
        return result
    
    return None


def archive_existing_bayram_file():
    """Eğer eski bir bayram_namazi.json varsa, içindeki yıla bakarak ilgili yılın klasörüne taşır."""
    file_path = "bayram_namazi.json"
    if not os.path.exists(file_path):
        return

    try:
        with open(file_path, "r", encoding="utf-8") as f:
            data = json.load(f)
            
        if not data:
            return
            
        # İlk ilçenin verisini alıp tarihi bulalım
        sample_key = list(data.keys())[0]
        sample_entry = data[sample_key]
        tarih = sample_entry.get("tarih", "")
        
        # Tarih formatı: "20 Mart 2026 Cuma" -> yılı çek
        year = None
        for word in tarih.split():
            if word.isdigit() and len(word) == 4:
                year = word
                break
                
        if year:
            archive_dir = str(year)
            if not os.path.exists(archive_dir):
                os.makedirs(archive_dir)
            
            archive_path = os.path.join(archive_dir, "bayram_namazi.json")
            shutil.move(file_path, archive_path)
            print(f"📦 Eski bayram verisi arşivlendi: {archive_path}")
            
    except Exception as e:
        print(f"⚠️ Eski bayram dosyası arşivlenirken hata oluştu: {e}")


def scrape_all():
    """Tüm il/ilçeler için bayram namazı vakitlerini çeker."""
    archive_existing_bayram_file()
    
    session = requests.Session()
    result = {}
    
    # 1. İlk GET isteği — sayfayı yükle, cookie ve token al
    print("🔄 Sayfa yükleniyor...")
    resp = session.get(URL, headers=HEADERS, timeout=30)
    resp.raise_for_status()
    page = resp.text
    
    hidden = extract_hidden_fields(page)
    print(f"   ✅ Gizli alanlar bulundu: {list(hidden.keys())}")
    
    # İlk yüklenen il/ilçe bilgisini al (varsayılan: Ankara)
    cities = extract_select_options(page, FIELD_CITY)
    print(f"   ✅ {len(cities)} il bulundu")
    
    if not cities:
        print("   ❌ İl listesi bulunamadı!")
        sys.exit(1)
    
    # Varsayılan ilçe listesini al (Ankara)
    districts = extract_select_options(page, FIELD_DISTRICT)
    
    # Varsayılan ilçenin bayram bilgisini al
    bayram = extract_bayram_info(page)
    if bayram and districts:
        # Varsayılan seçili ilçeyi bul
        default_district_match = re.search(
            rf'<select[^>]*name="{re.escape(FIELD_DISTRICT)}"[^>]*>.*?'
            r'<option\s+selected="selected"\s+value="(\d+)"',
            page, re.DOTALL
        )
        if default_district_match:
            did = default_district_match.group(1)
            result[did] = bayram
            print(f"   ✅ Varsayılan ilçe ({did}): {bayram['ramazan']}")
    
    total_cities = len(cities)
    total_districts = 0
    errors = 0
    
    # 2. Her il için işlem yap
    for city_idx, (city_id, city_name) in enumerate(cities):
        print(f"\n📍 [{city_idx+1}/{total_cities}] {city_name} (ID: {city_id})")
        
        # İl seçimi POST
        post_data = {
            FIELD_EVENTTARGET: FIELD_CITY,
            FIELD_EVENTARGUMENT: "",
            FIELD_COUNTRY: "2",  # Türkiye
            FIELD_CITY: city_id,
        }
        post_data.update(hidden)
        
        try:
            resp = session.post(URL, data=post_data, headers=HEADERS, timeout=30)
            resp.raise_for_status()
            page = resp.text
            hidden = extract_hidden_fields(page)
        except Exception as e:
            print(f"   ❌ İl seçimi hatası: {e}")
            errors += 1
            time.sleep(2)
            continue
        
        # Bu ilin ilçelerini al
        districts = extract_select_options(page, FIELD_DISTRICT)
        print(f"   📋 {len(districts)} ilçe bulundu")
        
        if not districts:
            print(f"   ⚠️  İlçe bulunamadı, atlanıyor...")
            time.sleep(1)
            continue
        
        # İl seçildiğinde ilk ilçe otomatik yüklenir — bayram bilgisini al
        bayram = extract_bayram_info(page)
        first_district_match = re.search(
            rf'<select[^>]*name="{re.escape(FIELD_DISTRICT)}"[^>]*>.*?'
            r'<option\s+(?:selected="selected"\s+)?value="(\d+)"',
            page, re.DOTALL
        )
        if bayram and first_district_match:
            did = first_district_match.group(1)
            result[did] = bayram
            print(f"   ✅ {did}: {bayram['ramazan']}")
            total_districts += 1
            first_district_id = did
        else:
            first_district_id = None
        
        # Diğer ilçeler için tek tek POST
        for dist_idx, (dist_id, dist_name) in enumerate(districts):
            if dist_id == first_district_id:
                continue  # İlk ilçe zaten alındı
            
            post_data = {
                FIELD_EVENTTARGET: FIELD_DISTRICT,
                FIELD_EVENTARGUMENT: "",
                FIELD_COUNTRY: "2",
                FIELD_CITY: city_id,
                FIELD_DISTRICT: dist_id,
            }
            post_data.update(hidden)
            
            try:
                resp = session.post(URL, data=post_data, headers=HEADERS, timeout=30)
                resp.raise_for_status()
                page = resp.text
                hidden = extract_hidden_fields(page)
                
                bayram = extract_bayram_info(page)
                if bayram:
                    result[dist_id] = bayram
                    print(f"   ✅ {dist_name} ({dist_id}): {bayram['ramazan']}")
                    total_districts += 1
                else:
                    print(f"   ⚠️  {dist_name} ({dist_id}): Bayram bilgisi bulunamadı")
                    errors += 1
                    
            except Exception as e:
                print(f"   ❌ {dist_name} ({dist_id}): {e}")
                errors += 1
            
            # Rate limit: istekler arası bekleme
            time.sleep(1.5)
        
        # İller arası bekleme
        time.sleep(1)
    
    # 3. Sonuçları kaydet
    output_file = "bayram_namazi.json"
    with open(output_file, "w", encoding="utf-8") as f:
        json.dump(result, f, ensure_ascii=False, indent=2)
    
    print(f"\n{'='*50}")
    print(f"✅ Tamamlandı!")
    print(f"   📊 Toplam ilçe: {total_districts}")
    print(f"   ❌ Hata: {errors}")
    print(f"   💾 Kaydedildi: {output_file}")
    print(f"{'='*50}")
    
    return result


if __name__ == "__main__":
    scrape_all()
