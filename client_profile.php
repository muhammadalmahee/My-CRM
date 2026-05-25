<?php
// ========================================================================
// client_profile.php — Systellio CRM
// Usage: client_profile.php?id=5
// ========================================================================
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// ── AJAX: Save interest change → notification ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_interest') {
    header('Content-Type: application/json');

    $intKey    = mysqli_real_escape_string($conn, trim($_POST['int_key']    ?? ''));
    $state     = mysqli_real_escape_string($conn, trim($_POST['state']      ?? ''));
    $rowType   = mysqli_real_escape_string($conn, trim($_POST['row_type']   ?? ''));
    $rowId     = (int)($_POST['row_id']    ?? 0);
    $dealName  = mysqli_real_escape_string($conn, trim($_POST['deal_name']  ?? ''));
    $campName  = mysqli_real_escape_string($conn, trim($_POST['camp_name']  ?? ''));
    $clientName= mysqli_real_escape_string($conn, trim($_POST['client_name']?? ''));
    $assignedTo= mysqli_real_escape_string($conn, trim($_POST['assigned_to']?? ''));
    $cid       = (int)($_POST['client_id'] ?? 0);

    $allowedStates = ['interested', 'maybe', 'not-interested'];
    if (!in_array($state, $allowedStates) || $rowId <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Invalid data']);
        exit();
    }

    // Ensure notifications table exists
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS notifications (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        recipient   VARCHAR(100) NOT NULL,
        sender      VARCHAR(100) NOT NULL DEFAULT 'System',
        type        VARCHAR(50)  NOT NULL DEFAULT 'interest_update',
        title       VARCHAR(255) NOT NULL,
        message     TEXT         NOT NULL,
        is_read     TINYINT(1)   NOT NULL DEFAULT 0,
        link        VARCHAR(255) DEFAULT NULL,
        created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stateLabel = ['interested' => 'Interested', 'maybe' => 'Maybe', 'not-interested' => 'Not Interested'][$state] ?? $state;
    $sender     = mysqli_real_escape_string($conn, $_SESSION['name'] ?? 'Someone');
    $subject    = $dealName ?: $campName ?: 'an item';
    $title      = mysqli_real_escape_string($conn, "Interest Update: $subject");
    $message    = mysqli_real_escape_string($conn,
        $sender . ' marked "' . $subject . '" as "' . $stateLabel . '" for client ' . $clientName . '.'
    );
    $link       = "client_profile.php?id=$cid";

    $sql = "INSERT INTO notifications (recipient, sender, type, title, message, is_read, link, created_at)
            VALUES ('$assignedTo', '$sender', 'interest_update', '$title', '$message', 0, '$link', NOW())";
    $ok = mysqli_query($conn, $sql);

    echo json_encode(['ok' => (bool)$ok, 'msg' => $ok ? 'Saved' : mysqli_error($conn)]);
    exit();
}

// ── AJAX: Get subtasks (called from task view modal) ───────────────────
if (isset($_GET['get_subtasks']) && isset($_GET['task_id'])) {
    header('Content-Type: application/json');
    $tid  = (int)$_GET['task_id'];
    $res  = mysqli_query($conn, "SELECT id, title, is_done FROM subtasks WHERE task_id=$tid ORDER BY id ASC");
    $rows = [];
    if ($res) { while ($r = mysqli_fetch_assoc($res)) $rows[] = $r; }
    echo json_encode($rows);
    exit();
}

$client_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($client_id <= 0) { header("Location: client_list.php"); exit(); }

// ── Toast vars ─────────────────────────────────────────────────────────
$toastMessage = "";
$toastType    = "";

// ── Handle: Submit Note ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_note'])) {
    $note_text = mysqli_real_escape_string($conn, trim($_POST['note_text'] ?? ''));
    $author    = mysqli_real_escape_string($conn, $_SESSION['name']);
    if ($note_text !== '') {
        // Ensure table + columns exist before inserting
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS client_notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            author VARCHAR(100),
            note TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $_ec = []; $_cr = mysqli_query($conn, "SHOW COLUMNS FROM client_notes");
        if ($_cr) { while ($_c = mysqli_fetch_assoc($_cr)) $_ec[] = $_c['Field']; }
        if (!in_array('client_id',  $_ec)) mysqli_query($conn, "ALTER TABLE client_notes ADD COLUMN client_id INT NOT NULL DEFAULT 0 AFTER id");
        if (!in_array('author',     $_ec)) mysqli_query($conn, "ALTER TABLE client_notes ADD COLUMN author VARCHAR(100) AFTER client_id");
        if (!in_array('note',       $_ec)) mysqli_query($conn, "ALTER TABLE client_notes ADD COLUMN note TEXT NOT NULL AFTER author");
        if (!in_array('created_at', $_ec)) mysqli_query($conn, "ALTER TABLE client_notes ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER note");

        $note_sql = "INSERT INTO client_notes (client_id, author, note, created_at)
                     VALUES ($client_id, '$author', '$note_text', NOW())";
        if (mysqli_query($conn, $note_sql)) {
            $toastMessage = "Note saved successfully!";
            $toastType    = "success";
        } else {
            $toastMessage = "Error saving note: " . mysqli_error($conn);
            $toastType    = "error";
        }
    } else {
        $toastMessage = "Note cannot be empty.";
        $toastType    = "error";
    }
    header("Location: client_profile.php?id=$client_id");
    exit();
}

// ── Ensure social columns exist in contacts ───────────────────────────
$_sc = []; $_scr = mysqli_query($conn, "SHOW COLUMNS FROM contacts");
if ($_scr) { while ($_c = mysqli_fetch_assoc($_scr)) $_sc[] = $_c['Field']; }
foreach (['fb_url','linkedin_url','twitter_url','insta_url'] as $_col) {
    if (!in_array($_col, $_sc))
        mysqli_query($conn, "ALTER TABLE contacts ADD COLUMN {$_col} VARCHAR(255) DEFAULT NULL");
}

// ── Fetch client + company ──────────────────────────────────────────────
$client = null;
$company = null;
$q = mysqli_query($conn, "SELECT c.*, co.company_name, co.company_email, co.company_number,
                                  co.company_website, co.assigned_agent,
                                  c.fb_url, c.linkedin_url,
                                  c.insta_url, c.twitter_url
                           FROM contacts c
                           LEFT JOIN companies co ON c.company_id = co.id
                           WHERE c.id = $client_id LIMIT 1");
if ($q && mysqli_num_rows($q) > 0) {
    $client = mysqli_fetch_assoc($q);
} else {
    header("Location: client_list.php");
    exit();
}

// ── Fetch notes ──────────────────────────────────────────────────────────
$notes = [];
$note_count = 0;

// 1) Create table if it does not exist
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS client_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    author VARCHAR(100),
    note TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 2) If table existed with wrong/missing columns, patch them safely
$_existing_cols = [];
$_col_res = mysqli_query($conn, "SHOW COLUMNS FROM client_notes");
if ($_col_res) {
    while ($_col = mysqli_fetch_assoc($_col_res)) $_existing_cols[] = $_col['Field'];
}
if (!in_array('client_id',  $_existing_cols)) mysqli_query($conn, "ALTER TABLE client_notes ADD COLUMN client_id INT NOT NULL DEFAULT 0 AFTER id");
if (!in_array('author',     $_existing_cols)) mysqli_query($conn, "ALTER TABLE client_notes ADD COLUMN author VARCHAR(100) AFTER client_id");
if (!in_array('note',       $_existing_cols)) mysqli_query($conn, "ALTER TABLE client_notes ADD COLUMN note TEXT NOT NULL AFTER author");
if (!in_array('created_at', $_existing_cols)) mysqli_query($conn, "ALTER TABLE client_notes ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER note");

// 3) Now safely fetch notes
$nq = mysqli_query($conn, "SELECT * FROM client_notes WHERE client_id=$client_id ORDER BY created_at DESC");
if ($nq) {
    $note_count = mysqli_num_rows($nq);
    while ($row = mysqli_fetch_assoc($nq)) $notes[] = $row;
}

// ── Fetch linked tasks ───────────────────────────────────────────────────
// Ensure client_ids column exists (added dynamically by task_manager.php)
@mysqli_query($conn, "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS client_ids TEXT DEFAULT NULL");

$tasks = [];
$task_count = 0;
$tq = mysqli_query($conn,
    "SELECT * FROM tasks
     WHERE client_ids IS NOT NULL
       AND client_ids != ''
       AND FIND_IN_SET('$client_id', REPLACE(client_ids, ' ', '')) > 0
     ORDER BY due_date ASC"
);
if ($tq) {
    $task_count = mysqli_num_rows($tq);
    while ($row = mysqli_fetch_assoc($tq)) $tasks[] = $row;
}

// ── Fetch linked deals (by company name OR via campaign's deal_id) ──────
$deals_linked = [];
$deal_count   = 0;

$co_esc = !empty($client['company_name'])
    ? mysqli_real_escape_string($conn, $client['company_name'])
    : '';

// Step 1: deals directly linked by company name
$direct_deal_ids = [];
if ($co_esc !== '') {
    $dq = mysqli_query($conn,
        "SELECT * FROM deals WHERE link_company = '$co_esc' ORDER BY created_at DESC"
    );
    if ($dq) {
        while ($row = mysqli_fetch_assoc($dq)) {
            $deals_linked[$row['id']] = $row;
            $direct_deal_ids[] = $row['id'];
        }
    }
}

