<?php
// ========================================================================
// INITIALIZATION & SECURITY CHECK
// ========================================================================
session_start();
@include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$toastMessage = "";
$toastType    = "";
$activeTab    = "profile"; // default tab after submit

// ========================================================================
// A. UPDATE PERSONAL PROFILE
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    $activeTab = "profile";
    if (isset($conn)) {
        $uid   = $_SESSION['user_id'];
        $name  = mysqli_real_escape_string($conn, $_POST['name']  ?? '');
        $email = mysqli_real_escape_string($conn, $_POST['email'] ?? '');
        $sql   = "UPDATE users SET name='$name', email='$email' WHERE id='$uid'";
        try {
            if (mysqli_query($conn, $sql)) {
                $_SESSION['name'] = $name;
                $toastMessage = "Profile updated successfully!";
                $toastType    = "success";
            }
        } catch (mysqli_sql_exception $e) {
            $toastMessage = "Database Error! Could not update profile.";
            $toastType    = "error";
        }
    }
}

// ========================================================================
// B. CHANGE PASSWORD
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $activeTab = "security";
    if (isset($conn)) {
        $uid      = $_SESSION['user_id'];
        $new_pass = $_POST['new_password']     ?? '';
        $con_pass = $_POST['confirm_password'] ?? '';
        if (!empty($new_pass) && $new_pass === $con_pass) {
            $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
            $sql    = "UPDATE users SET password='$hashed' WHERE id='$uid'";
            try {
                if (mysqli_query($conn, $sql)) {
                    $toastMessage = "Password changed successfully!";
                    $toastType    = "success";
                }
            } catch (mysqli_sql_exception $e) {
                $toastMessage = "Database Error! Could not change password.";
                $toastType    = "error";
            }
        } else {
            $toastMessage = "Passwords do not match!";
            $toastType    = "error";
        }
    }
}

// ========================================================================
// C. USER MANAGEMENT — CREATE
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_user'])) {
    $activeTab = "users";
    if (isset($conn)) {
        $name        = mysqli_real_escape_string($conn, $_POST['name']        ?? '');
        $username    = mysqli_real_escape_string($conn, $_POST['username']    ?? '');
        $email       = mysqli_real_escape_string($conn, $_POST['email']       ?? '');
        $role        = mysqli_real_escape_string($conn, $_POST['role']        ?? '');
        $designation = mysqli_real_escape_string($conn, $_POST['designation'] ?? '');
        $raw_pass    = $_POST['password']         ?? '';
        $con_pass    = $_POST['confirm_password'] ?? '';
        if ($raw_pass !== $con_pass) {
            $toastMessage = "Passwords do not match!";
            $toastType    = "error";
        } else {
            $hashed = password_hash($raw_pass, PASSWORD_DEFAULT);
            $sql    = "INSERT INTO users (name, username, email, password, role, designation, status)
                       VALUES ('$name','$username','$email','$hashed','$role','$designation','active')";
            try {
                if (mysqli_query($conn, $sql)) {
                    $toastMessage = "User created successfully!";
                    $toastType    = "success";
                }
            } catch (mysqli_sql_exception $e) {
                $toastMessage = "Error! Username may already exist.";
                $toastType    = "error";
            }
        }
    }
}

// ========================================================================
// D. USER MANAGEMENT — UPDATE
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_user'])) {
    $activeTab = "users";
    if (isset($conn)) {
        $id          = mysqli_real_escape_string($conn, $_POST['user_id']     ?? '');
        $name        = mysqli_real_escape_string($conn, $_POST['name']        ?? '');
        $username    = mysqli_real_escape_string($conn, $_POST['username']    ?? '');
        $email       = mysqli_real_escape_string($conn, $_POST['email']       ?? '');
        $role        = mysqli_real_escape_string($conn, $_POST['role']        ?? '');
        $designation = mysqli_real_escape_string($conn, $_POST['designation'] ?? '');
        $status      = mysqli_real_escape_string($conn, $_POST['status']      ?? 'active');
        $raw_pass    = $_POST['password']         ?? '';
        $con_pass    = $_POST['confirm_password'] ?? '';
        if (!empty($raw_pass) && $raw_pass !== $con_pass) {
            $toastMessage = "Passwords do not match! User not updated.";
            $toastType    = "error";
        } else {
            $sql = "UPDATE users SET name='$name', username='$username', email='$email',
                    role='$role', designation='$designation', status='$status'";
            if (!empty($raw_pass)) {
                $hashed = password_hash($raw_pass, PASSWORD_DEFAULT);
                $sql   .= ", password='$hashed'";
            }
            $sql .= " WHERE id='$id'";
            try {
                if (mysqli_query($conn, $sql)) {
                    $toastMessage = "User updated successfully!";
                    $toastType    = "success";
                }
            } catch (mysqli_sql_exception $e) {
                $toastMessage = "Database Error! Could not update user.";
                $toastType    = "error";
            }
        }
    }
}

// ========================================================================
// E. USER MANAGEMENT — DELETE
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_user'])) {
    $activeTab = "users";
    if (isset($conn)) {
        $del_id = mysqli_real_escape_string($conn, $_POST['delete_user_id'] ?? '');
        try {
            if (mysqli_query($conn, "DELETE FROM users WHERE id='$del_id'")) {
                $toastMessage = "User deleted successfully!";
                $toastType    = "success";
            }
        } catch (mysqli_sql_exception $e) {
            $toastMessage = "Error deleting user!";
            $toastType    = "error";
        }
    }
}

