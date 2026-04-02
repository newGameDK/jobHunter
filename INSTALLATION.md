# JobHunter – Installationsvejledning

Denne vejledning beskriver, hvad du skal uploade til din webhost og hvordan du sætter den lokale scraper op på din PC.

---

## Oversigt

JobHunter består af to dele:

| Del | Beskrivelse | Placering |
|-----|-------------|-----------|
| **Webapplikation** | PHP/HTML/JS backend + frontend | Uploades til `public_html/` (eller en undermappe) på din webhost |
| **Lokal scraper** | Python-script der kører på din PC | Downloades fra webappen og køres lokalt |

---

## Del 1 – Webapplikationen (server)

### Krav til webhosten

- PHP 7.4 eller nyere
- SQLite3-support (standard på de fleste shared hosting)
- Apache med `mod_rewrite` aktiveret **eller** PHP-routing (allerede konfigureret)

### Hvad skal uploades?

Upload indholdet af mappen **`public/`** direkte til din **`public_html/`** mappe på webhostens FTP/filmanager.

```
public_html/
├── .htaccess
├── index.html          ← login-side
├── app.html            ← hoved-app
├── version.json
├── css/
│   └── style.css
├── js/
│   ├── config.js
│   ├── auth.js
│   └── app.js
├── api/
│   ├── .htaccess
│   ├── router.php
│   └── db.php
└── downloads/
    ├── install.py
    ├── helper.py
    ├── requirements.txt
    ├── start.bat
    └── start.sh
```

> **Vigtigt:** Upload *indholdet* af `public/` – ikke selve `public/`-mappen.  
> Det vil sige at `index.html` skal ligge direkte i `public_html/index.html`.  
> Ønsker du at deploye til en undermappe (f.eks. `public_html/jobHunter/`), se afsnittet **Deploying to a subfolder** nedenfor.

### Trin-for-trin (FTP)

1. Åbn din FTP-klient (f.eks. FileZilla) eller webhostens filmanager
2. Naviger til `public_html/` på serveren
3. Upload **alle filer og mapper** fra `public/` i dette repository
4. Bekræft at `index.html` og `app.html` ligger direkte i `public_html/`

### Første gang du åbner siden

1. Gå til `https://dit-domæne.dk` i din browser
2. Klik **Registrer** og opret en ny bruger
3. Log ind med din nye bruger

> Databasen (`api/data/jobhunter.db`) oprettes automatisk første gang siden tilgås.  
> Mappen `api/data/` må **aldrig** overskrives ved geninstallation – her ligger dine data.

---

## Del 2 – Lokal scraper (din PC)

Den lokale scraper er et Python-script, der kører på din egen computer og henter jobopslag fra jobindex.dk. Det er nødvendigt, fordi jobindex.dk ikke tillader direkte forespørgsler fra eksterne servere.

### Krav

- Python 3.8 eller nyere  
  Download fra [python.org](https://www.python.org/downloads/)  
  *(Windows: sørg for at sætte hak ved "Add Python to PATH" under installationen)*

### Trin-for-trin installation

#### Mulighed A – GUI-installer (anbefalet)

1. Log ind på JobHunter i din browser
2. Gå til **Indstillinger → Download lokal scraper**
3. Download alle 5 filer til en mappe på din PC (f.eks. Skrivebord)
4. Dobbeltklik på **`install.py`**
5. Vælg den mappe, du ønsker scraperen installeret i (f.eks. `Dokumenter/JobHunter`)
6. Klik **Installer** – afhængigheder installeres automatisk
7. Start scraperen ved at dobbeltklikke på **`start.bat`** (Windows) eller køre **`./start.sh`** (Mac/Linux)

#### Mulighed B – Manuel installation

```bash
# 1. Download filerne fra Indstillinger → Download lokal scraper
# 2. Åbn en terminal i den mappe du downloadede filerne til

# 3. Installer afhængigheder (anbefalet for bedre resultater)
pip install -r requirements.txt

# 4. Start scraperen
python helper.py          # Windows
python3 helper.py         # Mac / Linux
```

Du bør se:
```
[JobHunter]  Lokal scraper kører på  http://localhost:7474
[JobHunter]  Tryk Ctrl+C for at stoppe.
```

### Brug af scraperen

1. Start scraperen (se ovenfor)
2. Åbn JobHunter i din browser
3. Gå til **Søg jobs** – indikatoren skifter til grøn "Lokal scraper kører ✓"
4. Indsæt en jobindex.dk søge-URL og klik **Hent jobs**
5. Gem ønskede job til din konto med **Gem**-knappen

> Scraperen skal køre, hver gang du vil søge jobs. Den lukkes, når du lukker terminalen.

### Ændring af port (valgfrit)

Standard-porten er **7474**. Ønsker du en anden port:

```bash
python helper.py 8080
```

Opdater derefter porten under **Indstillinger → Lokal scraper** i webappen.

---

## Deploying to a subfolder (e.g. public_html/jobHunter/)

JobHunter supports subfolder deployment **out of the box** — no code changes are needed.

### What to do

Upload the contents of `public/` to a subfolder on your webhost instead of the root:

```
public_html/
└── jobHunter/          ← your chosen subfolder name
    ├── .htaccess
    ├── index.html
    ├── app.html
    ├── version.json
    ├── css/
    ├── js/
    ├── api/
    └── downloads/
```

The site will then be accessible at:

```
https://dit-domæne.dk/jobHunter/
```

### Why it works without changes

The JavaScript configuration in `js/config.js` uses `API_BASE = '.'`, which is always resolved **relative to the current page URL** by the browser. This means `./api/router.php` is automatically resolved to the correct path regardless of the subfolder depth.

All HTML, CSS and JS asset references are also relative (no leading `/`), so they resolve correctly from any subfolder.

### ⚠ Protect your data folder

When uploading to a subfolder, `api/data/` must still **never** be overwritten during updates — this folder contains your database. Skip it when re-uploading files.

---

## Opdatering af webapplikationen

Ved opdatering uploades filerne igen via FTP – **bortset fra** mappen `api/data/`, som indeholder din database og aldrig må overskrives.

---

## Fejlfinding

| Problem | Løsning |
|---------|---------|
| "Lokal scraper er ikke aktiv" | Sørg for at `helper.py` / `start.bat` kører, og at porten stemmer overens med indstillingerne |
| Siden viser en blank skærm | Tjek at `index.html` ligger direkte i `public_html/` |
| PHP-fejl / 500-fejl | Verificer at PHP 7.4+ og SQLite3 er aktiveret på din webhost |
| "Python er ikke installeret" | Download Python fra python.org – Windows: sæt hak ved "Add to PATH" |
| pip-fejl under installation | Kør manuelt: `pip install requests beautifulsoup4` |

---

## Sikkerhed og privatliv

- Al trafik til jobindex.dk går **kun** fra din egen PC – serveren kontakter aldrig jobindex.dk
- Dine jobdata gemmes i en SQLite-database på serveren i `api/data/`
- API-nøglen til ChatGPT gemmes krypteret på serveren
