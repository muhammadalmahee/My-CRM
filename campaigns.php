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
$toastType = "";

// ── Role & user info ──
$_currentRole     = $_SESSION['role']     ?? '';
$_currentUserId   = (int)($_SESSION['user_id'] ?? 0);
$_currentUsername = $_SESSION['username'] ?? '';
$_currentName     = $_SESSION['name']     ?? '';
$_isAgent         = ($_currentRole === 'agent');
$_isManager       = ($_currentRole === 'manager');
$_isAdmin         = ($_currentRole === 'admin');
// Manager ও Agent: শুধু view + assign করতে পারবে, edit/delete পারবে না
$_canEditDelete   = (!$_isAgent && !$_isManager);

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
         VALUES ('$user_id','$username','$action','$description','Campaign','$entity_id','$old_val','$new_val','$ip')");
}

// ========================================================================
// 1b. ENSURE assigned_clients COLUMN EXISTS IN campaigns TABLE
// ========================================================================
if (isset($conn)) {
    @mysqli_query($conn, "ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS assigned_clients TEXT DEFAULT NULL");
    @mysqli_query($conn, "ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS created_by INT DEFAULT NULL");
    @mysqli_query($conn, "ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS created_by_name VARCHAR(255) DEFAULT NULL");
}

// ========================================================================
// 2. CAMPAIGN MANAGEMENT LOGIC (CREATE, UPDATE, DELETE)
// ========================================================================

// A. CREATE NEW CAMPAIGN LOGIC
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_campaign']) && !$_isAgent && !$_isManager) {
    if(isset($conn)){
        $campaign_name = mysqli_real_escape_string($conn, $_POST['campaign_name'] ?? '');
        $campaign_type = mysqli_real_escape_string($conn, $_POST['campaign_type'] ?? '');
        $description = mysqli_real_escape_string($conn, $_POST['description'] ?? '');
        $target_audience = mysqli_real_escape_string($conn, $_POST['target_audience'] ?? '');
        $budget = mysqli_real_escape_string($conn, $_POST['budget'] ?? '0');
        $currency = mysqli_real_escape_string($conn, $_POST['currency'] ?? 'USD');
        $start_date = mysqli_real_escape_string($conn, $_POST['start_date'] ?? '');
        $end_date = mysqli_real_escape_string($conn, $_POST['end_date'] ?? '');
        $assigned_to = mysqli_real_escape_string($conn, $_POST['assigned_to'] ?? 'Unassigned');
        $status = mysqli_real_escape_string($conn, $_POST['status'] ?? 'Planning');
        $deal_id = !empty($_POST['deal_id']) ? (int)$_POST['deal_id'] : NULL;
        $assigned_clients = isset($_POST['assigned_clients']) ? mysqli_real_escape_string($conn, implode(',', array_map('intval', $_POST['assigned_clients']))) : '';
        $created_by_id = $_currentUserId;
        $created_by_name_val = mysqli_real_escape_string($conn, $_currentName);
        
        $deal_id_sql = $deal_id !== NULL ? "$deal_id" : "NULL";
        $insert_sql = "INSERT INTO campaigns (campaign_name, campaign_type, description, target_audience, budget, currency, start_date, end_date, assigned_to, deal_id, status, assigned_clients, created_by, created_by_name) 
                       VALUES ('$campaign_name', '$campaign_type', '$description', '$target_audience', '$budget', '$currency', '$start_date', '$end_date', '$assigned_to', $deal_id_sql, '$status', '$assigned_clients', '$created_by_id', '$created_by_name_val')";
        
        try {
            if(mysqli_query($conn, $insert_sql)){
                $new_id = mysqli_insert_id($conn);
                logActivity('CREATE', "Created new campaign: {$_POST['campaign_name']} ({$_POST['campaign_type']}) — Status: $status", 'Campaign', $new_id, '—', "Status: $status, Budget: $currency $budget");
                $toastMessage = "Campaign created successfully!"; $toastType = "success";
            }
        } catch (mysqli_sql_exception $e) {
            $toastMessage = "Database Error: " . $e->getMessage(); $toastType = "error";
        }
    }
}

// B. UPDATE/EDIT EXISTING CAMPAIGN LOGIC
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_campaign']) && !$_isAgent && !$_isManager) {
    if(isset($conn)){
        $id = (int)($_POST['campaign_id'] ?? 0);
        $campaign_name = mysqli_real_escape_string($conn, $_POST['campaign_name'] ?? '');
        $campaign_type = mysqli_real_escape_string($conn, $_POST['campaign_type'] ?? '');
        $description = mysqli_real_escape_string($conn, $_POST['description'] ?? '');
        $target_audience = mysqli_real_escape_string($conn, $_POST['target_audience'] ?? '');
        $budget = mysqli_real_escape_string($conn, $_POST['budget'] ?? '0');
        $currency = mysqli_real_escape_string($conn, $_POST['currency'] ?? 'USD');
        $start_date = mysqli_real_escape_string($conn, $_POST['start_date'] ?? '');
        $end_date = mysqli_real_escape_string($conn, $_POST['end_date'] ?? '');
        $assigned_to = mysqli_real_escape_string($conn, $_POST['assigned_to'] ?? 'Unassigned');
        $status = mysqli_real_escape_string($conn, $_POST['status'] ?? 'Planning');
        $deal_id = !empty($_POST['deal_id']) ? (int)$_POST['deal_id'] : NULL;
        $assigned_clients = isset($_POST['assigned_clients']) ? mysqli_real_escape_string($conn, implode(',', array_map('intval', $_POST['assigned_clients']))) : '';

        // old data for audit log
        $old_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM campaigns WHERE id='$id'"));

        $deal_id_sql = $deal_id !== NULL ? "$deal_id" : "NULL";
        $update_sql = "UPDATE campaigns SET campaign_name='$campaign_name', campaign_type='$campaign_type', description='$description', target_audience='$target_audience', budget='$budget', currency='$currency', start_date='$start_date', end_date='$end_date', assigned_to='$assigned_to', deal_id=$deal_id_sql, status='$status', assigned_clients='$assigned_clients' WHERE id='$id'";
        
        try {
            if(mysqli_query($conn, $update_sql)){
                $old_summary = $old_row ? "Status: {$old_row['status']}, Budget: {$old_row['currency']} {$old_row['budget']}" : '—';
                logActivity('UPDATE', "Updated campaign: {$_POST['campaign_name']} ({$_POST['campaign_type']}) — Status: $status", 'Campaign', $id, $old_summary, "Status: $status, Budget: $currency $budget");
                $toastMessage = "Campaign updated successfully!"; $toastType = "success";
            } else {
                $toastMessage = "Error updating campaign! " . mysqli_error($conn); $toastType = "error";
            }
        } catch (mysqli_sql_exception $e) {
            $toastMessage = "Database Error: " . $e->getMessage(); $toastType = "error";
        }
    }
}

// C. DELETE CAMPAIGN LOGIC
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_campaign']) && !$_isAgent && !$_isManager) {
    if(isset($conn)){
        $del_id = (int)($_POST['delete_campaign_id'] ?? 0);
        $del_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM campaigns WHERE id='$del_id'"));
        $delete_sql = "DELETE FROM campaigns WHERE id='$del_id'";
        try {
            if(mysqli_query($conn, $delete_sql)){
                $del_name = $del_row ? "{$del_row['campaign_name']} ({$del_row['campaign_type']})" : "ID $del_id";
                logActivity('DELETE', "Deleted campaign: $del_name", 'Campaign', $del_id, $del_row ? "Status: {$del_row['status']}" : '—', '—');
                $toastMessage = "Campaign deleted successfully!"; $toastType = "success";
            }
        } catch (mysqli_sql_exception $e) {
            $toastMessage = "Error deleting campaign!"; $toastType = "error";
        }
    }
}

