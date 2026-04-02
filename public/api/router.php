<?php
// =========================================================================
// JobHunter – PHP API Router
// =========================================================================
// All API calls from the frontend arrive here as:
//   api/router.php?_route=<path>[&other_params]
// =========================================================================

session_start();
header('Content-Type: application/json; charset=utf-8');

// CORS: allow the same origin (shared hosting – no cross-origin needed)
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if ($origin) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db.php';

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

function json_ok($data = []) {
    echo json_encode(array_merge(['ok' => true], $data));
    exit;
}

function json_err($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function body() {
    static $parsed = null;
    if ($parsed === null) {
        $raw    = file_get_contents('php://input');
        $parsed = $raw ? (json_decode($raw, true) ?? []) : [];
    }
    return $parsed;
}

function require_auth($db) {
    $user = get_session_user($db);
    if (!$user) json_err('Not authenticated', 401);
    return $user;
}

// -------------------------------------------------------------------------
// Routing
// -------------------------------------------------------------------------

$route  = isset($_GET['_route']) ? trim($_GET['_route'], '/') : '';
$method = $_SERVER['REQUEST_METHOD'];

// ── Health ──────────────────────────────────────────────────────────────
if ($route === 'health') {
    json_ok(['service' => 'JobHunter API', 'php' => PHP_VERSION]);
}

// ── Diagnostics ──────────────────────────────────────────────────────────
if ($route === 'diag') {
    json_ok([
        'php'         => PHP_VERSION,
        'pdo_sqlite'  => extension_loaded('pdo_sqlite'),
        'curl'        => extension_loaded('curl'),
        'session'     => session_status() === PHP_SESSION_ACTIVE,
        'data_dir'    => is_dir(__DIR__ . '/data') ? 'ok' : 'missing',
        'db_writable' => is_writable(__DIR__ . '/data'),
    ]);
}

// ── Auth: Register ────────────────────────────────────────────────────────
if ($route === 'auth/register' && $method === 'POST') {
    $b        = body();
    $username = trim($b['username'] ?? '');
    $email    = trim($b['email'] ?? '');
    $password = $b['password'] ?? '';

    if (!$username || !$email || !$password) json_err('Username, email and password are required');
    if (strlen($password) < 6)               json_err('Password must be at least 6 characters');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Invalid email address');

    $check = $db->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
    $check->execute([$username, $email]);
    if ($check->fetch()) json_err('Username or email already taken');

    $id   = uuid_v4();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $db->prepare('INSERT INTO users (id, username, email, password_hash) VALUES (?, ?, ?, ?)')
       ->execute([$id, $username, $email, $hash]);
    ensure_settings_row($db, $id);

    $user              = $db->prepare('SELECT * FROM users WHERE id = ?');
    $user->execute([$id]);
    $u                 = $user->fetch();
    $_SESSION['user_id'] = $id;
    json_ok(['user' => sanitize_user($u)]);
}

// ── Auth: Login ────────────────────────────────────────────────────────
if ($route === 'auth/login' && $method === 'POST') {
    $b        = body();
    $username = trim($b['username'] ?? '');
    $password = $b['password'] ?? '';

    if (!$username || !$password) json_err('Username and password are required');

    $s = $db->prepare('SELECT * FROM users WHERE username = ?');
    $s->execute([$username]);
    $u = $s->fetch();
    if (!$u || !password_verify($password, $u['password_hash'])) json_err('Invalid username or password', 401);

    ensure_settings_row($db, $u['id']);
    $_SESSION['user_id'] = $u['id'];
    json_ok(['user' => sanitize_user($u)]);
}

// ── Auth: Logout ────────────────────────────────────────────────────────
if ($route === 'auth/logout' && $method === 'POST') {
    session_destroy();
    json_ok();
}

// ── Auth: Me ────────────────────────────────────────────────────────────
if ($route === 'auth/me' && $method === 'GET') {
    $user = require_auth($db);
    json_ok(['user' => sanitize_user($user)]);
}

// ── Settings: Get ────────────────────────────────────────────────────────
if ($route === 'settings' && $method === 'GET') {
    $user = require_auth($db);
    ensure_settings_row($db, $user['id']);
    $s = $db->prepare('SELECT * FROM user_settings WHERE user_id = ?');
    $s->execute([$user['id']]);
    $row = $s->fetch();

    $apiKey = '';
    if (!empty($row['chatgpt_api_key'])) {
        $apiKey = decrypt_key($row['chatgpt_api_key'], $user['password_hash']);
    }
    $hasKey = ($apiKey !== '');

    json_ok([
        'has_api_key'          => $hasKey,
        'api_key_preview'      => $hasKey ? 'sk-...' . substr($apiKey, -4) : '',
        'search_descriptions'  => json_decode($row['search_descriptions'] ?? '[]', true),
        'last_url'             => $row['last_url'] ?? '',
    ]);
}

// ── Settings: Save ────────────────────────────────────────────────────────
if ($route === 'settings' && $method === 'POST') {
    $user = require_auth($db);
    ensure_settings_row($db, $user['id']);
    $b = body();

    $s    = $db->prepare('SELECT chatgpt_api_key FROM user_settings WHERE user_id = ?');
    $s->execute([$user['id']]);
    $row  = $s->fetch();

    // Only update API key if a new non-empty one was provided
    if (isset($b['chatgpt_api_key']) && $b['chatgpt_api_key'] !== '') {
        $enc = encrypt_key(trim($b['chatgpt_api_key']), $user['password_hash']);
    } else {
        $enc = $row['chatgpt_api_key'] ?? '';
    }

    $descriptions = isset($b['search_descriptions']) ? json_encode($b['search_descriptions']) : '[]';
    $lastUrl      = isset($b['last_url']) ? trim($b['last_url']) : '';

    $db->prepare('UPDATE user_settings SET chatgpt_api_key = ?, search_descriptions = ?, last_url = ?, updated_at = ? WHERE user_id = ?')
       ->execute([$enc, $descriptions, $lastUrl, now_ms(), $user['id']]);

    json_ok();
}

// ── Settings: Clear API Key ────────────────────────────────────────────
if ($route === 'settings/clear-key' && $method === 'POST') {
    $user = require_auth($db);
    $db->prepare('UPDATE user_settings SET chatgpt_api_key = \'\', updated_at = ? WHERE user_id = ?')
       ->execute([now_ms(), $user['id']]);
    json_ok();
}

// ── Jobs: List ────────────────────────────────────────────────────────────
if ($route === 'jobs' && $method === 'GET') {
    $user = require_auth($db);
    $status = isset($_GET['status']) ? $_GET['status'] : null;

    if ($status) {
        $s = $db->prepare('SELECT * FROM jobs WHERE user_id = ? AND status = ? ORDER BY found_at DESC');
        $s->execute([$user['id'], $status]);
    } else {
        $s = $db->prepare('SELECT * FROM jobs WHERE user_id = ? ORDER BY found_at DESC');
        $s->execute([$user['id']]);
    }
    $jobs = $s->fetchAll();
    json_ok(['jobs' => $jobs]);
}

// ── Jobs: Save / Update ────────────────────────────────────────────────────
if ($route === 'jobs' && $method === 'POST') {
    $user = require_auth($db);
    $b    = body();

    $id      = $b['id']          ?? uuid_v4();
    $title   = trim($b['title']  ?? '');
    $company = trim($b['company']?? '');
    $loc     = trim($b['location']?? '');
    $url     = trim($b['url']    ?? '');
    $desc    = trim($b['description'] ?? '');
    $gpt     = trim($b['gpt_analysis']?? '');
    $status  = $b['status']      ?? 'new';

    $allowed = ['new', 'saved', 'applied', 'rejected'];
    if (!in_array($status, $allowed)) $status = 'new';

    // Upsert
    $existing = $db->prepare('SELECT id FROM jobs WHERE id = ? AND user_id = ?');
    $existing->execute([$id, $user['id']]);
    if ($existing->fetch()) {
        $db->prepare('UPDATE jobs SET title=?, company=?, location=?, url=?, description=?, gpt_analysis=?, status=?, updated_at=? WHERE id=? AND user_id=?')
           ->execute([$title, $company, $loc, $url, $desc, $gpt, $status, now_ms(), $id, $user['id']]);
    } else {
        $db->prepare('INSERT INTO jobs (id, user_id, title, company, location, url, description, gpt_analysis, status, found_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([$id, $user['id'], $title, $company, $loc, $url, $desc, $gpt, $status, now_ms(), now_ms()]);
    }

    $s = $db->prepare('SELECT * FROM jobs WHERE id = ?');
    $s->execute([$id]);
    json_ok(['job' => $s->fetch()]);
}

// ── Jobs: Delete ────────────────────────────────────────────────────────
if ($route === 'jobs/delete' && $method === 'POST') {
    $user = require_auth($db);
    $b    = body();
    $id   = $b['id'] ?? '';
    if (!$id) json_err('Job id required');
    $db->prepare('DELETE FROM jobs WHERE id = ? AND user_id = ?')->execute([$id, $user['id']]);
    json_ok();
}

// ── Scrape jobindex.dk ────────────────────────────────────────────────────
if ($route === 'scrape' && $method === 'POST') {
    $user = require_auth($db);

    if (!extension_loaded('curl')) json_err('cURL extension is not available on this server', 503);

    $b       = body();
    $baseUrl = trim($b['url'] ?? '');
    $maxPages = min((int)($b['max_pages'] ?? 3), 5);

    if (!$baseUrl) json_err('URL is required');
    if (!preg_match('#^https?://(?:www\.)?jobindex\.dk/#i', $baseUrl)) {
        json_err('Only jobindex.dk URLs are allowed');
    }

    // Save last_url for the user
    ensure_settings_row($db, $user['id']);
    $db->prepare('UPDATE user_settings SET last_url = ?, updated_at = ? WHERE user_id = ?')
       ->execute([$baseUrl, now_ms(), $user['id']]);

    $allJobs = [];
    $errors  = [];

    for ($page = 1; $page <= $maxPages; $page++) {
        // Build paged URL: append &page=N or &PageIndex=N depending on what is already there
        if ($page === 1) {
            $pageUrl = $baseUrl;
        } else {
            $sep = (strpos($baseUrl, '?') !== false) ? '&' : '?';
            $pageUrl = $baseUrl . $sep . 'page=' . $page;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $pageUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => [
                'Accept-Language: da,da-DK;q=0.9,en;q=0.8',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ],
            // Deliberately no Referer header so jobindex cannot see the origin domain
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING       => 'gzip, deflate',
        ]);
        $html   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err || !$html) {
            $errors[] = 'Page ' . $page . ': ' . ($err ?: 'empty response');
            break;
        }
        if ($status !== 200) {
            $errors[] = 'Page ' . $page . ': HTTP ' . $status;
            break;
        }

        $jobs = parse_jobindex_html($html, $pageUrl);
        if (empty($jobs)) break; // No more results

        $allJobs = array_merge($allJobs, $jobs);

        if ($page < $maxPages) sleep(1); // Polite delay between pages
    }

    // Deduplicate by URL
    $seen = [];
    $deduped = [];
    foreach ($allJobs as $j) {
        $key = $j['url'] ?: $j['title'] . '|' . $j['company'];
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $deduped[]  = $j;
        }
    }

    json_ok(['jobs' => $deduped, 'errors' => $errors, 'total' => count($deduped)]);
}

