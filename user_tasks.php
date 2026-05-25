<?php
// ========================================================================
// 1. INITIALIZATION & SECURITY CHECK
// ========================================================================
session_start();
@include 'config.php'; 

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// ========================================================================
// AJAX — Subtask fetch (get_subtasks)
// ========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'get_subtasks') {
    header('Content-Type: application/json');
    $task_id = intval($_GET['task_id'] ?? 0);
    $subtasks = [];
    if ($task_id && isset($conn)) {
        $res = mysqli_query($conn, "SELECT id, title, is_done FROM subtasks WHERE task_id = $task_id ORDER BY id ASC");
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) $subtasks[] = $row;
        }
    }
    echo json_encode($subtasks);
    exit();
}

$toastMessage = "";
$toastType = "";

// ========================================================================
// CURRENT USER INFO & ROLE-BASED FILTER
// ========================================================================
$currentRole  = $_SESSION['role'] ?? '';
$currentName  = $_SESSION['name'] ?? '';
$isSuperAdmin = ($currentRole === 'super_admin');

// username session এ না থাকলে DB থেকে নাও (পুরনো session এর জন্য)
if (!empty($_SESSION['username'])) {
    $currentUsername = $_SESSION['username'];
} elseif (isset($conn) && !empty($_SESSION['user_id'])) {
    $uid  = intval($_SESSION['user_id']);
    $uRes = mysqli_query($conn, "SELECT username FROM users WHERE id=$uid LIMIT 1");
    $uRow = $uRes ? mysqli_fetch_assoc($uRes) : null;
    $currentUsername = $uRow['username'] ?? '';
    $_SESSION['username'] = $currentUsername; // cache করো
} else {
    $currentUsername = '';
}

// super_admin ছাড়া বাকি সবাই শুধু নিজের task দেখবে
function buildTaskWhere($username, $name, $isSuperAdmin, $conn) {
    if ($isSuperAdmin) return "1=1";
    $u = mysqli_real_escape_string($conn, $username);
    $n = mysqli_real_escape_string($conn, $name);
    return "(assigned_to = '$u' OR assigned_by = '$n')";
}

// ========================================================================
// 2. TASK MANAGEMENT LOGIC (CREATE, UPDATE, DELETE)
// ========================================================================

// A. CREATE NEW TASK LOGIC
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_task'])) {
    if(isset($conn)){
        $title = mysqli_real_escape_string($conn, $_POST['title'] ?? '');
        $description = mysqli_real_escape_string($conn, $_POST['description'] ?? ''); 
        $assigned_to = mysqli_real_escape_string($conn, $_POST['assigned_to'] ?? 'Unassigned');
        $priority = mysqli_real_escape_string($conn, $_POST['priority'] ?? 'Medium');
        $status = mysqli_real_escape_string($conn, $_POST['status'] ?? 'To-Do');
        $due_date = mysqli_real_escape_string($conn, $_POST['due_date'] ?? '');
        
        $assigned_by = mysqli_real_escape_string($conn, $currentName);
        $insert_sql = "INSERT INTO tasks (title, description, assigned_to, priority, status, due_date, assigned_by) VALUES ('$title', '$description', '$assigned_to', '$priority', '$status', '$due_date', '$assigned_by')";
        try {
            if(mysqli_query($conn, $insert_sql)){
                $toastMessage = "Task created successfully!"; $toastType = "success";
            }
        } catch (mysqli_sql_exception $e) {
            $toastMessage = "Database Error! Could not create task."; $toastType = "error";
        }
    }
}

// B. UPDATE/EDIT EXISTING TASK LOGIC
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_task'])) {
    if(isset($conn)){
        $id = mysqli_real_escape_string($conn, $_POST['task_id'] ?? '');
        $title = mysqli_real_escape_string($conn, $_POST['title'] ?? '');
        $description = mysqli_real_escape_string($conn, $_POST['description'] ?? '');
        $assigned_to = mysqli_real_escape_string($conn, $_POST['assigned_to'] ?? 'Unassigned');
        $priority = mysqli_real_escape_string($conn, $_POST['priority'] ?? 'Medium');
        $status = mysqli_real_escape_string($conn, $_POST['status'] ?? 'To-Do');
        $due_date = mysqli_real_escape_string($conn, $_POST['due_date'] ?? '');

        $update_sql = "UPDATE tasks SET title='$title', description='$description', assigned_to='$assigned_to', priority='$priority', status='$status', due_date='$due_date' WHERE id='$id'";
        try {
            if(mysqli_query($conn, $update_sql)){
                $toastMessage = "Task updated successfully!"; $toastType = "success";
            }
        } catch (mysqli_sql_exception $e) {
            $toastMessage = "Database Error! Could not update task."; $toastType = "error";
        }
    }
}