// ========================================================================
// 3. FETCH DATA FOR UI (Users for Assignment, Deals, Campaigns)
// ========================================================================
$assigneeOptions = ""; 
$dealOptions = "";
$clientOptions = "";
if(isset($conn)){
    // সব role এর জন্য সব users দেখাবে assign করার সময়
    $user_query = mysqli_query($conn, "SELECT username, name FROM users ORDER BY name ASC");
    while($u = mysqli_fetch_assoc($user_query)){
        $assigneeOptions .= "<option value='{$u['username']}'>{$u['name']} ({$u['username']})</option>";
    }
    
    // Fetch deals for linking
    $deal_query = mysqli_query($conn, "SELECT id, deal_name FROM deals ORDER BY deal_name ASC");
    $dealOptions .= "<option value='' selected>No Deal (Optional)</option>";
    while($d = mysqli_fetch_assoc($deal_query)){
        $dealOptions .= "<option value='{$d['id']}'>{$d['deal_name']}</option>";
    }

    // Fetch contacts/clients
    $client_query = mysqli_query($conn, "SELECT c.id, c.name, c.email, co.company_name FROM contacts c LEFT JOIN companies co ON c.company_id = co.id ORDER BY c.name ASC");
    while($cl = mysqli_fetch_assoc($client_query)){
        $label = htmlspecialchars($cl['name']);
        if(!empty($cl['company_name'])) $label .= ' — ' . htmlspecialchars($cl['company_name']);
        $clientOptions .= "<option value='{$cl['id']}'>$label</option>";
    }
}

