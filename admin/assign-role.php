<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');

// Helper: send JSON and exit
function jsonExit($data, int $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// must be admin
if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'admin') {
    // If AJAX, return JSON; else show forbidden
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        jsonExit(['error' => 'Forbidden'], 403);
    }
    http_response_code(403);
    exit('Forbidden');
}

// load DB
$pdo = require __DIR__ . '/../auth/db.php';

// Collect and normalize inputs
$rawEmails = $_POST['emails'] ?? ($_POST['email'] ?? '');
$role = $_POST['role'] ?? '';
$clubDir = isset($_POST['clubDir']) ? trim((string)$_POST['clubDir']) : '';
$clubAction = isset($_POST['clubAction']) ? trim((string)$_POST['clubAction']) : '';

// Validate role
$allowedRoles = ['student','teacher','executive','admin'];
if (!in_array($role, $allowedRoles, true)) {
    jsonExit(['error' => 'Invalid role'], 400);
}

// Parse multiple emails from newline/comma/space separated input
function parseEmails(string $s): array {
    $parts = preg_split('/[\r\n,;]+/', $s);
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        // allow only valid emails
        if (!filter_var($p, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $out[] = strtolower($p);
    }
    // dedupe
    return array_values(array_unique($out));
}

$emails = parseEmails((string)$rawEmails);

if (empty($emails)) {
    jsonExit(['error' => 'No valid emails provided.'], 400);
}

// Optional club validation
$projectRoot = dirname(__DIR__);
$clubEdited = null;
$updatedExecs = null;

if ($clubDir !== '') {
    if (!preg_match('/^[a-z0-9\-_]+$/', $clubDir)) {
        jsonExit(['error' => 'Invalid club directory name'], 400);
    }
    $clubPath = $projectRoot . '/' . $clubDir;
    $drawerFile = $clubPath . '/drawer.json';
    if (!is_dir($clubPath) || !file_exists($drawerFile)) {
        jsonExit(['error' => 'Club not found: ' . $clubDir], 404);
    }
    if (!in_array($clubAction, ['', 'add', 'remove'], true)) {
        jsonExit(['error' => 'Invalid club action'], 400);
    }
    $clubEdited = $clubDir;
}

// Results per email
$results = [];

foreach ($emails as $email) {
    $row = ['email' => $email, 'created' => false, 'role_updated' => false, 'error' => null];

    try {
        // Check if user exists
        $stmt = $pdo->prepare("SELECT id, role FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Update role if different
            if ($user['role'] !== $role) {
                $u = $pdo->prepare("UPDATE users SET role = ? WHERE email = ?");
                $u->execute([$role, $email]);
                $row['role_updated'] = true;
            }
        } else {
            // Create a minimal user entry (name from local part)
            $name = explode('@', $email)[0];
            $ins = $pdo->prepare("INSERT INTO users (name, email, google_id, role) VALUES (?, ?, ?, ?)");
            $ins->execute([$name, $email, '', $role]);
            $row['created'] = true;
            $row['role_updated'] = true;
        }
    } catch (Exception $e) {
        $row['error'] = 'DB error: ' . $e->getMessage();
    }

    $results[] = $row;
}

// If club edit requested, update drawer.json
if ($clubEdited !== null && $clubAction !== '') {
    $drawerFile = $projectRoot . '/' . $clubEdited . '/drawer.json';
    $drawer = json_decode(file_get_contents($drawerFile), true);
    if (!is_array($drawer)) {
        jsonExit(['error' => 'Invalid drawer.json for club: ' . $clubEdited], 500);
    }
    $execs = array_map('strtolower', $drawer['executiveEmails'] ?? []);

    if ($clubAction === 'add') {
        $merged = array_unique(array_merge($execs, $emails));
        $drawer['executiveEmails'] = array_values($merged);
    } elseif ($clubAction === 'remove') {
        $drawer['executiveEmails'] = array_values(array_filter($execs, function($e) use ($emails) {
            return !in_array($e, $emails, true);
        }));
    }

    if (file_put_contents($drawerFile, json_encode($drawer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
        jsonExit(['error' => 'Failed to write drawer.json for club: ' . $clubEdited], 500);
    }

    $updatedExecs = $drawer['executiveEmails'];
}

// Determine if request is AJAX (fetch from front-end)
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);

if ($isAjax) {
    $response = ['success' => true, 'results' => $results];
    if ($clubEdited !== null) {
        $response['club'] = ['dirName' => $clubEdited, 'executiveEmails' => $updatedExecs];
    }
    jsonExit($response, 200);
}

// Non-AJAX fallback: redirect back to dashboard
header('Location: dashboard.php');
exit;