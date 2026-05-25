<?php
session_start();
@include 'config.php';

// Role check: manager only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'manager') {
    header("Location: index.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Dashboard - Systellio CRM</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        @import url('https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&display=swap');

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }

        body {
            background-color: #f3f4f6;
            display: flex; height: 100vh; overflow: hidden;
            transition: background-color 0.3s, color 0.3s; color: #111827;
        }

        /* Toast */
        #toastBox { visibility: hidden; min-width: 250px; background-color: #333; color: #fff; text-align: center; border-radius: 8px; padding: 16px; position: fixed; z-index: 9999; right: 30px; top: 30px; font-size: 14px; font-weight: 600; box-shadow: 0 4px 12px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 10px; transform: translateX(100%); transition: transform 0.4s cubic-bezier(0.68,-0.55,0.265,1.55), visibility 0.4s; }
        #toastBox.show { visibility: visible; transform: translateX(0); }
        #toastBox.success { background-color: #10b981; }
        #toastBox.error   { background-color: #ef4444; }

        /* Layout */
        .main-content { flex-grow: 1; display: flex; flex-direction: column; overflow-y: auto; transition: background-color 0.3s ease; background-color: #f3f4f6; }

        /* Notification panel */
        .notif-wrapper { position: relative; overflow: visible !important; }
        .notif-panel { display: none; position: fixed; top: 70px; right: 20px; width: 340px; background: #ffffff; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,0.18); border: 1px solid #e5e7eb; z-index: 9999; overflow: hidden; }
        .notif-panel.open { display: block; }
        .notif-panel-header { padding: 16px 20px; border-bottom: 1px solid #f3f4f6; display: flex; justify-content: space-between; align-items: center; }
        .notif-panel-header h3 { font-size: 15px; font-weight: 700; color: #111827; }
        .notif-panel-header span { font-size: 11px; color: #6b7280; cursor: pointer; font-weight: 600; }
        .notif-panel-header span:hover { color: #3b82f6; }
        .notif-list { max-height: 360px; overflow-y: auto; }
        .notif-item { display: flex; gap: 14px; padding: 14px 20px; border-bottom: 1px solid #f9fafb; cursor: pointer; transition: background 0.2s; }
        .notif-item:hover { background: #f9fafb; }
        .notif-item:last-child { border-bottom: none; }
        .notif-icon { width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 15px; }
        .notif-body { flex: 1; }
        .notif-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #9ca3af; margin-bottom: 2px; }
        .notif-text { font-size: 13px; font-weight: 500; color: #111827; margin-bottom: 3px; line-height: 1.4; }
        .notif-time { font-size: 11px; color: #9ca3af; font-weight: 500; }
        .notif-empty { padding: 30px 20px; text-align: center; color: #9ca3af; font-size: 13px; }
        .notif-panel-footer { padding: 12px 20px; border-top: 1px solid #f3f4f6; text-align: center; }
        .notif-panel-footer a { font-size: 12px; font-weight: 600; color: #3b82f6; text-decoration: none; }
        .notification-badge { position: absolute; top: -4px; right: -4px; background-color: #ef4444; color: white; font-size: 9px; font-weight: bold; padding: 2px 5px; border-radius: 50%; border: 2px solid #ffffff; }

        /* ── Dashboard overview ── */
        #mainDashboardContent { padding: 28px 30px 36px; transition: background-color 0.3s; }

        .ov-heading { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 24px; }
        .ov-heading-left h1 { font-size: 22px; font-weight: 800; color: #0f172a; letter-spacing: -0.4px; margin-bottom: 2px; }
        .ov-heading-left p  { font-size: 13px; color: #64748b; font-weight: 500; }
        .ov-date-badge { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 7px 14px; font-size: 12px; font-weight: 600; color: #6b7280; display: flex; align-items: center; }

        /* KPI strip — 4 cards for manager */
        .kpi-strip { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 22px; }
        .kpi-card { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 20px 22px; display: flex; flex-direction: column; gap: 6px; position: relative; overflow: hidden; transition: box-shadow 0.2s, transform 0.2s; }
        .kpi-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,0.08); transform: translateY(-2px); }
        .kpi-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; border-radius: 14px 14px 0 0; }
        .kpi-blue::before   { background: linear-gradient(90deg,#3b82f6,#6366f1); }
        .kpi-green::before  { background: linear-gradient(90deg,#10b981,#34d399); }
        .kpi-amber::before  { background: linear-gradient(90deg,#f59e0b,#fbbf24); }
        .kpi-rose::before   { background: linear-gradient(90deg,#f43f5e,#fb7185); }
        .kpi-cyan::before   { background: linear-gradient(90deg,#06b6d4,#22d3ee); }
        .kpi-violet::before { background: linear-gradient(90deg,#8b5cf6,#a78bfa); }

        .kpi-icon { width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; margin-bottom: 4px; }
        .kpi-blue   .kpi-icon { background: #eff6ff; color: #3b82f6; }
        .kpi-green  .kpi-icon { background: #f0fdf4; color: #10b981; }
        .kpi-amber  .kpi-icon { background: #fffbeb; color: #f59e0b; }
        .kpi-rose   .kpi-icon { background: #fff1f2; color: #f43f5e; }
        .kpi-cyan   .kpi-icon { background: #ecfeff; color: #06b6d4; }
        .kpi-violet .kpi-icon { background: #f5f3ff; color: #8b5cf6; }

        .kpi-label { font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.8px; }
        .kpi-value { font-size: 32px; font-weight: 800; color: #0f172a; font-family: 'DM Mono', monospace; line-height: 1; }
        .kpi-value-sm { font-size: 22px; }
        .kpi-sub { font-size: 11px; color: #94a3b8; font-weight: 500; }
        .kpi-sub b { color: #374151; }

        /* Mid row & bottom row panels */
        .ov-mid-row    { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px; }
        .ov-bottom-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
        .ov-panel { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 20px 22px; }
        .ov-panel-title { font-size: 13px; font-weight: 700; color: #374151; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .ov-panel-title i { color: #3b82f6; }

        /* Funnel bars */
        .funnel-row { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .funnel-label { font-size: 11px; font-weight: 600; color: #6b7280; width: 90px; flex-shrink: 0; }
        .funnel-bar-wrap { flex: 1; height: 8px; background: #f1f5f9; border-radius: 99px; overflow: hidden; }
        .funnel-bar { height: 100%; border-radius: 99px; transition: width 0.6s ease; }
        .funnel-count { font-size: 12px; font-weight: 700; color: #374151; width: 24px; text-align: right; flex-shrink: 0; font-family: 'DM Mono', monospace; }
        .funnel-deal-total { display: flex; justify-content: space-between; align-items: center; margin-top: 14px; padding-top: 14px; border-top: 1px solid #f1f5f9; font-size: 12px; }
        .funnel-deal-total span  { color: #94a3b8; font-weight: 500; }
        .funnel-deal-total strong { color: #0f172a; font-weight: 700; font-family: 'DM Mono', monospace; }

        /* Task donut */
        .task-ring-wrap { display: flex; flex-direction: column; align-items: center; gap: 14px; }
        .donut-svg { transform: rotate(-90deg); }
        .donut-bg  { fill: none; stroke: #f1f5f9; }
        .task-ring-legend { display: flex; flex-direction: column; gap: 8px; width: 100%; }
        .trl-row { display: flex; align-items: center; gap: 8px; padding-bottom: 8px; border-bottom: 1px solid #f8fafc; }
        .trl-row:last-child { border-bottom: none; padding-bottom: 0; }
        .trl-dot  { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
        .trl-name { font-size: 12px; font-weight: 600; color: #374151; flex: 1; }
        .trl-num  { font-size: 12px; font-weight: 700; color: #6b7280; font-family: 'DM Mono', monospace; }

        /* Team breakdown */
        .ubl-row { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid #f8fafc; }
        .ubl-row:last-of-type { border-bottom: none; }
        .ubl-avatar { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
        .ubl-info { flex: 1; }
        .ubl-role { font-size: 13px; font-weight: 700; color: #111827; }
        .ubl-stat { font-size: 11px; color: #94a3b8; font-weight: 500; margin-top: 1px; }
        .ubl-count { font-size: 22px; font-weight: 800; color: #374151; font-family: 'DM Mono', monospace; }

        /* Mini tables */
        .mini-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .mini-table th { background: #f8fafc; padding: 8px 10px; font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .5px; text-align: left; border-bottom: 1px solid #f1f5f9; }
        .mini-table td { padding: 10px; border-bottom: 1px solid #f8fafc; color: #374151; font-weight: 500; vertical-align: middle; }
        .mini-table tr:last-child td { border-bottom: none; }
        .mini-deal-name { font-weight: 700; color: #111827; max-width: 130px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .mini-td-title  { font-weight: 600; color: #111827; max-width: 110px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .mini-td-amount { font-family: 'DM Mono', monospace; font-weight: 700; color: #059669; white-space: nowrap; }

        .ov-panel-footer { margin-top: 10px; padding-top: 10px; border-top: 1px solid #f1f5f9; text-align: center; }
        .ov-panel-footer a { font-size: 11px; font-weight: 700; color: #3b82f6; text-decoration: none; }
        .ov-panel-footer a:hover { text-decoration: underline; }
        .ov-empty { text-align: center; padding: 24px 10px; color: #cbd5e1; font-size: 12px; }
        .ov-empty i { font-size: 28px; display: block; margin-bottom: 8px; }

        /* Manager-specific accent banner */
        .manager-banner {
            background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 60%, #1d4ed8 100%);
            border-radius: 14px; padding: 22px 28px;
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 22px; overflow: hidden; position: relative;
        }
        .manager-banner::after {
            content: '';
            position: absolute; right: -40px; top: -40px;
            width: 180px; height: 180px;
            border-radius: 50%;
            background: rgba(59,130,246,0.15);
        }
        .banner-left h2 { font-size: 18px; font-weight: 800; color: #ffffff; margin-bottom: 4px; }
        .banner-left p  { font-size: 12px; color: #93c5fd; font-weight: 500; }
        .banner-right { display: flex; gap: 10px; z-index: 1; }
        .banner-stat { background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.15); border-radius: 10px; padding: 10px 16px; text-align: center; }
        .banner-stat-val { font-size: 22px; font-weight: 800; color: #ffffff; font-family: 'DM Mono', monospace; line-height: 1; }
        .banner-stat-lbl { font-size: 10px; color: #93c5fd; font-weight: 600; text-transform: uppercase; letter-spacing: 0.6px; margin-top: 3px; }

        /* Dark mode */
        body.dark-mode { background-color: #0f172a; color: #f8fafc; }
        body.dark-mode .main-content { background-color: #0f172a; }
        body.dark-mode #mainDashboardContent { background-color: #0f172a; }
        body.dark-mode .ov-heading-left h1 { color: #f8fafc; }
        body.dark-mode .ov-heading-left p  { color: #94a3b8; }
        body.dark-mode .ov-date-badge { background: #1e293b; border-color: #334155; color: #94a3b8; }
        body.dark-mode .kpi-card { background: #1e293b; border-color: #334155; }
        body.dark-mode .kpi-value { color: #f8fafc; }
        body.dark-mode .kpi-label { color: #64748b; }
        body.dark-mode .kpi-sub   { color: #94a3b8; }
        body.dark-mode .kpi-sub b { color: #e2e8f0; }
        body.dark-mode .kpi-blue   .kpi-icon { background: #1e3a5f; }
        body.dark-mode .kpi-green  .kpi-icon { background: #052e16; }
        body.dark-mode .kpi-amber  .kpi-icon { background: #2d1a00; }
        body.dark-mode .kpi-rose   .kpi-icon { background: #2d0a16; }
        body.dark-mode .kpi-cyan   .kpi-icon { background: #082f49; }
        body.dark-mode .kpi-violet .kpi-icon { background: #2e1065; }
        body.dark-mode .ov-panel { background: #1e293b; border-color: #334155; }
        body.dark-mode .ov-panel-title { color: #f8fafc; }
        body.dark-mode .ov-panel-footer { border-color: #334155; }
        body.dark-mode .ov-panel-footer a { color: #60a5fa; }
        body.dark-mode .ov-empty { color: #475569; }
        body.dark-mode .funnel-label  { color: #94a3b8; }
        body.dark-mode .funnel-count  { color: #94a3b8; }
        body.dark-mode .funnel-bar-wrap { background: #0f172a; }
        body.dark-mode .funnel-deal-total { border-color: #334155; }
        body.dark-mode .funnel-deal-total span  { color: #64748b; }
        body.dark-mode .funnel-deal-total strong { color: #f8fafc; }
        body.dark-mode .trl-row  { border-color: #1e293b; }
        body.dark-mode .trl-name { color: #e2e8f0; }
        body.dark-mode .trl-num  { color: #94a3b8; }
        body.dark-mode .ubl-row  { border-color: #1e293b; }
        body.dark-mode .ubl-role { color: #f8fafc; }
        body.dark-mode .ubl-stat { color: #64748b; }
        body.dark-mode .ubl-count { color: #f8fafc; }
        body.dark-mode .mini-table th { background: #0f172a; color: #64748b; border-color: #1e293b; }
        body.dark-mode .mini-table td { border-color: #1e293b; color: #94a3b8; }
        body.dark-mode .mini-deal-name { color: #f8fafc; }
        body.dark-mode .mini-td-title  { color: #f8fafc; }
        body.dark-mode .mini-td-amount { color: #34d399; }
        body.dark-mode .notif-panel { background: #1e293b; border-color: #334155; box-shadow: 0 8px 30px rgba(0,0,0,0.4); }
        body.dark-mode .notif-panel-header { border-color: #334155; }
        body.dark-mode .notif-panel-header h3 { color: #f8fafc; }
        body.dark-mode .notif-item { border-color: #1e293b; }
        body.dark-mode .notif-item:hover { background: #0f172a; }
        body.dark-mode .notif-text { color: #e2e8f0; }
        body.dark-mode .notif-panel-footer { border-color: #334155; }
        body.dark-mode .notification-badge { border-color: #1e293b; }

        @media(max-width:1280px){ .kpi-strip{grid-template-columns:repeat(2,1fr);} .ov-mid-row,.ov-bottom-row{grid-template-columns:1fr 1fr;} }
        @media(max-width:900px) { .kpi-strip{grid-template-columns:repeat(2,1fr);} .ov-mid-row,.ov-bottom-row{grid-template-columns:1fr;} .banner-right{display:none;} }
    </style>
</head>
<body>

<div id="toastBox">
    <i id="toastIcon" class="fa-solid fa-circle-check"></i>
    <span id="toastMsg">Action Successful!</span>
</div>

<?php
$sidebarRole  = 'Manager';
$dashboardFile = 'manager_dashboard.php';
$activePage   = 'dashboard';
include 'sidebar.php';
?>

<div class="main-content">

    <?php include 'topbar.php'; ?>

    <?php
    // ── Data queries ──────────────────────────────────────────────
    $mgr = $_SESSION['username'] ?? '';
    $mgrName = $_SESSION['name'] ?? 'Manager';

    // Safe escape
    $mgrSafe = isset($conn) ? mysqli_real_escape_string($conn, $mgr) : $mgr;

    // Default values
    $ov = [
        'agents'         => 0,
        'active_agents'  => 0,
        'inactive_agents'=> 0,
        'total_tasks'    => 0,
        'tasks_todo'     => 0,
        'tasks_progress' => 0,
        'tasks_done'     => 0,
        'tasks_overdue'  => 0,
        'total_contacts' => 0,
        'total_deals'    => 0,
        'deal_value'     => 0,
        'deals_won'      => 0,
        'deals_lost'     => 0,
        'total_campaigns'=> 0,
        'camp_active'    => 0,
        'camp_planning'  => 0,
        'recent_deals'   => [],
        'recent_tasks'   => [],
        'recent_agents'  => [],
    ];

    if (isset($conn)) {

        // Build team list: manager + agents created by this manager
        $teamUsers = [$mgrSafe];
        $tuq = mysqli_query($conn, "SELECT username FROM users WHERE created_by='$mgrSafe' AND role='agent' AND status='active'");
        if ($tuq) while ($tu = mysqli_fetch_assoc($tuq)) $teamUsers[] = mysqli_real_escape_string($conn, $tu['username']);
        $teamList = implode("','", $teamUsers);

        // Team display names (for tasks queries)
        $teamNames = [];
        $tnq = mysqli_query($conn, "SELECT name FROM users WHERE username IN ('$teamList')");
        if ($tnq) while ($tn = mysqli_fetch_assoc($tnq)) $teamNames[] = mysqli_real_escape_string($conn, $tn['name']);
        $teamNameList = implode("','", $teamNames);

        // 1. Agents count — only agents created by this manager
        $r = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) t, SUM(status='active') a, SUM(status='inactive') i
             FROM users WHERE role='agent' AND created_by='$mgrSafe'"
        ));
        if ($r) {
            $ov['agents']          = (int)$r['t'];
            $ov['active_agents']   = (int)$r['a'];
            $ov['inactive_agents'] = (int)$r['i'];
        }

        // 2. Tasks — manager + their team
        $r2 = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) t,
                    SUM(status='To-Do') td,
                    SUM(status='In-Progress') ip,
                    SUM(status='Done') d,
                    SUM(due_date < CURDATE() AND status != 'Done') ov
             FROM tasks
             WHERE assigned_to IN ('$teamNameList')
                OR assigned_by IN ('$teamNameList')"
        ));
        if ($r2) {
            $ov['total_tasks']    = (int)$r2['t'];
            $ov['tasks_todo']     = (int)$r2['td'];
            $ov['tasks_progress'] = (int)$r2['ip'];
            $ov['tasks_done']     = (int)$r2['d'];
            $ov['tasks_overdue']  = (int)$r2['ov'];
        }

        // 3. Contacts assigned to manager or their team
        $contactConditions = [];
        foreach ($teamUsers as $u) $contactConditions[] = "FIND_IN_SET('" . mysqli_real_escape_string($conn, $u) . "', assigned_agents)";
        $contactWhere = implode(' OR ', $contactConditions);
        $r3 = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM contacts WHERE $contactWhere"));
        if ($r3) $ov['total_contacts'] = (int)$r3['c'];

        // 4. Deals — manager + team
        $r4 = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) t,
                    COALESCE(SUM(deal_value), 0) v,
                    SUM(stage='Won') w,
                    SUM(stage='Lost') l
             FROM deals WHERE sales_officer IN ('$teamList')"
        ));
        if ($r4) {
            $ov['total_deals'] = (int)$r4['t'];
            $ov['deal_value']  = (float)$r4['v'];
            $ov['deals_won']   = (int)$r4['w'];
            $ov['deals_lost']  = (int)$r4['l'];
        }

        // 5. Campaigns — manager + team
        $r5 = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) t, SUM(status='Active') a, SUM(status='Planning') p
             FROM campaigns WHERE assigned_to IN ('$teamList')"
        ));
        if ($r5) {
            $ov['total_campaigns'] = (int)$r5['t'];
            $ov['camp_active']     = (int)$r5['a'];
            $ov['camp_planning']   = (int)$r5['p'];
        }

        // 6. Recent deals — manager + team
        $dq = mysqli_query($conn,
            "SELECT deal_name, deal_value, currency, stage
             FROM deals WHERE sales_officer IN ('$teamList')
             ORDER BY id DESC LIMIT 5"
        );
        if ($dq) while ($row = mysqli_fetch_assoc($dq)) $ov['recent_deals'][] = $row;

        // 7. Recent tasks — manager + team
        $tq = mysqli_query($conn,
            "SELECT title, status, priority, due_date
             FROM tasks
             WHERE assigned_to IN ('$teamNameList')
                OR assigned_by IN ('$teamNameList')
             ORDER BY id DESC LIMIT 4"
        );
        if ($tq) while ($row = mysqli_fetch_assoc($tq)) $ov['recent_tasks'][] = $row;

        // 8. Recent agents — only created by this manager
        $aq = mysqli_query($conn,
            "SELECT name, username, status, designation FROM users
             WHERE role='agent' AND created_by='$mgrSafe' ORDER BY id DESC LIMIT 4"
        );
        if ($aq) while ($row = mysqli_fetch_assoc($aq)) $ov['recent_agents'][] = $row;
    }

    // Helper functions
    function ovFmt($v, $c = 'USD') {
        if ($v >= 1000000) return $c . ' ' . number_format($v / 1000000, 1) . 'M';
        if ($v >= 1000)    return $c . ' ' . number_format($v / 1000, 1) . 'K';
        return $c . ' ' . number_format($v, 0);
    }
    function ovStage($s) {
        $m = ['Lead'=>['#dbeafe','#1d4ed8'],'Proposal'=>['#fef9c3','#a16207'],'Negotiation'=>['#fff7ed','#c2410c'],'Won'=>['#dcfce7','#15803d'],'Lost'=>['#fee2e2','#b91c1c']];
        $c = $m[$s] ?? ['#f3f4f6','#374151'];
        return "<span style='background:{$c[0]};color:{$c[1]};padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;'>$s</span>";
    }
    function ovTask($s) {
        $m = ['To-Do'=>['#f3f4f6','#6b7280'],'In-Progress'=>['#dbeafe','#1d4ed8'],'Done'=>['#dcfce7','#15803d']];
        $c = $m[$s] ?? ['#f3f4f6','#374151'];
        return "<span style='background:{$c[0]};color:{$c[1]};padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;'>$s</span>";
    }
    function ovPrio($p) {
        $m = ['High'=>['#fee2e2','#b91c1c'],'Medium'=>['#fef3c7','#b45309'],'Low'=>['#dcfce7','#15803d']];
        $c = $m[$p] ?? ['#f3f4f6','#374151'];
        return "<span style='background:{$c[0]};color:{$c[1]};padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;'>$p</span>";
    }
    ?>

    <div id="mainDashboardContent">

        <!-- Manager accent banner -->
        <div class="manager-banner">
            <div class="banner-left">
                <h2>Welcome back, <?php echo htmlspecialchars($mgrName); ?> 👋</h2>
                <p>Manager Dashboard — your team activity at a glance</p>
            </div>
            <div class="banner-right">
                <div class="banner-stat">
                    <div class="banner-stat-val"><?php echo $ov['agents']; ?></div>
                    <div class="banner-stat-lbl">Agents</div>
                </div>
                <div class="banner-stat">
                    <div class="banner-stat-val"><?php echo $ov['total_tasks']; ?></div>
                    <div class="banner-stat-lbl">Tasks</div>
                </div>
                <div class="banner-stat">
                    <div class="banner-stat-val"><?php echo $ov['total_deals']; ?></div>
                    <div class="banner-stat-lbl">Deals</div>
                </div>
            </div>
        </div>

        <!-- Heading -->
        <div class="ov-heading">
            <div class="ov-heading-left">
                <h1>CRM Overview</h1>
                <p>Filtered to your assignments &amp; supervised agents — <?php echo date('l, d F Y'); ?></p>
            </div>
            <div class="ov-date-badge"><i class="fa-regular fa-calendar" style="margin-right:6px;"></i><?php echo date('D, d M Y'); ?></div>
        </div>

        <!-- KPI Strip: 4 cards -->
        <div class="kpi-strip">
            <!-- Agents -->
            <div class="kpi-card kpi-blue">
                <div class="kpi-icon"><i class="fa-solid fa-headset"></i></div>
                <div class="kpi-label">Agents</div>
                <div class="kpi-value"><?php echo $ov['agents']; ?></div>
                <div class="kpi-sub"><b><?php echo $ov['active_agents']; ?></b> active &nbsp;·&nbsp; <b><?php echo $ov['inactive_agents']; ?></b> inactive</div>
            </div>
            <!-- Tasks -->
            <div class="kpi-card kpi-amber">
                <div class="kpi-icon"><i class="fa-solid fa-list-check"></i></div>
                <div class="kpi-label">My Tasks</div>
                <div class="kpi-value"><?php echo $ov['total_tasks']; ?></div>
                <div class="kpi-sub"><b style="color:#ef4444;"><?php echo $ov['tasks_overdue']; ?></b> overdue &nbsp;·&nbsp; <b><?php echo $ov['tasks_progress']; ?></b> in progress</div>
            </div>
            <!-- Contacts -->
            <div class="kpi-card kpi-cyan">
                <div class="kpi-icon"><i class="fa-solid fa-address-book"></i></div>
                <div class="kpi-label">Assigned Contacts</div>
                <div class="kpi-value"><?php echo $ov['total_contacts']; ?></div>
                <div class="kpi-sub">contacts assigned to you</div>
            </div>
            <!-- Deals -->
            <div class="kpi-card kpi-green">
                <div class="kpi-icon"><i class="fa-solid fa-sack-dollar"></i></div>
                <div class="kpi-label">Deal Value</div>
                <div class="kpi-value kpi-value-sm"><?php echo ovFmt($ov['deal_value']); ?></div>
                <div class="kpi-sub"><b><?php echo $ov['total_deals']; ?></b> deals &nbsp;·&nbsp; <b><?php echo $ov['deals_won']; ?></b> won</div>
            </div>
        </div>

        <!-- Mid row: Deal Pipeline | Task Donut | Team Breakdown -->
        <div class="ov-mid-row">
            <!-- Panel 1: Deal Pipeline -->
            <div class="ov-panel">
                <div class="ov-panel-title"><i class="fa-solid fa-filter"></i> Deal Pipeline — Stage Breakdown</div>
                <?php
                $stages = ['Lead'=>['#60a5fa',0],'Proposal'=>['#a78bfa',0],'Negotiation'=>['#f97316',0],'Won'=>['#34d399',0],'Lost'=>['#f87171',0]];
                if (isset($conn)) {
                    $sq = mysqli_query($conn, "SELECT stage, COUNT(*) c FROM deals WHERE sales_officer IN ('$teamList') GROUP BY stage");
                    if ($sq) while ($sr = mysqli_fetch_assoc($sq)) if (isset($stages[$sr['stage']])) $stages[$sr['stage']][1] = (int)$sr['c'];
                }
                $mx = max(1, max(array_column($stages, 1)));
                foreach ($stages as $lbl => [$col, $cnt]):
                    $pct = round($cnt / $mx * 100);
                ?>
                <div class="funnel-row">
                    <div class="funnel-label"><?php echo $lbl; ?></div>
                    <div class="funnel-bar-wrap"><div class="funnel-bar" style="width:<?php echo $pct; ?>%;background:<?php echo $col; ?>;"></div></div>
                    <div class="funnel-count"><?php echo $cnt; ?></div>
                </div>
                <?php endforeach; ?>
                <div class="funnel-deal-total">
                    <span>Total pipeline value</span>
                    <strong><?php echo ovFmt($ov['deal_value']); ?></strong>
                </div>
            </div>

            <!-- Panel 2: Task Status Donut -->
            <div class="ov-panel">
                <div class="ov-panel-title"><i class="fa-solid fa-chart-pie"></i> Task Status</div>
                <?php
                $tTot = max(1, $ov['total_tasks']);
                $rv = 46; $circ = 2 * M_PI * $rv;
                $segs = [[$ov['tasks_done'],'#34d399'],[$ov['tasks_progress'],'#60a5fa'],[$ov['tasks_todo'],'#d1d5db'],[$ov['tasks_overdue'],'#f87171']];
                $off = 0; $svgp = '';
                foreach ($segs as [$sv, $sc]) {
                    $frac = $sv / $tTot; $dash = $frac * $circ; $gap = $circ - $dash;
                    $svgp .= "<circle class='donut-bg' cx='60' cy='60' r='46' stroke-width='12'/>";
                    $svgp .= "<circle cx='60' cy='60' r='46' fill='none' stroke='{$sc}' stroke-width='12' stroke-dasharray='{$dash} {$gap}' stroke-dashoffset='-{$off}' stroke-linecap='round'/>";
                    $off += $dash;
                }
                ?>
                <div class="task-ring-wrap">
                    <svg class="donut-svg" width="120" height="120" viewBox="0 0 120 120">
                        <?php echo $svgp; ?>
                        <g transform="rotate(90,60,60)">
                            <text x="60" y="56" text-anchor="middle" font-size="20" font-weight="800" fill="#111827" font-family="DM Mono,monospace"><?php echo $ov['total_tasks']; ?></text>
                            <text x="60" y="70" text-anchor="middle" font-size="9" font-weight="600" fill="#94a3b8">TASKS</text>
                        </g>
                    </svg>
                    <div class="task-ring-legend" style="width:100%;">
                        <?php foreach([['Done','#34d399',$ov['tasks_done']],['In Progress','#60a5fa',$ov['tasks_progress']],['To-Do','#d1d5db',$ov['tasks_todo']],['Overdue','#f87171',$ov['tasks_overdue']]] as [$n,$c,$v]): ?>
                        <div class="trl-row">
                            <div class="trl-dot" style="background:<?php echo $c; ?>;"></div>
                            <div class="trl-name"><?php echo $n; ?></div>
                            <div class="trl-num"><?php echo $v; ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Panel 3: Team Breakdown — Agents only -->
            <div class="ov-panel">
                <div class="ov-panel-title"><i class="fa-solid fa-user-group"></i> Team Breakdown</div>
                <div class="ubl-row">
                    <div class="ubl-avatar" style="background:#dcfce7;color:#10b981;"><i class="fa-solid fa-headset"></i></div>
                    <div class="ubl-info">
                        <div class="ubl-role">Agents</div>
                        <div class="ubl-stat">Client handling</div>
                    </div>
                    <div class="ubl-count"><?php echo $ov['agents']; ?></div>
                </div>
                <div class="ubl-row" style="border-bottom:none;">
                    <div class="ubl-avatar" style="background:#dcfce7;color:#059669;"><i class="fa-solid fa-circle-check"></i></div>
                    <div class="ubl-info">
                        <div class="ubl-role">Active Agents</div>
                        <div class="ubl-stat">Currently enabled</div>
                    </div>
                    <div class="ubl-count"><?php echo $ov['active_agents']; ?></div>
                </div>
                <div class="ov-panel-footer" style="margin-top:14px;">
                    <a href="user_list.php">View all agents →</a>
                </div>
            </div>
        </div>

        <!-- Bottom row: Recent Deals | Recent Tasks | Recent Agents -->
        <div class="ov-bottom-row">
            <!-- Panel 4: Recent Deals -->
            <div class="ov-panel">
                <div class="ov-panel-title"><i class="fa-solid fa-handshake"></i> My Recent Deals</div>
                <?php if (empty($ov['recent_deals'])): ?>
                    <div class="ov-empty"><i class="fa-solid fa-inbox"></i>No deals assigned to you yet.</div>
                <?php else: ?>
                <table class="mini-table">
                    <thead><tr><th>Deal Name</th><th>Value</th><th>Stage</th></tr></thead>
                    <tbody>
                    <?php foreach ($ov['recent_deals'] as $d): ?>
                    <tr>
                        <td class="mini-deal-name" title="<?php echo htmlspecialchars($d['deal_name']); ?>"><?php echo htmlspecialchars($d['deal_name']); ?></td>
                        <td class="mini-td-amount"><?php echo htmlspecialchars($d['currency']); ?> <?php echo number_format((float)$d['deal_value'], 0); ?></td>
                        <td><?php echo ovStage($d['stage']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                <div class="ov-panel-footer"><a href="deal_pipeline.php">All deals →</a></div>
            </div>

            <!-- Panel 5: Recent Tasks -->
            <div class="ov-panel">
                <div class="ov-panel-title"><i class="fa-solid fa-clipboard-list"></i> My Recent Tasks</div>
                <?php if (empty($ov['recent_tasks'])): ?>
                    <div class="ov-empty"><i class="fa-solid fa-inbox"></i>No tasks found.</div>
                <?php else: ?>
                <table class="mini-table">
                    <thead><tr><th>Title</th><th>Priority</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($ov['recent_tasks'] as $t): ?>
                    <tr>
                        <td class="mini-td-title" title="<?php echo htmlspecialchars($t['title']); ?>"><?php echo htmlspecialchars($t['title']); ?></td>
                        <td><?php echo ovPrio($t['priority']); ?></td>
                        <td><?php echo ovTask($t['status']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                <div class="ov-panel-footer"><a href="task_manager.php">All tasks →</a></div>
            </div>

            <!-- Panel 6: Recent Agents -->
            <div class="ov-panel">
                <div class="ov-panel-title"><i class="fa-solid fa-user-plus"></i> Recent Agents</div>
                <?php if (empty($ov['recent_agents'])): ?>
                    <div class="ov-empty"><i class="fa-solid fa-inbox"></i>No agents found.</div>
                <?php else: ?>
                <table class="mini-table">
                    <thead><tr><th>Name</th><th>Designation</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($ov['recent_agents'] as $u): ?>
                    <tr>
                        <td class="mini-td-title"><?php echo htmlspecialchars($u['name']); ?></td>
                        <td style="font-size:11px;color:#6b7280;"><?php echo htmlspecialchars($u['designation'] ?: '—'); ?></td>
                        <td><?php echo strtolower($u['status']) === 'active'
                            ? "<span style='color:#10b981;font-size:11px;font-weight:700;'>● Active</span>"
                            : "<span style='color:#ef4444;font-size:11px;font-weight:700;'>● Inactive</span>"; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                <div class="ov-panel-footer"><a href="user_list.php">All agents →</a></div>
            </div>
        </div>

    </div><!-- /mainDashboardContent -->
</div><!-- /main-content -->

<script>
function showToast(msg, type = 'success') {
    const box = document.getElementById('toastBox');
    const icon = document.getElementById('toastIcon');
    const txt  = document.getElementById('toastMsg');
    box.className = 'show ' + type;
    icon.className = type === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark';
    txt.textContent = msg;
    setTimeout(() => { box.className = box.className.replace('show', '').trim(); }, 3500);
}
</script>
</body>
</html>