$campaignTableRows = "";
if(isset($conn)){
    if ($_isAdmin) {
        // Admin: শুধু নিজের তৈরি campaigns দেখবে
        $camp_query_sql = "SELECT c.*, d.deal_name FROM campaigns c LEFT JOIN deals d ON c.deal_id = d.id
           WHERE c.created_by = '$_currentUserId'
           ORDER BY c.start_date DESC, c.id DESC";
    } elseif ($_isManager) {
        // Manager: শুধু তাকে assigned campaigns দেখবে
        $camp_query_sql = "SELECT c.*, d.deal_name FROM campaigns c LEFT JOIN deals d ON c.deal_id = d.id
           WHERE c.assigned_to = '" . mysqli_real_escape_string($conn, $_currentUsername) . "'
              OR c.assigned_to = '" . mysqli_real_escape_string($conn, $_currentName) . "'
           ORDER BY c.start_date DESC, c.id DESC";
    } elseif ($_isAgent) {
        // Agent: শুধু নিজের assigned campaigns দেখবে
        $camp_query_sql = "SELECT c.*, d.deal_name FROM campaigns c LEFT JOIN deals d ON c.deal_id = d.id
           WHERE c.assigned_to = '" . mysqli_real_escape_string($conn, $_currentUsername) . "'
              OR c.assigned_to = '" . mysqli_real_escape_string($conn, $_currentName) . "'
           ORDER BY c.start_date DESC, c.id DESC";
    } else {
        // Super Admin বা অন্য role: সব campaigns দেখবে
        $camp_query_sql = "SELECT c.*, d.deal_name FROM campaigns c LEFT JOIN deals d ON c.deal_id = d.id ORDER BY c.start_date DESC, c.id DESC";
    }
    $campaigns_query = mysqli_query($conn, $camp_query_sql);
    if($campaigns_query && mysqli_num_rows($campaigns_query) > 0){
        while($row = mysqli_fetch_assoc($campaigns_query)){
            $campaignData = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
            
            // Status Badge Color
            $statusClass = "status-planning";
            if($row['status'] == 'Active') $statusClass = "status-active";
            if($row['status'] == 'Completed') $statusClass = "status-completed";
            if($row['status'] == 'On Hold') $statusClass = "status-onhold";
            
            // Campaign Type Badge Color
            $typeClass = "type-email";
            if($row['campaign_type'] == 'Social Media') $typeClass = "type-social";
            if($row['campaign_type'] == 'Content Marketing') $typeClass = "type-content";
            if($row['campaign_type'] == 'Paid Ads') $typeClass = "type-paid";
            if($row['campaign_type'] == 'Event') $typeClass = "type-event";

            $campaignTableRows .= "
                <tr class='campaign-row' data-status='{$row['status']}'>
                    <td style='font-weight: 700;'>#{$row['id']}</td>
                    <td style='text-align: left; font-weight: 600;'>{$row['campaign_name']}</td>
                    <td><span class='badge $typeClass'>{$row['campaign_type']}</span></td>
                    <td>{$row['assigned_to']}</td>
                    <td>{$row['currency']} " . number_format($row['budget'], 2) . "</td>
                    <td>" . date('M d, Y', strtotime($row['start_date'])) . "</td>
                    <td><span class='badge $statusClass'>{$row['status']}</span></td>
                    <td>
                        <div class='action-btns'>
                            <button class='btn-view' onclick='openViewModal({$campaignData})'><i class='fa-solid fa-eye'></i></button>
                            " . ($_canEditDelete ? "
                            <button class='btn-edit' onclick='openEditModal({$campaignData})'><i class='fa-solid fa-pen'></i></button>
                            <form method='POST' id='delete-campaign-{$row['id']}' style='display:inline;'>
                                <input type='hidden' name='delete_campaign_id' value='{$row['id']}'>
                                <input type='hidden' name='delete_campaign' value='1'>
                                <button type='button' class='btn-delete' onclick='confirmDelete(\"delete-campaign-{$row['id']}\", \"campaign\")'><i class='fa-solid fa-trash'></i></button>
                            </form>" : "") . "
                        </div>
                    </td>
                </tr>";
        }
    } else {
        $campaignTableRows = "<tr><td colspan='8' style='padding: 20px; color: #6b7280;'>No campaigns found.</td></tr>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campaigns - Systellio CRM</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background-color: #f3f4f6; display: flex; height: 100vh; overflow: hidden; transition: background-color 0.3s, color 0.3s; color: #111827; }

        /* Toast */
        #toastBox { visibility: hidden; min-width: 250px; background-color: #333; color: #fff; text-align: center; border-radius: 8px; padding: 16px; position: fixed; z-index: 9999; right: 30px; top: 30px; font-size: 14px; font-weight: 600; box-shadow: 0 4px 12px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 10px; transform: translateX(100%); transition: transform 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55), visibility 0.4s; }
        #toastBox.show { visibility: visible; transform: translateX(0); }
        #toastBox.success { background-color: #10b981; }
        #toastBox.error { background-color: #ef4444; }

                /* Sidebar CSS → see sidebar.php */
        
        /* Sidebar CSS → see sidebar.php */
        /* Main Content */
        .main-content { flex-grow: 1; display: flex; flex-direction: column; overflow-y: auto; transition: background-color 0.3s ease; background-color: #f3f4f6; }
        
        
        .toggle-btn:hover { color: #111827; }
        
        
        
        .nav-icon-btn:hover { color: #3b82f6; }
        
        
        .user-profile i { font-size: 24px; color: #3b82f6; }

        /* Campaign Section Styles */
        #campaignSection { padding: 30px; display: block; }
        .campaign-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px; }
        .campaign-title h1 { font-size: 26px; font-weight: 800; margin-bottom: 4px; letter-spacing: -0.5px; transition: 0.3s;}
        .campaign-title p { font-size: 11px; color: #6b7280; font-weight: 500; }
        
        .header-actions { display: flex; gap: 12px; }
        .btn-primary { background-color: #000000; color: #ffffff; padding: 10px 18px; border-radius: 6px; font-size: 12px; font-weight: 600; border: none; cursor: pointer; transition: 0.3s; display: flex; align-items: center; gap: 8px; }
        .btn-primary:hover { background-color: #1f2937; transform: translateY(-1px); }

        .tab-container { display: flex; gap: 25px; border-bottom: 1px solid #d1d5db; margin-bottom: 25px; transition: 0.3s;}
        .tab-btn { padding: 10px 5px; font-size: 13px; font-weight: 600; color: #6b7280; cursor: pointer; position: relative; transition: 0.3s; }
        .tab-btn:hover { color: #111827; }
        .tab-btn.active { color: #3b82f6; }
        .tab-btn.active::after { content: ''; position: absolute; bottom: -1px; left: 0; width: 100%; height: 2px; background-color: #3b82f6; }

        .table-wrapper { border-radius: 8px; overflow: hidden; border: 1px solid #d1d5db; transition: 0.3s; background: #ffffff;}
        .custom-table { width: 100%; border-collapse: collapse; text-align: center; font-size: 12px; }
        .custom-table th { background-color: #c4f042; padding: 14px 10px; font-weight: 700; color: #000000; border-bottom: 1px solid #d1d5db; transition: 0.3s;}
        .custom-table td { padding: 14px 10px; color: #374151; font-weight: 500; vertical-align: middle; border-right: 1px solid rgba(0,0,0,0.05); transition: 0.3s;}
        .custom-table td:last-child { border-right: none; }

        .custom-table tbody tr:nth-child(4n+1) { background-color: #e6fced; } 
        .custom-table tbody tr:nth-child(4n+2) { background-color: #fcedf6; } 
        .custom-table tbody tr:nth-child(4n+3) { background-color: #fceddb; } 
        .custom-table tbody tr:nth-child(4n+4) { background-color: #e6edff; } 

        .badge { padding: 4px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .status-planning { background-color: #dbeafe; color: #3b82f6; }
        .status-active { background-color: #dcfce7; color: #10b981; }
        .status-completed { background-color: #fef3c7; color: #f59e0b; }
        .status-onhold { background-color: #fee2e2; color: #ef4444; }
        
        .type-email { background-color: #e0e7ff; color: #4f46e5; }
        .type-social { background-color: #fce7f3; color: #ec4899; }
        .type-content { background-color: #dbeafe; color: #0284c7; }
        .type-paid { background-color: #fef3c7; color: #ca8a04; }
        .type-event { background-color: #d1d5db; color: #374151; }

        .action-btns { display: flex; justify-content: center; gap: 6px; }
        .btn-view { background-color: #60a5fa; color: white; padding: 6px 10px; border-radius: 4px; font-size: 11px; border: none; cursor: pointer; transition: 0.3s;}
        .btn-edit { background-color: #34d399; color: white; padding: 6px 10px; border-radius: 4px; font-size: 11px; border: none; cursor: pointer; transition: 0.3s;}
        .btn-delete { background-color: #f87171; color: white; padding: 6px 10px; border-radius: 4px; font-size: 11px; border: none; cursor: pointer; transition: 0.3s;}
        .btn-view:hover { background-color: #3b82f6; }
        .btn-edit:hover { background-color: #10b981; }
        .btn-delete:hover { background-color: #ef4444; }

        /* Modals */
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center; overflow: hidden; }
        .modal-content { background-color: #fff; padding: 28px 30px; border-radius: 10px; width: 100%; max-width: 680px; max-height: 90vh; overflow-y: auto; overflow-x: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.15); transition: 0.3s; scrollbar-width: none; -ms-overflow-style: none; }
        .modal-content::-webkit-scrollbar { display: none; }
        
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-header h2 { font-size: 20px; font-weight: 700; transition: 0.3s;}
        .close-btn { font-size: 20px; cursor: pointer; color: #6b7280; border: none; background: none; transition: 0.3s;}
        .close-btn:hover { color: #ef4444; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-group { margin-bottom: 15px; position: relative; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: #374151; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; outline: none; transition: 0.3s; font-family: 'Inter', sans-serif; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
        
        .form-group textarea { resize: vertical; min-height: 80px; }
        .full-width { grid-column: span 2; }

        .submit-btn { width: 100%; background-color: #000000; color: #ffffff; padding: 12px; border-radius: 6px; font-size: 14px; font-weight: 700; border: none; cursor: pointer; transition: 0.3s; margin-top: 10px; }
        .submit-btn:hover { background-color: #1f2937; }

        .view-data-box { background: #f9fafb; padding: 10px 12px; border-radius: 6px; border: 1px solid #e5e7eb; font-size: 13px; font-weight: 500; min-height: 40px; display: flex; align-items: center; }

        /* Dark Mode */
        body.dark-mode { background-color: #0f172a; color: #f8fafc; }
        body.dark-mode .main-content { background-color: #0f172a; }
        body.dark-mode 
        body.dark-mode 
        
        body.dark-mode .tab-container { border-color: #334155; }
        body.dark-mode .tab-btn { color: #94a3b8; }
        body.dark-mode .tab-btn:hover { color: #f8fafc; }
        
        body.dark-mode .table-wrapper { border-color: #334155; background: #1e293b; }
        body.dark-mode .custom-table th { background-color: #334155; color: #f8fafc; border-color: #475569; }
        body.dark-mode .custom-table td { color: #cbd5e1; border-color: #334155; }
        
        body.dark-mode .custom-table tbody tr:nth-child(even) { background-color: #1e293b; } 
        body.dark-mode .custom-table tbody tr:nth-child(odd) { background-color: #0f172a; } 

        body.dark-mode .modal-content { background-color: #1e293b; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        body.dark-mode .form-group label { color: #cbd5e1; }
        body.dark-mode .form-group input, body.dark-mode .form-group select, body.dark-mode .form-group textarea { background-color: #0f172a; color: #f8fafc; border-color: #334155; }
        body.dark-mode .view-data-box { background-color: #0f172a; color: #f8fafc; border-color: #334155; }
        /* ── Toolbar (search + total) ── */
        .camp-toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; gap: 12px; }
        .comp-search  { position: relative; width: 300px; }
        .comp-search i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #9ca3af; font-size: 13px; pointer-events: none; }
        .comp-search input { width: 100%; padding: 10px 14px 10px 38px; border: 1px solid #d1d5db; border-radius: 20px; font-size: 13px; outline: none; transition: .3s; color: #374151; background: #fff; }
        .comp-search input:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.1); }
        .comp-total { font-size: 12px; font-weight: 600; color: #6b7280; background: #f9fafb; border: 1px solid #e5e7eb; padding: 8px 16px; border-radius: 20px; white-space: nowrap; }
        body.dark-mode .comp-search input { background: #0f172a; color: #f8fafc; border-color: #334155; }
        body.dark-mode .comp-search input::placeholder { color: #475569; }
        body.dark-mode .comp-total { background: #0f172a; color: #cbd5e1; border-color: #334155; }

        /* Multi-step Wizard Styles */
        .camp-step-container { display: none; overflow: hidden; }
        .camp-step-container.camp-step-active { display: block; animation: fadeIn 0.4s ease-in-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .camp-progress-bar { display: flex; justify-content: space-between; margin-bottom: 20px; position: relative; padding: 0 10px; }
        .camp-progress-bar::before { content: ''; position: absolute; top: 15px; left: 0; width: 100%; height: 2px; background: #e5e7eb; z-index: 1; }
        .camp-progress-step { width: 32px; height: 32px; background: #fff; border: 2px solid #e5e7eb; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; color: #9ca3af; z-index: 2; position: relative; transition: all 0.3s ease; }
        .camp-progress-step.active { border-color: #22c55e; color: #22c55e; background: #f0fdf4; }
        .camp-progress-step.completed { background: #22c55e; border-color: #22c55e; color: #fff; }
        body.dark-mode .camp-progress-step { background: #1e293b; }
        body.dark-mode .camp-progress-step.active { background: #052e16; }
        body.dark-mode .camp-progress-step.completed { background: #16a34a; border-color: #16a34a; }
        body.dark-mode .camp-progress-bar::before { background: #334155; }

        .camp-footer-nav { display: flex; justify-content: flex-end; gap: 12px; margin-top: 25px; padding-top: 20px; border-top: 1px solid #e5e7eb; }
        .camp-btn-nav { padding: 10px 20px; border-radius: 6px; font-weight: 600; cursor: pointer; transition: all 0.2s; border: none; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .camp-btn-prev { background-color: #f3f4f6; color: #4b5563; }
        .camp-btn-prev:hover { background-color: #e5e7eb; }
        .camp-btn-next, .camp-btn-submit { background-color: #22c55e; color: #fff; }
        .camp-btn-next:hover, .camp-btn-submit:hover { background-color: #16a34a; }
        .camp-btn-submit { background-color: #22c55e; }
        .camp-btn-submit:hover { background-color: #16a34a; }

        /* ── Client Dropdown Picker ── */
        .client-dropdown-wrap { position: relative; width: 100%; }
        .client-dropdown-trigger {
            width: 100%; padding: 9px 36px 9px 12px; border: 1px solid #d1d5db; border-radius: 6px;
            font-size: 13px; font-family: 'Inter', sans-serif; background: #fff; cursor: pointer;
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
            color: #6b7280; transition: border-color .2s, box-shadow .2s; user-select: none; min-height: 42px;
        }
        .client-dropdown-trigger:focus, .client-dropdown-trigger.open { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.1); }
        .client-dropdown-trigger .cdt-arrow { font-size: 11px; flex-shrink: 0; transition: transform .2s; color: #9ca3af; }
        .client-dropdown-trigger.open .cdt-arrow { transform: rotate(180deg); }
        .client-dropdown-trigger .cdt-placeholder { color: #9ca3af; font-size: 13px; }
        .client-dropdown-trigger .cdt-count { background: #2563eb; color: #fff; font-size: 10px; font-weight: 700; border-radius: 20px; padding: 2px 8px; }

        .client-dropdown-menu {
            display: none; position: fixed; width: auto;
            background: #fff; border: 1px solid #d1d5db; border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12); z-index: 9999;
            max-height: 200px; flex-direction: column; overflow: hidden;
        }
        .client-dropdown-menu.open { display: flex; animation: cdFadeIn .15s ease; }
        @keyframes cdFadeIn { from { opacity:0; transform:translateY(-4px); } to { opacity:1; transform:translateY(0); } }

        .client-dd-search {
            width: 100%; padding: 9px 12px; border: none; border-bottom: 1px solid #e5e7eb;
            font-size: 12px; outline: none; font-family: 'Inter', sans-serif; background: #f9fafb; color: #374151;
            flex-shrink: 0;
        }
        .client-dd-list { padding: 4px 0; overflow-y: auto; flex: 1; }
        .client-dd-item {
            display: flex; align-items: center; gap: 9px; padding: 8px 12px;
            cursor: pointer; transition: background .12s; font-size: 12px; color: #374151;
        }
        .client-dd-item:hover { background: #f3f4f6; }
        .client-dd-item input[type=checkbox] { accent-color: #2563eb; width: 14px; height: 14px; flex-shrink: 0; cursor: pointer; pointer-events: none; }
        .client-dd-item label { cursor: pointer; line-height: 1.3; pointer-events: none; }
        .client-dd-item.checked { background: #eff6ff; }
        .client-dd-item.checked label { color: #2563eb; font-weight: 600; }
        .client-dd-no-results { padding: 10px 12px; font-size: 12px; color: #9ca3af; display: none; text-align: center; }

        .client-selected-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
        .client-tag { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; border-radius: 20px; padding: 3px 10px; font-size: 11px; font-weight: 600; display: flex; align-items: center; gap: 5px; }
        .client-tag .rm-tag { cursor: pointer; font-size: 11px; color: #93c5fd; transition: color .15s; line-height: 1; }
        .client-tag .rm-tag:hover { color: #ef4444; }

        /* Dark mode */
        body.dark-mode .client-dropdown-trigger { background: #0f172a; border-color: #334155; color: #cbd5e1; }
        body.dark-mode .client-dropdown-trigger.open, body.dark-mode .client-dropdown-trigger:focus { border-color: #3b82f6; }
        body.dark-mode .client-dropdown-menu { background: #1e293b; border-color: #334155; box-shadow: 0 8px 24px rgba(0,0,0,.4); flex-direction: column; }
        body.dark-mode .client-dd-search { background: #0f172a; color: #f8fafc; border-color: #334155; }
        body.dark-mode .client-dd-item { color: #cbd5e1; }
        body.dark-mode .client-dd-item:hover { background: #0f172a; }
        body.dark-mode .client-dd-item.checked { background: #1e3a5f; }
        body.dark-mode .client-tag { background: #1e3a8a22; border-color: #1e40af; color: #60a5fa; }

        /* ── Step Section Title ── */
        .step-section-title { font-size: 13px; font-weight: 700; color: #374151; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: #f3f4f6; border-radius: 8px; border-left: 3px solid #2563eb; }
        body.dark-mode .step-section-title { background: #0f172a; color: #cbd5e1; border-left-color: #3b82f6; }

        /* ── Step Labels ── */
        .camp-step-labels { display: flex; justify-content: space-between; margin: -10px 0 20px 0; padding: 0 6px; }
        .camp-step-label { font-size: 10px; font-weight: 600; color: #9ca3af; text-align: center; flex: 1; transition: color 0.3s; }
        .camp-step-label.active { color: #22c55e; }
        body.dark-mode .camp-step-label { color: #475569; }
        body.dark-mode .camp-step-label.active { color: #4ade80; }

        /* ── Edit Modal 3-step wizard ── */
        .edit-progress-bar { display: flex; justify-content: space-between; margin-bottom: 22px; position: relative; padding: 0 10px; }
        .edit-progress-bar::before { content: ''; position: absolute; top: 15px; left: 0; width: 100%; height: 2px; background: #e5e7eb; z-index: 1; }
        .edit-progress-step { width: 32px; height: 32px; background: #fff; border: 2px solid #e5e7eb; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; color: #9ca3af; z-index: 2; position: relative; transition: all 0.3s ease; }
        .edit-progress-step.active { border-color: #22c55e; color: #22c55e; background: #f0fdf4; }
        .edit-progress-step.completed { background: #22c55e; border-color: #22c55e; color: #fff; }
        body.dark-mode .edit-progress-step { background: #1e293b; }
        body.dark-mode .edit-progress-step.active { background: #052e16; }
        body.dark-mode .edit-progress-step.completed { background: #16a34a; border-color: #16a34a; }
        body.dark-mode .edit-progress-bar::before { background: #334155; }

        .edit-step-container { display: none; }
        .edit-step-container.edit-step-active { display: block; animation: fadeIn 0.35s ease; }

        .edit-footer-nav { display: flex; justify-content: flex-end; gap: 12px; margin-top: 22px; padding-top: 18px; border-top: 1px solid #e5e7eb; }
        body.dark-mode .edit-footer-nav { border-color: #334155; }
        body.dark-mode .camp-footer-nav { border-color: #334155; }
    </style>
</head>
<body>

    <div id="toastBox">
        <i id="toastIcon" class="fa-solid fa-circle-check"></i>
        <span id="toastMsg">Action Successful!</span>
    </div>

        <?php
    $activePage    = 'campaigns';
    $sidebarRole   = ucfirst(str_replace('_',' ',$_SESSION['role']));
    $dashboardFile = match($_SESSION['role']) {
        'super_admin' => 'super_admin_dashboard.php',
        'admin'       => 'admin_dashboard.php',
        'manager'     => 'manager_dashboard.php',
        'agent'       => 'agent_dashboard.php',
        default       => 'index.php',
    }; // manager/agent dashboard নেই, login page fallback
    include 'sidebar.php';
?>

    <div class="main-content">
        <?php include 'topbar.php'; ?>

        <div id="campaignSection">
            <div class="campaign-header">
                <div class="campaign-title">
                    <h1>Campaign Management</h1>
                    <p>Create, manage, and track your marketing campaigns.</p>
                </div>
                <div class="header-actions">
                    <?php if (!$_isAgent && !$_isManager): ?>
                    <button class="btn-primary" onclick="openModal('createCampaignModal')"><i class="fa-solid fa-plus"></i> Create Campaign</button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tab-container">
                <div class="tab-btn active" onclick="filterCampaigns('all', this)">All Campaigns</div>
                <div class="tab-btn" onclick="filterCampaigns('Planning', this)">Planning</div>
                <div class="tab-btn" onclick="filterCampaigns('Active', this)">Active</div>
                <div class="tab-btn" onclick="filterCampaigns('Completed', this)">Completed</div>
                <div class="tab-btn" onclick="filterCampaigns('On Hold', this)">On Hold</div>
            </div>

            <div class="camp-toolbar">
                <div class="comp-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="campSearchInput" placeholder="Search campaigns..." oninput="searchCampaigns(this.value)">
                </div>
                <div class="comp-total" id="campTotalCount">Total: <?php
                    if(isset($conn)){
                        if ($_isAdmin) {
                            $cnt_sql = "SELECT id FROM campaigns WHERE created_by='$_currentUserId'";
                        } elseif ($_isManager || $_isAgent) {
                            $cnt_sql = "SELECT id FROM campaigns WHERE assigned_to='" . mysqli_real_escape_string($conn,$_currentUsername) . "' OR assigned_to='" . mysqli_real_escape_string($conn,$_currentName) . "'";
                        } else {
                            $cnt_sql = "SELECT id FROM campaigns";
                        }
                        $cnt_r = mysqli_query($conn, $cnt_sql);
                        echo $cnt_r ? mysqli_num_rows($cnt_r) : 0;
                    } else { echo 0; }
                ?> Campaigns</div>
            </div>

            <div class="table-wrapper">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Campaign Name</th>
                            <th>Type</th>
                            <th>Assigned To</th>
                            <th>Budget</th>
                            <th>Start Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php echo $campaignTableRows; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Create Campaign Modal -->
    <div id="createCampaignModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Create New Campaign</h2>
                <button type="button" class="close-btn" onclick="closeModal('createCampaignModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form action="campaigns.php" method="POST" id="createCampaignForm">
                <!-- Progress Bar (3 steps) -->
                <div class="camp-progress-bar">
                    <div class="camp-progress-step active" data-step="1">1</div>
                    <div class="camp-progress-step" data-step="2">2</div>
                    <div class="camp-progress-step" data-step="3">3</div>
                </div>
                <!-- Step Labels -->
                <div class="camp-step-labels">
                    <span class="camp-step-label active" data-label="1">Basic Info</span>
                    <span class="camp-step-label" data-label="2">Schedule & Budget</span>
                    <span class="camp-step-label" data-label="3">Assignment & Details</span>
                </div>

                <!-- Step 1: Basic Info -->
                <div class="camp-step-container camp-step-active" data-step="1">
                    <div class="step-section-title"><i class="fa-solid fa-bullhorn"></i> Campaign Basics</div>
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label>Campaign Name <span style="color:red">*</span></label>
                            <input type="text" name="campaign_name" id="camp_name" required placeholder="e.g. Summer Product Launch">
                        </div>
                        <div class="form-group">
                            <label>Campaign Type <span style="color:red">*</span></label>
                            <select name="campaign_type" id="camp_type" required>
                                <option value="" disabled selected>Select Type</option>
                                <option value="Email">📧 Email</option>
                                <option value="Social Media">📱 Social Media</option>
                                <option value="Content Marketing">📝 Content Marketing</option>
                                <option value="Paid Ads">💰 Paid Ads</option>
                                <option value="Event">🎉 Event</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Status <span style="color:red">*</span></label>
                            <select name="status" id="camp_status" required>
                                <option value="Planning">📋 Planning</option>
                                <option value="Active">✅ Active</option>
                                <option value="On Hold">⏸️ On Hold</option>
                                <option value="Completed">🏁 Completed</option>
                            </select>
                        </div>
                        <div class="form-group full-width">
                            <label>Target Audience</label>
                            <input type="text" name="target_audience" placeholder="e.g. Ages 18-35, Tech Enthusiasts">
                        </div>
                    </div>
                </div>

                <!-- Step 2: Schedule & Budget -->
                <div class="camp-step-container" data-step="2">
                    <div class="step-section-title"><i class="fa-solid fa-calendar-days"></i> Schedule & Budget</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Start Date <span style="color:red">*</span></label>
                            <input type="date" name="start_date" id="camp_start_date" required min="<?php echo date('Y-m-d'); ?>" onchange="document.getElementById('camp_end_date').min = this.value;">
                        </div>
                        <div class="form-group">
                            <label>End Date <span style="color:red">*</span></label>
                            <input type="date" name="end_date" id="camp_end_date" required min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                            <label>Budget</label>
                            <input type="number" name="budget" step="0.01" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label>Currency</label>
                            <select name="currency">
                                <option value="USD">🇺🇸 USD — US Dollar</option>
                                <option value="EUR">🇪🇺 EUR — Euro</option>
                                <option value="GBP">🇬🇧 GBP — British Pound</option>
                                <option value="INR">🇮🇳 INR — Indian Rupee</option>
                                <option value="BDT">🇧🇩 BDT — Bangladeshi Taka</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Assignment & Details -->
                <div class="camp-step-container" data-step="3">
                    <div class="step-section-title"><i class="fa-solid fa-users"></i> Assignment & Details</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Assigned To</label>
                            <select name="assigned_to">
                                <option value="Unassigned">Unassigned</option>
                                <?php echo $assigneeOptions; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Link to Deal</label>
                            <select name="deal_id" required>
                                <?php echo $dealOptions; ?>
                            </select>
                        </div>
                        <div class="form-group full-width">
                            <label>Assign to Clients (Optional)</label>
                            <div class="client-dropdown-wrap" id="createCDW">
                                <div class="client-dropdown-trigger" id="createCDT" onclick="toggleDropdown('create')">
                                    <span class="cdt-placeholder" id="createCDLabel">Select clients...</span>
                                    <i class="fa-solid fa-chevron-down cdt-arrow"></i>
                                </div>
                                <div class="client-dropdown-menu" id="createCDMenu">
                                    <input type="text" class="client-dd-search" placeholder="&#xf002; Search clients..." oninput="filterDropdown('create', this.value)">
                                    <div class="client-dd-list" id="createCDList">
                                        <?php
                                        $client_query2 = mysqli_query($conn, "SELECT c.id, c.name, co.company_name FROM contacts c LEFT JOIN companies co ON c.company_id = co.id ORDER BY c.name ASC");
                                        while($cl = mysqli_fetch_assoc($client_query2)){
                                            $lbl = htmlspecialchars($cl['name']);
                                            if(!empty($cl['company_name'])) $lbl .= ' — ' . htmlspecialchars($cl['company_name']);
                                            $searchVal = strtolower(htmlspecialchars($cl['name']).' '.htmlspecialchars($cl['company_name'] ?? ''));
                                            echo "<div class='client-dd-item' data-search='{$searchVal}' data-id='{$cl['id']}' data-label='".htmlspecialchars($lbl)."' onclick='toggleClient(\"create\",{$cl['id']},this)'>
                                                <input type='checkbox' name='assigned_clients[]' value='{$cl['id']}' id='cc_{$cl['id']}'>
                                                <label>$lbl</label>
                                              </div>";
                                        }
                                        ?>
                                        <div class="client-dd-no-results" id="createCDNoRes">No clients found.</div>
                                    </div>
                                </div>
                            </div>
                            <div class="client-selected-tags" id="createClientTags"></div>
                        </div>
                        <div class="form-group full-width">
                            <label>Description</label>
                            <textarea name="description" placeholder="Provide detailed information about the campaign..." style="height: 80px;"></textarea>
                        </div>
                    </div>
                </div>

                <!-- Navigation Buttons -->
                <div class="camp-footer-nav">
                    <button type="button" class="camp-btn-nav camp-btn-prev" id="prevBtn" style="display:none;" onclick="changeStep(-1)"><i class="fa-solid fa-arrow-left"></i> Previous</button>
                    <button type="button" class="camp-btn-nav camp-btn-next" id="nextBtn" onclick="changeStep(1)" style="background:#22c55e;">Next <i class="fa-solid fa-arrow-right"></i></button>
                    <button type="submit" name="create_campaign" class="camp-btn-nav camp-btn-submit" id="submitBtn" style="display:none; background:#22c55e;">Create Campaign <i class="fa-solid fa-check"></i></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Campaign Modal -->
    <div id="editCampaignModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit Campaign</h2>
                <button type="button" class="close-btn" onclick="closeModal('editCampaignModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form action="campaigns.php" method="POST" id="editCampaignForm">
                <input type="hidden" name="campaign_id" id="edit_campaign_id">

                <!-- Edit Progress Bar (3 steps) -->
                <div class="edit-progress-bar">
                    <div class="edit-progress-step active" id="edit_ps1">1</div>
                    <div class="edit-progress-step" id="edit_ps2">2</div>
                    <div class="edit-progress-step" id="edit_ps3">3</div>
                </div>
                <!-- Edit Step Labels -->
                <div class="camp-step-labels">
                    <span class="camp-step-label active" data-label="1">Basic Info</span>
                    <span class="camp-step-label" data-label="2">Schedule & Budget</span>
                    <span class="camp-step-label" data-label="3">Assignment & Details</span>
                </div>

                <!-- Edit Step 1: Basic Info -->
                <div class="edit-step-container edit-step-active" data-estep="1">
                    <div class="step-section-title"><i class="fa-solid fa-bullhorn"></i> Campaign Basics</div>
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label>Campaign Name</label>
                            <input type="text" name="campaign_name" id="edit_campaign_name" required>
                        </div>
                        <div class="form-group">
                            <label>Campaign Type</label>
                            <select name="campaign_type" id="edit_campaign_type" required>
                                <option value="Email">📧 Email</option>
                                <option value="Social Media">📱 Social Media</option>
                                <option value="Content Marketing">📝 Content Marketing</option>
                                <option value="Paid Ads">💰 Paid Ads</option>
                                <option value="Event">🎉 Event</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" id="edit_status" required>
                                <option value="Planning">📋 Planning</option>
                                <option value="Active">✅ Active</option>
                                <option value="On Hold">⏸️ On Hold</option>
                                <option value="Completed">🏁 Completed</option>
                            </select>
                        </div>
                        <div class="form-group full-width">
                            <label>Target Audience</label>
                            <input type="text" name="target_audience" id="edit_target_audience" placeholder="e.g. Ages 18-35, Tech Enthusiasts">
                        </div>
                    </div>
                </div>

                <!-- Edit Step 2: Schedule & Budget -->
                <div class="edit-step-container" data-estep="2">
                    <div class="step-section-title"><i class="fa-solid fa-calendar-days"></i> Schedule & Budget</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Start Date</label>
                            <input type="date" name="start_date" id="edit_start_date" required onchange="document.getElementById('edit_end_date').min = this.value;">
                        </div>
                        <div class="form-group">
                            <label>End Date</label>
                            <input type="date" name="end_date" id="edit_end_date" required>
                        </div>
                        <div class="form-group">
                            <label>Budget</label>
                            <input type="number" name="budget" id="edit_budget" step="0.01" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label>Currency</label>
                            <select name="currency" id="edit_currency">
                                <option value="USD">🇺🇸 USD — US Dollar</option>
                                <option value="EUR">🇪🇺 EUR — Euro</option>
                                <option value="GBP">🇬🇧 GBP — British Pound</option>
                                <option value="INR">🇮🇳 INR — Indian Rupee</option>
                                <option value="BDT">🇧🇩 BDT — Bangladeshi Taka</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Edit Step 3: Assignment & Details -->
                <div class="edit-step-container" data-estep="3">
                    <div class="step-section-title"><i class="fa-solid fa-users"></i> Assignment & Details</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Assigned To</label>
                            <select name="assigned_to" id="edit_assigned_to">
                                <option value="Unassigned">Unassigned</option>
                                <?php echo $assigneeOptions; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Link to Deal (Optional)</label>
                            <select name="deal_id" id="edit_deal_id">
                                <?php echo $dealOptions; ?>
                            </select>
                        </div>
                        <div class="form-group full-width">
                            <label>Assign to Clients (Optional)</label>
                            <div class="client-dropdown-wrap" id="editCDW">
                                <div class="client-dropdown-trigger" id="editCDT" onclick="toggleDropdown('edit')">
                                    <span class="cdt-placeholder" id="editCDLabel">Select clients...</span>
                                    <i class="fa-solid fa-chevron-down cdt-arrow"></i>
                                </div>
                                <div class="client-dropdown-menu" id="editCDMenu">
                                    <input type="text" class="client-dd-search" placeholder="Search clients..." oninput="filterDropdown('edit', this.value)">
                                    <div class="client-dd-list" id="editCDList">
                                        <?php
                                        $client_query3 = mysqli_query($conn, "SELECT c.id, c.name, co.company_name FROM contacts c LEFT JOIN companies co ON c.company_id = co.id ORDER BY c.name ASC");
                                        while($cl = mysqli_fetch_assoc($client_query3)){
                                            $lbl = htmlspecialchars($cl['name']);
                                            if(!empty($cl['company_name'])) $lbl .= ' — ' . htmlspecialchars($cl['company_name']);
                                            $searchVal = strtolower(htmlspecialchars($cl['name']).' '.htmlspecialchars($cl['company_name'] ?? ''));
                                            echo "<div class='client-dd-item' data-search='{$searchVal}' data-id='{$cl['id']}' data-label='".htmlspecialchars($lbl)."' onclick='toggleClient(\"edit\",{$cl['id']},this)'>
                                                <input type='checkbox' name='assigned_clients[]' value='{$cl['id']}' id='ec_{$cl['id']}'>
                                                <label>$lbl</label>
                                              </div>";
                                        }
                                        ?>
                                        <div class="client-dd-no-results" id="editCDNoRes">No clients found.</div>
                                    </div>
                                </div>
                            </div>
                            <div class="client-selected-tags" id="editClientTags"></div>
                        </div>
                        <div class="form-group full-width">
                            <label>Description</label>
                            <textarea name="description" id="edit_description" style="height:80px;" placeholder="Provide detailed information about the campaign..."></textarea>
                        </div>
                    </div>
                </div>

                <!-- Edit Navigation -->
                <div class="edit-footer-nav">
                    <button type="button" class="camp-btn-nav camp-btn-prev" id="editPrevBtn" style="display:none;" onclick="editChangeStep(-1)"><i class="fa-solid fa-arrow-left"></i> Previous</button>
                    <button type="button" class="camp-btn-nav camp-btn-next" id="editNextBtn" onclick="editChangeStep(1)" style="background:#22c55e;">Next <i class="fa-solid fa-arrow-right"></i></button>
                    <button type="submit" name="update_campaign" class="camp-btn-nav camp-btn-submit" id="editSubmitBtn" style="display:none; background:#22c55e;">Update Campaign <i class="fa-solid fa-check"></i></button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Campaign Modal -->
    <div id="viewCampaignModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Campaign Details</h2>
                <button type="button" class="close-btn" onclick="closeModal('viewCampaignModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="form-grid">
                <div class="form-group"><label>Campaign Name</label><div class="view-data-box" id="view_campaign_name">-</div></div>
                <div class="form-group"><label>Campaign Type</label><div class="view-data-box" id="view_campaign_type">-</div></div>
                <div class="form-group"><label>Status</label><div class="view-data-box" id="view_status">-</div></div>
                <div class="form-group"><label>Assigned To</label><div class="view-data-box" id="view_assigned_to">-</div></div>
                <div class="form-group"><label>Budget</label><div class="view-data-box" id="view_budget">-</div></div>
                <div class="form-group"><label>Currency</label><div class="view-data-box" id="view_currency">-</div></div>
                <div class="form-group"><label>Start Date</label><div class="view-data-box" id="view_start_date">-</div></div>
                <div class="form-group"><label>End Date</label><div class="view-data-box" id="view_end_date">-</div></div>
                <div class="form-group"><label>Linked Deal</label><div class="view-data-box" id="view_deal_name">-</div></div>
                <div class="form-group full-width"><label>Assigned Clients</label><div class="view-data-box" id="view_assigned_clients" style="flex-wrap:wrap; gap:6px; align-items:flex-start; padding: 8px 12px; min-height:40px;">-</div></div>
                <div class="form-group full-width"><label>Target Audience</label><div class="view-data-box" id="view_target_audience">-</div></div>
                <div class="form-group full-width"><label>Description</label><div class="view-data-box" id="view_description" style="min-height: 80px; align-items: flex-start; padding-top: 10px;">-</div></div>
            </div>
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button class="submit-btn" onclick="switchToEditMode()" style="background-color: #22c55e; margin-top: 0;"><i class="fa-solid fa-pen-to-square"></i> Edit Campaign</button>
                <button class="submit-btn" onclick="closeModal('viewCampaignModal')" style="background-color: #6b7280; margin-top: 0;">Close</button>
            </div>
        </div>
    </div>

    <script>
        // Current active status filter
        let _activeCampStatus = 'all';

        // Data Filtering Logic — now search-aware
        function filterCampaigns(status, btnElement) {
            _activeCampStatus = status;
            const tabBtns = document.querySelectorAll('.tab-btn');
            tabBtns.forEach(btn => btn.classList.remove('active'));
            btnElement.classList.add('active');
            _applyCampFilters();
        }

        // Search function — works together with tab filter
        function searchCampaigns(q) {
            _applyCampFilters();
        }

        function _applyCampFilters() {
            const q = (document.getElementById('campSearchInput')?.value || '').toLowerCase().trim();
            let visible = 0;
            document.querySelectorAll('.campaign-row').forEach(tr => {
                const statusMatch = _activeCampStatus === 'all' || tr.getAttribute('data-status') === _activeCampStatus;
                const tds = tr.querySelectorAll('td');
                const rowText = Array.from(tds).map(td => td.textContent.trim().toLowerCase()).join(' ');
                const searchMatch = !q || rowText.includes(q);
                const show = statusMatch && searchMatch;
                tr.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            const totalEl = document.getElementById('campTotalCount');
            if (totalEl) totalEl.textContent = 'Total: ' + visible + ' Campaign' + (visible !== 1 ? 's' : '');
        }

        // Modal Logic
        function openModal(id) { document.getElementById(id).style.display = "flex"; }
        function closeModal(id) { document.getElementById(id).style.display = "none"; }

        let currentCampaignData = null;

        // Client data for JS lookup
        const ALL_CLIENTS = <?php
            $cdata = [];
            $cq = mysqli_query($conn, "SELECT c.id, c.name, co.company_name FROM contacts c LEFT JOIN companies co ON c.company_id = co.id ORDER BY c.name ASC");
            while($r = mysqli_fetch_assoc($cq)) {
                $lbl = $r['name'];
                if(!empty($r['company_name'])) $lbl .= ' — ' . $r['company_name'];
                $cdata[] = ['id' => (int)$r['id'], 'label' => $lbl];
            }
            echo json_encode($cdata);
        ?>;

        // ── Client Dropdown Logic ──
        const _cdState = { create: new Set(), edit: new Set() };

        function toggleDropdown(prefix) {
            const trigger = document.getElementById(prefix + 'CDT');
            const menu    = document.getElementById(prefix + 'CDMenu');
            const isOpen  = menu.classList.contains('open');
            // close all dropdowns first
            ['create','edit'].forEach(p => {
                document.getElementById(p+'CDT')?.classList.remove('open');
                document.getElementById(p+'CDMenu')?.classList.remove('open');
            });
            if (!isOpen) {
                trigger.classList.add('open');
                // Position menu using fixed coords based on trigger rect
                const rect = trigger.getBoundingClientRect();
                menu.style.top   = (rect.bottom + 4) + 'px';
                menu.style.left  = rect.left + 'px';
                menu.style.width = rect.width + 'px';
                menu.classList.add('open');
                menu.querySelector('.client-dd-search')?.focus();
            }
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            ['create','edit'].forEach(p => {
                const wrap = document.getElementById(p + 'CDW');
                if (wrap && !wrap.contains(e.target)) {
                    document.getElementById(p+'CDT')?.classList.remove('open');
                    document.getElementById(p+'CDMenu')?.classList.remove('open');
                }
            });
        });

        function toggleClient(prefix, id, el) {
            const cb = el.querySelector('input[type=checkbox]');
            const checked = !cb.checked;
            cb.checked = checked;
            if (checked) { _cdState[prefix].add(id); el.classList.add('checked'); }
            else          { _cdState[prefix].delete(id); el.classList.remove('checked'); }
            updateDropdownLabel(prefix);
            renderTags(prefix);
        }

        function filterDropdown(prefix, q) {
            const list  = document.getElementById(prefix + 'CDList');
            const noRes = document.getElementById(prefix + 'CDNoRes');
            let vis = 0;
            list.querySelectorAll('.client-dd-item').forEach(item => {
                const match = !q || item.dataset.search.includes(q.toLowerCase());
                item.style.display = match ? '' : 'none';
                if (match) vis++;
            });
            noRes.style.display = vis === 0 ? 'block' : 'none';
        }

        function updateDropdownLabel(prefix) {
            const lbl  = document.getElementById(prefix + 'CDLabel');
            const cnt  = _cdState[prefix].size;
            if (cnt === 0) {
                lbl.className = 'cdt-placeholder';
                lbl.textContent = 'Select clients...';
            } else {
                lbl.className = 'cdt-count';
                lbl.textContent = cnt + ' client' + (cnt > 1 ? 's' : '') + ' selected';
            }
        }

        function renderTags(prefix) {
            const box = document.getElementById(prefix + 'ClientTags');
            box.innerHTML = '';
            _cdState[prefix].forEach(id => {
                const cl = ALL_CLIENTS.find(c => c.id == id);
                if (!cl) return;
                const tag = document.createElement('span');
                tag.className = 'client-tag';
                tag.innerHTML = `${cl.label} <span class="rm-tag" onclick="removeClient('${prefix}',${id})">&times;</span>`;
                box.appendChild(tag);
            });
        }

        function removeClient(prefix, id) {
            _cdState[prefix].delete(id);
            const cb = document.getElementById((prefix==='create'?'cc_':'ec_') + id);
            if (cb) { cb.checked = false; cb.closest('.client-dd-item')?.classList.remove('checked'); }
            updateDropdownLabel(prefix);
            renderTags(prefix);
        }

        function clearClientDropdown(prefix) {
            _cdState[prefix].clear();
            const list = document.getElementById(prefix + 'CDList');
            list.querySelectorAll('input[type=checkbox]').forEach(cb => { cb.checked = false; });
            list.querySelectorAll('.client-dd-item').forEach(el => el.classList.remove('checked'));
            updateDropdownLabel(prefix);
            renderTags(prefix);
            filterDropdown(prefix, '');
            const si = document.getElementById(prefix==='create'?'createCDMenu':'editCDMenu')?.querySelector('.client-dd-search');
            if (si) si.value = '';
        }

        function preSelectClients(prefix, csvIds) {
            clearClientDropdown(prefix);
            if (!csvIds) return;
            csvIds.toString().split(',').filter(Boolean).forEach(id => {
                const idN = parseInt(id.trim());
                _cdState[prefix].add(idN);
                const cb = document.getElementById((prefix==='create'?'cc_':'ec_') + idN);
                if (cb) { cb.checked = true; cb.closest('.client-dd-item')?.classList.add('checked'); }
            });
            updateDropdownLabel(prefix);
            renderTags(prefix);
        }

        function openViewModal(campaign) {
            currentCampaignData = campaign;
            document.getElementById('view_campaign_name').innerText = campaign.campaign_name || 'N/A';
            document.getElementById('view_campaign_type').innerText = campaign.campaign_type || 'N/A';
            document.getElementById('view_status').innerText = campaign.status || 'N/A';
            document.getElementById('view_assigned_to').innerText = campaign.assigned_to || 'N/A';
            document.getElementById('view_budget').innerText = (campaign.currency || 'USD') + ' ' + (parseFloat(campaign.budget).toFixed(2) || '0.00');
            document.getElementById('view_currency').innerText = campaign.currency || 'USD';
            document.getElementById('view_start_date').innerText = campaign.start_date ? new Date(campaign.start_date).toLocaleDateString() : 'N/A';
            document.getElementById('view_end_date').innerText = campaign.end_date ? new Date(campaign.end_date).toLocaleDateString() : 'N/A';
            document.getElementById('view_deal_name').innerText = campaign.deal_name || 'No Deal Linked';
            document.getElementById('view_target_audience').innerText = campaign.target_audience || 'N/A';
            document.getElementById('view_description').innerText = campaign.description || 'No description provided.';

            // Assigned clients
            const clientBox = document.getElementById('view_assigned_clients');
            clientBox.innerHTML = '';
            const rawIds = campaign.assigned_clients ? campaign.assigned_clients.toString().split(',').filter(Boolean) : [];
            if (rawIds.length === 0) {
                clientBox.innerText = 'None';
            } else {
                rawIds.forEach(id => {
                    const cl = ALL_CLIENTS.find(c => c.id == parseInt(id));
                    if (cl) {
                        const tag = document.createElement('span');
                        tag.className = 'client-tag';
                        tag.style.pointerEvents = 'none';
                        tag.textContent = cl.label;
                        clientBox.appendChild(tag);
                    }
                });
                if (clientBox.children.length === 0) clientBox.innerText = 'None';
            }
            openModal('viewCampaignModal');
        }

        function switchToEditMode() {
            closeModal('viewCampaignModal');
            if(currentCampaignData) openEditModal(currentCampaignData);
        }

        function openEditModal(campaign) {
            document.getElementById('edit_campaign_id').value = campaign.id;
            document.getElementById('edit_campaign_name').value = campaign.campaign_name || '';
            document.getElementById('edit_campaign_type').value = campaign.campaign_type || '';
            document.getElementById('edit_status').value = campaign.status || '';
            document.getElementById('edit_budget').value = campaign.budget || '';
            document.getElementById('edit_currency').value = campaign.currency || 'USD';
            document.getElementById('edit_assigned_to').value = campaign.assigned_to || 'Unassigned';
            document.getElementById('edit_target_audience').value = campaign.target_audience || '';
            document.getElementById('edit_description').value = campaign.description || '';

            // Date fields — no min restriction so existing past dates work
            const startInput = document.getElementById('edit_start_date');
            const endInput   = document.getElementById('edit_end_date');
            startInput.removeAttribute('min');
            endInput.removeAttribute('min');
            startInput.value = campaign.start_date || '';
            endInput.value   = campaign.end_date   || '';
            startInput.onchange = function() { endInput.min = this.value; };

            // Deal ID — handle null/empty properly
            const dealSel = document.getElementById('edit_deal_id');
            dealSel.value = campaign.deal_id ? campaign.deal_id : '';

            // Pre-select assigned clients
            preSelectClients('edit', campaign.assigned_clients);

            // Reset edit wizard to step 1
            resetEditWizard();
            openModal('editCampaignModal');
        }

        // ── Edit Wizard Logic (3 steps) ──
        let editCurrentStep = 1;
        const editTotalSteps = 3;

        function editChangeStep(n) {
            if (n === 1) {
                // validate step 1
                const container = document.querySelector(`.edit-step-container[data-estep="1"]`);
                const inputs = container.querySelectorAll('input[required], select[required]');
                let valid = true;
                inputs.forEach(inp => {
                    inp.style.borderColor = '';
                    if (!inp.value) { inp.style.borderColor = '#ef4444'; valid = false; }
                });
                if (!valid) {
                    Swal.fire({ icon: 'error', title: 'Required Fields', text: 'Please fill in all required fields.', confirmButtonColor: '#22c55e' });
                    return;
                }
            }
            const steps = document.querySelectorAll('.edit-step-container');
            steps[editCurrentStep - 1].classList.remove('edit-step-active');
            editCurrentStep = Math.min(Math.max(editCurrentStep + n, 1), editTotalSteps);
            steps[editCurrentStep - 1].classList.add('edit-step-active');
            updateEditProgress();
            updateEditButtons();
        }

        function updateEditProgress() {
            const psteps = [document.getElementById('edit_ps1'), document.getElementById('edit_ps2'), document.getElementById('edit_ps3')];
            psteps.forEach((el, idx) => {
                el.classList.remove('active','completed');
                if (idx + 1 < editCurrentStep) { el.classList.add('completed'); el.innerHTML = '<i class="fa-solid fa-check"></i>'; }
                else if (idx + 1 === editCurrentStep) { el.classList.add('active'); el.innerHTML = idx + 1; }
                else { el.innerHTML = idx + 1; }
            });
            // Update step labels (edit modal only)
            document.querySelectorAll('#editCampaignModal .camp-step-label').forEach((lbl, idx) => {
                lbl.classList.toggle('active', idx + 1 === editCurrentStep);
            });
        }

        function updateEditButtons() {
            document.getElementById('editPrevBtn').style.display   = editCurrentStep === 1 ? 'none' : 'flex';
            document.getElementById('editNextBtn').style.display   = editCurrentStep === editTotalSteps ? 'none' : 'flex';
            document.getElementById('editSubmitBtn').style.display = editCurrentStep === editTotalSteps ? 'flex' : 'none';
        }

        function resetEditWizard() {
            editCurrentStep = 1;
            document.querySelectorAll('.edit-step-container').forEach((s, i) => {
                s.classList.toggle('edit-step-active', i === 0);
            });
            updateEditProgress();
            updateEditButtons();
        }

        function confirmDelete(formId, type) {
            Swal.fire({
                title: 'Are you sure?',
                text: `You are about to delete this ${type}. This action cannot be undone!`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById(formId).submit();
                }
            });
        }

        // Multi-step Wizard Logic (Create — 3 steps, same pattern as Edit)
        let currentStep = 1;
        const totalSteps = 3;

        function changeStep(n) {
            if (n === 1) {
                const container = document.querySelector(`.camp-step-container[data-step="${currentStep}"]`);
                const inputs = container.querySelectorAll('input[required], select[required]');
                let valid = true;
                inputs.forEach(inp => {
                    inp.style.borderColor = '';
                    if (!inp.value) { inp.style.borderColor = '#ef4444'; valid = false; }
                });
                if (!valid) {
                    Swal.fire({ icon: 'error', title: 'Required Fields', text: 'Please fill in all required fields before proceeding.', confirmButtonColor: '#22c55e' });
                    return;
                }
            }
            const steps = document.querySelectorAll('.camp-step-container');
            steps[currentStep - 1].classList.remove('camp-step-active');
            currentStep = Math.min(Math.max(currentStep + n, 1), totalSteps);
            steps[currentStep - 1].classList.add('camp-step-active');
            updateProgress();
            updateButtons();
        }

        function updateProgress() {
            const psteps = document.querySelectorAll('#createCampaignModal .camp-progress-step');
            psteps.forEach((el, idx) => {
                el.classList.remove('active', 'completed');
                if (idx + 1 < currentStep) { el.classList.add('completed'); el.innerHTML = '<i class="fa-solid fa-check"></i>'; }
                else if (idx + 1 === currentStep) { el.classList.add('active'); el.innerHTML = idx + 1; }
                else { el.innerHTML = idx + 1; }
            });
            document.querySelectorAll('#createCampaignModal .camp-step-label').forEach((lbl, idx) => {
                lbl.classList.toggle('active', idx + 1 === currentStep);
            });
        }

        function updateButtons() {
            document.getElementById('prevBtn').style.display   = currentStep === 1 ? 'none' : 'flex';
            document.getElementById('nextBtn').style.display   = currentStep === totalSteps ? 'none' : 'flex';
            document.getElementById('submitBtn').style.display = currentStep === totalSteps ? 'flex' : 'none';
        }

        // Reset wizard when modal is closed/opened
        function resetWizard() {
            currentStep = 1;
            const steps = document.querySelectorAll('.camp-step-container');
            steps.forEach(s => s.classList.remove('camp-step-active'));
            steps[0].classList.add('camp-step-active');
            updateProgress();
            updateButtons();

            // Clear inputs
            const form = document.getElementById('createCampaignForm');
            if(form) {
                form.reset();
                form.querySelectorAll('input, select, textarea').forEach(i => i.style.borderColor = "");
            }
            clearClientDropdown('create');
        }

        // Override openModal to reset wizard if it's the create modal
        const originalOpenModal = openModal;
        openModal = function(id) {
            if(id === 'createCampaignModal') resetWizard();
            originalOpenModal(id);
        };

        // Show Toast if message exists
        <?php if($toastMessage): ?>
        const toastBox = document.getElementById('toastBox');
        const toastMsg = document.getElementById('toastMsg');
        const toastIcon = document.getElementById('toastIcon');
        
        toastMsg.innerText = "<?php echo $toastMessage; ?>";
        toastBox.className = "show <?php echo $toastType; ?>";
        toastIcon.className = "fa-solid <?php echo ($toastType == 'success') ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>";
        
        setTimeout(() => { toastBox.className = toastBox.className.replace("show", ""); }, 4000);
        <?php endif; ?>
    </script>
</body>
</html>