// C. DELETE TASK LOGIC
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_task'])) {
    if(isset($conn)){
        $del_id = mysqli_real_escape_string($conn, $_POST['delete_task_id'] ?? '');
        $delete_sql = "DELETE FROM tasks WHERE id='$del_id'";
        try {
            if(mysqli_query($conn, $delete_sql)){
                $toastMessage = "Task deleted successfully!"; $toastType = "success";
            }
        } catch (mysqli_sql_exception $e) {
            $toastMessage = "Error deleting task!"; $toastType = "error";
        }
    }
}

// ========================================================================
// 3. FETCH DATA FOR UI (Users for Assignment, Tasks)
// ========================================================================
$assigneeOptions = ""; 
if(isset($conn)){
    $user_query = mysqli_query($conn, "SELECT username, name FROM users ORDER BY name ASC");
    while($u = mysqli_fetch_assoc($user_query)){
        $assigneeOptions .= "<option value='{$u['username']}'>{$u['name']} ({$u['username']})</option>";
    }
}

// Summary counts
$totalTasks = $todoCount = $progressCount = $doneCount = $dueTodayCount = 0;
if (isset($conn)) {
    $whereClause = buildTaskWhere($currentUsername, $currentName, $isSuperAdmin, $conn);
    $cr = mysqli_query($conn, "SELECT status, COUNT(*) as cnt FROM tasks WHERE $whereClause GROUP BY status");
    while ($row2 = mysqli_fetch_assoc($cr)) {
        $totalTasks += $row2['cnt'];
        if ($row2['status'] == 'To-Do')      $todoCount     = $row2['cnt'];
        if ($row2['status'] == 'In-Progress') $progressCount = $row2['cnt'];
        if ($row2['status'] == 'Completed')   $doneCount     = $row2['cnt'];
    }
    $today = date('Y-m-d');
    $due_r = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM tasks WHERE due_date = '$today' AND status != 'Completed' AND $whereClause");
    if ($due_r) $dueTodayCount = mysqli_fetch_assoc($due_r)['cnt'];
    $total_due_r = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM tasks WHERE due_date IS NOT NULL AND due_date != '' AND due_date != '0000-00-00' AND status != 'Completed' AND $whereClause");
    $totalDueCount = ($total_due_r) ? (int)mysqli_fetch_assoc($total_due_r)['cnt'] : 0;
}

