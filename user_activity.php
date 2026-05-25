<?php
// ============================================================
// user_activity.php — Systellio CRM
// Role-based audit trail. No demo data — DB only.
// ============================================================
session_start();
@include 'config.php';

// ── 1. Auth check ──────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$sidebarRole   = strtoupper(str_replace('_', ' ', $_SESSION['role']));
$dashboardFile = match($_SESSION['role']) {
    'super_admin' => 'super_admin_dashboard.php',
    'admin'       => 'admin_dashboard.php',
    'manager'     => 'manager_dashboard.php',
    'agent'       => 'agent_dashboard.php',
    default       => 'index.php',
};

$currentRole     = $_SESSION['role'];
$currentUserId   = (int)$_SESSION['user_id'];
$currentUsername = $_SESSION['username'] ?? '';

// ── 2. logActivity() helper ────────────────────────────────
function logActivity($action, $description, $entity_type, $entity_id, $old_value = null, $new_value = null) {
    global $conn;
    if (!isset($conn)) return false;
    $user_id    = $_SESSION['user_id'];
    $username   = $_SESSION['username'];  // ✅ FIXED: Changed from 'name' to 'username'
    $timestamp  = date('Y-m-d H:i:s');
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $action      = mysqli_real_escape_string($conn, $action);
    $description = mysqli_real_escape_string($conn, $description);
    $entity_type = mysqli_real_escape_string($conn, $entity_type);
    $entity_id   = mysqli_real_escape_string($conn, (string)$entity_id);
    $old_value   = mysqli_real_escape_string($conn, $old_value ?? '');
    $new_value   = mysqli_real_escape_string($conn, $new_value ?? '');
    return mysqli_query($conn,
        "INSERT INTO activity_logs
            (user_id, username, action, description, entity_type, entity_id, old_value, new_value, ip_address, timestamp)
         VALUES
            ('$user_id','$username','$action','$description','$entity_type','$entity_id','$old_value','$new_value','$ip_address','$timestamp')"
    );
}

// ── 3. Filters and pagination vars ────────────────────────
$filterType = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$searchTerm = isset($_GET['search']) ? trim($_GET['search'])  : '';
$filterUser = isset($_GET['user'])   ? trim($_GET['user'])    : 'all';
$limit      = 50;
$page       = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset     = ($page - 1) * $limit;

$statCreate = 0; $statUpdate = 0; $statDelete = 0; $statLogin = 0;
$totalActivities   = 0;
$activityTableRows = '';
$userFilterOptions = "<option value='all'" . ($filterUser === 'all' ? ' selected' : '') . ">All Users</option>";

// ── 4. Role-based visible user IDs ─────────────────────────
//   super_admin → all (no restriction)
//   admin       → self + level-1 reports + level-2 reports
//   manager     → self + direct agent reports
//   agent       → self only
$visibleUserIds = [];

if ($currentRole === 'admin') {
    $visibleUserIds[] = $currentUserId;
    if (isset($conn)) {
        $esc  = mysqli_real_escape_string($conn, $currentUsername);
        $lvl1 = mysqli_query($conn, "SELECT id, username FROM users WHERE reporting_to = '$esc'");
        $lvl1Names = [];
        if ($lvl1) {
            while ($r = mysqli_fetch_assoc($lvl1)) {
                $visibleUserIds[] = (int)$r['id'];
                $lvl1Names[]      = $r['username'];
            }
        }
        foreach ($lvl1Names as $mgrName) {
            $esc2 = mysqli_real_escape_string($conn, $mgrName);
            $lvl2 = mysqli_query($conn, "SELECT id FROM users WHERE reporting_to = '$esc2'");
            if ($lvl2) {
                while ($r2 = mysqli_fetch_assoc($lvl2)) {
                    if (!in_array((int)$r2['id'], $visibleUserIds))
                        $visibleUserIds[] = (int)$r2['id'];
                }
            }
        }
    }

} elseif ($currentRole === 'manager') {
    $visibleUserIds[] = $currentUserId;
    if (isset($conn)) {
        $esc = mysqli_real_escape_string($conn, $currentUsername);
        $sub = mysqli_query($conn, "SELECT id FROM users WHERE reporting_to = '$esc'");
        if ($sub) {
            while ($r = mysqli_fetch_assoc($sub)) $visibleUserIds[] = (int)$r['id'];
        }
    }

} elseif ($currentRole === 'agent') {
    $visibleUserIds[] = $currentUserId;
}

