<?php
// ========================================================================
// INITIALIZATION & SECURITY CHECK
// ========================================================================
session_start();
@include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$toastMessage = "";
$toastType    = "";
$currentRole  = $_SESSION['role'] ?? '';
$uid          = $_SESSION['user_id'];

// ========================================================================
// POST HANDLERS
// ========================================================================

// A. Update basic profile (all roles)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (isset($conn)) {
        $name  = mysqli_real_escape_string($conn, trim($_POST['name']  ?? ''));
        $email = mysqli_real_escape_string($conn, trim($_POST['email'] ?? ''));
        $phone = mysqli_real_escape_string($conn, trim($_POST['phone'] ?? ''));
        $sql   = "UPDATE users SET name='$name', email='$email', phone='$phone' WHERE id='$uid'";
        if (mysqli_query($conn, $sql)) {
            $_SESSION['name'] = $name;
            $toastMessage = "Profile updated successfully!";
            $toastType    = "success";
        } else {
            $toastMessage = "Failed to update profile.";
            $toastType    = "error";
        }
    }
}

// B. Change password (all roles)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if (isset($conn)) {
        $cur  = $_POST['current_password']  ?? '';
        $new  = $_POST['new_password']      ?? '';
        $conf = $_POST['confirm_password']  ?? '';

        $uq = mysqli_query($conn, "SELECT password FROM users WHERE id='$uid'");
        $ud = $uq ? mysqli_fetch_assoc($uq) : null;

        if (!$ud || !password_verify($cur, $ud['password'])) {
            $toastMessage = "Current password is incorrect!";
            $toastType    = "error";
        } elseif (strlen($new) < 6) {
            $toastMessage = "New password must be at least 6 characters!";
            $toastType    = "error";
        } elseif ($new !== $conf) {
            $toastMessage = "New passwords do not match!";
            $toastType    = "error";
        } else {
            $hashed = password_hash($new, PASSWORD_DEFAULT);
            if (mysqli_query($conn, "UPDATE users SET password='$hashed' WHERE id='$uid'")) {
                $toastMessage = "Password changed successfully!";
                $toastType    = "success";
            } else {
                $toastMessage = "Failed to change password.";
                $toastType    = "error";
            }
        }
    }
}

// ========================================================================
// FETCH CURRENT USER DATA
// ========================================================================
$user = [];
if (isset($conn)) {
    $uq = mysqli_query($conn, "SELECT * FROM users WHERE id='$uid'");
    if ($uq && mysqli_num_rows($uq) > 0) {
        $user = mysqli_fetch_assoc($uq);
    }
}
// Fallbacks from session
if (empty($user)) {
    $user = [
        'id'          => $uid,
        'name'        => $_SESSION['name']     ?? '',
        'username'    => $_SESSION['username'] ?? '',
        'email'       => '',
        'phone'       => '',
        'role'        => $currentRole,
        'designation' => '',
        'status'      => 'active',
        'created_at'  => '',
    ];
}

// ── Role-specific stats ──────────────────────────────────────────────────
$stats = [];
$recentActivity = [];

