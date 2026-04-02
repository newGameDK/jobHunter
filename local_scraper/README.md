# JobHunter – Local Scraper Helper

This is the **local companion app** for JobHunter Online.  
It runs on your own PC and scrapes jobindex.dk from your own internet connection,  
so jobindex.dk only ever sees **your IP address** – never the hosted server.

---

## Why is this needed?

The hosted website (on simply.com) manages your account, saved jobs, and settings,  
but it **never contacts jobindex.dk directly**.  
All jobindex.dk traffic must come from your own computer.

---

## Setup (once)

**Requirements:** Python 3.8 or newer.

### Option A – GUI installer (recommended)

1. Download all files from **Settings → Download lokal scraper** in the web app
2. Double-click **`install.py`**
3. Choose an installation folder and click **Installer**
4. Start the scraper by double-clicking **`start.bat`** (Windows) or running **`./start.sh`** (Mac/Linux)

### Option B – Manual

```bash
# 1. Open a terminal / command prompt in this folder
cd local_scraper

# 2. Install dependencies (optional but recommended for better results)
pip install -r requirements.txt

# 3. Start the scraper
python helper.py
```

You should see:
```
[JobHunter]  Lokal scraper kører på  http://localhost:7474
[JobHunter]  Tryk Ctrl+C for at stoppe.
```

---

## Usage

1. Start `helper.py` on your PC (see above)
2. Open the JobHunter web app in your browser
3. Go to **Søg jobs** – you will see a green "Lokal scraper kører ✓" indicator
4. Paste a jobindex.dk search URL and click **Hent jobs**
5. Results are scraped from jobindex.dk via your own connection and displayed in the app
6. Click **Gem** on any job to save it to your hosted account

---

## Changing the port

Default port is **7474**. To use a different port:

```bash
python helper.py 8080
```

Then update the port under **Indstillinger → Lokal scraper** in the web app.

---

## What data does the helper send to jobindex.dk?

Only the HTTP request your browser would make anyway:
- The search URL you provided
- A standard browser `User-Agent` header
- `Accept-Language: da` (Danish)
- **No Referer header** (jobindex.dk cannot see where the request originated)

The helper **never contacts the simply.com server** while scraping.  
After scraping, the browser app sends only the extracted job data (title, company, URL, etc.) to the hosted backend.

---

## Without pip dependencies

The helper works with **standard Python only** (no pip install needed),  
but it will use a simpler regex-based parser which may miss some job cards.  
For best results, install `requests` and `beautifulsoup4`.