// ── 5. Exclude super_admin IDs for non-super_admin users ──
$superAdminIds = [];
if ($currentRole !== 'super_admin' && isset($conn)) {
    $saQ = mysqli_query($conn, "SELECT id FROM users WHERE role = 'super_admin'");
    if ($saQ) {
        while ($r = mysqli_fetch_assoc($saQ)) $superAdminIds[] = (int)$r['id'];
    }
}

// ── 6. Assigned tasks and deals (agent / manager context) ─
$assignedTaskIds = [];
$assignedDealIds = [];
if (($currentRole === 'agent' || $currentRole === 'manager') && isset($conn)) {
    $escName = mysqli_real_escape_string($conn, $currentUsername);
    $tQ = mysqli_query($conn, "SELECT id FROM tasks WHERE assigned_to = '$escName'");
    if ($tQ) { while ($r = mysqli_fetch_assoc($tQ)) $assignedTaskIds[] = (int)$r['id']; }
    $dQ = mysqli_query($conn, "SELECT id FROM deals WHERE sales_officer = '$escName'");
    if ($dQ) { while ($r = mysqli_fetch_assoc($dQ)) $assignedDealIds[] = (int)$r['id']; }
}

// ── 7. Badge class helpers ─────────────────────────────────
function actionClass($a) {
    $map = ['CREATE'=>'action-create','UPDATE'=>'action-update','DELETE'=>'action-delete','LOGIN'=>'action-login','LOGOUT'=>'action-logout'];
    return $map[$a] ?? 'action-view';
}
function entityClass($e) {
    $map = ['User'=>'entity-user','Task'=>'entity-task','Company'=>'entity-company','Deal'=>'entity-deal','Contact'=>'entity-contact','Campaign'=>'entity-campaign'];
    return $map[$e] ?? 'entity-default';
}
function buildRow($row) {
    $ac   = actionClass($row['action']);
    $ec   = entityClass($row['entity_type']);
    $data = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
    $ts   = date('M d, Y H:i', strtotime($row['timestamp']));
    $user = htmlspecialchars($row['username']);
    $act  = htmlspecialchars($row['action']);
    $ent  = htmlspecialchars($row['entity_type']);
    $desc = htmlspecialchars($row['description']);
    $ip   = htmlspecialchars($row['ip_address']);
    return "<tr>
        <td style='text-align:left;font-weight:600;'>$user</td>
        <td><span class='badge $ac'>$act</span></td>
        <td><span class='badge $ec'>$ent</span></td>
        <td style='text-align:left;'>$desc</td>
        <td>$ts</td>
        <td>$ip</td>
        <td><div class='action-btns'><button class='btn-view' onclick='openDetailModal({$data})'><i class='fa-solid fa-eye'></i></button></div></td>
    </tr>";
}