// ── Analyze with ChatGPT ──────────────────────────────────────────────────
if ($route === 'analyze' && $method === 'POST') {
    $user = require_auth($db);

    if (!extension_loaded('curl')) json_err('cURL extension is not available on this server', 503);

    // Fetch the user's stored API key
    $s = $db->prepare('SELECT chatgpt_api_key FROM user_settings WHERE user_id = ?');
    $s->execute([$user['id']]);
    $row = $s->fetch();

    $apiKey = '';
    if (!empty($row['chatgpt_api_key'])) {
        $apiKey = decrypt_key($row['chatgpt_api_key'], $user['password_hash']);
    }
    if (!$apiKey) json_err('No ChatGPT API key saved. Add your key in Settings.', 400);

    $b           = body();
    $jobTitle    = trim($b['title']   ?? '');
    $company     = trim($b['company'] ?? '');
    $description = trim($b['description'] ?? '');
    $profiles    = $b['profiles']    ?? [];

    if (!$jobTitle && !$description) json_err('Job title or description required');

    $profileText = '';
    if (!empty($profiles)) {
        $profileText = "\n\nMin profil/søgekriterier:\n" . implode("\n", array_map(fn($p) => '- ' . $p, $profiles));
    }

    $systemPrompt = 'Du er en hjælpsom karriererådgiver. Analyser jobopslag og giv en konkret, struktureret vurdering på dansk.';
    $userPrompt   = "Analyser dette jobopslag og vurder, om det er et godt match.\n\n"
        . "Jobtitel: {$jobTitle}\n"
        . "Virksomhed: {$company}\n"
        . "Beskrivelse: " . substr($description, 0, 2000)
        . $profileText
        . "\n\nGiv:\n1. Overordnet vurdering (⭐️ 1-5)\n2. Fordele ved jobbet\n3. Udfordringer/krav\n4. Anbefaling (søg / overvej / skip)";

    $payload = json_encode([
        'model'       => 'gpt-4o-mini',
        'messages'    => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userPrompt],
        ],
        'max_tokens'  => 600,
        'temperature' => 0.7,
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) json_err('Failed to reach OpenAI: ' . $curlErr, 502);

    $data = json_decode($response, true);
    if ($httpCode !== 200) {
        $errMsg = $data['error']['message'] ?? ('OpenAI returned HTTP ' . $httpCode);
        json_err($errMsg, 502);
    }

    $text = $data['choices'][0]['message']['content'] ?? '';
    json_ok(['analysis' => $text]);
}

