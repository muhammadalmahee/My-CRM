<?php
// ========================================================================
// 1. INITIALIZATION & SECURITY CHECK
// ========================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
@include 'config.php'; 

if (!isset($_SESSION['user_id'])) {
    // header("Location: login.php");
    // exit();
}

$toastMessage = "";
$toastType = "";

// ── Role & user info ──
$_currentRole     = strtolower(trim($_SESSION['role']     ?? ''));
$_currentUsername = $_SESSION['username'] ?? '';
$_currentName     = $_SESSION['name']     ?? '';
$_currentUserId   = (int)($_SESSION['user_id'] ?? 0);
$_isAgent         = ($_currentRole === 'agent');
$_isManager       = ($_currentRole === 'manager');
$_isAdmin         = ($_currentRole === 'admin');
$_isSuperAdmin    = ($_currentRole === 'super_admin');

// ── Fetch manager's sub-users (agents under this manager) ──
$_managerSubUsernames = []; // username list only (for validation)
$_managerSubNames     = []; // name list only
if ($_isManager && isset($conn)) {
    try {
        $_escMgrUsername = mysqli_real_escape_string($conn, $_currentUsername);
        $_escMgrName     = mysqli_real_escape_string($conn, $_currentName);
        $sub_q = mysqli_query($conn, "SELECT username, name FROM users WHERE status='active' AND role='agent' AND (
            manager_id = '$_currentUserId'
            OR parent_id = '$_currentUserId'
            OR reporting_to = '$_escMgrUsername'
            OR reporting_to = '$_escMgrName'
        )");
        if ($sub_q) {
            while ($sRow = mysqli_fetch_assoc($sub_q)) {
                $_managerSubUsernames[] = mysqli_real_escape_string($conn, $sRow['username']);
                $_managerSubNames[]     = mysqli_real_escape_string($conn, $sRow['name']);
            }
        }
    } catch (mysqli_sql_exception $e) {}
}
$_managerSubAll = array_unique(array_merge($_managerSubUsernames, $_managerSubNames));

// ── Fetch all users for assign dropdown ──
$_assignUserOptions = '';
if (isset($conn) && ($_isAdmin || $_isManager || $_isSuperAdmin)) {
    try {
        $_escAsnU = mysqli_real_escape_string($conn, $_currentUsername);
        $_escAsnN = mysqli_real_escape_string($conn, $_currentName);
        if ($_isSuperAdmin) {
            $users_q = mysqli_query($conn, "SELECT id, username, name, role FROM users WHERE status='active' ORDER BY name ASC");
        } elseif ($_isAdmin) {
            // Admin-এর under-এর manager ও agent — reporting_to, manager_id, parent_id, created_by সব দিয়ে
            $users_q = mysqli_query($conn, "SELECT id, username, name, role FROM users WHERE status='active'
                AND role IN ('manager','agent')
                AND (manager_id='$_currentUserId' OR parent_id='$_currentUserId'
                     OR reporting_to='$_escAsnU' OR reporting_to='$_escAsnN'
                     OR created_by='$_currentUserId')
                ORDER BY name ASC");
        } else {
            // Manager-এর under-এর agent — reporting_to, manager_id, parent_id সব দিয়ে
            $users_q = mysqli_query($conn, "SELECT id, username, name, role FROM users WHERE status='active'
                AND role='agent'
                AND (manager_id='$_currentUserId' OR parent_id='$_currentUserId'
                     OR reporting_to='$_escAsnU' OR reporting_to='$_escAsnN')
                ORDER BY name ASC");
        }
        if ($users_q) {
            while ($uRow = mysqli_fetch_assoc($users_q)) {
                $uName     = htmlspecialchars($uRow['name']);
                $uUsername = htmlspecialchars($uRow['username']);
                $uRole     = htmlspecialchars($uRow['role']);
                // value = username (used for sales_officer field)
                $_assignUserOptions .= "<option value='" . htmlspecialchars($uRow['username']) . "' data-name='" . htmlspecialchars($uRow['name']) . "'>{$uName} ({$uRole})</option>";
            }
        }
    } catch (mysqli_sql_exception $e) {}
}

// ========================================================================
// ACTIVITY LOG HELPER
// ========================================================================
function logActivity($action, $description, $entity_type, $entity_id, $old_value = null, $new_value = null) {
    global $conn;
    if (!isset($conn) || !isset($_SESSION['user_id'])) return false;
    $user_id     = (int)$_SESSION['user_id'];
    $username    = mysqli_real_escape_string($conn, $_SESSION['name'] ?? 'Unknown');
    $ip          = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $action      = mysqli_real_escape_string($conn, $action);
    $description = mysqli_real_escape_string($conn, $description);
    $entity_type = mysqli_real_escape_string($conn, $entity_type);
    $entity_id   = (int)$entity_id;
    $old_val     = mysqli_real_escape_string($conn, $old_value ?? '');
    $new_val     = mysqli_real_escape_string($conn, $new_value ?? '');
    return mysqli_query($conn,
        "INSERT INTO activity_logs (user_id, username, action, description, entity_type, entity_id, old_value, new_value, ip_address)
         VALUES ('$user_id','$username','$action','$description','Deal','$entity_id','$old_val','$new_val','$ip')");
}

// ========================================================================
// 2. DEAL PIPELINE LOGIC (CREATE, EDIT & DELETE)
// ========================================================================

// A. Create Deal — Admin & Manager
// Ensure deals.created_by column exists
if (isset($conn)) {
    $_deals_cols = []; $_deals_cr = mysqli_query($conn, 'SHOW COLUMNS FROM deals');
    if ($_deals_cr) { while ($_dc = mysqli_fetch_assoc($_deals_cr)) $_deals_cols[] = $_dc['Field']; }
    if (!in_array('created_by', $_deals_cols)) mysqli_query($conn, 'ALTER TABLE deals ADD COLUMN created_by INT DEFAULT NULL');
}
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_deal']) && ($_isAdmin || $_isSuperAdmin || $_isManager)) {
    if(isset($conn)){
        $deal_name        = mysqli_real_escape_string($conn, $_POST['project_name'] ?? '');
        $link_company     = mysqli_real_escape_string($conn, $_POST['link_company'] ?? '');
        $service_required = mysqli_real_escape_string($conn, $_POST['service_required'] ?? '');
        $deal_value       = (float)($_POST['total_amount'] ?? 0);
        $currency         = mysqli_real_escape_string($conn, $_POST['currency'] ?? 'USD');
        $start_date       = mysqli_real_escape_string($conn, $_POST['start_date'] ?? '');
        $end_date         = mysqli_real_escape_string($conn, $_POST['end_date'] ?? '');
        $stage            = mysqli_real_escape_string($conn, $_POST['project_status'] ?? 'Lead');
        $platform         = mysqli_real_escape_string($conn, $_POST['platform'] ?? '');
        $sales_officer    = mysqli_real_escape_string($conn, $_POST['sales_officer'] ?? '');
        $notes            = mysqli_real_escape_string($conn, $_POST['additional_notes'] ?? '');

        $sql = "INSERT INTO deals (deal_name, deal_value, stage, link_company, service_required, currency, start_date, end_date, platform, sales_officer, additional_notes, created_by) 
                VALUES ('$deal_name', '$deal_value', '$stage', '$link_company', '$service_required', '$currency', '$start_date', '$end_date', '$platform', '$sales_officer', '$notes', '$_currentUserId')";
        try {
            if(mysqli_query($conn, $sql)){
                $new_id = mysqli_insert_id($conn);
                logActivity('CREATE', "Created new deal: {$_POST['project_name']} — Company: {$_POST['link_company']}, Value: {$currency} {$deal_value}", 'Deal', $new_id, '—', "Stage: $stage");
                $toastMessage = "Deal added successfully!";
                $toastType    = "success";
            }
        } catch (mysqli_sql_exception $e) {
            $toastMessage = "DB Error! " . $e->getMessage();
            $toastType    = "error";
        }
    }
}

// B. Edit/Update Deal — Admin & Manager can edit
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_deal']) && ($_isAdmin || $_isManager || $_isSuperAdmin)) {
    if(isset($conn)){
        $edit_id          = (int)($_POST['edit_deal_id'] ?? 0);
        $deal_name        = mysqli_real_escape_string($conn, $_POST['edit_project_name'] ?? '');
        $link_company     = mysqli_real_escape_string($conn, $_POST['edit_link_company'] ?? '');
        $service_required = mysqli_real_escape_string($conn, $_POST['edit_service_required'] ?? '');
        $deal_value       = (float)($_POST['edit_total_amount'] ?? 0);
        $currency         = mysqli_real_escape_string($conn, $_POST['edit_currency'] ?? 'USD');
        $start_date       = mysqli_real_escape_string($conn, $_POST['edit_start_date'] ?? '');
        $end_date         = mysqli_real_escape_string($conn, $_POST['edit_end_date'] ?? '');
        $stage            = mysqli_real_escape_string($conn, $_POST['edit_project_status'] ?? 'Lead');
        $platform         = mysqli_real_escape_string($conn, $_POST['edit_platform'] ?? '');
        $sales_officer    = mysqli_real_escape_string($conn, $_POST['edit_sales_officer'] ?? '');
        $notes            = mysqli_real_escape_string($conn, $_POST['edit_additional_notes'] ?? '');

        // old data for audit log
        $old_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM deals WHERE id='$edit_id'"));

        $sql = "UPDATE deals SET 
                    deal_name='$deal_name',
                    link_company='$link_company',
                    service_required='$service_required',
                    deal_value='$deal_value',
                    currency='$currency',
                    start_date='$start_date',
                    end_date='$end_date',
                    stage='$stage',
                    platform='$platform',
                    sales_officer='$sales_officer',
                    additional_notes='$notes'
                WHERE id='$edit_id'";
        try {
            if(mysqli_query($conn, $sql)){
                $old_summary = $old_row ? "Stage: {$old_row['stage']}, Value: {$old_row['currency']} {$old_row['deal_value']}" : '—';
                logActivity('UPDATE', "Updated deal: {$_POST['edit_project_name']} — Company: {$_POST['edit_link_company']}", 'Deal', $edit_id, $old_summary, "Stage: $stage, Value: $currency $deal_value");
                $toastMessage = "Deal updated successfully!";
                $toastType    = "success";
            } else {
                $toastMessage = "Error updating deal!";
                $toastType    = "error";
            }
        } catch (mysqli_sql_exception $e) {
            $toastMessage = "DB Error! " . $e->getMessage();
            $toastType    = "error";
        }
    }
}

// C. Delete Deal — Admin only
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_deal']) && ($_isAdmin || $_isSuperAdmin)) {
    if(isset($conn)){
        $del_id     = (int)($_POST['delete_deal_id'] ?? 0);
        // old data for log
        $del_row    = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM deals WHERE id='$del_id'"));
        $delete_sql = "DELETE FROM deals WHERE id='$del_id'";
        if(mysqli_query($conn, $delete_sql)){
            $del_name = $del_row ? $del_row['deal_name'] : "ID $del_id";
            logActivity('DELETE', "Deleted deal: $del_name", 'Deal', $del_id, $del_row ? "Stage: {$del_row['stage']}, Value: {$del_row['currency']} {$del_row['deal_value']}" : '—', '—');
            $toastMessage = "Deal deleted successfully!";
            $toastType    = "success";
        } else {
            $toastMessage = "Error deleting deal!";
            $toastType    = "error";
        }
    }
}