// ── 8. Build WHERE clause helper ──────────────────────────
function buildRoleWhere($currentRole, $currentUserId, $visibleUserIds, $superAdminIds, $assignedTaskIds, $assignedDealIds) {
    $where = "WHERE 1=1";
    if (!empty($superAdminIds))
        $where .= " AND user_id NOT IN (" . implode(',', $superAdminIds) . ")";
    if ($currentRole === 'agent') {
        $c = ["user_id = $currentUserId"];
        if (!empty($assignedTaskIds)) $c[] = "(entity_type='Task' AND entity_id IN (" . implode(',', $assignedTaskIds) . "))";
        if (!empty($assignedDealIds)) $c[] = "(entity_type='Deal' AND entity_id IN (" . implode(',', $assignedDealIds) . "))";
        $where .= " AND (" . implode(' OR ', $c) . ")";
    } elseif ($currentRole === 'manager') {
        $c = [];
        if (!empty($visibleUserIds)) $c[] = "user_id IN (" . implode(',', array_map('intval', $visibleUserIds)) . ")";
        if (!empty($assignedTaskIds)) $c[] = "(entity_type='Task' AND entity_id IN (" . implode(',', $assignedTaskIds) . "))";
        if (!empty($assignedDealIds)) $c[] = "(entity_type='Deal' AND entity_id IN (" . implode(',', $assignedDealIds) . "))";
        if (!empty($c)) $where .= " AND (" . implode(' OR ', $c) . ")";
    } elseif ($currentRole === 'admin' && !empty($visibleUserIds)) {
        $where .= " AND user_id IN (" . implode(',', array_map('intval', $visibleUserIds)) . ")";
    }
    return $where;
}

// ── 9. DB queries ──────────────────────────────────────────
if (isset($conn)) {
    $st = mysqli_real_escape_string($conn, $searchTerm);
    $ft = mysqli_real_escape_string($conn, $filterType);
    $fu = mysqli_real_escape_string($conn, $filterUser);

    $statsWhere = buildRoleWhere($currentRole, $currentUserId, $visibleUserIds, $superAdminIds, $assignedTaskIds, $assignedDealIds);

    $mainWhere = $statsWhere;
    if ($ft !== 'all') $mainWhere .= " AND action = '$ft'";
    if ($fu !== 'all') $mainWhere .= " AND username = '$fu'";
    if ($st !== '')    $mainWhere .= " AND (username LIKE '%$st%' OR description LIKE '%$st%' OR entity_type LIKE '%$st%')";

    // Stats
    $sqStats = mysqli_query($conn, "SELECT action, COUNT(*) as cnt FROM activity_logs $statsWhere GROUP BY action");
    if ($sqStats) {
        while ($sr = mysqli_fetch_assoc($sqStats)) {
            if ($sr['action'] === 'CREATE') $statCreate = (int)$sr['cnt'];
            elseif ($sr['action'] === 'UPDATE') $statUpdate = (int)$sr['cnt'];
            elseif ($sr['action'] === 'DELETE') $statDelete = (int)$sr['cnt'];
            elseif ($sr['action'] === 'LOGIN')  $statLogin  = (int)$sr['cnt'];
        }
    }

    // Total count
    $cq = mysqli_query($conn, "SELECT COUNT(*) as total FROM activity_logs $mainWhere");
    $totalActivities = $cq ? (int)mysqli_fetch_assoc($cq)['total'] : 0;

    // Rows
    $aq = mysqli_query($conn, "SELECT * FROM activity_logs $mainWhere ORDER BY timestamp DESC LIMIT $limit OFFSET $offset");
    if ($aq && mysqli_num_rows($aq) > 0) {
        while ($row = mysqli_fetch_assoc($aq)) {
            $row['entity_id'] = $row['entity_id'] ?? '-';
            $row['old_value'] = $row['old_value'] ?? '—';
            $row['new_value'] = $row['new_value'] ?? '—';
            $activityTableRows .= buildRow($row);
        }
    } else {
        $activityTableRows = "<tr><td colspan='7' style='padding:50px;text-align:center;color:#9ca3af;'>
            <i class='fa-solid fa-clock-rotate-left' style='font-size:32px;display:block;margin-bottom:12px;opacity:0.35;'></i>
            <span style='font-size:14px;font-weight:600;display:block;margin-bottom:6px;color:#6b7280;'>No activity records found</span>
            <span style='font-size:12px;'>Activities will appear here as users perform actions in the system.</span>
        </td></tr>";
    }

    // User dropdown
    $ddWhere = buildRoleWhere($currentRole, $currentUserId, $visibleUserIds, $superAdminIds, $assignedTaskIds, $assignedDealIds);
    $uq = mysqli_query($conn, "SELECT DISTINCT username FROM activity_logs $ddWhere ORDER BY username ASC");
    if ($uq) {
        while ($ur = mysqli_fetch_assoc($uq)) {
            $sel = ($filterUser === $ur['username']) ? ' selected' : '';
            $userFilterOptions .= "<option value='" . htmlspecialchars($ur['username']) . "'$sel>" . htmlspecialchars($ur['username']) . "</option>";
        }
    }

} else {
    // No DB connection
    $activityTableRows = "<tr><td colspan='7' style='padding:50px;text-align:center;color:#ef4444;'>
        <i class='fa-solid fa-database' style='font-size:32px;display:block;margin-bottom:12px;opacity:0.45;'></i>
        <span style='font-size:14px;font-weight:600;display:block;margin-bottom:6px;'>Database connection unavailable</span>
        <span style='font-size:12px;color:#9ca3af;'>Please check your config.php settings.</span>
    </td></tr>";
}

