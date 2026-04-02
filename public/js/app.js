'use strict';

// =========================================================================
// JobHunter – Main App
// =========================================================================

// ── State ─────────────────────────────────────────────────────────────────
let currentUser      = null;
let userSettings     = { has_api_key: false, api_key_preview: '', search_descriptions: [], last_url: '' };
let savedJobs        = [];
let searchResults    = [];
let activePanel      = 'dashboard';
let activeModalJob   = null;

// Allow the stored scraper URL to be overridden in settings
let scraperUrl = (() => {
    try { return localStorage.getItem('jh-scraper-url') || LOCAL_SCRAPER_URL; } catch { return LOCAL_SCRAPER_URL; }
})();

// ── Toast ─────────────────────────────────────────────────────────────────
let toastTimer = null;
function toast(msg, duration = 2800) {
    let el = document.getElementById('toast');
    if (!el) {
        el = document.createElement('div');
        el.id = 'toast';
        document.body.appendChild(el);
    }
    el.textContent = msg;
    el.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => el.classList.remove('show'), duration);
}

// ── API helpers ───────────────────────────────────────────────────────────
async function apiFetch(path, opts = {}) {
    const res = await fetch(apiUrl(path), {
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', ...(opts.headers || {}) },
        ...opts,
    });
    const ct = res.headers.get('content-type') || '';
    if (!ct.includes('application/json')) throw new Error('Server returnerede ikke JSON (HTTP ' + res.status + ')');
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'HTTP ' + res.status);
    return data;
}

// ── Initialise ────────────────────────────────────────────────────────────
(async function init() {
    // Auth check
    try {
        const d = await apiFetch('/api/auth/me');
        currentUser = d.user;
    } catch {
        window.location.href = 'index.html';
        return;
    }

    document.getElementById('userName').textContent = currentUser.username;

    // Dark mode
    if (localStorage.getItem('jh-dark-mode') === 'true') {
        document.documentElement.classList.add('dark-mode');
    }

    // Navigation
    document.querySelectorAll('.nav-item').forEach(item => {
        item.addEventListener('click', () => showPanel(item.dataset.panel));
    });

    // Logout
    document.getElementById('logoutBtn').addEventListener('click', async () => {
        try { await apiFetch('/api/auth/logout', { method: 'POST' }); } catch { /* ignore */ }
        window.location.href = 'index.html';
    });

    // Dark mode toggle
    document.getElementById('darkToggle').addEventListener('click', () => {
        const on = document.documentElement.classList.toggle('dark-mode');
        localStorage.setItem('jh-dark-mode', String(on));
    });

    // Load data
    await Promise.all([loadSettings(), loadJobs()]);

    // Panel-specific setup
    setupSearch();
    setupSettings();
    setupModal();

    showPanel('dashboard');
})();

// ── Navigation ────────────────────────────────────────────────────────────
function showPanel(name) {
    document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));

    const panel = document.getElementById('panel-' + name);
    const navItem = document.querySelector('[data-panel="' + name + '"]');
    if (panel) panel.classList.add('active');
    if (navItem) navItem.classList.add('active');
    activePanel = name;

    if (name === 'dashboard') renderDashboard();
    if (name === 'jobs')      renderKanban();
    if (name === 'search')    onSearchPanelOpen();
}

