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

// ========================================================================
// AJAX: Subtask handlers (must be before any HTML output)
// ========================================================================
if (isset($_GET['get_subtasks']) && isset($_GET['task_id']) && isset($conn)) {
    header('Content-Type: application/json');
    $tid  = (int)$_GET['task_id'];
    $res  = mysqli_query($conn, "SELECT id, title, is_done FROM subtasks WHERE task_id=$tid ORDER BY id ASC");
    $rows = [];
    if ($res) { while ($r = mysqli_fetch_assoc($res)) $rows[] = $r; }
    echo json_encode($rows);
    exit();
}

// Save subtasks for edit modal (add new + delete removed)
if (isset($_POST['save_edit_subtasks']) && isset($conn)) {
    header('Content-Type: application/json');
    $tid = (int)($_POST['task_id'] ?? 0);
    $subtasksJson = $_POST['subtasks_json'] ?? '[]';
    $subtasks = json_decode($subtasksJson, true);
    $keepIds = [];
    if (is_array($subtasks)) {
        foreach ($subtasks as $st) {
            $stTitle = mysqli_real_escape_string($conn, trim($st['title'] ?? ''));
            if ($stTitle === '') continue;
            if (!empty($st['id']) && (int)$st['id'] > 0) {
                // existing — update title
                $stId = (int)$st['id'];
                mysqli_query($conn, "UPDATE subtasks SET title='$stTitle' WHERE id=$stId AND task_id=$tid");
                $keepIds[] = $stId;
            } else {
                // new
                mysqli_query($conn, "INSERT INTO subtasks (task_id, title) VALUES ($tid, '$stTitle')");
                $keepIds[] = mysqli_insert_id($conn);
            }
        }
    }
    // Delete subtasks not in keepIds
    if (!empty($keepIds)) {
        $keepStr = implode(',', $keepIds);
        mysqli_query($conn, "DELETE FROM subtasks WHERE task_id=$tid AND id NOT IN ($keepStr)");
    } else {
        mysqli_query($conn, "DELETE FROM subtasks WHERE task_id=$tid");
    }
    // Return updated list
    $res  = mysqli_query($conn, "SELECT id, title, is_done FROM subtasks WHERE task_id=$tid ORDER BY id ASC");
    $rows = [];
    if ($res) { while ($r = mysqli_fetch_assoc($res)) $rows[] = $r; }
    echo json_encode(['success' => true, 'subtasks' => $rows]);
    exit();
}

if (isset($_GET['toggle_subtask']) && isset($_GET['st_id']) && isset($conn)) {
    header('Content-Type: application/json');
    $stId  = (int)$_GET['st_id'];
    $isDone = (int)$_GET['done'];
    mysqli_query($conn, "UPDATE subtasks SET is_done=$isDone WHERE id=$stId");
    // Return updated counts for this task
    $taskRes = mysqli_query($conn, "SELECT task_id FROM subtasks WHERE id=$stId");
    $taskRow = $taskRes ? mysqli_fetch_assoc($taskRes) : null;
    $total = $done = 0;
    if ($taskRow) {
        $tid   = (int)$taskRow['task_id'];
        $cr    = mysqli_query($conn, "SELECT COUNT(*) as c FROM subtasks WHERE task_id=$tid");
        $total = ($cr && ($row = mysqli_fetch_assoc($cr))) ? (int)$row['c'] : 0;
        $dr    = mysqli_query($conn, "SELECT COUNT(*) as c FROM subtasks WHERE task_id=$tid AND is_done=1");
        $done  = ($dr && ($row = mysqli_fetch_assoc($dr))) ? (int)$row['c'] : 0;
    }
    echo json_encode(['total' => $total, 'done' => $done]);
    exit();
}

$toastMessage    = "";
$toastType       = "";
$currentUser     = $_SESSION['name']     ?? '';
$currentUsername = $_SESSION['username'] ?? '';
$currentRole     = $_SESSION['role']     ?? '';

// DB থেকে fresh role ও name fetch — session mismatch হলেও ঠিক থাকবে
if (isset($conn) && isset($_SESSION['user_id'])) {
    $uid     = (int)$_SESSION['user_id'];
    $freshQ  = mysqli_query($conn, "SELECT role, name, username FROM users WHERE id=$uid LIMIT 1");
    if ($freshQ && $freshRow = mysqli_fetch_assoc($freshQ)) {
        $currentRole     = $freshRow['role'];
        $currentUsername = $freshRow['username'];
        if (!empty($freshRow['name'])) $currentUser = $freshRow['name'];
    }
}
$isAgent = ($currentRole === 'agent');

// ========================================================================
// 2. TASK CRUD LOGIC
// ========================================================================

// A. CREATE
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_task'])) {
    if (isset($conn)) {
        $title       = mysqli_real_escape_string($conn, $_POST['title']       ?? '');
        $description = mysqli_real_escape_string($conn, $_POST['description'] ?? '');
        $assigned_to_raw = $_POST['assigned_to'] ?? 'Unassigned';

        // Agent: admin/super_admin কে assign করা যাবে না
        if ($isAgent) {
            $assignees     = array_map('trim', explode(',', $assigned_to_raw));
            $blockedRoles  = ['super_admin', 'admin'];
            $filteredAssignees = [];
            foreach ($assignees as $aUser) {
                if ($aUser === '' || $aUser === 'Unassigned') continue;
                $escA   = mysqli_real_escape_string($conn, $aUser);
                $chkRes = mysqli_query($conn, "SELECT role FROM users WHERE username='$escA' LIMIT 1");
                $chkRow = $chkRes ? mysqli_fetch_assoc($chkRes) : null;
                if ($chkRow && in_array($chkRow['role'], $blockedRoles)) {
                    // skip — agent cannot assign to admin/super_admin
                    continue;
                }
                $filteredAssignees[] = $aUser;
            }
            $assigned_to_raw = count($filteredAssignees) ? implode(',', $filteredAssignees) : 'Unassigned';
        }

        $assigned_to = mysqli_real_escape_string($conn, $assigned_to_raw);
        // assigned_by: name থাকলে name, না থাকলে username — filter এ দুটোই check করা হয়
        $assigned_by = mysqli_real_escape_string($conn, !empty($currentUser) ? $currentUser : $currentUsername);
        $priority    = mysqli_real_escape_string($conn, $_POST['priority']    ?? 'Medium');
        $status      = mysqli_real_escape_string($conn, $_POST['status']      ?? 'To-Do');
        $due_date    = mysqli_real_escape_string($conn, $_POST['due_date']    ?? '');

        // tasks টেবিলে প্রয়োজনীয় কলাম না থাকলে যোগ করব
        @mysqli_query($conn, "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS assigned_by VARCHAR(100) DEFAULT NULL");
        @mysqli_query($conn, "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP");
        @mysqli_query($conn, "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS client_ids TEXT DEFAULT NULL");

        // subtasks টেবিল না থাকলে তৈরি করব
        @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS subtasks (
            id INT(11) NOT NULL AUTO_INCREMENT,
            task_id INT(11) NOT NULL,
            title VARCHAR(255) NOT NULL,
            is_done TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY task_id (task_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $client_ids  = mysqli_real_escape_string($conn, $_POST['client_ids'] ?? '');

        $sql = "INSERT INTO tasks (title, description, assigned_to, assigned_by, priority, status, due_date, client_ids)
                VALUES ('$title','$description','$assigned_to','$assigned_by','$priority','$status','$due_date','$client_ids')";
        if (mysqli_query($conn, $sql)) {
            $newTaskId = mysqli_insert_id($conn);

            // Subtasks সেভ করা
            $subtasksJson = $_POST['subtasks_json'] ?? '[]';
            $subtasks     = json_decode($subtasksJson, true);
            if (is_array($subtasks)) {
                foreach ($subtasks as $st) {
                    $stTitle = mysqli_real_escape_string($conn, $st['title'] ?? '');
                    if ($stTitle !== '') {
                        mysqli_query($conn, "INSERT INTO subtasks (task_id, title) VALUES ('$newTaskId', '$stTitle')");
                    }
                }
            }

            $toastMessage = "Task created successfully!";
            $toastType    = "success";
        } else {
            $toastMessage = "Database Error! Could not create task.";
            $toastType    = "error";
        }
    }
}

// B. UPDATE
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_task'])) {
    if (isset($conn)) {
        $id          = mysqli_real_escape_string($conn, $_POST['task_id']     ?? '');
        $status      = mysqli_real_escape_string($conn, $_POST['status']      ?? 'To-Do');

        if ($isAgent) {
            // Agent: শুধু নিজের task এর status update করতে পারবে (assigned_by তে name বা username দুটোই match)
            $escName     = mysqli_real_escape_string($conn, $currentUser);
            $escUsername = mysqli_real_escape_string($conn, $currentUsername);
            $ownerCheck = mysqli_query($conn,
                "SELECT id FROM tasks WHERE id='$id' AND (
                    assigned_by = '$escName'
                    OR assigned_by = '$escUsername'
                    OR assigned_to = '$escUsername'
                    OR assigned_to LIKE '$escUsername,%'
                    OR assigned_to LIKE '%,$escUsername'
                    OR assigned_to LIKE '%,$escUsername,%'
                ) LIMIT 1"
            );
            if ($ownerCheck && mysqli_num_rows($ownerCheck) > 0) {
                $sql = "UPDATE tasks SET status='$status', updated_at=NOW() WHERE id='$id'";
                if (mysqli_query($conn, $sql)) {
                    $toastMessage = "Task status updated successfully!";
                    $toastType    = "success";
                } else {
                    $toastMessage = "Database Error! Could not update task.";
                    $toastType    = "error";
                }
            } else {
                $toastMessage = "Permission denied!";
                $toastType    = "error";
            }
        } else {
            $title       = mysqli_real_escape_string($conn, $_POST['title']       ?? '');
            $description = mysqli_real_escape_string($conn, $_POST['description'] ?? '');
            $assigned_to = mysqli_real_escape_string($conn, $_POST['assigned_to'] ?? 'Unassigned');
            $priority    = mysqli_real_escape_string($conn, $_POST['priority']    ?? 'Medium');
            $due_date    = mysqli_real_escape_string($conn, $_POST['due_date']    ?? '');
            $client_ids  = mysqli_real_escape_string($conn, $_POST['client_ids']  ?? '');

            $sql = "UPDATE tasks SET title='$title', description='$description', assigned_to='$assigned_to',
                    priority='$priority', status='$status', due_date='$due_date', client_ids='$client_ids', updated_at=NOW() WHERE id='$id'";
            if (mysqli_query($conn, $sql)) {
                $toastMessage = "Task updated successfully!";
                $toastType    = "success";
            } else {
                $toastMessage = "Database Error! Could not update task.";
                $toastType    = "error";
            }
        }
    }
}

// C. DELETE
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_task'])) {
    if ($isAgent) {
        $toastMessage = "Permission denied! Agents cannot delete tasks.";
        $toastType    = "error";
    } elseif (isset($conn)) {
        $del_id = mysqli_real_escape_string($conn, $_POST['delete_task_id'] ?? '');
        if (mysqli_query($conn, "DELETE FROM tasks WHERE id='$del_id'")) {
            $toastMessage = "Task deleted successfully!";
            $toastType    = "success";
        } else {
            $toastMessage = "Error deleting task!";
            $toastType    = "error";
        }
    }
}