// ── 404 ────────────────────────────────────────────────────────────────────
json_err('Unknown route: ' . $route, 404);

// =========================================================================
// Scraping helpers
// =========================================================================

/**
 * Parse a jobindex.dk search results page and return an array of job objects.
 */
function parse_jobindex_html($html, $pageUrl) {
    // Silence XML/HTML parse errors
    $prev = libxml_use_internal_errors(true);
    $doc  = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $xpath = new DOMXPath($doc);
    $jobs  = [];

    // Base URL for resolving relative hrefs
    $parsed  = parse_url($pageUrl);
    $baseUrl = $parsed['scheme'] . '://' . $parsed['host'];

    // ── Strategy 1: articles with class containing "PaidJob" or "jix_robotjob" ──
    $articles = $xpath->query('//article[contains(@class,"PaidJob") or contains(@class,"jix_robotjob") or contains(@class,"job_ad")]');

    foreach ($articles as $article) {
        $job = extract_job_from_node($xpath, $article, $baseUrl);
        if ($job) $jobs[] = $job;
    }

    // ── Strategy 2: fallback – look for <h4>/<h3> inside job-listing divs ──
    if (empty($jobs)) {
        $containers = $xpath->query('//*[contains(@class,"jix-toolbar") or contains(@class,"job-listing") or contains(@class,"result")]');
        foreach ($containers as $node) {
            $job = extract_job_from_node($xpath, $node, $baseUrl);
            if ($job) $jobs[] = $job;
        }
    }

    // ── Strategy 3: any <a> with href matching jobdetail pattern ──
    if (empty($jobs)) {
        $links = $xpath->query('//a[contains(@href,"vis-job") or contains(@href,"jobannonce/sign")]');
        foreach ($links as $link) {
            $href  = $link->getAttribute('href');
            $url   = resolve_url($href, $baseUrl);
            $title = trim($link->textContent);
            if ($title && $url) {
                $jobs[] = [
                    'id'          => '',
                    'title'       => $title,
                    'company'     => '',
                    'location'    => '',
                    'url'         => $url,
                    'description' => '',
                ];
            }
        }
    }

    return $jobs;
}