// D. Assign Deal to User — Admin (sub-users only) or Manager (sub-users only)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_deal']) && ($_isAdmin || $_isManager || $_isSuperAdmin)) {
    if (isset($conn)) {
        $assign_deal_id  = (int)($_POST['assign_deal_id'] ?? 0);
        $assign_to       = mysqli_real_escape_string($conn, $_POST['assign_to_user'] ?? '');

        // Fetch admin sub-user usernames+names for validation
        $_adminSubAll = [];
        if ($_isAdmin) {
            $_escAdmU = mysqli_real_escape_string($conn, $_currentUsername);
            $_escAdmN = mysqli_real_escape_string($conn, $_currentName);
            $sub_q2 = mysqli_query($conn, "SELECT username, name FROM users WHERE status='active' AND role IN ('manager','agent') AND (
                manager_id = '$_currentUserId' OR parent_id = '$_currentUserId'
                OR reporting_to = '$_escAdmU' OR reporting_to = '$_escAdmN'
                OR created_by = '$_currentUserId'
            )");
            if ($sub_q2) {
                while ($sRow2 = mysqli_fetch_assoc($sub_q2)) {
                    $_adminSubAll[] = mysqli_real_escape_string($conn, $sRow2['username']);
                    $_adminSubAll[] = mysqli_real_escape_string($conn, $sRow2['name']);
                }
            }
            $_adminSubAll = array_unique($_adminSubAll);
        }

        // super_admin can assign to anyone; admin/manager restricted to sub-users
        $allowed = false;
        if ($_isSuperAdmin) {
            $allowed = !empty($assign_to);
        } elseif ($_isAdmin && !empty($_adminSubAll)) {
            $allowed = in_array($assign_to, $_adminSubAll);
        } elseif ($_isManager && !empty($_managerSubAll)) {
            $allowed = in_array($assign_to, $_managerSubAll);
        }

        if ($allowed && $assign_deal_id > 0 && !empty($assign_to)) {
            $old_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT sales_officer FROM deals WHERE id='$assign_deal_id'"));
            if (mysqli_query($conn, "UPDATE deals SET sales_officer='$assign_to' WHERE id='$assign_deal_id'")) {
                logActivity('ASSIGN', "Assigned deal ID {$assign_deal_id} to: {$assign_to}", 'Deal', $assign_deal_id, $old_row['sales_officer'] ?? '—', $assign_to);
                $toastMessage = "Deal assigned successfully!";
                $toastType    = "success";
            } else {
                $toastMessage = "Error assigning deal!";
                $toastType    = "error";
            }
        } else {
            $toastMessage = "Permission denied or invalid selection.";
            $toastType    = "error";
        }
    }
}


$hasDeals           = false;
$dealTableRows      = "";
$totalDeals         = "0";
$totalPipelineValue = 0;