// ── Dashboard ─────────────────────────────────────────────────────────────
function renderDashboard() {
    const counts = { new: 0, saved: 0, applied: 0, rejected: 0 };
    savedJobs.forEach(j => { if (counts[j.status] !== undefined) counts[j.status]++; });

    document.getElementById('statTotal').textContent    = savedJobs.length;
    document.getElementById('statNew').textContent      = counts.new;
    document.getElementById('statSaved').textContent    = counts.saved;
    document.getElementById('statApplied').textContent  = counts.applied;
    document.getElementById('statRejected').textContent = counts.rejected;

    const recentEl = document.getElementById('recentJobs');
    const recent   = [...savedJobs].slice(0, 8);
    if (!recent.length) {
        recentEl.innerHTML =
            '<div class="empty-state">' +
            '<p>Ingen gemte jobs endnu.</p>' +
            '<button class="btn btn-primary btn-sm" id="dashboardSearchBtn">Søg dine første jobs →</button>' +
            '</div>';
        document.getElementById('dashboardSearchBtn').addEventListener('click', () => showPanel('search'));
        return;
    }
    recentEl.innerHTML = recent.map(j => `
        <div class="recent-item" data-id="${j.id}">
          <div style="flex:1;min-width:0">
            <div class="recent-item-title">${esc(j.title || 'Unavngivet')}</div>
            <div class="recent-item-company">${esc(j.company || '')}</div>
          </div>
          <span class="status-badge status-${esc(j.status)}">${statusLabel(j.status)}</span>
        </div>
    `).join('');
    recentEl.querySelectorAll('.recent-item').forEach(el => {
        el.addEventListener('click', () => openModal(el.dataset.id));
    });
}

// ── Search panel ─────────────────────────────────────────────────────────
function setupSearch() {
    document.getElementById('recheckScraperBtn').addEventListener('click', checkLocalScraper);
    document.getElementById('scrapeBtn').addEventListener('click', doScrape);
    document.getElementById('importAllBtn').addEventListener('click', importAll);
    document.getElementById('gotoDownloadBtn').addEventListener('click', () => showPanel('settings'));
}

async function loadPool() {
    try {
        const data = await apiFetch('/api/pool');
        const jobs = data.jobs || [];
        if (!jobs.length) return;
        searchResults = jobs;
        renderSearchResults(jobs, undefined, jobs.length);
    } catch { /* non-critical */ }
}

function onSearchPanelOpen() {
    // Restore last URL from settings
    const urlInput = document.getElementById('searchUrl');
    if (!urlInput.value && userSettings.last_url) {
        urlInput.value = userSettings.last_url;
    }
    checkLocalScraper();
    // Pre-populate results from the shared pool so users see previous scrapes immediately
    loadPool();
}

async function checkLocalScraper() {
    const indEl    = document.getElementById('scraperIndicator');
    const dotEl    = indEl.querySelector('.dot');
    const textEl   = document.getElementById('scraperStatusText');
    const offEl    = document.getElementById('scraperOfflineInfo');
    const scrapeBtn = document.getElementById('scrapeBtn');

    dotEl.className   = 'dot dot-checking';
    textEl.textContent = 'Tjekker lokal scraper…';
    offEl.style.display = 'none';

    try {
        const ctrl = new AbortController();
        setTimeout(() => ctrl.abort(), 3000);
        const res  = await fetch(scraperUrl + '/health', { signal: ctrl.signal });
        const data = await res.json();
        if (res.ok && data.ok) {
            dotEl.className    = 'dot dot-online';
            textEl.textContent = 'Lokal scraper kører ✓';
            scrapeBtn.disabled = false;
            return true;
        }
    } catch { /* offline */ }

    dotEl.className    = 'dot dot-offline';
    textEl.textContent = 'Lokal scraper er offline';
    offEl.style.display = 'block';
    scrapeBtn.disabled  = true;
    return false;
}

