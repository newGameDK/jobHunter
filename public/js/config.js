'use strict';

// ==========================================================================
// JobHunter – Frontend Configuration
// ==========================================================================
//
// API_BASE: '.' = relative to current page (works on PHP shared hosting)
// PHP_ROUTER: true = calls api/router.php?_route=... directly (recommended
//             for shared hosting where mod_rewrite may be unavailable)
// ==========================================================================

const API_BASE   = '.';
const PHP_ROUTER = true;

/**
 * Build the URL for an API call.
 * @param {string} path  e.g. '/api/auth/login' or '/api/jobs?status=new'
 * @returns {string}     Full URL ready for fetch()
 */
function apiUrl(path) {
    if (PHP_ROUTER && (!API_BASE || API_BASE === '.')) {
        const qIdx     = path.indexOf('?');
        const pathname = qIdx === -1 ? path : path.substring(0, qIdx);
        const query    = qIdx === -1 ? ''   : path.substring(qIdx + 1);
        const route    = pathname.replace(/^\/api\//, '');
        let url        = API_BASE + '/api/router.php?_route=' + route;
        if (query) url += '&' + query;
        return url;
    }
    return API_BASE + path;
}