// Step 2: deals linked via campaigns that have this client assigned
@mysqli_query($conn, "ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS assigned_clients TEXT DEFAULT NULL");
$camp_deal_q = mysqli_query($conn,
    "SELECT DISTINCT deal_id FROM campaigns
     WHERE deal_id IS NOT NULL
       AND FIND_IN_SET('$client_id', REPLACE(assigned_clients, ' ', '')) > 0"
);
if ($camp_deal_q) {
    $extra_ids = [];
    while ($r = mysqli_fetch_assoc($camp_deal_q)) {
        $did = (int)$r['deal_id'];
        if ($did > 0 && !isset($deals_linked[$did])) $extra_ids[] = $did;
    }
    if (!empty($extra_ids)) {
        $extra_sql = implode(',', $extra_ids);
        $eq = mysqli_query($conn, "SELECT * FROM deals WHERE id IN ($extra_sql) ORDER BY created_at DESC");
        if ($eq) {
            while ($row = mysqli_fetch_assoc($eq)) $deals_linked[$row['id']] = $row;
        }
    }
}

// Re-index as a plain array and sort by created_at DESC
usort($deals_linked, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
$deal_count = count($deals_linked);

// ── Fetch linked campaigns (by deal_id OR directly assigned_clients) ──────
$campaigns_linked = [];
$campaign_count   = 0;

$deal_ids_arr = array_column($deals_linked, 'id');
$deal_ids_sql = !empty($deal_ids_arr)
    ? implode(',', array_map('intval', $deal_ids_arr))
    : '0';

$cq = mysqli_query($conn,
    "SELECT c.*, d.deal_name FROM campaigns c
     LEFT JOIN deals d ON c.deal_id = d.id
     WHERE c.deal_id IN ($deal_ids_sql)
        OR FIND_IN_SET('$client_id', REPLACE(c.assigned_clients, ' ', '')) > 0
     ORDER BY c.created_at DESC"
);
if ($cq) {
    $campaign_count = mysqli_num_rows($cq);
    while ($row = mysqli_fetch_assoc($cq)) $campaigns_linked[] = $row;
}

// ── Helpers ───────────────────────────────────────────────────────────────
if (!function_exists('h'))    { function h($v)    { return htmlspecialchars($v ?? ''); } }
if (!function_exists('orNA')) { function orNA($v) { return (!empty($v)) ? htmlspecialchars($v) : 'N/A'; } }

$avatar_letter = strtoupper(substr($client['name'], 0, 1));
$company_name  = !empty($client['company_name']) ? $client['company_name'] : 'Independent';
$assigned_agent = !empty($client['assigned_agent']) ? $client['assigned_agent'] : 'Unassigned';

// Last contacted = latest note date
$last_contacted = 'N/A';
if (!empty($notes)) {
    $last_contacted = date('d M, Y', strtotime($notes[0]['created_at']));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($client['name']) ?> — Client Profile | Systellio CRM</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Inter',sans-serif; }

        /* ── Toast ─────────────────────────────────────────── */
        #toastBox { visibility:hidden; min-width:250px; background:#333; color:#fff; text-align:center;
            border-radius:8px; padding:16px; position:fixed; z-index:9999; right:30px; top:30px;
            font-size:14px; font-weight:600; box-shadow:0 4px 12px rgba(0,0,0,.15);
            display:flex; align-items:center; gap:10px;
            transform:translateX(120%); transition:transform .4s cubic-bezier(.68,-.55,.265,1.55), visibility .4s; }
        #toastBox.show { visibility:visible; transform:translateX(0); }
        #toastBox.success { background:#10b981; }
        #toastBox.error   { background:#ef4444; }

        /* ── Layout ─────────────────────────────────────────── */
        body { background:#f3f4f6; display:flex; height:100vh; overflow:hidden; color:#111827; transition:.3s; }
        .main-content { flex-grow:1; display:flex; flex-direction:column; overflow-y:auto; background:#f3f4f6; transition:.3s; }

        /* ── Navbar ─────────────────────────────────────────── */
        
        
        .breadcrumb a { color:#6b7280; text-decoration:none; transition:.2s; }
        .breadcrumb a:hover { color:#3b82f6; }
        .breadcrumb .current { color:#3b82f6; font-weight:700; }
        .breadcrumb .sep { color:#d1d5db; }
        
        
        
        
        .nav-icon-btn:hover { color:#3b82f6; }
        
        .user-profile i { font-size:24px; color:#3b82f6; }
        .back-btn { background:#0f172a; color:#fff; padding:9px 20px; border-radius:8px;
            font-size:13px; font-weight:700; text-decoration:none; display:flex; align-items:center;
            gap:8px; transition:.25s; }
        .back-btn:hover { background:#1e293b; transform:translateY(-1px); }

        /* ── Page wrapper ──────────────────────────────────── */
        .profile-page { padding:28px; display:flex; flex-direction:column; gap:22px; }

        /* ── Action Bar ─────────────────────────────────────── */
        .action-bar {
            display:flex; align-items:center; gap:12px; padding:0 0 14px 0;
        }
        .btn-back {
            background:#0f172a; color:#fff; padding:9px 18px; border-radius:8px;
            font-size:13px; font-weight:700; text-decoration:none;
            display:inline-flex; align-items:center; gap:8px; transition:.2s;
            border:none; cursor:pointer;
        }
        .btn-back:hover { background:#1e293b; transform:translateY(-1px); }
        .spacer { flex-grow:1; }
        body.dark-mode .btn-back { background:#1e293b; }
        body.dark-mode .btn-back:hover { background:#334155; }

        /* ── Hero Banner ────────────────────────────────────── */
        .hero-banner {
            background: linear-gradient(135deg, #3b3fa5 0%, #5c5fe8 45%, #7c5ce8 100%);
            border-radius:14px; padding:28px 32px;
            display:flex; flex-direction:column; gap:18px;
            position:relative; overflow:hidden; color:#fff;
            box-shadow:0 8px 30px rgba(91,92,232,.3);
        }
        .hero-banner::before {
            content:''; position:absolute; right:-40px; top:-40px;
            width:260px; height:260px;
            background:rgba(255,255,255,.06); border-radius:50%;
        }
        .hero-banner::after {
            content:''; position:absolute; right:80px; bottom:-60px;
            width:180px; height:180px;
            background:rgba(255,255,255,.04); border-radius:50%;
        }
        /* Top row: avatar + name/tags */
        .hero-top-row { display:flex; align-items:center; gap:20px; z-index:1; }
        .hero-top-row .hero-info { flex:1; }

        /* Mini stat chips in banner */
        .hero-stats { display:flex; gap:10px; flex-shrink:0; z-index:1; }
        .hero-stat-chip {
            background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.25);
            border-radius:12px; padding:10px 16px;
            display:flex; align-items:center; gap:10px;
            backdrop-filter:blur(6px); min-width:90px;
        }
        .hero-stat-chip i { font-size:18px; color:rgba(255,255,255,.85); }
        .hero-stat-text { display:flex; flex-direction:column; line-height:1.2; }
        .hero-stat-num { font-size:20px; font-weight:800; color:#fff; }
        .hero-stat-label { font-size:10px; font-weight:600; color:rgba(255,255,255,.7);
            text-transform:uppercase; letter-spacing:.5px; }
        .avatar-circle {
            width:68px; height:68px; background:rgba(255,255,255,.22);
            border-radius:14px; display:flex; align-items:center; justify-content:center;
            font-size:28px; font-weight:800; color:#fff;
            box-shadow:0 4px 15px rgba(0,0,0,.2); flex-shrink:0;
        }
        .hero-info h2 { font-size:24px; font-weight:800; margin-bottom:8px; }
        .hero-tags { display:flex; gap:8px; flex-wrap:wrap; }
        .tag-badge { font-size:11px; font-weight:700; padding:4px 12px; border-radius:20px;
            background:rgba(255,255,255,.18); backdrop-filter:blur(4px); letter-spacing:.5px; }
        /* Bottom row: contact buttons + social icons */
        .hero-bottom-row {
            display:flex; align-items:center; justify-content:space-between;
            flex-wrap:wrap; gap:12px; z-index:1;
        }
        .hero-contact-btns { display:flex; gap:10px; flex-wrap:wrap; }
        .hero-contact-btn {
            background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.25);
            color:#fff; padding:8px 16px; border-radius:8px; font-size:12px; font-weight:600;
            display:flex; align-items:center; gap:8px; backdrop-filter:blur(4px);
            transition:.25s; text-decoration:none; cursor:default;
        }
        .hero-contact-btn i { font-size:13px; }
        .hero-social-row { display:flex; gap:10px; align-items:center; z-index:1; }
        .social-circle {
            width:36px; height:36px; background:rgba(255,255,255,.15);
            border:1px solid rgba(255,255,255,.25); border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            font-size:14px; color:#fff; cursor:pointer;
            transition:.25s; text-decoration:none;
        }
        .social-circle:hover { background:rgba(255,255,255,.3); transform:translateY(-2px); }

        /* ── Cards grid ─────────────────────────────────────── */
        .cards-row { display:grid; grid-template-columns:1fr 2fr; gap:22px; }
        .card { background:#fff; border-radius:14px; border:1px solid #e5e7eb;
            box-shadow:0 2px 12px rgba(0,0,0,.04); overflow:hidden; }
        .card-header { padding:18px 22px 14px; display:flex; align-items:center;
            justify-content:space-between; border-bottom:1px solid #f3f4f6; }
        .card-header h3 { font-size:15px; font-weight:800; color:#111827;
            display:flex; align-items:center; gap:10px; }
        .card-header h3 i { color:#3b82f6; font-size:15px; }
        .badge-count { background:#3b82f6; color:#fff; font-size:11px;
            font-weight:700; padding:3px 10px; border-radius:20px; }
        .card-body { padding:20px 22px; }

        /* ── Key Contact ────────────────────────────────────── */
        .contact-row { display:flex; justify-content:space-between; align-items:center;
            padding:11px 0; border-bottom:1px solid #f9fafb; }
        .contact-row:last-child { border-bottom:none; }
        .contact-label { font-size:11px; font-weight:700; color:#9ca3af;
            text-transform:uppercase; letter-spacing:.6px; }
        .contact-val { font-size:13px; font-weight:600; color:#111827; text-align:right; }
        .contact-val.blue { color:#3b82f6; }
        .designation-tag { background:#f3f4f6; border:1px solid #e5e7eb; color:#374151;
            font-size:11px; font-weight:700; padding:3px 10px; border-radius:6px; }
        .agent-chip { display:flex; align-items:center; gap:6px; }
        .agent-chip i { color:#3b82f6; }
        .date-chip { display:flex; align-items:center; gap:6px; color:#059669; }
        .date-chip i { color:#059669; }

        /* Social row inside card */
        .social-row { display:flex; gap:8px; }
        .soc-btn { width:32px; height:32px; border-radius:8px; background:#f3f4f6;
            display:flex; align-items:center; justify-content:center;
            color:#374151; font-size:14px; text-decoration:none; transition:.2s; }
        .soc-btn:hover { background:#dbeafe; color:#3b82f6; }

        /* WhatsApp button */
        .wa-btn { margin-top:16px; width:100%; background:#25D366; color:#fff;
            border:none; padding:12px; border-radius:10px; font-size:14px;
            font-weight:700; cursor:pointer; display:flex; align-items:center;
            justify-content:center; gap:10px; transition:.25s; text-decoration:none; }
        .wa-btn:hover { background:#1ebe5d; transform:translateY(-1px); }

        /* ── Notes ──────────────────────────────────────────── */
        .note-label { font-size:11px; font-weight:700; color:#6b7280;
            text-transform:uppercase; letter-spacing:.6px; margin-bottom:10px; }
        .note-textarea { width:100%; min-height:110px; border:1px solid #e5e7eb;
            border-radius:10px; padding:14px 16px; font-size:14px; color:#374151;
            background:#f9fafb; outline:none; resize:vertical; transition:.25s;
            font-family:'Inter',sans-serif; }
        .note-textarea:focus { border-color:#3b82f6; background:#fff;
            box-shadow:0 0 0 3px rgba(59,130,246,.1); }
        .submit-note-btn { margin-top:10px; float:right; background:#3b82f6; color:#fff;
            border:none; padding:10px 22px; border-radius:8px; font-size:13px;
            font-weight:700; cursor:pointer; display:flex; align-items:center;
            gap:8px; transition:.25s; }
        .submit-note-btn:hover { background:#2563eb; }
        .notes-divider { clear:both; margin-top:20px; padding-top:18px;
            border-top:1px solid #f3f4f6; }
        .history-label { font-size:11px; font-weight:700; color:#6b7280;
            text-transform:uppercase; letter-spacing:.6px; margin-bottom:12px; }
        .note-item { background:#f9fafb; border:1px solid #f3f4f6;
            border-radius:10px; padding:14px 16px; margin-bottom:10px; }
        .note-item-header { display:flex; justify-content:space-between;
            align-items:center; margin-bottom:8px; }
        .note-author { font-size:12px; font-weight:700; color:#3b82f6; }
        .note-date { font-size:11px; color:#9ca3af; }
        .note-text { font-size:13px; color:#374151; line-height:1.6; }
        .empty-state { display:flex; flex-direction:column; align-items:center;
            justify-content:center; padding:40px 20px; color:#9ca3af; }
        .empty-state i { font-size:36px; margin-bottom:12px; color:#d1d5db; }
        .empty-state p { font-size:13px; font-weight:500; }
        .empty-state small { font-size:12px; margin-top:4px; }

        /* ── Deals & Campaigns row ───────────────────────────── */
        .deals-campaigns-row { display:grid; grid-template-columns:1fr 1fr; gap:22px; }
        .deal-pill-stage { font-size:10px; font-weight:700; padding:2px 9px;
            border-radius:20px; display:inline-block; }
        .deal-pill-stage.lead       { background:#fef3c7; color:#92400e; }
        .deal-pill-stage.proposal   { background:#dbeafe; color:#1d4ed8; }
        .deal-pill-stage.negotiation{ background:#ede9fe; color:#6d28d9; }
        .deal-pill-stage.closed     { background:#d1fae5; color:#065f46; }
        .deal-pill-stage.lost       { background:#fee2e2; color:#991b1b; }
        .camp-pill-status { font-size:10px; font-weight:700; padding:2px 9px;
            border-radius:20px; display:inline-block; }
        .camp-pill-status.planning  { background:#fef3c7; color:#92400e; }
        .camp-pill-status.active    { background:#d1fae5; color:#065f46; }
        .camp-pill-status.completed { background:#dbeafe; color:#1d4ed8; }
        .camp-pill-status.on-hold   { background:#f3f4f6; color:#374151; }
        body.dark-mode .deal-pill-stage.lead       { background:#451a03; color:#fbbf24; }
        body.dark-mode .deal-pill-stage.proposal   { background:#1e3a8a; color:#93c5fd; }
        body.dark-mode .deal-pill-stage.negotiation{ background:#2e1065; color:#c4b5fd; }
        body.dark-mode .deal-pill-stage.closed     { background:#064e3b; color:#6ee7b7; }
        body.dark-mode .deal-pill-stage.lost       { background:#450a0a; color:#fca5a5; }
        body.dark-mode .camp-pill-status.planning  { background:#451a03; color:#fbbf24; }
        body.dark-mode .camp-pill-status.active    { background:#064e3b; color:#6ee7b7; }
        body.dark-mode .camp-pill-status.completed { background:#1e3a8a; color:#93c5fd; }
        body.dark-mode .camp-pill-status.on-hold   { background:#1e293b; color:#94a3b8; }

        /* ── Bottom row ──────────────────────────────────────── */
        .bottom-row { display:grid; grid-template-columns:1fr 2fr; gap:22px; }

        /* ── System Summary ──────────────────────────────────── */
        .system-summary-text { font-size:13px; color:#374151; line-height:1.7; }
        .system-summary-text strong { color:#111827; }

        /* ── Tasks ───────────────────────────────────────────── */
        .tasks-table { width:100%; border-collapse:collapse; }
        .tasks-table th { font-size:11px; font-weight:700; color:#6b7280;
            text-transform:uppercase; letter-spacing:.6px; padding:10px 12px;
            border-bottom:1px solid #f3f4f6; text-align:left; }
        .tasks-table td { padding:12px 12px; border-bottom:1px solid #f9fafb;
            font-size:13px; color:#374151; }
        .tasks-table tr:last-child td { border-bottom:none; }
        .status-pill { font-size:11px; font-weight:700; padding:3px 10px;
            border-radius:20px; display:inline-block; }
        .status-pill.todo      { background:#fef3c7; color:#92400e; }
        .status-pill.inprog    { background:#dbeafe; color:#1d4ed8; }
        .status-pill.done      { background:#d1fae5; color:#065f46; }
        .status-pill.onhold    { background:#f3f4f6; color:#374151; }

        /* ── Dark mode ───────────────────────────────────────── */
        body.dark-mode { background:#0f172a; color:#f8fafc; }
        body.dark-mode .main-content { background:#0f172a; }
        body.dark-mode 
        body.dark-mode .breadcrumb, body.dark-mode .breadcrumb a { color:#94a3b8; }
        body.dark-mode .card { background:#1e293b; border-color:#334155; }
        body.dark-mode .card-header { border-color:#334155; }
        body.dark-mode .card-header h3 { color:#f8fafc; }
        body.dark-mode .contact-row { border-color:#334155; }
        body.dark-mode .contact-val { color:#f8fafc; }
        body.dark-mode .designation-tag { background:#334155; border-color:#475569; color:#f8fafc; }
        body.dark-mode .soc-btn { background:#334155; color:#94a3b8; }
        body.dark-mode .soc-btn:hover { background:#1e40af; color:#fff; }
        body.dark-mode .note-textarea { background:#0f172a; border-color:#334155; color:#f8fafc; }
        body.dark-mode .note-textarea:focus { background:#1e293b; border-color:#3b82f6; }
        body.dark-mode .note-item { background:#0f172a; border-color:#334155; }
        body.dark-mode .note-text { color:#cbd5e1; }
        body.dark-mode .tasks-table th { color:#94a3b8; border-color:#334155; }
        body.dark-mode .tasks-table td { color:#cbd5e1; border-color:#334155; }
        body.dark-mode .system-summary-text { color:#cbd5e1; }
        body.dark-mode .system-summary-text strong { color:#f8fafc; }
        body.dark-mode 
    </style>
</head>
<body>

<div id="toastBox">
    <i id="toastIcon" class="fa-solid fa-circle-check"></i>
    <span id="toastMsg">Action Successful!</span>
</div>

<?php
    $activePage    = 'client_list';
    $sidebarRole   = ucfirst(str_replace('_',' ',$_SESSION['role']));
    $dashboardFile = match($_SESSION['role']) {
        'super_admin' => 'super_admin_dashboard.php',
        'admin'       => 'admin_dashboard.php',
        'manager'     => 'manager_dashboard.php',
        'agent'       => 'agent_dashboard.php',
        default       => 'index.php',
    };
    include 'sidebar.php';
?>

<div class="main-content">

    <!-- Navbar -->
    <?php include 'topbar.php'; ?>

    <div class="profile-page">

        <!-- ACTION BAR -->
        <div class="action-bar">
            <a href="client_list.php" class="btn-back">
                <i class="fa-solid fa-arrow-left"></i> Back to Clients
            </a>
            <div class="spacer"></div>
        </div>

        <!-- ── Hero Banner ──────────────────────────────────── -->
        <div class="hero-banner">
            <!-- Top row: avatar + name + tags -->
            <div class="hero-top-row">
                <div class="avatar-circle"><?= $avatar_letter ?></div>
                <div class="hero-info">
                    <h2><?= h($client['name']) ?></h2>
                    <div class="hero-tags">
                        <span class="tag-badge"><i class="fa-solid fa-building" style="font-size:10px;margin-right:5px;"></i><?= h($company_name) ?></span>
                        <span class="tag-badge"><i class="fa-solid fa-id-badge" style="font-size:10px;margin-right:4px;"></i>ID: #<?= str_pad($client_id, 4, '0', STR_PAD_LEFT) ?></span>
                    </div>
                </div>
                <!-- Deal & Campaign & Task mini stats -->
                <div class="hero-stats">
                    <div class="hero-stat-chip">
                        <i class="fa-solid fa-handshake"></i>
                        <div class="hero-stat-text">
                            <span class="hero-stat-num"><?= $deal_count ?></span>
                            <span class="hero-stat-label">Deal<?= $deal_count != 1 ? 's' : '' ?></span>
                        </div>
                    </div>
                    <div class="hero-stat-chip">
                        <i class="fa-solid fa-bullhorn"></i>
                        <div class="hero-stat-text">
                            <span class="hero-stat-num"><?= $campaign_count ?></span>
                            <span class="hero-stat-label">Campaign<?= $campaign_count != 1 ? 's' : '' ?></span>
                        </div>
                    </div>
                    <div class="hero-stat-chip">
                        <i class="fa-solid fa-list-check"></i>
                        <div class="hero-stat-text">
                            <span class="hero-stat-num"><?= $task_count ?></span>
                            <span class="hero-stat-label">Task<?= $task_count != 1 ? 's' : '' ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Bottom row: contact buttons + social icons -->
            <div class="hero-bottom-row">
                <div class="hero-contact-btns">
                    <div class="hero-contact-btn">
                        <i class="fa-solid fa-phone"></i>
                        <?= !empty($client['phone']) ? h($client['phone']) : 'N/A' ?>
                    </div>
                    <div class="hero-contact-btn">
                        <i class="fa-solid fa-envelope"></i>
                        <?= !empty($client['email']) ? h($client['email']) : 'N/A' ?>
                    </div>
                </div>
                <div class="hero-social-row">
                    <?php
                    $socials = [
                        ['fa', 'fa-brands fa-facebook-f', $client['fb_url']      ?? ''],
                        ['fa', 'fa-brands fa-linkedin-in', $client['linkedin_url'] ?? ''],
                        ['svg', '', $client['twitter_url'] ?? ''],
                        ['fa', 'fa-brands fa-instagram', $client['insta_url']    ?? ''],
                    ];
                    foreach ($socials as $s):
                        $href   = !empty($s[2]) ? $s[2] : '#';
                        $target = !empty($s[2]) ? 'target="_blank"' : '';
                        $icon   = $s[0] === 'svg'
                            ? '<svg width="13" height="13" viewBox="0 0 1200 1227" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M714.163 519.284L1160.89 0H1055.03L667.137 450.887L357.328 0H0L468.492 681.821L0 1226.37H105.866L515.491 750.218L842.672 1226.37H1200L714.163 519.284ZM569.165 687.828L521.697 619.934L144.011 79.6944H306.615L611.412 515.685L658.88 583.579L1055.08 1150.3H892.476L569.165 687.828Z" fill="currentColor"/></svg>'
                            : '<i class="' . $s[1] . '"></i>';
                    ?>
                    <a href="<?= $href ?>" <?= $target ?> class="social-circle"><?= $icon ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ── Top Cards Row ─────────────────────────────────── -->
        <div class="cards-row">

            <!-- Key Contact Person -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fa-solid fa-user"></i> Key Contact Person</h3>
                </div>
                <div class="card-body">
                    <div class="contact-row">
                        <span class="contact-label">Full Name</span>
                        <span class="contact-val blue"><?= h($client['name']) ?></span>
                    </div>
                    <div class="contact-row">
                        <span class="contact-label">Designation</span>
                        <span class="contact-val">
                            <?php if(!empty($client['designation'])): ?>
                                <span class="designation-tag"><?= h($client['designation']) ?></span>
                            <?php else: ?>
                                <span class="designation-tag">N/A</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="contact-row">
                        <span class="contact-label">Direct Phone</span>
                        <span class="contact-val"><?= orNA($client['phone']) ?></span>
                    </div>
                    <div class="contact-row">
                        <span class="contact-label">Direct Email</span>
                        <span class="contact-val"><?= orNA($client['email']) ?></span>
                    </div>
                    <div class="contact-row">
                        <span class="contact-label">Assigned Agent(s)</span>
                        <span class="contact-val">
                            <span class="agent-chip"><i class="fa-solid fa-user-group"></i><?= h($assigned_agent) ?></span>
                        </span>
                    </div>
                    <div class="contact-row">
                        <span class="contact-label">Last Contacted</span>
                        <span class="contact-val">
                            <span class="date-chip"><i class="fa-regular fa-calendar-check"></i><?= $last_contacted ?></span>
                        </span>
                    </div>
                    <div class="contact-row">
                        <span class="contact-label">Personal Socials</span>
                        <span class="contact-val">
                            <div class="social-row">
                                <?php foreach ($socials as $s):
                                    $href = !empty($s[2]) ? $s[2] : '#';
                                    $target = !empty($s[2]) ? 'target="_blank"' : '';
                                    $icon = $s[0] === 'svg'
                                        ? '<svg width="13" height="13" viewBox="0 0 1200 1227" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M714.163 519.284L1160.89 0H1055.03L667.137 450.887L357.328 0H0L468.492 681.821L0 1226.37H105.866L515.491 750.218L842.672 1226.37H1200L714.163 519.284ZM569.165 687.828L521.697 619.934L144.011 79.6944H306.615L611.412 515.685L658.88 583.579L1055.08 1150.3H892.476L569.165 687.828Z" fill="currentColor"/></svg>'
                                        : '<i class="' . $s[1] . '"></i>';
                                ?>
                                <a href="<?= $href ?>" <?= $target ?> class="soc-btn"><?= $icon ?></a>
                                <?php endforeach; ?>
                            </div>
                        </span>
                    </div>

                    <!-- WhatsApp button -->
                    <?php
                    $wa_phone = preg_replace('/[^0-9]/', '', $client['phone'] ?? '');
                    $wa_href = !empty($wa_phone) ? "https://wa.me/$wa_phone" : '#';
                    ?>
                    <a href="<?= $wa_href ?>" target="_blank" class="wa-btn">
                        <i class="fa-brands fa-whatsapp" style="font-size:18px;"></i> Open WhatsApp Chat
                    </a>
                </div>
            </div>

            <!-- Conversation Notes & Logs -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fa-solid fa-comments"></i> Conversation Notes &amp; Logs</h3>
                    <span class="badge-count"><?= $note_count ?> Notes</span>
                </div>
                <div class="card-body">
                    <!-- Submit new note -->
                    <form method="POST" action="client_profile.php?id=<?= $client_id ?>">
                        <p class="note-label">Log New Interaction</p>
                        <textarea class="note-textarea" name="note_text"
                            placeholder="Type your meeting summary, call details or updates here..."></textarea>
                        <button type="submit" name="submit_note" class="submit-note-btn">
                            <i class="fa-solid fa-paper-plane"></i> Submit Note
                        </button>
                    </form>

                    <!-- History -->
                    <div class="notes-divider">
                        <p class="history-label">History Log</p>
                        <?php if (empty($notes)): ?>
                        <div class="empty-state">
                            <i class="fa-regular fa-folder-open"></i>
                            <p>No conversation history found.</p>
                            <small>Be the first to log an interaction!</small>
                        </div>
                        <?php else: ?>
                            <?php foreach ($notes as $note): ?>
                            <div class="note-item">
                                <div class="note-item-header">
                                    <span class="note-author"><i class="fa-solid fa-user-pen"></i> <?= h($note['author']) ?></span>
                                    <span class="note-date"><?= date('d M Y, h:i A', strtotime($note['created_at'])) ?></span>
                                </div>
                                <p class="note-text"><?= nl2br(h($note['note'])) ?></p>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div><!-- /cards-row -->

        <!-- ── Linked Deals & Campaigns (Combined Table) ─────── -->
        <div class="card" style="margin-bottom:0;">
            <div class="card-header">
                <h3><i class="fa-solid fa-handshake"></i> Linked Deals &amp; Campaigns</h3>
                <div style="display:flex;gap:8px;align-items:center;">
                    <span class="badge-count" style="background:#7c3aed;"><?= $deal_count ?> Deal<?= $deal_count != 1 ? 's' : '' ?></span>
                    <span class="badge-count" style="background:#0891b2;"><?= $campaign_count ?> Campaign<?= $campaign_count != 1 ? 's' : '' ?></span>
                </div>
            </div>
            <div class="card-body" style="padding:0;">

<?php
/* ── Build combined rows ── */
/* Each row: type, campaign_name, deal_name, deal_value, link_company,
             service_required, platform, end_date, row_id, currency, stage/status */
$combined_rows = [];

/* Deals rows */
foreach ($deals_linked as $deal) {
    /* Find the first campaign linked to this deal, if any */
    $camp_name_for_deal = '—';
    foreach ($campaigns_linked as $c) {
        if (!empty($c['deal_id']) && (int)$c['deal_id'] === (int)$deal['id']) {
            $camp_name_for_deal = $c['campaign_name'];
            break;
        }
    }
    $combined_rows[] = [
        'type'             => 'deal',
        'campaign_name'    => $camp_name_for_deal,
        'deal_name'        => $deal['deal_name']        ?? '—',
        'deal_value'       => $deal['deal_value']       ?? 0,
        'currency'         => $deal['currency']         ?? 'USD',
        'link_company'     => $deal['link_company']     ?? '—',
        'service_required' => $deal['service_required'] ?? '—',
        'platform'         => $deal['platform']         ?? '—',
        'end_date'         => $deal['end_date']         ?? '',
        'stage'            => $deal['stage']            ?? 'Lead',
        'row_id'           => $deal['id'],
        'assigned_to'      => $deal['sales_officer']    ?? '',
        'raw_deal'         => $deal,
        'raw_camp'         => null,
    ];
}

/* Campaigns that are NOT already covered by a deal row above */
$covered_deal_ids = array_column($deals_linked, 'id');
foreach ($campaigns_linked as $camp) {
    if (!empty($camp['deal_id']) && in_array((int)$camp['deal_id'], $covered_deal_ids)) {
        continue; /* already shown via deal row */
    }
    $combined_rows[] = [
        'type'             => 'campaign',
        'campaign_name'    => $camp['campaign_name']   ?? '—',
        'deal_name'        => $camp['deal_name']        ?? '—',
        'deal_value'       => 0,
        'currency'         => $camp['currency']         ?? 'USD',
        'link_company'     => '—',
        'service_required' => '—',
        'platform'         => '—',
        'end_date'         => $camp['end_date']         ?? '',
        'stage'            => $camp['status']           ?? 'Planning',
        'row_id'           => $camp['id'],
        'assigned_to'      => $camp['assigned_to']      ?? '',
        'raw_deal'         => null,
        'raw_camp'         => $camp,
    ];
}
?>

                <?php if (empty($combined_rows)): ?>
                <div class="empty-state" style="padding:30px 20px;">
                    <i class="fa-solid fa-handshake" style="font-size:28px;margin-bottom:10px;color:#d1d5db;"></i>
                    <p style="font-size:13px;color:#9ca3af;">No deals or campaigns linked yet.</p>
                </div>
                <?php else: ?>

                <style>
                /* ── Combined D&C Table ── */
                .dc-table { width:100%; border-collapse:collapse; }
                .dc-table th {
                    font-size:11px; font-weight:700; color:#6b7280;
                    text-transform:uppercase; letter-spacing:.55px;
                    padding:11px 13px; border-bottom:1px solid #f3f4f6;
                    text-align:left; white-space:nowrap;
                }
                .dc-table td {
                    padding:12px 13px; border-bottom:1px solid #f9fafb;
                    font-size:13px; color:#374151; vertical-align:middle;
                }
                .dc-table tbody tr:last-child td { border-bottom:none; }
                .dc-table tbody tr:hover { background:#f9fafb; }

                /* row-type badge */
                .row-type-badge {
                    font-size:10px; font-weight:700; padding:2px 8px;
                    border-radius:20px; text-transform:uppercase; letter-spacing:.4px;
                }
                .row-type-badge.deal     { background:#ede9fe; color:#6d28d9; }
                .row-type-badge.campaign { background:#e0f2fe; color:#0369a1; }

                /* interest dropdown wrapper */
                .interest-wrap { position:relative; display:inline-block; }
                .interest-btn {
                    display:inline-flex; align-items:center; gap:6px;
                    padding:5px 11px; border-radius:6px; border:none; cursor:pointer;
                    font-size:12px; font-weight:700; background:#f3f4f6; color:#374151;
                    transition:background .18s; white-space:nowrap;
                }
                .interest-btn:hover { background:#e5e7eb; }
                .interest-btn .chevron { font-size:9px; transition:transform .18s; }
                .interest-btn.open .chevron { transform:rotate(180deg); }

                /* colour variants per saved state */
                .interest-btn.state-interested   { background:#d1fae5; color:#065f46; }
                .interest-btn.state-maybe        { background:#fef3c7; color:#92400e; }
                .interest-btn.state-not-interested{ background:#fee2e2; color:#991b1b; }

                .interest-dropdown {
                    display:none; position:fixed;
                    min-width:160px; background:#fff; border:1px solid #e5e7eb;
                    border-radius:10px; box-shadow:0 6px 20px rgba(0,0,0,.12);
                    z-index:9999; overflow:hidden; animation:idFadeIn .16s ease;
                }
                @keyframes idFadeIn { from{opacity:0;transform:translateY(-5px)} to{opacity:1;transform:translateY(0)} }
                .interest-wrap.open .interest-dropdown { display:block; }
                .interest-dropdown button {
                    display:flex; align-items:center; gap:9px; width:100%;
                    padding:10px 14px; border:none; background:transparent;
                    cursor:pointer; font-size:13px; font-weight:600; color:#374151;
                    transition:background .15s; text-align:left;
                }
                .interest-dropdown button:hover { background:#f9fafb; }
                .interest-dropdown button .dot {
                    width:8px; height:8px; border-radius:50%; flex-shrink:0;
                }
                .interest-dropdown button.opt-interested   .dot { background:#10b981; }
                .interest-dropdown button.opt-maybe        .dot { background:#f59e0b; }
                .interest-dropdown button.opt-not-interested .dot { background:#ef4444; }

                /* dark mode */
                body.dark-mode .dc-table th { color:#94a3b8; border-color:#334155; }
                body.dark-mode .dc-table td { color:#cbd5e1; border-color:#334155; }
                body.dark-mode .dc-table tbody tr:hover { background:#162235; }
                body.dark-mode .row-type-badge.deal     { background:#2e1065; color:#c4b5fd; }
                body.dark-mode .row-type-badge.campaign { background:#0c2a40; color:#7dd3fc; }
                body.dark-mode .interest-btn { background:#334155; color:#e2e8f0; }
                body.dark-mode .interest-btn:hover { background:#475569; }
                body.dark-mode .interest-btn.state-interested    { background:#064e3b; color:#6ee7b7; }
                body.dark-mode .interest-btn.state-maybe         { background:#451a03; color:#fbbf24; }
                body.dark-mode .interest-btn.state-not-interested{ background:#450a0a; color:#fca5a5; }
                body.dark-mode .interest-dropdown { background:#1e293b; border-color:#334155; box-shadow:0 6px 20px rgba(0,0,0,.4); }
                body.dark-mode .interest-dropdown button { color:#cbd5e1; }
                body.dark-mode .interest-dropdown button:hover { background:#0f172a; }
                </style>

                <!-- Bulk Action Bar -->
                <div class="dc-bulk-bar" id="dcBulkBar">
                    <div class="dc-bulk-left">
                        <span class="dc-bulk-count" id="dcBulkCount">0 selected</span>
                    </div>
                    <div class="dc-bulk-actions">
                        <button class="dc-bulk-btn interested"    onclick="bulkSetInterest('interested')">
                            <span class="dot"></span> Interested
                        </button>
                        <button class="dc-bulk-btn maybe"         onclick="bulkSetInterest('maybe')">
                            <span class="dot"></span> Maybe
                        </button>
                        <button class="dc-bulk-btn not-interested" onclick="bulkSetInterest('not-interested')">
                            <span class="dot"></span> Not Interested
                        </button>
                        <button class="dc-bulk-btn clear"         onclick="clearBulkSelection()">
                            <i class="fa-solid fa-xmark"></i> Clear
                        </button>
                    </div>
                </div>

                <style>
                .dc-bulk-bar {
                    display:flex; align-items:center; justify-content:space-between;
                    padding:10px 14px; background:#f8fafc;
                    border-bottom:1px solid #e5e7eb;
                    gap:12px; flex-wrap:wrap; transition:background .2s;
                }
                .dc-bulk-bar.has-selection { background:#eff6ff; border-bottom-color:#bfdbfe; }
                .dc-bulk-left { display:flex; align-items:center; gap:10px; }
                .dc-bulk-count { font-size:12px; font-weight:700; color:#6b7280; min-width:70px; }
                .dc-bulk-bar.has-selection .dc-bulk-count { color:#1d4ed8; }
                .dc-bulk-actions { display:flex; align-items:center; gap:7px; flex-wrap:wrap; }
                .dc-bulk-btn {
                    display:inline-flex; align-items:center; gap:6px;
                    padding:6px 13px; border-radius:6px; border:none; cursor:pointer;
                    font-size:12px; font-weight:700; transition:opacity .15s, transform .1s; white-space:nowrap;
                }
                .dc-bulk-btn:disabled { opacity:.4; cursor:not-allowed; }
                .dc-bulk-btn:not(:disabled):hover { opacity:.85; transform:translateY(-1px); }
                .dc-bulk-btn .dot { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
                .dc-bulk-btn.interested      { background:#d1fae5; color:#065f46; }
                .dc-bulk-btn.interested .dot { background:#10b981; }
                .dc-bulk-btn.maybe           { background:#fef3c7; color:#92400e; }
                .dc-bulk-btn.maybe .dot      { background:#f59e0b; }
                .dc-bulk-btn.not-interested  { background:#fee2e2; color:#991b1b; }
                .dc-bulk-btn.not-interested .dot { background:#ef4444; }
                .dc-bulk-btn.clear           { background:#f3f4f6; color:#374151; }
                .dc-bulk-btn.clear i         { font-size:11px; }
                .dc-check { width:16px; height:16px; cursor:pointer; accent-color:#3b82f6; flex-shrink:0; }
                body.dark-mode .dc-bulk-bar { background:#162235; border-color:#334155; }
                body.dark-mode .dc-bulk-bar.has-selection { background:#1e3a8a22; border-color:#1d4ed8; }
                body.dark-mode .dc-bulk-count { color:#94a3b8; }
                body.dark-mode .dc-bulk-bar.has-selection .dc-bulk-count { color:#93c5fd; }
                body.dark-mode .dc-bulk-btn.clear { background:#334155; color:#e2e8f0; }
                body.dark-mode .dc-bulk-btn.interested     { background:#064e3b; color:#6ee7b7; }
                body.dark-mode .dc-bulk-btn.maybe          { background:#451a03; color:#fbbf24; }
                body.dark-mode .dc-bulk-btn.not-interested { background:#450a0a; color:#fca5a5; }
                </style>

                <div style="overflow-x:auto;">
                <table class="dc-table">
                    <thead>
                        <tr>
                            <th style="width:36px;">
                                <input type="checkbox" class="dc-check" id="dcSelectAll" title="Select All" onchange="toggleSelectAll(this)">
                            </th>
                            <th>Campaign Name</th>
                            <th>Deal Name</th>
                            <th>Deal Value</th>
                            <th>Company</th>
                            <th>Service</th>
                            <th>Platform</th>
                            <th>End Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($combined_rows as $i => $row):
                        $rowNum   = $i + 1;
                        $isType   = $row['type'];
                        $endDate  = !empty($row['end_date'])
                            ? date('d M Y', strtotime($row['end_date'])) : '—';
                        $value    = ($row['deal_value'] > 0)
                            ? h($row['currency']) . ' ' . number_format((float)$row['deal_value'], 2)
                            : '—';

                        /* Interest state stored in localStorage key: interest_{type}_{id} */
                        $intKey   = 'interest_' . $isType . '_' . $row['row_id'];

                        /* View modal data */
                        $b64Deal  = $row['raw_deal'] ? base64_encode(json_encode($row['raw_deal'])) : '';
                        $b64Camp  = $row['raw_camp'] ? base64_encode(json_encode($row['raw_camp'])) : '';
                    ?>
                    <tr data-intkey="<?= $intKey ?>"
                        data-rowtype="<?= h($row['type']) ?>"
                        data-rowid="<?= $row['row_id'] ?>"
                        data-dealname="<?= h($row['deal_name']) ?>"
                        data-campname="<?= h($row['campaign_name']) ?>"
                        data-assignedto="<?= h($row['assigned_to'] ?? '') ?>"
                        data-clientname="<?= h($client['name']) ?>"
                        data-clientid="<?= $client_id ?>">
                        <td style="text-align:center;">
                            <input type="checkbox" class="dc-check dc-row-check" data-intkey="<?= $intKey ?>" onchange="onRowCheck(this)">
                        </td>
                        <td>
                            <?php if ($row['campaign_name'] !== '—'): ?>
                                <span style="font-weight:600;color:#0891b2;"><?= h($row['campaign_name']) ?></span>
                            <?php else: ?>
                                <span style="color:#d1d5db;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['deal_name'] !== '—'): ?>
                                <span style="font-weight:600;color:#7c3aed;"><?= h($row['deal_name']) ?></span>
                            <?php else: ?>
                                <span style="color:#d1d5db;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-weight:700;color:#7c3aed;"><?= $value ?></td>
                        <td><?= $row['link_company'] !== '—' ? '<span style="font-weight:500;">'.h($row['link_company']).'</span>' : '<span style="color:#d1d5db;">—</span>' ?></td>
                        <td><?= $row['service_required'] !== '—' ? h($row['service_required']) : '<span style="color:#d1d5db;">—</span>' ?></td>
                        <td><?= $row['platform'] !== '—' ? h($row['platform']) : '<span style="color:#d1d5db;">—</span>' ?></td>
                        <td style="white-space:nowrap;">
                            <?php if ($endDate !== '—'): ?>
                                <span style="font-size:12px;font-weight:600;color:#374151;"><?= $endDate ?></span>
                            <?php else: ?>
                                <span style="color:#d1d5db;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;align-items:center;gap:7px;">
                                <?php if ($b64Deal): ?>
                                <button class="task-view-btn" style="background:#7c3aed;padding:5px 9px;" title="View Deal"
                                    onmouseover="this.style.background='#6d28d9'" onmouseout="this.style.background='#7c3aed'"
                                    onclick="openDealViewModal('<?= $b64Deal ?>')">
                                    <i class="fa-solid fa-handshake"></i>
                                </button>
                                <?php endif; ?>
                                <?php if ($b64Camp): ?>
                                <button class="task-view-btn" style="background:#0891b2;padding:5px 9px;" title="View Campaign"
                                    onmouseover="this.style.background='#0e7490'" onmouseout="this.style.background='#0891b2'"
                                    onclick="openCampViewModal('<?= $b64Camp ?>')">
                                    <i class="fa-solid fa-bullhorn"></i>
                                </button>
                                <?php endif; ?>

                                <!-- Interest Dropdown -->
                                <div class="interest-wrap" id="iw_<?= $intKey ?>">
                                    <button class="interest-btn" id="ib_<?= $intKey ?>"
                                        onclick="toggleInterest('<?= $intKey ?>')">
                                        <span class="int-label" id="il_<?= $intKey ?>">Action</span>
                                        <i class="fa-solid fa-chevron-down chevron"></i>
                                    </button>
                                    <div class="interest-dropdown">
                                        <button class="opt-interested"    onclick="setInterest('<?= $intKey ?>','interested')">
                                            <span class="dot"></span> Interested
                                        </button>
                                        <button class="opt-maybe"         onclick="setInterest('<?= $intKey ?>','maybe')">
                                            <span class="dot"></span> Maybe
                                        </button>
                                        <button class="opt-not-interested" onclick="setInterest('<?= $intKey ?>','not-interested')">
                                            <span class="dot"></span> Not Interested
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div><!-- /combined deals-campaigns card -->

        <script>
        /* ── Interest Dropdown Logic ── */
        (function(){
            var STATE_LABELS = {
                'interested':    'Interested',
                'maybe':         'Maybe',
                'not-interested':'Not Interested'
            };
            var STATE_CLASSES = {
                'interested':    'state-interested',
                'maybe':         'state-maybe',
                'not-interested':'state-not-interested'
            };

            /* Restore saved states on load */
            document.addEventListener('DOMContentLoaded', function(){
                document.querySelectorAll('.interest-wrap').forEach(function(wrap){
                    var key = wrap.id.replace('iw_','');
                    var saved = localStorage.getItem('crm_interest_' + key);
                    if (saved) applyInterestUI(key, saved, false);
                });
            });

            window.toggleInterest = function(key){
                var wrap = document.getElementById('iw_' + key);
                var btn  = document.getElementById('ib_' + key);
                if (!wrap) return;
                var dd = wrap.querySelector('.interest-dropdown');

                /* Close all others */
                document.querySelectorAll('.interest-wrap.open').forEach(function(w){
                    if (w !== wrap) {
                        w.classList.remove('open');
                        w.querySelector('.interest-btn')?.classList.remove('open');
                    }
                });

                var isOpen = wrap.classList.contains('open');
                wrap.classList.toggle('open');
                btn.classList.toggle('open');

                /* Position dropdown using fixed coords from button */
                if (!isOpen && dd) {
                    var rect = btn.getBoundingClientRect();
                    dd.style.top  = (rect.bottom + 5) + 'px';
                    /* Align right edge of dropdown to right edge of button */
                    var ddW = dd.offsetWidth || 160;
                    var left = rect.right - ddW;
                    if (left < 8) left = 8; /* don't go off left edge */
                    dd.style.left = left + 'px';
                    dd.style.right = 'auto';
                }
            };

            window.setInterest = function(key, state, skipAjax){
                localStorage.setItem('crm_interest_' + key, state);
                applyInterestUI(key, state, true);

                if (skipAjax) return;

                /* Find the row with this intkey and read its data attributes */
                var row = document.querySelector('tr[data-intkey="' + key + '"]');
                if (!row) return;

                var fd = new FormData();
                fd.append('action',      'save_interest');
                fd.append('int_key',     key);
                fd.append('state',       state);
                fd.append('row_type',    row.dataset.rowtype    || '');
                fd.append('row_id',      row.dataset.rowid      || '');
                fd.append('deal_name',   row.dataset.dealname   || '');
                fd.append('camp_name',   row.dataset.campname   || '');
                fd.append('assigned_to', row.dataset.assignedto || '');
                fd.append('client_name', row.dataset.clientname || '');
                fd.append('client_id',   row.dataset.clientid   || '');

                fetch(window.location.href.split('?')[0] + '?id=' + (row.dataset.clientid || ''), {
                    method: 'POST',
                    body: fd
                }).catch(function(){});
            };

            function applyInterestUI(key, state, close){
                var wrap  = document.getElementById('iw_' + key);
                var btn   = document.getElementById('ib_' + key);
                var label = document.getElementById('il_' + key);
                if (!wrap || !btn || !label) return;

                /* Remove old state classes */
                btn.classList.remove('state-interested','state-maybe','state-not-interested');
                /* Add new */
                if (STATE_CLASSES[state]) btn.classList.add(STATE_CLASSES[state]);
                label.textContent = STATE_LABELS[state] || 'Action';

                if (close) {
                    wrap.classList.remove('open');
                    btn.classList.remove('open');
                }
            }

            /* Click outside closes all */
            document.addEventListener('click', function(e){
                if (!e.target.closest('.interest-wrap')) {
                    document.querySelectorAll('.interest-wrap.open').forEach(function(w){
                        w.classList.remove('open');
                        w.querySelector('.' + 'interest-btn')?.classList.remove('open');
                    });
                }
            });

            /* ── Bulk Selection ── */
            function updateBulkBar() {
                var checked = document.querySelectorAll('.dc-row-check:checked');
                var total   = document.querySelectorAll('.dc-row-check').length;
                var bar     = document.getElementById('dcBulkBar');
                var count   = document.getElementById('dcBulkCount');
                var selectAll = document.getElementById('dcSelectAll');

                count.textContent = checked.length + ' selected';
                bar.classList.toggle('has-selection', checked.length > 0);

                /* indeterminate state for select-all */
                if (selectAll) {
                    selectAll.indeterminate = checked.length > 0 && checked.length < total;
                    selectAll.checked = total > 0 && checked.length === total;
                }
            }

            window.toggleSelectAll = function(cb) {
                document.querySelectorAll('.dc-row-check').forEach(function(c){ c.checked = cb.checked; });
                updateBulkBar();
            };

            window.onRowCheck = function() { updateBulkBar(); };

            window.bulkSetInterest = function(state) {
                document.querySelectorAll('.dc-row-check:checked').forEach(function(cb) {
                    var key = cb.getAttribute('data-intkey');
                    if (key) setInterest(key, state); /* setInterest handles AJAX per row */
                });
                clearBulkSelection();
            };

            window.clearBulkSelection = function() {
                document.querySelectorAll('.dc-row-check').forEach(function(c){ c.checked = false; });
                var sa = document.getElementById('dcSelectAll');
                if (sa) { sa.checked = false; sa.indeterminate = false; }
                updateBulkBar();
            };

            /* init bar count */
            document.addEventListener('DOMContentLoaded', function(){ updateBulkBar(); });

        }());
        </script>

        <!-- ── Bottom Cards Row ───────────────────────────────── -->
        <div class="bottom-row">

            <!-- System Summary -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fa-solid fa-robot"></i> System Summary</h3>
                </div>
                <div class="card-body">
                    <p class="system-summary-text">
                        Profile established for <strong><?= h($client['name']) ?></strong>,
                        acting as a representative from
                        <strong><?= h($company_name) ?></strong>.
                        Account is actively managed by
                        <strong><?= h($assigned_agent) ?></strong>.
                        <?php if ($last_contacted !== 'N/A'): ?>
                            Last logged interaction was on <strong><?= $last_contacted ?></strong>.
                        <?php else: ?>
                            No interaction has been logged yet.
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <!-- Linked Tasks -->
            <div class="card">
                <div class="card-header">
                    <h3><i class="fa-solid fa-list-check"></i> Linked Tasks</h3>
                    <span class="badge-count" style="background:#111827;"><?= $task_count ?> Tasks</span>
                </div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($tasks)): ?>
                    <div class="empty-state" style="padding:30px 20px;">
                        <p style="font-size:13px; color:#9ca3af;">No active tasks linked to this company yet.</p>
                    </div>
                    <?php else: ?>
                    <table class="tasks-table">
                        <thead>
                            <tr>
                                <th>Task Objective</th>
                                <th>Status</th>
                                <th>Due Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tasks as $task):
                                $statusClass = match(strtolower($task['status'])) {
                                    'in progress' => 'inprog',
                                    'done', 'completed' => 'done',
                                    'on hold' => 'onhold',
                                    default => 'todo'
                                };
                                // Use base64 to safely pass JSON — avoids any quote/entity issues
                                $taskJson   = json_encode($task);
                                $taskBase64 = base64_encode($taskJson);
                            ?>
                            <tr>
                                <td><?= h($task['title']) ?></td>
                                <td><span class="status-pill <?= $statusClass ?>"><?= h($task['status']) ?></span></td>
                                <td><?= !empty($task['due_date']) ? date('d M Y', strtotime($task['due_date'])) : '—' ?></td>
                                <td><button class="task-view-btn" onclick="openTaskViewModal('<?= $taskBase64 ?>')"><i class="fa-solid fa-eye"></i> View</button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div><!-- /bottom-row -->

    </div><!-- /profile-page -->
</div><!-- /main-content -->

<!-- ===== TASK VIEW MODAL ===== -->
<div id="taskViewModal" style="display:none;position:fixed;z-index:3000;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,.5);align-items:flex-start;justify-content:center;overflow-y:auto;padding:18px 16px;">
    <div style="background:#fff;padding:22px 24px;border-radius:12px;width:100%;max-width:560px;box-shadow:0 10px 30px rgba(0,0,0,.18);margin:auto;position:relative;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h2 style="font-size:15px;font-weight:700;color:#111827;display:flex;align-items:center;gap:8px;">
                <i class="fa-solid fa-eye" style="color:#60a5fa;"></i> Task Details
            </h2>
            <button onclick="closeTaskViewModal()" style="background:none;border:none;font-size:18px;cursor:pointer;color:#6b7280;line-height:1;"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div style="grid-column:span 2;">
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;">Task Title</div>
                <div class="tvm-box" id="tvm_title">—</div>
            </div>
            <div style="grid-column:span 2;">
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;">Description</div>
                <div class="tvm-box" id="tvm_description" style="min-height:46px;align-items:flex-start;padding-top:8px;">—</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;">Assigned To</div>
                <div class="tvm-box" id="tvm_assigned_to">—</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;">Assigned By</div>
                <div class="tvm-box" id="tvm_assigned_by">—</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;">Priority</div>
                <div class="tvm-box" id="tvm_priority">—</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;">Status</div>
                <div class="tvm-box" id="tvm_status">—</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;">Due Date</div>
                <div class="tvm-box" id="tvm_due_date">—</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;"><i class="fa-solid fa-calendar-plus" style="color:#3b82f6;margin-right:3px;"></i>Created At</div>
                <div class="tvm-box" id="tvm_created_at">—</div>
            </div>
        </div>
        <div id="tvm_subtasks_section" style="display:none;margin-top:12px;">
            <div style="font-size:12px;font-weight:700;color:#374151;margin-bottom:8px;">
                <i class="fa-solid fa-list-ul" style="color:#3b82f6;margin-right:6px;"></i>Sub Tasks
                <span id="tvm_subtask_progress" style="font-size:11px;font-weight:600;color:#6b7280;margin-left:8px;"></span>
            </div>
            <div id="tvm_subtasks_list" style="display:flex;flex-direction:column;gap:6px;"></div>
        </div>
        <div style="margin-top:14px;">
            <button onclick="closeTaskViewModal()" style="background:#6b7280;color:#fff;padding:9px 18px;border:none;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;width:100%;">
                <i class="fa-solid fa-xmark" style="margin-right:6px;"></i>Close
            </button>
        </div>
    </div>
</div>

<style>
.tvm-box { background:#f3f4f6;padding:7px 10px;border-radius:6px;border:1px solid #e5e7eb;font-size:12px;font-weight:500;color:#111827;min-height:34px;display:flex;align-items:center;word-break:break-word; }
.task-view-btn { background:#60a5fa;color:#fff;padding:5px 10px;border:none;border-radius:4px;font-size:11px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:background 0.2s; }
.task-view-btn:hover { background:#3b82f6; }
body.dark-mode #taskViewModal > div { background:#1e293b; }
body.dark-mode .tvm-box { background:#0f172a;border-color:#334155;color:#f1f5f9; }
</style>

<script>
function openTaskViewModal(taskBase64) {
    var task;
    try {
        task = JSON.parse(atob(taskBase64));
    } catch(e) {
        console.error('Task parse error', e);
        return;
    }
    document.getElementById('tvm_title').innerText       = task.title       || 'N/A';
    document.getElementById('tvm_description').innerText = task.description || 'No description.';
    document.getElementById('tvm_assigned_to').innerText = task.assigned_to || 'Unassigned';
    document.getElementById('tvm_assigned_by').innerText = task.assigned_by || '—';
    document.getElementById('tvm_due_date').innerText    = task.due_date    || 'N/A';

    var pColors = { High:'#fee2e2;color:#ef4444', Medium:'#fef3c7;color:#f59e0b', Low:'#dcfce7;color:#10b981' };
    document.getElementById('tvm_priority').innerHTML =
        '<span style="background:' + (pColors[task.priority] || '#e5e7eb;color:#374151') + ';padding:3px 8px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;">' + (task.priority || 'Medium') + '</span>';

    var sColors = { 'To-Do':'#e5e7eb;color:#374151', 'In-Progress':'#dbeafe;color:#3b82f6', 'Completed':'#d1fae5;color:#059669' };
    document.getElementById('tvm_status').innerHTML =
        '<span style="background:' + (sColors[task.status] || '#e5e7eb;color:#374151') + ';padding:3px 8px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;">' + (task.status || 'To-Do') + '</span>';

    var caEl = document.getElementById('tvm_created_at');
    if (task.created_at) {
        var cd = new Date(task.created_at.replace(' ','T'));
        caEl.innerHTML = '<strong>' + cd.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) + '</strong>'
                       + ' <span style="color:#9ca3af;font-size:11px;margin-left:4px;">' + cd.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'}) + '</span>';
    } else { caEl.innerText = '—'; }

    var sec  = document.getElementById('tvm_subtasks_section');
    var list = document.getElementById('tvm_subtasks_list');
    var prog = document.getElementById('tvm_subtask_progress');
    sec.style.display = 'none';
    list.innerHTML = '<div style="font-size:12px;color:#9ca3af;padding:6px 0;">Loading...</div>';

    fetch('client_profile.php?get_subtasks=1&task_id=' + encodeURIComponent(task.id))
        .then(function(r){ return r.json(); })
        .then(function(data) {
            list.innerHTML = '';
            if (data && data.length > 0) {
                sec.style.display = 'block';
                var done = data.filter(function(s){ return s.is_done == 1; }).length;
                prog.innerText = done + '/' + data.length + ' done';
                data.forEach(function(st) {
                    var item = document.createElement('div');
                    item.style.cssText = 'display:flex;align-items:center;gap:10px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:9px 12px;';
                    var checked = st.is_done == 1;
                    item.innerHTML = '<input type="checkbox" disabled ' + (checked ? 'checked' : '') + ' style="width:15px;height:15px;accent-color:#3b82f6;flex-shrink:0;">'
                        + '<span style="font-size:13px;font-weight:500;' + (checked ? 'text-decoration:line-through;color:#9ca3af;' : 'color:#374151;') + '">' + st.title + '</span>';
                    list.appendChild(item);
                });
            } else { sec.style.display = 'none'; }
        })
        .catch(function(){ sec.style.display = 'none'; });

    document.getElementById('taskViewModal').style.display = 'flex';
}
function closeTaskViewModal() {
    document.getElementById('taskViewModal').style.display = 'none';
}
document.getElementById('taskViewModal').addEventListener('click', function(e) {
    if (e.target === this) closeTaskViewModal();
});
</script>

<!-- ===== DEAL VIEW MODAL ===== -->
<div id="dealViewModal" style="display:none;position:fixed;z-index:3000;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,.5);align-items:flex-start;justify-content:center;overflow-y:auto;padding:18px 16px;">
    <div style="background:#fff;padding:22px 24px;border-radius:12px;width:100%;max-width:560px;box-shadow:0 10px 30px rgba(0,0,0,.18);margin:auto;position:relative;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h2 style="font-size:15px;font-weight:700;color:#111827;display:flex;align-items:center;gap:8px;">
                <i class="fa-solid fa-handshake" style="color:#7c3aed;"></i> Deal Details
            </h2>
            <button onclick="closeDealViewModal()" style="background:none;border:none;font-size:18px;cursor:pointer;color:#6b7280;line-height:1;"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div style="grid-column:span 2;">
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;">Deal Name</div>
                <div class="tvm-box" id="dvm_name">—</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;">Deal Value</div>
                <div class="tvm-box" id="dvm_value" style="font-weight:700;color:#7c3aed;">—</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;">Stage</div>
                <div class="tvm-box" id="dvm_stage">—</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;">Linked Company</div>
                <div class="tvm-box" id="dvm_company">—</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;">Service Required</div>
                <div class="tvm-box" id="dvm_service">—</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;">Platform</div>
                <div class="tvm-box" id="dvm_platform">—</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;">Sales Officer</div>
                <div class="tvm-box" id="dvm_officer">—</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;">Start Date</div>
                <div class="tvm-box" id="dvm_start">—</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;">End Date</div>
                <div class="tvm-box" id="dvm_end">—</div>
            </div>
            <div style="grid-column:span 2;">
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;">Additional Notes</div>
                <div class="tvm-box" id="dvm_notes" style="min-height:46px;align-items:flex-start;padding-top:8px;">—</div>
            </div>
            <div style="grid-column:span 2;">
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;"><i class="fa-solid fa-calendar-plus" style="color:#7c3aed;margin-right:3px;"></i>Created At</div>
                <div class="tvm-box" id="dvm_created">—</div>
            </div>
        </div>
        <div style="margin-top:14px;">
            <button onclick="closeDealViewModal()" style="background:#6b7280;color:#fff;padding:9px 18px;border:none;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;width:100%;">
                <i class="fa-solid fa-xmark" style="margin-right:6px;"></i>Close
            </button>
        </div>
    </div>
</div>

<!-- ===== CAMPAIGN VIEW MODAL ===== -->
<div id="campViewModal" style="display:none;position:fixed;z-index:3000;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,.5);align-items:flex-start;justify-content:center;overflow-y:auto;padding:18px 16px;">
    <div style="background:#fff;padding:22px 24px;border-radius:12px;width:100%;max-width:560px;box-shadow:0 10px 30px rgba(0,0,0,.18);margin:auto;position:relative;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h2 style="font-size:15px;font-weight:700;color:#111827;display:flex;align-items:center;gap:8px;">
                <i class="fa-solid fa-bullhorn" style="color:#0891b2;"></i> Campaign Details
            </h2>
            <button onclick="closeCampViewModal()" style="background:none;border:none;font-size:18px;cursor:pointer;color:#6b7280;line-height:1;"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div style="grid-column:span 2;">
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;">Campaign Name</div>
                <div class="tvm-box" id="cvm_name">—</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;">Type</div>
                <div class="tvm-box" id="cvm_type">—</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;">Status</div>
                <div class="tvm-box" id="cvm_status">—</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;">Budget</div>
                <div class="tvm-box" id="cvm_budget" style="font-weight:700;color:#0891b2;">—</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;">Target Audience</div>
                <div class="tvm-box" id="cvm_audience">—</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;">Assigned To</div>
                <div class="tvm-box" id="cvm_assigned">—</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;">Start Date</div>
                <div class="tvm-box" id="cvm_start">—</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;">End Date</div>
                <div class="tvm-box" id="cvm_end">—</div>
            </div>
            <div style="grid-column:span 2;">
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;"><i class="fa-solid fa-handshake" style="color:#7c3aed;margin-right:3px;"></i>Linked Deal</div>
                <div class="tvm-box" id="cvm_deal" style="font-weight:600;color:#7c3aed;">—</div>
            </div>
            <div style="grid-column:span 2;">
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;">Description</div>
                <div class="tvm-box" id="cvm_desc" style="min-height:46px;align-items:flex-start;padding-top:8px;">—</div>
            </div>
            <div style="grid-column:span 2;">
                <div style="font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;margin-bottom:3px;"><i class="fa-solid fa-calendar-plus" style="color:#0891b2;margin-right:3px;"></i>Created At</div>
                <div class="tvm-box" id="cvm_created">—</div>
            </div>
        </div>
        <div style="margin-top:14px;">
            <button onclick="closeCampViewModal()" style="background:#6b7280;color:#fff;padding:9px 18px;border:none;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;width:100%;">
                <i class="fa-solid fa-xmark" style="margin-right:6px;"></i>Close
            </button>
        </div>
    </div>
</div>

<style>
body.dark-mode #dealViewModal > div,
body.dark-mode #campViewModal > div { background:#1e293b; }
</style>

<script>
/* ── Deal View Modal ── */
function openDealViewModal(b64) {
    var deal;
    try { deal = JSON.parse(atob(b64)); } catch(e) { return; }

    document.getElementById('dvm_name').innerText    = deal.deal_name       || '—';
    document.getElementById('dvm_company').innerText = deal.link_company     || '—';
    document.getElementById('dvm_service').innerText = deal.service_required || '—';
    document.getElementById('dvm_platform').innerText= deal.platform         || '—';
    document.getElementById('dvm_officer').innerText = deal.sales_officer    || '—';
    document.getElementById('dvm_notes').innerText   = deal.additional_notes || 'No notes.';

    var cur = deal.currency || 'USD';
    var val = parseFloat(deal.deal_value || 0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    document.getElementById('dvm_value').innerText = cur + ' ' + val;

    var stageColors = {
        'Lead':        '#fef3c7;color:#92400e',
        'Proposal':    '#dbeafe;color:#1d4ed8',
        'Negotiation': '#ede9fe;color:#6d28d9',
        'Closed Won':  '#d1fae5;color:#065f46',
        'Closed Lost': '#fee2e2;color:#991b1b'
    };
    var sc = stageColors[deal.stage] || '#e5e7eb;color:#374151';
    document.getElementById('dvm_stage').innerHTML =
        '<span style="background:' + sc + ';padding:3px 10px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;">' + (deal.stage || '—') + '</span>';

    document.getElementById('dvm_start').innerText = deal.start_date ? formatDate(deal.start_date) : '—';
    document.getElementById('dvm_end').innerText   = deal.end_date   ? formatDate(deal.end_date)   : '—';

    var creEl = document.getElementById('dvm_created');
    if (deal.created_at) {
        var cd = new Date(deal.created_at.replace(' ','T'));
        creEl.innerHTML = '<strong>' + cd.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) + '</strong>'
                        + ' <span style="color:#9ca3af;font-size:11px;margin-left:4px;">' + cd.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'}) + '</span>';
    } else { creEl.innerText = '—'; }

    document.getElementById('dealViewModal').style.display = 'flex';
}
function closeDealViewModal() {
    document.getElementById('dealViewModal').style.display = 'none';
}
document.getElementById('dealViewModal').addEventListener('click', function(e) {
    if (e.target === this) closeDealViewModal();
});

/* ── Campaign View Modal ── */
function openCampViewModal(b64) {
    var camp;
    try { camp = JSON.parse(atob(b64)); } catch(e) { return; }

    document.getElementById('cvm_name').innerText    = camp.campaign_name    || '—';
    document.getElementById('cvm_type').innerText    = camp.campaign_type    || '—';
    document.getElementById('cvm_audience').innerText= camp.target_audience  || '—';
    document.getElementById('cvm_assigned').innerText= camp.assigned_to      || '—';
    document.getElementById('cvm_desc').innerText    = camp.description      || 'No description.';
    document.getElementById('cvm_deal').innerText    = camp.deal_name        || 'No Deal Linked';

    var cur = camp.currency || 'USD';
    var bud = parseFloat(camp.budget || 0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    document.getElementById('cvm_budget').innerText = cur + ' ' + bud;

    var statusColors = {
        'Planning':  '#fef3c7;color:#92400e',
        'Active':    '#d1fae5;color:#065f46',
        'Completed': '#dbeafe;color:#1d4ed8',
        'On Hold':   '#f3f4f6;color:#374151'
    };
    var sc = statusColors[camp.status] || '#e5e7eb;color:#374151';
    document.getElementById('cvm_status').innerHTML =
        '<span style="background:' + sc + ';padding:3px 10px;border-radius:4px;font-size:10px;font-weight:700;text-transform:uppercase;">' + (camp.status || '—') + '</span>';

    document.getElementById('cvm_start').innerText = camp.start_date ? formatDate(camp.start_date) : '—';
    document.getElementById('cvm_end').innerText   = camp.end_date   ? formatDate(camp.end_date)   : '—';

    var creEl = document.getElementById('cvm_created');
    if (camp.created_at) {
        var cd = new Date(camp.created_at.replace(' ','T'));
        creEl.innerHTML = '<strong>' + cd.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) + '</strong>'
                        + ' <span style="color:#9ca3af;font-size:11px;margin-left:4px;">' + cd.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'}) + '</span>';
    } else { creEl.innerText = '—'; }

    document.getElementById('campViewModal').style.display = 'flex';
}
function closeCampViewModal() {
    document.getElementById('campViewModal').style.display = 'none';
}
document.getElementById('campViewModal').addEventListener('click', function(e) {
    if (e.target === this) closeCampViewModal();
});

/* ── Shared helper ── */
function formatDate(str) {
    if (!str) return '—';
    var d = new Date(str);
    return d.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
}
</script>

<script>
    function showToast(message, type) {
        const toast = document.getElementById("toastBox");
        document.getElementById("toastMsg").innerText = message;
        toast.className = "show " + type;
        document.getElementById("toastIcon").className =
            (type === 'success') ? "fa-solid fa-circle-check" : "fa-solid fa-circle-xmark";
        setTimeout(() => { toast.className = toast.className.replace("show", "").trim(); }, 3500);
    }

    window.onload = function () {
        <?php if ($toastMessage !== ''): ?>
        showToast("<?= $toastMessage ?>", "<?= $toastType ?>");
        <?php endif; ?>
    };
</script>
</body>
</html>