async function doScrape() {
    const url      = document.getElementById('searchUrl').value.trim();
    const maxPages = parseInt(document.getElementById('maxPages').value, 10);

    if (!url) { toast('Indtast en Jobindex søge-URL'); return; }
    if (!/^https?:\/\/(?:www\.)?jobindex\.dk\//i.test(url)) {
        toast('Kun jobindex.dk URL\'er er tilladt'); return;
    }

    // Save last_url silently
    apiFetch('/api/settings', { method: 'POST', body: JSON.stringify({ last_url: url }) })
        .then(() => { userSettings.last_url = url; })
        .catch(() => { /* non-critical */ });

    const btn      = document.getElementById('scrapeBtn');
    const progress = document.getElementById('scrapeProgress');
    const progText = document.getElementById('scrapeProgressText');
    btn.disabled   = true;
    progress.style.display = 'flex';
    progText.textContent   = `Side 1 af ${maxPages}…`;

    document.getElementById('searchResultsHeader').style.display = 'none';
    document.getElementById('searchResults').innerHTML = '';
    searchResults = [];

    try {
        progText.textContent = `Henter jobs (op til ${maxPages} sider)…`;
        const res  = await fetch(scraperUrl + '/scrape', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ url, max_pages: maxPages }),
        });
        const data = await res.json();

        if (!res.ok) throw new Error(data.error || 'Scraper fejl');
        const freshJobs = data.jobs || [];

        // Merge fresh results into the shared pool and get the full pool back
        progText.textContent = 'Gemmer i delt pulje…';
        let poolJobs = freshJobs;
        let addedCount = freshJobs.length;
        let totalCount = freshJobs.length;
        try {
            const poolData = await apiFetch('/api/pool/import', {
                method: 'POST',
                body: JSON.stringify({ jobs: freshJobs }),
            });
            // Normalise pool entries (pool uses same field names as scraper)
            poolJobs   = poolData.jobs  || freshJobs;
            addedCount = poolData.added ?? freshJobs.length;
            totalCount = poolData.total ?? freshJobs.length;
        } catch { /* non-critical – fall back to fresh results only */ }

        searchResults = poolJobs;
        renderSearchResults(searchResults, addedCount, totalCount);
    } catch (err) {
        toast('Scraper fejl: ' + err.message, 4000);
        await checkLocalScraper();
    } finally {
        btn.disabled           = false;
        progress.style.display = 'none';
    }
}

function renderSearchResults(jobs, addedCount, totalCount) {
    const grid    = document.getElementById('searchResults');
    const header  = document.getElementById('searchResultsHeader');
    const countEl = document.getElementById('resultsCount');

    if (!jobs.length) {
        grid.innerHTML = '<p style="color:var(--text-2);font-size:.9rem;padding:8px 0">Ingen jobs fundet. Prøv en anden søgning.</p>';
        header.style.display = 'none';
        return;
    }

    header.style.display = 'flex';
    if (addedCount !== undefined && totalCount !== undefined) {
        const fromPool = totalCount - addedCount;
        if (fromPool > 0) {
            countEl.textContent = `${totalCount} jobs i alt · ${addedCount} nye · ${fromPool} fra delt pulje`;
        } else {
            countEl.textContent = `${totalCount} jobs fundet`;
        }
    } else {
        countEl.textContent = `${jobs.length} jobs`;
    }

    grid.innerHTML = jobs.map((j, i) => `
        <div class="job-card" data-idx="${i}">
          <div class="job-card-title">${esc(j.title || 'Unavngivet')}</div>
          ${j.company  ? `<div class="job-card-company">${esc(j.company)}</div>` : ''}
          ${j.location ? `<div class="job-card-location"><span aria-hidden="true">📍</span> ${esc(j.location)}</div>` : ''}
          ${j.description ? `<div class="job-card-desc">${esc(j.description)}</div>` : ''}
          <div class="job-card-actions">
            <button class="btn btn-primary save-one-btn" data-idx="${i}">Gem</button>
            ${j.url ? `<a href="${esc(j.url)}" target="_blank" rel="noopener" class="btn btn-secondary">Åbn ↗</a>` : ''}
          </div>
        </div>
    `).join('');

    grid.querySelectorAll('.save-one-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const job = searchResults[parseInt(btn.dataset.idx, 10)];
            await importJobs([job]);
            btn.textContent = 'Gemt ✓';
            btn.disabled    = true;
        });
    });
}

async function importAll() {
    if (!searchResults.length) return;
    const d = await importJobs(searchResults);
    toast(`${d.imported} job(s) gemt, ${d.skipped} sprunget over (allerede gemt)`);
    await loadJobs();
    renderDashboard();
}

