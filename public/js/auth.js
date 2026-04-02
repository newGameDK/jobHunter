'use strict';

// --------------------------------------------------------------------------
// JobHunter – Auth page logic
// --------------------------------------------------------------------------

if (location.protocol === 'file:') {
    document.querySelector('.auth-container').insertAdjacentHTML('afterbegin',
        '<div class="file-protocol-warning">' +
        '<strong>⚠ Kan ikke forbinde til server</strong><br>' +
        'Du åbnede filen direkte. Upload mappen til en webserver og åbn den derfra.' +
        '</div>'
    );
}

// Probe the API health endpoint to detect non-working PHP setups
if (location.protocol !== 'file:') {
    (async () => {
        let ok = false;
        try {
            const res = await fetch(apiUrl('/api/health'), { credentials: 'include' });
            if (res.ok) {
                const data = await res.json();
                ok = data && data.ok;
            }
        } catch { /* unreachable */ }

        if (!ok) {
            document.querySelector('.auth-container').insertAdjacentHTML('afterbegin',
                '<div class="file-protocol-warning">' +
                '<strong>⚠ Kan ikke nå backend-API\'et</strong><br>' +
                'Siden blev indlæst, men PHP-API\'et svarer ikke. ' +
                'Kontrollér at <code>api/</code> mappen er uploadet og PHP er aktiveret. ' +
                '<a href="' + apiUrl('/api/diag').replace(/"/g, '&quot;') + '" target="_blank">Åbn diagnostik</a>' +
                '</div>'
            );
        }
    })();
}

// ── Tab switching ────────────────────────────────────────────────────────
document.querySelectorAll('.auth-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById(tab.dataset.tab + 'Form').classList.add('active');
    });
});

// ── Login ────────────────────────────────────────────────────────────────
document.getElementById('loginForm').addEventListener('submit', async e => {
    e.preventDefault();
    const username = document.getElementById('loginUsername').value.trim();
    const password = document.getElementById('loginPassword').value;
    const errEl    = document.getElementById('loginError');
    errEl.textContent = '';

    try {
        const res = await fetch(apiUrl('/api/auth/login'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ username, password }),
        });
        if (!res.headers.get('content-type')?.includes('application/json')) {
            errEl.textContent = 'Serveren returnerede ikke et gyldigt svar. Kontrollér at api/ mappen er uploadet og PHP er aktiveret.';
            return;
        }
        const data = await res.json();
        if (!res.ok) { errEl.textContent = data.error || 'Login mislykkedes'; return; }
        window.location.href = 'app.html';
    } catch {
        errEl.textContent = 'Kan ikke nå serveren.';
    }
});

// ── Register ─────────────────────────────────────────────────────────────
document.getElementById('registerForm').addEventListener('submit', async e => {
    e.preventDefault();
    const username = document.getElementById('regUsername').value.trim();
    const email    = document.getElementById('regEmail').value.trim();
    const password = document.getElementById('regPassword').value;
    const errEl    = document.getElementById('registerError');
    errEl.textContent = '';

    try {
        const res = await fetch(apiUrl('/api/auth/register'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ username, email, password }),
        });
        if (!res.headers.get('content-type')?.includes('application/json')) {
            errEl.textContent = 'Serveren returnerede ikke et gyldigt svar.';
            return;
        }
        const data = await res.json();
        if (!res.ok) { errEl.textContent = data.error || 'Oprettelse mislykkedes'; return; }
        window.location.href = 'app.html';
    } catch {
        errEl.textContent = 'Kan ikke nå serveren.';
    }
});

// ── Already logged in? ────────────────────────────────────────────────────
(async () => {
    try {
        const res = await fetch(apiUrl('/api/auth/me'), { credentials: 'include' });
        if (res.ok) window.location.href = 'app.html';
    } catch { /* not logged in */ }
})();

// ── Version ───────────────────────────────────────────────────────────────
(async () => {
    try {
        const res = await fetch('version.json?_=' + Date.now(), { cache: 'no-store' });
        if (res.ok) {
            const d = await res.json();
            const el = document.getElementById('authVersion');
            if (el && d.version) el.textContent = 'v' + d.version;
        }
    } catch { /* version.json missing */ }
})();
