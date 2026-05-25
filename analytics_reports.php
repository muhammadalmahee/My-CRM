<?php
// ========================================================================
// 1. INITIALIZATION & SECURITY CHECK
// ========================================================================
session_start();
@include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$toastMessage = "";
$toastType    = "";

// Get current user's role, username and name
$currentRole     = $_SESSION['role']     ?? '';
$currentUsername = $_SESSION['username'] ?? '';
$currentName     = $_SESSION['name']     ?? '';

// ========================================================================
// 2. FETCH ANALYTICS DATA (WITH AGENT FILTERING)
// ========================================================================
$totalRevenue        = 0;
$activeDealsCount    = 0;
$wonDealsCount       = 0;
$lostDealsCount      = 0;
$totalTasksCount     = 0;
$completedTasksCount = 0;
$totalCompaniesCount = 0;
$totalUsersCount     = 0;
$totalCampaigns      = 0;
$activeCampaigns     = 0;
$totalContacts       = 0;

$recentDeals      = [];
$taskStatusData   = ['todo' => 0, 'progress' => 0, 'done' => 0, 'overdue' => 0];
$dealsByStage     = [];
$usersByRole      = [];
$campaignsByType  = [];
$recentCampaigns  = [];

if (isset($conn)) {
    try {
        // ★ BUILD WHERE CLAUSES BASED ON ROLE ★
        // If agent: only show data assigned to them
        // If admin/manager/super_admin: show all data
        
        $isAgent = ($currentRole === 'agent');
        
        // Deal Analytics (filter by sales_officer for agents)
        // Match both username AND name, plus campaign-linked deals (mirrors agent_dashboard.php)
        if ($isAgent) {
            $agtSafe     = mysqli_real_escape_string($conn, $currentUsername);
            $agtNameSafe = mysqli_real_escape_string($conn, $currentName);
            $deal_query = mysqli_query($conn, "
                SELECT deal_name, deal_value, stage, created_at 
                FROM deals 
                WHERE sales_officer = '$agtSafe'
                   OR sales_officer = '$agtNameSafe'
                   OR id IN (
                       SELECT deal_id FROM campaigns
                       WHERE deal_id IS NOT NULL
                         AND (assigned_to = '$agtSafe' OR assigned_to = '$agtNameSafe')
                   )
                ORDER BY id DESC
            ");
        } else {
            $deal_query = mysqli_query($conn, "SELECT deal_name, deal_value, stage, created_at FROM deals ORDER BY id DESC");
        }
        
        if ($deal_query) {
            while ($row = mysqli_fetch_assoc($deal_query)) {
                $val   = (float)$row['deal_value'];
                $stage = strtolower($row['stage']);
                if ($stage == 'won') {
                    $totalRevenue += $val;
                    $wonDealsCount++;
                } elseif ($stage == 'lost') {
                    $lostDealsCount++;
                } else {
                    $activeDealsCount++;
                }
                $stageLabel = ucfirst($row['stage']);
                $dealsByStage[$stageLabel] = ($dealsByStage[$stageLabel] ?? 0) + 1;
                if (count($recentDeals) < 5) $recentDeals[] = $row;
            }
        }

        // Task Analytics (filter by assigned_to for agents)
        // Match both username AND name
        if ($isAgent) {
            $task_query = mysqli_query($conn, "
                SELECT status 
                FROM tasks 
                WHERE assigned_to = '$agtSafe'
                   OR assigned_to = '$agtNameSafe'
            ");
        } else {
            $task_query = mysqli_query($conn, "SELECT status FROM tasks");
        }
        
        if ($task_query) {
            while ($row = mysqli_fetch_assoc($task_query)) {
                $totalTasksCount++;
                $t = strtolower($row['status']);
                if (strpos($t, 'done') !== false || strpos($t, 'complete') !== false) {
                    $completedTasksCount++;
                    $taskStatusData['done']++;
                } elseif (strpos($t, 'progress') !== false) {
                    $taskStatusData['progress']++;
                } elseif (strpos($t, 'overdue') !== false) {
                    $taskStatusData['overdue']++;
                } else {
                    $taskStatusData['todo']++;
                }
            }
        }

        // Company Count (filter by assigned_agent for agents)
        // Match both username AND name
        if ($isAgent) {
            $comp_q = mysqli_query($conn, "
                SELECT COUNT(*) as c 
                FROM companies 
                WHERE assigned_agent = '$agtSafe'
                   OR assigned_agent = '$agtNameSafe'
            ");
        } else {
            $comp_q = mysqli_query($conn, "SELECT COUNT(*) as c FROM companies");
        }
        if ($comp_q) $totalCompaniesCount = mysqli_fetch_assoc($comp_q)['c'] ?? 0;

        // User Counts (agents can only see themselves, others see all)
        if ($isAgent) {
            $totalUsersCount = 1; // Only themselves
            $usersByRole['Agent'] = 1;
        } else {
            $user_q = mysqli_query($conn, "SELECT role, COUNT(*) as c FROM users WHERE status='active' GROUP BY role");
            if ($user_q) {
                while ($row = mysqli_fetch_assoc($user_q)) {
                    $totalUsersCount += $row['c'];
                    $usersByRole[ucfirst($row['role'])] = $row['c'];
                }
            }
        }

        // Contacts (filter by assigned_agents field - contains comma-separated usernames)
        // Match both username AND name
        if ($isAgent) {
            $cont_q = mysqli_query($conn, "
                SELECT COUNT(*) as c 
                FROM contacts 
                WHERE FIND_IN_SET('$agtSafe', assigned_agents) > 0
                   OR FIND_IN_SET('$agtNameSafe', assigned_agents) > 0
            ");
        } else {
            $cont_q = mysqli_query($conn, "SELECT COUNT(*) as c FROM contacts");
        }
        if ($cont_q) $totalContacts = mysqli_fetch_assoc($cont_q)['c'] ?? 0;

        // Campaigns (filter by assigned_to for agents)
        // Match both username AND name
        if ($isAgent) {
            $camp_q = mysqli_query($conn, "
                SELECT campaign_name, campaign_type, status, budget, currency, start_date, end_date 
                FROM campaigns 
                WHERE assigned_to = '$agtSafe'
                   OR assigned_to = '$agtNameSafe'
                ORDER BY id DESC
            ");
        } else {
            $camp_q = mysqli_query($conn, "SELECT campaign_name, campaign_type, status, budget, currency, start_date, end_date FROM campaigns ORDER BY id DESC");
        }
        
        if ($camp_q) {
            while ($row = mysqli_fetch_assoc($camp_q)) {
                $totalCampaigns++;
                if (strtolower($row['status']) === 'active') $activeCampaigns++;
                $type = $row['campaign_type'];
                $campaignsByType[$type] = ($campaignsByType[$type] ?? 0) + 1;
                if (count($recentCampaigns) < 5) $recentCampaigns[] = $row;
            }
        }

    } catch (mysqli_sql_exception $e) {
        // Use fallbacks
    }
}

// Calculate percentages
$winRate            = ($wonDealsCount + $lostDealsCount > 0) ? round(($wonDealsCount / ($wonDealsCount + $lostDealsCount)) * 100) : 0;
$taskCompletionRate = ($totalTasksCount > 0) ? round(($completedTasksCount / $totalTasksCount) * 100) : 0;

// Dummy data if DB is empty (only for non-agents or if they have no data)
if ($totalRevenue == 0 && $totalTasksCount == 0 && empty($recentDeals)) {
    if (!$isAgent) {
        // Full dummy data for admins/managers
        $totalRevenue        = 45200;
        $activeDealsCount    = 12;
        $wonDealsCount       = 17;
        $lostDealsCount      = 8;
        $winRate             = 68;
        $taskCompletionRate  = 85;
        $totalCompaniesCount = 24;
        $totalUsersCount     = 8;
        $totalContacts       = 36;
        $totalCampaigns      = 5;
        $activeCampaigns     = 2;
        $taskStatusData      = ['todo' => 15, 'progress' => 8, 'done' => 45, 'overdue' => 3];
        $dealsByStage        = ['Lead' => 5, 'Proposal' => 4, 'Negotiation' => 3, 'Won' => 17, 'Lost' => 8];
        $usersByRole         = ['Super_admin' => 1, 'Admin' => 2, 'Manager' => 2, 'Agent' => 3];
        $campaignsByType     = ['Email' => 2, 'Social Media' => 1, 'Paid Ads' => 1, 'Content Marketing' => 1];
        $recentCampaigns     = [
            ['campaign_name' => 'Q2 Email Blast', 'campaign_type' => 'Email', 'status' => 'Active', 'budget' => '500', 'currency' => 'USD', 'start_date' => date('Y-m-d', strtotime('-5 days')), 'end_date' => date('Y-m-d', strtotime('+10 days'))],
            ['campaign_name' => 'Social Spring', 'campaign_type' => 'Social Media', 'status' => 'Planning', 'budget' => '1200', 'currency' => 'USD', 'start_date' => date('Y-m-d', strtotime('+3 days')), 'end_date' => date('Y-m-d', strtotime('+20 days'))],
        ];
        $recentDeals = [
            ['deal_name' => 'Enterprise CRM Upgrade',    'deal_value' => '12000', 'stage' => 'Won',         'created_at' => date('Y-m-d', strtotime('-2 days'))],
            ['deal_name' => 'Website Redesign Phase 1',  'deal_value' => '4500',  'stage' => 'Negotiation', 'created_at' => date('Y-m-d', strtotime('-4 days'))],
            ['deal_name' => 'SEO Optimization Q3',       'deal_value' => '2100',  'stage' => 'Proposal',    'created_at' => date('Y-m-d', strtotime('-5 days'))],
            ['deal_name' => 'Cloud Migration',           'deal_value' => '8500',  'stage' => 'Lead',        'created_at' => date('Y-m-d', strtotime('-1 week'))],
        ];
    } else {
        // Minimal dummy data for agents with no assignments
        $totalRevenue        = 0;
        $activeDealsCount    = 0;
        $wonDealsCount       = 0;
        $lostDealsCount      = 0;
        $winRate             = 0;
        $taskCompletionRate  = 0;
        $totalCompaniesCount = 0;
        $totalContacts       = 0;
        $totalCampaigns      = 0;
        $activeCampaigns     = 0;
    }
}

// JSON encode for JS charts / export
$taskStatusJson   = json_encode($taskStatusData);
$dealsByStageJson = json_encode($dealsByStage);
$usersByRoleJson  = json_encode($usersByRole);
$campByTypeJson   = json_encode($campaignsByType);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="img/favicon.png">
    <title>Analytics & Reports — Systellio CRM</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ================================================================
           GLOBAL — matches all other CRM pages (Inter, same token set)
        ================================================================ */
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6;
            display: flex;
            height: 100vh;
            overflow: hidden;
            color: #111827;
            transition: background-color .3s, color .3s;
        }

        /* ── MAIN CONTENT ── */
        .main-content {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }

        .analytics-container {
            padding: 28px 30px 40px;
        }

        /* ── Page heading ── */
        .analytics-heading {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 26px;
        }
        .analytics-heading h1 {
            font-size: 24px;
            font-weight: 800;
            color: #111827;
            letter-spacing: -.6px;
            margin-bottom: 6px;
        }
        .analytics-heading p {
            font-size: 13px;
            color: #6b7280;
            font-weight: 500;
        }
        .export-btn {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: #fff;
            border: none;
            padding: 11px 22px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 6px rgba(59,130,246,.25);
            transition: box-shadow .2s, transform .15s;
        }
        .export-btn:hover {
            box-shadow: 0 4px 12px rgba(59,130,246,.35);
            transform: translateY(-1px);
        }
        .export-btn:active { transform: translateY(0); }

        /* ── Role badge (shows if agent) ── */
        .role-badge {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .8px;
            padding: 5px 12px;
            border-radius: 6px;
            display: inline-block;
            margin-bottom: 10px;
            box-shadow: 0 2px 6px rgba(16,185,129,.25);
        }

        /* ── Metrics grid ── */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 28px;
        }
        .metric-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 20px 22px;
            transition: box-shadow .2s, transform .15s;
        }
        .metric-card:hover {
            box-shadow: 0 6px 20px rgba(0,0,0,.07);
            transform: translateY(-2px);
        }
        .metric-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .7px;
            color: #9ca3af;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .metric-title i {
            font-size: 13px;
            color: #3b82f6;
        }
        .metric-value {
            font-size: 30px;
            font-weight: 800;
            color: #111827;
            margin-bottom: 6px;
            line-height: 1;
        }
        .metric-sub {
            font-size: 12px;
            color: #6b7280;
            font-weight: 600;
        }
        .metric-sub.positive { color: #10b981; }
        .metric-sub.negative { color: #ef4444; }

        /* ── Charts / Tables row ── */
        .charts-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 24px;
        }
        .chart-card, .table-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 24px;
        }
        .chart-card h3, .table-card h3 {
            font-size: 15px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 1px solid #f3f4f6;
        }
        .chart-placeholder {
            height: 220px;
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            color: #3b82f6;
            font-size: 13px;
            font-weight: 600;
        }
        .chart-placeholder i {
            font-size: 38px;
            margin-bottom: 12px;
            opacity: .7;
        }

        /* ── Simple table ── */
        .simple-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .simple-table thead th {
            text-align: left;
            padding: 10px 12px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #9ca3af;
            border-bottom: 2px solid #f3f4f6;
        }
        .simple-table tbody td {
            padding: 13px 12px;
            border-bottom: 1px solid #f9fafb;
            color: #374151;
            font-weight: 500;
        }
        .simple-table tbody tr:last-child td {
            border-bottom: none;
        }
        .simple-table tbody tr:hover {
            background: #f9fafb;
        }
        .pill {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: capitalize;
        }
        .pill.won, .pill.active      { background: #d1fae5; color: #065f46; }
        .pill.lost                   { background: #fee2e2; color: #991b1b; }
        .pill.proposal, .pill.negotiation { background: #fef3c7; color: #92400e; }
        .pill.lead, .pill.planning   { background: #dbeafe; color: #1e40af; }
        .pill.on-hold                { background: #e5e7eb; color: #374151; }

        /* ── Progress bars ── */
        .progress-item {
            margin-bottom: 14px;
        }
        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 7px;
            font-size: 12px;
            font-weight: 600;
            color: #374151;
        }
        .progress-bar-bg {
            background: #e5e7eb;
            border-radius: 8px;
            height: 7px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            border-radius: 8px;
            transition: width .5s ease;
        }
        .progress-fill.done     { background: #10b981; }
        .progress-fill.progress { background: #3b82f6; }
        .progress-fill.todo     { background: #9ca3af; }
        .progress-fill.overdue  { background: #ef4444; }

        /* ── Empty state ── */
        .empty-state {
            padding: 40px 20px;
            text-align: center;
            color: #9ca3af;
            font-size: 13px;
            font-weight: 500;
        }
        .empty-state i {
            font-size: 42px;
            margin-bottom: 12px;
            opacity: .5;
        }

        /* ── Dark mode ── */
        body.dark-mode {
            background-color: #0f172a;
            color: #f8fafc;
        }
        body.dark-mode .analytics-heading h1 { color: #f8fafc; }
        body.dark-mode .analytics-heading p  { color: #94a3b8; }
        body.dark-mode .metric-card, body.dark-mode .chart-card, body.dark-mode .table-card {
            background: #1e293b;
            border-color: #334155;
        }
        body.dark-mode .metric-title { color: #64748b; }
        body.dark-mode .metric-value { color: #f8fafc; }
        body.dark-mode .metric-sub   { color: #94a3b8; }
        body.dark-mode .chart-card h3, body.dark-mode .table-card h3 {
            color: #f8fafc;
            border-color: #334155;
        }
        body.dark-mode .chart-placeholder {
            background: linear-gradient(135deg, #1e3a8a22 0%, #1e40af22 100%);
            color: #60a5fa;
        }
        body.dark-mode .simple-table thead th {
            color: #64748b;
            border-color: #334155;
        }
        body.dark-mode .simple-table tbody td {
            color: #cbd5e1;
            border-color: #1e293b;
        }
        body.dark-mode .simple-table tbody tr:hover {
            background: #0f172a;
        }
        body.dark-mode .progress-label { color: #cbd5e1; }
        body.dark-mode .progress-bar-bg { background: #334155; }
        body.dark-mode .empty-state { color: #64748b; }
    </style>
</head>
<body>

<?php
$activePage    = 'analytics';
$sidebarRole   = ucfirst(str_replace('_', ' ', $_SESSION['role'] ?? ''));

// Role অনুযায়ী সঠিক dashboard file সেট করা
$_role = $_SESSION['role'] ?? '';
if ($_role === 'agent')        { $dashboardFile = 'agent_dashboard.php'; }
elseif ($_role === 'manager')  { $dashboardFile = 'manager_dashboard.php'; }
elseif ($_role === 'admin')    { $dashboardFile = 'admin_dashboard.php'; }
else                           { $dashboardFile = 'super_admin_dashboard.php'; }

include 'sidebar.php';
?>

<div class="main-content">
    <?php 
    $topbarTitle = 'Analytics & Reports';
    include 'topbar.php'; 
    ?>

    <div class="analytics-container">

        <!-- Page Heading -->
        <div class="analytics-heading">
            <div>
                <?php if ($isAgent): ?>
                <div class="role-badge">
                    <i class="fa-solid fa-user-tie"></i> Agent View — My Analytics
                </div>
                <?php endif; ?>
                <h1>📊 Analytics & Reports</h1>
                <p>Comprehensive performance metrics and insights<?php echo $isAgent ? ' for ' . htmlspecialchars($currentUsername) : ''; ?></p>
            </div>
            <button class="export-btn" onclick="exportFullReport()">
                <i class="fa-solid fa-download"></i> Export Full Report
            </button>
        </div>

        <!-- Metrics Grid -->
        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-title"><i class="fa-solid fa-dollar-sign"></i> Total Revenue</div>
                <div class="metric-value">$<?php echo number_format($totalRevenue, 0); ?></div>
                <div class="metric-sub positive">+<?php echo $winRate; ?>% win rate</div>
            </div>
            <div class="metric-card">
                <div class="metric-title"><i class="fa-solid fa-handshake"></i> Active Deals</div>
                <div class="metric-value"><?php echo $activeDealsCount; ?></div>
                <div class="metric-sub"><?php echo $wonDealsCount; ?> won, <?php echo $lostDealsCount; ?> lost</div>
            </div>
            <div class="metric-card">
                <div class="metric-title"><i class="fa-solid fa-list-check"></i> Total Tasks</div>
                <div class="metric-value"><?php echo $totalTasksCount; ?></div>
                <div class="metric-sub positive"><?php echo $taskCompletionRate; ?>% completion rate</div>
            </div>
            <div class="metric-card">
                <div class="metric-title"><i class="fa-solid fa-briefcase"></i> Companies</div>
                <div class="metric-value"><?php echo $totalCompaniesCount; ?></div>
                <div class="metric-sub"><?php echo $totalContacts; ?> contacts</div>
            </div>
        </div>

        <!-- Secondary Metrics -->
        <div class="metrics-grid" style="grid-template-columns: repeat(3, 1fr);">
            <div class="metric-card">
                <div class="metric-title"><i class="fa-solid fa-users"></i> Total Users</div>
                <div class="metric-value"><?php echo $totalUsersCount; ?></div>
                <div class="metric-sub"><?php echo count($usersByRole); ?> roles active</div>
            </div>
            <div class="metric-card">
                <div class="metric-title"><i class="fa-solid fa-bullhorn"></i> Campaigns</div>
                <div class="metric-value"><?php echo $totalCampaigns; ?></div>
                <div class="metric-sub positive"><?php echo $activeCampaigns; ?> currently active</div>
            </div>
            <div class="metric-card">
                <div class="metric-title"><i class="fa-solid fa-address-book"></i> Total Contacts</div>
                <div class="metric-value"><?php echo $totalContacts; ?></div>
                <div class="metric-sub">Across all companies</div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="charts-row">
            <!-- Deal Stage Distribution -->
            <div class="chart-card">
                <h3>📈 Deals by Stage</h3>
                <?php if (!empty($dealsByStage)): ?>
                    <div class="chart-placeholder">
                        <i class="fa-solid fa-chart-pie"></i>
                        Chart visualization (integrate Chart.js)
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fa-regular fa-chart-bar"></i>
                        <p>No deal data available</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Task Status Breakdown -->
            <div class="chart-card">
                <h3>✅ Task Status Breakdown</h3>
                <?php if ($totalTasksCount > 0): ?>
                    <?php
                    $taskTotal = array_sum($taskStatusData);
                    foreach ($taskStatusData as $key => $val):
                        if ($val == 0) continue;
                        $pct = $taskTotal > 0 ? round(($val / $taskTotal) * 100) : 0;
                    ?>
                    <div class="progress-item">
                        <div class="progress-label">
                            <span><?php echo ucfirst($key); ?></span>
                            <span><?php echo $val; ?> (<?php echo $pct; ?>%)</span>
                        </div>
                        <div class="progress-bar-bg">
                            <div class="progress-fill <?php echo $key; ?>" style="width: <?php echo $pct; ?>%;"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fa-regular fa-square-check"></i>
                        <p>No task data available</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Activities -->
        <div class="charts-row">
            <!-- Recent Deals -->
            <div class="table-card">
                <h3>📌 Recent Deal Activities</h3>
                <?php if (!empty($recentDeals)): ?>
                    <table class="simple-table">
                        <thead><tr><th>Deal Name</th><th>Date</th><th>Value</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($recentDeals as $deal):
                                $dt = date('M j, Y', strtotime($deal['created_at']));
                                $stageClass = strtolower(str_replace(' ', '-', $deal['stage']));
                            ?>
                            <tr>
                                <td style="font-weight:700;"><?php echo htmlspecialchars($deal['deal_name']); ?></td>
                                <td style="color:#6b7280; font-size:12px;"><?php echo $dt; ?></td>
                                <td style="font-weight:700; color:#3b82f6;">$<?php echo number_format($deal['deal_value'], 0); ?></td>
                                <td><span class="pill <?php echo $stageClass; ?>"><?php echo htmlspecialchars($deal['stage']); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fa-regular fa-handshake"></i>
                        <p>No recent deals found</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Recent Campaigns -->
            <div class="table-card">
                <h3>🚀 Recent Campaigns</h3>
                <?php if (!empty($recentCampaigns)): ?>
                    <table class="simple-table">
                        <thead><tr><th>Name</th><th>Type</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($recentCampaigns as $camp):
                                $cStatus = strtolower(str_replace(' ', '-', $camp['status']));
                            ?>
                            <tr>
                                <td style="font-weight:700;"><?php echo htmlspecialchars($camp['campaign_name']); ?></td>
                                <td style="color:#6b7280; font-size:12px;"><?php echo htmlspecialchars($camp['campaign_type']); ?></td>
                                <td><span class="pill <?php echo $cStatus; ?>"><?php echo htmlspecialchars($camp['status']); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fa-regular fa-bullhorn"></i>
                        <p>No campaigns found</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /analytics-container -->
</div><!-- /main-content -->

<script>
/* Dark mode & sidebar toggle are handled by sidebar.php's built-in script */

/* ════════════════════════════════════════════════════════════════
   EXPORT FULL REPORT — generates a self-contained HTML file
   and triggers a browser download (no server-side library needed)
   
   ★ AGENT FILTERING: All data exported is already filtered on the
   server-side based on the logged-in user's role. Agents only see
   their own data, while admins/managers see everything.
═══════════════════════════════════════════════════════════════════ */
function exportFullReport() {
    const now = new Date();
    const dateStr = now.toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });
    const timeStr = now.toLocaleTimeString('en-US', { hour:'2-digit', minute:'2-digit' });

    /* ── Collect live data from the page ── */
    const metrics = [];
    document.querySelectorAll('.metric-card').forEach(card => {
        const title = card.querySelector('.metric-title')?.firstChild?.textContent?.trim() ?? '';
        const value = card.querySelector('.metric-value')?.textContent?.trim() ?? '';
        const sub   = card.querySelector('.metric-sub')?.textContent?.trim() ?? '';
        metrics.push({ title, value, sub });
    });

    /* Deal rows */
    let dealRows = '';
    const dealTable = document.querySelectorAll('.table-card')[0];
    if (dealTable) {
        dealTable.querySelectorAll('.simple-table tbody tr').forEach(tr => {
            const cells = tr.querySelectorAll('td');
            if (cells.length >= 4) {
                dealRows += `<tr>
                    <td>${cells[0].textContent.trim()}</td>
                    <td>${cells[1].textContent.trim()}</td>
                    <td>${cells[2].textContent.trim()}</td>
                    <td>${cells[3].textContent.trim()}</td>
                </tr>`;
            }
        });
    }

    /* Progress data */
    let progressRows = '';
    document.querySelectorAll('.progress-item').forEach(item => {
        const labels = item.querySelectorAll('.progress-label span');
        const fill   = item.querySelector('.progress-fill');
        if (labels.length >= 2 && fill) {
            const pct   = fill.style.width;
            const bg    = fill.classList.contains('done') ? '#10b981'
                        : fill.classList.contains('progress') ? '#3b82f6'
                        : fill.classList.contains('overdue') ? '#ef4444' : '#9ca3af';
            progressRows += `<tr>
                <td>${labels[0].textContent.trim()}</td>
                <td>${labels[1].textContent.trim()}</td>
                <td><div style="width:120px;height:7px;background:#e5e7eb;border-radius:8px;overflow:hidden;">
                    <div style="width:${pct};height:100%;background:${bg};border-radius:8px;"></div></div></td>
            </tr>`;
        }
    });

    /* Campaign rows */
    let campaignRows = '';
    const campTable = document.querySelectorAll('.table-card')[1];
    if (campTable) {
        campTable.querySelectorAll('.simple-table tbody tr').forEach(tr => {
            const cells = tr.querySelectorAll('td');
            if (cells.length >= 3) {
                campaignRows += `<tr>
                    <td>${cells[0].textContent.trim()}</td>
                    <td>${cells[1].textContent.trim()}</td>
                    <td>${cells[2].textContent.trim()}</td>
                </tr>`;
            }
        });
    }

    /* Check if this is an agent view */
    const isAgentView = document.querySelector('.role-badge') !== null;
    const reportTitle = isAgentView 
        ? '📊 My Analytics Report — Agent View' 
        : '📊 Analytics &amp; Reports';

    /* Metric card HTML for report */
    const metricHTML = metrics.map(m => `
        <div class="r-card">
            <div class="r-card-title">${m.title}</div>
            <div class="r-card-value">${m.value}</div>
            <div class="r-card-sub">${m.sub}</div>
        </div>`).join('');

    /* Build full HTML report */
    const html = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Analytics Report — Systellio CRM — ${dateStr}</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
  * { margin:0; padding:0; box-sizing:border-box; font-family:'Inter',sans-serif; }
  body { background:#f3f4f6; color:#111827; padding:40px; }

  /* Cover */
  .cover { text-align:center; padding:60px 40px; background:#fff; border-radius:14px; margin-bottom:36px; border:1px solid #e5e7eb; }
  .cover h1 { font-size:28px; font-weight:800; color:#111827; margin-bottom:6px; }
  .cover p  { font-size:14px; color:#6b7280; }
  .cover .badge { display:inline-block; margin-top:18px; background:#10b981; color:#fff; padding:6px 18px; border-radius:20px; font-size:12px; font-weight:700; letter-spacing:.5px; }
  ${isAgentView ? '.cover .agent-badge { background:#059669; margin-top:10px; }' : ''}

  /* Sections */
  h2 { font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:1.2px; color:#9ca3af; margin-bottom:14px; margin-top:36px; }

  /* Metric cards */
  .r-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:10px; }
  .r-grid-3 { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:10px; }
  .r-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:18px 16px; }
  .r-card-title { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.8px; color:#6b7280; margin-bottom:8px; }
  .r-card-value { font-size:26px; font-weight:800; color:#111827; margin-bottom:4px; }
  .r-card-sub   { font-size:11px; color:#6b7280; font-weight:600; }

  /* Tables */
  .r-table-wrap { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:20px; margin-bottom:24px; }
  .r-table-wrap h3 { font-size:14px; font-weight:700; color:#111827; margin-bottom:14px; padding-bottom:10px; border-bottom:1px solid #f3f4f6; }
  table { width:100%; border-collapse:collapse; font-size:13px; }
  th { padding:8px 10px; text-align:left; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#6b7280; border-bottom:2px solid #f3f4f6; }
  td { padding:11px 10px; border-bottom:1px solid #f9fafb; color:#374151; font-weight:500; }
  tr:last-child td { border-bottom:none; }

  /* Footer */
  footer { text-align:center; font-size:11px; color:#9ca3af; margin-top:40px; padding-top:24px; border-top:1px solid #e5e7eb; }

  @media print {
    body { background:#fff; padding:20px; }
    .cover { box-shadow:none; }
    @page { margin:1cm; }
  }
</style>
</head>
<body>

<div class="cover">
  <h1>${reportTitle}</h1>
  <p>Systellio CRM · Generated on ${dateStr} at ${timeStr}</p>
  <span class="badge">Full Report Export</span>
  ${isAgentView ? '<div><span class="badge agent-badge">Agent: <?php echo htmlspecialchars($currentUsername); ?></span></div>' : ''}
</div>

<h2>Key Metrics</h2>
<div class="r-grid">${metricHTML.split('</div>').slice(0,4).join('</div>')}
</div>
<div class="r-grid-3">${metricHTML.split('</div>').slice(4).join('</div>')}
</div>

<div class="r-table-wrap">
  <h3>📌 Recent Deal Activities</h3>
  <table>
    <thead><tr><th>Deal Name</th><th>Date</th><th>Value</th><th>Status</th></tr></thead>
    <tbody>${dealRows || '<tr><td colspan="4" style="text-align:center;color:#9ca3af;">No deals found</td></tr>'}</tbody>
  </table>
</div>

<div class="r-table-wrap">
  <h3>✅ Task Status Breakdown</h3>
  <table>
    <thead><tr><th>Status</th><th>Count</th><th>Progress</th></tr></thead>
    <tbody>${progressRows || '<tr><td colspan="3" style="text-align:center;color:#9ca3af;">No task data</td></tr>'}</tbody>
  </table>
</div>

<div class="r-table-wrap">
  <h3>🚀 Recent Campaigns</h3>
  <table>
    <thead><tr><th>Name</th><th>Type</th><th>Status</th></tr></thead>
    <tbody>${campaignRows || '<tr><td colspan="3" style="text-align:center;color:#9ca3af;">No campaigns found</td></tr>'}</tbody>
  </table>
</div>

<footer>
  Systellio CRM · ${isAgentView ? 'Agent Report · ' : ''}Confidential · Exported ${dateStr} at ${timeStr}
</footer>

</body>
</html>`;

    /* Trigger download */
    const blob = new Blob([html], { type: 'text/html;charset=utf-8' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    const filename = isAgentView 
        ? `systellio-my-analytics-${now.toISOString().slice(0,10)}.html`
        : `systellio-analytics-${now.toISOString().slice(0,10)}.html`;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}
</script>
</body>
</html>