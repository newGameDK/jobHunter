<?php
// =========================================================================
// JobHunter – PHP Database Setup
// =========================================================================

$DATA_DIR = __DIR__ . '/data';
if (!is_dir($DATA_DIR)) {
    mkdir($DATA_DIR, 0750, true);
}

if (!extension_loaded('pdo_sqlite')) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'SQLite PDO extension is not available. Please enable pdo_sqlite in your PHP configuration.']);
    exit;
}

try {
    $db = new PDO('sqlite:' . $DATA_DIR . '/jobhunter.db');
} catch (Exception $e) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Cannot open database: ' . $e->getMessage()]);
    exit;
}

$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA journal_mode = WAL');
$db->exec('PRAGMA foreign_keys = ON');

// -------------------------------------------------------------------------
// Schema
// -------------------------------------------------------------------------

$db->exec("
CREATE TABLE IF NOT EXISTS users (
  id TEXT PRIMARY KEY,
  username TEXT UNIQUE NOT NULL,
  email TEXT UNIQUE NOT NULL,
  password_hash TEXT NOT NULL,
  created_at INTEGER NOT NULL DEFAULT (strftime('%s','now') * 1000)
);

CREATE TABLE IF NOT EXISTS user_settings (
  user_id TEXT PRIMARY KEY,
  chatgpt_api_key TEXT NOT NULL DEFAULT '',
  search_descriptions TEXT NOT NULL DEFAULT '[]',
  last_url TEXT NOT NULL DEFAULT '',
  updated_at INTEGER NOT NULL DEFAULT (strftime('%s','now') * 1000),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS jobs (
  id TEXT PRIMARY KEY,
  user_id TEXT NOT NULL,
  title TEXT NOT NULL DEFAULT '',
  company TEXT NOT NULL DEFAULT '',
  location TEXT NOT NULL DEFAULT '',
  url TEXT NOT NULL DEFAULT '',
  description TEXT NOT NULL DEFAULT '',
  gpt_analysis TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'new',
  found_at INTEGER NOT NULL DEFAULT (strftime('%s','now') * 1000),
  updated_at INTEGER NOT NULL DEFAULT (strftime('%s','now') * 1000),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
");

// Migrations: add columns to existing databases
$migrations = [
    "ALTER TABLE jobs ADD COLUMN gpt_analysis TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE user_settings ADD COLUMN last_url TEXT NOT NULL DEFAULT ''",
];
foreach ($migrations as $m) {
    try { $db->exec($m); } catch (Exception $e) { /* column already exists */ }
}

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

function uuid_v4() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function now_ms() {
    return (int)(microtime(true) * 1000);
}

function sanitize_user($u) {
    return [
        'id'         => $u['id'],
        'username'   => $u['username'],
        'email'      => $u['email'],
        'created_at' => (int)$u['created_at'],
    ];
}

// Encrypt/decrypt the ChatGPT API key using a server-side key derived from
// the user's own password hash so it is never stored in plain text.
define('ENCRYPT_CIPHER', 'AES-256-CBC');

function encrypt_key($plain, $secret) {
    if ($plain === '') return '';
    $iv  = random_bytes(openssl_cipher_iv_length(ENCRYPT_CIPHER));
    $enc = openssl_encrypt($plain, ENCRYPT_CIPHER, hash('sha256', $secret, true), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
}

function decrypt_key($encoded, $secret) {
    if ($encoded === '') return '';
    $raw    = base64_decode($encoded);
    $ivLen  = openssl_cipher_iv_length(ENCRYPT_CIPHER);
    $iv     = substr($raw, 0, $ivLen);
    $enc    = substr($raw, $ivLen);
    $plain  = openssl_decrypt($enc, ENCRYPT_CIPHER, hash('sha256', $secret, true), OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
}

function get_session_user($db) {
    if (empty($_SESSION['user_id'])) return null;
    $s = $db->prepare('SELECT * FROM users WHERE id = ?');
    $s->execute([$_SESSION['user_id']]);
    return $s->fetch() ?: null;
}

function ensure_settings_row($db, $userId) {
    $s = $db->prepare('INSERT OR IGNORE INTO user_settings (user_id) VALUES (?)');
    $s->execute([$userId]);
}