// ── 10. Pagination ─────────────────────────────────────────
$totalPages  = ($totalActivities > 0) ? ceil($totalActivities / $limit) : 1;
$currentPage = $page;

// ── 11. Page subtitle ──────────────────────────────────────
$reportCount  = max(0, count($visibleUserIds) - 1);
$subtitleText = match($currentRole) {
    'super_admin' => 'Full audit trail — all users and all actions.',
    'admin'       => "Your activity + {$reportCount} team member(s) under you (managers and their agents).",
    'manager'     => "Your activity + {$reportCount} agent(s) reporting to you, plus your assigned tasks and deals.",
    'agent'       => 'Your own activity and actions on your assigned tasks and deals.',
    default       => 'Activity log.',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Activity - Systellio CRM</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background-color: #f3f4f6; display: flex; height: 100vh; overflow: hidden; transition: background-color 0.3s, color 0.3s; color: #111827; }

        #toastBox { visibility: hidden; min-width: 250px; background-color: #333; color: #fff; text-align: center; border-radius: 8px; padding: 16px; position: fixed; z-index: 9999; right: 30px; top: 30px; font-size: 14px; font-weight: 600; box-shadow: 0 4px 12px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 10px; transform: translateX(100%); transition: transform 0.4s cubic-bezier(0.68,-0.55,0.265,1.55), visibility 0.4s; }
        #toastBox.show    { visibility: visible; transform: translateX(0); }
        #toastBox.success { background-color: #10b981; }
        #toastBox.error   { background-color: #ef4444; }

        .main-content { flex-grow: 1; display: flex; flex-direction: column; overflow-y: auto; background-color: #f3f4f6; transition: background-color 0.3s; }
        .toggle-btn:hover   { color: #111827; }
        .nav-icon-btn:hover { color: #3b82f6; }
        .user-profile i     { font-size: 24px; color: #3b82f6; }

        #activitySection { padding: 30px; }
        .activity-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px; }
        .activity-title h1 { font-size: 26px; font-weight: 800; letter-spacing: -0.5px; margin-bottom: 4px; }
        .activity-title p  { font-size: 11px; color: #6b7280; font-weight: 500; }

        .stats-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: #fff; border-radius: 10px; padding: 18px 20px; display: flex; align-items: center; gap: 14px; border: 1px solid #e5e7eb; transition: 0.3s; }
        .stat-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); transform: translateY(-1px); }
        .stat-icon { width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
        .stat-icon.green  { background: #dcfce7; color: #10b981; }
        .stat-icon.yellow { background: #fef3c7; color: #f59e0b; }
        .stat-icon.red    { background: #fee2e2; color: #ef4444; }
        .stat-icon.blue   { background: #dbeafe; color: #3b82f6; }
        .stat-number { font-size: 22px; font-weight: 800; color: #111827; }
        .stat-label  { font-size: 11px; color: #6b7280; font-weight: 500; margin-top: 2px; }

        .filter-section { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
        .filter-input  { padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 12px; outline: none; background: #fff; transition: 0.3s; }
        .filter-input:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        .filter-select { padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 12px; outline: none; background: #fff; cursor: pointer; transition: 0.3s; }
        .filter-btn { background-color: #111827; color: #fff; padding: 10px 18px; border-radius: 6px; font-size: 12px; font-weight: 600; border: none; cursor: pointer; transition: 0.3s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .filter-btn:hover { background-color: #1f2937; }
        .filter-btn.grey  { background-color: #6b7280; }
        .filter-btn.grey:hover { background-color: #4b5563; }

        .table-wrapper { border-radius: 8px; overflow: hidden; border: 1px solid #d1d5db; background: #fff; }
        .custom-table { width: 100%; border-collapse: collapse; text-align: center; font-size: 12px; }
        .custom-table th { background-color: #c4f042; padding: 14px 10px; font-weight: 700; color: #000; border-bottom: 1px solid #d1d5db; }
        .custom-table td { padding: 14px 10px; color: #374151; font-weight: 500; vertical-align: middle; border-right: 1px solid rgba(0,0,0,0.05); }
        .custom-table td:last-child { border-right: none; }
        .custom-table tbody tr:nth-child(4n+1) { background-color: #e6fced; }
        .custom-table tbody tr:nth-child(4n+2) { background-color: #fcedf6; }
        .custom-table tbody tr:nth-child(4n+3) { background-color: #fceddb; }
        .custom-table tbody tr:nth-child(4n+4) { background-color: #e6edff; }

        .badge { padding: 4px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .action-create  { background-color: #dcfce7; color: #10b981; }
        .action-update  { background-color: #fef3c7; color: #f59e0b; }
        .action-delete  { background-color: #fee2e2; color: #ef4444; }
        .action-login   { background-color: #dbeafe; color: #3b82f6; }
        .action-logout  { background-color: #e5e7eb; color: #374151; }
        .action-view    { background-color: #f3e8ff; color: #7c3aed; }
        .entity-user    { background-color: #dbeafe; color: #3b82f6; }
        .entity-task    { background-color: #fef3c7; color: #f59e0b; }
        .entity-company { background-color: #dcfce7; color: #10b981; }
        .entity-deal    { background-color: #e9d5ff; color: #a855f7; }
        .entity-contact { background-color: #fee2e2; color: #ef4444; }
        .entity-campaign{ background-color: #f3e8ff; color: #7c3aed; }
        .entity-default { background-color: #e5e7eb; color: #374151; }

        .action-btns { display: flex; justify-content: center; gap: 6px; }
        .btn-view { background-color: #60a5fa; color: #fff; padding: 6px 10px; border-radius: 4px; font-size: 11px; border: none; cursor: pointer; transition: 0.3s; }
        .btn-view:hover { background-color: #3b82f6; }

        .pagination { display: flex; justify-content: center; gap: 5px; margin-top: 20px; }
        .pagination a, .pagination span { padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 12px; text-decoration: none; color: #374151; background: #fff; transition: 0.3s; }
        .pagination a:hover { background-color: #f3f4f6; border-color: #3b82f6; color: #3b82f6; }
        .pagination .active { background-color: #3b82f6; color: #fff; border-color: #3b82f6; }

        /* Modal */
        .modal { display: none; position: fixed; z-index: 2000; inset: 0; background: rgba(11,21,36,0.65); backdrop-filter: blur(4px); align-items: center; justify-content: center; }
        .modal-content { background: #fff; border-radius: 20px; width: 100%; max-width: 660px; box-shadow: 0 32px 64px rgba(0,0,0,0.22); overflow: hidden; animation: modalIn 0.25s cubic-bezier(.34,1.56,.64,1); }
        @keyframes modalIn { from { opacity:0; transform:scale(0.92) translateY(20px); } to { opacity:1; transform:scale(1) translateY(0); } }
        .modal-header { background: linear-gradient(135deg,#0b1524 0%,#1a2e50 100%); padding: 22px 28px; display: flex; align-items: center; justify-content: space-between; }
        .modal-header-left { display: flex; align-items: center; gap: 14px; }
        .modal-header-icon { width: 42px; height: 42px; border-radius: 12px; background: rgba(59,130,246,0.18); display: flex; align-items: center; justify-content: center; font-size: 17px; color: #60a5fa; }
        .modal-header h2 { font-size: 17px; font-weight: 700; color: #fff; }
        .modal-header p  { font-size: 11px; color: #60a5fa; font-weight: 500; margin-top: 2px; }
        .close-btn { width: 34px; height: 34px; border-radius: 8px; border: none; background: rgba(255,255,255,0.1); color: #94a3b8; font-size: 15px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.2s; }
        .close-btn:hover { background: rgba(239,68,68,0.25); color: #f87171; }
        .modal-body   { padding: 26px 28px 20px; }
        .modal-footer { padding: 0 28px 24px; display: flex; justify-content: flex-end; }
        .modal-meta-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 20px; }
        .meta-chip { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; }
        .meta-chip.grey { background: #f1f5f9; color: #475569; }
        .meta-chip i { font-size: 10px; }
        .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .detail-group { display: flex; flex-direction: column; gap: 5px; }
        .full-width   { grid-column: span 2; }
        .detail-label { font-size: 10px; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.6px; }
        .detail-value { background: #f8fafc; border: 1px solid #e9ecef; border-radius: 10px; padding: 10px 14px; font-size: 13px; font-weight: 500; color: #1e293b; word-break: break-word; line-height: 1.5; }
        .detail-value.mono { font-family: 'Courier New', monospace; font-size: 12px; color: #475569; }
        .modal-divider { height: 1px; background: #f1f5f9; margin: 18px 0; }
        .btn-modal-close { padding: 10px 24px; border-radius: 10px; background: #f1f5f9; color: #475569; border: none; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: 0.2s; }
        .btn-modal-close:hover { background: #e2e8f0; color: #1e293b; }

        /* Dark mode */
        body.dark-mode { background-color: #0f172a; color: #f8fafc; }
        body.dark-mode .main-content { background-color: #0f172a; }
        body.dark-mode .stat-card    { background: #1e293b; border-color: #334155; }
        body.dark-mode .stat-number  { color: #f8fafc; }
        body.dark-mode .stat-label   { color: #64748b; }
        body.dark-mode .filter-input, body.dark-mode .filter-select { background: #0f172a; color: #f8fafc; border-color: #334155; }
        body.dark-mode .table-wrapper { border-color: #334155; background: #1e293b; }
        body.dark-mode .custom-table th { background-color: #334155; color: #f8fafc; border-color: #475569; }
        body.dark-mode .custom-table td { color: #cbd5e1; border-color: #334155; }
        body.dark-mode .custom-table tbody tr:nth-child(odd)  { background-color: #0f172a; }
        body.dark-mode .custom-table tbody tr:nth-child(even) { background-color: #1e293b; }
        body.dark-mode .filter-btn      { background-color: #3b82f6; }
        body.dark-mode .filter-btn.grey { background-color: #475569; }
        body.dark-mode .pagination a, body.dark-mode .pagination span { background: #1e293b; color: #cbd5e1; border-color: #334155; }
        body.dark-mode .pagination a:hover { background: #334155; }
        body.dark-mode .modal-content      { background: #1e293b; }
        body.dark-mode .detail-value       { background: #0f172a; border-color: #334155; color: #e2e8f0; }
        body.dark-mode .detail-value.mono  { color: #94a3b8; }
        body.dark-mode .modal-divider      { background: #334155; }
        body.dark-mode .btn-modal-close    { background: #334155; color: #cbd5e1; }
        body.dark-mode .btn-modal-close:hover { background: #475569; color: #f8fafc; }
        body.dark-mode .meta-chip.grey     { background: #334155; color: #94a3b8; }
    </style>
</head>
<body>

<div id="toastBox">
    <i id="toastIcon" class="fa-solid fa-circle-check"></i>
    <span id="toastMsg">Action Successful!</span>
</div>

<?php $activePage = 'user_activity'; include 'sidebar.php'; ?>

<div class="main-content">
    <?php include 'topbar.php'; ?>

    <div id="activitySection">

        <div class="activity-header">
            <div class="activity-title">
                <h1><i class="fa-solid fa-clock-rotate-left" style="margin-right:10px;color:#3b82f6;font-size:22px;"></i>User Activity Log</h1>
                <p><?php echo htmlspecialchars($subtitleText); ?></p>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon green"><i class="fa-solid fa-plus"></i></div>
                <div><div class="stat-number"><?php echo $statCreate; ?></div><div class="stat-label">Create Actions</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon yellow"><i class="fa-solid fa-pen"></i></div>
                <div><div class="stat-number"><?php echo $statUpdate; ?></div><div class="stat-label">Update Actions</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon red"><i class="fa-solid fa-trash"></i></div>
                <div><div class="stat-number"><?php echo $statDelete; ?></div><div class="stat-label">Delete Actions</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fa-solid fa-right-to-bracket"></i></div>
                <div><div class="stat-number"><?php echo $statLogin; ?></div><div class="stat-label">Login Events</div></div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filter-section">
            <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;width:100%;">
                <input type="text" name="search" class="filter-input" placeholder="Search by user, description or entity..." value="<?php echo htmlspecialchars($searchTerm); ?>" style="flex:1;min-width:220px;">
                <select name="user" class="filter-select"><?php echo $userFilterOptions; ?></select>
                <select name="filter" class="filter-select">
                    <option value="all"    <?php echo $filterType==='all'    ?'selected':''; ?>>All Actions</option>
                    <option value="CREATE" <?php echo $filterType==='CREATE' ?'selected':''; ?>>Create</option>
                    <option value="UPDATE" <?php echo $filterType==='UPDATE' ?'selected':''; ?>>Update</option>
                    <option value="DELETE" <?php echo $filterType==='DELETE' ?'selected':''; ?>>Delete</option>
                    <option value="LOGIN"  <?php echo $filterType==='LOGIN'  ?'selected':''; ?>>Login</option>
                    <option value="LOGOUT" <?php echo $filterType==='LOGOUT' ?'selected':''; ?>>Logout</option>
                    <option value="VIEW"   <?php echo $filterType==='VIEW'   ?'selected':''; ?>>View</option>
                </select>
                <button type="submit" class="filter-btn"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                <a href="user_activity.php" class="filter-btn grey"><i class="fa-solid fa-rotate-left"></i> Reset</a>
            </form>
        </div>

        <!-- Table -->
        <div class="table-wrapper">
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>User</th><th>Action</th><th>Entity</th><th>Description</th><th>Timestamp</th><th>IP Address</th><th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php echo $activityTableRows; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php
            $pBase = "user_activity.php?filter=$filterType&search=" . urlencode($searchTerm) . "&user=" . urlencode($filterUser);
            if ($currentPage > 1) {
                echo "<a href='$pBase&page=1'><i class='fa-solid fa-angles-left'></i></a>";
                echo "<a href='$pBase&page=" . ($currentPage - 1) . "'>Prev</a>";
            }
            for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++) {
                echo $i == $currentPage ? "<span class='active'>$i</span>" : "<a href='$pBase&page=$i'>$i</a>";
            }
            if ($currentPage < $totalPages) {
                echo "<a href='$pBase&page=" . ($currentPage + 1) . "'>Next</a>";
                echo "<a href='$pBase&page=$totalPages'><i class='fa-solid fa-angles-right'></i></a>";
            }
            ?>
        </div>
        <?php endif; ?>

        <div style="text-align:center;margin-top:14px;color:#9ca3af;font-size:12px;">
            Showing <?php echo min($limit, max(0, $totalActivities - $offset)); ?> of <?php echo $totalActivities; ?> records
        </div>

    </div>
</div>

<!-- Detail Modal -->
<div id="detailModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-header-icon"><i class="fa-solid fa-clock-rotate-left"></i></div>
                <div>
                    <h2>Activity Details</h2>
                    <p id="modal_subtitle">Audit trail entry</p>
                </div>
            </div>
            <button class="close-btn" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div class="modal-meta-row">
                <span class="badge" id="modal_action_badge">—</span>
                <span class="badge" id="modal_entity_badge">—</span>
                <span class="meta-chip grey"><i class="fa-solid fa-hashtag"></i><span id="modal_entity_id">—</span></span>
                <span class="meta-chip grey"><i class="fa-regular fa-clock"></i><span id="modal_time">—</span></span>
            </div>
            <div class="detail-grid">
                <div class="detail-group">
                    <span class="detail-label"><i class="fa-solid fa-user" style="margin-right:4px;"></i>User</span>
                    <div class="detail-value" id="modal_username">—</div>
                </div>
                <div class="detail-group">
                    <span class="detail-label"><i class="fa-solid fa-network-wired" style="margin-right:4px;"></i>IP Address</span>
                    <div class="detail-value" id="modal_ip">—</div>
                </div>
                <div class="detail-group full-width">
                    <span class="detail-label"><i class="fa-solid fa-align-left" style="margin-right:4px;"></i>Description</span>
                    <div class="detail-value" id="modal_desc">—</div>
                </div>
            </div>
            <div class="modal-divider"></div>
            <div class="detail-grid">
                <div class="detail-group full-width">
                    <span class="detail-label"><i class="fa-solid fa-arrow-left" style="margin-right:4px;color:#ef4444;"></i>Old Value</span>
                    <div class="detail-value mono" id="modal_old">—</div>
                </div>
                <div class="detail-group full-width">
                    <span class="detail-label"><i class="fa-solid fa-arrow-right" style="margin-right:4px;color:#10b981;"></i>New Value</span>
                    <div class="detail-value mono" id="modal_new">—</div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i> Close</button>
        </div>
    </div>
</div>

<script>
    var actionBadgeMap = {
        'CREATE':'badge action-create','UPDATE':'badge action-update',
        'DELETE':'badge action-delete','LOGIN':'badge action-login',
        'LOGOUT':'badge action-logout','VIEW':'badge action-view'
    };
    var entityBadgeMap = {
        'User':'badge entity-user','Task':'badge entity-task',
        'Company':'badge entity-company','Deal':'badge entity-deal',
        'Contact':'badge entity-contact','Campaign':'badge entity-campaign',
        'Auth':'badge action-login'
    };

    function openDetailModal(data) {
        var action = (data.action || '').toUpperCase();
        var entity = data.entity_type || '—';

        var ab = document.getElementById('modal_action_badge');
        ab.textContent = action;
        ab.className   = actionBadgeMap[action] || 'badge entity-default';

        var eb = document.getElementById('modal_entity_badge');
        eb.textContent = entity;
        eb.className   = entityBadgeMap[entity] || 'badge entity-default';

        document.getElementById('modal_subtitle').textContent  = action + ' · ' + entity;
        document.getElementById('modal_entity_id').textContent = data.entity_id  || '—';
        document.getElementById('modal_time').textContent      = data.timestamp  || '—';
        document.getElementById('modal_username').textContent  = data.username   || '—';
        document.getElementById('modal_ip').textContent        = data.ip_address || '—';
        document.getElementById('modal_desc').textContent      = data.description || '—';
        document.getElementById('modal_old').textContent       = data.old_value  || '—';
        document.getElementById('modal_new').textContent       = data.new_value  || '—';

        document.getElementById('detailModal').style.display = 'flex';
    }

    function closeModal() {
        document.getElementById('detailModal').style.display = 'none';
    }

    document.getElementById('detailModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeModal();
    });
</script>
</body>
</html>