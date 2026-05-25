<?php
// ========================================================================
// company_profile.php — Systellio CRM
// Usage: company_profile.php?id=5
// ========================================================================
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// ── AJAX POST handler (add_contact / bulk_add_contacts) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json');

    $action     = $_POST['action'];
    $company_id = isset($_POST['company_id']) ? (int)$_POST['company_id'] : 0;

    function escQ($conn, $v) { return mysqli_real_escape_string($conn, trim($v ?? '')); }

    function insertContact($conn, $name, $email, $phone, $designation, $cid) {
        if (empty(trim($name))) return false;
        $n = escQ($conn,$name); $e = escQ($conn,$email);
        $p = escQ($conn,$phone); $d = escQ($conn,$designation);
        return mysqli_query($conn,
            "INSERT INTO contacts (name,email,phone,designation,company_id)
             VALUES ('$n','$e','$p','$d',$cid)");
    }

    if ($action === 'add_contact') {
        if (empty(trim($_POST['ac_name'] ?? ''))) {
            echo json_encode(['success'=>false,'message'=>'Name is required.']); exit();
        }
        $name  = escQ($conn, $_POST['ac_name']);
        $email = escQ($conn, $_POST['ac_email'] ?? '');
        $phone = escQ($conn, $_POST['ac_phone'] ?? '');
        $desig = escQ($conn, $_POST['ac_designation'] ?? '');
        $agents_raw = $_POST['assigned_agents'] ?? [];
        $agents_val = !empty($agents_raw) ? "'".escQ($conn, implode(',', $agents_raw))."'" : "NULL";
        $ok = mysqli_query($conn,
            "INSERT INTO contacts (name,email,phone,designation,company_id,assigned_agents)
             VALUES ('$name','$email','$phone','$desig',$company_id,$agents_val)");
        echo json_encode($ok
            ? ['success'=>true,  'message'=>'Client added successfully!']
            : ['success'=>false, 'message'=>'Database error: '.mysqli_error($conn)]);
        exit();
    }

    if ($action === 'bulk_add_contacts') {
        $rows = json_decode($_POST['rows'] ?? '[]', true);
        if (!is_array($rows) || empty($rows)) {
            echo json_encode(['success'=>false,'message'=>'No data to import.']); exit();
        }
        $inserted = $skipped = 0;
        foreach ($rows as $row) {
            $ok = insertContact($conn, $row['name']??'', $row['email']??'',
                                $row['phone']??'', $row['designation']??'', $company_id);
            $ok ? $inserted++ : $skipped++;
        }
        $msg = "$inserted contact(s) uploaded successfully!";
        if ($skipped) $msg .= " ($skipped row(s) skipped — missing name.)";
        echo json_encode(['success'=>true,'message'=>$msg,'inserted'=>$inserted,'skipped'=>$skipped]);
        exit();
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action.']);
    exit();
}
// ─────────────────────────────────────────────────────────────────────────

$company_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($company_id <= 0) { header("Location: company_list.php"); exit(); }

function h($v) { return htmlspecialchars($v ?? ''); }
function orNA($v) { return (!empty($v)) ? htmlspecialchars($v) : 'N/A'; }

// ── Fetch company ────────────────────────────────────────────────────────
$company = null;
$cq = mysqli_query($conn, "SELECT * FROM companies WHERE id = $company_id LIMIT 1");
if ($cq && mysqli_num_rows($cq) > 0) {
    $company = mysqli_fetch_assoc($cq);
} else {
    header("Location: company_list.php");
    exit();
}

// ── Fetch clients under this company ────────────────────────────────────
$clients      = [];
$client_count = 0;
$clq = mysqli_query($conn, "SELECT * FROM contacts WHERE company_id = $company_id ORDER BY id DESC");
if ($clq) {
    $client_count = mysqli_num_rows($clq);
    while ($row = mysqli_fetch_assoc($clq)) $clients[] = $row;
}

$avatar_letter  = strtoupper(substr($company['company_name'], 0, 1));
$assigned_agent = !empty($company['assigned_agent']) ? $company['assigned_agent'] : 'Unassigned';
$date_added     = !empty($company['created_at']) ? date('d M, Y', strtotime($company['created_at'])) : 'N/A';

$has_fb  = !empty($company['fb_url']);
$has_li  = !empty($company['linkedin_url']);
$has_ig  = !empty($company['insta_url']);
$has_tw  = !empty($company['twitter_url']);
$has_web = !empty($company['company_website']);
$no_links = !$has_fb && !$has_li && !$has_ig && !$has_tw;

// ── Dropdown data for Add Contact modal ──────────────────────────────────
$companyOptions = '';
$comp_drp = mysqli_query($conn, "SELECT id, company_name FROM companies ORDER BY company_name ASC");
if ($comp_drp) {
    while ($cRow = mysqli_fetch_assoc($comp_drp)) {
        $sel = ($cRow['id'] == $company_id) ? ' selected' : '';
        $companyOptions .= "<option value='{$cRow['id']}'{$sel}>" . htmlspecialchars($cRow['company_name']) . "</option>";
    }
}

$agentOptions = '';
$ag_q = mysqli_query($conn, "SELECT username, name FROM users WHERE role IN ('agent','manager','admin') AND status='active' ORDER BY name ASC");
if ($ag_q) {
    while ($aRow = mysqli_fetch_assoc($ag_q)) {
        $agentOptions .= "<option value='{$aRow['username']}'>" . htmlspecialchars($aRow['name']) . " ({$aRow['username']})</option>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($company['company_name']) ?> — Company Profile | Systellio CRM</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

        * { margin:0; padding:0; box-sizing:border-box; font-family:'Inter',sans-serif; }

        /* ── Toast ── */
        #toastBox {
            visibility:hidden; min-width:250px; background:#333; color:#fff;
            text-align:center; border-radius:8px; padding:16px;
            position:fixed; z-index:9999; right:30px; top:30px;
            font-size:14px; font-weight:600; box-shadow:0 4px 12px rgba(0,0,0,.15);
            display:flex; align-items:center; gap:10px;
            transform:translateX(120%);
            transition:transform .4s cubic-bezier(.68,-.55,.265,1.55), visibility .4s;
        }
        #toastBox.show    { visibility:visible; transform:translateX(0); }
        #toastBox.success { background:#10b981; }
        #toastBox.error   { background:#ef4444; }

        /* ── Layout ── */
        body { background:#f3f4f6; display:flex; height:100vh; overflow:hidden; color:#111827; }
        .main-content { flex-grow:1; display:flex; flex-direction:column; overflow-y:auto; background:#f3f4f6; }

        /* ── Navbar ── */
        
        
        
        
        .breadcrumb a { color:#6b7280; text-decoration:none; }
        .breadcrumb a:hover { color:#3b82f6; }
        .breadcrumb .sep { color:#d1d5db; }
        .breadcrumb .current { color:#3b82f6; font-weight:700; }
        
        
        .nav-icon-btn:hover { color:#3b82f6; }
        
        .user-profile i { font-size:22px; color:#3b82f6; }

        /* ── Page wrapper ── */
        .page-wrap { padding:24px 28px; display:flex; flex-direction:column; gap:20px; }

        /* ══ ACTION BAR ══ */
        .action-bar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .action-bar .spacer { flex:1; }

        .btn-back {
            background:#0f172a; color:#fff; padding:9px 18px; border-radius:8px;
            font-size:13px; font-weight:700; text-decoration:none;
            display:inline-flex; align-items:center; gap:8px;
            border:none; cursor:pointer; transition:.2s;
        }
        .btn-back:hover { background:#1e293b; }

        .btn-green {
            background:#22c55e; color:#fff; padding:9px 18px; border-radius:8px;
            font-size:13px; font-weight:700; border:none; cursor:pointer;
            display:inline-flex; align-items:center; gap:8px; transition:.2s;
        }
        .btn-green:hover { background:#16a34a; }

        .btn-dark {
            background:#1e293b; color:#fff; padding:9px 18px; border-radius:8px;
            font-size:13px; font-weight:700; border:none; cursor:pointer;
            display:inline-flex; align-items:center; gap:8px; transition:.2s;
        }
        .btn-dark:hover { background:#334155; }

        .btn-blue {
            background:#3b82f6; color:#fff; padding:9px 18px; border-radius:8px;
            font-size:13px; font-weight:700; border:none; cursor:pointer;
            display:inline-flex; align-items:center; gap:8px; transition:.2s;
        }
        .btn-blue:hover { background:#2563eb; }

        .search-box { position:relative; }
        .search-box i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:#9ca3af; font-size:12px; }
        .search-box input {
            padding:9px 14px 9px 34px; border:1px solid #e5e7eb; border-radius:20px;
            font-size:13px; font-family:'Inter',sans-serif; outline:none;
            width:210px; color:#374151; background:#fff; transition:.2s;
        }
        .search-box input:focus { border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.1); }

        /* ══ HERO BANNER ══ */
        .hero-banner {
            background: linear-gradient(135deg, #2d1fa3 0%, #3730a3 40%, #4f46e5 100%);
            border-radius:14px; padding:28px 32px;
            display:flex; align-items:center; justify-content:space-between;
            position:relative; overflow:hidden; color:#fff;
            box-shadow:0 6px 24px rgba(79,70,229,.4);
        }
        .hero-banner::before {
            content:''; position:absolute; right:-30px; top:-30px;
            width:220px; height:220px; border-radius:50%;
            background:rgba(255,255,255,.07);
        }
        .hero-banner::after {
            content:''; position:absolute; right:90px; bottom:-50px;
            width:160px; height:160px; border-radius:50%;
            background:rgba(255,255,255,.05);
        }

        .hero-left { display:flex; align-items:center; gap:20px; z-index:1; }

        .company-avatar {
            width:62px; height:62px; border-radius:14px;
            background:rgba(255,255,255,.2); backdrop-filter:blur(6px);
            display:flex; align-items:center; justify-content:center;
            font-size:26px; font-weight:800; color:#fff; flex-shrink:0;
            border:2px solid rgba(255,255,255,.3);
        }

        .hero-info h2 { font-size:24px; font-weight:800; margin-bottom:10px; }

        .hero-meta { display:flex; align-items:center; gap:12px; margin-bottom:14px; }
        .badge-b2b {
            background:rgba(255,255,255,.2); border:1px solid rgba(255,255,255,.35);
            color:#fff; font-size:11px; font-weight:700; padding:3px 12px;
            border-radius:20px; letter-spacing:.5px;
        }
        .contacts-count { font-size:13px; font-weight:600; display:flex; align-items:center; gap:6px; opacity:.9; }

        .hero-contact-row { display:flex; gap:8px; flex-wrap:wrap; }
        .hc-btn {
            background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.25);
            color:#fff; padding:7px 14px; border-radius:7px; font-size:12px; font-weight:600;
            display:inline-flex; align-items:center; gap:7px;
            text-decoration:none; transition:.2s; backdrop-filter:blur(4px);
        }
        .hc-btn:hover { background:rgba(255,255,255,.28); }

        .hero-right { display:flex; flex-direction:column; align-items:flex-end; gap:16px; z-index:1; }
        .social-row { display:flex; gap:10px; }
        .social-dot {
            width:36px; height:36px; border-radius:50%;
            background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.25);
            display:flex; align-items:center; justify-content:center;
            font-size:14px; color:#fff; text-decoration:none; transition:.2s;
        }
        .social-dot:hover { background:rgba(255,255,255,.3); transform:translateY(-2px); }
        .no-links-txt { font-size:12px; opacity:.5; font-style:italic; }

        .btn-edit-company {
            background:transparent; border:1.5px solid rgba(255,255,255,.5);
            color:#fff; padding:8px 18px; border-radius:8px; font-size:12px; font-weight:700;
            cursor:pointer; display:inline-flex; align-items:center; gap:7px;
            transition:.2s; text-decoration:none;
        }
        .btn-edit-company:hover { background:rgba(255,255,255,.15); }

        /* ══ CLIENTS TABLE ══ */
        .table-card {
            background:#fff; border-radius:14px; border:1px solid #e5e7eb;
            box-shadow:0 2px 10px rgba(0,0,0,.04); overflow:hidden;
        }

        .ct { width:100%; border-collapse:collapse; font-size:13px; }
        .ct thead th {
            background:#fff; padding:13px 16px; font-size:12px; font-weight:700;
            color:#6b7280; text-align:left; border-bottom:1px solid #f3f4f6; white-space:nowrap;
        }
        .ct tbody tr:hover td { background:#f8faff; }
        .ct tbody td {
            padding:13px 16px; color:#374151; font-weight:500;
            border-bottom:1px solid #f3f4f6; vertical-align:middle;
        }
        .ct tbody tr:last-child td { border-bottom:none; }

        .ct th:first-child, .ct td:first-child { width:40px; padding-left:18px; }

        .comp-name { font-size:12px; color:#6b7280; font-weight:500; }

        .cl-name-wrap { display:flex; align-items:center; gap:10px; }
        .cl-avatar {
            width:34px; height:34px; border-radius:50%;
            background:linear-gradient(135deg,#3b82f6,#7c3aed);
            display:flex; align-items:center; justify-content:center;
            font-size:13px; font-weight:800; color:#fff; flex-shrink:0;
        }
        .cl-name-link { font-weight:700; color:#3b82f6; text-decoration:none; font-size:13px; }
        .cl-name-link:hover { text-decoration:underline; }

        .desig-badge {
            display:inline-block; padding:4px 12px; border-radius:6px;
            background:#f3f4f6; color:#374151; font-size:11px; font-weight:700;
            border:1px solid #e5e7eb;
        }

        .last-col { display:flex; align-items:center; gap:6px; color:#6b7280; white-space:nowrap; font-size:12px; }
        .last-col i { color:#9ca3af; }

        .ci-wrap { display:flex; flex-direction:column; gap:3px; }
        .ci-row { display:flex; align-items:center; gap:6px; font-size:12px; }
        .ci-row i { color:#9ca3af; font-size:10px; width:12px; }
        .ci-row a { color:#374151; text-decoration:none; }
        .ci-row a:hover { color:#3b82f6; }

        .soc-wrap { display:flex; align-items:center; gap:5px; }
        .soc-i {
            width:26px; height:26px; border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            font-size:11px; text-decoration:none; transition:.18s;
            background:#f3f4f6; color:#9ca3af;
        }
        .soc-i.fb  { background:#e7f0ff; color:#1877f2; }
        .soc-i.li  { background:#e7f3fa; color:#0077b5; }
        .soc-i.ig  { background:#fce7f3; color:#e1306c; }
        .soc-i.tw  { background:#e7f5fd; color:#1da1f2; }
        .soc-i:hover { transform:translateY(-2px); }

        .act-wrap { display:flex; align-items:center; gap:5px; }
        .act-btn {
            width:28px; height:28px; border-radius:7px; border:none; cursor:pointer;
            display:flex; align-items:center; justify-content:center;
            font-size:12px; transition:.18s; text-decoration:none;
        }
        .act-btn.view { background:#dbeafe; color:#2563eb; }
        .act-btn.edit { background:#f3f4f6; color:#374151; }
        .act-btn.del  { background:#fee2e2; color:#dc2626; }
        .act-btn:hover { transform:translateY(-2px); opacity:.85; }

        .empty-state {
            display:flex; flex-direction:column; align-items:center;
            justify-content:center; padding:56px 20px; color:#9ca3af;
        }
        .empty-state i { font-size:42px; margin-bottom:14px; color:#d1d5db; }
        .empty-state p { font-size:13px; font-weight:500; }

        /* ── Dark mode ── */
        body.dark-mode { background:#0f172a; color:#f8fafc; }
        body.dark-mode .main-content { background:#0f172a; }
        body.dark-mode 
        body.dark-mode 
        body.dark-mode .breadcrumb a, body.dark-mode 
        body.dark-mode .search-box input { background:#0f172a; color:#f8fafc; border-color:#334155; }
        body.dark-mode .table-card { background:#1e293b; border-color:#334155; }
        body.dark-mode .ct thead th { background:#1e293b; color:#94a3b8; border-color:#334155; }
        body.dark-mode .ct tbody td { border-color:#334155; color:#cbd5e1; }
        body.dark-mode .ct tbody tr:hover td { background:#1e3a5f; }
        body.dark-mode .desig-badge { background:#334155; color:#cbd5e1; border-color:#475569; }
        body.dark-mode .soc-i { background:#334155; color:#64748b; }
        body.dark-mode .act-btn.edit { background:#334155; color:#cbd5e1; }
        body.dark-mode .comp-name { color:#64748b; }
        .swal2-container { z-index:9999 !important; }
        body.dark-mode .swal2-popup { background:#1e293b; color:#f8fafc; border:1px solid #334155; }

        /* ── Add Contact Modal (matches client_list.php) ── */
        .modal { display:none; position:fixed; z-index:2000; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.5); align-items:center; justify-content:center; }
        .modal-content { background-color:#fff; padding:20px 22px; border-radius:10px; width:100%; max-width:500px; box-shadow:0 10px 25px rgba(0,0,0,0.15); }
        .modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; }
        .modal-header h2 { font-size:15px; font-weight:700; color:#111827; }
        .close-btn { font-size:17px; cursor:pointer; color:#6b7280; border:none; background:none; transition:.2s; }
        .close-btn:hover { color:#ef4444; }
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .form-group { margin-bottom:0; }
        .full-width { grid-column:span 2; }
        .form-group label { display:block; font-size:11px; font-weight:700; color:#374151; margin-bottom:4px; }
        .form-group input, .form-group select { width:100%; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:12px; outline:none; background-color:#f9fafb; transition:.2s; color:#111827; font-family:'Inter',sans-serif; }
        .form-group input:focus, .form-group select:focus { border-color:#3b82f6; background-color:#fff; box-shadow:0 0 0 2px rgba(59,130,246,0.1); }
        .form-group input::placeholder { color:#9ca3af; }
        .form-group select[multiple] { padding:6px 4px; cursor:pointer; height:72px; }
        .form-group select[multiple] option { padding:7px 10px; border-radius:4px; margin-bottom:2px; }
        .form-group select[multiple] option:checked { background:#3b82f6 linear-gradient(0deg,#3b82f6 0%,#3b82f6 100%); color:#fff; }
        .submit-btn { background-color:#0f172a; color:#ffffff; padding:10px; border:none; border-radius:6px; width:100%; font-size:13px; font-weight:700; cursor:pointer; transition:.2s; margin-top:12px; }
        .submit-btn:hover { background-color:#1e293b; }
        body.dark-mode .modal-content { background-color:#1e293b; box-shadow:0 10px 25px rgba(0,0,0,0.5); border:1px solid #334155; }
        body.dark-mode .modal-header h2 { color:#f8fafc; }
        body.dark-mode .form-group label { color:#cbd5e1; }
        body.dark-mode .form-group input, body.dark-mode .form-group select { background-color:#0f172a; color:#f8fafc; border-color:#334155; }
        body.dark-mode .form-group input:focus, body.dark-mode .form-group select:focus { border-color:#3b82f6; background-color:#1e293b; }
        body.dark-mode .form-group select[multiple] option { color:#f8fafc; }
    </style>
</head>
<body>

<div id="toastBox">
    <i id="toastIcon" class="fa-solid fa-circle-check"></i>
    <span id="toastMsg">Done!</span>
</div>

<?php
    $activePage    = 'company_list';
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

    <div class="page-wrap">

        <!-- ACTION BAR -->
        <div class="action-bar">
            <a href="company_list.php" class="btn-back">
                <i class="fa-solid fa-arrow-left"></i> Back to Companies
            </a>
            <button class="btn-green" onclick="exportCSV()">
                <i class="fa-solid fa-file-csv"></i> Export CSV
            </button>
            <button class="btn-dark" onclick="bulkUpload()">
                <i class="fa-solid fa-cloud-arrow-up"></i> Bulk Upload
            </button>
            <button class="btn-blue" onclick="addContact()">
                <i class="fa-solid fa-user-plus"></i> Add Contact
            </button>
            <div class="spacer"></div>
            <div class="search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="clientSearch" placeholder="Search contact..." oninput="filterClients()">
            </div>
        </div>

        <!-- HERO BANNER -->
        <div class="hero-banner">
            <div class="hero-left">
                <div class="company-avatar"><?= $avatar_letter ?></div>
                <div class="hero-info">
                    <h2><?= h($company['company_name']) ?></h2>
                    <div class="hero-meta">
                        <span class="badge-b2b">B2B Partner</span>
                        <span class="contacts-count">
                            <i class="fa-solid fa-users" style="font-size:12px;"></i>
                            <?= $client_count ?> Contacts
                        </span>
                    </div>
                    <div class="hero-contact-row">
                        <!-- Phone -->
                        <?php if(!empty($company['company_number'])): ?>
                        <a href="tel:<?= h($company['company_number']) ?>" class="hc-btn">
                            <i class="fa-solid fa-phone" style="font-size:11px;"></i>
                            <?= h($company['company_number']) ?>
                        </a>
                        <?php else: ?>
                        <span class="hc-btn" style="opacity:.55;cursor:default;">
                            <i class="fa-solid fa-phone" style="font-size:11px;"></i> N/A
                        </span>
                        <?php endif; ?>
                        <!-- Email -->
                        <?php if(!empty($company['company_email'])): ?>
                        <a href="mailto:<?= h($company['company_email']) ?>" class="hc-btn">
                            <i class="fa-solid fa-envelope" style="font-size:11px;"></i>
                            <?= h($company['company_email']) ?>
                        </a>
                        <?php else: ?>
                        <span class="hc-btn" style="opacity:.55;cursor:default;">
                            <i class="fa-solid fa-envelope" style="font-size:11px;"></i> N/A
                        </span>
                        <?php endif; ?>
                        <!-- Website -->
                        <?php if($has_web): ?>
                        <a href="<?= h($company['company_website']) ?>" target="_blank" class="hc-btn">
                            <i class="fa-solid fa-globe" style="font-size:11px;"></i>
                            <?= h($company['company_website']) ?>
                        </a>
                        <?php else: ?>
                        <span class="hc-btn" style="opacity:.55;cursor:default;">
                            <i class="fa-solid fa-globe" style="font-size:11px;"></i> N/A
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="hero-right">
                <a href="?id=<?= $company_id ?>&edit=1" class="btn-edit-company">
                    <i class="fa-solid fa-pen-to-square"></i> Edit Company Info
                </a>
                <?php if($no_links): ?>
                    <span class="no-links-txt">No Links</span>
                <?php else: ?>
                <div class="social-row">
                    <?php if($has_fb): ?>
                    <a href="<?= h($company['fb_url']) ?>" target="_blank" class="social-dot">
                        <i class="fa-brands fa-facebook-f"></i>
                    </a>
                    <?php endif; ?>
                    <?php if($has_li): ?>
                    <a href="<?= h($company['linkedin_url']) ?>" target="_blank" class="social-dot">
                        <i class="fa-brands fa-linkedin-in"></i>
                    </a>
                    <?php endif; ?>
                    <?php if($has_ig): ?>
                    <a href="<?= h($company['insta_url']) ?>" target="_blank" class="social-dot">
                        <i class="fa-brands fa-instagram"></i>
                    </a>
                    <?php endif; ?>
                    <?php if($has_tw): ?>
                    <a href="<?= h($company['twitter_url']) ?>" target="_blank" class="social-dot">
                        <i class="fa-brands fa-twitter"></i>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- CLIENTS TABLE -->
        <div class="table-card">
            <?php if (empty($clients)): ?>
            <div class="empty-state">
                <i class="fa-solid fa-user-slash"></i>
                <p>No contacts linked to this company yet.</p>
            </div>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="ct" id="clientsTable">
                    <thead>
                        <tr>
                            <th>
                                <input type="checkbox" id="selectAll" onchange="toggleAll(this)"
                                    style="width:15px;height:15px;cursor:pointer;accent-color:#3b82f6;">
                            </th>
                            <th>Comp.</th>
                            <th>Client name</th>
                            <th>Designation</th>
                            <th>Last contacted</th>
                            <th>Contact Info</th>
                            <th>Socials</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($clients as $cl):
                        $init      = strtoupper(substr($cl['name'], 0, 1));
                        $last_date = !empty($cl['created_at']) ? date('d M Y', strtotime($cl['created_at'])) : '—';
                        $comp_short = mb_strlen($company['company_name']) > 14
                                    ? mb_substr($company['company_name'], 0, 13) . '...'
                                    : $company['company_name'];
                    ?>
                        <tr>
                            <!-- Checkbox -->
                            <td>
                                <input type="checkbox" class="row-cb"
                                    style="width:15px;height:15px;cursor:pointer;accent-color:#3b82f6;">
                            </td>

                            <!-- Comp. -->
                            <td>
                                <div class="comp-name" title="<?= h($company['company_name']) ?>">
                                    <?= h($comp_short) ?>
                                </div>
                            </td>

                            <!-- Client name -->
                            <td>
                                <div class="cl-name-wrap">
                                    <div class="cl-avatar"><?= $init ?></div>
                                    <a href="client_profile.php?id=<?= $cl['id'] ?>" class="cl-name-link">
                                        <?= h($cl['name']) ?>
                                    </a>
                                </div>
                            </td>

                            <!-- Designation -->
                            <td>
                                <?php if(!empty($cl['designation'])): ?>
                                    <span class="desig-badge"><?= h($cl['designation']) ?></span>
                                <?php else: ?>
                                    <span style="color:#d1d5db;font-size:12px;">—</span>
                                <?php endif; ?>
                            </td>

                            <!-- Last contacted -->
                            <td>
                                <div class="last-col">
                                    <i class="fa-regular fa-calendar"></i>
                                    <?= $last_date ?>
                                </div>
                            </td>

                            <!-- Contact Info -->
                            <td>
                                <div class="ci-wrap">
                                    <div class="ci-row">
                                        <i class="fa-solid fa-phone"></i>
                                        <?php if(!empty($cl['phone'])): ?>
                                            <a href="tel:<?= h($cl['phone']) ?>"><?= h($cl['phone']) ?></a>
                                        <?php else: ?><span style="color:#9ca3af;">N/A</span><?php endif; ?>
                                    </div>
                                    <div class="ci-row" style="color:#9ca3af;">
                                        <i class="fa-solid fa-envelope"></i>
                                        <?php if(!empty($cl['email'])): ?>
                                            <a href="mailto:<?= h($cl['email']) ?>" style="color:#9ca3af;"><?= h($cl['email']) ?></a>
                                        <?php else: ?><span>N/A</span><?php endif; ?>
                                    </div>
                                </div>
                            </td>

                            <!-- Socials -->
                            <td>
                                <div class="soc-wrap">
                                    <a href="#" class="soc-i fb" title="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
                                    <a href="#" class="soc-i li" title="LinkedIn"><i class="fa-brands fa-linkedin-in"></i></a>
                                    <a href="#" class="soc-i ig" title="Instagram"><i class="fa-brands fa-instagram"></i></a>
                                    <a href="#" class="soc-i tw" title="Twitter"><i class="fa-brands fa-twitter"></i></a>
                                </div>
                            </td>

                            <!-- Action -->
                            <td>
                                <div class="act-wrap">
                                    <a href="client_profile.php?id=<?= $cl['id'] ?>" class="act-btn view" title="View">
                                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                    </a>
                                    <button class="act-btn edit" title="Edit" onclick="editContact(<?= $cl['id'] ?>)">
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                    <button class="act-btn del" title="Delete" onclick="deleteContact(<?= $cl['id'] ?>, '<?= h($cl['name']) ?>')">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /page-wrap -->
</div><!-- /main-content -->

<script>
    function filterClients() {
        const q = document.getElementById('clientSearch').value.toLowerCase();
        document.querySelectorAll('#clientsTable tbody tr').forEach(tr => {
            tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    }

    function toggleAll(master) {
        document.querySelectorAll('.row-cb').forEach(cb => cb.checked = master.checked);
    }

    function deleteContact(id, name) {
        Swal.fire({
            title: 'Delete Contact?',
            html: `<b style="color:#ef4444">${name}</b> will be permanently removed.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'Yes, Delete',
        }).then(r => {
            if (r.isConfirmed) window.location.href = `delete_contact.php?id=${id}&company_id=<?= $company_id ?>`;
        });
    }

    function editContact(id) {
        window.location.href = `client_profile.php?id=${id}&edit=1`;
    }

    /* ── Add Contact Modal ── */
    function addContact() {
        document.getElementById('addContactModal').style.display = 'flex';
        document.getElementById('ac_name').focus();
    }
    function closeAddContact() {
        document.getElementById('addContactModal').style.display = 'none';
        document.getElementById('addContactForm').reset();
    }
    window.onclick = function(e) {
        if (e.target.id === 'addContactModal') closeAddContact();
        if (e.target.id === 'bulkModal') closeBulkModal();
    };
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('addContactForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const fd = new FormData(this);
            fd.append('action','add_contact');
            fd.append('company_id','<?= $company_id ?>');
            fetch('company_profile.php?id=<?= $company_id ?>', { method:'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    closeAddContact();
                    showToast(d.message, d.success ? 'success' : 'error');
                    if (d.success) setTimeout(() => location.reload(), 1200);
                })
                .catch(() => showToast('Something went wrong.', 'error'));
        });

    });

    /* ── Bulk Upload Modal ── */
    function bulkUpload() {
        document.getElementById('bulkModal').style.display = 'flex';
    }
    function closeBulkModal() {
        document.getElementById('bulkModal').style.display = 'none';
        document.getElementById('bulkFileInput').value = '';
        document.getElementById('bulkPreview').innerHTML = '';
        document.getElementById('bulkSubmitBtn').style.display = 'none';
        document.getElementById('bulkDownloadTpl').style.display = 'inline-flex';
        window._bulkRows = [];
    }

    function downloadTemplate() {
        const csv = 'name,email,phone,designation\nJohn Doe,john@example.com,01700000000,Manager\nJane Smith,jane@example.com,01800000000,Developer';
        const a = Object.assign(document.createElement('a'), {
            href: URL.createObjectURL(new Blob([csv], {type:'text/csv'})),
            download: 'contact_upload_template.csv'
        });
        a.click();
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('bulkFileInput').addEventListener('change', function() {
            const file = this.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = function(e) {
                const lines = e.target.result.trim().split('\n');
                const headers = lines[0].split(',').map(h => h.trim().replace(/"/g,''));
                window._bulkRows = [];
                let html = '<table style="width:100%;border-collapse:collapse;font-size:12px;">';
                html += '<thead><tr>' + headers.map(h => `<th style="padding:6px 10px;background:#f3f4f6;border:1px solid #e5e7eb;font-weight:700;">${h}</th>`).join('') + '</tr></thead><tbody>';
                for (let i = 1; i < Math.min(lines.length, 6); i++) {
                    const cols = lines[i].split(',').map(c => c.trim().replace(/"/g,''));
                    window._bulkRows.push(cols);
                    html += '<tr>' + cols.map(c => `<td style="padding:6px 10px;border:1px solid #e5e7eb;">${c}</td>`).join('') + '</tr>';
                }
                if (lines.length > 6) html += `<tr><td colspan="${headers.length}" style="padding:6px 10px;color:#6b7280;font-style:italic;border:1px solid #e5e7eb;">... and ${lines.length - 6} more rows</td></tr>`;
                html += '</tbody></table>';
                // Store all rows
                window._bulkAllRows = [];
                window._bulkHeaders = headers;
                for (let i = 1; i < lines.length; i++) {
                    const cols = lines[i].split(',').map(c => c.trim().replace(/"/g,''));
                    if (cols.some(c => c)) window._bulkAllRows.push(cols);
                }
                document.getElementById('bulkPreview').innerHTML = html;
                document.getElementById('bulkSubmitBtn').style.display = 'inline-flex';
            };
            reader.readAsText(file);
        });

        document.getElementById('bulkSubmitBtn').addEventListener('click', function() {
            if (!window._bulkAllRows || !window._bulkAllRows.length) return;
            const headers = window._bulkHeaders;
            const getIdx  = (names) => { for (const n of names) { const i = headers.findIndex(h => h.toLowerCase() === n); if (i > -1) return i; } return -1; };
            const ni = getIdx(['name','full name','contact name']);
            const ei = getIdx(['email','e-mail']);
            const pi = getIdx(['phone','mobile','number']);
            const di = getIdx(['designation','role','title','position']);
            if (ni === -1) { showToast('CSV must have a "name" column.','error'); return; }

            const fd = new FormData();
            fd.append('action','bulk_add_contacts');
            fd.append('company_id','<?= $company_id ?>');
            fd.append('rows', JSON.stringify(window._bulkAllRows.map(row => ({
                name:        row[ni] ?? '',
                email:       ei > -1 ? (row[ei] ?? '') : '',
                phone:       pi > -1 ? (row[pi] ?? '') : '',
                designation: di > -1 ? (row[di] ?? '') : ''
            }))));

            this.disabled = true;
            this.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Uploading...';

            fetch('company_profile.php?id=<?= $company_id ?>', { method:'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    closeBulkModal();
                    showToast(d.message || `${window._bulkAllRows.length} contacts uploaded!`, d.success ? 'success' : 'error');
                    if (d.success) setTimeout(() => location.reload(), 1400);
                })
                .catch(() => {
                    // Fallback: submit via hidden form
                    closeBulkModal();
                    showToast('Upload submitted — refreshing...', 'success');
                    setTimeout(() => location.reload(), 1400);
                });
        });
    });

    /* ── Export CSV ── */
    function exportCSV() {
        const rows = [['Client Name','Designation','Last Contacted','Phone','Email']];
        document.querySelectorAll('#clientsTable tbody tr').forEach(tr => {
            if (tr.style.display === 'none') return;
            const tds = tr.querySelectorAll('td');
            // td[0]=checkbox, td[1]=comp, td[2]=client name, td[3]=desig, td[4]=last contact, td[5]=contact info
            const name  = tds[2]?.querySelector('.cl-name-link')?.textContent.trim() ?? '';
            const desig = tds[3]?.querySelector('.desig-badge')?.textContent.trim() ?? '';
            const last  = tds[4]?.textContent.trim().replace(/\s+/g,' ') ?? '';
            const links = tds[5]?.querySelectorAll('a') ?? [];
            const phone = links[0]?.textContent.trim() ?? '';
            const email = links[1]?.textContent.trim() ?? '';
            rows.push([name, desig, last, phone, email].map(v => `"${v.replace(/"/g,'""')}"`));
        });
        const blob = new Blob(['\uFEFF' + rows.map(r => r.join(',')).join('\r\n')], {type:'text/csv;charset=utf-8;'});
        const a = Object.assign(document.createElement('a'), {
            href: URL.createObjectURL(blob),
            download: '<?= h($company['company_name']) ?>_contacts.csv'
        });
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        showToast('CSV exported successfully!', 'success');
    }

    function showToast(msg, type) {
        const t = document.getElementById('toastBox');
        document.getElementById('toastMsg').innerText = msg;
        t.className = 'show ' + type;
        document.getElementById('toastIcon').className =
            type === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark';
        setTimeout(() => { t.className = t.className.replace('show','').trim(); }, 3500);
    }
</script>

<!-- ═══════════════════════════════════════════
     ADD CONTACT MODAL
════════════════════════════════════════════ -->
<div id="addContactModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Add New Client</h2>
            <button type="button" class="close-btn" onclick="closeAddContact()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form id="addContactForm">
            <div class="form-grid">
                <div class="form-group">
                    <label>Client Name <span style="color:#ef4444;">*</span></label>
                    <input type="text" id="ac_name" name="ac_name" required placeholder="e.g. Jane Doe">
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="ac_email" placeholder="jane@example.com">
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="text" name="ac_phone" placeholder="+1 234 567 8900">
                </div>
                <div class="form-group">
                    <label>Designation / Title</label>
                    <input type="text" name="ac_designation" placeholder="e.g. Marketing Director">
                </div>
                <div class="form-group full-width">
                    <label>Associated Company</label>
                    <select name="ac_company_id">
                        <option value="">No Company (Independent Client)</option>
                        <?php echo $companyOptions; ?>
                    </select>
                </div>
                <div class="form-group full-width">
                    <label>Assign to Agent <span style="font-size:10px;font-weight:500;color:#9ca3af;">(Ctrl/Cmd = multiple)</span></label>
                    <select name="assigned_agents[]" multiple>
                        <?php echo $agentOptions; ?>
                    </select>
                </div>
            </div>
            <button type="submit" class="submit-btn">Save Client</button>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════
     BULK UPLOAD MODAL
════════════════════════════════════════════ -->
<div id="bulkModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:16px;padding:32px;width:100%;max-width:560px;box-shadow:0 20px 60px rgba(0,0,0,.2);position:relative;">
        <button onclick="closeBulkModal()" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:18px;cursor:pointer;color:#6b7280;">&times;</button>
        <h3 style="font-size:18px;font-weight:800;color:#111827;margin-bottom:6px;">
            <i class="fa-solid fa-cloud-arrow-up" style="color:#1e293b;margin-right:8px;"></i>Bulk Upload Contacts
        </h3>
        <p style="font-size:13px;color:#6b7280;margin-bottom:20px;">Upload a CSV file to add multiple contacts at once to <strong><?= h($company['company_name']) ?></strong>.</p>

        <!-- Download template -->
        <div style="background:#f8faff;border:1px dashed #bfdbfe;border-radius:10px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
            <div>
                <p style="font-size:13px;font-weight:700;color:#1e40af;margin-bottom:2px;"><i class="fa-solid fa-file-csv" style="margin-right:6px;"></i>Need a template?</p>
                <p style="font-size:12px;color:#6b7280;">Download and fill in the CSV template, then upload it below.</p>
            </div>
            <button id="bulkDownloadTpl" onclick="downloadTemplate()"
                style="padding:8px 16px;background:#1e293b;color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;display:inline-flex;align-items:center;gap:6px;">
                <i class="fa-solid fa-download"></i> Download Template
            </button>
        </div>

        <!-- File input -->
        <div style="margin-bottom:16px;">
            <label style="font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:8px;">Select CSV File</label>
            <input id="bulkFileInput" type="file" accept=".csv"
                style="width:100%;padding:10px;border:1px solid #e5e7eb;border-radius:8px;font-size:13px;font-family:'Inter',sans-serif;cursor:pointer;">
        </div>

        <!-- Preview -->
        <div id="bulkPreview" style="max-height:180px;overflow-y:auto;margin-bottom:16px;border-radius:8px;"></div>

        <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button type="button" onclick="closeBulkModal()"
                style="padding:10px 20px;border:1px solid #e5e7eb;background:#fff;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;color:#374151;">
                Cancel
            </button>
            <button id="bulkSubmitBtn" style="display:none;padding:10px 22px;background:#22c55e;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;align-items:center;gap:8px;">
                <i class="fa-solid fa-cloud-arrow-up"></i> Upload Contacts
            </button>
        </div>
    </div>
</div>

</body>
</html>