function extract_job_from_node($xpath, $node, $baseUrl) {
    // Title: first <h4> or <h3> or .jobtitle link
    $titleNode = $xpath->query('.//h4//a | .//h3//a | .//*[contains(@class,"jobtitle")]', $node)->item(0);
    if (!$titleNode) {
        $titleNode = $xpath->query('.//h4 | .//h3', $node)->item(0);
    }
    $title = $titleNode ? trim($titleNode->textContent) : '';

    // URL from the title anchor
    $href = '';
    if ($titleNode && $titleNode->nodeName === 'a') {
        $href = $titleNode->getAttribute('href');
    }
    if (!$href) {
        $linkNode = $xpath->query('.//a[contains(@href,"vis-job") or contains(@href,"jobannonce/sign")]', $node)->item(0);
        if ($linkNode) $href = $linkNode->getAttribute('href');
    }
    $url = $href ? resolve_url($href, $baseUrl) : '';

    if (!$title && !$url) return null;

    // Company: look for common patterns
    $companySelectors = [
        './/*[contains(@class,"company") or contains(@class,"employer") or contains(@class,"companyName")]',
        './/*[@itemprop="name"]',
        './/strong',
    ];
    $company = '';
    foreach ($companySelectors as $sel) {
        $n = $xpath->query($sel, $node)->item(0);
        if ($n) { $company = trim($n->textContent); break; }
    }

    // Location
    $locSelectors = [
        './/*[contains(@class,"location") or contains(@class,"area") or contains(@class,"region")]',
        './/*[@itemprop="addressLocality"]',
    ];
    $location = '';
    foreach ($locSelectors as $sel) {
        $n = $xpath->query($sel, $node)->item(0);
        if ($n) { $location = trim($n->textContent); break; }
    }

    // Short description
    $descNode = $xpath->query('.//*[contains(@class,"description") or contains(@class,"snippet") or contains(@class,"teaser")]', $node)->item(0);
    $description = $descNode ? trim($descNode->textContent) : '';

    // Clean up whitespace
    $title       = preg_replace('/\s+/', ' ', $title);
    $company     = preg_replace('/\s+/', ' ', $company);
    $location    = preg_replace('/\s+/', ' ', $location);
    $description = preg_replace('/\s+/', ' ', substr($description, 0, 500));

    return [
        'id'          => '',
        'title'       => $title,
        'company'     => $company,
        'location'    => $location,
        'url'         => $url,
        'description' => $description,
    ];
}

function resolve_url($href, $baseUrl) {
    if (!$href) return '';
    if (preg_match('#^https?://#', $href)) return $href;
    if (str_starts_with($href, '//')) return 'https:' . $href;
    if (str_starts_with($href, '/')) return rtrim($baseUrl, '/') . $href;
    return rtrim($baseUrl, '/') . '/' . $href;
}