// ========================================================================
// 3. FETCH DATA
// ========================================================================
$assigneeOptions = "";
$assigneeList    = []; // for multi-select in create/edit modals
if (isset($conn)) {
    // Agent: admin ও super_admin কে assignee হিসেবে দেখাবে না
    $uQuery = $isAgent
        ? "SELECT username, name FROM users WHERE role NOT IN ('admin','super_admin') ORDER BY name ASC"
        : "SELECT username, name FROM users ORDER BY name ASC";
    $uq = mysqli_query($conn, $uQuery);
    while ($u = mysqli_fetch_assoc($uq)) {
        $assigneeOptions .= "<option value='{$u['username']}'>{$u['name']} ({$u['username']})</option>";
        $assigneeList[]   = $u;
    }
}

// Fetch all clients for dropdown
$clientOptions = [];
if (isset($conn)) {
    $cq = mysqli_query($conn, "SELECT id, name FROM contacts ORDER BY name ASC");
    while ($c = mysqli_fetch_assoc($cq)) {
        $clientOptions[] = ['id' => $c['id'], 'name' => $c['name']];
    }
}

// Summary counts
$totalTasks = $todoCount = $progressCount = $doneCount = $overdueCount = 0;
if (isset($conn)) {
    // ✅ ROLE-BASED TASK VISIBILITY
    // Build visible users list based on role hierarchy
    $visibleUsernames = [];
    $escUsername = mysqli_real_escape_string($conn, $currentUsername);
    
    if ($currentRole === 'admin') {
        // Admin: self + level-1 (direct reports) + level-2 (indirect reports)
        $visibleUsernames[] = $currentUsername;
        
        // Level 1: Direct reports to admin
        $lvl1Q = mysqli_query($conn, "SELECT username FROM users WHERE reporting_to = '$escUsername'");
        $lvl1Usernames = [];
        if ($lvl1Q) {
            while ($r = mysqli_fetch_assoc($lvl1Q)) {
                $visibleUsernames[] = $r['username'];
                $lvl1Usernames[]    = $r['username'];
            }
        }
        
        // Level 2: Reports of those managers/agents
        foreach ($lvl1Usernames as $lvl1User) {
            $escLvl1 = mysqli_real_escape_string($conn, $lvl1User);
            $lvl2Q   = mysqli_query($conn, "SELECT username FROM users WHERE reporting_to = '$escLvl1'");
            if ($lvl2Q) {
                while ($r2 = mysqli_fetch_assoc($lvl2Q)) {
                    if (!in_array($r2['username'], $visibleUsernames)) {
                        $visibleUsernames[] = $r2['username'];
                    }
                }
            }
        }
        
    } elseif ($currentRole === 'manager') {
        // Manager: self + direct reports only
        $visibleUsernames[] = $currentUsername;
        $subQ = mysqli_query($conn, "SELECT username FROM users WHERE reporting_to = '$escUsername'");
        if ($subQ) {
            while ($r = mysqli_fetch_assoc($subQ)) {
                $visibleUsernames[] = $r['username'];
            }
        }
        
    } elseif ($currentRole === 'agent') {
        // Agent: only self
        $visibleUsernames[] = $currentUsername;
    }
    // super_admin: no restriction (empty visibleUsernames = see all)

    // Build WHERE clause based on visible users
    $agentWhereClause = '';
    if (!empty($visibleUsernames) && $currentRole !== 'super_admin') {
        $escName = mysqli_real_escape_string($conn, $currentUser);
        $conditions = [];
        
        // Tasks assigned BY any visible user (assigned_by can be name or username)
        foreach ($visibleUsernames as $vu) {
            $escVu = mysqli_real_escape_string($conn, $vu);
            $conditions[] = "assigned_by = '$escVu'";
        }
        $conditions[] = "assigned_by = '$escName'"; // Also check by current user's name
        
        // Tasks assigned TO any visible user (comma-separated usernames)
        foreach ($visibleUsernames as $vu) {
            $escVu = mysqli_real_escape_string($conn, $vu);
            $conditions[] = "assigned_to = '$escVu'";
            $conditions[] = "assigned_to LIKE '$escVu,%'";
            $conditions[] = "assigned_to LIKE '%,$escVu'";
            $conditions[] = "assigned_to LIKE '%,$escVu,%'";
        }
        
        $agentWhereClause = " WHERE (" . implode(' OR ', $conditions) . ")";
    }

    $cr = mysqli_query($conn, "SELECT status, COUNT(*) as cnt FROM tasks{$agentWhereClause} GROUP BY status");
    if ($cr) {
        while ($row = mysqli_fetch_assoc($cr)) {
            $totalTasks += $row['cnt'];
            if ($row['status'] == 'To-Do')       $todoCount     = $row['cnt'];
            if ($row['status'] == 'In-Progress')  $progressCount = $row['cnt'];
            if ($row['status'] == 'Completed')    $doneCount     = $row['cnt'];
        }
    }
    $today = date('Y-m-d');
    $overdueExtra = $agentWhereClause ? $agentWhereClause . " AND " : " WHERE ";
    $ocr = mysqli_query($conn,
        "SELECT COUNT(*) as cnt FROM tasks"
        . ($agentWhereClause ?: '')
        . ($agentWhereClause ? " AND " : " WHERE ")
        . "due_date IS NOT NULL
           AND due_date != ''
           AND due_date != '0000-00-00'
           AND CAST(due_date AS DATE) < CAST('$today' AS DATE)
           AND status NOT IN ('Completed')"
    );
    if ($ocr && ($orow = mysqli_fetch_assoc($ocr))) {
        $overdueCount = (int)$orow['cnt'];
    }
}