async function importJobs(jobs) {
    const data = await apiFetch('/api/import_jobs', {
        method: 'POST',
        body: JSON.stringify({ jobs }),
    });
    await loadJobs();
    renderDashboard();
    updateJobsBadge();
    return data;
}

// ── Jobs ─────────────────────────────────────────────────────────────────
async function loadJobs() {
    try {
        const d = await apiFetch('/api/jobs');
        savedJobs = d.jobs || [];
        updateJobsBadge();
    } catch { /* ignore */ }
}

function updateJobsBadge() {
    const badge = document.getElementById('jobsBadge');
    const n     = savedJobs.filter(j => j.status === 'new').length;
    if (n > 0) { badge.textContent = n; badge.style.display = 'inline-block'; }
    else         badge.style.display = 'none';
}

// ── Kanban ────────────────────────────────────────────────────────────────
function renderKanban(filter = '') {
    const statuses = ['new', 'saved', 'applied', 'rejected'];
    const colIds   = { new: 'colNew', saved: 'colSaved', applied: 'colApplied', rejected: 'colRejected' };
    const cntIds   = { new: 'countNew', saved: 'countSaved', applied: 'countApplied', rejected: 'countRejected' };

    const q = filter.toLowerCase();
    const visible = q
        ? savedJobs.filter(j => (j.title + j.company + j.location).toLowerCase().includes(q))
        : savedJobs;

    statuses.forEach(st => {
        const cards = visible.filter(j => j.status === st);
        document.getElementById(cntIds[st]).textContent = cards.length;
        const col = document.getElementById(colIds[st]);
        if (!cards.length) {
            col.innerHTML = '<div class="empty-col">Ingen jobs her</div>';
            return;
        }
        col.innerHTML = cards.map(j => `
            <div class="kanban-card" data-id="${j.id}">
              <div class="kanban-card-title">${esc(j.title || 'Unavngivet')}</div>
              <div class="kanban-card-company">${esc(j.company || '')}</div>
              ${j.gpt_analysis ? `<div class="kanban-card-gpt">${esc(j.gpt_analysis.substring(0, 120))}</div>` : ''}
            </div>
        `).join('');
        col.querySelectorAll('.kanban-card').forEach(card => {
            card.addEventListener('click', () => openModal(card.dataset.id));
        });
    });
}

document.getElementById('jobsFilter')?.addEventListener('input', e => {
    renderKanban(e.target.value);
});

// ── Modal ─────────────────────────────────────────────────────────────────
function setupModal() {
    document.getElementById('modalClose').addEventListener('click', closeModal);
    document.getElementById('jobModal').addEventListener('click', e => {
        if (e.target === document.getElementById('jobModal')) closeModal();
    });
    document.getElementById('saveJobBtn').addEventListener('click', saveModalJob);
    document.getElementById('deleteJobBtn').addEventListener('click', deleteModalJob);
    document.getElementById('analyzeBtn').addEventListener('click', analyzeModalJob);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
}

function openModal(jobId) {
    const job = savedJobs.find(j => j.id === jobId);
    if (!job) return;
    activeModalJob = { ...job };

    document.getElementById('modalTitle').textContent    = job.title    || 'Unavngivet';
    document.getElementById('modalCompany').textContent  = job.company  || '';
    document.getElementById('modalLocation').textContent = job.location ? '📍 ' + job.location : '';
    const urlEl = document.getElementById('modalUrl');
    if (job.url) { urlEl.href = job.url; urlEl.style.display = 'inline'; }
    else          urlEl.style.display = 'none';
    document.getElementById('modalDescription').textContent = job.description || 'Ingen beskrivelse';
    document.getElementById('modalStatus').value         = job.status || 'new';
    document.getElementById('analysisContent').textContent = job.gpt_analysis || '';
    document.getElementById('jobModal').style.display    = 'flex';
}

function closeModal() {
    document.getElementById('jobModal').style.display = 'none';
    activeModalJob = null;
}

