'use strict';

// ==========================================================================
// JobHunter – Frontend Configuration
// ==========================================================================
//
// API_BASE: '.' = relative to current page (works on PHP shared hosting)
// PHP_ROUTER: true = calls api/router.php?_route=... directly (recommended
//             for shared hosting where mod_rewrite may be unavailable)
//
// LOCAL_SCRAPER_URL: address of the local companion scraper running on the
//             user's own PC. All jobindex.dk traffic goes through this app,
//             never through the hosted server.
//             Default port is 7474. Change here if you use a different port.
// ==========================================================================

const API_BASE          = '.';
const PHP_ROUTER        = true;
const LOCAL_SCRAPER_URL = 'http://localhost:7474';

/**
 * Build the URL for a hosted API call.
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
