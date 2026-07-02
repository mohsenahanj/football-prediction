<?php
/**
 * api/submit.php – Handles prediction form submission via AJAX (POST).
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jsonOut(['success' => false, 'message' => 'Method not allowed.']);
}

// ── CSRF Check ───────────────────────────────────────────────────────────────
$token = $_POST['csrf_token'] ?? '';
if (!csrfVerify($token)) {
    http_response_code(403);
    jsonOut(['success' => false, 'message' => 'Token invalide. Veuillez actualiser la page.']);
}

// ── Input validation ─────────────────────────────────────────────────────────
$name       = sanitizeStr($_POST['name'] ?? '', 120);
$phone      = sanitizeStr($_POST['phone'] ?? '', 30);
$prediction = trim($_POST['prediction'] ?? '');
$matchId    = (int)($_POST['match_id'] ?? 1);

$errors = [];

if (mb_strlen($name) < 2) {
    $errors[] = 'Veuillez entrer votre nom complet.';
}

// Phone: allow digits, spaces, +, -, (, ) — min 7 chars of digits
$digitsOnly = preg_replace('/\D/', '', $phone);
if (strlen($digitsOnly) < 7 || strlen($phone) < 5) {
    $errors[] = 'Veuillez entrer un numéro de téléphone valide.';
}

if (!in_array($prediction, ['team1', 'draw', 'team2'], true)) {
    $errors[] = 'Valeur de pronostic invalide.';
}

if ($errors) {
    jsonOut(['success' => false, 'message' => implode(' ', $errors)]);
}

// ── Rate limiting: 1 entry per IP per match ──────────────────────────────────
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
try {
    $pdo  = getDB();

    // Verify match exists and is active
    $mStmt = $pdo->prepare("SELECT id FROM matches WHERE id = ? AND is_active = 1");
    $mStmt->execute([$matchId]);
    if (!$mStmt->fetch()) {
        jsonOut(['success' => false, 'message' => 'Ce match n\'est pas actif actuellement.']);
    }

    // Check duplicate by phone + match
    $dupStmt = $pdo->prepare("SELECT id FROM participants WHERE phone = ? AND match_id = ? LIMIT 1");
    $dupStmt->execute([$phone, $matchId]);
    if ($dupStmt->fetch()) {
        jsonOut(['success' => false, 'message' => 'Vous avez déjà soumis un pronostic pour ce match.']);
    }

    // Insert
    $ins = $pdo->prepare("
        INSERT INTO participants (name, phone, prediction, match_id, ip)
        VALUES (?, ?, ?, ?, ?)
    ");
    $ins->execute([$name, $phone, $prediction, $matchId, $ip]);

    jsonOut(['success' => true, 'message' => 'Thank you! Your prediction has been registered. 🎉']);

} catch (PDOException $e) {
    error_log('DB error in submit.php: ' . $e->getMessage());
    jsonOut(['success' => false, 'message' => 'Une erreur serveur est survenue. Veuillez réessayer.']);
}