if (isset($conn)) {
    $uSafe = mysqli_real_escape_string($conn, $user['username'] ?? '');
    $nSafe = mysqli_real_escape_string($conn, $user['name']     ?? '');

    if ($currentRole === 'agent') {
        // Agent stats
        $r = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT
                (SELECT COUNT(*) FROM tasks    WHERE assigned_to='$uSafe' OR assigned_to='$nSafe') AS total_tasks,
                (SELECT COUNT(*) FROM tasks    WHERE (assigned_to='$uSafe' OR assigned_to='$nSafe') AND status='Done') AS done_tasks,
                (SELECT COUNT(*) FROM tasks    WHERE (assigned_to='$uSafe' OR assigned_to='$nSafe') AND status='In-Progress') AS active_tasks,
                (SELECT COUNT(*) FROM campaigns WHERE assigned_to='$uSafe' OR assigned_to='$nSafe') AS campaigns,
                (SELECT COUNT(*) FROM deals    WHERE sales_officer='$uSafe' OR sales_officer='$nSafe') AS deals,
                (SELECT COUNT(*) FROM companies WHERE assigned_agent='$uSafe' OR assigned_agent='$nSafe') AS companies,
                (SELECT COUNT(*) FROM contacts WHERE FIND_IN_SET('$uSafe', assigned_agents) OR FIND_IN_SET('$nSafe', assigned_agents)) AS contacts
            "
        ));
        $stats = $r ?? [];

    } elseif ($currentRole === 'manager') {
        $r = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT
                (SELECT COUNT(*) FROM tasks WHERE assigned_by='$nSafe' OR assigned_by='$uSafe') AS tasks_assigned,
                (SELECT COUNT(*) FROM tasks WHERE (assigned_by='$nSafe' OR assigned_by='$uSafe') AND status='Done') AS tasks_done,
                (SELECT COUNT(*) FROM deals) AS total_deals,
                (SELECT COUNT(*) FROM companies) AS total_companies,
                (SELECT COUNT(*) FROM users WHERE role='agent' AND status='active') AS agents_count,
                (SELECT COUNT(*) FROM campaigns) AS campaigns
            "
        ));
        $stats = $r ?? [];

    } elseif (in_array($currentRole, ['admin', 'super_admin'])) {
        $r = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT
                (SELECT COUNT(*) FROM users WHERE status='active') AS active_users,
                (SELECT COUNT(*) FROM users) AS total_users,
                (SELECT COUNT(*) FROM companies) AS total_companies,
                (SELECT COUNT(*) FROM deals) AS total_deals,
                (SELECT COUNT(*) FROM campaigns) AS total_campaigns,
                (SELECT COUNT(*) FROM tasks) AS total_tasks,
                (SELECT COUNT(*) FROM contacts) AS total_contacts
            "
        ));
        $stats = $r ?? [];
    }

    // Recent activity logs (last 5)
    $tbl = mysqli_query($conn, "SHOW TABLES LIKE 'activity_logs'");
    if ($tbl && mysqli_num_rows($tbl) > 0) {
        $aq = mysqli_query($conn,
            "SELECT action, description, entity_type, timestamp
             FROM activity_logs
             WHERE user_id='$uid'
             ORDER BY timestamp DESC LIMIT 5"
        );
        if ($aq) while ($row = mysqli_fetch_assoc($aq)) $recentActivity[] = $row;
    }
}

// ── Helpers ──────────────────────────────────────────────────────────────
$roleMeta = [
    'super_admin' => ['Super Admin',  '#8b5cf6', '#f5f3ff', 'fa-crown'],
    'admin'       => ['Admin',        '#3b82f6', '#eff6ff', 'fa-shield-halved'],
    'manager'     => ['Manager',      '#f59e0b', '#fffbeb', 'fa-user-tie'],
    'agent'       => ['Agent',        '#10b981', '#f0fdf4', 'fa-headset'],
];
$rm = $roleMeta[$currentRole] ?? ['User', '#6b7280', '#f9fafb', 'fa-user'];

$nameParts = explode(' ', trim($user['name']));
$initials  = strtoupper(substr($nameParts[0], 0, 1));
if (count($nameParts) > 1) $initials .= strtoupper(substr($nameParts[count($nameParts)-1], 0, 1));