$taskTableRows = "";
if (isset($conn)) {
    @mysqli_query($conn, "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS assigned_by VARCHAR(100) DEFAULT NULL");
    @mysqli_query($conn, "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP");

    // ✅ Use same filtering logic for table display
    $taskQuery = "SELECT * FROM tasks";
    if (!empty($visibleUsernames) && $currentRole !== 'super_admin') {
        $escName = mysqli_real_escape_string($conn, $currentUser);
        $conditions = [];
        
        // Tasks assigned BY any visible user
        foreach ($visibleUsernames as $vu) {
            $escVu = mysqli_real_escape_string($conn, $vu);
            $conditions[] = "assigned_by = '$escVu'";
        }
        $conditions[] = "assigned_by = '$escName'";
        
        // Tasks assigned TO any visible user
        foreach ($visibleUsernames as $vu) {
            $escVu = mysqli_real_escape_string($conn, $vu);
            $conditions[] = "assigned_to = '$escVu'";
            $conditions[] = "assigned_to LIKE '$escVu,%'";
            $conditions[] = "assigned_to LIKE '%,$escVu'";
            $conditions[] = "assigned_to LIKE '%,$escVu,%'";
        }
        
        $taskQuery .= " WHERE (" . implode(' OR ', $conditions) . ")";
    }
    $taskQuery .= " ORDER BY id DESC";
    $tq = mysqli_query($conn, $taskQuery);
    if ($tq && mysqli_num_rows($tq) > 0) {
        while ($row = mysqli_fetch_assoc($tq)) {
            $taskData = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');

            $pClass = "priority-medium";
            if ($row['priority'] == 'High') $pClass = "priority-high";
            if ($row['priority'] == 'Low')  $pClass = "priority-low";

            $sClass = "status-todo";
            if ($row['status'] == 'In-Progress') $sClass = "status-progress";
            if ($row['status'] == 'Completed')   $sClass = "status-completed";

            // Overdue check per row
            $isOverdue = false;
            if (!empty($row['due_date']) && $row['due_date'] !== '0000-00-00'
                && $row['status'] !== 'Completed'
                && strtotime($row['due_date']) < strtotime(date('Y-m-d'))) {
                $isOverdue = true;
            }
            $overdueAttr  = $isOverdue ? ' data-overdue="1"' : '';
            $overdueStyle = $isOverdue ? ' style="border-left:3px solid #ef4444;"' : '';

            $assignedBy = !empty($row['assigned_by'])
                ? htmlspecialchars($row['assigned_by'])
                : '<span style="color:#9ca3af;font-style:italic;">—</span>';

            // Due Date cell — red if overdue
            $dueDateDisplay = 'N/A';
            if (!empty($row['due_date']) && $row['due_date'] !== '0000-00-00') {
                if ($isOverdue) {
                    $dueDateDisplay = '<span style="color:#ef4444;font-weight:700;">'
                        . date('M d, Y', strtotime($row['due_date']))
                        . ' <i class="fa-solid fa-triangle-exclamation" style="font-size:10px;"></i></span>';
                } else {
                    $dueDateDisplay = date('M d, Y', strtotime($row['due_date']));
                }
            }

            // Created At
            $createdAt = !empty($row['created_at'])
                ? '<span class="date-main">' . date('M d, Y', strtotime($row['created_at'])) . '</span>'
                  . '<span class="date-time">' . date('h:i A', strtotime($row['created_at'])) . '</span>'
                : '<span style="color:#9ca3af;">—</span>';

            // Last Edited
            $updatedAt = !empty($row['updated_at'])
                ? '<span class="date-main edited">' . date('M d, Y', strtotime($row['updated_at'])) . '</span>'
                  . '<span class="date-time">' . date('h:i A', strtotime($row['updated_at'])) . '</span>'
                : '<span style="color:#9ca3af;font-style:italic;font-size:10px;">Not edited</span>';

            $taskTableRows .= "
                <tr class='task-row' data-status='{$row['status']}'{$overdueAttr}{$overdueStyle}>
                    <td style='font-weight:700;'>#{$row['id']}</td>
                    <td style='text-align:left;font-weight:600;'>{$row['title']}" . ($isOverdue ? " <span style='font-size:9px;background:#fee2e2;color:#ef4444;border-radius:3px;padding:1px 5px;font-weight:700;'>OVERDUE</span>" : "") . "</td>
                    <td>{$row['assigned_to']}</td>
                    <td>{$assignedBy}</td>
                    <td><span class='badge $pClass'>{$row['priority']}</span></td>
                    <td><span class='badge $sClass'>{$row['status']}</span></td>
                    <td><div class='date-cell'>$createdAt</div></td>
                    <td>{$dueDateDisplay}</td>
                    <td><div class='date-cell'>$updatedAt</div></td>
                    <td>
                        <div class='action-btns'>
                            <button class='btn-view' onclick='openViewModal({$taskData})'><i class='fa-solid fa-eye'></i></button>
                            <button class='btn-edit' onclick='openEditModal({$taskData})'><i class='fa-solid fa-pen'></i></button>
                            " . (!$isAgent ? "
                            <form method='POST' id='del-{$row['id']}' style='display:inline;'>
                                <input type='hidden' name='delete_task_id' value='{$row['id']}'>
                                <input type='hidden' name='delete_task' value='1'>
                                <button type='button' class='btn-delete' onclick='confirmDelete(\"del-{$row['id']}\")'><i class='fa-solid fa-trash'></i></button>
                            </form>" : "") . "
                        </div>
                    </td>
                </tr>";
        }
    } else {
        $taskTableRows = "<tr><td colspan='10' style='padding:30px;color:#6b7280;text-align:center;'>No tasks found. Create your first task!</td></tr>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Manager - Systellio CRM</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Inter',sans-serif; }
        body { background-color:#f3f4f6; display:flex; height:100vh; overflow:hidden; transition:background-color 0.3s,color 0.3s; color:#111827; }

        /* Toast */
        #toastBox { visibility:hidden; min-width:260px; background-color:#333; color:#fff; text-align:center; border-radius:8px; padding:16px; position:fixed; z-index:9999; right:30px; top:30px; font-size:14px; font-weight:600; box-shadow:0 4px 12px rgba(0,0,0,.15); display:flex; align-items:center; gap:10px; transform:translateX(120%); transition:transform .4s cubic-bezier(.68,-.55,.265,1.55),visibility .4s; }
        #toastBox.show { visibility:visible; transform:translateX(0); }
        #toastBox.success { background-color:#10b981; }
        #toastBox.error   { background-color:#ef4444; }

        /* Main Layout */
        .main-content { flex-grow:1; display:flex; flex-direction:column; overflow-y:auto; background-color:#f3f4f6; transition:background-color 0.3s; }
        
        
        .toggle-btn:hover { color:#111827; }
        
        
        .nav-icon-btn:hover { color:#3b82f6; }
        
        .user-profile i { font-size:24px; color:#3b82f6; }

        /* Page Content */
        .page-body { padding:30px; }

        /* Page Header */
        .page-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:25px; }
        .page-title h1 { font-size:26px; font-weight:800; letter-spacing:-.5px; margin-bottom:4px; }
        .page-title p  { font-size:11px; color:#6b7280; font-weight:500; }
        .create-btn { background-color:#000; color:#fff; padding:11px 20px; border-radius:6px; font-size:13px; font-weight:700; border:none; cursor:pointer; display:flex; align-items:center; gap:8px; box-shadow:0 4px 6px rgba(0,0,0,.1); transition:0.3s; }
        .create-btn:hover { background-color:#1f2937; }

        /* Summary Cards */
        .summary-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:16px; margin-bottom:25px; }
        .icon-red { background:#fee2e2; color:#ef4444; }
        .summary-card { background:#fff; border-radius:10px; padding:20px 22px; border:1px solid #e5e7eb; display:flex; align-items:center; gap:16px; box-shadow:0 2px 6px rgba(0,0,0,.04); transition:0.3s; }
        .summary-icon { width:48px; height:48px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
        .icon-blue   { background:#dbeafe; color:#3b82f6; }
        .icon-yellow { background:#fef3c7; color:#f59e0b; }
        .icon-purple { background:#ede9fe; color:#8b5cf6; }
        .icon-green  { background:#d1fae5; color:#10b981; }
        .summary-info h3 { font-size:22px; font-weight:800; margin-bottom:2px; }
        .summary-info p  { font-size:11px; color:#6b7280; font-weight:600; }

        /* Tabs */
        .tabs-wrapper { margin-bottom:18px; width:max-content; }
        .tab-top-line { height:3px; width:100%; background:linear-gradient(to right,#3b82f6 33%,#f59e0b 33%,#f59e0b 66%,#10b981 66%); border-radius:3px 3px 0 0; }
        .tabs-container { display:flex; background:#fff; padding:5px; border-radius:0 0 6px 6px; gap:5px; border:1px solid #e5e7eb; border-top:none; transition:0.3s; }
        .tab-btn { padding:8px 18px; font-size:12px; font-weight:700; border:none; background:transparent; cursor:pointer; border-radius:4px; color:#6b7280; display:flex; align-items:center; gap:6px; transition:0.3s; }
        .tab-btn.active { background:#f3f4f6; color:#111827; box-shadow:0 2px 4px rgba(0,0,0,.05); }

        /* Table */
        .table-wrapper { border-radius:8px; overflow:hidden; border:1px solid #d1d5db; background:#fff; transition:0.3s; }
        .custom-table { width:100%; border-collapse:collapse; text-align:center; font-size:12px; }
        .custom-table th { background-color:#c4f042; padding:14px 10px; font-weight:700; color:#000; border-bottom:1px solid #d1d5db; }
        .custom-table td { padding:12px 10px; color:#374151; font-weight:500; vertical-align:middle; border-right:1px solid rgba(0,0,0,.05); transition:0.3s; }
        .custom-table td:last-child { border-right:none; }
        .custom-table tbody tr:nth-child(4n+1) { background:#e6fced; }
        .custom-table tbody tr:nth-child(4n+2) { background:#fcedf6; }
        .custom-table tbody tr:nth-child(4n+3) { background:#fceddb; }
        .custom-table tbody tr:nth-child(4n+4) { background:#e6edff; }
        .task-row[data-overdue="1"] { background:#fff5f5 !important; }

        .badge { padding:4px 8px; border-radius:4px; font-size:10px; font-weight:700; text-transform:uppercase; }
        .priority-high { background:#fee2e2; color:#ef4444; }
        .priority-medium { background:#fef3c7; color:#f59e0b; }
        .priority-low { background:#dcfce7; color:#10b981; }
        .status-todo { background:#e5e7eb; color:#374151; }
        .status-progress { background:#dbeafe; color:#3b82f6; }
        .status-completed { background:#d1fae5; color:#059669; }

        .action-btns { display:flex; justify-content:center; gap:6px; }
        .btn-view { background:#60a5fa; color:#fff; padding:6px 10px; border-radius:4px; font-size:11px; border:none; cursor:pointer; transition:0.3s; }
        .btn-view:hover { background:#3b82f6; }
        .btn-edit { background:#4ade80; color:#fff; padding:6px 10px; border-radius:4px; font-size:11px; border:none; cursor:pointer; transition:0.3s; }
        .btn-edit:hover { background:#22c55e; }
        .btn-delete { background:#f87171; color:#fff; padding:6px 10px; border-radius:4px; font-size:11px; border:none; cursor:pointer; transition:0.3s; }
        .btn-delete:hover { background:#ef4444; }

        /* Modals */
        .modal { display:none; position:fixed; z-index:2000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,.5); align-items:flex-start; justify-content:center; overflow-y:auto; padding:18px 16px; }
        .modal-content { background:#fff; padding:20px 22px; border-radius:10px; width:100%; max-width:580px; box-shadow:0 10px 25px rgba(0,0,0,.15); transition:0.3s; margin:auto; }
        .modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; }
        .modal-header h2 { font-size:15px; font-weight:700; }
        .close-btn { font-size:17px; cursor:pointer; color:#6b7280; border:none; background:none; transition:0.3s; }
        .close-btn:hover { color:#ef4444; }

        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .form-group { margin-bottom:0; }
        .full-width { grid-column:span 2; }
        .form-group label { display:block; font-size:11px; font-weight:700; color:#374151; margin-bottom:4px; }
        .form-group input,
        .form-group select,
        .form-group textarea { 
            width:100%; padding:8px 10px; 
            border:1px solid #d1d5db; border-radius:6px; 
            font-size:12px; outline:none; 
            font-family:'Inter',sans-serif; 
            background:#f3f4f6; 
            color:#111827;
            transition:0.3s; 
        }
        .form-group textarea { resize:vertical; min-height:58px; }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus { border-color:#3b82f6; background:#fff; box-shadow:0 0 0 2px rgba(59,130,246,.1); }
        .form-group input::placeholder,
        .form-group textarea::placeholder { color:#9ca3af; }
        .submit-btn { background:#0f172a; color:#fff; padding:10px; border:none; border-radius:6px; width:100%; font-size:13px; font-weight:700; cursor:pointer; transition:0.3s; margin-top:4px; }
        .submit-btn:hover { background:#1e293b; }

        .view-data-box { background:#f3f4f6; padding:7px 10px; border-radius:6px; border:1px solid #e5e7eb; font-size:12px; font-weight:500; color:#111827; word-break:break-all; min-height:34px; display:flex; align-items:center; transition:0.3s; }

        /* assigned_by info box in create modal */
        .info-box { background:#eff6ff; border:1px solid #bfdbfe; border-radius:6px; padding:7px 11px; font-size:11px; color:#1d4ed8; font-weight:600; display:flex; align-items:center; gap:7px; margin-bottom:10px; }

        /* ===== STEP WIZARD ===== */
        .step-wizard { display:flex; align-items:center; justify-content:center; margin-bottom:14px; gap:0; }
        .step-item { display:flex; flex-direction:column; align-items:center; gap:4px; flex:1; position:relative; }
        .step-item:not(:last-child)::after {
            content:''; position:absolute; top:13px; left:calc(50% + 15px); right:calc(-50% + 15px);
            height:2px; background:#e5e7eb; z-index:0; transition:background 0.3s;
        }
        .step-item.completed:not(:last-child)::after { background:#3b82f6; }
        .step-circle {
            width:26px; height:26px; border-radius:50%; border:2px solid #e5e7eb;
            background:#fff; display:flex; align-items:center; justify-content:center;
            font-size:11px; font-weight:800; color:#9ca3af; z-index:1; transition:all 0.3s;
        }
        .step-item.active   .step-circle { border-color:#3b82f6; background:#3b82f6; color:#fff; box-shadow:0 0 0 3px rgba(59,130,246,0.15); }
        .step-item.completed .step-circle { border-color:#3b82f6; background:#3b82f6; color:#fff; }
        .step-label { font-size:9px; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:0.4px; text-align:center; }
        .step-item.active    .step-label { color:#3b82f6; }
        .step-item.completed .step-label { color:#3b82f6; }

        /* Step Panels */
        .step-panel { display:none; }
        .step-panel.active { display:block; }

        /* Step nav buttons */
        .step-nav { display:flex; gap:8px; margin-top:14px; }
        .btn-prev { background:#f3f4f6; color:#374151; border:1px solid #d1d5db; padding:9px 16px; border-radius:6px; font-size:12px; font-weight:700; cursor:pointer; transition:0.3s; flex:1; }
        .btn-prev:hover { background:#e5e7eb; }
        .btn-next { background:#3b82f6; color:#fff; border:none; padding:9px 16px; border-radius:6px; font-size:12px; font-weight:700; cursor:pointer; transition:0.3s; flex:1; }
        .btn-next:hover { background:#2563eb; }

        /* Subtasks */
        .subtask-list { display:flex; flex-direction:column; gap:6px; margin-bottom:8px; }
        .subtask-row { display:flex; align-items:center; gap:7px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:7px 9px; }
        .subtask-row input[type="text"] { flex:1; border:none; background:transparent; font-size:12px; font-weight:500; color:#111827; outline:none; padding:0; }
        .subtask-row .del-subtask { background:none; border:none; cursor:pointer; color:#f87171; font-size:13px; flex-shrink:0; padding:0; }
        .subtask-row .del-subtask:hover { color:#ef4444; }
        .add-subtask-btn { display:flex; align-items:center; gap:6px; background:none; border:1px dashed #d1d5db; color:#6b7280; font-size:11px; font-weight:700; cursor:pointer; padding:7px 10px; border-radius:6px; width:100%; justify-content:center; transition:0.3s; }
        .add-subtask-btn:hover { border-color:#3b82f6; color:#3b82f6; background:#eff6ff; }

        /* Step 3 review card */
        .review-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
        .review-item { background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:8px 10px; }
        .review-item label { display:block; font-size:9px; font-weight:700; color:#9ca3af; text-transform:uppercase; margin-bottom:3px; }
        .review-item span { font-size:12px; font-weight:600; color:#111827; }
        .review-item.full { grid-column:span 2; }
        .review-subtasks { background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:9px 10px; margin-top:8px; }
        .review-subtasks label { display:block; font-size:9px; font-weight:700; color:#9ca3af; text-transform:uppercase; margin-bottom:6px; }
        .review-subtask-item { display:flex; align-items:center; gap:7px; font-size:11px; font-weight:600; color:#374151; padding:3px 0; }
        .review-subtask-item i { color:#9ca3af; font-size:10px; }

        /* Dark mode for wizard */
        body.dark-mode .step-circle { background:#1e293b; border-color:#334155; }
        body.dark-mode .step-label { color:#475569; }
        body.dark-mode .step-item:not(:last-child)::after { background:#334155; }
        body.dark-mode .btn-prev { background:#1e293b; color:#cbd5e1; border-color:#334155; }
        body.dark-mode .btn-prev:hover { background:#0f172a; }
        body.dark-mode .subtask-row { background:#0f172a; border-color:#334155; }
        body.dark-mode .subtask-row input[type="text"] { color:#f8fafc; }
        body.dark-mode .add-subtask-btn { border-color:#334155; color:#64748b; }
        body.dark-mode .add-subtask-btn:hover { border-color:#3b82f6; color:#3b82f6; background:#1e3a5f; }
        body.dark-mode .review-item { background:#0f172a; border-color:#334155; }
        body.dark-mode .review-item span { color:#f8fafc; }
        body.dark-mode .review-subtasks { background:#0f172a; border-color:#334155; }
        body.dark-mode .review-subtask-item { color:#cbd5e1; }
        body.dark-mode #viewSubtasksSection { color:#cbd5e1; }
        body.dark-mode #viewSubtasksList > div { background:#0f172a !important; border-color:#334155 !important; }
        body.dark-mode #viewSubtasksList span { color:#cbd5e1 !important; }

        /* Date cell — stacked date + time */
        .date-cell { display:flex; flex-direction:column; align-items:center; gap:1px; }
        .date-main { font-size:11px; font-weight:700; color:#374151; }
        .date-main.edited { color:#7c3aed; }
        .date-time { font-size:10px; font-weight:500; color:#9ca3af; }
        body.dark-mode .date-main { color:#cbd5e1; }
        body.dark-mode .date-main.edited { color:#a78bfa; }
        body.dark-mode .date-time { color:#475569; }

        /* Overdue card pulse */
        @keyframes pulse { 0%,100%{opacity:1;} 50%{opacity:0.3;} }
        .overdue-card:hover { border-color:#ef4444; box-shadow:0 4px 12px rgba(239,68,68,0.15); }
        body.dark-mode .task-row[data-overdue="1"] { background:#1a0a0a!important; }

        /* Multi-select */
        .multi-select-wrapper { position:relative; width:100%; }
        .multi-select-box { min-height:36px; padding:4px 32px 4px 8px; border:1px solid #d1d5db; border-radius:6px; background:#f3f4f6; cursor:pointer; display:flex; align-items:center; flex-wrap:wrap; gap:4px; position:relative; transition:0.3s; }
        .multi-select-box:focus-within, .multi-select-box.open { border-color:#3b82f6; background:#fff; box-shadow:0 0 0 2px rgba(59,130,246,.1); }
        .multi-arrow { position:absolute; right:10px; top:50%; transform:translateY(-50%); color:#9ca3af; font-size:11px; transition:transform 0.2s; pointer-events:none; }
        .multi-arrow.open { transform:translateY(-50%) rotate(180deg); }
        .multi-placeholder { font-size:12px; color:#9ca3af; }
        .multi-tag { display:inline-flex; align-items:center; gap:5px; background:#dbeafe; color:#1d4ed8; border-radius:4px; padding:2px 7px; font-size:11px; font-weight:600; }
        .multi-tag .remove-tag { cursor:pointer; font-size:10px; color:#3b82f6; background:none; border:none; padding:0; line-height:1; }
        .multi-tag .remove-tag:hover { color:#1d4ed8; }
        .multi-dropdown { display:none; position:absolute; top:calc(100% + 4px); left:0; width:100%; background:#fff; border:1px solid #d1d5db; border-radius:8px; box-shadow:0 8px 20px rgba(0,0,0,.12); z-index:3000; overflow:hidden; }
        .multi-dropdown.open { display:block; }
        .multi-search-wrap { display:flex; align-items:center; gap:8px; padding:8px 10px; border-bottom:1px solid #f3f4f6; }
        .multi-search-wrap input { border:none; outline:none; font-size:12px; flex:1; background:transparent; color:#111827; }
        .multi-options { max-height:180px; overflow-y:auto; }
        .multi-opt { display:flex; align-items:center; gap:10px; padding:9px 12px; font-size:12px; font-weight:500; color:#374151; cursor:pointer; transition:background 0.15s; }
        .multi-opt:hover { background:#f9fafb; }
        .multi-opt.selected { background:#eff6ff; color:#1d4ed8; }
        .multi-opt-check { width:16px; height:16px; border:1.5px solid #d1d5db; border-radius:4px; display:flex; align-items:center; justify-content:center; flex-shrink:0; transition:0.2s; }
        .multi-opt.selected .multi-opt-check { background:#3b82f6; border-color:#3b82f6; color:#fff; }
        .multi-opt-check i { font-size:9px; display:none; }
        .multi-opt.selected .multi-opt-check i { display:block; }
        .multi-opt small { color:#9ca3af; font-size:10px; }
        .multi-opt.selected small { color:#93c5fd; }
        /* Dark mode multi-select */
        body.dark-mode .multi-select-box { background:#0f172a; border-color:#334155; color:#f8fafc; }
        body.dark-mode .multi-select-box.open { background:#0f172a; }
        body.dark-mode .multi-dropdown { background:#1e293b; border-color:#334155; }
        body.dark-mode .multi-search-wrap { border-color:#334155; }
        body.dark-mode .multi-search-wrap input { color:#f8fafc; }
        body.dark-mode .multi-opt { color:#cbd5e1; }
        body.dark-mode .multi-opt:hover { background:#0f172a; }
        body.dark-mode .multi-opt.selected { background:#1e3a5f; color:#93c5fd; }
        body.dark-mode .multi-opt-check { border-color:#475569; }
        body.dark-mode .multi-tag { background:#1e3a5f; color:#93c5fd; }

        /* Summary red icon dark mode */
        body.dark-mode .icon-red { background:#450a0a; color:#f87171; }

        /* Assign To — full width in create modal step1 grid */
        body.dark-mode .main-content { background-color:#0f172a; }
        body.dark-mode 
        body.dark-mode 
        body.dark-mode .summary-card { background:#1e293b; border-color:#334155; }
        body.dark-mode .summary-info p { color:#94a3b8; }
        body.dark-mode .tabs-container { background:#1e293b; border-color:#334155; }
        body.dark-mode .tab-btn { color:#94a3b8; }
        body.dark-mode .tab-btn.active { background:#0f172a; color:#f8fafc; }
        body.dark-mode .table-wrapper { border-color:#334155; background:#1e293b; }
        body.dark-mode .custom-table th { background:#334155 !important; color:#f8fafc !important; border-color:#475569; }
        body.dark-mode .custom-table td { color:#cbd5e1 !important; border-color:#334155; }
        body.dark-mode .custom-table tbody tr:nth-child(4n+1) { background:#0f172a !important; }
        body.dark-mode .custom-table tbody tr:nth-child(4n+2) { background:#131e30 !important; }
        body.dark-mode .custom-table tbody tr:nth-child(4n+3) { background:#0f172a !important; }
        body.dark-mode .custom-table tbody tr:nth-child(4n+4) { background:#131e30 !important; }
        body.dark-mode .custom-table tbody tr:hover { background:#1e293b !important; }
        body.dark-mode .task-row[data-overdue="1"] { background:#1a0a0a !important; border-left:3px solid #ef4444; }
        body.dark-mode .modal-content { background:#1e293b; }
        body.dark-mode .form-group label { color:#cbd5e1; }
        body.dark-mode .form-group input,
        body.dark-mode .form-group select,
        body.dark-mode .form-group textarea { background:#0f172a; color:#f8fafc; border-color:#334155; }
        body.dark-mode .form-group input::placeholder,
        body.dark-mode .form-group textarea::placeholder { color:#475569; }
        body.dark-mode .view-data-box { background:#0f172a; color:#f8fafc; border-color:#334155; }
        body.dark-mode .create-btn { background:#3b82f6; }
        body.dark-mode .submit-btn { background:#3b82f6; }
        body.dark-mode .submit-btn:hover { background:#2563eb; }
        body.dark-mode .info-box { background:#1e3a5f; border-color:#3b82f6; color:#93c5fd; }
    </style>
</head>
<body>

<div id="toastBox">
    <i class="fa-solid fa-circle-check"></i>
    <span id="toastMsg">Action Successful!</span>
</div>

<?php
    $activePage    = 'task_manager';
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
    <!-- Top Navbar -->
    <?php include 'topbar.php'; ?>

    <div class="page-body">

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title">
                <h1><i class="fa-solid fa-list-check" style="color:#3b82f6;font-size:22px;margin-right:8px;"></i>Task Manager</h1>
                <p>Create, assign, and manage all tasks from one place.</p>
            </div>
            <button class="create-btn" onclick="openModal('createTaskModal')">
                <i class="fa-solid fa-plus"></i> Create New Task
            </button>
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
            <div class="summary-card overdue-card" onclick="filterOverdue(this)" style="cursor:pointer;" title="Click to filter overdue tasks">
                <div class="summary-icon icon-red"><i class="fa-solid fa-calendar-xmark"></i></div>
                <div class="summary-info">
                    <h3 id="overdueCountDisplay"><?php echo $overdueCount; ?></h3>
                    <p>Overdue <?php if($overdueCount > 0): ?><i class="fa-solid fa-circle" style="color:#ef4444;font-size:7px;margin-left:4px;vertical-align:middle;animation:pulse 1s infinite;"></i><?php endif; ?></p>
                </div>
            </div>
        </div><!-- /summary-grid -->

        <!-- Tabs -->
        <div class="tabs-wrapper">
            <div class="tab-top-line"></div>
            <div class="tabs-container">
                <button class="tab-btn active" onclick="filterTasks('all',this)"><i class="fa-solid fa-list-check"></i> All Tasks</button>
                <button class="tab-btn" onclick="filterTasks('To-Do',this)"><i class="fa-solid fa-clock"></i> To-Do</button>
                <button class="tab-btn" onclick="filterTasks('In-Progress',this)"><i class="fa-solid fa-spinner"></i> In-Progress</button>
                <button class="tab-btn" onclick="filterTasks('Completed',this)"><i class="fa-solid fa-circle-check"></i> Completed</button>
            </div>
        </div>

        <!-- Task Table -->
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
                        <th>Created</th>
                        <th>Due Date</th>
                        <th>Last Edited</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php echo $taskTableRows; ?>
                </tbody>
            </table>
        </div>

    </div><!-- /page-body -->
</div><!-- /main-content -->


<!-- ===== CREATE TASK MODAL (3-Step Wizard) ===== -->
<div id="createTaskModal" class="modal">
    <div class="modal-content" style="max-width:700px;">
        <div class="modal-header">
            <h2><i class="fa-solid fa-plus-circle" style="color:#3b82f6;margin-right:6px;"></i>Create New Task</h2>
            <button type="button" class="close-btn" onclick="closeCreateModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <!-- Step Indicator -->
        <div class="step-wizard" id="stepWizard">
            <div class="step-item active" id="wizStep1">
                <div class="step-circle">1</div>
                <div class="step-label">Basic Info</div>
            </div>
            <div class="step-item" id="wizStep2">
                <div class="step-circle">2</div>
                <div class="step-label">Sub Tasks</div>
            </div>
            <div class="step-item" id="wizStep3">
                <div class="step-circle">3</div>
                <div class="step-label">Review</div>
            </div>
        </div>

        <div class="info-box">
            <i class="fa-solid fa-user-pen"></i>
            Assigned by: <strong style="margin-left:4px;"><?php echo htmlspecialchars($currentUser); ?></strong>
        </div>

        <form action="task_manager.php" method="POST" id="createTaskForm">
            <!-- Hidden field for subtasks JSON -->
            <input type="hidden" name="subtasks_json" id="subtasksJsonInput">

            <!-- ========== STEP 1: Basic Info ========== -->
            <div class="step-panel active" id="panel1">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Task Title <span style="color:#ef4444;">*</span></label>
                        <input type="text" id="c_title" name="title" required placeholder="e.g. Follow up with Acme Corp">
                    </div>
                    <div class="form-group full-width">
                        <label>Description</label>
                        <textarea id="c_description" name="description" rows="2" placeholder="Detailed task description..."></textarea>
                    </div>
                    <div class="form-group full-width">
                        <label>Assign To <span style="color:#ef4444;">*</span></label>
                        <div class="multi-select-wrapper" id="multiSelectWrapper">
                            <div class="multi-select-box" id="multiSelectBox" onclick="toggleMultiDropdown()">
                                <div class="multi-select-tags" id="multiSelectTags">
                                    <span class="multi-placeholder" id="multiPlaceholder">— Select assignees —</span>
                                </div>
                                <i class="fa-solid fa-chevron-down multi-arrow" id="multiArrow"></i>
                            </div>
                            <div class="multi-dropdown" id="multiDropdown">
                                <div class="multi-search-wrap">
                                    <i class="fa-solid fa-magnifying-glass" style="color:#9ca3af;font-size:11px;"></i>
                                    <input type="text" id="multiSearchInput" placeholder="Search user..." oninput="filterMultiOptions(this.value)" onclick="event.stopPropagation()">
                                </div>
                                <div class="multi-options" id="multiOptions">
                                    <?php
                                    foreach ($assigneeList as $u) {
                                        echo "<div class='multi-opt' data-value='{$u['username']}' data-label='" . htmlspecialchars($u['name'] . ' (' . $u['username'] . ')') . "' onclick='toggleMultiOpt(this)'>"
                                           . "<span class='multi-opt-check'><i class='fa-solid fa-check'></i></span>"
                                           . htmlspecialchars($u['name']) . " <small>({$u['username']})</small>"
                                           . "</div>";
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                        <!-- Hidden input stores comma-separated values -->
                        <input type="hidden" name="assigned_to" id="c_assigned_to" required>
                    </div>
                    <div class="form-group">
                        <label>Due Date <span style="color:#ef4444;">*</span></label>
                        <input type="date" id="c_due_date" name="due_date" required>
                    </div>
                    <div class="form-group">
                        <label>Priority</label>
                        <select id="c_priority" name="priority">
                            <option value="Low">Low</option>
                            <option value="Medium" selected>Medium</option>
                            <option value="High">High</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select id="c_status" name="status">
                            <option value="To-Do" selected>To-Do</option>
                            <option value="In-Progress">In-Progress</option>
                            <option value="Completed">Completed</option>
                        </select>
                    </div>
                    <div class="form-group full-width">
                        <label>Assign To Clients</label>
                        <div class="multi-select-wrapper" id="clientMultiSelectWrapper">
                            <div class="multi-select-box" id="clientMultiSelectBox" onclick="toggleClientMultiDropdown()">
                                <div class="multi-select-tags" id="clientMultiSelectTags">
                                    <span class="multi-placeholder" id="clientMultiPlaceholder">— Select clients (optional) —</span>
                                </div>
                                <i class="fa-solid fa-chevron-down multi-arrow" id="clientMultiArrow"></i>
                            </div>
                            <div class="multi-dropdown" id="clientMultiDropdown">
                                <div class="multi-search-wrap">
                                    <i class="fa-solid fa-magnifying-glass" style="color:#9ca3af;font-size:11px;"></i>
                                    <input type="text" id="clientMultiSearchInput" placeholder="Search client..." oninput="filterClientMultiOptions(this.value)" onclick="event.stopPropagation()">
                                </div>
                                <div class="multi-options" id="clientMultiOptions">
                                    <?php
                                    foreach ($clientOptions as $client) {
                                        echo "<div class='multi-opt' data-value='{$client['id']}' data-label='" . htmlspecialchars($client['name']) . "' onclick='toggleClientMultiOpt(this)'>"
                                           . "<span class='multi-opt-check'><i class='fa-solid fa-check'></i></span>"
                                           . htmlspecialchars($client['name'])
                                           . "</div>";
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" name="client_ids" id="c_client_ids">
                    </div>
                </div>
                <div class="step-nav">
                    <button type="button" class="btn-next" onclick="wizNext(1)">
                        Next: Sub Tasks <i class="fa-solid fa-arrow-right" style="margin-left:6px;"></i>
                    </button>
                </div>
            </div>

            <!-- ========== STEP 2: Sub Tasks ========== -->
            <div class="step-panel" id="panel2">
                <div class="subtask-list" id="subtaskList">
                    <!-- subtask rows injected here -->
                </div>
                <button type="button" class="add-subtask-btn" onclick="addSubtask()">
                    <i class="fa-solid fa-plus"></i> Add Sub Task
                </button>
                <div class="step-nav" style="margin-top:20px;">
                    <button type="button" class="btn-prev" onclick="wizGo(1)">
                        <i class="fa-solid fa-arrow-left" style="margin-right:6px;"></i> Back
                    </button>
                    <button type="button" class="btn-next" onclick="wizNext(2)">
                        Next: Review <i class="fa-solid fa-arrow-right" style="margin-left:6px;"></i>
                    </button>
                </div>
            </div>

            <!-- ========== STEP 3: Review ========== -->
            <div class="step-panel" id="panel3">
                <div class="review-grid" id="reviewGrid">
                    <div class="review-item full">
                        <label>Task Title</label>
                        <span id="rev_title">—</span>
                    </div>
                    <div class="review-item full">
                        <label>Description</label>
                        <span id="rev_description">—</span>
                    </div>
                    <div class="review-item">
                        <label>Assign To</label>
                        <span id="rev_assigned_to">—</span>
                    </div>
                    <div class="review-item">
                        <label>Due Date</label>
                        <span id="rev_due_date">—</span>
                    </div>
                    <div class="review-item">
                        <label>Priority</label>
                        <span id="rev_priority">—</span>
                    </div>
                    <div class="review-item">
                        <label>Status</label>
                        <span id="rev_status">—</span>
                    </div>
                    <div class="review-item full">
                        <label>Assigned Clients</label>
                        <span id="rev_clients">—</span>
                    </div>
                </div>
                <div class="review-subtasks" id="reviewSubtasksBox" style="display:none;">
                    <label>Sub Tasks</label>
                    <div id="reviewSubtaskItems"></div>
                </div>
                <div class="step-nav" style="margin-top:20px;">
                    <button type="button" class="btn-prev" onclick="wizGo(2)">
                        <i class="fa-solid fa-arrow-left" style="margin-right:6px;"></i> Back
                    </button>
                    <button type="submit" name="create_task" class="btn-next" style="background:#10b981;" onclick="prepareSubtasksJson()">
                        <i class="fa-solid fa-floppy-disk" style="margin-right:6px;"></i> Save Task
                    </button>
                </div>
            </div>

        </form>
    </div>
</div>

<!-- ===== EDIT TASK MODAL ===== -->
<div id="editTaskModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fa-solid fa-pen" style="color:#22c55e;margin-right:6px;"></i>Edit Task</h2>
            <button type="button" class="close-btn" onclick="closeModal('editTaskModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form action="task_manager.php" method="POST">
            <input type="hidden" name="task_id" id="edit_task_id">
            <?php if ($isAgent): ?>
            <!-- Agent: শুধু Status পরিবর্তন করতে পারবে -->
            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 14px;margin-bottom:14px;font-size:12px;color:#1d4ed8;font-weight:600;display:flex;align-items:center;gap:8px;">
                <i class="fa-solid fa-circle-info"></i>
                As an agent, you can only update the task <strong>Status</strong>.
            </div>
            <div class="form-group" style="margin-bottom:14px;">
                <label>Update Status</label>
                <select name="status" id="edit_status">
                    <option value="To-Do">To-Do</option>
                    <option value="In-Progress">In-Progress</option>
                    <option value="Completed">Completed</option>
                </select>
            </div>
            <?php else: ?>
            <div class="form-grid">
                <div class="form-group full-width"><label>Task Title</label><input type="text" name="title" id="edit_title" required></div>
                <div class="form-group full-width"><label>Description</label><textarea name="description" id="edit_description" rows="2"></textarea></div>
                <div class="form-group full-width">
                    <label>Assigned To</label>
                    <div class="multi-select-wrapper" id="editMultiSelectWrapper">
                        <div class="multi-select-box" id="editMultiSelectBox" onclick="toggleEditMultiDropdown()">
                            <div class="multi-select-tags" id="editMultiSelectTags">
                                <span class="multi-placeholder" id="editMultiPlaceholder">— Select assignees —</span>
                            </div>
                            <i class="fa-solid fa-chevron-down multi-arrow" id="editMultiArrow"></i>
                        </div>
                        <div class="multi-dropdown" id="editMultiDropdown">
                            <div class="multi-search-wrap">
                                <i class="fa-solid fa-magnifying-glass" style="color:#9ca3af;font-size:11px;"></i>
                                <input type="text" id="editMultiSearchInput" placeholder="Search user..." oninput="filterEditMultiOptions(this.value)" onclick="event.stopPropagation()">
                            </div>
                            <div class="multi-options" id="editMultiOptions">
                                <?php
                                foreach ($assigneeList as $u) {
                                    echo "<div class='multi-opt' data-value='{$u['username']}' data-label='" . htmlspecialchars($u['name'] . ' (' . $u['username'] . ')') . "' onclick='toggleEditMultiOpt(this)'>"
                                       . "<span class='multi-opt-check'><i class='fa-solid fa-check'></i></span>"
                                       . htmlspecialchars($u['name']) . " <small>({$u['username']})</small>"
                                       . "</div>";
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="assigned_to" id="edit_assigned_to">
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
                <div class="form-group full-width">
                    <label>Assigned Clients</label>
                    <div class="multi-select-wrapper" id="editClientMultiSelectWrapper">
                        <div class="multi-select-box" id="editClientMultiSelectBox" onclick="toggleEditClientMultiDropdown()">
                            <div class="multi-select-tags" id="editClientMultiSelectTags">
                                <span class="multi-placeholder" id="editClientMultiPlaceholder">— Select clients (optional) —</span>
                            </div>
                            <i class="fa-solid fa-chevron-down multi-arrow" id="editClientMultiArrow"></i>
                        </div>
                        <div class="multi-dropdown" id="editClientMultiDropdown">
                            <div class="multi-search-wrap">
                                <i class="fa-solid fa-magnifying-glass" style="color:#9ca3af;font-size:11px;"></i>
                                <input type="text" id="editClientMultiSearchInput" placeholder="Search client..." oninput="filterEditClientMultiOptions(this.value)" onclick="event.stopPropagation()">
                            </div>
                            <div class="multi-options" id="editClientMultiOptions">
                                <?php
                                foreach ($clientOptions as $client) {
                                    echo "<div class='multi-opt' data-value='{$client['id']}' data-label='" . htmlspecialchars($client['name']) . "' onclick='toggleEditClientMultiOpt(this)'>"
                                       . "<span class='multi-opt-check'><i class='fa-solid fa-check'></i></span>"
                                       . htmlspecialchars($client['name'])
                                       . "</div>";
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="client_ids" id="edit_client_ids">
                </div>
            </div>

            <!-- Subtask Section in Edit Modal -->
            <div style="margin-top:12px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                    <div style="font-size:12px;font-weight:700;color:#374151;" id="editSubtaskLabel">
                        <i class="fa-solid fa-list-ul" style="color:#3b82f6;margin-right:6px;"></i>Sub Tasks
                        <span id="editSubtaskCount" style="font-size:10px;font-weight:600;color:#6b7280;margin-left:6px;"></span>
                    </div>
                    <button type="button" class="add-subtask-btn" style="width:auto;padding:5px 12px;font-size:11px;" onclick="addEditSubtask()">
                        <i class="fa-solid fa-plus"></i> Add Sub Task
                    </button>
                </div>
                <div id="editSubtaskLoading" style="font-size:12px;color:#9ca3af;padding:6px 0;text-align:center;display:none;">
                    <i class="fa-solid fa-spinner fa-spin" style="margin-right:4px;"></i> Loading subtasks...
                </div>
                <div class="subtask-list" id="editSubtaskList" style="max-height:180px;overflow-y:auto;"></div>
            </div>

            <div style="display:flex;gap:8px;margin-top:14px;">
                <button type="button" class="submit-btn" style="background:#3b82f6;flex:1;" onclick="saveEditSubtasks()">
                    <i class="fa-solid fa-floppy-disk" style="margin-right:6px;"></i>Save Sub Tasks
                </button>
                <button type="submit" name="update_task" class="submit-btn" style="background-color:#22c55e;flex:1;">
                    <i class="fa-solid fa-floppy-disk" style="margin-right:6px;"></i>Update Task
                </button>
            </div>
            <?php endif; ?>

            <?php if ($isAgent): ?>
            <button type="submit" name="update_task" class="submit-btn" style="background-color:#22c55e;margin-top:4px;">
                <i class="fa-solid fa-floppy-disk" style="margin-right:6px;"></i>Update Status
            </button>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- ===== VIEW TASK MODAL ===== -->
<div id="viewTaskModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fa-solid fa-eye" style="color:#60a5fa;margin-right:6px;"></i>Task Details</h2>
            <button type="button" class="close-btn" onclick="closeModal('viewTaskModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="form-grid">
            <div class="form-group full-width"><label>Task Title</label><div class="view-data-box" id="view_title">-</div></div>
            <div class="form-group full-width"><label>Description</label><div class="view-data-box" id="view_description" style="min-height:50px;align-items:flex-start;padding-top:8px;">-</div></div>
            <div class="form-group"><label>Assigned To</label><div class="view-data-box" id="view_assigned_to">-</div></div>
            <div class="form-group"><label>Assigned By</label><div class="view-data-box" id="view_assigned_by">-</div></div>
            <div class="form-group"><label>Priority</label><div class="view-data-box" id="view_priority">-</div></div>
            <div class="form-group"><label>Status</label><div class="view-data-box" id="view_status">-</div></div>
            <div class="form-group full-width"><label>Due Date</label><div class="view-data-box" id="view_due_date">-</div></div>
            <div class="form-group full-width"><label><i class="fa-solid fa-users" style="color:#8b5cf6;margin-right:4px;"></i>Assigned Clients</label><div class="view-data-box" id="view_assigned_clients" style="flex-wrap:wrap;gap:5px;align-items:flex-start;padding-top:7px;min-height:34px;">-</div></div>
            <div class="form-group">
                <label><i class="fa-solid fa-calendar-plus" style="color:#3b82f6;margin-right:4px;"></i>Created At</label>
                <div class="view-data-box" id="view_created_at">-</div>
            </div>
            <div class="form-group">
                <label><i class="fa-solid fa-pen-to-square" style="color:#7c3aed;margin-right:4px;"></i>Last Edited</label>
                <div class="view-data-box" id="view_updated_at">-</div>
            </div>
        </div>
        <!-- Subtasks in view modal -->
        <div id="viewSubtasksSection" style="display:none; margin-top:4px;">
            <div style="font-size:12px;font-weight:700;color:#374151;margin-bottom:8px;">
                <i class="fa-solid fa-list-ul" style="color:#3b82f6;margin-right:6px;"></i>Sub Tasks
                <span id="viewSubtaskProgress" style="font-size:11px;font-weight:600;color:#6b7280;margin-left:8px;"></span>
            </div>
            <div id="viewSubtasksList" style="display:flex;flex-direction:column;gap:6px;"></div>
        </div>
        <div style="display:flex;gap:10px;margin-top:10px;">
            <button class="submit-btn" onclick="switchToEdit()" style="background:#22c55e;">
                <i class="fa-solid fa-pen-to-square" style="margin-right:6px;"></i>Edit Task
            </button>
            <button class="submit-btn" onclick="closeModal('viewTaskModal')" style="background:#6b7280;">Close</button>
        </div>
    </div>
</div>

<script>
    // ---- Helpers ----
    function openModal(id)  { document.getElementById(id).style.display = 'flex'; }
    function closeModal(id) { document.getElementById(id).style.display = 'none'; }

    // ---- Set min date on due date inputs (today, no past dates) ----
    (function() {
        var today = new Date().toISOString().split('T')[0];
        var createDd = document.getElementById('c_due_date');
        var editDd   = document.getElementById('edit_due_date');
        if (createDd) createDd.setAttribute('min', today);
        if (editDd)   editDd.setAttribute('min', today);
    })();

    // ================================================================
    //  MULTI-SELECT — Create Modal
    // ================================================================
    var _createSelected = [];

    function toggleMultiDropdown() {
        var dd  = document.getElementById('multiDropdown');
        var box = document.getElementById('multiSelectBox');
        var arr = document.getElementById('multiArrow');
        var isOpen = dd.classList.contains('open');
        // Close edit dropdown if open (null-safe — agent modal এ নাও থাকতে পারে)
        var edd = document.getElementById('editMultiDropdown');
        var ebox = document.getElementById('editMultiSelectBox');
        var earr = document.getElementById('editMultiArrow');
        if (edd)  edd.classList.remove('open');
        if (ebox) ebox.classList.remove('open');
        if (earr) earr.classList.remove('open');
        dd.classList.toggle('open', !isOpen);
        box.classList.toggle('open', !isOpen);
        arr.classList.toggle('open', !isOpen);
    }

    function toggleMultiOpt(el) {
        var val   = el.dataset.value;
        var label = el.dataset.label;
        var idx   = _createSelected.findIndex(function(s) { return s.value === val; });
        if (idx > -1) {
            _createSelected.splice(idx, 1);
            el.classList.remove('selected');
        } else {
            _createSelected.push({ value: val, label: label });
            el.classList.add('selected');
        }
        renderMultiTags('multiSelectTags', 'multiPlaceholder', _createSelected, 'c_assigned_to', removeCreateTag);
    }

    function removeCreateTag(val) {
        _createSelected = _createSelected.filter(function(s) { return s.value !== val; });
        var opt = document.querySelector('#multiOptions .multi-opt[data-value="' + val + '"]');
        if (opt) opt.classList.remove('selected');
        renderMultiTags('multiSelectTags', 'multiPlaceholder', _createSelected, 'c_assigned_to', removeCreateTag);
    }

    function filterMultiOptions(q) {
        document.querySelectorAll('#multiOptions .multi-opt').forEach(function(opt) {
            opt.style.display = opt.dataset.label.toLowerCase().includes(q.toLowerCase()) ? '' : 'none';
        });
    }

    // ================================================================
    //  MULTI-SELECT — Edit Modal
    // ================================================================
    var _editSelected = [];

    function toggleEditMultiDropdown() {
        var dd  = document.getElementById('editMultiDropdown');
        var box = document.getElementById('editMultiSelectBox');
        var arr = document.getElementById('editMultiArrow');
        if (!dd || !box || !arr) return;
        var isOpen = dd.classList.contains('open');
        // Close create dropdown if open
        var cdd  = document.getElementById('multiDropdown');
        var cbox = document.getElementById('multiSelectBox');
        var carr = document.getElementById('multiArrow');
        if (cdd)  cdd.classList.remove('open');
        if (cbox) cbox.classList.remove('open');
        if (carr) carr.classList.remove('open');
        dd.classList.toggle('open', !isOpen);
        box.classList.toggle('open', !isOpen);
        arr.classList.toggle('open', !isOpen);
    }

    function toggleEditMultiOpt(el) {
        var val   = el.dataset.value;
        var label = el.dataset.label;
        var idx   = _editSelected.findIndex(function(s) { return s.value === val; });
        if (idx > -1) {
            _editSelected.splice(idx, 1);
            el.classList.remove('selected');
        } else {
            _editSelected.push({ value: val, label: label });
            el.classList.add('selected');
        }
        renderMultiTags('editMultiSelectTags', 'editMultiPlaceholder', _editSelected, 'edit_assigned_to', removeEditTag);
    }

    function removeEditTag(val) {
        _editSelected = _editSelected.filter(function(s) { return s.value !== val; });
        var opt = document.querySelector('#editMultiOptions .multi-opt[data-value="' + val + '"]');
        if (opt) opt.classList.remove('selected');
        renderMultiTags('editMultiSelectTags', 'editMultiPlaceholder', _editSelected, 'edit_assigned_to', removeEditTag);
    }

    function filterEditMultiOptions(q) {
        document.querySelectorAll('#editMultiOptions .multi-opt').forEach(function(opt) {
            opt.style.display = opt.dataset.label.toLowerCase().includes(q.toLowerCase()) ? '' : 'none';
        });
    }

    // ================================================================
    //  Shared helper: render tags + update hidden input
    // ================================================================
    function renderMultiTags(tagsContainerId, placeholderId, selected, hiddenInputId, removeFn) {
        var container  = document.getElementById(tagsContainerId);
        var placeholder = document.getElementById(placeholderId);
        // Clear old tags
        container.querySelectorAll('.multi-tag').forEach(function(t) { t.remove(); });
        if (selected.length === 0) {
            placeholder.style.display = '';
        } else {
            placeholder.style.display = 'none';
            selected.forEach(function(s) {
                var tag = document.createElement('span');
                tag.className = 'multi-tag';
                tag.innerHTML = s.value + '<button type="button" class="remove-tag" onclick="(function(e){e.stopPropagation();' + removeFn.name + '(\'' + s.value + '\');})(event)"><i class="fa-solid fa-xmark"></i></button>';
                container.insertBefore(tag, placeholder);
            });
        }
        // Update hidden input
        document.getElementById(hiddenInputId).value = selected.map(function(s) { return s.value; }).join(',');
    }

    // Close dropdowns when clicking outside (null-safe — agent modal এ কিছু element নাও থাকতে পারে)
    document.addEventListener('click', function(e) {
        var msw = document.getElementById('multiSelectWrapper');
        if (msw && !msw.contains(e.target)) {
            var md = document.getElementById('multiDropdown');
            var mb = document.getElementById('multiSelectBox');
            var ma = document.getElementById('multiArrow');
            if (md) md.classList.remove('open');
            if (mb) mb.classList.remove('open');
            if (ma) ma.classList.remove('open');
        }
        var emsw = document.getElementById('editMultiSelectWrapper');
        if (emsw && !emsw.contains(e.target)) {
            var emd = document.getElementById('editMultiDropdown');
            var emb = document.getElementById('editMultiSelectBox');
            var ema = document.getElementById('editMultiArrow');
            if (emd) emd.classList.remove('open');
            if (emb) emb.classList.remove('open');
            if (ema) ema.classList.remove('open');
        }
        var cmsw = document.getElementById('clientMultiSelectWrapper');
        if (cmsw && !cmsw.contains(e.target)) {
            var cmd = document.getElementById('clientMultiDropdown');
            var cmb = document.getElementById('clientMultiSelectBox');
            var cma = document.getElementById('clientMultiArrow');
            if (cmd) cmd.classList.remove('open');
            if (cmb) cmb.classList.remove('open');
            if (cma) cma.classList.remove('open');
        }
        var ecmsw = document.getElementById('editClientMultiSelectWrapper');
        if (ecmsw && !ecmsw.contains(e.target)) {
            var ecmd = document.getElementById('editClientMultiDropdown');
            var ecmb = document.getElementById('editClientMultiSelectBox');
            var ecma = document.getElementById('editClientMultiArrow');
            if (ecmd) ecmd.classList.remove('open');
            if (ecmb) ecmb.classList.remove('open');
            if (ecma) ecma.classList.remove('open');
        }
    });

    // ================================================================
    //  CLIENT MULTI-SELECT (CREATE MODAL)
    // ================================================================
    var _clientSelected = [];

    function toggleClientMultiDropdown() {
        var dd = document.getElementById('clientMultiDropdown');
        var box = document.getElementById('clientMultiSelectBox');
        var arrow = document.getElementById('clientMultiArrow');
        dd.classList.toggle('open');
        box.classList.toggle('open');
        arrow.classList.toggle('open');
    }

    function toggleClientMultiOpt(opt) {
        var val = opt.dataset.value;
        var label = opt.dataset.label;
        var found = _clientSelected.findIndex(function(s) { return s.value === val; });
        if (found >= 0) {
            _clientSelected.splice(found, 1);
            opt.classList.remove('selected');
        } else {
            _clientSelected.push({ value: val, label: label });
            opt.classList.add('selected');
        }
        renderMultiTags('clientMultiSelectTags', 'clientMultiPlaceholder', _clientSelected, 'c_client_ids', removeClientTag);
    }

    function removeClientTag(val) {
        var idx = _clientSelected.findIndex(function(s) { return s.value === val; });
        if (idx >= 0) _clientSelected.splice(idx, 1);
        var opt = document.querySelector('#clientMultiOptions .multi-opt[data-value="' + val + '"]');
        if (opt) opt.classList.remove('selected');
        renderMultiTags('clientMultiSelectTags', 'clientMultiPlaceholder', _clientSelected, 'c_client_ids', removeClientTag);
    }

    function filterClientMultiOptions(q) {
        document.querySelectorAll('#clientMultiOptions .multi-opt').forEach(function(opt) {
            opt.style.display = opt.dataset.label.toLowerCase().includes(q.toLowerCase()) ? '' : 'none';
        });
    }

    // ================================================================
    //  CLIENT MULTI-SELECT (EDIT MODAL)
    // ================================================================
    var _editClientSelected = [];

    function toggleEditClientMultiDropdown() {
        var dd = document.getElementById('editClientMultiDropdown');
        var box = document.getElementById('editClientMultiSelectBox');
        var arrow = document.getElementById('editClientMultiArrow');
        dd.classList.toggle('open');
        box.classList.toggle('open');
        arrow.classList.toggle('open');
    }

    function toggleEditClientMultiOpt(opt) {
        var val = opt.dataset.value;
        var label = opt.dataset.label;
        var found = _editClientSelected.findIndex(function(s) { return s.value === val; });
        if (found >= 0) {
            _editClientSelected.splice(found, 1);
            opt.classList.remove('selected');
        } else {
            _editClientSelected.push({ value: val, label: label });
            opt.classList.add('selected');
        }
        renderMultiTags('editClientMultiSelectTags', 'editClientMultiPlaceholder', _editClientSelected, 'edit_client_ids', removeEditClientTag);
    }

    function removeEditClientTag(val) {
        var idx = _editClientSelected.findIndex(function(s) { return s.value === val; });
        if (idx >= 0) _editClientSelected.splice(idx, 1);
        var opt = document.querySelector('#editClientMultiOptions .multi-opt[data-value="' + val + '"]');
        if (opt) opt.classList.remove('selected');
        renderMultiTags('editClientMultiSelectTags', 'editClientMultiPlaceholder', _editClientSelected, 'edit_client_ids', removeEditClientTag);
    }

    function filterEditClientMultiOptions(q) {
        document.querySelectorAll('#editClientMultiOptions .multi-opt').forEach(function(opt) {
            opt.style.display = opt.dataset.label.toLowerCase().includes(q.toLowerCase()) ? '' : 'none';
        });
    }

    // ---- Close & Reset Create Modal ----
    function closeCreateModal() {
        closeModal('createTaskModal');
        wizReset();
    }

    // ================================================================
    //  STEP WIZARD
    // ================================================================
    var currentStep = 1;

    function wizGo(step) {
        // Hide all panels
        document.querySelectorAll('.step-panel').forEach(p => p.classList.remove('active'));
        // Show target
        document.getElementById('panel' + step).classList.add('active');
        // Update circles
        for (var i = 1; i <= 3; i++) {
            var item = document.getElementById('wizStep' + i);
            item.classList.remove('active', 'completed');
            if (i < step)  item.classList.add('completed');
            if (i === step) item.classList.add('active');
        }
        // Swap check icon for completed steps
        document.querySelectorAll('.step-item.completed .step-circle').forEach(c => {
            if (!c.querySelector('i')) c.innerHTML = '<i class="fa-solid fa-check" style="font-size:13px;"></i>';
        });
        currentStep = step;
    }

    function wizNext(from) {
        if (from === 1) {
            // Validate step 1
            var title    = document.getElementById('c_title').value.trim();
            var assigned = document.getElementById('c_assigned_to').value;
            var dueDate  = document.getElementById('c_due_date').value;
            if (!title) { showFieldError('c_title', 'Task title is required!'); return; }
            if (!assigned || assigned === '') {
                Swal.fire({ toast:true, position:'top-end', icon:'warning', title:'Please assign the task to at least one user!', showConfirmButton:false, timer:2500, timerProgressBar:true });
                return;
            }
            if (!dueDate) { showFieldError('c_due_date', 'Due date is required!'); return; }
            wizGo(2);
        } else if (from === 2) {
            buildReview();
            wizGo(3);
        }
    }

    function showFieldError(fieldId, msg) {
        var el = document.getElementById(fieldId);
        el.style.borderColor = '#ef4444';
        el.style.boxShadow   = '0 0 0 3px rgba(239,68,68,0.15)';
        el.focus();
        setTimeout(() => { el.style.borderColor=''; el.style.boxShadow=''; }, 2500);
        Swal.fire({ toast:true, position:'top-end', icon:'warning', title:msg, showConfirmButton:false, timer:2500, timerProgressBar:true });
    }

    function wizReset() {
        wizGo(1);
        document.getElementById('createTaskForm').reset();
        document.getElementById('subtaskList').innerHTML = '';
        currentStep = 1;
        // Reset multi-select for users
        _createSelected = [];
        document.querySelectorAll('#multiOptions .multi-opt').forEach(function(opt) { opt.classList.remove('selected'); });
        renderMultiTags('multiSelectTags', 'multiPlaceholder', _createSelected, 'c_assigned_to', removeCreateTag);
        document.getElementById('multiSearchInput').value = '';
        document.querySelectorAll('#multiOptions .multi-opt').forEach(function(opt) { opt.style.display = ''; });
        // Reset multi-select for clients
        _clientSelected = [];
        document.querySelectorAll('#clientMultiOptions .multi-opt').forEach(function(opt) { opt.classList.remove('selected'); });
        renderMultiTags('clientMultiSelectTags', 'clientMultiPlaceholder', _clientSelected, 'c_client_ids', removeClientTag);
        document.getElementById('clientMultiSearchInput').value = '';
        document.querySelectorAll('#clientMultiOptions .multi-opt').forEach(function(opt) { opt.style.display = ''; });
    }

    // ================================================================
    //  SUBTASKS
    // ================================================================
    var subtaskCounter = 0;

    function addSubtask(text) {
        subtaskCounter++;
        var id  = 'st_' + subtaskCounter;
        var val = text || '';
        var row = document.createElement('div');
        row.className = 'subtask-row';
        row.id = 'strow_' + subtaskCounter;
        row.innerHTML =
            '<i class="fa-solid fa-grip-vertical" style="color:#d1d5db;font-size:12px;flex-shrink:0;"></i>' +
            '<input type="text" id="' + id + '" placeholder="Subtask description..." value="' + val + '" maxlength="200">' +
            '<button type="button" class="del-subtask" onclick="removeSubtask(\'strow_' + subtaskCounter + '\')" title="Remove">' +
            '<i class="fa-solid fa-xmark"></i></button>';
        document.getElementById('subtaskList').appendChild(row);
        document.getElementById(id).focus();
    }

    function removeSubtask(rowId) {
        var el = document.getElementById(rowId);
        if (el) el.remove();
    }

    function getSubtasks() {
        var rows = document.querySelectorAll('#subtaskList .subtask-row input[type="text"]');
        var list = [];
        rows.forEach(function(inp) {
            var val = inp.value.trim();
            if (val) list.push({ title: val, done: false });
        });
        return list;
    }

    function prepareSubtasksJson() {
        document.getElementById('subtasksJsonInput').value = JSON.stringify(getSubtasks());
    }

    // ================================================================
    //  REVIEW (Step 3 preview)
    // ================================================================
    function buildReview() {
        // Text values
        document.getElementById('rev_title').innerText       = document.getElementById('c_title').value        || '—';
        document.getElementById('rev_description').innerText = document.getElementById('c_description').value  || '—';
        document.getElementById('rev_priority').innerText    = document.getElementById('c_priority').value     || '—';
        document.getElementById('rev_status').innerText      = document.getElementById('c_status').value       || '—';

        // Assigned To — show selected names
        var assignedNames = _createSelected.map(function(s) { return s.label.split(' (')[0]; }).join(', ');
        document.getElementById('rev_assigned_to').innerText = assignedNames || '—';

        // Due date — format nicely
        var dd = document.getElementById('c_due_date').value;
        if (dd) {
            var parts = dd.split('-');
            var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            document.getElementById('rev_due_date').innerText = months[parseInt(parts[1])-1] + ' ' + parts[2] + ', ' + parts[0];
        } else {
            document.getElementById('rev_due_date').innerText = '—';
        }

        // Assigned Clients
        var clientNames = _clientSelected.map(function(s) { return s.label; }).join(', ');
        document.getElementById('rev_clients').innerText = clientNames || 'None selected';

        // Subtasks
        var subtasks = getSubtasks();
        var box  = document.getElementById('reviewSubtasksBox');
        var list = document.getElementById('reviewSubtaskItems');
        list.innerHTML = '';
        if (subtasks.length > 0) {
            box.style.display = 'block';
            subtasks.forEach(function(st) {
                var item = document.createElement('div');
                item.className = 'review-subtask-item';
                item.innerHTML = '<i class="fa-regular fa-circle"></i> ' + st.title;
                list.appendChild(item);
            });
        } else {
            box.style.display = 'none';
        }
    }

    // ---- Filter ----
    function filterTasks(status, btn) {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.querySelectorAll('.task-row').forEach(r => {
            r.style.display = (status === 'all' || r.dataset.status === status) ? '' : 'none';
        });
    }

    function filterOverdue(card) {
        // Deactivate all tab buttons
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        var count = 0;
        document.querySelectorAll('.task-row').forEach(function(r) {
            if (r.dataset.overdue === '1') { r.style.display = ''; count++; }
            else { r.style.display = 'none'; }
        });
        if (count === 0) {
            Swal.fire({ toast:true, position:'top-end', icon:'info', title:'No overdue tasks found!', showConfirmButton:false, timer:2000 });
            // Show all back
            document.querySelectorAll('.task-row').forEach(r => r.style.display = '');
            document.querySelector('.tab-btn').classList.add('active');
        }
    }

    // ---- View ----
    let _currentTask = null;
    function openViewModal(task) {
        _currentTask = task;
        document.getElementById('view_title').innerText       = task.title       || 'N/A';
        document.getElementById('view_description').innerText = task.description || 'No description.';
        document.getElementById('view_assigned_to').innerText = task.assigned_to || 'Unassigned';
        document.getElementById('view_assigned_by').innerText = task.assigned_by || '—';
        document.getElementById('view_priority').innerText    = task.priority    || 'Medium';
        document.getElementById('view_status').innerText      = task.status      || 'To-Do';
        document.getElementById('view_due_date').innerText    = task.due_date    || 'N/A';

        // Created At
        var caEl = document.getElementById('view_created_at');
        if (task.created_at) {
            var cd = new Date(task.created_at.replace(' ', 'T'));
            caEl.innerHTML = '<strong>' + cd.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) + '</strong>'
                           + ' <span style="color:#9ca3af;font-size:11px;">' + cd.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'}) + '</span>';
        } else { caEl.innerText = '—'; }

        // Last Edited
        var ueEl = document.getElementById('view_updated_at');
        if (task.updated_at && task.updated_at !== '0000-00-00 00:00:00' && task.updated_at !== null) {
            var ud = new Date(task.updated_at.replace(' ', 'T'));
            ueEl.innerHTML = '<strong style="color:#7c3aed;">' + ud.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) + '</strong>'
                           + ' <span style="color:#9ca3af;font-size:11px;">' + ud.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'}) + '</span>';
        } else {
            ueEl.innerHTML = '<span style="color:#9ca3af;font-style:italic;">Not edited yet</span>';
        }

        // Assigned Clients
        var clientsEl = document.getElementById('view_assigned_clients');
        clientsEl.innerHTML = '';
        if (task.client_ids && task.client_ids !== '') {
            var clientIdArr = task.client_ids.split(',').map(function(s) { return s.trim(); }).filter(Boolean);
            if (clientIdArr.length > 0) {
                // Build a lookup map from the DOM options in editClientMultiOptions
                clientIdArr.forEach(function(cid) {
                    var opt = document.querySelector('#editClientMultiOptions .multi-opt[data-value="' + cid + '"]');
                    var label = opt ? opt.dataset.label : ('Client #' + cid);
                    var badge = document.createElement('span');
                    badge.style.cssText = 'display:inline-flex;align-items:center;background:#ede9fe;color:#7c3aed;font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px;';
                    badge.innerHTML = '<i class="fa-solid fa-user" style="margin-right:5px;font-size:10px;"></i>' + label;
                    clientsEl.appendChild(badge);
                });
            } else {
                clientsEl.innerText = '—';
            }
        } else {
            clientsEl.innerText = '—';
        }

        // Fetch subtasks via AJAX
        var sec  = document.getElementById('viewSubtasksSection');
        var list = document.getElementById('viewSubtasksList');
        var prog = document.getElementById('viewSubtaskProgress');
        sec.style.display  = 'none';
        list.innerHTML     = '<div style="font-size:12px;color:#9ca3af;padding:6px 0;">Loading...</div>';

        fetch('task_manager.php?get_subtasks=1&task_id=' + encodeURIComponent(task.id))
            .then(r => r.json())
            .then(function(data) {
                list.innerHTML = '';
                if (data && data.length > 0) {
                    sec.style.display = 'block';
                    var done = data.filter(s => s.is_done == 1).length;
                    prog.innerText = done + '/' + data.length + ' done';
                    data.forEach(function(st) {
                        var item = document.createElement('div');
                        item.style.cssText = 'display:flex;align-items:center;gap:10px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:9px 12px;';
                        var checked = st.is_done == 1;
                        item.innerHTML =
                            '<input type="checkbox" ' + (checked ? 'checked' : '') +
                            ' style="width:15px;height:15px;accent-color:#3b82f6;flex-shrink:0;cursor:pointer;" ' +
                            'onchange="toggleSubtask(' + st.id + ', this, ' + task.id + ')">' +
                            '<span style="font-size:13px;font-weight:500;color:#374151;' + (checked ? 'text-decoration:line-through;color:#9ca3af;' : '') + '" id="stlabel_' + st.id + '">' +
                            st.title + '</span>';
                        list.appendChild(item);
                    });
                } else {
                    sec.style.display = 'none';
                }
            })
            .catch(function() { sec.style.display = 'none'; });

        openModal('viewTaskModal');
    }

    function toggleSubtask(stId, checkbox, taskId) {
        var isDone = checkbox.checked ? 1 : 0;
        var label  = document.getElementById('stlabel_' + stId);
        if (label) {
            label.style.textDecoration = isDone ? 'line-through' : 'none';
            label.style.color = isDone ? '#9ca3af' : '#374151';
        }
        // Update progress count
        fetch('task_manager.php?toggle_subtask=1&st_id=' + stId + '&done=' + isDone)
            .then(r => r.json())
            .then(function(data) {
                if (data && data.total !== undefined) {
                    document.getElementById('viewSubtaskProgress').innerText = data.done + '/' + data.total + ' done';
                }
            });
    }
    function switchToEdit() {
        closeModal('viewTaskModal');
        if (_currentTask) openEditModal(_currentTask);
    }

    // ---- Edit ----
    var _editTaskId = null;
    var _editSubtaskCounter = 0;

    function openEditModal(task) {
        _editTaskId = task.id;
        document.getElementById('edit_task_id').value = task.id;
        document.getElementById('edit_status').value  = task.status || 'To-Do';

        <?php if (!$isAgent): ?>
        document.getElementById('edit_title').value       = task.title       || '';
        document.getElementById('edit_description').value = task.description || '';
        document.getElementById('edit_priority').value    = task.priority    || 'Medium';
        document.getElementById('edit_due_date').value    = task.due_date    || '';

        // Pre-populate multi-select for edit
        _editSelected = [];
        document.querySelectorAll('#editMultiOptions .multi-opt').forEach(function(opt) {
            opt.classList.remove('selected');
        });
        if (task.assigned_to && task.assigned_to !== 'Unassigned') {
            var parts = task.assigned_to.split(',');
            parts.forEach(function(v) {
                v = v.trim();
                var opt = document.querySelector('#editMultiOptions .multi-opt[data-value="' + v + '"]');
                if (opt) {
                    opt.classList.add('selected');
                    _editSelected.push({ value: v, label: opt.dataset.label });
                } else if (v) {
                    _editSelected.push({ value: v, label: v });
                }
            });
        }
        renderMultiTags('editMultiSelectTags', 'editMultiPlaceholder', _editSelected, 'edit_assigned_to', removeEditTag);

        // Pre-populate clients multi-select for edit
        _editClientSelected = [];
        document.querySelectorAll('#editClientMultiOptions .multi-opt').forEach(function(opt) {
            opt.classList.remove('selected');
        });
        if (task.client_ids && task.client_ids !== '') {
            var clientParts = task.client_ids.split(',');
            clientParts.forEach(function(cid) {
                cid = cid.trim();
                var opt = document.querySelector('#editClientMultiOptions .multi-opt[data-value="' + cid + '"]');
                if (opt) {
                    opt.classList.add('selected');
                    _editClientSelected.push({ value: cid, label: opt.dataset.label });
                }
            });
        }
        renderMultiTags('editClientMultiSelectTags', 'editClientMultiPlaceholder', _editClientSelected, 'edit_client_ids', removeEditClientTag);

        // Load subtasks
        loadEditSubtasks(task.id);
        <?php endif; // end !$isAgent block ?>

        openModal('editTaskModal');
    }

    function loadEditSubtasks(taskId) {
        var list    = document.getElementById('editSubtaskList');
        var loading = document.getElementById('editSubtaskLoading');
        var countEl = document.getElementById('editSubtaskCount');
        list.innerHTML = '';
        loading.style.display = 'block';
        fetch('task_manager.php?get_subtasks=1&task_id=' + encodeURIComponent(taskId))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                loading.style.display = 'none';
                list.innerHTML = '';
                _editSubtaskCounter = 0;
                if (data && data.length > 0) {
                    countEl.innerText = '(' + data.length + ')';
                    data.forEach(function(st) {
                        appendEditSubtaskRow(st.title, st.id, st.is_done == 1);
                    });
                } else {
                    countEl.innerText = '';
                }
            })
            .catch(function() { loading.style.display = 'none'; });
    }

    function appendEditSubtaskRow(title, dbId, isDone) {
        _editSubtaskCounter++;
        var rowId = 'est_row_' + _editSubtaskCounter;
        var row = document.createElement('div');
        row.className = 'subtask-row';
        row.id = rowId;
        row.dataset.dbId = dbId || '';
        row.dataset.done = isDone ? '1' : '0';
        row.innerHTML =
            '<i class="fa-solid fa-grip-vertical" style="color:#d1d5db;font-size:12px;flex-shrink:0;"></i>' +
            (isDone ? '<i class="fa-solid fa-circle-check" style="color:#10b981;font-size:13px;flex-shrink:0;" title="Completed"></i>' : '') +
            '<input type="text" placeholder="Subtask description..." value="' + (title || '').replace(/"/g,'&quot;') + '" maxlength="200" style="' + (isDone ? 'text-decoration:line-through;color:#9ca3af;' : '') + '">' +
            '<button type="button" class="del-subtask" onclick="removeEditSubtaskRow(\'' + rowId + '\')" title="Remove"><i class="fa-solid fa-xmark"></i></button>';
        document.getElementById('editSubtaskList').appendChild(row);
    }

    function addEditSubtask() {
        appendEditSubtaskRow('', null, false);
        // focus last input
        var inputs = document.querySelectorAll('#editSubtaskList .subtask-row input[type="text"]');
        if (inputs.length) inputs[inputs.length - 1].focus();
    }

    function removeEditSubtaskRow(rowId) {
        var el = document.getElementById(rowId);
        if (el) el.remove();
        // update count label
        var remaining = document.querySelectorAll('#editSubtaskList .subtask-row').length;
        document.getElementById('editSubtaskCount').innerText = remaining ? '(' + remaining + ')' : '';
    }

    function saveEditSubtasks() {
        var rows = document.querySelectorAll('#editSubtaskList .subtask-row');
        var subtasks = [];
        rows.forEach(function(row) {
            var inp   = row.querySelector('input[type="text"]');
            var title = inp ? inp.value.trim() : '';
            if (!title) return;
            subtasks.push({ id: row.dataset.dbId || '', title: title });
        });

        var formData = new FormData();
        formData.append('save_edit_subtasks', '1');
        formData.append('task_id', _editTaskId);
        formData.append('subtasks_json', JSON.stringify(subtasks));

        fetch('task_manager.php', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    // Re-render list from server response
                    document.getElementById('editSubtaskList').innerHTML = '';
                    _editSubtaskCounter = 0;
                    if (data.subtasks && data.subtasks.length > 0) {
                        document.getElementById('editSubtaskCount').innerText = '(' + data.subtasks.length + ')';
                        data.subtasks.forEach(function(st) {
                            appendEditSubtaskRow(st.title, st.id, st.is_done == 1);
                        });
                    } else {
                        document.getElementById('editSubtaskCount').innerText = '';
                    }
                    Swal.fire({ toast:true, position:'top-end', icon:'success', title:'Sub tasks saved!', showConfirmButton:false, timer:2000, timerProgressBar:true });
                }
            })
            .catch(function() {
                Swal.fire({ toast:true, position:'top-end', icon:'error', title:'Failed to save sub tasks!', showConfirmButton:false, timer:2000 });
            });
    }

    // ---- Delete ----
    function confirmDelete(formId) {
        Swal.fire({
            title:'Are you sure?', text:"This task will be permanently deleted!",
            icon:'warning', showCancelButton:true,
            confirmButtonColor:'#ef4444', confirmButtonText:'Yes, delete it!'
        }).then(r => { if(r.isConfirmed) document.getElementById(formId).submit(); });
    }

    // ---- Toast ----
    window.onload = function() {
        <?php if($toastMessage != ""): ?>
        const tb = document.getElementById('toastBox');
        document.getElementById('toastMsg').innerText = "<?php echo $toastMessage; ?>";
        tb.className = "show <?php echo $toastType; ?>";
        setTimeout(() => tb.className = tb.className.replace("show",""), 3000);
        <?php endif; ?>
    };
</script>
</body>
</html>