'use strict';

// ==========================================================================
// JobHunter – Frontend Configuration
// ==========================================================================
//
// API_BASE: '.' = always relative to the current page.
//   Works whether the app is deployed at the domain root (public_html/) OR
//   in a subfolder (public_html/jobHunter/). No changes needed for either
//   case – the browser resolves './api/router.php' relative to the page URL.
//
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
 * Always returns a path relative to the current page (starts with './'),
 * so it works correctly whether the app lives at the domain root or in any
 * subfolder depth.
 *
 * @param {string} path  e.g. '/api/auth/login' or '/api/jobs?status=new'
 * @returns {string}     Relative URL ready for fetch()
 */
function apiUrl(path) {
    if (PHP_ROUTER && (!API_BASE || API_BASE === '.')) {
        const qIdx     = path.indexOf('?');
        const pathname = qIdx === -1 ? path : path.substring(0, qIdx);
        const query    = qIdx === -1 ? ''   : path.substring(qIdx + 1);
        const route    = pathname.replace(/^\/api\//, '');
        let url        = './api/router.php?_route=' + route;
        if (query) url += '&' + query;
        return url;
    }
    return API_BASE + path;
}