// ========================================================================
// F. DESIGNATION MANAGEMENT
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['create_designation']) && isset($conn)) {
        $activeTab = "designations";
        $title = mysqli_real_escape_string($conn, $_POST['designation_title'] ?? '');
        try {
            if (mysqli_query($conn, "INSERT INTO designations (title) VALUES ('$title')")) {
                $toastMessage = "Designation added successfully!";
                $toastType    = "success";
            }
        } catch (mysqli_sql_exception $e) {
            $toastMessage = "Error adding designation!";
            $toastType    = "error";
        }
    }
    if (isset($_POST['update_designation']) && isset($conn)) {
        $activeTab = "designations";
        $desig_id = mysqli_real_escape_string($conn, $_POST['desig_id']           ?? '');
        $title    = mysqli_real_escape_string($conn, $_POST['designation_title']  ?? '');
        if (mysqli_query($conn, "UPDATE designations SET title='$title' WHERE id='$desig_id'")) {
            $toastMessage = "Designation updated!";
            $toastType    = "success";
        } else {
            $toastMessage = "Error updating designation!";
            $toastType    = "error";
        }
    }
    if (isset($_POST['delete_designation']) && isset($conn)) {
        $activeTab = "designations";
        $desig_id = mysqli_real_escape_string($conn, $_POST['desig_id'] ?? '');
        if (mysqli_query($conn, "DELETE FROM designations WHERE id='$desig_id'")) {
            $toastMessage = "Designation deleted!";
            $toastType    = "success";
        } else {
            $toastMessage = "Error deleting designation!";
            $toastType    = "error";
        }
    }
}

// ========================================================================
// G. SYSTEM PREFERENCES (UI only — extend to DB as needed)
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_preferences'])) {
    $activeTab    = "preferences";
    $toastMessage = "System preferences saved!";
    $toastType    = "success";
}

// ========================================================================
// FETCH DATA
// ========================================================================
$current_name  = $_SESSION['name'] ?? '';
$current_email = '';
if (isset($conn)) {
    $uid = $_SESSION['user_id'];
    $uq  = mysqli_query($conn, "SELECT * FROM users WHERE id='$uid'");
    if ($uq && mysqli_num_rows($uq) > 0) {
        $ud            = mysqli_fetch_assoc($uq);
        $current_name  = $ud['name']  ?? $_SESSION['name'];
        $current_email = $ud['email'] ?? '';
    }
}

$all_users = [];
if (isset($conn)) {
    $uq2 = mysqli_query($conn, "SELECT * FROM users ORDER BY created_at DESC");
    if ($uq2) while ($r = mysqli_fetch_assoc($uq2)) $all_users[] = $r;
}

$all_designations = [];
if (isset($conn)) {
    $dq = mysqli_query($conn, "SELECT * FROM designations ORDER BY id DESC");
    if ($dq) while ($r = mysqli_fetch_assoc($dq)) $all_designations[] = $r;
}