$memberSince = !empty($user['created_at']) ? date('M Y', strtotime($user['created_at'])) : '—';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile — Systellio CRM</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f3f4f6;
            display: flex; height: 100vh; overflow: hidden;
            color: #111827;
            transition: background .3s, color .3s;
        }

        /* ── Toast ── */
        #toastBox { display: none; min-width: 260px; background: #333; color: #fff; text-align: center;
            border-radius: 8px; padding: 14px 18px; position: fixed; z-index: 9999; right: 24px; top: 24px;
            font-size: 13px; font-weight: 600; box-shadow: 0 4px 16px rgba(0,0,0,.18);
            align-items: center; gap: 10px; transform: translateX(120%);
            transition: transform .4s cubic-bezier(.68,-.55,.265,1.55); }
        #toastBox.show  { display: flex; transform: translateX(0); }
        #toastBox.success { background: #10b981; }
        #toastBox.error   { background: #ef4444; }

        /* ── Layout ── */
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; }
        .profile-body { padding: 28px 30px 48px; }

        /* ── Page header ── */
        .page-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 26px; flex-wrap: wrap; gap: 12px;
        }
        .page-header-left h1 { font-size: 22px; font-weight: 800; color: #0f172a; letter-spacing: -.4px; }
        .page-header-left p  { font-size: 12px; color: #64748b; margin-top: 3px; }
        .settings-link {
            display: inline-flex; align-items: center; gap: 8px;
            background: #fff; border: 1px solid #e5e7eb; border-radius: 10px;
            padding: 9px 16px; font-size: 12px; font-weight: 700; color: #374151;
            text-decoration: none; transition: all .2s;
        }
        .settings-link:hover { background: #f8fafc; border-color: #3b82f6; color: #3b82f6; }
        .settings-link i { font-size: 13px; }

        /* ── Profile card (hero) ── */
        .profile-hero {
            background: #fff; border: 1px solid #e5e7eb; border-radius: 16px;
            padding: 28px 30px; margin-bottom: 20px;
            display: flex; align-items: center; gap: 24px;
            position: relative; overflow: hidden;
        }
        .profile-hero::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
            background: linear-gradient(90deg, <?php echo $rm[1]; ?>, <?php echo $rm[1]; ?>88);
        }
        .hero-avatar {
            width: 80px; height: 80px; border-radius: 50%; flex-shrink: 0;
            background: linear-gradient(135deg, <?php echo $rm[1]; ?>, <?php echo $rm[1]; ?>bb);
            color: #fff; font-size: 28px; font-weight: 800;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 14px <?php echo $rm[1]; ?>44;
        }
        .hero-info { flex: 1; min-width: 0; }
        .hero-name { font-size: 20px; font-weight: 800; color: #0f172a; margin-bottom: 4px; }
        .hero-username { font-size: 12px; color: #94a3b8; font-weight: 500; margin-bottom: 8px; }
        .hero-role-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: <?php echo $rm[2]; ?>; color: <?php echo $rm[1]; ?>;
            padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700;
        }
        .hero-meta { display: flex; gap: 20px; margin-top: 12px; flex-wrap: wrap; }
        .hero-meta-item { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #6b7280; }
        .hero-meta-item i { font-size: 11px; color: #94a3b8; }
        .hero-status { margin-left: auto; text-align: right; flex-shrink: 0; }
        .status-dot {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 11px; font-weight: 700; color: #10b981;
        }
        .status-dot::before {
            content: ''; width: 8px; height: 8px; border-radius: 50%;
            background: #10b981; display: block;
            box-shadow: 0 0 0 2px #dcfce7;
        }
        .hero-member { font-size: 11px; color: #94a3b8; margin-top: 4px; }

        /* ── Grid layout ── */
        .profile-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .profile-grid.three-col { grid-template-columns: 1fr 1fr 1fr; }
        .col-span-2 { grid-column: span 2; }
        .col-span-3 { grid-column: span 3; }

        /* ── Panel card ── */
        .pcard {
            background: #fff; border: 1px solid #e5e7eb; border-radius: 14px;
            padding: 22px 24px; display: flex; flex-direction: column; gap: 0;
        }
        .pcard-title {
            font-size: 13px; font-weight: 700; color: #0f172a;
            margin-bottom: 18px; display: flex; align-items: center; gap: 8px;
        }
        .pcard-title i { color: <?php echo $rm[1]; ?>; font-size: 14px; }

        /* ── Form fields ── */
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .form-row.one-col { grid-template-columns: 1fr; }
        .form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
        .form-group:last-of-type { margin-bottom: 0; }
        .form-label { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .5px; }
        .form-input {
            padding: 10px 14px; border: 1px solid #e5e7eb; border-radius: 8px;
            font-size: 13px; font-weight: 500; color: #111827;
            background: #fff; outline: none; transition: border .2s, box-shadow .2s;
            font-family: 'Inter', sans-serif;
        }
        .form-input:focus { border-color: <?php echo $rm[1]; ?>; box-shadow: 0 0 0 3px <?php echo $rm[1]; ?>18; }
        .form-input[readonly] { background: #f8fafc; color: #64748b; cursor: not-allowed; }
        .form-input.pass-field { letter-spacing: .1em; }

        .form-hint { font-size: 10px; color: #94a3b8; margin-top: 2px; }

        /* ── Submit button ── */
        .btn-save {
            display: inline-flex; align-items: center; gap: 8px;
            background: <?php echo $rm[1]; ?>; color: #fff;
            border: none; border-radius: 10px; padding: 11px 22px;
            font-size: 13px; font-weight: 700; cursor: pointer;
            transition: opacity .2s, transform .15s;
            margin-top: 18px; font-family: 'Inter', sans-serif;
        }
        .btn-save:hover { opacity: .88; transform: translateY(-1px); }
        .btn-save-outline {
            background: #fff; color: <?php echo $rm[1]; ?>;
            border: 1.5px solid <?php echo $rm[1]; ?>;
        }
        .btn-save-outline:hover { background: <?php echo $rm[2]; ?>; opacity: 1; }

        /* ── Stats strip ── */
        .stats-strip {
            display: grid; gap: 12px;
            grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
            margin-bottom: 20px;
        }
        .stat-card {
            background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
            padding: 14px 16px; display: flex; flex-direction: column; gap: 4px;
            position: relative; overflow: hidden;
        }
        .stat-card::before {
            content: ''; position: absolute; top: 0; left: 0; bottom: 0; width: 3px;
            border-radius: 12px 0 0 12px;
        }
        <?php
        $statColors = ['#3b82f6','#10b981','#f59e0b','#8b5cf6','#f43f5e','#06b6d4','#ec4899'];
        for ($i = 0; $i < 7; $i++) {
            echo ".stat-c{$i}::before { background: {$statColors[$i]}; }\n";
            echo ".stat-c{$i} .stat-icon { color: {$statColors[$i]}; background: {$statColors[$i]}18; }\n";
        }
        ?>
        .stat-icon {
            width: 30px; height: 30px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; margin-bottom: 6px;
        }
        .stat-val  { font-size: 22px; font-weight: 800; color: #0f172a; line-height: 1; }
        .stat-lbl  { font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .5px; }

        /* ── Info list ── */
        .info-list { display: flex; flex-direction: column; gap: 0; }
        .info-row {
            display: flex; align-items: center; justify-content: space-between;
            padding: 11px 0; border-bottom: 1px solid #f8fafc; gap: 12px;
        }
        .info-row:last-child { border-bottom: none; }
        .info-key { font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .5px; flex-shrink: 0; min-width: 100px; }
        .info-val { font-size: 13px; font-weight: 600; color: #374151; text-align: right; word-break: break-all; }
        .info-badge {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px;
        }

        /* ── Activity log ── */
        .act-row {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 11px 0; border-bottom: 1px solid #f8fafc;
        }
        .act-row:last-child { border-bottom: none; }
        .act-icon {
            width: 32px; height: 32px; border-radius: 8px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; font-size: 12px;
        }
        .act-body { flex: 1; min-width: 0; }
        .act-desc { font-size: 12px; font-weight: 600; color: #374151; line-height: 1.4; }
        .act-meta { font-size: 10px; color: #94a3b8; margin-top: 2px; }
        .act-empty { text-align: center; padding: 20px 0; color: #cbd5e1; font-size: 12px; }
        .act-empty i { font-size: 24px; display: block; margin-bottom: 6px; }

        /* ── Divider ── */
        .section-divider { height: 1px; background: #f1f5f9; margin: 18px 0; }

        /* ── Password strength ── */
        .pass-strength { height: 4px; border-radius: 99px; background: #f1f5f9; margin-top: 6px; overflow: hidden; }
        .pass-strength-bar { height: 100%; border-radius: 99px; width: 0; transition: width .3s, background .3s; }

        /* ── Dark mode ── */
        body.dark-mode { background: #0f172a; color: #f8fafc; }
        body.dark-mode .main-content { background: #0f172a; }
        body.dark-mode .profile-hero, body.dark-mode .pcard, body.dark-mode .stat-card { background: #1e293b; border-color: #334155; }
        body.dark-mode .hero-name, body.dark-mode .pcard-title, body.dark-mode .stat-val { color: #f8fafc; }
        body.dark-mode .page-header-left h1 { color: #f8fafc; }
        body.dark-mode .form-input { background: #0f172a; border-color: #334155; color: #f8fafc; }
        body.dark-mode .form-input[readonly] { background: #162033; color: #64748b; }
        body.dark-mode .form-input:focus { border-color: <?php echo $rm[1]; ?>; }
        body.dark-mode .info-row { border-color: #1e293b; }
        body.dark-mode .info-val { color: #cbd5e1; }
        body.dark-mode .act-row { border-color: #1e293b; }
        body.dark-mode .act-desc { color: #cbd5e1; }
        body.dark-mode .section-divider { background: #1e293b; }
        body.dark-mode .settings-link { background: #1e293b; border-color: #334155; color: #94a3b8; }
        body.dark-mode .settings-link:hover { border-color: <?php echo $rm[1]; ?>; color: <?php echo $rm[1]; ?>; }
        body.dark-mode .stat-lbl { color: #64748b; }

        /* ================================================================
           RESPONSIVE
        ================================================================ */
        @media (max-width: 900px) {
            .profile-grid { grid-template-columns: 1fr; }
            .profile-grid.three-col { grid-template-columns: 1fr; }
            .col-span-2, .col-span-3 { grid-column: span 1; }
        }

        @media (max-width: 640px) {
            body { display: block; height: auto; overflow-x: hidden; }
            .main-content { min-height: 100vh; overflow-y: visible; }
            .profile-body { padding: 16px 14px 40px; }

            /* Sidebar overlay */
            .sidebar { position: fixed; top: 0; left: 0; height: 100vh; z-index: 1100;
                       transform: translateX(-100%); transition: transform .3s ease; margin-left: 0 !important; }
            .sidebar.mobile-open { transform: translateX(0); }
            .sidebar.collapsed  { transform: translateX(-100%); }
            #sidebarOverlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 1090; }
            #sidebarOverlay.show { display: block; }

            .profile-hero { flex-direction: column; align-items: flex-start; gap: 16px; padding: 20px 18px; }
            .hero-status  { margin-left: 0; text-align: left; }
            .hero-name    { font-size: 17px; }
            .hero-avatar  { width: 64px; height: 64px; font-size: 22px; }
            .hero-meta    { gap: 12px; }

            .page-header  { flex-direction: column; align-items: flex-start; gap: 10px; margin-bottom: 18px; }
            .page-header-left h1 { font-size: 18px; }

            .form-row { grid-template-columns: 1fr; }
            .pcard { padding: 16px 16px; }
            .pcard-title { font-size: 12px; margin-bottom: 14px; }

            .stats-strip { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .stat-val  { font-size: 20px; }

            .info-key { min-width: 80px; font-size: 10px; }
            .info-val { font-size: 12px; }

            #toastBox { right: 12px; top: 12px; min-width: 0; width: calc(100vw - 24px); }
        }

        @media (max-width: 380px) {
            .profile-body { padding: 12px 10px 32px; }
            .stats-strip  { grid-template-columns: 1fr 1fr; gap: 8px; }
        }
    </style>
</head>
<body>

<!-- Mobile sidebar overlay -->
<div id="sidebarOverlay" onclick="closeMobileSidebar()"></div>

<!-- Toast -->
<div id="toastBox">
    <i id="toastIcon" class="fa-solid fa-circle-check"></i>
    <span id="toastMsg">Done!</span>
</div>

<?php
// $activePage    = 'settings'; // sidebar-এ settings active থাকবে
$sidebarRole   = $rm[0];
$dashboardFile = match($currentRole) {
    'super_admin' => 'super_admin_dashboard.php',
    'admin'       => 'admin_dashboard.php',
    'manager'     => 'manager_dashboard.php',
    'agent'       => 'agent_dashboard.php',
    default       => 'index.php',
};
include 'sidebar.php';
?>

<div class="main-content">
    <?php
    $topbarTitle = 'My Profile';
    include 'topbar.php';
    ?>

    <div class="profile-body">

        <!-- ── Page Header ── -->
        <div class="page-header">
            <div class="page-header-left">
                <h1>My Profile</h1>
                <p>View and manage your personal information</p>
            </div>
            <?php if ($currentRole !== 'agent'): ?>
            <a href="settings.php" class="settings-link">
                <i class="fa-solid fa-gear"></i> Settings
            </a>
            <?php endif; ?>
        </div>

        <!-- ── Hero Card ── -->
        <div class="profile-hero">
            <div class="hero-avatar"><?php echo htmlspecialchars($initials); ?></div>
            <div class="hero-info">
                <div class="hero-name"><?php echo htmlspecialchars($user['name']); ?></div>
                <div class="hero-username">@<?php echo htmlspecialchars($user['username']); ?></div>
                <div class="hero-role-badge">
                    <i class="fa-solid <?php echo $rm[3]; ?>"></i>
                    <?php echo $rm[0]; ?>
                    <?php if (!empty($user['designation'])): ?>
                    &nbsp;·&nbsp; <?php echo htmlspecialchars($user['designation']); ?>
                    <?php endif; ?>
                </div>
                <div class="hero-meta">
                    <?php if (!empty($user['email'])): ?>
                    <div class="hero-meta-item"><i class="fa-solid fa-envelope"></i><?php echo htmlspecialchars($user['email']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($user['phone'])): ?>
                    <div class="hero-meta-item"><i class="fa-solid fa-phone"></i><?php echo htmlspecialchars($user['phone']); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="hero-status">
                <div class="status-dot"><?php echo ucfirst($user['status'] ?? 'active'); ?></div>
                <div class="hero-member">Member since <?php echo $memberSince; ?></div>
            </div>
        </div>

        <!-- ================================================================
             ROLE-SPECIFIC STATS STRIP
        ================================================================ -->
        <?php if (!empty($stats)): ?>
        <div class="stats-strip">
        <?php

        if ($currentRole === 'agent'):
            $statDefs = [
                ['fa-list-check',    'Total Tasks',    $stats['total_tasks']   ?? 0, 0],
                ['fa-circle-check',  'Done Tasks',     $stats['done_tasks']    ?? 0, 1],
                ['fa-spinner',       'Active Tasks',   $stats['active_tasks']  ?? 0, 2],
                ['fa-bullhorn',      'Campaigns',      $stats['campaigns']     ?? 0, 3],
                ['fa-handshake',     'Deals',          $stats['deals']         ?? 0, 4],
                ['fa-building',      'Companies',      $stats['companies']     ?? 0, 5],
                ['fa-address-book',  'Contacts',       $stats['contacts']      ?? 0, 6],
            ];
        elseif ($currentRole === 'manager'):
            $statDefs = [
                ['fa-paper-plane',   'Tasks Assigned', $stats['tasks_assigned'] ?? 0, 0],
                ['fa-circle-check',  'Tasks Done',     $stats['tasks_done']     ?? 0, 1],
                ['fa-handshake',     'Total Deals',    $stats['total_deals']    ?? 0, 2],
                ['fa-building',      'Companies',      $stats['total_companies']?? 0, 3],
                ['fa-headset',       'Agents',         $stats['agents_count']   ?? 0, 4],
                ['fa-bullhorn',      'Campaigns',      $stats['campaigns']      ?? 0, 5],
            ];
        else:
            $statDefs = [
                ['fa-users',         'Active Users',   $stats['active_users']   ?? 0, 0],
                ['fa-user-group',    'Total Users',    $stats['total_users']    ?? 0, 1],
                ['fa-building',      'Companies',      $stats['total_companies']?? 0, 2],
                ['fa-handshake',     'Deals',          $stats['total_deals']    ?? 0, 3],
                ['fa-bullhorn',      'Campaigns',      $stats['total_campaigns']?? 0, 4],
                ['fa-list-check',    'Tasks',          $stats['total_tasks']    ?? 0, 5],
                ['fa-address-book',  'Contacts',       $stats['total_contacts'] ?? 0, 6],
            ];
        endif;

        foreach ($statDefs as [$icon, $label, $val, $ci]):
        ?>
            <div class="stat-card stat-c<?php echo $ci; ?>">
                <div class="stat-icon"><i class="fa-solid <?php echo $icon; ?>"></i></div>
                <div class="stat-val"><?php echo number_format((int)$val); ?></div>
                <div class="stat-lbl"><?php echo $label; ?></div>
            </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ================================================================
             MAIN GRID
        ================================================================ -->
        <div class="profile-grid">

            <!-- ── LEFT: Edit Profile ── -->
            <div class="pcard">
                <div class="pcard-title"><i class="fa-solid fa-user-pen"></i> Edit Profile</div>
                <form method="POST" action="">
                    <input type="hidden" name="update_profile" value="1">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="name" class="form-input"
                                   value="<?php echo htmlspecialchars($user['name']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-input" readonly
                                   value="<?php echo htmlspecialchars($user['username']); ?>">
                            <span class="form-hint">Username cannot be changed</span>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-input"
                                   value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Phone Number</label>
                            <input type="text" name="phone" class="form-input"
                                   value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>"
                                   placeholder="+880...">
                        </div>
                    </div>
                    <button type="submit" class="btn-save">
                        <i class="fa-solid fa-floppy-disk"></i> Save Changes
                    </button>
                </form>
            </div>

            <!-- ── RIGHT: Account Info ── -->
            <div class="pcard">
                <div class="pcard-title"><i class="fa-solid fa-id-card"></i> Account Information</div>
                <div class="info-list">
                    <div class="info-row">
                        <span class="info-key">Role</span>
                        <span class="info-val">
                            <span class="info-badge" style="background:<?php echo $rm[2]; ?>;color:<?php echo $rm[1]; ?>;">
                                <i class="fa-solid <?php echo $rm[3]; ?>"></i>
                                <?php echo $rm[0]; ?>
                            </span>
                        </span>
                    </div>
                    <?php if (!empty($user['designation'])): ?>
                    <div class="info-row">
                        <span class="info-key">Designation</span>
                        <span class="info-val"><?php echo htmlspecialchars($user['designation']); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <span class="info-key">Status</span>
                        <span class="info-val">
                            <?php if (($user['status'] ?? '') === 'active'): ?>
                            <span class="info-badge" style="background:#dcfce7;color:#15803d;">
                                <i class="fa-solid fa-circle" style="font-size:7px;"></i> Active
                            </span>
                            <?php else: ?>
                            <span class="info-badge" style="background:#fee2e2;color:#b91c1c;">
                                <i class="fa-solid fa-circle" style="font-size:7px;"></i> Inactive
                            </span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-key">User ID</span>
                        <span class="info-val" style="font-family:monospace;">#<?php echo htmlspecialchars($user['id']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-key">Member Since</span>
                        <span class="info-val"><?php echo !empty($user['created_at']) ? date('d M Y', strtotime($user['created_at'])) : '—'; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-key">Email</span>
                        <span class="info-val"><?php echo !empty($user['email']) ? htmlspecialchars($user['email']) : '<span style="color:#94a3b8;">Not set</span>'; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-key">Phone</span>
                        <span class="info-val"><?php echo !empty($user['phone']) ? htmlspecialchars($user['phone']) : '<span style="color:#94a3b8;">Not set</span>'; ?></span>
                    </div>
                </div>
            </div>

            <!-- ── BOTTOM LEFT: Change Password ── -->
            <div class="pcard">
                <div class="pcard-title"><i class="fa-solid fa-lock"></i> Change Password</div>
                <form method="POST" action="" id="passForm">
                    <input type="hidden" name="change_password" value="1">
                    <div class="form-group">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-input pass-field"
                               placeholder="Enter current password" required autocomplete="current-password">
                    </div>
                    <div class="form-group">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" id="newPass" class="form-input pass-field"
                               placeholder="Minimum 6 characters" required autocomplete="new-password"
                               oninput="checkStrength(this.value)">
                        <div class="pass-strength"><div class="pass-strength-bar" id="strengthBar"></div></div>
                        <span class="form-hint" id="strengthHint"></span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" id="confPass" class="form-input pass-field"
                               placeholder="Repeat new password" required autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn-save btn-save-outline">
                        <i class="fa-solid fa-key"></i> Update Password
                    </button>
                </form>
            </div>

            <!-- ── BOTTOM RIGHT: Recent Activity OR Role-specific extra panel ── -->
            <div class="pcard">
                <?php if (!empty($recentActivity)): ?>
                <div class="pcard-title"><i class="fa-solid fa-clock-rotate-left"></i> Recent Activity</div>
                <?php
                $actColors = ['CREATE'=>['#dcfce7','#15803d','fa-plus'],'UPDATE'=>['#dbeafe','#1d4ed8','fa-pen'],'DELETE'=>['#fee2e2','#b91c1c','fa-trash'],'LOGIN'=>['#f0fdf4','#166534','fa-right-to-bracket'],'LOGOUT'=>['#f8fafc','#64748b','fa-right-from-bracket'],'VIEW'=>['#fef3c7','#92400e','fa-eye']];
                foreach ($recentActivity as $act):
                    $ac = $actColors[strtoupper($act['action'])] ?? ['#f3f4f6','#374151','fa-bolt'];
                ?>
                <div class="act-row">
                    <div class="act-icon" style="background:<?php echo $ac[0]; ?>;color:<?php echo $ac[1]; ?>;">
                        <i class="fa-solid <?php echo $ac[2]; ?>"></i>
                    </div>
                    <div class="act-body">
                        <div class="act-desc"><?php echo htmlspecialchars($act['description']); ?></div>
                        <div class="act-meta">
                            <?php echo htmlspecialchars($act['entity_type']); ?> &nbsp;·&nbsp;
                            <?php echo date('d M, h:i A', strtotime($act['timestamp'])); ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="pcard-title"><i class="fa-solid fa-clock-rotate-left"></i> Recent Activity</div>
                <div class="act-empty">
                    <i class="fa-solid fa-timeline"></i>
                    No recent activity recorded yet.
                </div>

                <!-- Role-specific quick links when no activity -->
                <div class="section-divider"></div>
                <div class="pcard-title" style="margin-top:4px;"><i class="fa-solid fa-bolt"></i> Quick Access</div>
                <div style="display:flex;flex-direction:column;gap:8px;">
                    <?php if ($currentRole === 'agent'): ?>
                    <a href="task_manager.php"  style="<?php echo quickLinkStyle($rm[1],$rm[2]); ?>"><i class="fa-solid fa-list-check"></i> My Tasks</a>
                    <a href="deal_pipeline.php" style="<?php echo quickLinkStyle($rm[1],$rm[2]); ?>"><i class="fa-solid fa-handshake"></i> My Deals</a>
                    <a href="campaigns.php"     style="<?php echo quickLinkStyle($rm[1],$rm[2]); ?>"><i class="fa-solid fa-bullhorn"></i> My Campaigns</a>
                    <?php elseif ($currentRole === 'manager'): ?>
                    <a href="task_manager.php"  style="<?php echo quickLinkStyle($rm[1],$rm[2]); ?>"><i class="fa-solid fa-list-check"></i> Task Manager</a>
                    <a href="deal_pipeline.php" style="<?php echo quickLinkStyle($rm[1],$rm[2]); ?>"><i class="fa-solid fa-handshake"></i> Deal Pipeline</a>
                    <a href="user_list.php"     style="<?php echo quickLinkStyle($rm[1],$rm[2]); ?>"><i class="fa-solid fa-users"></i> User List</a>
                    <?php else: ?>
                    <a href="user_list.php"     style="<?php echo quickLinkStyle($rm[1],$rm[2]); ?>"><i class="fa-solid fa-users"></i> User Management</a>
                    <a href="deal_pipeline.php" style="<?php echo quickLinkStyle($rm[1],$rm[2]); ?>"><i class="fa-solid fa-handshake"></i> Deal Pipeline</a>
                    <a href="analytics_reports.php" style="<?php echo quickLinkStyle($rm[1],$rm[2]); ?>"><i class="fa-solid fa-chart-column"></i> Analytics</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

        </div><!-- /profile-grid -->
    </div><!-- /profile-body -->
</div><!-- /main-content -->

<?php
function quickLinkStyle($color, $bg) {
    return "display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:9px;background:{$bg};color:{$color};font-size:12px;font-weight:700;text-decoration:none;transition:opacity .2s;";
}
?>

<script>
/* ── Toast auto-show on page load (PHP-set) ── */
<?php if (!empty($toastMessage)): ?>
window.addEventListener('DOMContentLoaded', function () {
    showToast(<?php echo json_encode($toastMessage); ?>, <?php echo json_encode($toastType); ?>);
});
<?php endif; ?>

function showToast(msg, type) {
    const box  = document.getElementById('toastBox');
    const icon = document.getElementById('toastIcon');
    const txt  = document.getElementById('toastMsg');
    box.className = 'show ' + (type || 'success');
    icon.className = (type === 'error') ? 'fa-solid fa-circle-xmark' : 'fa-solid fa-circle-check';
    txt.textContent = msg;
    setTimeout(() => { box.className = box.className.replace('show','').trim(); }, 3500);
}

/* ── Password strength indicator ── */
function checkStrength(val) {
    const bar  = document.getElementById('strengthBar');
    const hint = document.getElementById('strengthHint');
    if (!bar) return;
    let score = 0;
    if (val.length >= 6)  score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const levels = [
        [0,   '#e5e7eb', ''],
        [20,  '#ef4444', 'Too short'],
        [40,  '#f59e0b', 'Weak'],
        [60,  '#eab308', 'Fair'],
        [80,  '#10b981', 'Strong'],
        [100, '#059669', 'Very strong'],
    ];
    const [pct, color, label] = levels[Math.min(score, 5)];
    bar.style.width = pct + '%';
    bar.style.background = color;
    hint.textContent = label;
    hint.style.color = color;
}

/* ── Mobile sidebar ── */
function openMobileSidebar() {
    document.getElementById('sidebar')?.classList.add('mobile-open');
    document.getElementById('sidebarOverlay')?.classList.add('show');
    document.body.style.overflow = 'hidden';
}
function closeMobileSidebar() {
    document.getElementById('sidebar')?.classList.remove('mobile-open');
    document.getElementById('sidebarOverlay')?.classList.remove('show');
    document.body.style.overflow = '';
}

document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.getElementById('outerToggle');
    if (toggle) {
        const newToggle = toggle.cloneNode(true);
        toggle.parentNode.replaceChild(newToggle, toggle);
        newToggle.addEventListener('click', function () {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth <= 640) {
                sidebar?.classList.contains('mobile-open') ? closeMobileSidebar() : openMobileSidebar();
            } else {
                sidebar?.classList.toggle('collapsed');
            }
        });
    }
    window.addEventListener('resize', function () {
        if (window.innerWidth > 640) { closeMobileSidebar(); document.body.style.overflow = ''; }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeMobileSidebar();
    });
});
</script>
</body>
</html>