async function saveModalJob() {
    if (!activeModalJob) return;
    activeModalJob.status = document.getElementById('modalStatus').value;
    try {
        await apiFetch('/api/jobs', { method: 'POST', body: JSON.stringify(activeModalJob) });
        await loadJobs();
        renderKanban(document.getElementById('jobsFilter')?.value || '');
        renderDashboard();
        closeModal();
        toast('Job gemt');
    } catch (err) { toast('Fejl: ' + err.message); }
}

async function deleteModalJob() {
    if (!activeModalJob) return;
    if (!confirm('Slet dette job fra din liste?')) return;
    try {
        await apiFetch('/api/jobs/delete', { method: 'POST', body: JSON.stringify({ id: activeModalJob.id }) });
        savedJobs = savedJobs.filter(j => j.id !== activeModalJob.id);
        renderKanban(document.getElementById('jobsFilter')?.value || '');
        renderDashboard();
        updateJobsBadge();
        closeModal();
        toast('Job slettet');
    } catch (err) { toast('Fejl: ' + err.message); }
}

async function analyzeModalJob() {
    if (!activeModalJob) return;
    const contentEl = document.getElementById('analysisContent');
    const btn       = document.getElementById('analyzeBtn');

    contentEl.textContent = '';
    contentEl.className   = 'analysis-content analysis-loading';
    contentEl.textContent = 'Analyserer med ChatGPT…';
    btn.disabled          = true;

    try {
        const d = await apiFetch('/api/analyze', {
            method: 'POST',
            body: JSON.stringify({
                title:        activeModalJob.title,
                company:      activeModalJob.company,
                description:  activeModalJob.description,
                profiles:     userSettings.search_descriptions || [],
            }),
        });
        const text = d.analysis || '';
        contentEl.className   = 'analysis-content';
        contentEl.textContent = text;

        // Save analysis to the job record
        activeModalJob.gpt_analysis = text;
        await apiFetch('/api/jobs', { method: 'POST', body: JSON.stringify(activeModalJob) });
        await loadJobs();
    } catch (err) {
        contentEl.className   = 'analysis-content';
        contentEl.textContent = 'Fejl: ' + err.message;
    } finally {
        btn.disabled = false;
    }
}

// ── Settings ─────────────────────────────────────────────────────────────
async function loadSettings() {
    try {
        const d = await apiFetch('/api/settings');
        userSettings = d;
    } catch { /* ignore */ }
}

// Build the absolute URL to the PHP router, regardless of subdirectory depth.
function getApiRouterUrl() {
    return new URL('./api/router.php', window.location.href).href;
}