// Stats for overview cards
$total_users    = count($all_users);
$active_users   = count(array_filter($all_users, fn($u) => $u['status'] === 'active'));
$total_desig    = count($all_designations);
$total_companies = 0;
if (isset($conn)) {
    $cq = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM companies");
    if ($cq) $total_companies = mysqli_fetch_assoc($cq)['cnt'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRM Control Center — Systellio</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }

        body {
            background-color: #f1f5f9;
            display: flex;
            height: 100vh;
            overflow: hidden;
            color: #0f172a;
            transition: background-color 0.3s, color 0.3s;
        }

        /* ── Toast ── */
        #toastBox {
            visibility: hidden; min-width: 280px; color: #fff; text-align: left;
            border-radius: 12px; padding: 14px 18px; position: fixed; z-index: 99999;
            right: 28px; top: 28px; font-size: 14px; font-weight: 600;
            display: flex; align-items: center; gap: 10px;
            transform: translateX(120%); transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1), visibility 0.4s;
            box-shadow: 0 8px 24px rgba(0,0,0,0.18);
        }
        #toastBox.show { visibility: visible; transform: translateX(0); }
        #toastBox.success { background: linear-gradient(135deg,#10b981,#059669); }
        #toastBox.error   { background: linear-gradient(135deg,#ef4444,#dc2626); }

        /* ── Main layout ── */
        .main-content {
            flex-grow: 1; display: flex; flex-direction: column;
            overflow-y: auto; background-color: #f1f5f9;
            transition: background-color 0.3s;
        }

        /* ── Page body ── */
        .settings-body { padding: 28px 32px 40px; }

        /* ── Page header ── */
        .page-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 28px;
        }
        .page-header-left h1 {
            font-size: 24px; font-weight: 800; color: #0f172a; letter-spacing: -0.5px;
        }
        .page-header-left p { font-size: 13px; color: #64748b; margin-top: 3px; font-weight: 500; }

        /* ── Stats row ── */
        .stats-row {
            display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; margin-bottom: 28px;
        }
        .stat-card {
            background: #fff; border-radius: 14px; padding: 20px 22px;
            border: 1px solid #e2e8f0; display: flex; align-items: center; gap: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            transition: box-shadow 0.2s, transform 0.2s;
        }
        .stat-card:hover { box-shadow: 0 6px 20px rgba(0,0,0,0.08); transform: translateY(-2px); }
        .stat-icon {
            width: 48px; height: 48px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0;
        }
        .stat-info h3 { font-size: 24px; font-weight: 800; color: #0f172a; }
        .stat-info p  { font-size: 12px; color: #64748b; font-weight: 600; margin-top: 2px; }

        /* ── Tab Nav ── */
        .tab-nav {
            display: flex; gap: 4px; background: #fff; padding: 6px;
            border-radius: 14px; border: 1px solid #e2e8f0; margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .tab-btn {
            flex: 1; padding: 10px 14px; border-radius: 10px; border: none;
            background: transparent; cursor: pointer; font-size: 13px; font-weight: 600;
            color: #64748b; display: flex; align-items: center; justify-content: center;
            gap: 7px; transition: all 0.2s; white-space: nowrap;
        }
        .tab-btn:hover { background: #f8fafc; color: #0f172a; }
        .tab-btn.active { background: #0f172a; color: #fff; box-shadow: 0 2px 8px rgba(15,23,42,0.25); }
        .tab-btn i { font-size: 13px; }

        /* ── Tab Panels ── */
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }

        /* ── Card ── */
        .card {
            background: #fff; border-radius: 14px; border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04); overflow: hidden;
        }
        .card-header {
            padding: 20px 24px; border-bottom: 1px solid #f1f5f9;
            display: flex; align-items: center; justify-content: space-between;
        }
        .card-title {
            font-size: 15px; font-weight: 700; color: #0f172a;
            display: flex; align-items: center; gap: 10px;
        }
        .card-title i { font-size: 14px; }
        .card-body { padding: 24px; }

        /* Two-col grid */
        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }

        /* ── Form styles ── */
        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block; font-size: 11px; font-weight: 700; color: #64748b;
            text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 7px;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%; padding: 11px 14px; border-radius: 9px;
            border: 1.5px solid #e2e8f0; font-size: 14px; color: #0f172a;
            background: #f8fafc; outline: none;
            transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
            font-family: inherit;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #3b82f6; background: #fff;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        }
        .form-group input:disabled { opacity: 0.55; cursor: not-allowed; background: #f1f5f9; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

        /* ── Buttons ── */
        .btn {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 10px 18px; border-radius: 9px; border: none; cursor: pointer;
            font-size: 13px; font-weight: 700; font-family: inherit; transition: all 0.2s;
        }
        .btn-primary   { background: #0f172a; color: #fff; }
        .btn-primary:hover   { background: #1e293b; }
        .btn-danger    { background: #ef4444; color: #fff; }
        .btn-danger:hover    { background: #dc2626; }
        .btn-warning   { background: #f59e0b; color: #fff; }
        .btn-warning:hover   { background: #d97706; }
        .btn-success   { background: #10b981; color: #fff; }
        .btn-success:hover   { background: #059669; }
        .btn-ghost     { background: #f1f5f9; color: #475569; }
        .btn-ghost:hover     { background: #e2e8f0; }
        .btn-sm { padding: 7px 13px; font-size: 12px; }
        .btn-icon { width: 34px; height: 34px; padding: 0; justify-content: center; border-radius: 8px; }

        /* ── Password match ── */
        .pass-err { color: #ef4444; font-size: 11px; font-weight: 600; margin-top: 4px; display: none; }

        /* ── Toggle switch ── */
        .toggle-row {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 0; border-bottom: 1px solid #f8fafc;
        }
        .toggle-row:last-child { border-bottom: none; padding-bottom: 0; }
        .toggle-info h4 { font-size: 14px; font-weight: 600; color: #0f172a; }
        .toggle-info p  { font-size: 12px; color: #94a3b8; margin-top: 2px; }
        .switch { position: relative; display: inline-block; width: 42px; height: 22px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute; cursor: pointer; inset: 0;
            background: #cbd5e1; border-radius: 22px; transition: 0.3s;
        }
        .slider::before {
            content: ''; position: absolute; height: 16px; width: 16px;
            left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: 0.3s;
        }
        input:checked + .slider { background: #10b981; }
        input:checked + .slider::before { transform: translateX(20px); }

        /* ── Users Table ── */
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th {
            padding: 10px 16px; text-align: left; font-size: 11px; font-weight: 700;
            color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;
            background: #f8fafc; border-bottom: 1px solid #e2e8f0;
        }
        .data-table td {
            padding: 13px 16px; border-bottom: 1px solid #f8fafc;
            font-size: 13px; color: #334155;
        }
        .data-table tr:last-child td { border-bottom: none; }
        .data-table tr:hover td { background: #f8fafc; }

        /* Role badge */
        .role-badge {
            display: inline-flex; align-items: center; padding: 3px 10px;
            border-radius: 20px; font-size: 11px; font-weight: 700; letter-spacing: 0.3px;
        }
        .role-super_admin { background: #fef3c7; color: #b45309; }
        .role-admin       { background: #dbeafe; color: #1d4ed8; }
        .role-manager     { background: #ede9fe; color: #6d28d9; }
        .role-agent       { background: #d1fae5; color: #065f46; }

        /* Status badge */
        .status-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700;
        }
        .status-active   { background: #d1fae5; color: #065f46; }
        .status-inactive { background: #fee2e2; color: #991b1b; }
        .status-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

        /* ── Designation tags ── */
        .desig-list { display: flex; flex-wrap: wrap; gap: 10px; }
        .desig-tag {
            display: flex; align-items: center; gap: 8px;
            background: #f1f5f9; border: 1px solid #e2e8f0;
            border-radius: 8px; padding: 8px 14px; font-size: 13px; font-weight: 600; color: #334155;
        }
        .desig-tag-actions { display: flex; gap: 4px; }

        /* ── Empty state ── */
        .empty-state {
            padding: 48px; text-align: center; color: #94a3b8;
        }
        .empty-state i { font-size: 36px; display: block; margin-bottom: 12px; opacity: 0.4; }
        .empty-state p { font-size: 14px; font-weight: 500; }

        /* ── Section label ── */
        .section-label {
            font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase;
            letter-spacing: 1px; margin-bottom: 14px; margin-top: 24px; padding-bottom: 8px;
            border-bottom: 1px solid #f1f5f9;
        }
        .section-label:first-child { margin-top: 0; }

        /* ── Modal overlay ── */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(15,23,42,0.55); z-index: 9000;
            align-items: center; justify-content: center; backdrop-filter: blur(4px);
        }
        .modal-overlay.open { display: flex; }
        .modal-box {
            background: #fff; border-radius: 16px; padding: 32px;
            width: 100%; max-width: 520px; position: relative;
            box-shadow: 0 24px 60px rgba(0,0,0,0.18);
            animation: modalPop 0.25s ease;
        }
        @keyframes modalPop {
            from { opacity:0; transform: scale(0.96) translateY(8px); }
            to   { opacity:1; transform: scale(1) translateY(0); }
        }
        .modal-close {
            position: absolute; top: 16px; right: 16px;
            width: 30px; height: 30px; border-radius: 7px; border: none;
            background: #f1f5f9; cursor: pointer; font-size: 14px; color: #64748b;
            display: flex; align-items: center; justify-content: center;
            transition: background 0.2s, color 0.2s;
        }
        .modal-close:hover { background: #e2e8f0; color: #0f172a; }
        .modal-title { font-size: 18px; font-weight: 800; margin-bottom: 22px; color: #0f172a; }

        /* ── Danger zone ── */
        .danger-zone {
            border: 1.5px solid #fee2e2; border-radius: 12px; padding: 20px 22px;
            background: #fff5f5; margin-top: 20px;
        }
        .danger-zone h4 { font-size: 14px; font-weight: 700; color: #991b1b; margin-bottom: 6px; }
        .danger-zone p  { font-size: 13px; color: #ef4444; margin-bottom: 14px; }

        /* ── DARK MODE ── */
        body.dark-mode { background-color: #070d1a; color: #f1f5f9; }
        body.dark-mode .main-content  { background-color: #070d1a; }
        body.dark-mode .settings-body { }
        body.dark-mode .page-header-left h1 { color: #f1f5f9; }
        body.dark-mode .stat-card     { background: #0f1e35; border-color: #1e3a5f; }
        body.dark-mode .stat-info h3  { color: #f1f5f9; }
        body.dark-mode .tab-nav       { background: #0f1e35; border-color: #1e3a5f; }
        body.dark-mode .tab-btn       { color: #94a3b8; }
        body.dark-mode .tab-btn:hover { background: #1e3a5f; color: #f1f5f9; }
        body.dark-mode .tab-btn.active{ background: #3b82f6; }
        body.dark-mode .card          { background: #0f1e35; border-color: #1e3a5f; }
        body.dark-mode .card-header   { border-color: #1e3a5f; }
        body.dark-mode .card-title    { color: #f1f5f9; }
        body.dark-mode .form-group label  { color: #94a3b8; }
        body.dark-mode .form-group input,
        body.dark-mode .form-group select,
        body.dark-mode .form-group textarea {
            background: #162035; border-color: #1e3a5f; color: #f1f5f9;
        }
        body.dark-mode .form-group input:focus,
        body.dark-mode .form-group select:focus { background: #1e2d4a; }
        body.dark-mode .form-group input:disabled { background: #0a1525; }
        body.dark-mode .data-table th { background: #162035; border-color: #1e3a5f; color: #64748b; }
        body.dark-mode .data-table td { color: #cbd5e1; border-color: #162035; }
        body.dark-mode .data-table tr:hover td { background: #162035; }
        body.dark-mode .section-label { color: #475569; border-color: #1e3a5f; }
        body.dark-mode .toggle-row    { border-color: #1e3a5f; }
        body.dark-mode .toggle-info h4 { color: #f1f5f9; }
        body.dark-mode .desig-tag     { background: #162035; border-color: #1e3a5f; color: #cbd5e1; }
        body.dark-mode .btn-ghost     { background: #162035; color: #94a3b8; }
        body.dark-mode .btn-ghost:hover { background: #1e3a5f; }
        body.dark-mode .modal-box     { background: #0f1e35; }
        body.dark-mode .modal-title   { color: #f1f5f9; }
        body.dark-mode .modal-close   { background: #162035; color: #94a3b8; }
        body.dark-mode .modal-close:hover { background: #1e3a5f; }
        body.dark-mode .danger-zone   { background: #1a0d0d; border-color: #7f1d1d; }
        body.dark-mode .danger-zone h4 { color: #fca5a5; }
        body.dark-mode .danger-zone p  { color: #f87171; }
        body.dark-mode .form-group select option { background: #0f1e35; }
    </style>
</head>
<body>

<!-- ── Toast ── -->
<div id="toastBox">
    <i id="toastIcon" class="fa-solid fa-circle-check"></i>
    <span id="toastMsg">Done!</span>
</div>

<?php
    $activePage    = 'settings';
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

    <div class="settings-body">

        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-left">
                <h1><i class="fa-solid fa-sliders" style="color:#3b82f6;margin-right:10px;"></i>CRM Control Center</h1>
                <p>Manage users, designations, system preferences and account security from one place.</p>
            </div>
        </div>

        <!-- Stats Row -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-icon" style="background:#dbeafe;color:#3b82f6;">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div class="stat-info">
                    <h3><?= $total_users ?></h3>
                    <p>Total Users</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#d1fae5;color:#10b981;">
                    <i class="fa-solid fa-user-check"></i>
                </div>
                <div class="stat-info">
                    <h3><?= $active_users ?></h3>
                    <p>Active Users</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#ede9fe;color:#7c3aed;">
                    <i class="fa-solid fa-id-badge"></i>
                </div>
                <div class="stat-info">
                    <h3><?= $total_desig ?></h3>
                    <p>Designations</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#fef3c7;color:#d97706;">
                    <i class="fa-solid fa-building"></i>
                </div>
                <div class="stat-info">
                    <h3><?= $total_companies ?></h3>
                    <p>Total Companies</p>
                </div>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="tab-nav">
            <button class="tab-btn <?= $activeTab==='profile'      ? 'active':'' ?>" onclick="switchTab('profile')">
                <i class="fa-solid fa-user-circle"></i> My Profile
            </button>
            <button class="tab-btn <?= $activeTab==='security'     ? 'active':'' ?>" onclick="switchTab('security')">
                <i class="fa-solid fa-lock"></i> Security
            </button>
            <button class="tab-btn <?= $activeTab==='users'        ? 'active':'' ?>" onclick="switchTab('users')">
                <i class="fa-solid fa-users-gear"></i> User Management
            </button>
            <button class="tab-btn <?= $activeTab==='designations' ? 'active':'' ?>" onclick="switchTab('designations')">
                <i class="fa-solid fa-id-badge"></i> Designations
            </button>
            <button class="tab-btn <?= $activeTab==='preferences'  ? 'active':'' ?>" onclick="switchTab('preferences')">
                <i class="fa-solid fa-sliders"></i> Preferences
            </button>
        </div>

        <!-- ═══════════════════════════════════════════
             TAB 1 — MY PROFILE
        ═══════════════════════════════════════════ -->
        <div class="tab-panel <?= $activeTab==='profile' ? 'active':'' ?>" id="tab-profile">
            <div class="two-col">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fa-solid fa-user-pen" style="color:#3b82f6;"></i> Personal Information
                        </div>
                    </div>
                    <div class="card-body">
                        <form action="settings.php" method="POST">
                            <div class="form-group">
                                <label>Full Name</label>
                                <input type="text" name="name" value="<?= htmlspecialchars($current_name) ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Email Address</label>
                                <input type="email" name="email" value="<?= htmlspecialchars($current_email) ?>">
                            </div>
                            <div class="form-group">
                                <label>Role</label>
                                <input type="text" value="Super Admin" disabled>
                            </div>
                            <button type="submit" name="update_profile" class="btn btn-primary">
                                <i class="fa-solid fa-floppy-disk"></i> Save Changes
                            </button>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fa-solid fa-circle-info" style="color:#f59e0b;"></i> Account Overview
                        </div>
                    </div>
                    <div class="card-body">
                        <div style="display:flex;flex-direction:column;gap:16px;">
                            <div style="display:flex;align-items:center;gap:16px;padding:16px;background:#f8fafc;border-radius:12px;">
                                <div style="width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#3b82f6,#6366f1);display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px;font-weight:800;">
                                    <?= strtoupper(substr($current_name,0,1)) ?>
                                </div>
                                <div>
                                    <div style="font-weight:700;font-size:15px;color:#0f172a;"><?= htmlspecialchars($current_name) ?></div>
                                    <div style="font-size:12px;color:#64748b;margin-top:2px;"><?= htmlspecialchars($current_email ?: 'No email set') ?></div>
                                </div>
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                                <div style="padding:14px;background:#f8fafc;border-radius:10px;">
                                    <div style="font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">Role</div>
                                    <div style="font-size:14px;font-weight:700;color:#0f172a;margin-top:4px;">Super Admin</div>
                                </div>
                                <div style="padding:14px;background:#f8fafc;border-radius:10px;">
                                    <div style="font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;">Status</div>
                                    <div style="font-size:14px;font-weight:700;color:#10b981;margin-top:4px;"><i class="fa-solid fa-circle" style="font-size:8px;"></i> Active</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════
             TAB 2 — SECURITY
        ═══════════════════════════════════════════ -->
        <div class="tab-panel <?= $activeTab==='security' ? 'active':'' ?>" id="tab-security">
            <div class="two-col">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fa-solid fa-key" style="color:#ef4444;"></i> Change Password
                        </div>
                    </div>
                    <div class="card-body">
                        <form action="settings.php" method="POST" id="passForm">
                            <div class="form-group">
                                <label>New Password</label>
                                <input type="password" name="new_password" id="newPass" placeholder="Enter new password" required>
                            </div>
                            <div class="form-group">
                                <label>Confirm New Password</label>
                                <input type="password" name="confirm_password" id="confPass" placeholder="Confirm new password" onkeyup="checkPass()">
                                <div class="pass-err" id="passErr">Passwords do not match!</div>
                            </div>
                            <button type="submit" name="change_password" id="passBtn" class="btn btn-danger">
                                <i class="fa-solid fa-shield-halved"></i> Update Password
                            </button>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fa-solid fa-shield-check" style="color:#10b981;"></i> Security Tips
                        </div>
                    </div>
                    <div class="card-body">
                        <div style="display:flex;flex-direction:column;gap:13px;">
                            <?php
                            $tips = [
                                ['fa-lock','Use a strong password with letters, numbers & symbols','#3b82f6'],
                                ['fa-rotate','Change your password every 90 days','#10b981'],
                                ['fa-eye-slash','Never share your credentials with others','#f59e0b'],
                                ['fa-right-from-bracket','Always log out from shared devices','#ef4444'],
                            ];
                            foreach ($tips as [$icon,$text,$color]):
                            ?>
                            <div style="display:flex;align-items:flex-start;gap:12px;padding:12px;background:#f8fafc;border-radius:10px;">
                                <div style="width:32px;height:32px;border-radius:8px;background:<?=$color?>22;color:<?=$color?>;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;">
                                    <i class="fa-solid <?=$icon?>"></i>
                                </div>
                                <p style="font-size:13px;color:#475569;font-weight:500;line-height:1.5;"><?=$text?></p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════
             TAB 3 — USER MANAGEMENT
        ═══════════════════════════════════════════ -->
        <div class="tab-panel <?= $activeTab==='users' ? 'active':'' ?>" id="tab-users">
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <i class="fa-solid fa-users" style="color:#3b82f6;"></i>
                        All Users <span style="background:#eff6ff;color:#3b82f6;font-size:11px;padding:2px 9px;border-radius:20px;margin-left:6px;"><?= $total_users ?></span>
                    </div>
                    <button class="btn btn-primary btn-sm" onclick="openModal('createUserModal')">
                        <i class="fa-solid fa-plus"></i> Add User
                    </button>
                </div>
                <?php if (empty($all_users)): ?>
                <div class="empty-state"><i class="fa-solid fa-users"></i><p>No users found.</p></div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Designation</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($all_users as $i => $u): ?>
                        <tr>
                            <td style="color:#94a3b8;font-weight:600;"><?= $i+1 ?></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:9px;">
                                    <div style="width:30px;height:30px;border-radius:8px;background:linear-gradient(135deg,#3b82f6,#6366f1);display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:700;flex-shrink:0;">
                                        <?= strtoupper(substr($u['name'],0,1)) ?>
                                    </div>
                                    <span style="font-weight:600;"><?= htmlspecialchars($u['name']) ?></span>
                                </div>
                            </td>
                            <td style="font-family:monospace;font-size:12px;color:#64748b;"><?= htmlspecialchars($u['username']) ?></td>
                            <td style="color:#64748b;"><?= htmlspecialchars($u['email'] ?? '—') ?></td>
                            <td><span class="role-badge role-<?= $u['role'] ?>"><?= ucfirst(str_replace('_',' ',$u['role'])) ?></span></td>
                            <td style="color:#64748b;"><?= htmlspecialchars($u['designation'] ?: '—') ?></td>
                            <td>
                                <span class="status-badge status-<?= $u['status'] ?>">
                                    <span class="status-dot"></span>
                                    <?= ucfirst($u['status']) ?>
                                </span>
                            </td>
                            <td>
                                <div style="display:flex;gap:5px;">
                                    <button class="btn btn-ghost btn-icon btn-sm"
                                        title="Edit"
                                        onclick="openEditUser(<?= htmlspecialchars(json_encode($u)) ?>)">
                                        <i class="fa-solid fa-pen-to-square" style="color:#3b82f6;"></i>
                                    </button>
                                    <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this user?')">
                                        <input type="hidden" name="delete_user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" name="delete_user" class="btn btn-ghost btn-icon btn-sm" title="Delete">
                                            <i class="fa-solid fa-trash" style="color:#ef4444;"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════
             TAB 4 — DESIGNATIONS
        ═══════════════════════════════════════════ -->
        <div class="tab-panel <?= $activeTab==='designations' ? 'active':'' ?>" id="tab-designations">
            <div class="two-col">
                <!-- Add designation -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fa-solid fa-plus-circle" style="color:#7c3aed;"></i> Add New Designation
                        </div>
                    </div>
                    <div class="card-body">
                        <form action="settings.php" method="POST">
                            <div class="form-group">
                                <label>Designation Title</label>
                                <input type="text" name="designation_title" placeholder="e.g. Sales Executive" required>
                            </div>
                            <button type="submit" name="create_designation" class="btn" style="background:#7c3aed;color:#fff;">
                                <i class="fa-solid fa-plus"></i> Add Designation
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Existing designations -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fa-solid fa-list" style="color:#7c3aed;"></i>
                            All Designations <span style="background:#ede9fe;color:#7c3aed;font-size:11px;padding:2px 9px;border-radius:20px;margin-left:6px;"><?= $total_desig ?></span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($all_designations)): ?>
                        <div class="empty-state" style="padding:24px;"><i class="fa-solid fa-id-badge"></i><p>No designations yet.</p></div>
                        <?php else: ?>
                        <div class="desig-list">
                            <?php foreach ($all_designations as $d): ?>
                            <div class="desig-tag">
                                <span><?= htmlspecialchars($d['title']) ?></span>
                                <div class="desig-tag-actions">
                                    <button class="btn btn-ghost btn-icon" style="width:28px;height:28px;"
                                        onclick="openEditDesig(<?= $d['id'] ?>, '<?= htmlspecialchars(addslashes($d['title'])) ?>')">
                                        <i class="fa-solid fa-pen-to-square" style="font-size:11px;color:#3b82f6;"></i>
                                    </button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this designation?')">
                                        <input type="hidden" name="desig_id" value="<?= $d['id'] ?>">
                                        <button type="submit" name="delete_designation" class="btn btn-ghost btn-icon" style="width:28px;height:28px;">
                                            <i class="fa-solid fa-trash" style="font-size:11px;color:#ef4444;"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════
             TAB 5 — PREFERENCES
        ═══════════════════════════════════════════ -->
        <div class="tab-panel <?= $activeTab==='preferences' ? 'active':'' ?>" id="tab-preferences">
            <div class="two-col">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fa-solid fa-globe" style="color:#f59e0b;"></i> Regional Settings
                        </div>
                    </div>
                    <div class="card-body">
                        <form action="settings.php" method="POST">
                            <div class="form-group">
                                <label>Timezone</label>
                                <select name="timezone">
                                    <option value="Asia/Dhaka" selected>Asia/Dhaka (+06:00)</option>
                                    <option value="UTC">UTC (Universal)</option>
                                    <option value="America/New_York">America/New_York (EST)</option>
                                    <option value="Europe/London">Europe/London (GMT)</option>
                                    <option value="Asia/Kolkata">Asia/Kolkata (+05:30)</option>
                                    <option value="Asia/Singapore">Asia/Singapore (+08:00)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Default Currency</label>
                                <select name="currency">
                                    <option value="BDT">BDT — Bangladeshi Taka (৳)</option>
                                    <option value="USD">USD — US Dollar ($)</option>
                                    <option value="EUR">EUR — Euro (€)</option>
                                    <option value="GBP">GBP — British Pound (£)</option>
                                    <option value="INR">INR — Indian Rupee (₹)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Date Format</label>
                                <select name="date_format">
                                    <option value="d/m/Y" selected>DD/MM/YYYY</option>
                                    <option value="Y-m-d">YYYY-MM-DD</option>
                                    <option value="M d, Y">Mon DD, YYYY</option>
                                </select>
                            </div>
                            <button type="submit" name="save_preferences" class="btn btn-warning">
                                <i class="fa-solid fa-check-double"></i> Save Preferences
                            </button>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="fa-solid fa-bell" style="color:#8b5cf6;"></i> Notification Settings
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="toggle-row">
                            <div class="toggle-info">
                                <h4>Email Notifications</h4>
                                <p>Receive daily summary emails and alerts.</p>
                            </div>
                            <label class="switch">
                                <input type="checkbox" id="notif_email" onchange="saveToggle('notif_email', this.checked)">
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="toggle-row">
                            <div class="toggle-info">
                                <h4>Browser Notifications</h4>
                                <p>Get instant alerts for new tasks and deals.</p>
                            </div>
                            <label class="switch">
                                <input type="checkbox" id="notif_browser" onchange="saveToggle('notif_browser', this.checked)">
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="toggle-row">
                            <div class="toggle-info">
                                <h4>Task Due Reminders</h4>
                                <p>Notify 24 hours before a task is due.</p>
                            </div>
                            <label class="switch">
                                <input type="checkbox" id="notif_tasks" onchange="saveToggle('notif_tasks', this.checked)">
                                <span class="slider"></span>
                            </label>
                        </div>
                        <div class="toggle-row">
                            <div class="toggle-info">
                                <h4>Deal Status Updates</h4>
                                <p>Alerts when a deal changes stage.</p>
                            </div>
                            <label class="switch">
                                <input type="checkbox" id="notif_deals" onchange="saveToggle('notif_deals', this.checked)">
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /settings-body -->
</div><!-- /main-content -->

<!-- ═══════════════════════════════════════════
     MODAL — Create User
═══════════════════════════════════════════ -->
<div class="modal-overlay" id="createUserModal">
    <div class="modal-box">
        <button class="modal-close" onclick="closeModal('createUserModal')"><i class="fa-solid fa-xmark"></i></button>
        <div class="modal-title"><i class="fa-solid fa-user-plus" style="color:#3b82f6;margin-right:8px;"></i>Create New User</div>
        <form action="settings.php" method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="name" placeholder="John Doe" required>
                </div>
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" placeholder="johndoe" required>
                </div>
            </div>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="john@example.com">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" required>
                        <option value="">Select Role</option>
                        <option value="admin">Admin</option>
                        <option value="manager">Manager</option>
                        <option value="agent">Agent</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Designation</label>
                    <select name="designation">
                        <option value="">None</option>
                        <?php foreach ($all_designations as $d): ?>
                        <option value="<?= htmlspecialchars($d['title']) ?>"><?= htmlspecialchars($d['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" placeholder="••••••••" required>
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" placeholder="••••••••" required>
                </div>
            </div>
            <div style="display:flex;gap:10px;margin-top:8px;">
                <button type="submit" name="create_user" class="btn btn-primary"><i class="fa-solid fa-check"></i> Create User</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('createUserModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════
     MODAL — Edit User
═══════════════════════════════════════════ -->
<div class="modal-overlay" id="editUserModal">
    <div class="modal-box">
        <button class="modal-close" onclick="closeModal('editUserModal')"><i class="fa-solid fa-xmark"></i></button>
        <div class="modal-title"><i class="fa-solid fa-user-pen" style="color:#f59e0b;margin-right:8px;"></i>Edit User</div>
        <form action="settings.php" method="POST" id="editUserForm">
            <input type="hidden" name="user_id" id="eu_id">
            <div class="form-row">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="name" id="eu_name" required>
                </div>
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" id="eu_username" required>
                </div>
            </div>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" id="eu_email">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" id="eu_role">
                        <option value="super_admin">Super Admin</option>
                        <option value="admin">Admin</option>
                        <option value="manager">Manager</option>
                        <option value="agent">Agent</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="eu_status">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Designation</label>
                <select name="designation" id="eu_designation">
                    <option value="">None</option>
                    <?php foreach ($all_designations as $d): ?>
                    <option value="<?= htmlspecialchars($d['title']) ?>"><?= htmlspecialchars($d['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>New Password <span style="color:#94a3b8;font-weight:500;text-transform:none;">(leave blank to keep)</span></label>
                    <input type="password" name="password" placeholder="••••••••">
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" placeholder="••••••••">
                </div>
            </div>
            <div style="display:flex;gap:10px;margin-top:8px;">
                <button type="submit" name="update_user" class="btn btn-warning"><i class="fa-solid fa-floppy-disk"></i> Update User</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('editUserModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════
     MODAL — Edit Designation
═══════════════════════════════════════════ -->
<div class="modal-overlay" id="editDesigModal">
    <div class="modal-box" style="max-width:400px;">
        <button class="modal-close" onclick="closeModal('editDesigModal')"><i class="fa-solid fa-xmark"></i></button>
        <div class="modal-title"><i class="fa-solid fa-pen" style="color:#7c3aed;margin-right:8px;"></i>Edit Designation</div>
        <form action="settings.php" method="POST">
            <input type="hidden" name="desig_id" id="ed_id">
            <div class="form-group">
                <label>Designation Title</label>
                <input type="text" name="designation_title" id="ed_title" required>
            </div>
            <div style="display:flex;gap:10px;">
                <button type="submit" name="update_designation" class="btn" style="background:#7c3aed;color:#fff;">
                    <i class="fa-solid fa-floppy-disk"></i> Update
                </button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('editDesigModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Tab switching ──────────────────────────────────
function switchTab(name) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    document.querySelectorAll('.tab-btn').forEach(b => {
        if (b.getAttribute('onclick') === "switchTab('" + name + "')") b.classList.add('active');
    });
}

// ── Toast ──────────────────────────────────────────
function showToast(msg, type) {
    const t = document.getElementById('toastBox');
    const m = document.getElementById('toastMsg');
    const i = document.getElementById('toastIcon');
    m.textContent = msg;
    t.className   = 'show ' + type;
    i.className   = type === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark';
    setTimeout(() => { t.className = t.className.replace('show', ''); }, 3200);
}

// ── Password match ──────────────────────────────────
function checkPass() {
    const p1  = document.getElementById('newPass').value;
    const p2  = document.getElementById('confPass').value;
    const err = document.getElementById('passErr');
    const btn = document.getElementById('passBtn');
    if (p2 !== '') {
        if (p1 !== p2) { err.style.display = 'block'; btn.disabled = true; btn.style.opacity = '0.5'; }
        else            { err.style.display = 'none';  btn.disabled = false; btn.style.opacity = '1'; }
    } else { err.style.display = 'none'; btn.disabled = false; btn.style.opacity = '1'; }
}

// ── Modal helpers ───────────────────────────────────
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// Close modal on overlay click
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) closeModal(this.id);
    });
});

// ── Edit User fill ──────────────────────────────────
function openEditUser(u) {
    document.getElementById('eu_id').value          = u.id;
    document.getElementById('eu_name').value        = u.name;
    document.getElementById('eu_username').value    = u.username;
    document.getElementById('eu_email').value       = u.email || '';
    document.getElementById('eu_role').value        = u.role;
    document.getElementById('eu_status').value      = u.status;
    document.getElementById('eu_designation').value = u.designation || '';
    openModal('editUserModal');
}

// ── Edit Designation fill ───────────────────────────
function openEditDesig(id, title) {
    document.getElementById('ed_id').value    = id;
    document.getElementById('ed_title').value = title;
    openModal('editDesigModal');
}

// ── Notification Toggles — localStorage ────────────
var NOTIF_DEFAULTS = {
    notif_email:   true,
    notif_browser: false,
    notif_tasks:   true,
    notif_deals:   true
};

function saveToggle(key, val) {
    localStorage.setItem(key, val ? '1' : '0');
    showToast(val ? 'Notification enabled' : 'Notification disabled', val ? 'success' : 'error');
}

function loadToggles() {
    Object.keys(NOTIF_DEFAULTS).forEach(function(key) {
        var el = document.getElementById(key);
        if (!el) return;
        var stored = localStorage.getItem(key);
        // stored নেই মানে প্রথমবার — default মান ব্যবহার করো
        el.checked = stored !== null ? stored === '1' : NOTIF_DEFAULTS[key];
    });
}


window.addEventListener('load', function () {
    loadToggles();
    <?php if ($toastMessage): ?>
        showToast("<?= addslashes($toastMessage) ?>", "<?= $toastType ?>");
    <?php endif; ?>
});
</script>
</body>
</html>