// Fetch companies for dropdown — role-based
$companyOptions = "";
if(isset($conn)){
        // Ensure created_by column exists
        $_comp_cols2 = []; $_comp_cr2 = mysqli_query($conn, 'SHOW COLUMNS FROM companies');
        if ($_comp_cr2) { while ($_cc2 = mysqli_fetch_assoc($_comp_cr2)) $_comp_cols2[] = $_cc2['Field']; }
        if (!in_array('created_by', $_comp_cols2)) mysqli_query($conn, 'ALTER TABLE companies ADD COLUMN created_by INT DEFAULT NULL');
    try {
        $_escDlUsername = mysqli_real_escape_string($conn, $_currentUsername);
        $_escDlName     = mysqli_real_escape_string($conn, $_currentName);

        // status column না থাকলে বা NULL হলেও দেখাবে
        $_statusCheck = "(c.status = 'active' OR c.status IS NULL)";

        if ($_isSuperAdmin) {
            $comp_q = mysqli_query($conn, "SELECT id, company_name FROM companies c WHERE $_statusCheck ORDER BY company_name ASC");

        } elseif ($_isAdmin) {
            // admin নিজের + under-এর managers এর create করা companies দেখবে
            $_dlAdminSubIds = [$_currentUserId];
            $_dlSubQ = mysqli_query($conn, "SELECT id FROM users WHERE status='active'
                AND role = 'manager'
                AND (reporting_to='$_escDlUsername' OR reporting_to='$_escDlName'
                     OR manager_id='$_currentUserId' OR parent_id='$_currentUserId'
                     OR created_by=$_currentUserId)");
            if ($_dlSubQ) { while ($_dlSubR = mysqli_fetch_assoc($_dlSubQ)) $_dlAdminSubIds[] = (int)$_dlSubR['id']; }
            $_dlAdminSubIdsStr = implode(',', $_dlAdminSubIds);
            $comp_q = mysqli_query($conn, "SELECT id, company_name FROM companies c WHERE $_statusCheck AND c.created_by IN ($_dlAdminSubIdsStr) ORDER BY company_name ASC");

        } elseif ($_isManager) {
            $comp_q = mysqli_query($conn, "SELECT id, company_name FROM companies c WHERE $_statusCheck AND (
                c.created_by = '$_currentUserId'
                OR c.assigned_agent LIKE '%$_escDlName%'
                OR c.assigned_agent LIKE '%$_escDlUsername%'
            ) ORDER BY company_name ASC");

        } elseif ($_isAgent) {
            $comp_q = mysqli_query($conn, "SELECT id, company_name FROM companies c WHERE $_statusCheck AND (
                c.assigned_agent LIKE '%$_escDlName%'
                OR c.assigned_agent LIKE '%$_escDlUsername%'
            ) ORDER BY company_name ASC");

        } else {
            $comp_q = false;
        }

        if($comp_q && mysqli_num_rows($comp_q) > 0){
            while($cRow = mysqli_fetch_assoc($comp_q)){
                $companyOptions .= "<option value='" . htmlspecialchars($cRow['company_name']) . "'>" . htmlspecialchars($cRow['company_name']) . "</option>";
            }
        }
    } catch (mysqli_sql_exception $e) {}
}

if(isset($conn)){
    try {
        if ($_isSuperAdmin) {
            // Super admin sees all deals
            $deal_query_sql = "SELECT * FROM deals ORDER BY id DESC";
        } elseif ($_isAgent) {
            // Agent: শুধু সেই deals দেখবে যেগুলো তাকে sales_officer হিসেবে assign করা হয়েছে
            $deal_query_sql = "SELECT * FROM deals
               WHERE deals.sales_officer = '" . mysqli_real_escape_string($conn, $_currentUsername) . "'
                  OR deals.sales_officer = '" . mysqli_real_escape_string($conn, $_currentName) . "'
               ORDER BY deals.id DESC";
        } elseif ($_isManager) {
            // Manager: নিজের create করা deals + admin assign করা deals (sales_officer) + sub-agents এর deals
            $managerNames = array_map(fn($u) => "'$u'", array_unique(array_merge(
                [mysqli_real_escape_string($conn, $_currentUsername), mysqli_real_escape_string($conn, $_currentName)],
                $_managerSubAll
            )));
            $namesIn = implode(',', $managerNames);
            $deal_query_sql = "SELECT DISTINCT deals.* FROM deals
               LEFT JOIN campaigns ON campaigns.deal_id = deals.id
               WHERE deals.created_by = '$_currentUserId'
                  OR deals.sales_officer IN ($namesIn)
                  OR campaigns.assigned_to IN ($namesIn)
               ORDER BY deals.id DESC";
        } else {
            // Admin: নিজের + under-এর সব manager ও agent এর create করা deals দেখবে
            $_escapedAdminUsername = mysqli_real_escape_string($conn, $_currentUsername);
            $_escapedAdminName     = mysqli_real_escape_string($conn, $_currentName);

            // Admin এর under এর সব manager + agent id collect করো
            $_adminSubIds = [$_currentUserId];
            $_adSubQ = mysqli_query($conn, "SELECT id FROM users WHERE status='active'
                AND role IN ('manager', 'agent')
                AND (reporting_to='$_escapedAdminUsername' OR reporting_to='$_escapedAdminName'
                     OR manager_id='$_currentUserId' OR parent_id='$_currentUserId'
                     OR created_by=$_currentUserId)");
            if ($_adSubQ) { while ($_adSubR = mysqli_fetch_assoc($_adSubQ)) $_adminSubIds[] = (int)$_adSubR['id']; }
            $_adminSubIdsStr = implode(',', $_adminSubIds);
            $deal_query_sql = "SELECT * FROM deals WHERE created_by IN ($_adminSubIdsStr) ORDER BY id DESC";
        }
        $deal_query = mysqli_query($conn, $deal_query_sql);
        $wonDeals = 0;
        if($deal_query && mysqli_num_rows($deal_query) > 0){
            $hasDeals   = true;
            $totalDeals = mysqli_num_rows($deal_query);
            
            while($row = mysqli_fetch_assoc($deal_query)){
                if(($row['stage'] ?? '') === 'Won') $wonDeals++;
                $d_name       = htmlspecialchars($row['deal_name']);
                $d_company    = htmlspecialchars($row['link_company'] ?? '-');
                $d_service    = htmlspecialchars($row['service_required'] ?? '-');
                $d_platform   = htmlspecialchars($row['platform'] ?? '-');
                $d_officer    = htmlspecialchars($row['sales_officer'] ?? '-');
                $d_notes_raw  = $row['additional_notes'] ?? '';
                $d_notes      = htmlspecialchars($d_notes_raw);
                $d_currency   = htmlspecialchars($row['currency'] ?? 'USD');
                $d_start_raw  = $row['start_date'] ?? '';
                $d_end_raw    = $row['end_date'] ?? '';
                $d_start      = !empty($d_start_raw) ? date('d M Y', strtotime($d_start_raw)) : '-';
                $d_end        = !empty($d_end_raw)   ? date('d M Y', strtotime($d_end_raw))   : '-';
                $d_value_raw  = $row['deal_value'];
                $totalPipelineValue += $d_value_raw;
                $d_value      = number_format($d_value_raw, 2);
                $d_stage      = htmlspecialchars($row['stage']);
                $d_id         = $row['id'];

                // Stage color
                $stage_class  = 'todo';
                if($d_stage == 'Lead')        $stage_class = 'todo';
                if($d_stage == 'Proposal')    $stage_class = 'progress';
                if($d_stage == 'Negotiation') $stage_class = 'medium';
                if($d_stage == 'Won')         $stage_class = 'low';
                if($d_stage == 'Lost')        $stage_class = 'emergency';

                // Build JSON-safe data attribute for edit
                $edit_data = json_encode([
                    'id'       => $d_id,
                    'name'     => $row['deal_name'],
                    'company'  => $row['link_company'] ?? '',
                    'service'  => $row['service_required'] ?? '',
                    'amount'   => $d_value_raw,
                    'currency' => $row['currency'] ?? 'USD',
                    'start'    => $d_start_raw,
                    'end'      => $d_end_raw,
                    'stage'    => $row['stage'],
                    'platform' => $row['platform'] ?? '',
                    'officer'  => $row['sales_officer'] ?? '',
                    'notes'    => $d_notes_raw,
                ], JSON_HEX_QUOT | JSON_HEX_APOS);

                $dealTableRows .= "<tr>
                    <td style='text-align:center;font-weight:700;color:#6b7280;font-size:11px;'>#{$d_id}</td>
                    <td><b>{$d_name}</b><br><small style='color:#9ca3af;font-weight:400;'>{$d_company}</small></td>
                    <td style='font-weight: 700; color: #10b981;'>{$d_currency} {$d_value}</td>
                    <td><span class='pill {$stage_class}'>{$d_stage}</span></td>
                    <td>{$d_service}</td>
                    <td>{$d_platform}</td>
                    <td>{$d_officer}</td>
                    <td>{$d_start}<br><small style='color:#9ca3af;'>→ {$d_end}</small></td>
                    <td style='text-align: right;'>
                        <div class='action-btns' style='justify-content: flex-end;'>
                            <button class='comp-icon-btn view' title='View'
                                onclick=\"openViewModal({$d_id}, '{$d_name}', '{$d_company}', '{$d_service}', '{$d_platform}', '{$d_officer}', '{$d_currency} {$d_value}', '{$d_stage}', '{$d_start}', '{$d_end}', `{$d_notes}`)\">
                                <i class='fa-regular fa-eye'></i>
                            </button>"
                            . (($_isAdmin || $_isManager || $_isSuperAdmin) ? "
                            <button class='comp-icon-btn' title='Assign' style='background:#eff6ff;color:#3b82f6;border:1px solid #bfdbfe;'
                                onclick='openAssignModal({$d_id}, \"{$d_name}\", \"{$d_officer}\")'>
                                <i class='fa-solid fa-user-plus'></i>
                            </button>" : "")
                            . (($_isAdmin || $_isManager || $_isSuperAdmin) ? "
                            <button class='comp-icon-btn edit' title='Edit'
                                onclick='openEditModal(" . json_encode($edit_data) . ")'>
                                <i class='fa-solid fa-pen-to-square'></i>
                            </button>" : "")
                            . (($_isAdmin || $_isSuperAdmin) ? "
                            <form method='POST' id='delete-deal-{$d_id}' style='display:inline;'>
                                <input type='hidden' name='delete_deal_id' value='{$d_id}'>
                                <input type='hidden' name='delete_deal' value='1'>
                                <button type='button' class='comp-icon-btn delete' onclick='confirmDelete(\"delete-deal-{$d_id}\", \"deal\")' title='Delete'><i class='fa-solid fa-trash'></i></button>
                            </form>" : "") . "
                        </div>
                    </td>
                </tr>";
            }
        }
    } catch(mysqli_sql_exception $e) {}
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deal Pipeline - Systellio CRM</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background-color: #f3f4f6; display: flex; height: 100vh; overflow: hidden; transition: background-color 0.3s, color 0.3s; color: #111827; }

        #toastBox { visibility: hidden; min-width: 250px; background-color: #333; color: #fff; text-align: center; border-radius: 8px; padding: 16px; position: fixed; z-index: 9999; right: 30px; top: 30px; font-size: 14px; font-weight: 600; box-shadow: 0 4px 12px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 10px; transform: translateX(100%); transition: transform 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55), visibility 0.4s; }
        #toastBox.show { visibility: visible; transform: translateX(0); }
        #toastBox.success { background-color: #10b981; }
        #toastBox.error { background-color: #ef4444; }

        .main-content { flex-grow: 1; display: flex; flex-direction: column; overflow-y: auto; transition: background-color 0.3s ease; background-color: #f3f4f6; }
        .notification-badge { position: absolute; top: -4px; right: -4px; background-color: #ef4444; color: white; font-size: 9px; font-weight: bold; padding: 2px 5px; border-radius: 50%; border: 2px solid #ffffff; }
        body.dark-mode .notification-badge { border-color: #1e293b; }

        /* ========== DEAL PIPELINE SPECIFIC ========== */
        .company-container { padding: 30px; display: block; }
        .comp-header-title h1 { font-size: 26px; font-weight: 800; margin-bottom: 4px; letter-spacing: -0.5px; transition: 0.3s; color: #111827;}
        .comp-header-title p { font-size: 13px; color: #6b7280; font-weight: 500; }
        .user-list-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .header-buttons { display: flex; gap: 10px; }
        .btn-add-client { background-color: #0f172a; color: #ffffff; padding: 10px 18px; border-radius: 6px; font-size: 13px; font-weight: 700; border: none; cursor: pointer; display: flex; align-items: center; gap: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.12); transition: background-color 0.2s, transform 0.1s; }
        .btn-add-client:hover { background-color: #1e293b; transform: translateY(-1px); }
        .btn-add-client i { font-size: 13px; }
        .comp-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;}
        .comp-search { position: relative; width: 300px; }
        .comp-search i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #9ca3af; font-size: 14px;}
        .comp-search input { width: 100%; padding: 10px 15px 10px 38px; border: 1px solid #d1d5db; border-radius: 20px; font-size: 13px; font-family: 'Inter', sans-serif; outline: none; transition: 0.3s; color: #374151;}
        .comp-search input:focus { border-color: #3b82f6; box-shadow: 0 0 0 2px #eff6ff;}
        .comp-total { font-size: 13px; font-weight: 600; color: #4b5563; background: #ffffff; border: 1px solid #d1d5db; padding: 8px 15px; border-radius: 20px;}

        /* Cards */
        .cards-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 14px; margin-bottom: 20px; }
        .card { background-color: #ffffff; padding: 14px 16px; border-radius: 8px; box-shadow: 0px 2px 8px rgba(0, 0, 0, 0.04); display: flex; align-items: center; justify-content: space-between; border: 1px solid #e5e7eb; transition: 0.3s;}
        .card-info h4 { font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 4px; font-weight: 700; }
        .card-info h2 { font-size: 20px; font-weight: 800; transition: 0.3s;}
        .card-icon { background-color: #eff6ff; width: 36px; height: 36px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 15px; color: #3b82f6; transition: 0.3s; flex-shrink: 0;}

        /* Table */
        .table-wrapper { border-radius: 8px; overflow: hidden; border: 1px solid #d1d5db; transition: 0.3s; background: #ffffff;}
        .custom-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 12px; }
        .custom-table th { background-color: #f9fafb; padding: 9px 12px; font-weight: 700; color: #374151; border-bottom: 1px solid #e5e7eb; transition: 0.3s; text-transform: uppercase; letter-spacing: 0.5px; font-size: 10px;}
        .custom-table td { padding: 9px 12px; color: #374151; font-weight: 500; vertical-align: middle; border-right: 1px solid rgba(0,0,0,0.05); transition: 0.3s; font-size: 12px;}
        .custom-table td:last-child { border-right: none; }
        .custom-table th:first-child, .custom-table td:first-child { width: 50px; text-align: center; }
        .custom-table tbody tr { background-color: #ffffff; }
        .custom-table tbody tr:nth-child(4n+1) { background-color: #e6fced; }
        .custom-table tbody tr:nth-child(4n+2) { background-color: #fcedf6; }
        .custom-table tbody tr:nth-child(4n+3) { background-color: #fceddb; }
        .custom-table tbody tr:nth-child(4n+4) { background-color: #e6edff; }
        .custom-table tbody tr:hover { background-color: #f0f7ff; }

        /* Badges */
        .pill { padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; display: inline-block; letter-spacing: 0.5px;}
        .pill.high      { background: #fee2e2; color: #ef4444; }
        .pill.medium    { background: #fef3c7; color: #f59e0b; }
        .pill.low       { background: #d1fae5; color: #10b981; }
        .pill.emergency { background: #ef4444; color: #ffffff; }
        .pill.todo      { background: #e5e7eb; color: #4b5563; }
        .pill.progress  { background: #dbeafe; color: #2563eb; }

        /* Icon Buttons */
        .comp-icon-btn { width: 32px; height: 32px; border-radius: 4px; border: 1px solid #e5e7eb; background: transparent; cursor: pointer; display: inline-flex; justify-content: center; align-items: center; transition: 0.3s; margin-right: 5px;}
        .comp-icon-btn.view   { color: #3b82f6; }
        .comp-icon-btn.view:hover   { background: #eff6ff; border-color: #3b82f6; }
        .comp-icon-btn.edit   { color: #f59e0b; }
        .comp-icon-btn.edit:hover   { background: #fffbeb; border-color: #f59e0b; }
        .comp-icon-btn.delete { color: #ef4444; }
        .comp-icon-btn.delete:hover { background: #fef2f2; border-color: #ef4444; }

        /* ========== ADD / EDIT DEAL MODAL — Wizard ========== */
        .modal { display:none; position:fixed; z-index:2000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,.5); align-items:center; justify-content:center; }
        .modal-content { background:#fff; padding:28px; border-radius:10px; width:100%; max-width:700px; box-shadow:0 10px 25px rgba(0,0,0,.15); max-height:90vh; overflow-y:auto; }
        .modal-content.deal-modal-content { max-width:800px; padding:0; overflow:visible; max-height:none; display:flex; flex-direction:column; }
        .deal-modal-header-wrap { background:#f4f6fb; padding:18px 28px 16px; border-bottom:1px solid #e5e7eb; }
        .deal-modal-top { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px; }
        .deal-modal-top h2 { font-size:18px; font-weight:800; color:#111827; margin-bottom:2px; }
        .deal-modal-top p  { font-size:12px; color:#6b7280; }
        .deal-progress-bar { display:flex; justify-content:space-between; position:relative; padding:0 10px; }
        .deal-progress-bar::before { content:''; position:absolute; top:15px; left:0; width:100%; height:2px; background:#e5e7eb; z-index:1; }
        .deal-progress-step { width:32px; height:32px; background:#fff; border:2px solid #e5e7eb; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; color:#9ca3af; z-index:2; position:relative; transition:all .3s; }
        .deal-progress-step.active    { border-color:#10b981; color:#10b981; background:#ecfdf5; }
        .deal-progress-step.completed { background:#10b981; border-color:#10b981; color:#fff; }
        .deal-step-label-row { display:flex; justify-content:space-between; padding:5px 4px 0; }
        .deal-step-label-row span { font-size:10px; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:.4px; text-align:center; flex:1; }
        .deal-step-label-row span.active-lbl { color:#10b981; }
        .deal-modal-body { padding:16px 28px; background:#fff; }
        .deal-step-container { display:none; }
        .deal-step-container.deal-step-active { display:block; animation:dealFade .35s ease; }
        @keyframes dealFade { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }
        .deal-section-title { display:flex; align-items:center; gap:8px; font-size:12px; font-weight:700; color:#10b981; text-transform:uppercase; letter-spacing:.6px; margin-bottom:10px; padding-bottom:7px; border-bottom:1px solid #d1fae5; }
        .deal-form-grid      { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .deal-form-grid.full { grid-template-columns:1fr; }
        .deal-form-group     { margin-bottom:2px; }
        .deal-form-group label { display:block; font-size:11px; font-weight:700; color:#4b5563; text-transform:uppercase; letter-spacing:.5px; margin-bottom:5px; }
        .deal-form-group input,
        .deal-form-group select,
        .deal-form-group textarea { width:100%; padding:9px 13px; border:none; background:#f4f6fb; border-radius:6px; font-size:13px; font-family:'Inter',sans-serif; color:#1f2937; outline:none; transition:.25s; box-shadow:inset 0 0 0 1px transparent; }
        .deal-form-group input:focus,
        .deal-form-group select:focus,
        .deal-form-group textarea:focus { box-shadow:inset 0 0 0 1.5px #10b981; background:#fff; }
        .deal-form-group textarea { resize:vertical; min-height:60px; }
        .deal-modal-footer { display:flex; justify-content:space-between; align-items:center; padding:14px 28px; border-top:1px solid #e5e7eb; background:#fff; }
        .deal-btn-cancel,.deal-btn-back { background:transparent; border:none; color:#6b7280; font-size:13px; font-weight:600; cursor:pointer; transition:.2s; padding:0; }
        .deal-btn-cancel:hover,.deal-btn-back:hover { color:#111827; }
        .deal-btn-next { background:#10b981; color:#fff; padding:10px 22px; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition:.2s; }
        .deal-btn-next:hover { background:#059669; }
        .deal-btn-save { background:#0f172a; color:#fff; padding:10px 22px; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition:.2s; }
        .deal-btn-save:hover { background:#1e293b; }

        /* Edit modal header accent — same green as Add New */
        .edit-header-wrap { background:#f4f6fb; border-bottom-color: #e5e7eb; }
        .edit-section-title { color: #10b981; border-color: #d1fae5; }
        .edit-progress-step.active    { border-color:#10b981; color:#10b981; background:#ecfdf5; }
        .edit-progress-step.completed { background:#10b981; border-color:#10b981; color:#fff; }
        .edit-step-label-row span.active-lbl { color:#10b981; }
        .deal-form-group input.edit-focus:focus,
        .deal-form-group select.edit-focus:focus,
        .deal-form-group textarea.edit-focus:focus { box-shadow:inset 0 0 0 1.5px #10b981; background:#fff; }
        .deal-btn-update { background:#0f172a; color:#fff; padding:10px 22px; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition:.2s; }
        .deal-btn-update:hover { background:#1e293b; }

        /* SweetAlert dark mode */
        .swal2-container { z-index: 9999 !important; }
        body.dark-mode .swal2-popup { background-color: #1e293b; color: #f8fafc; border: 1px solid #334155; }
        body.dark-mode .swal2-title, body.dark-mode .swal2-html-container { color: #f8fafc; }

        /* ===== VIEW DEAL MODAL ===== */
        .view-modal-overlay { display:none; position:fixed; z-index:3000; inset:0; background:rgba(0,0,0,0.45); align-items:center; justify-content:center; }
        .view-modal-overlay.open { display:flex; }
        .view-modal-box { background:#fff; border-radius:12px; width:100%; max-width:480px; box-shadow:0 16px 40px rgba(0,0,0,0.18); overflow:hidden; animation: vmSlide .25s ease; }
        @keyframes vmSlide { from{opacity:0;transform:translateY(18px)} to{opacity:1;transform:translateY(0)} }
        .vm-header { background:#0f172a; padding:18px 22px; display:flex; justify-content:space-between; align-items:center; }
        .vm-header h3 { font-size:15px; font-weight:800; color:#fff; letter-spacing:0.3px; }
        .vm-header button { background:none; border:none; color:#94a3b8; font-size:18px; cursor:pointer; line-height:1; transition:.2s; }
        .vm-header button:hover { color:#fff; }
        .vm-body { padding:18px 22px; display:grid; grid-template-columns:1fr 1fr; gap:10px 16px; }
        .vm-field { display:flex; flex-direction:column; gap:3px; }
        .vm-field.full { grid-column:1/-1; }
        .vm-field label { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; color:#9ca3af; }
        .vm-field span { font-size:13px; font-weight:600; color:#111827; word-break:break-word; }
        .vm-footer { padding:12px 22px; border-top:1px solid #f1f5f9; text-align:right; }
        .vm-close-btn { background:#0f172a; color:#fff; border:none; padding:9px 22px; border-radius:6px; font-size:12px; font-weight:700; cursor:pointer; transition:.2s; }
        .vm-close-btn:hover { background:#1e293b; }

        /* ===== DARK MODE ===== */
        body.dark-mode { background-color: #0f172a; color: #f8fafc; }
        body.dark-mode .main-content { background-color: #0f172a; }
        body.dark-mode .card, body.dark-mode .table-wrapper { background-color: #1e293b; border-color: #334155; box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
        body.dark-mode .card-icon { background-color: #334155; color: #3b82f6; }
        body.dark-mode .card-info h2, body.dark-mode .comp-header-title h1 { color: #f8fafc; }
        body.dark-mode .custom-table th { background-color: #1e293b; color: #cbd5e1; border-color: #334155; }
        body.dark-mode .custom-table td { color: #cbd5e1; border-color: #334155; }
        body.dark-mode .custom-table tbody tr { background-color: #0f172a; }
        body.dark-mode .custom-table tbody tr:nth-child(even) { background-color: #1e293b; }
        body.dark-mode .custom-table tbody tr:hover { background-color: #334155; }
        body.dark-mode .comp-search input { background-color: #0f172a; color: #f8fafc; border-color: #334155; }
        body.dark-mode .comp-total { background-color: #0f172a; color: #cbd5e1; border-color: #334155; }
        body.dark-mode .comp-icon-btn { border-color: #475569; color: #cbd5e1; }
        body.dark-mode .comp-icon-btn.view:hover   { background: #1e3a8a; border-color: #3b82f6; color: #60a5fa; }
        body.dark-mode .comp-icon-btn.edit:hover   { background: #78350f; border-color: #f59e0b; color: #fcd34d; }
        body.dark-mode .comp-icon-btn.delete:hover { background: #7f1d1d; border-color: #ef4444; color: #fca5a5; }
        body.dark-mode .modal-content { background-color: #1e293b; border: 1px solid #334155; }
        body.dark-mode .deal-modal-header-wrap { background:#0f172a; border-color:#334155; }
        body.dark-mode .deal-modal-top h2 { color:#f8fafc; }
        body.dark-mode .deal-progress-step { background:#1e293b; border-color:#334155; color:#94a3b8; }
        body.dark-mode .deal-progress-bar::before { background:#334155; }
        body.dark-mode .deal-modal-body   { background:#1e293b; }
        body.dark-mode .deal-modal-footer { background:#1e293b; border-color:#334155; }
        body.dark-mode .deal-form-group label { color:#cbd5e1; }
        body.dark-mode .deal-form-group input,
        body.dark-mode .deal-form-group select,
        body.dark-mode .deal-form-group textarea { background:#0f172a; color:#f8fafc; }
        body.dark-mode .deal-section-title { color:#34d399; border-color:#064e3b; }
        body.dark-mode .deal-step-label-row span { color:#475569; }
        body.dark-mode .deal-step-label-row span.active-lbl { color:#34d399; }
        body.dark-mode .deal-btn-cancel, body.dark-mode .deal-btn-back { color:#94a3b8; }
        body.dark-mode .deal-btn-save { background:#1e293b; border:1px solid #334155; }
        body.dark-mode .view-modal-box { background:#1e293b; border:1px solid #334155; }
        body.dark-mode .vm-header { background:#0b1524; }
        body.dark-mode .vm-field span { color:#f1f5f9; }
        body.dark-mode .vm-footer { border-color:#334155; background:#1e293b; }
        body.dark-mode .edit-header-wrap { background:#0f172a; border-color:#334155; }
    </style>
</head>
<body>

    <div id="toastBox">
        <i id="toastIcon" class="fa-solid fa-circle-check"></i>
        <span id="toastMsg">Action Successful!</span>
    </div>

    <?php
    $activePage    = 'deal_pipeline';
    $sidebarRole   = ucfirst(str_replace('_',' ',$_SESSION['role']));
    $dashboardFile = match($_SESSION['role']) {
        'super_admin' => 'super_admin_dashboard.php',
        'admin'       => 'admin_dashboard.php',
        'manager'     => 'manager_dashboard.php',
        'agent'       => 'agent_dashboard.php',
        default       => 'index.php',
    }; // manager/agent dashboard নেই, login page fallback
    include_once 'sidebar.php';
?>

    <div class="main-content">
        <?php include_once 'topbar.php'; ?>

        <div class="company-container">
            <div class="user-list-header">
                <div class="comp-header-title">
                    <h1>Deal Pipeline</h1>
                    <p>Track and manage your sales pipeline efficiently.</p>
                </div>
                <div class="header-buttons">
                    <?php if ($_isAdmin || $_isSuperAdmin || $_isManager): ?>
                    <button class="btn-add-client" onclick="openDealModal()"><i class="fa-solid fa-handshake"></i> Add Deal</button>
                    <?php endif; ?>                </div>
            </div>

            <div class="cards-grid">
                <div class="card">
                    <div class="card-info"><h4>Total Deals</h4><h2><?php echo $totalDeals; ?></h2></div>
                    <div class="card-icon" style="background:#eff6ff; color:#3b82f6;"><i class="fa-solid fa-briefcase"></i></div>
                </div>
                <div class="card">
                    <div class="card-info"><h4>Pipeline Value</h4><h2>$<?php echo number_format($totalPipelineValue, 2); ?></h2></div>
                    <div class="card-icon" style="background:#fef3c7; color:#f59e0b;"><i class="fa-solid fa-sack-dollar"></i></div>
                </div>
                <div class="card">
                    <div class="card-info"><h4>Conversion Rate</h4><h2><?php echo ($totalDeals > 0 ? round(($wonDeals / $totalDeals) * 100) : 0); ?>%</h2></div>
                    <div class="card-icon" style="background:#d1fae5; color:#10b981;"><i class="fa-solid fa-chart-line"></i></div>
                </div>
            </div>

            <div class="comp-toolbar" style="margin-bottom: 20px;">
                <div class="comp-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="dealSearch" placeholder="Search deals..." oninput="filterDeals(this.value)">
                </div>
                <div class="comp-total">Total Records: <?php echo (isset($hasDeals) && $hasDeals) ? $totalDeals : "0"; ?></div>
            </div>

            <div class="table-wrapper">
                <table class="custom-table" id="dealTable">
                    <thead>
                        <tr>
                            <th style="text-align:center;">#ID</th>
                            <th>Deal / Company</th>
                            <th>Value</th>
                            <th>Stage</th>
                            <th>Service</th>
                            <th>Platform</th>
                            <th>Sales Officer</th>
                            <th>Timeline</th>
                            <th style="text-align: right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="dealTableBody">
                        <?php if(isset($hasDeals) && $hasDeals): ?>
                            <?php echo $dealTableRows; ?>
                        <?php else: ?>
                            <tr><td colspan="9" style="text-align:center;padding:30px;color:#9ca3af;">No deals found. Click "Add Deal" to get started.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- ============================================================ -->
    <!-- ADD NEW DEAL MODAL                                           -->
    <!-- ============================================================ -->
    <div id="addDealModal" class="modal">
        <div class="modal-content deal-modal-content">

            <div class="deal-modal-header-wrap">
                <div class="deal-modal-top">
                    <div>
                        <h2>Add New Deal</h2>
                        <p>Fill in the details to create a new deal record</p>
                    </div>
                    <button type="button" class="deal-btn-cancel" onclick="closeDealModal()"><i class="fa-solid fa-xmark" style="font-size:18px;color:#6b7280;"></i></button>
                </div>
                <div class="deal-progress-bar">
                    <div class="deal-progress-step active" id="dealStep1">1</div>
                    <div class="deal-progress-step"        id="dealStep2">2</div>
                    <div class="deal-progress-step"        id="dealStep3">3</div>
                </div>
                <div class="deal-step-label-row">
                    <span class="active-lbl" id="dealLbl1">Basic Info</span>
                    <span id="dealLbl2">Financials</span>
                    <span id="dealLbl3">Status &amp; Notes</span>
                </div>
            </div>

            <form action="" method="POST" id="addDealForm">
                <input type="hidden" name="create_deal" value="1">

                <div class="deal-modal-body">

                    <!-- Step 1: Basic Info -->
                    <div class="deal-step-container deal-step-active" id="dealStepBody1">
                        <div class="deal-section-title"><i class="fa-solid fa-handshake"></i> Deal Identity</div>
                        <div class="deal-form-grid full">
                            <div class="deal-form-group">
                                <label>Deal / Project Name <span style="color:#ef4444;">*</span></label>
                                <input type="text" id="deal_project_name" name="project_name" placeholder="e.g. Website Redesign for Acme Corp" required>
                            </div>
                        </div>
                        <div class="deal-form-grid full" style="margin-top:10px;">
                            <div class="deal-form-group">
                                <label>Link Company <span style="color:#ef4444;">*</span></label>
                                <select id="deal_link_company" name="link_company" required>
                                    <option value="" disabled selected>Select an associated company...</option>
                                    <?php if(!empty($companyOptions)): ?>
                                        <?php echo $companyOptions; ?>
                                    <?php else: ?>
                                        <option value="" disabled>No companies found — add one first</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="deal-form-grid full" style="margin-top:10px;">
                            <div class="deal-form-group">
                                <label>Service Required</label>
                                <input type="text" name="service_required" placeholder="e.g. Web Development, Email Marketing">
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Financials & Timeline -->
                    <div class="deal-step-container" id="dealStepBody2">
                        <div class="deal-section-title"><i class="fa-solid fa-sack-dollar"></i> Financials &amp; Timeline</div>
                        <div class="deal-form-grid">
                            <div class="deal-form-group">
                                <label>Total Amount</label>
                                <input type="number" step="0.01" min="0" name="total_amount" placeholder="0.00">
                            </div>
                            <div class="deal-form-group">
                                <label>Currency</label>
                                <select name="currency">
                                    <option value="USD" selected>USD ($)</option>
                                    <option value="EUR">EUR (€)</option>
                                    <option value="GBP">GBP (£)</option>
                                    <option value="BDT">BDT (৳)</option>
                                    <option value="INR">INR (₹)</option>
                                    <option value="AED">AED (د.إ)</option>
                                </select>
                            </div>
                        </div>
                        <div class="deal-form-grid" style="margin-top:10px;">
                            <div class="deal-form-group">
                                <label>Start Date</label>
                                <input type="date" name="start_date" id="deal_start_date">
                            </div>
                            <div class="deal-form-group">
                                <label>End Date</label>
                                <input type="date" name="end_date" id="deal_end_date">
                            </div>
                        </div>
                    </div>

                    <!-- Step 3: Status & Details -->
                    <div class="deal-step-container" id="dealStepBody3">
                        <div class="deal-section-title"><i class="fa-solid fa-chart-gantt"></i> Status &amp; Details</div>
                        <div class="deal-form-grid">
                            <div class="deal-form-group">
                                <label>Deal Stage</label>
                                <select name="project_status">
                                    <option value="Lead" selected>Lead / Qualified</option>
                                    <option value="Proposal">Proposal / Quote</option>
                                    <option value="Negotiation">Negotiation</option>
                                    <option value="Won">Closed Won</option>
                                    <option value="Lost">Closed Lost</option>
                                </select>
                            </div>
                            <div class="deal-form-group">
                                <label>Platform / Source</label>
                                <select name="platform">
                                    <option value="" disabled selected>Select Source...</option>
                                    <option value="Website">Website</option>
                                    <option value="Referral">Referral</option>
                                    <option value="LinkedIn">LinkedIn</option>
                                    <option value="Cold Call">Cold Call</option>
                                    <option value="Facebook">Facebook</option>
                                    <option value="Email">Email</option>
                                </select>
                            </div>
                        </div>
                        <div class="deal-form-grid full" style="margin-top:10px;">
                            <div class="deal-form-group">
                                <label>Sales Officer</label>
                                <input type="text" name="sales_officer" placeholder="Name of assigned sales officer">
                            </div>
                        </div>
                        <div class="deal-form-grid full" style="margin-top:10px;">
                            <div class="deal-form-group">
                                <label>Additional Notes</label>
                                <textarea name="additional_notes" placeholder="Enter any extra details or comments..."></textarea>
                            </div>
                        </div>
                        <p style="font-size:11px;color:#9ca3af;margin-top:8px;"><i class="fa-solid fa-circle-info"></i> Status and notes fields are optional.</p>
                    </div>

                </div><!-- /deal-modal-body -->

                <!-- Footer -->
                <div class="deal-modal-footer">
                    <div>
                        <button type="button" class="deal-btn-cancel" id="dealBtnCancel" onclick="closeDealModal()">Cancel</button>
                        <button type="button" class="deal-btn-back" id="dealBtnBack" style="display:none;margin-left:14px;" onclick="dealPrevStep()">
                            <i class="fa-solid fa-arrow-left"></i> Back
                        </button>
                    </div>
                    <div style="display:flex;gap:10px;">
                        <button type="button" class="deal-btn-next" id="dealBtnNext" onclick="dealNextStep()">
                            Next Step <i class="fa-solid fa-arrow-right"></i>
                        </button>
                        <button type="submit" name="create_deal" value="1" class="deal-btn-save" id="dealBtnSave" style="display:none;">
                            <i class="fa-solid fa-floppy-disk"></i> Save Deal
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- EDIT DEAL MODAL                                              -->
    <!-- ============================================================ -->
    <div id="editDealModal" class="modal">
        <div class="modal-content deal-modal-content">

            <div class="deal-modal-header-wrap edit-header-wrap">
                <div class="deal-modal-top">
                    <div>
                        <h2>Edit Deal</h2>
                        <p>Update all fields and save changes</p>
                    </div>
                    <button type="button" class="deal-btn-cancel" onclick="closeEditModal()"><i class="fa-solid fa-xmark" style="font-size:18px;color:#6b7280;"></i></button>
                </div>
                <div class="deal-progress-bar">
                    <div class="deal-progress-step edit-progress-step active" id="editStep1">1</div>
                    <div class="deal-progress-step edit-progress-step"        id="editStep2">2</div>
                    <div class="deal-progress-step edit-progress-step"        id="editStep3">3</div>
                </div>
                <div class="deal-step-label-row edit-step-label-row">
                    <span class="active-lbl" id="editLbl1">Basic Info</span>
                    <span id="editLbl2">Financials</span>
                    <span id="editLbl3">Status &amp; Notes</span>
                </div>
            </div>

            <form action="" method="POST" id="editDealForm">
                <input type="hidden" name="edit_deal" value="1">
                <input type="hidden" name="edit_deal_id" id="edit_deal_id">

                <div class="deal-modal-body">

                    <!-- Edit Step 1: Basic Info -->
                    <div class="deal-step-container deal-step-active" id="editStepBody1">
                        <div class="deal-section-title edit-section-title"><i class="fa-solid fa-handshake"></i> Deal Identity</div>
                        <div class="deal-form-grid full">
                            <div class="deal-form-group">
                                <label>Deal / Project Name <span style="color:#ef4444;">*</span></label>
                                <input type="text" id="edit_project_name" name="edit_project_name" class="edit-focus" placeholder="e.g. Website Redesign for Acme Corp" required>
                            </div>
                        </div>
                        <div class="deal-form-grid full" style="margin-top:10px;">
                            <div class="deal-form-group">
                                <label>Link Company <span style="color:#ef4444;">*</span></label>
                                <select id="edit_link_company" name="edit_link_company" class="edit-focus" required>
                                    <option value="" disabled>Select an associated company...</option>
                                    <?php if(!empty($companyOptions)): ?>
                                        <?php echo $companyOptions; ?>
                                    <?php else: ?>
                                        <option value="" disabled>No companies found</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                        <div class="deal-form-grid full" style="margin-top:10px;">
                            <div class="deal-form-group">
                                <label>Service Required</label>
                                <input type="text" id="edit_service_required" name="edit_service_required" class="edit-focus" placeholder="e.g. Web Development, Email Marketing">
                            </div>
                        </div>
                    </div>

                    <!-- Edit Step 2: Financials & Timeline -->
                    <div class="deal-step-container" id="editStepBody2">
                        <div class="deal-section-title edit-section-title"><i class="fa-solid fa-sack-dollar"></i> Financials &amp; Timeline</div>
                        <div class="deal-form-grid">
                            <div class="deal-form-group">
                                <label>Total Amount</label>
                                <input type="number" step="0.01" min="0" id="edit_total_amount" name="edit_total_amount" class="edit-focus" placeholder="0.00">
                            </div>
                            <div class="deal-form-group">
                                <label>Currency</label>
                                <select id="edit_currency" name="edit_currency" class="edit-focus">
                                    <option value="USD">USD ($)</option>
                                    <option value="EUR">EUR (€)</option>
                                    <option value="GBP">GBP (£)</option>
                                    <option value="BDT">BDT (৳)</option>
                                    <option value="INR">INR (₹)</option>
                                    <option value="AED">AED (د.إ)</option>
                                </select>
                            </div>
                        </div>
                        <div class="deal-form-grid" style="margin-top:10px;">
                            <div class="deal-form-group">
                                <label>Start Date</label>
                                <input type="date" id="edit_start_date" name="edit_start_date" class="edit-focus">
                            </div>
                            <div class="deal-form-group">
                                <label>End Date</label>
                                <input type="date" id="edit_end_date" name="edit_end_date" class="edit-focus">
                            </div>
                        </div>
                    </div>

                    <!-- Edit Step 3: Status & Details -->
                    <div class="deal-step-container" id="editStepBody3">
                        <div class="deal-section-title edit-section-title"><i class="fa-solid fa-chart-gantt"></i> Status &amp; Details</div>
                        <div class="deal-form-grid">
                            <div class="deal-form-group">
                                <label>Deal Stage</label>
                                <select id="edit_project_status" name="edit_project_status" class="edit-focus">
                                    <option value="Lead">Lead / Qualified</option>
                                    <option value="Proposal">Proposal / Quote</option>
                                    <option value="Negotiation">Negotiation</option>
                                    <option value="Won">Closed Won</option>
                                    <option value="Lost">Closed Lost</option>
                                </select>
                            </div>
                            <div class="deal-form-group">
                                <label>Platform / Source</label>
                                <select id="edit_platform" name="edit_platform" class="edit-focus">
                                    <option value="">— Select Source —</option>
                                    <option value="Website">Website</option>
                                    <option value="Referral">Referral</option>
                                    <option value="LinkedIn">LinkedIn</option>
                                    <option value="Cold Call">Cold Call</option>
                                    <option value="Facebook">Facebook</option>
                                    <option value="Email">Email</option>
                                </select>
                            </div>
                        </div>
                        <div class="deal-form-grid full" style="margin-top:10px;">
                            <div class="deal-form-group">
                                <label>Sales Officer</label>
                                <input type="text" id="edit_sales_officer" name="edit_sales_officer" class="edit-focus" placeholder="Name of assigned sales officer">
                            </div>
                        </div>
                        <div class="deal-form-grid full" style="margin-top:10px;">
                            <div class="deal-form-group">
                                <label>Additional Notes</label>
                                <textarea id="edit_additional_notes" name="edit_additional_notes" class="edit-focus" placeholder="Enter any extra details or comments..."></textarea>
                            </div>
                        </div>
                    </div>

                </div><!-- /deal-modal-body -->

                <!-- Edit Footer -->
                <div class="deal-modal-footer">
                    <div>
                        <button type="button" class="deal-btn-cancel" id="editBtnCancel" onclick="closeEditModal()">Cancel</button>
                        <button type="button" class="deal-btn-back" id="editBtnBack" style="display:none;margin-left:14px;" onclick="editPrevStep()">
                            <i class="fa-solid fa-arrow-left"></i> Back
                        </button>
                    </div>
                    <div style="display:flex;gap:10px;">
                        <button type="button" class="deal-btn-next" id="editBtnNext" onclick="editNextStep()">
                            Next Step <i class="fa-solid fa-arrow-right"></i>
                        </button>
                        <button type="submit" name="edit_deal" value="1" class="deal-btn-update" id="editBtnSave" style="display:none;">
                            <i class="fa-solid fa-floppy-disk"></i> Update Deal
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ASSIGN DEAL MODAL -->
    <?php if ($_isAdmin || $_isManager || $_isSuperAdmin): ?>
    <div class="view-modal-overlay" id="assignDealModal">
        <div class="view-modal-box" style="max-width:440px;">
            <div class="vm-header" style="background: linear-gradient(135deg,#1e40af,#3b82f6);">
                <h3><i class="fa-solid fa-user-plus" style="margin-right:8px;color:#bfdbfe;"></i> Assign Deal</h3>
                <button onclick="closeAssignModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST" id="assignDealForm">
                <input type="hidden" name="assign_deal" value="1">
                <input type="hidden" name="assign_deal_id" id="assign_deal_id_input">
                <div class="vm-body">
                    <div class="vm-field full" style="margin-bottom:10px;">
                        <label style="font-size:12px;color:#6b7280;font-weight:600;">Deal</label>
                        <span id="assign_deal_name" style="font-weight:700;color:#111827;font-size:15px;">-</span>
                    </div>
                    <div class="vm-field full" style="margin-bottom:10px;">
                        <label style="font-size:12px;color:#6b7280;font-weight:600;">Currently Assigned To</label>
                        <span id="assign_current_officer" style="color:#3b82f6;font-weight:600;">-</span>
                    </div>
                    <div class="vm-field full">
                        <label style="font-size:12px;color:#6b7280;font-weight:600;display:block;margin-bottom:6px;">Assign To <span style="color:#ef4444;">*</span></label>
                        <select name="assign_to_user" id="assign_to_user_select" required
                            style="width:100%;padding:10px 14px;border:1.5px solid #d1d5db;border-radius:8px;font-size:14px;font-family:inherit;outline:none;background:#fff;color:#111827;">
                            <option value="" disabled selected>Select a user...</option>
                            <?php echo $_assignUserOptions; ?>
                        </select>
                    </div>
                </div>
                <div class="vm-footer" style="display:flex;gap:10px;justify-content:flex-end;">
                    <button type="button" class="vm-close-btn" onclick="closeAssignModal()" style="background:#f3f4f6;color:#374151;">Cancel</button>
                    <button type="submit" class="vm-close-btn" style="background:linear-gradient(135deg,#1e40af,#3b82f6);color:#fff;border:none;">
                        <i class="fa-solid fa-paper-plane" style="margin-right:6px;"></i> Assign
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- VIEW DEAL MODAL -->
    <div class="view-modal-overlay" id="viewDealModal">
        <div class="view-modal-box">
            <div class="vm-header">
                <h3><i class="fa-solid fa-briefcase" style="margin-right:8px;color:#60a5fa;"></i> Deal Details</h3>
                <button onclick="closeViewModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="vm-body">
                <div class="vm-field full"><label>Deal Name</label><span id="vm_name">-</span></div>
                <div class="vm-field"><label>Company</label><span id="vm_company">-</span></div>
                <div class="vm-field"><label>Stage</label><span id="vm_stage">-</span></div>
                <div class="vm-field"><label>Deal Value</label><span id="vm_value" style="color:#10b981;font-weight:800;">-</span></div>
                <div class="vm-field"><label>Platform / Source</label><span id="vm_platform">-</span></div>
                <div class="vm-field full"><label>Service Required</label><span id="vm_service">-</span></div>
                <div class="vm-field"><label>Sales Officer</label><span id="vm_officer">-</span></div>
                <div class="vm-field"><label>Start Date</label><span id="vm_start">-</span></div>
                <div class="vm-field full"><label>End Date</label><span id="vm_end">-</span></div>
                <div class="vm-field full"><label>Additional Notes</label><span id="vm_notes" style="color:#6b7280;font-weight:500;font-style:italic;">-</span></div>
            </div>
            <div class="vm-footer">
                <button class="vm-close-btn" onclick="closeViewModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
    // ================================================================
    // VIEW MODAL
    // ================================================================
    function openViewModal(id, name, company, service, platform, officer, value, stage, start, end, notes) {
        document.getElementById('vm_name').textContent    = name    || '-';
        document.getElementById('vm_company').textContent = company || '-';
        document.getElementById('vm_service').textContent = service || '-';
        document.getElementById('vm_platform').textContent= platform|| '-';
        document.getElementById('vm_officer').textContent = officer || '-';
        document.getElementById('vm_value').textContent   = value   || '-';
        document.getElementById('vm_stage').textContent   = stage   || '-';
        document.getElementById('vm_start').textContent   = start   || '-';
        document.getElementById('vm_end').textContent     = end     || '-';
        document.getElementById('vm_notes').textContent   = notes   || 'No notes added.';
        document.getElementById('viewDealModal').classList.add('open');
    }
    function closeViewModal() {
        document.getElementById('viewDealModal').classList.remove('open');
    }
    document.getElementById('viewDealModal').addEventListener('click', function(e){
        if(e.target === this) closeViewModal();
    });

    // ================================================================
    // ASSIGN MODAL
    // ================================================================
    <?php if ($_isAdmin || $_isManager || $_isSuperAdmin): ?>
    function openAssignModal(dealId, dealName, currentOfficer) {
        document.getElementById('assign_deal_id_input').value = dealId;
        document.getElementById('assign_deal_name').textContent = dealName || '-';
        document.getElementById('assign_current_officer').textContent = currentOfficer || 'Not assigned';
        document.getElementById('assign_to_user_select').value = '';
        document.getElementById('assignDealModal').classList.add('open');
    }
    function closeAssignModal() {
        document.getElementById('assignDealModal').classList.remove('open');
    }
    document.getElementById('assignDealModal').addEventListener('click', function(e){
        if(e.target === this) closeAssignModal();
    });
    <?php endif; ?>

    // ================================================================
    // TOAST
    // ================================================================
    window.onload = function() {
        <?php if($toastMessage != ""): ?>
            showToast("<?php echo $toastMessage; ?>", "<?php echo $toastType; ?>");
        <?php endif; ?>
        setDateMin();
    };

    function showToast(message, type) {
        const toast    = document.getElementById("toastBox");
        const toastMsg = document.getElementById("toastMsg");
        const toastIcon= document.getElementById("toastIcon");
        toastMsg.innerText  = message;
        toast.className     = "show " + type;
        toastIcon.className = (type === 'success') ? "fa-solid fa-circle-check" : "fa-solid fa-circle-xmark";
        setTimeout(() => { toast.className = toast.className.replace("show", ""); }, 3000);
    }

    // ================================================================
    // DATE RESTRICTION
    // ================================================================
    function setDateMin() {
        const today     = new Date().toISOString().split('T')[0];
        const startInput= document.getElementById('deal_start_date');
        const endInput  = document.getElementById('deal_end_date');
        if (startInput) startInput.min = today;
        if (endInput)   endInput.min   = today;
        if (startInput) {
            startInput.addEventListener('change', function() {
                if (endInput) {
                    endInput.min = this.value || today;
                    if (endInput.value && endInput.value < this.value) endInput.value = '';
                }
            });
        }
    }

    // ================================================================
    // ADD DEAL WIZARD
    // ================================================================
    var dealCurrentStep = 1;
    var dealTotalSteps  = 3;

    function dealResetWizard() {
        dealCurrentStep = 1;
        dealUpdateWizard();
        document.getElementById('addDealForm').reset();
        setDateMin();
    }

    function dealUpdateWizard() {
        for (var i = 1; i <= dealTotalSteps; i++) {
            var stepEl = document.getElementById('dealStep' + i);
            var bodyEl = document.getElementById('dealStepBody' + i);
            var lblEl  = document.getElementById('dealLbl' + i);
            stepEl.classList.remove('active', 'completed');
            bodyEl.classList.remove('deal-step-active');
            if (lblEl) lblEl.classList.remove('active-lbl');
            if (i < dealCurrentStep) {
                stepEl.classList.add('completed');
                stepEl.innerHTML = '<i class="fa-solid fa-check" style="font-size:11px;"></i>';
            } else {
                stepEl.textContent = i;
                if (i === dealCurrentStep) {
                    stepEl.classList.add('active');
                    bodyEl.classList.add('deal-step-active');
                    if (lblEl) lblEl.classList.add('active-lbl');
                }
            }
        }
        document.getElementById('dealBtnCancel').style.display = dealCurrentStep === 1 ? 'inline-block' : 'none';
        document.getElementById('dealBtnBack').style.display   = dealCurrentStep > 1   ? 'inline-block' : 'none';
        document.getElementById('dealBtnNext').style.display   = dealCurrentStep < dealTotalSteps ? 'inline-flex' : 'none';
        document.getElementById('dealBtnSave').style.display   = dealCurrentStep === dealTotalSteps ? 'inline-flex' : 'none';
    }

    function dealValidateStep() {
        if (dealCurrentStep === 1) {
            var name = document.getElementById('deal_project_name').value.trim();
            var comp = document.getElementById('deal_link_company').value;
            if (!name || !comp) { showToast("Please fill all required (*) fields.", "error"); return false; }
        }
        return true;
    }

    function dealNextStep() {
        if (dealValidateStep() && dealCurrentStep < dealTotalSteps) { dealCurrentStep++; dealUpdateWizard(); }
    }
    function dealPrevStep() {
        if (dealCurrentStep > 1) { dealCurrentStep--; dealUpdateWizard(); }
    }
    function openDealModal() {
        document.getElementById('addDealModal').style.display = 'flex';
        dealResetWizard();
    }
    function closeDealModal() {
        document.getElementById('addDealModal').style.display = 'none';
    }
    document.getElementById('addDealModal').addEventListener('click', function(e) {
        if (e.target === this) closeDealModal();
    });

    // ================================================================
    // EDIT DEAL WIZARD
    // ================================================================
    var editCurrentStep = 1;
    var editTotalSteps  = 3;

    function editUpdateWizard() {
        for (var i = 1; i <= editTotalSteps; i++) {
            var stepEl = document.getElementById('editStep' + i);
            var bodyEl = document.getElementById('editStepBody' + i);
            var lblEl  = document.getElementById('editLbl' + i);
            stepEl.classList.remove('active', 'completed');
            bodyEl.classList.remove('deal-step-active');
            if (lblEl) lblEl.classList.remove('active-lbl');
            if (i < editCurrentStep) {
                stepEl.classList.add('completed');
                stepEl.innerHTML = '<i class="fa-solid fa-check" style="font-size:11px;"></i>';
            } else {
                stepEl.textContent = i;
                if (i === editCurrentStep) {
                    stepEl.classList.add('active');
                    bodyEl.classList.add('deal-step-active');
                    if (lblEl) lblEl.classList.add('active-lbl');
                }
            }
        }
        document.getElementById('editBtnCancel').style.display = editCurrentStep === 1 ? 'inline-block' : 'none';
        document.getElementById('editBtnBack').style.display   = editCurrentStep > 1   ? 'inline-block' : 'none';
        document.getElementById('editBtnNext').style.display   = editCurrentStep < editTotalSteps ? 'inline-flex' : 'none';
        document.getElementById('editBtnSave').style.display   = editCurrentStep === editTotalSteps ? 'inline-flex' : 'none';
    }

    function editValidateStep() {
        if (editCurrentStep === 1) {
            var name = document.getElementById('edit_project_name').value.trim();
            var comp = document.getElementById('edit_link_company').value;
            if (!name || !comp) { showToast("Please fill all required (*) fields.", "error"); return false; }
        }
        return true;
    }

    function editNextStep() {
        if (editValidateStep() && editCurrentStep < editTotalSteps) { editCurrentStep++; editUpdateWizard(); }
    }
    function editPrevStep() {
        if (editCurrentStep > 1) { editCurrentStep--; editUpdateWizard(); }
    }

    function openEditModal(dataJson) {
        var d = (typeof dataJson === 'string') ? JSON.parse(dataJson) : dataJson;

        document.getElementById('edit_deal_id').value          = d.id       || '';
        document.getElementById('edit_project_name').value     = d.name     || '';
        document.getElementById('edit_service_required').value = d.service  || '';
        document.getElementById('edit_total_amount').value     = d.amount   || '';
        document.getElementById('edit_start_date').value       = d.start    || '';
        document.getElementById('edit_end_date').value         = d.end      || '';
        document.getElementById('edit_sales_officer').value    = d.officer  || '';
        document.getElementById('edit_additional_notes').value = d.notes    || '';

        // Select dropdowns
        setSelectValue('edit_link_company',    d.company  || '');
        setSelectValue('edit_currency',        d.currency || 'USD');
        setSelectValue('edit_project_status',  d.stage    || 'Lead');
        setSelectValue('edit_platform',        d.platform || '');

        // Reset wizard to step 1
        editCurrentStep = 1;
        editUpdateWizard();

        document.getElementById('editDealModal').style.display = 'flex';
    }

    function setSelectValue(id, val) {
        var sel = document.getElementById(id);
        if (!sel) return;
        for (var i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value === val) { sel.selectedIndex = i; return; }
        }
        // If not found, try adding as a new option (for company names not in list)
        if (val) {
            var opt = document.createElement('option');
            opt.value = val; opt.text = val;
            sel.add(opt);
            sel.value = val;
        }
    }

    function closeEditModal() {
        document.getElementById('editDealModal').style.display = 'none';
    }
    document.getElementById('editDealModal').addEventListener('click', function(e) {
        if (e.target === this) closeEditModal();
    });

    // ================================================================
    // DELETE CONFIRMATION
    // ================================================================
    function confirmDelete(formId, typeName) {
        const isDark   = document.body.classList.contains('dark-mode');
        const bgColor  = isDark ? '#1e293b' : '#fff';
        const textColor= isDark ? '#f8fafc' : '#111827';
        Swal.fire({
            title: 'Are you sure?',
            text: "You won't be able to revert this " + typeName + "!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'Yes, delete it!',
            background: bgColor,
            color: textColor
        }).then((result) => {
            if (result.isConfirmed) { document.getElementById(formId).submit(); }
        });
    }

    // ================================================================
    // SEARCH / FILTER
    // ================================================================
    function filterDeals(query) {
        query = query.toLowerCase();
        var rows = document.querySelectorAll('#dealTableBody tr');
        rows.forEach(function(row) {
            row.style.display = row.textContent.toLowerCase().includes(query) ? '' : 'none';
        });
    }
    </script>
</body>
</html>