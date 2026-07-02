<?php
/**
 * adv741.php – Admin Panel
 * Protected by session-based login.
 */
require_once __DIR__ . '/includes/config.php';

// ── Upload dir ───────────────────────────────────────────────────────────────
if (!is_dir(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0755, true);
}

// ── Handle actions (POST) ────────────────────────────────────────────────────
$flashMsg   = '';
$flashType  = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // LOGIN
    if ($action === 'login') {
        $user = trim($_POST['username'] ?? '');
        $pass = trim($_POST['password'] ?? '');
        if ($user === ADMIN_USER && password_verify($pass, ADMIN_PASS_HASH)) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_user']      = $user;
            header('Location: adv741.php');
            exit;
        } else {
            $flashMsg  = 'Invalid username or password.';
            $flashType = 'error';
        }

    // All other actions require login
    } elseif (isAdminLoggedIn()) {

        // Verify CSRF for all authenticated actions
        $tok = $_POST['csrf_token'] ?? '';
        if (!csrfVerify($tok)) {
            $flashMsg = 'Invalid security token. Please try again.';
            $flashType = 'error';
        } else {
            $pdo = getDB();

            // LOGOUT
            if ($action === 'logout') {
                session_destroy();
                header('Location: adv741.php');
                exit;
            }

            // SET RESULT
            if ($action === 'set_result') {
                $matchId = (int)($_POST['match_id'] ?? 0);
                $result  = trim($_POST['result'] ?? '');
                if ($matchId > 0 && in_array($result, ['team1','draw','team2'], true)) {
                    $stmt = $pdo->prepare("UPDATE matches SET result = ?, winner_id = NULL WHERE id = ?");
                    $stmt->execute([$result, $matchId]);
                    $flashMsg = 'Match result updated successfully.';
                } else {
                    $flashMsg = 'Invalid match or result.'; $flashType = 'error';
                }
            }

            // SET WINNER (lottery result)
            if ($action === 'set_winner') {
                $winnerId = (int)($_POST['winner_id'] ?? 0);
                $matchId  = (int)($_POST['match_id'] ?? 0);
                if ($winnerId > 0 && $matchId > 0) {
                    $pdo->prepare("UPDATE matches SET winner_id = ? WHERE id = ?")->execute([$winnerId, $matchId]);
                    $flashMsg = 'Winner saved successfully! 🎉';
                }
            }

            // UPDATE MATCH (team names, flags, date)
            if ($action === 'update_match') {
                $matchId   = (int)($_POST['match_id'] ?? 0);
                $team1     = sanitizeStr($_POST['team1_name'] ?? '', 60);
                $team2     = sanitizeStr($_POST['team2_name'] ?? '', 60);
                $matchDate = sanitizeStr($_POST['match_date'] ?? '', 80);

                if (!$team1 || !$team2) {
                    $flashMsg = 'Team names cannot be empty.'; $flashType = 'error';
                } else {
                    // Handle flag uploads
                    $team1Flag = $_POST['team1_flag_current'] ?? '';
                    $team2Flag = $_POST['team2_flag_current'] ?? '';

                    foreach (['team1_flag', 'team2_flag'] as $field) {
                        if (!empty($_FILES[$field]['tmp_name']) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
                            $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
                            if (!in_array($ext, ['jpg','jpeg','png','webp','gif','svg'], true)) {
                                $flashMsg = "Invalid image type for flag."; $flashType = 'error'; break;
                            }
                            if ($_FILES[$field]['size'] > MAX_FILE_SIZE) {
                                $flashMsg = "Flag image is too large (max 2MB)."; $flashType = 'error'; break;
                            }
                            $filename = 'flag_' . $field . '_' . $matchId . '_' . time() . '.' . $ext;
                            $dest = UPLOAD_DIR . $filename;
                            if (move_uploaded_file($_FILES[$field]['tmp_name'], $dest)) {
                                if ($field === 'team1_flag') $team1Flag = 'assets/uploads/' . $filename;
                                else $team2Flag = 'assets/uploads/' . $filename;
                            }
                        }
                    }

                    if ($flashType === 'success') {
                        $pdo->prepare("
                            UPDATE matches SET team1_name=?, team2_name=?, team1_flag=?, team2_flag=?, match_date=?
                            WHERE id=?
                        ")->execute([$team1, $team2, $team1Flag, $team2Flag, $matchDate, $matchId]);
                        $flashMsg = 'Match configuration updated.';
                    }
                }
            }

            // CREATE NEW MATCH
            if ($action === 'new_match') {
                // Deactivate others
                $pdo->exec("UPDATE matches SET is_active = 0");
                $pdo->prepare("
                    INSERT INTO matches (team1_name, team2_name, team1_flag, team2_flag, match_date, is_active)
                    VALUES (?, ?, ?, ?, ?, 1)
                ")->execute(['Team 1','Team 2','','','TBD']);
                $newId = $pdo->lastInsertId();
                $flashMsg = "New match created (ID $newId). Please configure it below.";
            }

            // EXPORT CSV
            if ($action === 'export_csv') {
                $matchId = (int)($_POST['match_id'] ?? 0);
                $stmt = $pdo->prepare("
                    SELECT p.id, p.name, p.phone, p.prediction, p.created_at,
                           m.team1_name, m.team2_name, m.result
                    FROM participants p
                    JOIN matches m ON m.id = p.match_id
                    WHERE p.match_id = ?
                    ORDER BY p.id
                ");
                $stmt->execute([$matchId]);
                $rows = $stmt->fetchAll();

                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="predictions_match_'.$matchId.'_'.date('Ymd').'.csv"');
                $out = fopen('php://output', 'w');
                fputcsv($out, ['#','Name','Phone','Prediction','Correct?','Registered At']);
                foreach ($rows as $r) {
                    $pred = $r['prediction'] === 'team1' ? $r['team1_name'].' Wins'
                          : ($r['prediction'] === 'team2' ? $r['team2_name'].' Wins' : 'Draw');
                    $correct = $r['result'] ? ($r['prediction'] === $r['result'] ? 'YES' : 'NO') : '?';
                    fputcsv($out, [$r['id'], $r['name'], $r['phone'], $pred, $correct, $r['created_at']]);
                }
                fclose($out);
                exit;
            }
        }
    }
}

// ── Require login from here ───────────────────────────────────────────────────
$showDash = isAdminLoggedIn();
$csrf     = csrfToken();

// Fetch data if logged in
$matches      = [];
$activeMatch  = null;
$participants = [];
$stats        = [];

if ($showDash) {
    try {
        $pdo         = getDB();
        $matches     = $pdo->query("SELECT * FROM matches ORDER BY id DESC")->fetchAll();
        $activeMatch = $pdo->query("SELECT * FROM matches WHERE is_active=1 ORDER BY id DESC LIMIT 1")->fetch();

        if ($activeMatch) {
            $mid  = (int)$activeMatch['id'];
            $participants = $pdo->prepare("SELECT * FROM participants WHERE match_id=? ORDER BY id DESC");
            $participants->execute([$mid]);
            $participants = $participants->fetchAll();

            $t1   = 0; $t2 = 0; $d = 0;
            foreach ($participants as $p) {
                if ($p['prediction'] === 'team1') $t1++;
                elseif ($p['prediction'] === 'team2') $t2++;
                else $d++;
            }
            $result  = $activeMatch['result'] ?? '';
            $correct = $result ? array_filter($participants, fn($p) => $p['prediction'] === $result) : [];
            $stats   = [
                'total'   => count($participants),
                'team1'   => $t1, 'draw' => $d, 'team2' => $t2,
                'correct' => count($correct),
                'result'  => $result,
            ];
        }
    } catch (PDOException $e) {
        $flashMsg  = 'Database error: ' . htmlspecialchars($e->getMessage());
        $flashType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Panel – Football Prediction</title>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --pitch:     #0a1f0a;
  --neon:      #39ff14;
  --gold:      #f5c842;
  --rose:      #f7a8c4;
  --drose:     #e8799b;
  --pearl:     #faf7f2;
  --dark:      #080d08;
  --card:      rgba(255,255,255,0.05);
  --border:    rgba(57,255,20,0.18);
}
body {
  font-family: 'Inter', sans-serif;
  background: var(--dark);
  color: var(--pearl);
  min-height: 100vh;
}
/* Grid bg */
body::before {
  content: '';
  position: fixed; inset: 0; z-index: 0;
  background-image:
    repeating-linear-gradient(0deg, rgba(57,255,20,0.025) 0, rgba(57,255,20,0.025) 1px, transparent 1px, transparent 50px),
    repeating-linear-gradient(90deg, rgba(57,255,20,0.025) 0, rgba(57,255,20,0.025) 1px, transparent 1px, transparent 50px);
  pointer-events: none;
}

/* ── Login ───────────────────────────────────────────────────────────────── */
.login-wrap {
  min-height: 100vh; display: flex; align-items: center; justify-content: center;
  padding: 20px; position: relative; z-index: 1;
}
.login-card {
  background: rgba(10,31,10,0.9);
  border: 1px solid var(--border);
  border-radius: 20px; padding: 40px 32px;
  width: 100%; max-width: 400px;
  box-shadow: 0 0 60px rgba(57,255,20,0.1);
}
.login-logo { font-size: 48px; text-align: center; margin-bottom: 8px; }
.login-card h1 { font-family: 'Oswald', sans-serif; font-size: 22px; text-align: center; color: var(--neon); letter-spacing: 2px; margin-bottom: 4px; }
.login-card .sub { font-size: 12px; color: rgba(250,247,242,0.4); text-align:center; margin-bottom: 28px; }

/* ── Dashboard layout ────────────────────────────────────────────────────── */
.dash-wrap { display: flex; min-height: 100vh; position: relative; z-index: 1; }
.sidebar {
  width: 220px; flex-shrink: 0;
  background: rgba(6,16,6,0.95);
  border-right: 1px solid rgba(57,255,20,0.1);
  padding: 24px 0;
  position: sticky; top: 0; height: 100vh;
  overflow-y: auto;
}
.sidebar-logo { padding: 0 20px 24px; border-bottom: 1px solid rgba(255,255,255,0.06); margin-bottom: 16px; }
.sidebar-logo .icon { font-size: 28px; }
.sidebar-logo h2 { font-family: 'Oswald', sans-serif; font-size: 14px; color: var(--neon); letter-spacing: 2px; margin-top: 4px; }
.nav-item {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 20px; cursor: pointer;
  font-size: 13px; font-weight: 500;
  color: rgba(250,247,242,0.5);
  border-left: 2px solid transparent;
  transition: all 0.2s; text-decoration: none;
  background: none; border-right: none; border-top: none; border-bottom: none;
  width: 100%; text-align: left; font-family: 'Inter', sans-serif;
}
.nav-item:hover { color: var(--pearl); background: rgba(57,255,20,0.04); }
.nav-item.active { color: var(--neon); border-left-color: var(--neon); background: rgba(57,255,20,0.07); }
.nav-item .icon { font-size: 16px; width: 20px; text-align: center; }
.sidebar-foot { padding: 20px; margin-top: auto; border-top: 1px solid rgba(255,255,255,0.06); }

.main-area { flex: 1; overflow-y: auto; }
.top-bar {
  background: rgba(6,16,6,0.8);
  border-bottom: 1px solid rgba(255,255,255,0.06);
  padding: 14px 28px;
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  position: sticky; top: 0; z-index: 50;
}
.top-bar h1 { font-family: 'Oswald', sans-serif; font-size: 18px; color: var(--neon); letter-spacing: 1px; }

.section { display: none; padding: 28px; }
.section.active { display: block; }

/* ── Stats grid ──────────────────────────────────────────────────────────── */
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 14px; margin-bottom: 28px; }
.stat-card {
  background: var(--card);
  border: 1px solid rgba(255,255,255,0.07);
  border-radius: 14px; padding: 18px;
  text-align: center;
}
.stat-card .num { font-family: 'Oswald', sans-serif; font-size: 34px; color: var(--neon); line-height: 1; }
.stat-card .lbl { font-size: 11px; color: rgba(250,247,242,0.4); margin-top: 4px; letter-spacing: 0.5px; }

/* ── Card ────────────────────────────────────────────────────────────────── */
.card {
  background: var(--card);
  border: 1px solid rgba(255,255,255,0.07);
  border-radius: 16px; padding: 22px;
  margin-bottom: 20px;
}
.card h3 { font-size: 14px; font-weight: 600; color: var(--gold); margin-bottom: 16px; letter-spacing: 0.5px; }

/* ── Forms ───────────────────────────────────────────────────────────────── */
.field { margin-bottom: 14px; }
.field label { display: block; font-size: 11px; font-weight: 600; letter-spacing: 1.5px; text-transform: uppercase; color: rgba(250,247,242,0.4); margin-bottom: 6px; }
.field input[type="text"],
.field input[type="password"],
.field input[type="tel"],
.field input[type="file"],
.field select {
  width: 100%; padding: 12px 14px;
  border-radius: 10px;
  border: 1.5px solid rgba(57,255,20,0.15);
  background: rgba(255,255,255,0.05);
  color: var(--pearl);
  font-family: 'Inter', sans-serif; font-size: 14px;
  outline: none; transition: all 0.25s;
  -webkit-appearance: none;
}
.field input:focus, .field select:focus { border-color: var(--neon); background: rgba(57,255,20,0.05); }
.field input::placeholder { color: rgba(250,247,242,0.2); }

/* ── Buttons ─────────────────────────────────────────────────────────────── */
.btn {
  padding: 11px 22px; border-radius: 10px; border: none;
  font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 600;
  cursor: pointer; transition: all 0.25s; letter-spacing: 0.3px;
}
.btn-primary { background: linear-gradient(135deg, #1a8a00, var(--neon)); color: #000; box-shadow: 0 4px 20px rgba(57,255,20,0.25); }
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 28px rgba(57,255,20,0.4); }
.btn-gold { background: linear-gradient(135deg, #c87000, var(--gold)); color: #000; box-shadow: 0 4px 20px rgba(245,200,66,0.25); }
.btn-gold:hover { transform: translateY(-1px); box-shadow: 0 6px 28px rgba(245,200,66,0.4); }
.btn-rose { background: linear-gradient(135deg, #a0344d, var(--drose)); color: #fff; }
.btn-rose:hover { transform: translateY(-1px); }
.btn-ghost { background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); color: var(--pearl); }
.btn-ghost:hover { background: rgba(255,255,255,0.12); }
.btn-sm { padding: 8px 16px; font-size: 12px; }
.btn-full { width: 100%; padding: 14px; font-size: 15px; }

/* ── Table ───────────────────────────────────────────────────────────────── */
.table-wrap { overflow-x: auto; border-radius: 12px; border: 1px solid rgba(255,255,255,0.07); }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
thead th {
  background: rgba(57,255,20,0.07); padding: 11px 14px;
  text-align: left; font-size: 10px; font-weight: 700;
  letter-spacing: 1.5px; text-transform: uppercase; color: var(--neon); white-space: nowrap;
}
tbody tr { border-top: 1px solid rgba(255,255,255,0.04); transition: background 0.15s; }
tbody tr:hover { background: rgba(255,255,255,0.025); }
tbody td { padding: 11px 14px; color: rgba(250,247,242,0.8); }
.badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.badge-green { background: rgba(57,255,20,0.12); color: var(--neon); border: 1px solid rgba(57,255,20,0.25); }
.badge-gold  { background: rgba(245,200,66,0.12); color: var(--gold); border: 1px solid rgba(245,200,66,0.25); }
.badge-rose  { background: rgba(247,168,196,0.12); color: var(--rose); border: 1px solid rgba(247,168,196,0.25); }
.badge-grey  { background: rgba(255,255,255,0.07); color: rgba(250,247,242,0.5); }
.badge-red   { background: rgba(255,80,80,0.1); color: #ff8080; }

/* ── Result selector ─────────────────────────────────────────────────────── */
.result-opts { display: flex; gap: 10px; flex-wrap: wrap; }
.result-opt {
  flex: 1; min-width: 90px; padding: 13px 10px;
  border-radius: 12px; border: 1.5px solid rgba(57,255,20,0.2);
  background: rgba(57,255,20,0.04);
  color: var(--pearl); font-family: 'Inter', sans-serif;
  font-size: 13px; font-weight: 600; cursor: pointer; text-align: center;
  transition: all 0.25s;
}
.result-opt:hover { border-color: var(--neon); background: rgba(57,255,20,0.1); }
.result-opt.sel { border-color: var(--neon); background: rgba(57,255,20,0.18); color: var(--neon); box-shadow: 0 0 0 2px var(--neon); }

/* ── Lottery ─────────────────────────────────────────────────────────────── */
.lottery-wrap { text-align: center; }
.lottery-pool-info { font-size: 14px; color: rgba(250,247,242,0.5); margin-bottom: 20px; }
.names-reel {
  height: 64px; overflow: hidden; border-radius: 12px;
  border: 1px solid rgba(57,255,20,0.2);
  background: rgba(0,0,0,0.4);
  position: relative; margin-bottom: 20px; display: none;
}
.names-reel::before, .names-reel::after {
  content: ''; position: absolute; left: 0; right: 0; height: 20px; z-index: 2; pointer-events: none;
}
.names-reel::before { top: 0; background: linear-gradient(to bottom, rgba(0,0,0,0.7), transparent); }
.names-reel::after { bottom: 0; background: linear-gradient(to top, rgba(0,0,0,0.7), transparent); }
#reelInner { display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 6px 0; }
.reel-name { font-size: 18px; font-weight: 600; padding: 3px 0; color: rgba(250,247,242,0.45); white-space: nowrap; transition: all 0.08s; }
.reel-name.center { color: var(--neon); font-size: 22px; transform: scale(1.05); text-shadow: 0 0 12px var(--neon); }

.winner-card {
  background: linear-gradient(135deg, rgba(57,255,20,0.1), rgba(245,200,66,0.08));
  border: 1px solid rgba(57,255,20,0.3);
  border-radius: 20px; padding: 28px; display: none;
  animation: popIn 0.5s cubic-bezier(0.34,1.56,0.64,1);
}
@keyframes popIn { from { transform: scale(0.7); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.winner-trophy { font-size: 52px; margin-bottom: 10px; }
.winner-card h3 { font-family: 'Oswald', sans-serif; font-size: 22px; color: var(--gold); margin-bottom: 6px; letter-spacing: 1px; }
.winner-name-big { font-size: 30px; font-weight: 700; color: var(--pearl); margin: 8px 0; }
.winner-phone { font-size: 14px; color: rgba(250,247,242,0.5); }

/* ── Flash ───────────────────────────────────────────────────────────────── */
.flash {
  margin-bottom: 20px; padding: 12px 16px; border-radius: 10px;
  font-size: 13px; font-weight: 500;
}
.flash-success { background: rgba(57,255,20,0.1); border: 1px solid rgba(57,255,20,0.25); color: var(--neon); }
.flash-error   { background: rgba(255,80,80,0.1); border: 1px solid rgba(255,80,80,0.25); color: #ff9090; }

/* ── Mobile sidebar toggle ───────────────────────────────────────────────── */
@media (max-width: 700px) {
  .dash-wrap { flex-direction: column; }
  .sidebar { width: 100%; height: auto; position: relative; display: flex; flex-wrap: wrap; align-items: center; padding: 12px 16px; gap: 8px; }
  .sidebar-logo { padding: 0; border: none; margin: 0; flex-shrink: 0; }
  .nav-item { padding: 8px 12px; border-left: none; border-bottom: 2px solid transparent; border-radius: 8px; font-size: 12px; }
  .nav-item.active { border-bottom-color: var(--neon); border-left-color: transparent; }
  .sidebar-foot { display: none; }
  .section { padding: 16px; }
  .stats-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>
</head>
<body>

<?php if (!$showDash): ?>
<!-- ═══════════════════════════════ LOGIN ════════════════════════════════════ -->
<div class="login-wrap">
  <div class="login-card">
    <div class="login-logo">⚽</div>
    <h1>Admin Panel</h1>
    <p class="sub">Football Prediction · Secure Access</p>

    <?php if ($flashMsg): ?>
      <div class="flash flash-<?= $flashType ?>"><?= htmlspecialchars($flashMsg) ?></div>
    <?php endif; ?>

    <form method="POST" action="adv741.php">
      <input type="hidden" name="action" value="login">
      <div class="field">
        <label>Username</label>
        <input type="text" name="username" placeholder="admin" autocomplete="username" required>
      </div>
      <div class="field">
        <label>Password</label>
        <input type="password" name="password" placeholder="••••••••" autocomplete="current-password" required>
      </div>
      <button type="submit" class="btn btn-primary btn-full" style="margin-top:8px;">Login →</button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ══════════════════════════════ DASHBOARD ══════════════════════════════════ -->
<?php
$team1Name = htmlspecialchars($activeMatch['team1_name'] ?? 'France', ENT_QUOTES, 'UTF-8');
$team2Name = htmlspecialchars($activeMatch['team2_name'] ?? 'Sweden', ENT_QUOTES, 'UTF-8');
$matchId   = (int)($activeMatch['id'] ?? 1);
$result    = $activeMatch['result'] ?? '';
$winnerId  = $activeMatch['winner_id'] ?? null;
$correctParticipants = $result ? array_values(array_filter($participants, fn($p) => $p['prediction'] === $result)) : [];
?>
<div class="dash-wrap">

  <!-- Sidebar -->
  <nav class="sidebar">
    <div class="sidebar-logo">
      <div class="icon">⚽</div>
      <h2>ADMIN</h2>
    </div>
    <button class="nav-item active" onclick="showSection('overview', this)"><span class="icon">📊</span> Overview</button>
    <button class="nav-item" onclick="showSection('participants', this)"><span class="icon">👥</span> Participants</button>
    <button class="nav-item" onclick="showSection('lottery', this)"><span class="icon">🎰</span> Lottery</button>
    <button class="nav-item" onclick="showSection('match', this)"><span class="icon">⚙️</span> Match Setup</button>
    <div class="sidebar-foot">
      <form method="POST" action="adv741.php">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit" class="btn btn-ghost" style="width:100%;">🚪 Logout</button>
      </form>
    </div>
  </nav>

  <!-- Main -->
  <div class="main-area">
    <div class="top-bar">
      <h1>⚽ Football Prediction Admin</h1>
      <div style="display:flex;gap:8px;align-items:center;">
        <form method="POST" action="adv741.php" style="margin:0;">
          <input type="hidden" name="action" value="export_csv">
          <input type="hidden" name="match_id" value="<?= $matchId ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <button type="submit" class="btn btn-ghost btn-sm">⬇ Export CSV</button>
        </form>
        <form method="POST" action="adv741.php" style="margin:0;">
          <input type="hidden" name="action" value="logout">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <button type="submit" class="btn btn-ghost btn-sm">Logout</button>
        </form>
      </div>
    </div>

    <?php if ($flashMsg): ?>
    <div style="padding:16px 28px 0;">
      <div class="flash flash-<?= $flashType ?>"><?= htmlspecialchars($flashMsg) ?></div>
    </div>
    <?php endif; ?>

    <!-- ── OVERVIEW ───────────────────────────────────────────────────────── -->
    <div class="section active" id="sec-overview">
      <h2 style="font-family:'Oswald',sans-serif; font-size:18px; color:var(--neon); margin-bottom:20px; letter-spacing:1px;">
        <?= $team1Name ?> vs <?= $team2Name ?>
      </h2>

      <div class="stats-grid">
        <div class="stat-card"><div class="num"><?= $stats['total'] ?? 0 ?></div><div class="lbl">Total Participants</div></div>
        <div class="stat-card"><div class="num" style="color:var(--neon)"><?= $stats['team1'] ?? 0 ?></div><div class="lbl"><?= $team1Name ?> Win</div></div>
        <div class="stat-card"><div class="num" style="color:var(--gold)"><?= $stats['draw'] ?? 0 ?></div><div class="lbl">Draw</div></div>
        <div class="stat-card"><div class="num" style="color:var(--rose)"><?= $stats['team2'] ?? 0 ?></div><div class="lbl"><?= $team2Name ?> Win</div></div>
        <div class="stat-card"><div class="num" style="color:var(--drose)"><?= $stats['correct'] ?? '—' ?></div><div class="lbl">Correct Predictions</div></div>
      </div>

      <!-- Set Result -->
      <div class="card">
        <h3>🏆 Set Match Result</h3>
        <p style="font-size:13px;color:rgba(250,247,242,0.45);margin-bottom:16px;">Set the actual result to enable the lottery among correct predictors.</p>
        <form method="POST" action="adv741.php" id="resultForm">
          <input type="hidden" name="action" value="set_result">
          <input type="hidden" name="match_id" value="<?= $matchId ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="result" id="resultHidden" value="<?= htmlspecialchars($result) ?>">
          <div class="result-opts">
            <button type="button" class="result-opt <?= $result==='team1'?'sel':'' ?>" onclick="pickResult('team1',this)">🇫🇷 <?= $team1Name ?></button>
            <button type="button" class="result-opt <?= $result==='draw'?'sel':'' ?>" onclick="pickResult('draw',this)">🤝 Draw</button>
            <button type="button" class="result-opt <?= $result==='team2'?'sel':'' ?>" onclick="pickResult('team2',this)">🇸🇪 <?= $team2Name ?></button>
          </div>
          <button type="submit" class="btn btn-primary" style="margin-top:16px;" <?= !$result?'disabled':'' ?> id="saveResultBtn">Save Result</button>
        </form>
        <?php if ($result): ?>
          <div style="margin-top:12px;font-size:13px;color:var(--neon);">✓ Current result saved: <strong><?= $result==='team1'?$team1Name:($result==='team2'?$team2Name:'Draw') ?></strong></div>
        <?php endif; ?>
      </div>

      <!-- New match -->
      <div class="card">
        <h3>➕ Create New Match</h3>
        <p style="font-size:13px;color:rgba(250,247,242,0.45);margin-bottom:16px;">This will archive the current match and create a new one. Configure team names and flags in Match Setup.</p>
        <form method="POST" action="adv741.php" onsubmit="return confirm('Create a new match? The current match will be archived.');">
          <input type="hidden" name="action" value="new_match">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <button type="submit" class="btn btn-ghost">Create New Match</button>
        </form>
      </div>
    </div>

    <!-- ── PARTICIPANTS ──────────────────────────────────────────────────── -->
    <div class="section" id="sec-participants">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px;">
        <h2 style="font-family:'Oswald',sans-serif;font-size:18px;color:var(--neon);letter-spacing:1px;">
          👥 Participants (<?= count($participants) ?>)
        </h2>
        <form method="POST" action="adv741.php">
          <input type="hidden" name="action" value="export_csv">
          <input type="hidden" name="match_id" value="<?= $matchId ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <button class="btn btn-gold btn-sm" type="submit">⬇ Download CSV</button>
        </form>
      </div>

      <?php if (empty($participants)): ?>
        <div class="card" style="text-align:center;padding:40px;color:rgba(250,247,242,0.35);">
          <div style="font-size:40px;margin-bottom:12px;">👤</div>
          <p>No participants yet.</p>
        </div>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Name</th>
              <th>Phone</th>
              <th>Prediction</th>
              <th>Correct?</th>
              <th>Registered</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($participants as $i => $p):
              $pLabel = $p['prediction']==='team1' ? $team1Name.' Wins'
                      : ($p['prediction']==='team2' ? $team2Name.' Wins' : 'Draw');
              $pClass = $p['prediction']==='team1' ? 'badge-green'
                      : ($p['prediction']==='team2' ? 'badge-rose' : 'badge-gold');
              $correct = $result ? ($p['prediction']===$result ? '✓' : '✗') : '—';
              $corrClass = $result ? ($p['prediction']===$result ? 'badge-green' : 'badge-red') : 'badge-grey';
              $isWinner = $winnerId && $p['id'] == $winnerId;
            ?>
            <tr <?= $isWinner ? 'style="background:rgba(57,255,20,0.07)"' : '' ?>>
              <td style="color:rgba(250,247,242,0.3)"><?= $i+1 ?><?= $isWinner ? ' 🏆' : '' ?></td>
              <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
              <td style="font-size:12px;color:rgba(250,247,242,0.5)"><?= htmlspecialchars($p['phone']) ?></td>
              <td><span class="badge <?= $pClass ?>"><?= htmlspecialchars($pLabel) ?></span></td>
              <td><span class="badge <?= $corrClass ?>"><?= $correct ?></span></td>
              <td style="font-size:11px;color:rgba(250,247,242,0.35)"><?= htmlspecialchars($p['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── LOTTERY ───────────────────────────────────────────────────────── -->
    <div class="section" id="sec-lottery">
      <h2 style="font-family:'Oswald',sans-serif;font-size:18px;color:var(--neon);margin-bottom:20px;letter-spacing:1px;">🎰 Lottery</h2>

      <div class="card lottery-wrap">
        <?php if (!$result): ?>
          <div class="lottery-pool-info">⚠️ Set the match result first to run the lottery.</div>
          <button class="btn btn-primary btn-full" disabled>Set result first</button>

        <?php elseif (empty($correctParticipants)): ?>
          <div class="lottery-pool-info">No participants predicted correctly for this match.</div>

        <?php else: ?>
          <?php $savedWinner = $winnerId ? array_values(array_filter($participants, fn($p) => $p['id'] == $winnerId)) : []; ?>
          <div class="lottery-pool-info">
            🎯 <strong><?= count($correctParticipants) ?></strong> participant<?= count($correctParticipants)>1?'s':'' ?> predicted correctly and are eligible for the draw.
          </div>

          <div class="names-reel" id="namesReel">
            <div id="reelInner"></div>
          </div>

          <button class="btn btn-gold btn-full" id="lotteryBtn" onclick="runLottery()" style="margin-bottom:16px;">
            🎰 Run Lottery Draw
          </button>

          <div class="winner-card" id="winnerCard">
            <div class="winner-trophy">🏆</div>
            <h3>WINNER!</h3>
            <div class="winner-name-big" id="winnerNameDisplay"></div>
            <div class="winner-phone" id="winnerPhoneDisplay"></div>
            <p style="color:var(--rose);margin:10px 0;font-size:13px;">💅 50€ Service</p>
            <form method="POST" action="adv741.php" id="saveWinnerForm" style="margin-top:14px;">
              <input type="hidden" name="action" value="set_winner">
              <input type="hidden" name="match_id" value="<?= $matchId ?>">
              <input type="hidden" name="winner_id" id="winnerIdInput" value="">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
              <button type="submit" class="btn btn-primary">✓ Save Winner</button>
              <button type="button" class="btn btn-ghost" onclick="runLottery()" style="margin-left:8px;">↺ Re-draw</button>
            </form>
          </div>

          <?php if ($savedWinner): ?>
          <div style="margin-top:16px;padding:14px 18px;border-radius:12px;background:rgba(57,255,20,0.07);border:1px solid rgba(57,255,20,0.2);font-size:13px;">
            ✓ Saved winner: <strong><?= htmlspecialchars($savedWinner[0]['name']) ?></strong>
            (<?= htmlspecialchars($savedWinner[0]['phone']) ?>)
          </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── MATCH SETUP ───────────────────────────────────────────────────── -->
    <div class="section" id="sec-match">
      <h2 style="font-family:'Oswald',sans-serif;font-size:18px;color:var(--neon);margin-bottom:20px;letter-spacing:1px;">⚙️ Match Configuration</h2>

      <div class="card">
        <h3>Active Match</h3>
        <form method="POST" action="adv741.php" enctype="multipart/form-data">
          <input type="hidden" name="action" value="update_match">
          <input type="hidden" name="match_id" value="<?= $matchId ?>">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
          <input type="hidden" name="team1_flag_current" value="<?= htmlspecialchars($activeMatch['team1_flag'] ?? '') ?>">
          <input type="hidden" name="team2_flag_current" value="<?= htmlspecialchars($activeMatch['team2_flag'] ?? '') ?>">

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div>
              <div class="field">
                <label>Team 1 Name</label>
                <input type="text" name="team1_name" value="<?= htmlspecialchars($activeMatch['team1_name'] ?? 'France') ?>" required>
              </div>
              <div class="field">
                <label>Team 1 Flag (image)</label>
                <?php if (!empty($activeMatch['team1_flag']) && file_exists($activeMatch['team1_flag'])): ?>
                  <img src="<?= htmlspecialchars($activeMatch['team1_flag']) ?>" alt="Flag" style="height:40px;display:block;margin-bottom:6px;border-radius:4px;">
                <?php endif; ?>
                <input type="file" name="team1_flag" accept="image/*">
              </div>
            </div>
            <div>
              <div class="field">
                <label>Team 2 Name</label>
                <input type="text" name="team2_name" value="<?= htmlspecialchars($activeMatch['team2_name'] ?? 'Sweden') ?>" required>
              </div>
              <div class="field">
                <label>Team 2 Flag (image)</label>
                <?php if (!empty($activeMatch['team2_flag']) && file_exists($activeMatch['team2_flag'])): ?>
                  <img src="<?= htmlspecialchars($activeMatch['team2_flag']) ?>" alt="Flag" style="height:40px;display:block;margin-bottom:6px;border-radius:4px;">
                <?php endif; ?>
                <input type="file" name="team2_flag" accept="image/*">
              </div>
            </div>
          </div>

          <div class="field">
            <label>Match Date / Description</label>
            <input type="text" name="match_date" value="<?= htmlspecialchars($activeMatch['match_date'] ?? '') ?>" placeholder="e.g. Saturday 5th July · 8:00 PM">
          </div>

          <button type="submit" class="btn btn-primary">✓ Save Configuration</button>
        </form>
      </div>

      <!-- All matches archive -->
      <?php if (count($matches) > 1): ?>
      <div class="card">
        <h3>📋 All Matches</h3>
        <div class="table-wrap">
          <table>
            <thead><tr><th>ID</th><th>Match</th><th>Date</th><th>Result</th><th>Active</th></tr></thead>
            <tbody>
              <?php foreach ($matches as $m): ?>
              <tr>
                <td style="color:rgba(250,247,242,0.3)"><?= $m['id'] ?></td>
                <td><?= htmlspecialchars($m['team1_name']) ?> vs <?= htmlspecialchars($m['team2_name']) ?></td>
                <td style="font-size:12px"><?= htmlspecialchars($m['match_date']) ?></td>
                <td><?= $m['result'] ? '<span class="badge badge-green">'.htmlspecialchars($m['result']).'</span>' : '<span class="badge badge-grey">TBD</span>' ?></td>
                <td><?= $m['is_active'] ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-grey">Archived</span>' ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- /main-area -->
</div><!-- /dash-wrap -->

<!-- JSON data for lottery -->
<script>
const CORRECT_PARTICIPANTS = <?= json_encode(array_map(fn($p) => ['id'=>$p['id'],'name'=>$p['name'],'phone'=>$p['phone']], $correctParticipants)) ?>;

// ── Tab navigation ────────────────────────────────────────────────────────────
function showSection(id, navEl) {
  document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById('sec-' + id).classList.add('active');
  if (navEl) navEl.classList.add('active');
}

// ── Result picker ─────────────────────────────────────────────────────────────
function pickResult(val, btn) {
  document.querySelectorAll('.result-opt').forEach(b => b.classList.remove('sel'));
  btn.classList.add('sel');
  document.getElementById('resultHidden').value = val;
  document.getElementById('saveResultBtn').disabled = false;
}

// ── Lottery animation ─────────────────────────────────────────────────────────
let lotteryRunning = false;

function runLottery() {
  if (lotteryRunning || !CORRECT_PARTICIPANTS.length) return;
  lotteryRunning = true;

  document.getElementById('winnerCard').style.display = 'none';
  const reel = document.getElementById('namesReel');
  const inner = document.getElementById('reelInner');
  reel.style.display = 'block';
  inner.innerHTML = '';

  // Pick winner
  const winner = CORRECT_PARTICIPANTS[Math.floor(Math.random() * CORRECT_PARTICIPANTS.length)];

  // Build name sequence (60 randoms + winner at end)
  const seq = [];
  for (let i = 0; i < 60; i++) {
    seq.push(CORRECT_PARTICIPANTS[Math.floor(Math.random() * CORRECT_PARTICIPANTS.length)]);
  }
  seq.push(winner);

  let idx = 0;
  let delay = 45;

  function tick() {
    inner.innerHTML = '';
    for (let j = -1; j <= 1; j++) {
      const ni = (idx + j + seq.length) % seq.length;
      const div = document.createElement('div');
      div.className = 'reel-name' + (j === 0 ? ' center' : '');
      div.textContent = seq[ni].name;
      inner.appendChild(div);
    }
    idx++;

    const progress = idx / seq.length;
    if (progress < 0.5) {
      delay = 45;
    } else {
      delay = 45 + Math.pow((progress - 0.5) * 2, 2) * 380;
    }

    if (idx < seq.length) {
      setTimeout(tick, delay);
    } else {
      inner.innerHTML = `<div class="reel-name center">${winner.name}</div>`;
      setTimeout(() => showWinner(winner), 600);
    }
  }
  tick();
}

function showWinner(winner) {
  document.getElementById('winnerNameDisplay').textContent = winner.name;
  document.getElementById('winnerPhoneDisplay').textContent = '📱 ' + winner.phone;
  document.getElementById('winnerIdInput').value = winner.id;
  document.getElementById('winnerCard').style.display = 'block';
  lotteryRunning = false;
}
</script>
<?php endif; ?>
</body>
</html>
