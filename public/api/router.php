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

// Authenticate either by PHP session or by a personal scrape token supplied in
// the POST body ("token" field). Tokens are never accepted from URL parameters
// to avoid leaking them in server logs, browser history, or referrer headers.
// Only a limited set of import endpoints use this – all other routes require a
// proper session via require_auth().
function get_token_user($db) {
    $token = trim(body()['token'] ?? '');
    if (!$token || strlen($token) < 32) return null;
    $s = $db->prepare('SELECT * FROM users WHERE scrape_token = ?');
    $s->execute([$token]);
    return $s->fetch() ?: null;
}

function require_auth_or_token($db) {
    $user = get_session_user($db);
    if ($user) return $user;
    $user = get_token_user($db);
    if ($user) return $user;
    json_err('Not authenticated', 401);
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

// ── Auth: Scrape token – get or generate ─────────────────────────────────
if ($route === 'auth/scrape-token' && $method === 'GET') {
    $user = require_auth($db);
    $token = $user['scrape_token'] ?? '';
    if ($token === '') {
        $token = bin2hex(random_bytes(32));
        $db->prepare('UPDATE users SET scrape_token = ? WHERE id = ?')
           ->execute([$token, $user['id']]);
    }
    json_ok(['token' => $token]);
}

// ── Auth: Scrape token – regenerate ──────────────────────────────────────
if ($route === 'auth/scrape-token' && $method === 'POST') {
    $user  = require_auth($db);
    $token = bin2hex(random_bytes(32));
    $db->prepare('UPDATE users SET scrape_token = ? WHERE id = ?')
       ->execute([$token, $user['id']]);
    json_ok(['token' => $token]);
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

// ── Import jobs (sent from the user's local scraper or browser bookmarklet) ─
// The server NEVER scrapes jobindex.dk directly. All scraping happens on the
// user's own PC via the local companion app (local_scraper/helper.py) or the
// browser bookmarklet. This endpoint only receives already-parsed job data.
// Accepts both session auth and personal scrape-token auth so the bookmarklet
// can POST cross-origin without needing a session cookie.
if ($route === 'import_jobs' && $method === 'POST') {
    $user = require_auth_or_token($db);
    $b    = body();
    $jobs = $b['jobs'] ?? [];

    if (!is_array($jobs)) json_err('jobs must be an array');
    if (count($jobs) > 200) json_err('Too many jobs in one import (max 200)');

    $imported = 0;
    $skipped  = 0;

    foreach ($jobs as $j) {
        $title   = substr(trim($j['title']       ?? ''), 0, 500);
        $company = substr(trim($j['company']      ?? ''), 0, 200);
        $loc     = substr(trim($j['location']     ?? ''), 0, 200);
        $url     = substr(trim($j['url']          ?? ''), 0, 2000);
        $desc    = substr(trim($j['description']  ?? ''), 0, 5000);

        if (!$title && !$url) { $skipped++; continue; }

        // Skip if the same URL already exists for this user
        if ($url) {
            $exists = $db->prepare('SELECT id FROM jobs WHERE user_id = ? AND url = ?');
            $exists->execute([$user['id'], $url]);
            if ($exists->fetch()) { $skipped++; continue; }
        }

        $id = uuid_v4();
        $db->prepare('INSERT INTO jobs (id, user_id, title, company, location, url, description, status, found_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
           ->execute([$id, $user['id'], $title, $company, $loc, $url, $desc, 'new', now_ms(), now_ms()]);
        $imported++;
    }

    json_ok(['imported' => $imported, 'skipped' => $skipped]);
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

// ── Shared scraped pool: fetch ────────────────────────────────────────────
// Returns all non-expired pool entries. Also purges jobs older than 40 days.
if ($route === 'pool' && $method === 'GET') {
    require_auth($db);
    $cutoff = now_ms() - (40 * 24 * 60 * 60 * 1000);
    $db->prepare('DELETE FROM scraped_pool WHERE last_seen_at < ?')->execute([$cutoff]);
    $s = $db->prepare('SELECT * FROM scraped_pool ORDER BY last_seen_at DESC');
    $s->execute();
    json_ok(['jobs' => $s->fetchAll()]);
}

// ── Shared scraped pool: import ────────────────────────────────────────────
// Merges freshly-scraped jobs into the shared pool (all users contribute,
// all users benefit). Purges entries unseen for more than 40 days.
// Returns the full updated pool so the caller can display it immediately.
// Accepts both session auth and personal scrape-token auth.
if ($route === 'pool/import' && $method === 'POST') {
    require_auth_or_token($db);
    $b    = body();
    $jobs = $b['jobs'] ?? [];

    if (!is_array($jobs))      json_err('jobs must be an array');
    if (count($jobs) > 500)    json_err('Too many jobs in one import (max 500)');

    $now   = now_ms();
    $added = 0;

    $stmtCheck  = $db->prepare('SELECT id FROM scraped_pool WHERE url = ?');
    $stmtInsert = $db->prepare(
        'INSERT INTO scraped_pool (id, url, title, company, location, description, first_seen_at, last_seen_at)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    $stmtUpdate = $db->prepare(
        'UPDATE scraped_pool
         SET title = ?, company = ?, location = ?,
             description = CASE WHEN description = \'\' THEN ? ELSE description END,
             last_seen_at = ?
         WHERE url = ?'
    );

    foreach ($jobs as $j) {
        $url  = substr(trim($j['url']         ?? ''), 0, 2000);
        if (!$url) continue;

        $title   = substr(trim($j['title']       ?? ''), 0, 500);
        $company = substr(trim($j['company']     ?? ''), 0, 200);
        $loc     = substr(trim($j['location']    ?? ''), 0, 200);
        $desc    = substr(trim($j['description'] ?? ''), 0, 5000);

        $stmtCheck->execute([$url]);
        if ($stmtCheck->fetch()) {
            $stmtUpdate->execute([$title, $company, $loc, $desc, $now, $url]);
        } else {
            $stmtInsert->execute([uuid_v4(), $url, $title, $company, $loc, $desc, $now, $now]);
            $added++;
        }
    }

    // Purge entries unseen for more than 40 days
    $cutoff = $now - (40 * 24 * 60 * 60 * 1000);
    $db->prepare('DELETE FROM scraped_pool WHERE last_seen_at < ?')->execute([$cutoff]);

    // Return the full updated pool
    $s = $db->prepare('SELECT * FROM scraped_pool ORDER BY last_seen_at DESC');
    $s->execute();
    $pool = $s->fetchAll();

    json_ok(['added' => $added, 'total' => count($pool), 'jobs' => $pool]);
}

// ── Admin: helpers ────────────────────────────────────────────────────────
function require_admin($db) {
    $user = get_session_user($db);
    if (!$user) json_err('Not authenticated', 401);
    if (empty($user['is_admin'])) json_err('Admin access required', 403);
    return $user;
}

// Copy files from $src to $dst recursively, skipping any destination path
// that resolves to $skipDstPath (the live database directory).
function copy_update_files($src, $dst, $skipDstPath) {
    $copied = 0;
    $failed = 0;
    if (!is_dir($dst)) mkdir($dst, 0755, true);
    foreach (scandir($src) as $item) {
        if ($item === '.' || $item === '..') continue;
        $srcPath = $src . '/' . $item;
        $dstPath = $dst . '/' . $item;
        // Protect the data directory – resolve symlinks and compare canonical paths
        $resolvedDst  = is_dir($dstPath)  ? realpath($dstPath)  : realpath($dst) . '/' . $item;
        $resolvedSkip = $skipDstPath;
        if ($resolvedDst === $resolvedSkip) continue;
        if (is_dir($srcPath)) {
            [$c, $f] = copy_update_files($srcPath, $dstPath, $skipDstPath);
            $copied += $c;
            $failed += $f;
        } else {
            copy($srcPath, $dstPath) ? $copied++ : $failed++;
        }
    }
    return [$copied, $failed];
}

function delete_dir_recursive($dir) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        is_dir($path) ? delete_dir_recursive($path) : @unlink($path);
    }
    @rmdir($dir);
}

// ── Admin: Status ─────────────────────────────────────────────────────────
if ($route === 'admin/status' && $method === 'GET') {
    $user = require_auth($db);

    $adminCount   = (int)$db->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn();
    $adminClaimed = $adminCount > 0;

    $result = [
        'admin_claimed' => $adminClaimed,
        'is_admin'      => (bool)($user['is_admin'] ?? false),
    ];

    if (!empty($user['is_admin'])) {
        $result['user_count'] = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();

        $versionFile = dirname(__DIR__) . '/version.json';
        $currentVersion = '?';
        if (file_exists($versionFile)) {
            $vj = json_decode(file_get_contents($versionFile), true);
            $currentVersion = $vj['version'] ?? '?';
        }
        $result['current_version'] = $currentVersion;
    }

    json_ok($result);
}

// ── Admin: Claim admin role ────────────────────────────────────────────────
// The very first user to POST this endpoint becomes the sole administrator.
if ($route === 'admin/claim' && $method === 'POST') {
    $user = require_auth($db);

    // Use an exclusive transaction to prevent a race between concurrent requests
    $db->exec('BEGIN EXCLUSIVE');
    $adminCount = (int)$db->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn();
    if ($adminCount > 0) {
        $db->exec('ROLLBACK');
        json_err('Admin role is already claimed', 403);
    }
    $db->prepare('UPDATE users SET is_admin = 1 WHERE id = ?')->execute([$user['id']]);
    $db->exec('COMMIT');

    $s = $db->prepare('SELECT * FROM users WHERE id = ?');
    $s->execute([$user['id']]);
    json_ok(['user' => sanitize_user($s->fetch())]);
}

// ── Admin: Check for update ────────────────────────────────────────────────
if ($route === 'admin/check-update' && $method === 'GET') {
    require_admin($db);

    if (!extension_loaded('curl')) json_err('cURL extension is not available', 503);

    $versionFile    = dirname(__DIR__) . '/version.json';
    $currentVersion = '0.0.0';
    if (file_exists($versionFile)) {
        $vj = json_decode(file_get_contents($versionFile), true);
        $currentVersion = $vj['version'] ?? '0.0.0';
    }

    $ch = curl_init('https://raw.githubusercontent.com/newGameDK/jobHunter/main/public/version.json');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'JobHunter-Updater/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr || $httpCode !== 200) {
        json_err('Could not reach GitHub: ' . ($curlErr ?: 'HTTP ' . $httpCode), 502);
    }

    $remoteData    = json_decode($response, true);
    $latestVersion = $remoteData['version'] ?? '0.0.0';

    json_ok([
        'current_version'  => $currentVersion,
        'latest_version'   => $latestVersion,
        'update_available' => version_compare($latestVersion, $currentVersion, '>'),
    ]);
}

// ── Admin: Apply update ────────────────────────────────────────────────────
// Downloads the main branch ZIP from GitHub, extracts it, and copies all
// files to the web root – but NEVER touches api/data/ (the live database).
if ($route === 'admin/update' && $method === 'POST') {
    require_admin($db);

    if (!extension_loaded('curl'))   json_err('cURL extension is not available', 503);
    if (!class_exists('ZipArchive')) json_err('ZipArchive extension is not available', 503);

    $webRoot     = dirname(__DIR__);
    // Resolve the protected data path to its canonical form to prevent symlink bypasses
    $skipDstPath = realpath(__DIR__ . '/data') ?: ($webRoot . '/api/data');

    // Download ZIP from GitHub (URL is hardcoded; CURLOPT_SSL_VERIFYPEER ensures
    // transport integrity – a full GPG signature check would require distributing a
    // public key and is outside the scope of this self-hosted setup).
    $zipUrl = 'https://github.com/newGameDK/jobHunter/archive/refs/heads/main.zip';
    $ch = curl_init($zipUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_USERAGENT      => 'JobHunter-Updater/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $zipData  = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr || $httpCode !== 200 || !$zipData) {
        json_err('Failed to download update: ' . ($curlErr ?: 'HTTP ' . $httpCode), 502);
    }

    // Use unpredictable names to prevent temp-file race conditions
    $tmpZip = tempnam(sys_get_temp_dir(), 'jh_upd_');
    if ($tmpZip === false || file_put_contents($tmpZip, $zipData) === false) {
        json_err('Failed to save downloaded archive', 500);
    }
    unset($zipData); // free memory

    $tmpDir = sys_get_temp_dir() . '/jh_extract_' . bin2hex(random_bytes(8));
    $zip    = new ZipArchive();
    if ($zip->open($tmpZip) !== true) {
        @unlink($tmpZip);
        json_err('Failed to open downloaded archive', 500);
    }

    // Validate all entries for path-traversal sequences before extracting
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) continue;
        // Reject entries that contain traversal sequences or null bytes
        if (strpos($name, '..') !== false || strpos($name, "\0") !== false) {
            $zip->close();
            @unlink($tmpZip);
            json_err('Archive contains unsafe paths', 400);
        }
    }

    $zip->extractTo($tmpDir);
    $zip->close();
    @unlink($tmpZip);

    // The GitHub archive contains a single top-level folder (e.g. jobHunter-main/)
    // with a public/ subdirectory inside. Find it.
    $extractedPublic = null;
    foreach (scandir($tmpDir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $candidate = $tmpDir . '/' . $item . '/public';
        if (is_dir($candidate)) {
            $extractedPublic = $candidate;
            break;
        }
    }

    if (!$extractedPublic) {
        delete_dir_recursive($tmpDir);
        json_err('Unexpected archive structure – could not locate public/ folder', 500);
    }

    [$copied, $failed] = copy_update_files($extractedPublic, $webRoot, $skipDstPath);
    delete_dir_recursive($tmpDir);

    $newVersion = '?';
    if (file_exists($webRoot . '/version.json')) {
        $vj = json_decode(file_get_contents($webRoot . '/version.json'), true);
        $newVersion = $vj['version'] ?? '?';
    }

    json_ok(['copied' => $copied, 'failed' => $failed, 'version' => $newVersion]);
}

// ── 404 ────────────────────────────────────────────────────────────────────
json_err('Unknown route: ' . $route, 404);