// Build a javascript: bookmarklet URL that scrapes the current jobindex.dk
// page and POSTs the results to this server using the user's personal token.
// The token is sent in the POST body, never in the URL, to avoid leaking it
// in browser history, server logs, or referrer headers.
function generateBookmarkletUrl(token, routerUrl) {
    const code = '(function(){'
        + 'var H=' + JSON.stringify(routerUrl) + ';'
        + 'var T=' + JSON.stringify(token) + ';'
        + 'var hn=location.hostname.toLowerCase();'
        + 'if(hn!=="www.jobindex.dk"&&hn!=="jobindex.dk"){alert("K\\xF8r dette p\\xE5 jobindex.dk!");return;}'
        + 'var J=[];'
        + 'document.querySelectorAll("article").forEach(function(a){'
        +   'var l=a.querySelector("a[href*=\\"/vis-job/\\"],a[href*=\\"/jobannonce/\\"]");'
        +   'if(!l)return;'
        +   'var t=l.textContent.trim().replace(/\\s+/g," ");'
        +   'var co=(a.querySelector("[class*=\\"company\\"],[class*=\\"employer\\"]")||{textContent:""}).textContent.trim();'
        +   'var lo=(a.querySelector("[class*=\\"location\\"],[class*=\\"area\\"],[class*=\\"region\\"]")||{textContent:""}).textContent.trim();'
        +   'var de=(a.querySelector("[class*=\\"description\\"],[class*=\\"snippet\\"],[class*=\\"teaser\\"]")||{textContent:""}).textContent.trim().slice(0,500);'
        +   'if(t)J.push({title:t,company:co,location:lo,url:l.href,description:de});'
        + '});'
        + 'if(!J.length){'
        +   'document.querySelectorAll("a[href*=\\"/vis-job/\\"],a[href*=\\"/jobannonce/\\"]").forEach(function(a){'
        +     'var t=a.textContent.trim().replace(/\\s+/g," ");'
        +     'if(t.length>2)J.push({title:t,company:"",location:"",url:a.href,description:""});'
        +   '});'
        + '}'
        + 'var S={};J=J.filter(function(j){var k=j.url||j.title;if(S[k])return false;S[k]=1;return true;});'
        + 'if(!J.length){alert("Ingen jobs fundet p\\xE5 denne side.");return;}'
        + 'var n=document.createElement("div");'
        + 'n.style.cssText="position:fixed;top:20px;right:20px;z-index:2147483647;background:#1e40af;color:#fff;padding:16px 24px;border-radius:10px;font:bold 14px/1.5 sans-serif;box-shadow:0 4px 20px rgba(0,0,0,.4);max-width:320px";'
        + 'n.textContent="\\u23F3 JobHunter: Sender "+J.length+" jobs\\u2026";'
        + 'document.body.appendChild(n);'
        + 'fetch(H+"?_route=import_jobs",{'
        +   'method:"POST",'
        +   'headers:{"Content-Type":"application/json"},'
        +   'body:JSON.stringify({token:T,jobs:J})'
        + '}).then(function(r){return r.json();})'
        + '.then(function(d){'
        +   'n.style.background=d.ok?"#166534":"#991b1b";'
        +   'n.textContent=d.ok?"\\u2705 JobHunter: "+(d.imported||J.length)+" nye jobs gemt!":"\\u274C JobHunter: "+(d.error||"Fejl");'
        +   'setTimeout(function(){n.remove();},5000);'
        + '}).catch(function(e){'
        +   'n.style.background="#991b1b";'
        +   'n.textContent="\\u274C JobHunter: "+e.message;'
        +   'setTimeout(function(){n.remove();},7000);'
        + '});'
        + '})();';
    return 'javascript:' + encodeURIComponent(code);
}