$taskTableRows = "";
if(isset($conn)){
    $whereClause = buildTaskWhere($currentUsername, $currentName, $isSuperAdmin, $conn);
    $tasks_query = mysqli_query($conn, "SELECT * FROM tasks WHERE $whereClause ORDER BY id DESC");
    if($tasks_query && mysqli_num_rows($tasks_query) > 0){
        while($row = mysqli_fetch_assoc($tasks_query)){
            $taskData = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
            
            // Priority Badge Color
            $priorityClass = "priority-medium";
            if($row['priority'] == 'High') $priorityClass = "priority-high";
            if($row['priority'] == 'Low') $priorityClass = "priority-low";
            
            // Status Badge Color
            $statusClass = "status-todo";
            if($row['status'] == 'In-Progress') $statusClass = "status-progress";
            if($row['status'] == 'Completed') $statusClass = "status-completed";

            // Assigned By
            $assignedBy = !empty($row['assigned_by']) ? htmlspecialchars($row['assigned_by']) : '<span style="color:#9ca3af;font-style:italic;">—</span>';

            // Overdue check
            $isOverdue = false;
            if (!empty($row['due_date']) && $row['due_date'] !== '0000-00-00'
                && $row['status'] !== 'Completed'
                && strtotime($row['due_date']) < strtotime(date('Y-m-d'))) {
                $isOverdue = true;
            }
            $overdueAttr  = $isOverdue ? ' data-overdue="1"' : '';
            $overdueStyle = $isOverdue ? ' style="border-left:3px solid #ef4444;"' : '';

            // Due Date cell — red if overdue
            $dueDateDisplay = 'N/A';
            if (!empty($row['due_date']) && $row['due_date'] !== '0000-00-00') {
                if ($isOverdue) {
                    $dueDateDisplay = "<span style='color:#ef4444;font-weight:700;'>" . date('M d, Y', strtotime($row['due_date'])) . "</span>";
                } else {
                    $dueDateDisplay = date('M d, Y', strtotime($row['due_date']));
                }
            }

            $taskTableRows .= "
                <tr class='task-row' data-status='{$row['status']}'{$overdueAttr}{$overdueStyle}>
                    <td style='font-weight: 700;'>#{$row['id']}</td>
                    <td style='text-align: left; font-weight: 600;'>{$row['title']}" . ($isOverdue ? " <span style='font-size:9px;background:#fee2e2;color:#ef4444;border-radius:3px;padding:1px 5px;font-weight:700;'>OVERDUE</span>" : "") . "</td>
                    <td>{$row['assigned_to']}</td>
                    <td>{$assignedBy}</td>
                    <td><span class='badge $priorityClass'>{$row['priority']}</span></td>
                    <td><span class='badge $statusClass'>{$row['status']}</span></td>
                    <td>" . (!empty($row['created_at']) ? date('M d, Y', strtotime($row['created_at'])) : 'N/A') . "</td>
                    <td>{$dueDateDisplay}</td>
                    <td>
                        <div class='action-btns'>
                            <button class='btn-view' onclick='openViewModal({$taskData})'><i class='fa-solid fa-eye'></i></button>
                        </div>
                    </td>
                </tr>";
        }
    } else {
        $taskTableRows = "<tr><td colspan='9' style='padding: 20px; color: #6b7280;'>No tasks found.</td></tr>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Tasks - Systellio CRM</title>
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

        /* Task Section Styles */
        #taskSection { padding: 30px; display: block; }
        .task-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; }
        .task-title h1 { font-size: 26px; font-weight: 800; margin-bottom: 4px; letter-spacing: -0.5px; transition: 0.3s;}
        .task-title p { font-size: 11px; color: #6b7280; font-weight: 500; }

        /* Summary Cards */
        .summary-grid { display: grid; grid-template-columns: repeat(5,1fr); gap: 16px; margin-bottom: 22px; }
        .summary-card { background: #fff; border-radius: 10px; padding: 18px 20px; border: 1px solid #e5e7eb; display: flex; align-items: center; gap: 14px; box-shadow: 0 2px 6px rgba(0,0,0,.04); transition: 0.3s; }
        .summary-icon { width: 46px; height: 46px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 19px; flex-shrink: 0; }
        .icon-blue   { background: #dbeafe; color: #3b82f6; }
        .icon-yellow { background: #fef3c7; color: #f59e0b; }
        .icon-purple { background: #ede9fe; color: #8b5cf6; }
        .icon-green  { background: #d1fae5; color: #10b981; }
        .icon-red    { background: #fee2e2; color: #ef4444; }
        .summary-info h3 { font-size: 22px; font-weight: 800; margin-bottom: 2px; }
        .summary-info p  { font-size: 11px; color: #6b7280; font-weight: 600; }

        /* Dark mode summary */
        body.dark-mode .summary-card { background: #1e293b; border-color: #334155; }
        body.dark-mode .summary-info p { color: #94a3b8; }
        
        .header-buttons { display: flex; gap: 10px; }
        .create-btn { background-color: #000000; color: #ffffff; padding: 10px 18px; border-radius: 6px; font-size: 12px; font-weight: 600; border: none; cursor: pointer; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); transition: 0.3s;}
        .create-btn:hover { background-color: #1f2937; }

        .tabs-wrapper { margin-bottom: 20px; width: max-content; }
        .tab-top-line { height: 3px; width: 100%; background: linear-gradient(to right, #3b82f6 33%, #f59e0b 33%, #f59e0b 66%, #10b981 66%); border-radius: 3px 3px 0 0; }
        .tabs-container { display: flex; background: #ffffff; padding: 5px; border-radius: 0 0 6px 6px; gap: 5px; transition: 0.3s; border: 1px solid #e5e7eb; border-top: none;}
        .tab-btn { padding: 8px 18px; font-size: 12px; font-weight: 700; border: none; background: transparent; cursor: pointer; border-radius: 4px; color: #6b7280; display: flex; align-items: center; gap: 6px; transition: 0.3s;}
        .tab-btn.active { background: #f3f4f6; color: #111827; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }

        .table-wrapper { border-radius: 8px; overflow: hidden; border: 1px solid #d1d5db; transition: 0.3s; background: #ffffff;}
        .custom-table { width: 100%; border-collapse: collapse; text-align: center; font-size: 12px; }
        .custom-table th { background-color: #c4f042; padding: 14px 10px; font-weight: 700; color: #000000; border-bottom: 1px solid #d1d5db; transition: 0.3s;}
        .custom-table td { padding: 14px 10px; color: #374151; font-weight: 500; vertical-align: middle; border-right: 1px solid rgba(0,0,0,0.05); transition: 0.3s;}
        .custom-table td:last-child { border-right: none; }

        .custom-table tbody tr:nth-child(4n+1) { background-color: #e6fced; } 
        .custom-table tbody tr:nth-child(4n+2) { background-color: #fcedf6; } 
        .custom-table tbody tr:nth-child(4n+3) { background-color: #fceddb; } 
        .custom-table tbody tr:nth-child(4n+4) { background-color: #e6edff; }
        .task-row[data-overdue="1"] { background-color: #fff5f5 !important; }

        .badge { padding: 4px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .priority-high { background-color: #fee2e2; color: #ef4444; }
        .priority-medium { background-color: #fef3c7; color: #f59e0b; }
        .priority-low { background-color: #dcfce7; color: #10b981; }
        
        .status-todo { background-color: #e5e7eb; color: #374151; }
        .status-progress { background-color: #dbeafe; color: #3b82f6; }
        .status-completed { background-color: #d1fae5; color: #059669; }

        .action-btns { display: flex; justify-content: center; gap: 6px; }
        .btn-view { background-color: #60a5fa; color: white; padding: 6px 10px; border-radius: 4px; font-size: 11px; border: none; cursor: pointer; transition: 0.3s;}
        .btn-view:hover { background-color: #3b82f6; }
        .btn-edit { background-color: #4ade80; color: white; padding: 6px 10px; border-radius: 4px; font-size: 11px; border: none; cursor: pointer; transition: 0.3s;}
        .btn-edit:hover { background-color: #22c55e; }
        .btn-delete { background-color: #f87171; color: white; padding: 6px 10px; border-radius: 4px; font-size: 11px; border: none; cursor: pointer; transition: 0.3s;}
        .btn-delete:hover { background-color: #ef4444; }

        /* Modals */
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center; }
        .modal-content { background-color: #fff; padding: 30px; border-radius: 10px; width: 100%; max-width: 650px; box-shadow: 0 10px 25px rgba(0,0,0,0.15); max-height: 90vh; overflow-y: auto; transition: 0.3s; scrollbar-width: none; }
        .modal-content::-webkit-scrollbar { display: none; }
        #viewTaskModal .modal-content { max-width: 960px; max-height: 88vh; overflow-y: auto; }
        
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-header h2 { font-size: 20px; font-weight: 700; transition: 0.3s;}
        .close-btn { font-size: 20px; cursor: pointer; color: #6b7280; border: none; background: none; transition: 0.3s;}
        .close-btn:hover { color: #ef4444; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-group { margin-bottom: 15px; position: relative; } 
        .full-width { grid-column: span 2; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; transition: 0.3s;}
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; outline: none; font-family: 'Inter', sans-serif; background-color: #f9fafb; transition: 0.3s;}
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: #3b82f6; background-color: #fff; }
        
        .submit-btn { background-color: #000000; color: #ffffff; padding: 12px; border: none; border-radius: 6px; width: 100%; font-size: 14px; font-weight: 600; cursor: pointer; transition: 0.3s; }
        .submit-btn:hover { background-color: #1f2937; }

        .view-data-box { background: #f9fafb; padding: 7px 12px; border-radius: 6px; border: 1px solid #e5e7eb; font-size: 13px; font-weight: 500; word-break: break-word; min-height: 34px; display: flex; align-items: center; transition: 0.3s;}

        /* Subtask section */
        .subtask-section { margin-top: 14px; border-top: 1px solid #e5e7eb; padding-top: 12px; }
        .subtask-section h4 { font-size: 13px; font-weight: 700; color: #374151; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
        .subtask-section h4 span.sub-count { background: #dbeafe; color: #3b82f6; font-size: 11px; padding: 2px 8px; border-radius: 20px; font-weight: 700; }
        .subtask-list { display: flex; flex-direction: column; gap: 5px; }
        .subtask-item { display: flex; align-items: center; gap: 10px; padding: 7px 12px; border-radius: 7px; border: 1px solid #e5e7eb; background: #f9fafb; font-size: 12px; font-weight: 500; color: #374151; }
        .subtask-item.done { background: #f0fdf4; border-color: #bbf7d0; color: #6b7280; text-decoration: line-through; }
        .subtask-item i { font-size: 12px; flex-shrink: 0; }
        .subtask-item.done i { color: #10b981; }
        .subtask-item:not(.done) i { color: #d1d5db; }
        .subtask-empty { font-size: 12px; color: #9ca3af; font-style: italic; padding: 6px 0; }

        body.dark-mode .subtask-section { border-color: #334155; }
        body.dark-mode .subtask-section h4 { color: #cbd5e1; }
        body.dark-mode .subtask-item { background: #0f172a; border-color: #334155; color: #cbd5e1; }
        body.dark-mode .subtask-item.done { background: #052e16; border-color: #166534; color: #6b7280; }

        /* Dark Mode */
        body.dark-mode { background-color: #0f172a; color: #f8fafc; }
        body.dark-mode .main-content { background-color: #0f172a; }
        body.dark-mode .task-title h1 { color: #f8fafc; }
        body.dark-mode .task-title p  { color: #64748b; }

        /* Summary cards */
        body.dark-mode .summary-card { background: #1e293b; border-color: #334155; }
        body.dark-mode .summary-info h3 { color: #f8fafc; }
        body.dark-mode .summary-info p  { color: #94a3b8; }
        body.dark-mode .icon-blue   { background: #1e3a5f; color: #60a5fa; }
        body.dark-mode .icon-yellow { background: #422006; color: #fbbf24; }
        body.dark-mode .icon-purple { background: #2e1065; color: #a78bfa; }
        body.dark-mode .icon-green  { background: #052e16; color: #34d399; }
        body.dark-mode .icon-red    { background: #450a0a; color: #f87171; }

        /* Tabs */
        body.dark-mode .tabs-container { background: #1e293b; border-color: #334155; }
        body.dark-mode .tab-btn { color: #94a3b8; }
        body.dark-mode .tab-btn.active { background: #0f172a; color: #f8fafc; }

        /* Table */
        body.dark-mode .table-wrapper { border-color: #334155; background: #1e293b; }
        body.dark-mode .custom-table th { background-color: #334155 !important; color: #f8fafc !important; border-color: #475569; }
        body.dark-mode .custom-table td { color: #cbd5e1 !important; border-color: #334155; }
        body.dark-mode .custom-table tbody tr:nth-child(4n+1) { background-color: #0f172a !important; }
        body.dark-mode .custom-table tbody tr:nth-child(4n+2) { background-color: #131e30 !important; }
        body.dark-mode .custom-table tbody tr:nth-child(4n+3) { background-color: #0f172a !important; }
        body.dark-mode .custom-table tbody tr:nth-child(4n+4) { background-color: #131e30 !important; }
        body.dark-mode .custom-table tbody tr:hover { background-color: #1e293b !important; }
        body.dark-mode .task-row[data-overdue="1"] { background-color: #2d0a0a !important; border-left: 3px solid #ef4444; }

        /* Modal */
        body.dark-mode .modal-content { background-color: #1e293b; box-shadow: 0 10px 25px rgba(0,0,0,0.5); border: 1px solid #334155; }
        body.dark-mode .modal-header h2 { color: #f8fafc; }
        body.dark-mode .close-btn { color: #94a3b8; }

        /* Form */
        body.dark-mode .form-group label { color: #cbd5e1; }
        body.dark-mode .form-group input, body.dark-mode .form-group select, body.dark-mode .form-group textarea { background-color: #0f172a; color: #f8fafc; border-color: #334155; }
        body.dark-mode .form-group input:focus, body.dark-mode .form-group select:focus, body.dark-mode .form-group textarea:focus { background-color: #1e293b; border-color: #3b82f6; }
        body.dark-mode .view-data-box { background-color: #0f172a; color: #f8fafc; border-color: #334155; }
        body.dark-mode .create-btn, body.dark-mode .submit-btn { background-color: #3b82f6; }
    </style>
</head>
<body>

    <div id="toastBox">
        <i id="toastIcon" class="fa-solid fa-circle-check"></i>
        <span id="toastMsg">Action Successful!</span>
    </div>

        <?php
    $activePage    = 'user_tasks';
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
        <?php include 'topbar.php'; ?>

        <div id="taskSection">
            <div class="task-header">
                <div class="task-title">
                    <h1>User Tasks Overview</h1>
                    <p>View who assigned which task and track their current status.</p>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="summary-icon icon-blue"><i class="fa-solid fa-list-check"></i></div>
                    <div class="summary-info">
                        <h3><?php echo $totalTasks; ?></h3>
                        <p>Total Tasks</p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon icon-yellow"><i class="fa-solid fa-clock"></i></div>
                    <div class="summary-info">
                        <h3><?php echo $todoCount; ?></h3>
                        <p>To-Do</p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon icon-purple"><i class="fa-solid fa-spinner"></i></div>
                    <div class="summary-info">
                        <h3><?php echo $progressCount; ?></h3>
                        <p>In-Progress</p>
                    </div>
                </div>
                <div class="summary-card">
                    <div class="summary-icon icon-green"><i class="fa-solid fa-circle-check"></i></div>
                    <div class="summary-info">
                        <h3><?php echo $doneCount; ?></h3>
                        <p>Completed</p>
                    </div>
                </div>
                <div class="summary-card" onclick="filterOverdue(this)" style="cursor:pointer;" title="Click to filter overdue tasks">
                    <div class="summary-icon icon-red"><i class="fa-solid fa-calendar-day"></i></div>
                    <div class="summary-info">
                        <h3><?php echo $dueTodayCount; ?> <span style="font-size:14px;font-weight:600;color:#9ca3af;">/ <?php echo $totalDueCount; ?></span></h3>
                        <p>Due Today / Total Due</p>
                    </div>
                </div>
            </div>

            <div class="tabs-wrapper">
                <div class="tab-top-line"></div>
                <div class="tabs-container">
                    <button class="tab-btn active" onclick="filterTasks('all', this)"><i class="fa-solid fa-list-check"></i> All Tasks</button>
                    <button class="tab-btn" onclick="filterTasks('To-Do', this)"><i class="fa-solid fa-clock"></i> To-Do</button>
                    <button class="tab-btn" onclick="filterTasks('In-Progress', this)"><i class="fa-solid fa-spinner"></i> In-Progress</button>
                    <button class="tab-btn" onclick="filterTasks('Completed', this)"><i class="fa-solid fa-circle-check"></i> Completed</button>
                </div>
            </div>

            <div class="table-wrapper">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Task Title</th>
                            <th>Assigned To</th>
                            <th>Assigned By</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Assigned Date</th>
                            <th>Due Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php echo $taskTableRows; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Create Task Modal -->
    <div id="createTaskModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Create New Task</h2>
                <button type="button" class="close-btn" onclick="closeModal('createTaskModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form action="user_tasks.php" method="POST">
                <div class="form-grid">
                    <div class="form-group full-width"><label>Task Title</label><input type="text" name="title" required placeholder="e.g. Follow up with Acme Corp"></div>
                    <div class="form-group full-width"><label>Description</label><textarea name="description" rows="3" placeholder="Detailed task description..."></textarea></div>
                    <div class="form-group">
                        <label>Assigned To</label>
                        <select name="assigned_to" required>
                            <option value="Unassigned">Unassigned</option>
                            <?php echo $assigneeOptions; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Priority</label>
                        <select name="priority">
                            <option value="Low">Low</option>
                            <option value="Medium" selected>Medium</option>
                            <option value="High">High</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="To-Do" selected>To-Do</option>
                            <option value="In-Progress">In-Progress</option>
                            <option value="Completed">Completed</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Due Date</label><input type="date" name="due_date" required></div>
                </div>
                <button type="submit" name="create_task" class="submit-btn">Save Task</button>
            </form>
        </div>
    </div>

    <!-- Edit Task Modal -->
    <div id="editTaskModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit Task Details</h2>
                <button type="button" class="close-btn" onclick="closeModal('editTaskModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form action="user_tasks.php" method="POST">
                <input type="hidden" name="task_id" id="edit_task_id">
                <div class="form-grid">
                    <div class="form-group full-width"><label>Task Title</label><input type="text" name="title" id="edit_title" required></div>
                    <div class="form-group full-width"><label>Description</label><textarea name="description" id="edit_description" rows="3"></textarea></div>
                    <div class="form-group">
                        <label>Assigned To</label>
                        <select name="assigned_to" id="edit_assigned_to" required>
                            <option value="Unassigned">Unassigned</option>
                            <?php echo $assigneeOptions; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Priority</label>
                        <select name="priority" id="edit_priority">
                            <option value="Low">Low</option>
                            <option value="Medium">Medium</option>
                            <option value="High">High</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="edit_status">
                            <option value="To-Do">To-Do</option>
                            <option value="In-Progress">In-Progress</option>
                            <option value="Completed">Completed</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Due Date</label><input type="date" name="due_date" id="edit_due_date" required></div>
                </div>
                <button type="submit" name="update_task" class="submit-btn" style="background-color: #22c55e;">Update Task</button>
            </form>
        </div>
    </div>

    <!-- View Task Modal -->
    <div id="viewTaskModal" class="modal">
        <div class="modal-content">
            <div class="modal-header" style="margin-bottom:14px;">
                <h2>Task Details View</h2>
                <button type="button" class="close-btn" onclick="closeModal('viewTaskModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="form-grid" style="gap:10px;">
                <div class="form-group full-width" style="margin-bottom:2px;"><label>Task Title</label><div class="view-data-box" id="view_title">-</div></div>
                <div class="form-group full-width" style="margin-bottom:2px;"><label>Description</label><div class="view-data-box" id="view_description" style="min-height: 56px; align-items: flex-start; padding-top: 8px;">-</div></div>
                <div class="form-group" style="margin-bottom:2px;"><label>Assigned To</label><div class="view-data-box" id="view_assigned_to">-</div></div>
                <div class="form-group" style="margin-bottom:2px;"><label>Assigned By</label><div class="view-data-box" id="view_assigned_by">-</div></div>
                <div class="form-group" style="margin-bottom:2px;"><label>Priority</label><div class="view-data-box" id="view_priority">-</div></div>
                <div class="form-group" style="margin-bottom:2px;"><label>Status</label><div class="view-data-box" id="view_status">-</div></div>
                <div class="form-group" style="margin-bottom:2px;"><label>Due Date</label><div class="view-data-box" id="view_due_date">-</div></div>
            </div>

            <!-- Subtask Section -->
            <div class="subtask-section" id="subtaskSection" style="display:none;">
                <h4><i class="fa-solid fa-list-check"></i> Sub Tasks <span class="sub-count" id="subCount">0</span></h4>
                <div class="subtask-list" id="subtaskList"></div>
            </div>

            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button class="submit-btn" onclick="switchToEditMode()" style="background-color: #22c55e; margin-top: 0;"><i class="fa-solid fa-pen-to-square"></i> Edit Task</button>
                <button class="submit-btn" onclick="closeModal('viewTaskModal')" style="background-color: #6b7280; margin-top: 0;">Close</button>
            </div>
        </div>
    </div>

    <script>
        function filterOverdue(card) {
            const rows = document.querySelectorAll('.task-row');
            let count = 0;
            rows.forEach(r => {
                if (r.dataset.overdue === '1') { r.style.display = ''; count++; }
                else r.style.display = 'none';
            });
            // Tab button highlight সরাও
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            if (count === 0) {
                Swal.fire({ toast:true, position:'top-end', icon:'info', title:'No overdue tasks found!', showConfirmButton:false, timer:2000 });
                // সব দেখাও
                rows.forEach(r => r.style.display = '');
            }
        }

        function filterTasks(status, btnElement) {
            const tabBtns = document.querySelectorAll('.tab-btn');
            tabBtns.forEach(btn => btn.classList.remove('active'));
            btnElement.classList.add('active');

            const rows = document.querySelectorAll('.task-row');
            rows.forEach(row => {
                if (status === 'all') { row.style.display = ''; } 
                else {
                    if (row.getAttribute('data-status') === status) { row.style.display = ''; } 
                    else { row.style.display = 'none'; }
                }
            });
        }

        function openModal(id) { document.getElementById(id).style.display = "flex"; }
        function closeModal(id) { document.getElementById(id).style.display = "none"; }

        let currentTaskData = null; 

        function openViewModal(task) {
            currentTaskData = task; 
            document.getElementById('view_title').innerText = task.title || 'N/A';
            document.getElementById('view_description').innerText = task.description || 'No description provided.';
            document.getElementById('view_assigned_to').innerText = task.assigned_to || 'Unassigned';
            document.getElementById('view_assigned_by').innerText = task.assigned_by || '—';
            document.getElementById('view_priority').innerText = task.priority || 'Medium';
            document.getElementById('view_status').innerText = task.status || 'To-Do';
            document.getElementById('view_due_date').innerText = task.due_date || 'N/A';

            // Subtask fetch
            const subSection = document.getElementById('subtaskSection');
            const subList    = document.getElementById('subtaskList');
            const subCount   = document.getElementById('subCount');
            subSection.style.display = 'none';
            subList.innerHTML = '';

            fetch('user_tasks.php?action=get_subtasks&task_id=' + task.id)
                .then(r => r.json())
                .then(data => {
                    if (data && data.length > 0) {
                        subCount.innerText = data.length;
                        data.forEach(function(sub) {
                            const isDone = sub.is_done == 1;
                            subList.innerHTML += `<div class="subtask-item ${isDone ? 'done' : ''}">
                                <i class="fa-solid ${isDone ? 'fa-circle-check' : 'fa-circle'}"></i>
                                ${sub.title}
                            </div>`;
                        });
                        subSection.style.display = 'block';
                    }
                })
                .catch(() => {}); // subtask না থাকলে চুপ থাকো

            openModal('viewTaskModal');
        }

        function switchToEditMode() {
            closeModal('viewTaskModal');
            if(currentTaskData) openEditModal(currentTaskData);
        }

        function openEditModal(task) {
            document.getElementById('edit_task_id').value = task.id;
            document.getElementById('edit_title').value = task.title || '';
            document.getElementById('edit_description').value = task.description || '';
            document.getElementById('edit_assigned_to').value = task.assigned_to || 'Unassigned';
            document.getElementById('edit_priority').value = task.priority || 'Medium';
            document.getElementById('edit_status').value = task.status || 'To-Do';
            document.getElementById('edit_due_date').value = task.due_date || '';
            openModal('editTaskModal');
        }

        function confirmDelete(formId, typeName) {
            Swal.fire({ title: 'Are you sure?', text: "You won't be able to revert this!", icon: 'warning', showCancelButton: true, confirmButtonColor: '#ef4444', confirmButtonText: 'Yes, delete it!' })
            .then((result) => { if (result.isConfirmed) { document.getElementById(formId).submit(); } });
        }

        window.onload = function() {
            // Past date disable করো — create ও edit modal এর due_date input এ
            const today = new Date().toISOString().split('T')[0];
            document.querySelectorAll('input[type="date"]').forEach(function(el) {
                el.setAttribute('min', today);
            });

            <?php if($toastMessage != ""): ?>
                const toast = document.getElementById("toastBox");
                document.getElementById("toastMsg").innerText = "<?php echo $toastMessage; ?>";
                toast.className = "show <?php echo $toastType; ?>";
                setTimeout(() => toast.className = toast.className.replace("show", ""), 3000);
            <?php endif; ?>
        };

        // Hamburger & Dark Mode handled by sidebar.php
    </script>
</body>
</html>