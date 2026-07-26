#!/usr/bin/env python3
import json
import os
import time
import subprocess
from datetime import datetime

# Paths (assuming script is run from repo root 'githup/')
ARNAVUTKOY_FILE = 'ARNAVUTKOY_9535.json'
BAYRAM_JSON_FILE = 'bayram_namazi.json'
PING_FILE = 'ping.txt'
RAMAZAN_SCRAPER = ['python', 'scripts/scrape_bayram_namazi.py']
KURBAN_SCRAPER = ['php', 'scrape_kurban_bayrami.php']

TURKISH_MONTHS = {
    'Ocak': 1, 'Şubat': 2, 'Mart': 3, 'Nisan': 4,
    'Mayıs': 5, 'Haziran': 6, 'Temmuz': 7, 'Ağustos': 8,
    'Eylül': 9, 'Ekim': 10, 'Kasım': 11, 'Aralık': 12
}

def parse_turkish_date(date_str):
    """Parses '20 Mart 2026 Cuma' into a datetime object."""
    try:
        parts = date_str.strip().split()
        day = int(parts[0])
        month_name = parts[1]
        year = int(parts[2])
        month = TURKISH_MONTHS.get(month_name)
        if not month:
            return None
        return datetime(year, month, day)
    except Exception as e:
        print(f"Date parsing error for '{date_str}': {e}")
        return None

def get_bayram_dates(current_year):
    """Finds Ramazan and Kurban dates from Arnavutkoy JSON for the given year."""
    if not os.path.exists(ARNAVUTKOY_FILE):
        print(f"Error: {ARNAVUTKOY_FILE} not found.")
        return None, None
        
    with open(ARNAVUTKOY_FILE, 'r', encoding='utf-8') as f:
        data = json.load(f)
        
    ramazan_date = None
    kurban_date = None
    
    for entry in data:
        hicri = entry.get('hicriTarih', '')
        miladi = entry.get('miladiTarih', '')
        
        if str(current_year) in miladi:
            if hicri.startswith('1 Şevval'):
                ramazan_date = parse_turkish_date(miladi)
            elif hicri.startswith('10 Zilhicce'):
                kurban_date = parse_turkish_date(miladi)
                
    return ramazan_date, kurban_date

def check_if_data_exists(current_year, check_type):
    """Checks if we already have the data for the current year."""
    if not os.path.exists(BAYRAM_JSON_FILE):
        return False
        
    with open(BAYRAM_JSON_FILE, 'r', encoding='utf-8') as f:
        try:
            data = json.load(f)
        except json.JSONDecodeError:
            return False
            
    if not data:
        return False
        
    # Check the first district to see if it has the current year's data
    sample_key = list(data.keys())[0]
    sample_entry = data[sample_key]
    
    if check_type == 'ramazan':
        tarih = sample_entry.get('tarih', '')
        return str(current_year) in tarih and 'ramazan' in sample_entry
    elif check_type == 'kurban':
        tarih = sample_entry.get('kurban_tarih', '')
        return str(current_year) in tarih and 'kurban' in sample_entry
        
    return False

def update_ping():
    """Updates ping.txt if it's older than 30 days to keep GitHub repo active."""
    need_ping = False
    if not os.path.exists(PING_FILE):
        need_ping = True
    else:
        mtime = os.path.getmtime(PING_FILE)
        days_old = (time.time() - mtime) / (24 * 3600)
        if days_old > 30:
            need_ping = True
            
    if need_ping:
        print("Updating ping.txt to keep repo alive...")
        with open(PING_FILE, 'w', encoding='utf-8') as f:
            f.write(f"Last keep-alive ping: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")
            
def main():
    print("=== BAYRAM KONTROL VE OTOMASYON SİSTEMİ ===")
    today = datetime.now()
    current_year = today.year
    
    ramazan_date, kurban_date = get_bayram_dates(current_year)
    
    action_taken = False
    
    if ramazan_date:
        days_to_ramazan = (ramazan_date - today).days
        print(f"Ramazan Bayramı ({ramazan_date.strftime('%Y-%m-%d')}): {days_to_ramazan} gün kaldı.")
        
        if 0 <= days_to_ramazan <= 15:
            if not check_if_data_exists(current_year, 'ramazan'):
                print("-> Ramazan Bayramı yaklaştı ve veri henüz çekilmemiş. Bot başlatılıyor...")
                subprocess.run(RAMAZAN_SCRAPER)
                action_taken = True
            else:
                print("-> Ramazan Bayramı verisi bu yıl için zaten çekilmiş. İşlem atlanıyor.")
                
    if kurban_date:
        days_to_kurban = (kurban_date - today).days
        print(f"Kurban Bayramı ({kurban_date.strftime('%Y-%m-%d')}): {days_to_kurban} gün kaldı.")
        
        if 0 <= days_to_kurban <= 15:
            if not check_if_data_exists(current_year, 'kurban'):
                print("-> Kurban Bayramı yaklaştı ve veri henüz çekilmemiş. Bot başlatılıyor...")
                subprocess.run(KURBAN_SCRAPER)
                action_taken = True
            else:
                print("-> Kurban Bayramı verisi bu yıl için zaten çekilmiş. İşlem atlanıyor.")
                
    if not action_taken:
        print("Bugün herhangi bir bayram verisi çekilmedi. Ping durumu kontrol ediliyor...")
        update_ping()

if __name__ == '__main__':
    main()