function setupSettings() {
    // Populate settings fields once settings are loaded
    refreshSettingsUI();

    // ── Bookmarklet ───────────────────────────────────────────────────────
    (async () => {
        try {
            const d = await apiFetch('/api/auth/scrape-token');
            updateBookmarkletUI(d.token);
        } catch { /* non-critical */ }
    })();

    document.getElementById('regenerateTokenBtn')?.addEventListener('click', async () => {
        if (!confirm('Forny din scraper-nøgle?\n\nDet eksisterende bogmærke vil holde op med at virke – du skal opdatere det med den nye knap.')) return;
        try {
            const d = await apiFetch('/api/auth/scrape-token', { method: 'POST' });
            updateBookmarkletUI(d.token);
            toast('Scraper-nøgle fornyet – træk den nye knap til bogmærkelinjen');
        } catch (err) { toast('Fejl: ' + err.message); }
    });

    // ChatGPT API Key
    document.getElementById('toggleApiKeyVisibility').addEventListener('click', () => {
        const inp  = document.getElementById('apiKeyInput');
        const btn  = document.getElementById('toggleApiKeyVisibility');
        inp.type   = inp.type === 'password' ? 'text' : 'password';
        btn.textContent = inp.type === 'password' ? 'Vis' : 'Skjul';
    });

    document.getElementById('saveApiKeyBtn').addEventListener('click', async () => {
        const key = document.getElementById('apiKeyInput').value.trim();
        if (!key) { toast('Indtast en API-nøgle'); return; }
        try {
            await apiFetch('/api/settings', { method: 'POST', body: JSON.stringify({ chatgpt_api_key: key }) });
            document.getElementById('apiKeyInput').value = '';
            await loadSettings();
            refreshSettingsUI();
            toast('API-nøgle gemt');
        } catch (err) { toast('Fejl: ' + err.message); }
    });

    document.getElementById('clearApiKeyBtn').addEventListener('click', async () => {
        if (!confirm('Fjern den gemte API-nøgle?')) return;
        try {
            await apiFetch('/api/settings/clear-key', { method: 'POST' });
            await loadSettings();
            refreshSettingsUI();
            toast('API-nøgle fjernet');
        } catch (err) { toast('Fejl: ' + err.message); }
    });

    // Profile / Keywords
    document.getElementById('saveProfileBtn').addEventListener('click', async () => {
        const profile   = document.getElementById('profileInput').value.trim();
        const keywords  = document.getElementById('keywordsInput').value
            .split('\n').map(s => s.trim()).filter(Boolean);
        const descriptions = profile ? [profile, ...keywords] : keywords;
        try {
            await apiFetch('/api/settings', { method: 'POST', body: JSON.stringify({ search_descriptions: descriptions }) });
            await loadSettings();
            toast('Profil gemt');
        } catch (err) { toast('Fejl: ' + err.message); }
    });

    // Scraper URL
    document.getElementById('scraperUrlInput').value = scraperUrl;
    document.getElementById('saveScraperUrlBtn').addEventListener('click', async () => {
        const val = document.getElementById('scraperUrlInput').value.trim().replace(/\/$/, '');
        scraperUrl = val;
        try { localStorage.setItem('jh-scraper-url', val); } catch { /* ignore */ }
        const resultEl = document.getElementById('scraperTestResult');
        resultEl.textContent = 'Tester…';
        try {
            const ctrl = new AbortController();
            setTimeout(() => ctrl.abort(), 3000);
            const res  = await fetch(val + '/health', { signal: ctrl.signal });
            const data = await res.json();
            resultEl.style.color = data.ok ? 'var(--success)' : 'var(--danger)';
            resultEl.textContent  = data.ok ? '✓ Forbundet' : '✗ Ingen respons';
        } catch {
            resultEl.style.color  = 'var(--danger)';
            resultEl.textContent  = '✗ Kan ikke forbinde';
        }
    });
}

function refreshSettingsUI() {
    const statusEl   = document.getElementById('apiKeyStatus');
    const clearBtn   = document.getElementById('clearApiKeyBtn');
    if (userSettings.has_api_key) {
        statusEl.textContent    = '✓ Nøgle gemt (' + (userSettings.api_key_preview || '****') + ')';
        statusEl.style.color    = 'var(--success)';
        clearBtn.style.display  = 'inline-flex';
    } else {
        statusEl.textContent    = 'Ingen nøgle gemt endnu';
        statusEl.style.color    = 'var(--text-2)';
        clearBtn.style.display  = 'none';
    }

    // Profile / keywords: first item is the prose profile, rest are keywords
    const desc = userSettings.search_descriptions || [];
    if (desc.length) {
        document.getElementById('profileInput').value   = desc[0] || '';
        document.getElementById('keywordsInput').value  = desc.slice(1).join('\n');
    }
}

function updateBookmarkletUI(token) {
    const loadingEl = document.getElementById('bookmarkletLoading');
    const readyEl   = document.getElementById('bookmarkletReady');
    const linkEl    = document.getElementById('bookmarkletLink');
    const previewEl = document.getElementById('tokenPreview');
    if (!linkEl) return;

    const routerUrl = getApiRouterUrl();
    linkEl.href = generateBookmarkletUrl(token, routerUrl);
    if (previewEl) previewEl.textContent = 'Nøgle: ' + token.slice(0, 8) + '…';
    if (loadingEl) loadingEl.style.display = 'none';
    if (readyEl)   readyEl.style.display   = 'block';
}

// ── Utilities ─────────────────────────────────────────────────────────────
function esc(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function statusLabel(s) {
    return { new: 'Ny', saved: 'Favorit', applied: 'Ansøgt', rejected: 'Afvist' }[s] || s;
}
