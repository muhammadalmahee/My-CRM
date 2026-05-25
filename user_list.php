<?php
ob_start(); // output buffering — header() সবসময় কাজ করবে
// ========================================================================
// 1. INITIALIZATION & SECURITY CHECK
// ========================================================================
session_start();

// ========================================================================
// EXCEL EXPORT HANDLER
// ========================================================================
if (isset($_GET['export_excel'])) {
    @include 'config.php';
    if (isset($conn)) {
        // Export-এও role-based filter
        $exp_username = mysqli_real_escape_string($conn, $_SESSION['username'] ?? '');
        $exp_uid      = (int)($_SESSION['user_id'] ?? 0);
        $exp_role     = $_SESSION['role'] ?? '';
        if ($exp_role === 'super_admin') {
            $export_query = mysqli_query($conn, "SELECT id, name, username, email, role, designation, reporting_to, status, phone, created_at FROM users ORDER BY id ASC");
        } elseif ($exp_role === 'admin') {
            // Admin: নিজের তৈরি manager ও agent + নিজে
            $export_query = mysqli_query($conn, "SELECT id, name, username, email, role, designation, reporting_to, status, phone, created_at FROM users WHERE created_by='$exp_username' OR id=$exp_uid ORDER BY id ASC");
        } elseif ($exp_role === 'manager') {
            // Manager: নিজের তৈরি agent + নিজের কাছে assigned agent + নিজে
            $export_query = mysqli_query($conn, "SELECT id, name, username, email, role, designation, reporting_to, status, phone, created_at FROM users WHERE ((created_by='$exp_username' OR reporting_to='$exp_username') AND role='agent') OR id=$exp_uid ORDER BY id ASC");
        } else {
            $export_query = mysqli_query($conn, "SELECT id, name, username, email, role, designation, reporting_to, status, phone, created_at FROM users WHERE id=$exp_uid ORDER BY id ASC");
        }
        if (ob_get_length()) ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="user_list_' . date('Y-m-d') . '.csv"');
        header('Cache-Control: max-age=0');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID','Full Name','User ID','Email','Role','Designation','Reporting To','Status','Phone','Created At']);
        while ($row = mysqli_fetch_assoc($export_query)) {
            fputcsv($out, [
                $row['id'], $row['name'], $row['username'], $row['email'],
                $row['role'], $row['designation'] ?? '', $row['reporting_to'] ?? '', $row['status'],
                $row['phone'] ?? '', $row['created_at']
            ]);
        }
        fclose($out);
        exit;
    }
}

// ========================================================================
// CSV TEMPLATE DOWNLOAD HANDLER
// ========================================================================
if (isset($_GET['download_template'])) {
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="user_bulk_upload_template.csv"');
    header('Cache-Control: max-age=0');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['name', 'username', 'email', 'password', 'role', 'designation', 'phone', 'reporting_to']);
    fputcsv($out, ['John Doe', 'johndoe', 'john@example.com', 'Pass@1234', 'agent', 'Manager', '+8801812345678', 'admin']);
    fputcsv($out, ['Jane Smith', 'janesmith', 'jane@example.com', 'Pass@5678', 'manager', 'COO', '+8801987654321', 'superadmin']);
    fclose($out);
    exit;
}

// ========================================================================
// BULK EDIT CSV TEMPLATE DOWNLOAD HANDLER
// ========================================================================
if (isset($_GET['download_edit_template'])) {
    // output buffer clear করো যাতে header() কাজ করে
    if (ob_get_length()) ob_end_clean();

    // DB connect করো (output এর আগেই)
    $host    = "localhost";
    $db_user = "root";
    $db_pass = "";
    $db_name = "demo_crm";
    $tmpConn = @mysqli_connect($host, $db_user, $db_pass, $db_name);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="user_bulk_edit_template.csv"');
    header('Cache-Control: max-age=0');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'name', 'email', 'role', 'designation', 'status', 'phone', 'reporting_to']);
    // real user data থেকে example row দেখাও
    if ($tmpConn) {
        $ex = mysqli_query($tmpConn, "SELECT id, name, email, role, designation, status, phone, reporting_to FROM users ORDER BY id ASC LIMIT 3");
        while ($r = mysqli_fetch_assoc($ex)) {
            fputcsv($out, [
                $r['id'], $r['name'], $r['email'] ?? '',
                $r['role'], $r['designation'] ?? '', $r['status'], $r['phone'] ?? '', $r['reporting_to'] ?? ''
            ]);
        }
        mysqli_close($tmpConn);
    } else {
        fputcsv($out, [1, 'John Doe',   'john@example.com',  'agent',   'Manager', 'active',   '+8801812345678', 'admin']);
        fputcsv($out, [2, 'Jane Smith', 'jane@example.com',  'manager', 'COO',     'inactive', '+8801987654321', 'superadmin']);
    }
    fclose($out);
    exit;
}


$bulkResults = [];
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_upload'])) {
    @include 'config.php';
    if (isset($conn) && isset($_FILES['bulk_file']) && $_FILES['bulk_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp  = $_FILES['bulk_file']['tmp_name'];
        $file_name = $_FILES['bulk_file']['name'];
        $ext       = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        $rows = [];
        if ($ext === 'csv') {
            if (($handle = fopen($file_tmp, 'r')) !== false) {
                $header = null;
                while (($line = fgetcsv($handle)) !== false) {
                    if (!$header) { $header = array_map('trim', $line); continue; }
                    if (count($line) >= 4) {
                        $rows[] = array_combine(array_slice($header, 0, count($line)), array_map('trim', $line));
                    }
                }
                fclose($handle);
            }
        } else {
            $toastMessage = "Only CSV files are supported for bulk upload.";
            $toastType    = "error";
        }

        $inserted   = 0;
        $skipped    = 0;
        $duplicates = [];   // duplicate username list
        $errors     = [];   // other errors

        foreach ($rows as $i => $r) {
            $rowNum = $i + 2;
            $name   = trim($r['name']         ?? '');
            $uname  = trim($r['username']      ?? '');
            $email  = trim($r['email']         ?? '');
            $pass   = trim($r['password']      ?? '');
            $role   = trim($r['role']          ?? 'agent');
            $desig  = trim($r['designation']   ?? '');
            $phone  = trim($r['phone']         ?? '');
            $rep_to = trim($r['reporting_to']  ?? '');

            // Required fields check
            if (empty($name) || empty($uname) || empty($pass)) {
                $errors[] = "Row $rowNum: name, username, password are required.";
                $skipped++; continue;
            }

            $valid_roles = ['super_admin','admin','manager','agent'];
            if (!in_array($role, $valid_roles)) $role = 'agent';

            $uname_esc = mysqli_real_escape_string($conn, $uname);

            // Duplicate username check — skip and record separately
            $chk = mysqli_query($conn, "SELECT id FROM users WHERE username='$uname_esc' LIMIT 1");
            if ($chk && mysqli_num_rows($chk) > 0) {
                $duplicates[] = $uname;   // collect duplicate username
                $skipped++; continue;
            }

            $hashed    = password_hash($pass, PASSWORD_DEFAULT);
            $name_esc  = mysqli_real_escape_string($conn, $name);
            $email_esc = mysqli_real_escape_string($conn, $email);
            $role_esc  = mysqli_real_escape_string($conn, $role);
            $desig_esc = mysqli_real_escape_string($conn, $desig);
            $phone_esc = mysqli_real_escape_string($conn, $phone);

            $bulk_created_by = mysqli_real_escape_string($conn, $_SESSION["username"] ?? "");
            $rep_to_esc = mysqli_real_escape_string($conn, $rep_to);
            $sql = "INSERT INTO users (name,username,email,password,role,designation,status,phone,created_by,reporting_to)
                    VALUES ('$name_esc','$uname_esc','$email_esc','$hashed','$role_esc','$desig_esc','active','$phone_esc','$bulk_created_by','$rep_to_esc')";
            if (mysqli_query($conn, $sql)) $inserted++;
            else { $errors[] = "Row $rowNum: DB error — " . mysqli_error($conn); $skipped++; }
        }

        $bulkResults = [
            'inserted'   => $inserted,
            'skipped'    => $skipped,
            'duplicates' => $duplicates,
            'errors'     => $errors,
        ];

        // Toast message
        if ($inserted > 0 && empty($duplicates) && empty($errors)) {
            $toastMessage = "$inserted user(s) imported successfully!";
            $toastType    = "success";
        } elseif ($inserted > 0) {
            $toastMessage = "$inserted imported, $skipped skipped.";
            $toastType    = "success";
        } else {
            $toastMessage = "No users were imported.";
            $toastType    = "error";
        }

    } else {
        $toastMessage = "Please select a valid CSV file."; $toastType = "error";
    }
}

// ── Country Code Helper ──
function getCountryCodeOptions() {
    $countries = [
        // South Asia (প্রথমে — BD default)
        ['+880','🇧🇩','BD','Bangladesh'],
        ['+91', '🇮🇳','IN','India'],
        ['+92', '🇵🇰','PK','Pakistan'],
        ['+94', '🇱🇰','LK','Sri Lanka'],
        ['+95', '🇲🇲','MM','Myanmar'],
        ['+977','🇳🇵','NP','Nepal'],
        ['+975','🇧🇹','BT','Bhutan'],
        // Middle East
        ['+971','🇦🇪','AE','UAE'],
        ['+966','🇸🇦','SA','Saudi Arabia'],
        ['+974','🇶🇦','QA','Qatar'],
        ['+965','🇰🇼','KW','Kuwait'],
        ['+968','🇴🇲','OM','Oman'],
        ['+973','🇧🇭','BH','Bahrain'],
        ['+962','🇯🇴','JO','Jordan'],
        // Southeast Asia
        ['+60', '🇲🇾','MY','Malaysia'],
        ['+65', '🇸🇬','SG','Singapore'],
        ['+66', '🇹🇭','TH','Thailand'],
        ['+84', '🇻🇳','VN','Vietnam'],
        ['+62', '🇮🇩','ID','Indonesia'],
        ['+63', '🇵🇭','PH','Philippines'],
        // East Asia
        ['+86', '🇨🇳','CN','China'],
        ['+81', '🇯🇵','JP','Japan'],
        ['+82', '🇰🇷','KR','South Korea'],
        // Europe
        ['+44', '🇬🇧','GB','United Kingdom'],
        ['+49', '🇩🇪','DE','Germany'],
        ['+33', '🇫🇷','FR','France'],
        ['+39', '🇮🇹','IT','Italy'],
        ['+34', '🇪🇸','ES','Spain'],
        ['+31', '🇳🇱','NL','Netherlands'],
        ['+7',  '🇷🇺','RU','Russia'],
        // Americas
        ['+1',  '🇺🇸','US','USA'],
        ['+1',  '🇨🇦','CA','Canada'],
        ['+55', '🇧🇷','BR','Brazil'],
        ['+52', '🇲🇽','MX','Mexico'],
        // Africa
        ['+20', '🇪🇬','EG','Egypt'],
        ['+234','🇳🇬','NG','Nigeria'],
        ['+27', '🇿🇦','ZA','South Africa'],
        // Oceania
        ['+61', '🇦🇺','AU','Australia'],
        ['+64', '🇳🇿','NZ','New Zealand'],
    ];
    $html = '';
    foreach($countries as $i => $c){
        $sel = ($i === 0) ? ' selected' : '';
        $html .= "<option value='{$c[0]}'{$sel}>{$c[1]} {$c[0]}</option>\n";
    }
    return $html;
}
@include 'config.php'; 

// ── AJAX: Username availability check ──
if (isset($_POST['check_username']) && isset($conn)) {
    $check_user = mysqli_real_escape_string($conn, trim($_POST['check_username']));
    $res = mysqli_query($conn, "SELECT id FROM users WHERE username='$check_user' LIMIT 1");
    echo ($res && mysqli_num_rows($res) > 0) ? 'taken' : 'available';
    exit;
}

// phone / reporting_to column না থাকলে automatically add করো
if(isset($conn)){
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN IF NOT EXISTS phone varchar(50) DEFAULT NULL");
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN IF NOT EXISTS created_by varchar(100) DEFAULT NULL");
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN IF NOT EXISTS reporting_to varchar(100) DEFAULT NULL");
    mysqli_query($conn, "ALTER TABLE designations ADD COLUMN IF NOT EXISTS created_by varchar(100) DEFAULT NULL");
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$toastMessage = "";
$toastType = "";

// ========================================================================
// 2. USER MANAGEMENT LOGIC (CREATE, UPDATE, DELETE)
// ========================================================================

// A. CREATE NEW USER LOGIC
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_user'])) {
    if(isset($conn)){
        $name = mysqli_real_escape_string($conn, $_POST['name'] ?? '');
        $username = mysqli_real_escape_string($conn, $_POST['username'] ?? ''); 
        $email = mysqli_real_escape_string($conn, $_POST['email'] ?? '');
        $role = mysqli_real_escape_string($conn, $_POST['role'] ?? '');
        $designation = mysqli_real_escape_string($conn, $_POST['designation'] ?? '');
        $phone = mysqli_real_escape_string($conn, $_POST['phone'] ?? '');
        $country_code = mysqli_real_escape_string($conn, $_POST['country_code'] ?? '');
        $full_phone = trim($country_code . ' ' . $phone);
        $reporting_to = mysqli_real_escape_string($conn, $_POST['reporting_to'] ?? '');

        // Role permission check
        $allowedRoles = match($_SESSION['role']) {
            'super_admin' => ['super_admin','admin','manager','agent'],
            'admin'       => ['manager','agent'],
            default       => ['agent'],
        };
        if (!in_array($role, $allowedRoles)) {
            $toastMessage = "You are not allowed to create a user with this role!"; $toastType = "error";
            goto end_create;
        }
        
        $raw_password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Username: space ও special character server-side check
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $_POST['username'] ?? '')) {
            $toastMessage = "User ID cannot contain spaces or special characters!"; $toastType = "error";
            goto end_create;
        }

        // Password: space server-side check
        if (strpos($raw_password, ' ') !== false) {
            $toastMessage = "Password cannot contain spaces!"; $toastType = "error";
            goto end_create;
        }
        
        if($raw_password !== $confirm_password) {
            $toastMessage = "Passwords do not match!"; $toastType = "error";
        } else {
            // Check if username already exists
            $check_sql = "SELECT id FROM users WHERE username='$username' LIMIT 1";
            $check_result = mysqli_query($conn, $check_sql);
            if($check_result && mysqli_num_rows($check_result) > 0){
                $toastMessage = "User ID '$username' already exists! Please choose a different User ID."; $toastType = "error";
            } else {
                $password = password_hash($raw_password, PASSWORD_DEFAULT); 
                $status = 'active'; 
                
                $created_by = mysqli_real_escape_string($conn, $_SESSION['username'] ?? '');
                $insert_sql = "INSERT INTO users (name, username, email, password, role, designation, status, phone, created_by, reporting_to) VALUES ('$name', '$username', '$email', '$password', '$role', '$designation', '$status', '$full_phone', '$created_by', '$reporting_to')";
                
                try {
                    if(mysqli_query($conn, $insert_sql)){
                        $toastMessage = "User created successfully!"; $toastType = "success";
                    }
                } catch (mysqli_sql_exception $e) {
                    $toastMessage = "Database Error: " . $e->getMessage(); $toastType = "error";
                }
            }
        }
    }
    end_create:;
}

// B. UPDATE/EDIT EXISTING USER LOGIC
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_user'])) {
    if(isset($conn)){
        $id = mysqli_real_escape_string($conn, $_POST['user_id'] ?? '');
        $name = mysqli_real_escape_string($conn, $_POST['name'] ?? '');
        $username = mysqli_real_escape_string($conn, $_POST['username'] ?? '');
        $email = mysqli_real_escape_string($conn, $_POST['email'] ?? '');
        $role = mysqli_real_escape_string($conn, $_POST['role'] ?? '');
        $designation = mysqli_real_escape_string($conn, $_POST['designation'] ?? '');
        $status = mysqli_real_escape_string($conn, $_POST['status'] ?? 'active');
        $phone = mysqli_real_escape_string($conn, $_POST['phone'] ?? '');
        $reporting_to = mysqli_real_escape_string($conn, $_POST['reporting_to'] ?? '');

        // Role permission check
        $allowedRolesEdit = match($_SESSION['role']) {
            'super_admin' => ['super_admin','admin','manager','agent'],
            'admin'       => ['manager','agent'],
            default       => ['agent'],
        };
        $editingOwnProfile = ((int)$id === (int)($_SESSION['user_id'] ?? 0));
        // Self-edit হলে নিজের current role টা allowed — role lock হবে পরে
        if (!in_array($role, $allowedRolesEdit) && !$editingOwnProfile) {
            $toastMessage = "You are not allowed to assign this role!"; $toastType = "error";
            goto end_update;
        }

        // Admin/Manager নিজের role ও reporting_to change করতে পারবে না
        // Manager নিজের designation ও status-ও change করতে পারবে না
        $selfEditRestricted = $editingOwnProfile && in_array($_SESSION['role'], ['admin', 'manager']);
        $managerSelfRestrict = $editingOwnProfile && ($_SESSION['role'] === 'manager');

        if ($selfEditRestricted || $managerSelfRestrict) {
            $origRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT role, reporting_to, designation, status FROM users WHERE id=" . (int)$id . " LIMIT 1"));
            if ($origRow) {
                if ($selfEditRestricted) {
                    $role         = mysqli_real_escape_string($conn, $origRow['role']);
                    $reporting_to = mysqli_real_escape_string($conn, $origRow['reporting_to'] ?? '');
                }
                if ($managerSelfRestrict) {
                    $designation = mysqli_real_escape_string($conn, $origRow['designation'] ?? '');
                    $status      = mysqli_real_escape_string($conn, $origRow['status'] ?? 'active');
                }
            }
        }

        $raw_password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Password: space server-side check
        if (!empty($raw_password) && strpos($raw_password, ' ') !== false) {
            $toastMessage = "Password cannot contain spaces!"; $toastType = "error";
            goto end_update;
        }

        if(!empty($raw_password) && $raw_password !== $confirm_password) {
            $toastMessage = "Passwords do not match! User not updated."; $toastType = "error";
        } else {
            $update_sql = "UPDATE users SET name='$name', email='$email', role='$role', designation='$designation', status='$status', phone='$phone', reporting_to='$reporting_to'";
            if (!empty($raw_password)) {
                $new_password = password_hash($raw_password, PASSWORD_DEFAULT);
                $update_sql .= ", password='$new_password'";
            }
            $update_sql .= " WHERE id='$id'";
            try {
                if(mysqli_query($conn, $update_sql)){
                    $toastMessage = "User updated successfully!"; $toastType = "success";
                }
            } catch (mysqli_sql_exception $e) {
                $toastMessage = "Database Error: " . $e->getMessage(); $toastType = "error";
            }
        }
    }
    end_update:;
}

// C. DELETE USER LOGIC
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_user'])) {
    if(isset($conn)){
        $del_id = mysqli_real_escape_string($conn, $_POST['delete_user_id'] ?? '');
        $delete_sql = "DELETE FROM users WHERE id='$del_id'";
        try {
            if(mysqli_query($conn, $delete_sql)){
                $toastMessage = "User deleted successfully!"; $toastType = "success";
            }
        } catch (mysqli_sql_exception $e) {
            $toastMessage = "Error deleting user!"; $toastType = "error";
        }
    }
}

// D. BULK EDIT VIA CSV LOGIC
$bulkEditResults = [];
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_edit_csv'])) {
    // $conn এই পয়েন্টে already available (line 228 এ include হয়েছে)
    // তবু নিশ্চিত করতে re-check
    if (!isset($conn)) @include 'config.php';
    if (isset($conn) && isset($_FILES['bulk_edit_file']) && $_FILES['bulk_edit_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['bulk_edit_file']['tmp_name'];
        $ext      = strtolower(pathinfo($_FILES['bulk_edit_file']['name'], PATHINFO_EXTENSION));

        $rows = [];
        if ($ext === 'csv') {
            if (($handle = fopen($file_tmp, 'r')) !== false) {
                $header = null;
                while (($line = fgetcsv($handle)) !== false) {
                    if (!$header) { $header = array_map('trim', $line); continue; }
                    if (count($line) >= 1) {
                        $rows[] = array_combine(array_slice($header, 0, count($line)), array_map('trim', $line));
                    }
                }
                fclose($handle);
            }
        } else {
            $toastMessage = "Only CSV files are supported.";
            $toastType    = "error";
        }

        $updated  = 0;
        $skipped  = 0;
        $notFound = [];
        $errors   = [];
        $valid_roles   = ['super_admin','admin','manager','agent'];
        $valid_status  = ['active','inactive'];

        foreach ($rows as $i => $r) {
            $rowNum = $i + 2;
            $id = intval($r['id'] ?? 0);
            if ($id <= 0) { $errors[] = "Row $rowNum: Invalid or missing id."; $skipped++; continue; }

            // Check user exists
            $chk = mysqli_query($conn, "SELECT id FROM users WHERE id=$id LIMIT 1");
            if (!$chk || mysqli_num_rows($chk) === 0) {
                $notFound[] = "Row $rowNum (id=$id)";
                $skipped++; continue;
            }

            // Build SET parts — only non-empty columns (username is never touched)
            $set = [];
            if (!empty($r['name']))        $set[] = "name='"         . mysqli_real_escape_string($conn, $r['name'])        . "'";
            if (!empty($r['email']))       $set[] = "email='"        . mysqli_real_escape_string($conn, $r['email'])       . "'";
            if (!empty($r['phone']))       $set[] = "phone='"        . mysqli_real_escape_string($conn, $r['phone'])       . "'";
            if (!empty($r['designation'])) $set[] = "designation='"  . mysqli_real_escape_string($conn, $r['designation']) . "'";
            if (isset($r['reporting_to'])) $set[] = "reporting_to='" . mysqli_real_escape_string($conn, $r['reporting_to']). "'";
            if (!empty($r['role']) && in_array($r['role'], $valid_roles))
                                           $set[] = "role='"         . mysqli_real_escape_string($conn, $r['role'])        . "'";
            if (!empty($r['status']) && in_array($r['status'], $valid_status))
                                           $set[] = "status='"       . mysqli_real_escape_string($conn, $r['status'])      . "'";

            if (empty($set)) { $errors[] = "Row $rowNum (id=$id): No valid fields to update."; $skipped++; continue; }

            $sql = "UPDATE users SET " . implode(', ', $set) . " WHERE id=$id";
            if (mysqli_query($conn, $sql)) $updated++;
            else { $errors[] = "Row $rowNum (id=$id): DB error — " . mysqli_error($conn); $skipped++; }
        }

        $bulkEditResults = [
            'updated'  => $updated,
            'skipped'  => $skipped,
            'notFound' => $notFound,
            'errors'   => $errors,
        ];

        if ($updated > 0 && empty($notFound) && empty($errors)) {
            $toastMessage = "$updated user(s) updated successfully!"; $toastType = "success";
        } elseif ($updated > 0) {
            $toastMessage = "$updated updated, $skipped skipped."; $toastType = "success";
        } else {
            $toastMessage = "No users were updated. Check your CSV file."; $toastType = "error";
        }
    } else {
        // নির্দিষ্ট upload error দেখাও
        $uploadErr = isset($_FILES['bulk_edit_file']) ? $_FILES['bulk_edit_file']['error'] : -1;
        if ($uploadErr === UPLOAD_ERR_NO_FILE) {
            $toastMessage = "No file selected. Please choose a CSV file.";
        } elseif ($uploadErr === UPLOAD_ERR_INI_SIZE || $uploadErr === UPLOAD_ERR_FORM_SIZE) {
            $toastMessage = "File is too large. Please upload a smaller CSV.";
        } else {
            $toastMessage = "File upload failed (error: $uploadErr). Please try again.";
        }
        $toastType = "error";
    }
}

// ========================================================================
// 3. DESIGNATION MANAGEMENT LOGIC
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['create_designation']) && isset($conn)) {
        // Only admin & super_admin can create designations
        if (!in_array($_SESSION['role'] ?? '', ['super_admin', 'admin'])) {
            $toastMessage = "You are not allowed to create designations!"; $toastType = "error";
        } else {
        $designation_title = mysqli_real_escape_string($conn, $_POST['designation_title'] ?? '');
        $desig_created_by  = mysqli_real_escape_string($conn, $_SESSION['username'] ?? '');
        $insert_desig = "INSERT INTO designations (title, created_by) VALUES ('$designation_title', '$desig_created_by')";
        try {
            if(mysqli_query($conn, $insert_desig)){
                $toastMessage = "Designation added successfully!"; $toastType = "success";
            }
        } catch (mysqli_sql_exception $e) {
            $toastMessage = "Error adding designation!"; $toastType = "error";
        }
        } // end role check
    }
    
    if (isset($_POST['update_designation']) && isset($conn)) {
        $desig_id = mysqli_real_escape_string($conn, $_POST['desig_id'] ?? '');
        $designation_title = mysqli_real_escape_string($conn, $_POST['designation_title'] ?? '');
        $upd_username = mysqli_real_escape_string($conn, $_SESSION['username'] ?? '');
        $upd_role     = $_SESSION['role'] ?? '';
        // পুরনো title টা আগে নিয়ে রাখো; admin শুধু নিজের তৈরি designation edit করতে পারবে
        if ($upd_role === 'super_admin') {
            $old_desig_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT title FROM designations WHERE id='$desig_id'"));
        } else {
            $old_desig_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT title FROM designations WHERE id='$desig_id' AND created_by='$upd_username'"));
        }
        if (!$old_desig_row) {
            $toastMessage = "Designation not found or you don't have permission!"; $toastType = "error";
            goto end_update_desig;
        }
        $old_title = mysqli_real_escape_string($conn, $old_desig_row['title']);
        $update_desig = "UPDATE designations SET title='$designation_title' WHERE id='$desig_id'";
        if(mysqli_query($conn, $update_desig)){
            // যেসব user এ পুরনো designation assigned ছিল সেগুলোও update করো
            if(!empty($old_title)){
                mysqli_query($conn, "UPDATE users SET designation='$designation_title' WHERE designation='$old_title'");
            }
            $toastMessage = "Designation updated successfully!"; $toastType = "success";
        } else {
            $toastMessage = "Error updating designation!"; $toastType = "error";
        }
        end_update_desig:;
    }

    if (isset($_POST['delete_designation']) && isset($conn)) {
        $desig_id = mysqli_real_escape_string($conn, $_POST['desig_id'] ?? '');
        $del_username = mysqli_real_escape_string($conn, $_SESSION['username'] ?? '');
        $del_role     = $_SESSION['role'] ?? '';
        if ($del_role === 'super_admin') {
            $delete_desig = "DELETE FROM designations WHERE id='$desig_id'";
        } else {
            $delete_desig = "DELETE FROM designations WHERE id='$desig_id' AND created_by='$del_username'";
        }
        if(mysqli_query($conn, $delete_desig)){
            $toastMessage = "Designation deleted successfully!"; $toastType = "success";
        } else {
            $toastMessage = "Error deleting designation!"; $toastType = "error";
        }
    }
}

// ========================================================================
// 4. FETCH DATA FOR UI (Designations, Users)
// ========================================================================
$designationsList = ""; 
$designationTableRows = ""; 

if(isset($conn)){
    try {
        $fetch_role     = $_SESSION['role']     ?? '';
        $fetch_username = mysqli_real_escape_string($conn, $_SESSION['username'] ?? '');

        if ($fetch_role === 'super_admin') {
            // super_admin সব designation দেখবে
            $desig_query = mysqli_query($conn, "SELECT * FROM designations ORDER BY title ASC");
        } elseif ($fetch_role === 'admin') {
            // Admin শুধু নিজের তৈরি designation দেখবে ও manage করবে
            $desig_query = mysqli_query($conn, "SELECT * FROM designations WHERE created_by='$fetch_username' ORDER BY title ASC");
        } elseif ($fetch_role === 'manager') {
            // Manager দেখবে তার admin এর তৈরি designation (যে admin তাকে তৈরি করেছে)
            $mgr_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT created_by FROM users WHERE username='$fetch_username' LIMIT 1"));
            $mgr_admin = $mgr_row ? mysqli_real_escape_string($conn, $mgr_row['created_by'] ?? '') : '';
            $desig_query = !empty($mgr_admin)
                ? mysqli_query($conn, "SELECT * FROM designations WHERE created_by='$mgr_admin' ORDER BY title ASC")
                : null;
        } else {
            $desig_query = null;
        }

        if($desig_query && mysqli_num_rows($desig_query) > 0){
            while($row = mysqli_fetch_assoc($desig_query)){
                $designationsList .= "<option value='{$row['title']}'>{$row['title']}</option>";
                // Manager শুধু read করবে — edit/delete button দেখবে না
                if ($fetch_role === 'manager') {
                    $designationTableRows .= "
                    <tr>
                        <td style='padding: 10px; border-bottom: 1px solid #e5e7eb; text-align: left; font-weight: 500;' class='desig-text'>{$row['title']}</td>
                        <td style='padding: 10px; border-bottom: 1px solid #e5e7eb; text-align: right;'><span style='color:#9ca3af;font-size:12px;font-style:italic;'>View only</span></td>
                    </tr>";
                } else {
                    $desigData = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
                    $d_id    = (int)$row['id'];
                    $d_title = htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8');
                    $designationTableRows .= "
                    <tr>
                        <td style='padding: 10px; border-bottom: 1px solid #e5e7eb; text-align: left; font-weight: 500;' class='desig-text'>{$row['title']}</td>
                        <td style='padding: 10px; border-bottom: 1px solid #e5e7eb; text-align: right;'>
                            <button type='button' style='background:none; border:none; color:#3b82f6; cursor:pointer; margin-right:15px; font-size:14px;' onclick='openEditDesignationModal({$desigData})'><i class='fa-solid fa-pen-to-square'></i></button>
                            <button type='button' onclick='confirmDeleteDesignation($d_id, this)' data-title='$d_title' style='background:none; border:none; color:#ef4444; cursor:pointer; font-size:14px;'><i class='fa-solid fa-trash'></i></button>
                        </td>
                    </tr>";
                }
            }
        } else {
            $designationsList .= "<option value='COO'>COO</option><option value='CTO'>CTO</option><option value='Manager'>Manager</option>";
            $designationTableRows .= "<tr><td colspan='2' style='padding: 10px; text-align:center; color:#6b7280;'>No designations found.</td></tr>";
        }
    } catch (mysqli_sql_exception $e) {
        $designationsList .= "<option value='COO'>COO</option><option value='CTO'>CTO</option><option value='Manager'>Manager</option>";
        $designationTableRows .= "<tr><td colspan='2' style='padding: 10px; text-align:center; color:#ef4444;'>Table 'designations' not found.</td></tr>";
    }
}

$userTableRows = "";
$_currentRole    = $_SESSION['role']     ?? '';
$_currentUsername = $_SESSION['username'] ?? '';

// Reporting To dropdown — role-based
// super_admin  → সবাইকে দেখাবে
// admin        → শুধু নিজের তৈরি manager ও agent (assign করার জন্য)
// manager      → শুধু নিজের তৈরি বা নিজের কাছে assigned agent
$reportingToOptions = "<option value=''>— None —</option>";
if(isset($conn)){
    $rpt_role     = $_SESSION['role']     ?? '';
    $rpt_username = mysqli_real_escape_string($conn, $_SESSION['username'] ?? '');
    $rpt_uid      = (int)($_SESSION['user_id'] ?? 0);

    if ($rpt_role === 'super_admin') {
        $rpt_query = mysqli_query($conn, "SELECT id, name, username, role FROM users WHERE status='active' ORDER BY name ASC");
    } elseif ($rpt_role === 'admin') {
        // Admin: নিজে + নিজের তৈরি manager ও agent — সবাইকে reporting_to তে assign করতে পারবে
        $rpt_query = mysqli_query($conn, "SELECT id, name, username, role FROM users WHERE status='active' AND (id=$rpt_uid OR (created_by='$rpt_username' AND role IN ('manager','agent'))) ORDER BY name ASC");
    } elseif ($rpt_role === 'manager') {
        // Manager শুধু নিজেকে reporting_to হিসেবে দেখাবে — agent শুধু manager কেই report করবে
        $rpt_query = mysqli_query($conn, "SELECT id, name, username, role FROM users WHERE status='active' AND id=$rpt_uid ORDER BY name ASC");
    } else {
        $rpt_query = null;
    }
    if (!empty($rpt_query)) {
        while($rpt_row = mysqli_fetch_assoc($rpt_query)){
            $rpt_val   = htmlspecialchars($rpt_row['username'], ENT_QUOTES, 'UTF-8');
            $rpt_role_label = ucfirst(str_replace('_', ' ', $rpt_row['role'] ?? ''));
            $rpt_label = htmlspecialchars($rpt_row['name'] . ' (' . $rpt_row['username'] . ') — ' . $rpt_role_label, ENT_QUOTES, 'UTF-8');
            $reportingToOptions .= "<option value='{$rpt_val}'>{$rpt_label}</option>";
        }
    }
}

if(isset($conn)){
    // Role-based filter:
    // super_admin → সব user দেখবে
    // admin       → যারা তাকে report করে (reporting_to = admin username) + নিজে
    // manager     → যারা তাকে report করে (reporting_to = manager username) + নিজে
    // agent       → শুধু নিজেকে দেখবে
    $escaped_username = mysqli_real_escape_string($conn, $_currentUsername);
    $currentUserId    = (int)($_SESSION['user_id'] ?? 0);

    if ($_currentRole === 'super_admin') {
        $users_query = mysqli_query($conn, "SELECT * FROM users ORDER BY id DESC");
    } elseif ($_currentRole === 'admin') {
        // Admin: নিজের তৈরি manager ও agent + নিজে
        $users_query = mysqli_query($conn, "SELECT * FROM users WHERE created_by='$escaped_username' OR id=$currentUserId ORDER BY id DESC");
    } elseif ($_currentRole === 'manager') {
        // Manager: নিজের তৈরি agent + নিজের কাছে assigned agent + নিজে
        $users_query = mysqli_query($conn, "SELECT * FROM users WHERE ((created_by='$escaped_username' OR reporting_to='$escaped_username') AND role='agent') OR id=$currentUserId ORDER BY id DESC");
    } else {
        // agent — শুধু নিজেকে
        $users_query = mysqli_query($conn, "SELECT * FROM users WHERE id=$currentUserId ORDER BY id DESC");
    }

    // super_admin username list — reporting_to hide করার জন্য
    $superAdminUsernames = [];
    if ($_currentRole !== 'super_admin') {
        $saUQ = mysqli_query($conn, "SELECT username FROM users WHERE role='super_admin'");
        if ($saUQ) while ($saUR = mysqli_fetch_assoc($saUQ)) {
            $superAdminUsernames[] = $saUR['username'];
        }
    }

    if($users_query && mysqli_num_rows($users_query) > 0){
        while($row = mysqli_fetch_assoc($users_query)){
            $userData    = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
            $statusClass = ($row['status'] == 'active') ? 'status-active' : 'status-inactive';
            // শুধু super_admin delete button দেখবে
            $deleteBtn = ($_currentRole === 'super_admin')
                ? "<form method='POST' id='delete-user-{$row['id']}' style='display:inline;'>
                        <input type='hidden' name='delete_user_id' value='{$row['id']}'>
                        <input type='hidden' name='delete_user' value='1'>
                        <button type='button' class='btn-delete' onclick='confirmDelete(\"delete-user-{$row['id']}\", \"user\")'><i class='fa-solid fa-trash'></i></button>
                   </form>"
                : "";
            $userTableRows .= "
                <tr class='user-row' data-status='{$row['status']}'>
                    <td>#{$row['id']}</td>
                    <td style='text-align: left; font-weight: 600;'>{$row['name']}</td>
                    <td>{$row['username']}</td>
                    <td>{$row['email']}</td>
                    <td>{$row['role']}</td>
                    <td>" . (!empty($row['designation']) ? htmlspecialchars($row['designation']) : '<span style=\"color:#9ca3af;font-style:italic;\">—</span>') . "</td>
                    <td>" . (!empty($row['reporting_to']) && !in_array($row['reporting_to'], $superAdminUsernames) ? htmlspecialchars($row['reporting_to']) : '<span style=\"color:#9ca3af;font-style:italic;\">—</span>') . "</td>
                    <td><span class='badge $statusClass'>{$row['status']}</span></td>
                    <td>
                        <div class='action-btns'>
                            <button class='btn-view' onclick='openViewModal({$userData})'><i class='fa-solid fa-eye'></i></button>
                            <button class='btn-edit' onclick='openEditModal({$userData})'><i class='fa-solid fa-pen'></i></button>
                            {$deleteBtn}
                        </div>
                    </td>
                </tr>";
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User List - Systellio CRM</title>
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

        /* User Section Styles */
        #userSection { padding: 30px; display: block; }
        .user-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px; }
        .user-title h1 { font-size: 26px; font-weight: 800; margin-bottom: 4px; letter-spacing: -0.5px; transition: 0.3s;}
        .user-title p { font-size: 11px; color: #6b7280; font-weight: 500; }
        
        .header-actions { display: flex; gap: 12px; }
        .header-buttons { display:flex; gap:10px; flex-wrap:wrap; }
        .btn-export {
            background-color:#16a34a; color:#ffffff; padding:10px 18px;
            border-radius:6px; font-size:13px; font-weight:700; border:none;
            cursor:pointer; display:flex; align-items:center; gap:8px;
            box-shadow:0 2px 8px rgba(0,0,0,0.12); transition:background-color .2s, transform .1s;
        }
        .btn-export:hover { background-color:#15803d; transform:translateY(-1px); }
        .btn-bulk {
            background-color:#1e293b; color:#ffffff; padding:10px 18px;
            border-radius:6px; font-size:13px; font-weight:700; border:none;
            cursor:pointer; display:flex; align-items:center; gap:8px;
            box-shadow:0 2px 8px rgba(0,0,0,0.12); transition:background-color .2s, transform .1s;
        }
        .btn-bulk:hover { background-color:#334155; transform:translateY(-1px); }
        .btn-add-user {
            background-color:#0f172a; color:#ffffff; padding:10px 18px;
            border-radius:6px; font-size:13px; font-weight:700; border:none;
            cursor:pointer; display:flex; align-items:center; gap:8px;
            box-shadow:0 2px 8px rgba(0,0,0,0.12); transition:background-color .2s, transform .1s;
        }
        .btn-add-user:hover { background-color:#1e293b; transform:translateY(-1px); }
        body.dark-mode .btn-export     { background-color:#15803d; }
        body.dark-mode .btn-bulk       { background-color:#334155; }
        body.dark-mode .btn-add-user   { background-color:#1e293b; border:1px solid #334155; }

        .btn-primary { background-color: #000000; color: #ffffff; padding: 10px 18px; border-radius: 6px; font-size: 12px; font-weight: 600; border: none; cursor: pointer; transition: 0.3s; display: flex; align-items: center; gap: 8px; }
        .btn-primary:hover { background-color: #1f2937; transform: translateY(-1px); }
        .btn-secondary { background-color: #ffffff; color: #111827; padding: 10px 18px; border-radius: 6px; font-size: 12px; font-weight: 600; border: 1px solid #d1d5db; cursor: pointer; transition: 0.3s; display: flex; align-items: center; gap: 8px; }
        .btn-secondary:hover { background-color: #f9fafb; }

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
        .status-active { background-color: #dcfce7; color: #10b981; }
        .status-inactive { background-color: #fee2e2; color: #ef4444; }

        .action-btns { display: flex; justify-content: center; gap: 6px; }
        .btn-view { background-color: #60a5fa; color: white; padding: 6px 10px; border-radius: 4px; font-size: 11px; border: none; cursor: pointer; transition: 0.3s;}
        .btn-edit { background-color: #34d399; color: white; padding: 6px 10px; border-radius: 4px; font-size: 11px; border: none; cursor: pointer; transition: 0.3s;}
        .btn-delete { background-color: #f87171; color: white; padding: 6px 10px; border-radius: 4px; font-size: 11px; border: none; cursor: pointer; transition: 0.3s;}
        .btn-view:hover { background-color: #3b82f6; }
        .btn-edit:hover { background-color: #10b981; }
        .btn-delete:hover { background-color: #ef4444; }

        /* Modals */
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center; }
        .modal-content { background-color: #fff; padding: 25px 30px; border-radius: 10px; width: 100%; max-width: 750px; box-shadow: 0 10px 25px rgba(0,0,0,0.15); max-height: 95vh; overflow-y: auto; transition: 0.3s; scrollbar-width: none; }
        .modal-content::-webkit-scrollbar { display: none; }
        
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .modal-header h2 { font-size: 20px; font-weight: 700; transition: 0.3s;}
        .close-btn { font-size: 20px; cursor: pointer; color: #6b7280; border: none; background: none; transition: 0.3s;}
        .close-btn:hover { color: #ef4444; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .form-group { margin-bottom: 4px; position: relative; }
        .form-group label { display: block; font-size: 11px; font-weight: 600; margin-bottom: 4px; color: #374151; }
        .form-group input, .form-group select { width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; outline: none; transition: 0.3s; }
        .form-group input:focus, .form-group select:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
        
        .password-toggle {
            position: absolute; right: 10px; bottom: 9px;
            cursor: pointer; color: #6b7280; font-size: 14px; z-index: 2;
        }
        .form-group input[type="password"] { padding-right: 34px; }
        .password-error { color: #ef4444; font-size: 10px; font-weight: 600; margin-top: 4px; display: none; }

        .submit-btn { width: 100%; background-color: #000000; color: #ffffff; padding: 12px; border-radius: 6px; font-size: 14px; font-weight: 700; border: none; cursor: pointer; transition: 0.3s; margin-top: 10px; }
        .submit-btn:hover { background-color: #1f2937; }

        .view-data-box { background: #f9fafb; padding: 10px 12px; border-radius: 6px; border: 1px solid #e5e7eb; font-size: 13px; font-weight: 500; min-height: 40px; display: flex; align-items: center; }

        /* Phone Input */
        .phone-input-wrap {
            display: flex; align-items: stretch;
            border: 1px solid #d1d5db; border-radius: 6px;
            background: #fff;
            transition: border-color 0.2s, box-shadow 0.2s;
            height: 36px; overflow: hidden;
        }
        .phone-input-wrap:focus-within {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        }
        .phone-cc-select {
            border: none !important; outline: none !important;
            background: #f3f4f6;
            padding: 0 18px 0 6px;
            font-size: 11px; font-weight: 600; color: #374151;
            cursor: pointer;
            flex: 0 0 68px; width: 68px; min-width: 68px; max-width: 68px;
            height: 100%; box-shadow: none !important;
            border-radius: 6px 0 0 6px;
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%236b7280'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 5px center;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .phone-divider {
            width: 1px; height: 20px;
            background: #d1d5db; flex-shrink: 0;
        }
        .phone-num-input {
            border: none !important; outline: none !important;
            flex: 1 1 0%; padding: 0 10px;
            font-size: 13px; background: transparent;
            box-shadow: none !important; height: 100%;
            min-width: 0; display: block;
        }
        .phone-field-full { grid-column: 1 / -1; }
        body.dark-mode .phone-input-wrap { border-color: #334155; background: #0f172a; }
        body.dark-mode .phone-cc-select { background: #1e293b; color: #f8fafc; }
        body.dark-mode .phone-divider { background: #334155; }
        body.dark-mode .phone-num-input { color: #f8fafc; background: transparent; }

        /* SweetAlert সবার উপরে */
        .swal-on-top { z-index: 99999 !important; }
        .swal2-container { z-index: 99999 !important; }

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
        body.dark-mode .form-group input, body.dark-mode .form-group select { background-color: #0f172a; color: #f8fafc; border-color: #334155; }
        body.dark-mode .view-data-box { background-color: #0f172a; color: #f8fafc; border-color: #334155; }

        /* Bulk Upload Modal - Dark Mode */
        body.dark-mode #bulkUploadModal .modal-content { background-color: #1e293b; }
        body.dark-mode #bulkUploadModal [style*="background:#eff6ff"] { background:#1e3a5f !important; border-color:#1d4ed8 !important; }
        body.dark-mode #bulkDropZone { border-color:#334155 !important; background:#0f172a !important; color:#94a3b8; }
        body.dark-mode #bulkFileInfo { background:#052e16 !important; border-color:#166534 !important; color:#34d399; }
        body.dark-mode #bulkUploadModal [style*="background:#f0fdf4"] { background:#052e16 !important; border-color:#166534 !important; }
        .btn-bulk-edit-header {
            background-color:#f59e0b; color:#ffffff; padding:10px 18px;
            border-radius:6px; font-size:13px; font-weight:700; border:none;
            cursor:pointer; display:flex; align-items:center; gap:8px;
            box-shadow:0 2px 8px rgba(0,0,0,0.12); transition:background-color .2s, transform .1s;
        }
        .btn-bulk-edit-header:hover { background-color:#d97706; transform:translateY(-1px); }
        body.dark-mode .btn-bulk-edit-header { background-color:#d97706; }
        /* Bulk Edit Modal dark mode */
        body.dark-mode #bulkEditModal [style*="background:#fef9ec"] { background:#2d1f00 !important; border-color:#92400e !important; }
        body.dark-mode #bulkEditDropZone { border-color:#92400e !important; background:#1a1200 !important; color:#94a3b8; }
        body.dark-mode #bulkEditFileInfo { background:#2d1f00 !important; border-color:#92400e !important; color:#fbbf24; }
    </style>
</head>
<body>

    <div id="toastBox">
        <i id="toastIcon" class="fa-solid fa-circle-check"></i>
        <span id="toastMsg">Action Successful!</span>
    </div>

        <?php
    $activePage    = 'user_list';
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

        <div id="userSection">
            <div class="user-header">
                <div class="user-title">
                    <h1>User Management</h1>
                    <p>Manage your team members, roles, and account permissions.</p>
                </div>
                <div class="header-buttons">
                    <a href="user_list.php?export_excel=1" class="btn-export" style="text-decoration:none;">
                        <i class="fa-solid fa-file-csv"></i> Export CSV
                    </a>
                    <button class="btn-bulk" onclick="openModal('bulkUploadModal')">
                        <i class="fa-solid fa-cloud-arrow-up"></i> Bulk Upload
                    </button>
                    <button class="btn-bulk-edit-header" onclick="activateBulkEditMode()">
                        <i class="fa-solid fa-users-gear"></i> Bulk Edit
                    </button>
                    <button class="btn-secondary" onclick="openModal('createDesignationModal')"><i class="fa-solid fa-id-badge"></i> Designations</button>
                    <button class="btn-add-user" onclick="openModal('createUserModal')"><i class="fa-solid fa-plus"></i> Add New User</button>
                </div>
            </div>

            <div class="tab-container">
                <div class="tab-btn active" onclick="filterUsers('all', this)">All Users</div>
                <div class="tab-btn" onclick="filterUsers('active', this)">Active</div>
                <div class="tab-btn" onclick="filterUsers('inactive', this)">In-Active</div>
            </div>

            <div class="table-wrapper">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Full Name</th>
                            <th>User ID</th>
                            <th>Email Address</th>
                            <th>Role</th>
                            <th>Designation</th>
                            <th>Reporting To</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php echo $userTableRows; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <div id="createDesignationModal" class="modal">
        <div class="modal-content" style="max-width: 450px;">
            <div class="modal-header">
                <h2>Manage Designations</h2>
                <button type="button" class="close-btn" onclick="closeModal('createDesignationModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form action="user_list.php" method="POST">
                <div class="form-group" style="margin-bottom: 20px;">
                    <label>New Designation Title</label>
                    <input type="text" name="designation_title" required placeholder="e.g. Senior Developer">
                </div>
                <button type="submit" name="create_designation" class="submit-btn">Add Designation</button>
            </form>
            <div style="margin-top: 25px;">
                <h3 style="font-size: 14px; margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 5px;">Existing Designations</h3>
                <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                    <?php echo $designationTableRows; ?>
                </table>
            </div>
        </div>
    </div>

    <div id="editDesignationModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header">
                <h2>Edit Designation</h2>
                <button type="button" class="close-btn" onclick="closeModal('editDesignationModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form action="user_list.php" method="POST">
                <input type="hidden" name="desig_id" id="edit_desig_id">
                <div class="form-group" style="margin-bottom: 20px;">
                    <label>Designation Title</label>
                    <input type="text" name="designation_title" id="edit_desig_title" required>
                </div>
                <button type="submit" name="update_designation" class="submit-btn" style="background-color: #22c55e;">Update Designation</button>
            </form>
        </div>
    </div>

    <div id="createUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Create New User</h2>
                <button type="button" class="close-btn" onclick="closeModal('createUserModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form action="user_list.php" method="POST">
                <div class="form-grid">
                    <div class="form-group"><label>Full Name</label><input type="text" name="name" required placeholder="e.g. MD. Mh Mahee"></div>
                    <div class="form-group">
                        <label>User ID</label>
                        <input type="text" name="username" id="create_username" required placeholder="e.g. mahee01" oninput="validateUsername(this); debounceUsernameCheck(this.value)" autocomplete="off">
                        <span id="username_check_msg" style="font-size:10px;font-weight:600;margin-top:4px;display:none;"></span>
                        <span id="username_format_msg" style="font-size:10px;font-weight:600;margin-top:2px;display:none;color:#ef4444;">⚠ Spaces and special characters (. , / ? @ # $ % ! etc.) are not allowed.</span>
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <div class="phone-input-wrap">
                            <select name="country_code" id="create_country_code" class="phone-cc-select">
                                <?php echo getCountryCodeOptions(); ?>
                            </select>
                            <span class="phone-divider"></span>
                            <input type="number" name="phone" id="create_phone_num" class="phone-num-input" placeholder="1812345678">
                        </div>
                    </div>
                    <div class="form-group"><label>Email</label><input type="email" name="email" required placeholder="e.g. example@gmail.com"></div>
                    
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" id="create_pass" required placeholder="********" oninput="blockPasswordSpace(this)" onkeyup="checkPasswordMatch('create_pass', 'create_confirm_pass', 'create_error_msg', 'create_submit_btn')">
                        <i class="fa-solid fa-eye password-toggle" onclick="togglePassword('create_pass', this)"></i>
                        <span id="create_pass_space_msg" style="font-size:10px;font-weight:600;margin-top:2px;display:none;color:#ef4444;">⚠ Password cannot contain spaces.</span>
                    </div>
                    <div class="form-group">
                        <label>Confirm Password</label>
                        <input type="password" name="confirm_password" id="create_confirm_pass" required placeholder="********" oninput="blockPasswordSpace(this)" onkeyup="checkPasswordMatch('create_pass', 'create_confirm_pass', 'create_error_msg', 'create_submit_btn')">
                        <i class="fa-solid fa-eye password-toggle" onclick="togglePassword('create_confirm_pass', this)"></i>
                        <span id="create_error_msg" class="password-error">Passwords do not match!</span>
                    </div>

                    <div class="form-group">
                        <label>Role</label>
                        <select name="role" required>
                            <option value="" disabled selected>Select Role</option>
                            <?php if ($_currentRole === 'super_admin'): ?>
                            <option value="super_admin">Super Admin</option>
                            <option value="admin">Admin</option>
                            <option value="manager">Manager</option>
                            <option value="agent">Agent</option>
                            <?php elseif ($_currentRole === 'admin'): ?>
                            <option value="manager">Manager</option>
                            <option value="agent">Agent</option>
                            <?php else: ?>
                            <option value="agent">Agent</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Designation</label>
                        <select name="designation" required>
                            <option value="" disabled selected>Select Designation</option>
                            <?php echo $designationsList; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Reporting To</label>
                        <select name="reporting_to">
                            <?php echo $reportingToOptions; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" name="create_user" id="create_submit_btn" class="submit-btn" style="margin-top: 20px;">Save User</button>
            </form>
        </div>
    </div>

    <div id="editUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit User Details</h2>
                <button type="button" class="close-btn" onclick="closeModal('editUserModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form action="user_list.php" method="POST" id="editUserForm" onsubmit="combineEditPhone()">
                <!-- hidden fields — must be inside form -->
                <input type="hidden" name="user_id"  id="edit_user_id">
                <input type="hidden" name="phone"    id="edit_phone_combined">
                <input type="hidden" name="update_user" value="1">

                <div class="form-grid">
                    <div class="form-group"><label>Full Name</label><input type="text" name="name" id="edit_name" required></div>
                    <div class="form-group">
                        <label>User ID <span style="font-size:10px;font-weight:500;color:#ef4444;">(Cannot be changed)</span></label>
                        <input type="text" name="username" id="edit_username" required readonly
                               style="background:#f3f4f6;color:#6b7280;cursor:not-allowed;border-color:#e5e7eb;">
                    </div>

                    <!-- Phone Number -->
                    <div class="form-group">
                        <label>Phone Number</label>
                        <div class="phone-input-wrap">
                            <select id="edit_country_code" class="phone-cc-select">
                                <?php echo getCountryCodeOptions(); ?>
                            </select>
                            <span class="phone-divider"></span>
                            <input type="text" id="edit_phone_num" class="phone-num-input" placeholder="1812345678">
                        </div>
                    </div>

                    <div class="form-group"><label>Email</label><input type="email" name="email" id="edit_email" required></div>

                    <div class="form-group">
                        <label>Password <span style="font-weight:400;font-size:10px;opacity:.7;">(Leave blank to keep same)</span></label>
                        <input type="password" name="password" id="edit_password" placeholder="********" oninput="blockPasswordSpace(this)" onkeyup="checkPasswordMatch('edit_password','edit_confirm_password','edit_error_msg','edit_submit_btn')">
                        <i class="fa-solid fa-eye password-toggle" onclick="togglePassword('edit_password',this)"></i>
                        <span id="edit_pass_space_msg" style="font-size:10px;font-weight:600;margin-top:2px;display:none;color:#ef4444;">⚠ Password cannot contain spaces.</span>
                    </div>
                    <div class="form-group">
                        <label>Confirm Password</label>
                        <input type="password" name="confirm_password" id="edit_confirm_password" placeholder="********" oninput="blockPasswordSpace(this)" onkeyup="checkPasswordMatch('edit_password','edit_confirm_password','edit_error_msg','edit_submit_btn')">
                        <i class="fa-solid fa-eye password-toggle" onclick="togglePassword('edit_confirm_password',this)"></i>
                        <span id="edit_error_msg" class="password-error">Passwords do not match!</span>
                    </div>

                    <div class="form-group">
                        <label>Role</label>
                        <select name="role" id="edit_role" required>
                            <?php if ($_currentRole === 'super_admin'): ?>
                            <option value="super_admin">Super Admin</option>
                            <option value="admin">Admin</option>
                            <option value="manager">Manager</option>
                            <option value="agent">Agent</option>
                            <?php elseif ($_currentRole === 'admin'): ?>
                            <option value="manager">Manager</option>
                            <option value="agent">Agent</option>
                            <?php else: ?>
                            <option value="agent">Agent</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Account Status</label>
                        <select name="status" id="edit_status" required>
                            <option value="active">Active</option>
                            <option value="inactive">In-Active</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Designation</label>
                        <select name="designation" id="edit_designation">
                            <?php echo $designationsList; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Reporting To</label>
                        <select name="reporting_to" id="edit_reporting_to">
                            <?php echo $reportingToOptions; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" id="edit_submit_btn" class="submit-btn" style="background-color:#22c55e;margin-top:12px;">
                    <i class="fa-solid fa-floppy-disk"></i> Update User
                </button>
            </form>
        </div>
    </div>

    <div id="viewUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>User Profile View</h2>
                <button type="button" class="close-btn" onclick="closeModal('viewUserModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="form-grid">
                <div class="form-group"><label>Full Name</label><div class="view-data-box" id="view_name">-</div></div>
                <div class="form-group"><label>User ID</label><div class="view-data-box" id="view_username">-</div></div>
                <div class="form-group"><label>Phone Number</label><div class="view-data-box" id="view_phone">-</div></div>
                <div class="form-group"><label>Email ID</label><div class="view-data-box" id="view_email">-</div></div>
                <div class="form-group"><label>Password</label><div class="view-data-box" style="color: #6b7280; font-family: monospace;">******** (Encrypted)</div></div>
                <div class="form-group"><label>Role</label><div class="view-data-box" id="view_role">-</div></div>
                <div class="form-group"><label>Account Status</label><div class="view-data-box" id="view_status" style="font-weight: 700;">-</div></div>
                <div class="form-group"><label>Designation</label><div class="view-data-box" id="view_designation">-</div></div>
                <div class="form-group"><label>Reporting To</label><div class="view-data-box" id="view_reporting_to">-</div></div>
            </div>
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button class="submit-btn" onclick="switchToEditMode()" style="background-color: #22c55e; margin-top: 0;"><i class="fa-solid fa-pen-to-square"></i> Edit User</button>
                <button class="submit-btn" onclick="closeModal('viewUserModal')" style="background-color: #6b7280; margin-top: 0;">Close</button>
            </div>
        </div>
    </div>

    <!-- ===== BULK EDIT MODAL (CSV-based) ===== -->
    <div id="bulkEditModal" class="modal">
        <div class="modal-content" style="max-width:820px;">
            <div class="modal-header">
                <div>
                    <h2><i class="fa-solid fa-users-gear" style="color:#f59e0b;margin-right:8px;"></i>Bulk Edit Users</h2>
                    <p style="font-size:12px;color:#6b7280;margin-top:3px;">Upload a CSV file with user <strong>id</strong> to update multiple users at once. Username cannot be changed.</p>
                </div>
                <button type="button" class="close-btn" onclick="closeModal('bulkEditModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <!-- Step 1: Download Template -->
            <div style="background:#fef9ec;border:1px solid #fde68a;border-radius:8px;padding:14px 16px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
                <div>
                    <div style="font-size:13px;font-weight:700;color:#92400e;margin-bottom:3px;"><i class="fa-solid fa-file-csv" style="margin-right:6px;"></i>Download Edit Template</div>
                    <div style="font-size:11px;color:#b45309;">
                        Required: <code style="background:#fde68a;padding:1px 4px;border-radius:3px;">id</code> &nbsp;|&nbsp;
                        Editable: <code style="background:#fde68a;padding:1px 4px;border-radius:3px;">name, email, role, designation, status, phone, reporting_to</code><br>
                        <span style="color:#ef4444;font-weight:600;">⚠ username column is ignored — it cannot be changed.</span><br>
                        Role values: <code style="background:#fde68a;padding:1px 4px;border-radius:3px;">super_admin | admin | manager | agent</code> &nbsp;|&nbsp;
                        Status: <code style="background:#fde68a;padding:1px 4px;border-radius:3px;">active | inactive</code><br>
                        Empty cells = no change for that field.
                    </div>
                </div>
                <a href="user_list.php?download_edit_template=1"
                   style="background:#f59e0b;color:#fff;padding:9px 16px;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:7px;white-space:nowrap;">
                    <i class="fa-solid fa-download"></i> Download Template
                </a>
            </div>

            <!-- Step 2: Upload File -->
            <form action="user_list.php" method="POST" enctype="multipart/form-data" id="bulkEditForm">
                <div id="bulkEditDropZone"
                     style="border:2px dashed #fde68a;border-radius:10px;padding:30px;text-align:center;cursor:pointer;transition:.2s;margin-bottom:16px;background:#fffbeb;"
                     onclick="document.getElementById('bulk_edit_file_input').click()"
                     ondragover="beeDragOver(event)" ondragleave="beeDragLeave(event)" ondrop="beeDrop(event)">
                    <i class="fa-solid fa-users-gear" style="font-size:28px;color:#f59e0b;margin-bottom:8px;display:block;"></i>
                    <div style="font-size:13px;font-weight:600;color:#374151;">Click to browse or drag & drop CSV here</div>
                    <div style="font-size:11px;color:#9ca3af;margin-top:4px;">Only .csv files accepted</div>
                    <input type="file" id="bulk_edit_file_input" name="bulk_edit_file" accept=".csv" style="display:none;" onchange="beeUpdateLabel(this)">
                </div>

                <div id="bulkEditFileInfo" style="display:none;background:#fef9ec;border:1px solid #fde68a;border-radius:7px;padding:10px 14px;margin-bottom:14px;font-size:12px;font-weight:600;color:#92400e;">
                    <i class="fa-solid fa-file-csv"></i> <span id="bulk_edit_file_label">No file selected</span>
                </div>

                <div style="display:flex;gap:10px;margin-top:4px;">
                    <button type="button" onclick="closeModal('bulkEditModal')" style="flex:1;background:#f3f4f6;color:#374151;padding:11px;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;">Cancel</button>
                    <button type="submit" name="bulk_edit_csv" id="bulkEditSubmitBtn" disabled
                        style="flex:2;background:#f59e0b;color:#fff;padding:11px;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;opacity:.5;transition:.2s;">
                        <i class="fa-solid fa-users-gear"></i> Apply Bulk Edit
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===== BULK UPLOAD MODAL ===== -->
    <div id="bulkUploadModal" class="modal">
        <div class="modal-content" style="max-width:820px;">
            <div class="modal-header">
                <div>
                    <h2><i class="fa-solid fa-cloud-arrow-up" style="color:#3b82f6;margin-right:8px;"></i>Bulk Upload Users</h2>
                    <p style="font-size:12px;color:#6b7280;margin-top:3px;">Upload a CSV file to add multiple users at once.</p>
                </div>
                <button type="button" class="close-btn" onclick="closeModal('bulkUploadModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <!-- Step 1: Download Template -->
            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 16px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
                <div>
                    <div style="font-size:13px;font-weight:700;color:#1d4ed8;margin-bottom:3px;"><i class="fa-solid fa-file-csv" style="margin-right:6px;"></i>Download CSV Template</div>
                    <div style="font-size:11px;color:#3b82f6;">Required: <code style="background:#dbeafe;padding:1px 4px;border-radius:3px;">name, username, email, password, role</code> &nbsp;|&nbsp; Optional: <code style="background:#dbeafe;padding:1px 4px;border-radius:3px;">designation, phone, reporting_to</code><br>Role values: <code style="background:#dbeafe;padding:1px 4px;border-radius:3px;">super_admin | admin | manager | agent</code> &nbsp;|&nbsp; reporting_to: username of the supervisor</div>
                </div>
                <a href="user_list.php?download_template=1"
                   style="background:#1d4ed8;color:#fff;padding:9px 16px;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:7px;white-space:nowrap;">
                    <i class="fa-solid fa-download"></i> Download Template
                </a>
            </div>

            <!-- Step 2: Upload File -->
            <form action="user_list.php" method="POST" enctype="multipart/form-data" id="bulkUploadForm">
                <!-- File drop zone -->
                <div id="bulkDropZone" style="border:2px dashed #d1d5db;border-radius:10px;padding:30px;text-align:center;cursor:pointer;transition:.2s;margin-bottom:16px;" onclick="document.getElementById('bulk_file_input').click()" ondragover="bulkDragOver(event)" ondragleave="bulkDragLeave(event)" ondrop="bulkDrop(event)">
                    <i class="fa-solid fa-cloud-arrow-up" style="font-size:28px;color:#9ca3af;margin-bottom:8px;display:block;"></i>
                    <div style="font-size:13px;font-weight:600;color:#374151;">Click to browse or drag & drop CSV here</div>
                    <div style="font-size:11px;color:#9ca3af;margin-top:4px;">Only .csv files accepted</div>
                    <input type="file" id="bulk_file_input" name="bulk_file" accept=".csv" style="display:none;" onchange="updateBulkLabel(this)">
                </div>

                <div id="bulkFileInfo" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:7px;padding:10px 14px;margin-bottom:14px;font-size:12px;font-weight:600;color:#15803d;">
                    <i class="fa-solid fa-file-csv"></i> <span id="bulk_file_label">No file selected</span>
                </div>

                <div style="display:flex;gap:10px;margin-top:4px;">
                    <button type="button" onclick="closeModal('bulkUploadModal')" style="flex:1;background:#f3f4f6;color:#374151;padding:11px;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;">Cancel</button>
                    <button type="submit" name="bulk_upload" id="bulkUploadBtn" disabled
                        style="flex:2;background:#10b981;color:#fff;padding:11px;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;opacity:.5;transition:.2s;">
                        <i class="fa-solid fa-cloud-arrow-up"></i> Import Users
                    </button>
                </div>
            </form>

            </div>
    </div>

    <script>
        /* ===== BULK EDIT — Drag & Drop CSV ===== */
        function activateBulkEditMode() { openModal('bulkEditModal'); }
        function openBulkEditModal()    { openModal('bulkEditModal'); }

        function beeUpdateLabel(input) {
            var file = input.files[0];
            if (file) {
                document.getElementById('bulk_edit_file_label').textContent = file.name;
                document.getElementById('bulkEditFileInfo').style.display   = 'block';
                document.getElementById('bulkEditDropZone').style.borderColor = '#f59e0b';
                document.getElementById('bulkEditDropZone').style.background  = '#fef9ec';
                var btn = document.getElementById('bulkEditSubmitBtn');
                btn.disabled = false; btn.style.opacity = '1';
            }
        }
        function beeDragOver(e)  {
            e.preventDefault();
            document.getElementById('bulkEditDropZone').style.borderColor = '#f59e0b';
            document.getElementById('bulkEditDropZone').style.background  = '#fef3c7';
        }
        function beeDragLeave(e) {
            document.getElementById('bulkEditDropZone').style.borderColor = '#fde68a';
            document.getElementById('bulkEditDropZone').style.background  = '#fffbeb';
        }
        function beeDrop(e) {
            e.preventDefault(); beeDragLeave(e);
            var f = e.dataTransfer.files[0];
            if (f && f.name.endsWith('.csv')) {
                var dt = new DataTransfer(); dt.items.add(f);
                var inp = document.getElementById('bulk_edit_file_input');
                inp.files = dt.files;
                beeUpdateLabel(inp);
            } else {
                Swal.fire({ title:'Invalid file', text:'Please drop a .csv file only!', icon:'warning', confirmButtonColor:'#f59e0b' });
            }
        }
    </script>

    <script>
        function updateBulkLabel(input) {
            var file = input.files[0];
            if (file) {
                var label = document.getElementById('bulk_file_label');
                var info  = document.getElementById('bulkFileInfo');
                var btn   = document.getElementById('bulkUploadBtn');
                var zone  = document.getElementById('bulkDropZone');
                label.textContent = file.name;
                info.style.display = 'block';
                zone.style.borderColor = '#10b981';
                zone.style.background  = '#f0fdf4';
                btn.disabled    = false;
                btn.style.opacity = '1';
            }
        }
        function bulkDragOver(e)  { e.preventDefault(); document.getElementById('bulkDropZone').style.borderColor='#3b82f6'; document.getElementById('bulkDropZone').style.background='#eff6ff'; }
        function bulkDragLeave(e) { document.getElementById('bulkDropZone').style.borderColor='#d1d5db'; document.getElementById('bulkDropZone').style.background=''; }
        function bulkDrop(e) {
            e.preventDefault(); bulkDragLeave(e);
            var f = e.dataTransfer.files[0];
            if (f && f.name.endsWith('.csv')) {
                var dt = new DataTransfer(); dt.items.add(f);
                var inp = document.getElementById('bulk_file_input');
                inp.files = dt.files;
                updateBulkLabel(inp);
            } else {
                Swal.fire({title:'Invalid file', text:'Please drop a .csv file only!', icon:'warning', confirmButtonColor:'#ef4444'});
            }
        }
    </script>

    <script>
        // Data Filtering Logic
        function filterUsers(status, btnElement) {
            const tabBtns = document.querySelectorAll('.tab-btn');
            tabBtns.forEach(btn => btn.classList.remove('active'));
            btnElement.classList.add('active');

            const rows = document.querySelectorAll('.user-row');
            rows.forEach(row => {
                if (status === 'all') { row.style.display = ''; } 
                else {
                    if (row.getAttribute('data-status') === status) { row.style.display = ''; } 
                    else { row.style.display = 'none'; }
                }
            });
        }

        // Modal Logic
        function openModal(id) { document.getElementById(id).style.display = "flex"; }
        function closeModal(id) { document.getElementById(id).style.display = "none"; }

        function openEditDesignationModal(desig) {
            closeModal('createDesignationModal');
            document.getElementById('edit_desig_id').value = desig.id;
            document.getElementById('edit_desig_title').value = desig.title;
            openModal('editDesignationModal');
        }

        let currentUserData = null; 

        function openViewModal(user) {
            currentUserData = user; 
            document.getElementById('view_name').innerText = user.name || 'N/A';
            document.getElementById('view_username').innerText = user.username || 'N/A';
            document.getElementById('view_email').innerText = user.email || 'N/A';
            document.getElementById('view_phone').innerText = user.phone || 'N/A';
            document.getElementById('view_role').innerText = user.role ? user.role.toUpperCase() : 'N/A';
            document.getElementById('view_designation').innerText = user.designation || 'N/A';
            document.getElementById('view_reporting_to').innerText = user.reporting_to || '— None —';
            
            const statusText = (user.status == 'active') ? 'Active' : 'In-Active';
            document.getElementById('view_status').innerText = statusText;
            document.getElementById('view_status').style.color = (user.status == 'active' || user.status == 'Active') ? '#10b981' : '#ef4444';
            openModal('viewUserModal');
        }

        function switchToEditMode() {
            closeModal('viewUserModal');
            if(currentUserData) openEditModal(currentUserData);
        }

        function combineEditPhone() {
            var cc  = document.getElementById('edit_country_code').value;
            var num = document.getElementById('edit_phone_num').value.trim();
            document.getElementById('edit_phone_combined').value = cc + num;
        }

        function openEditModal(user) {
            document.getElementById('edit_user_id').value    = user.id;
            document.getElementById('edit_name').value       = user.name       || '';
            document.getElementById('edit_username').value   = user.username   || '';
            document.getElementById('edit_email').value      = user.email      || '';
            document.getElementById('edit_role').value       = user.role       || '';
            document.getElementById('edit_designation').value= user.designation|| '';
            document.getElementById('edit_reporting_to').value = user.reporting_to || '';

            // phone split করো: country code আলাদা করো
            var fullPhone = user.phone || '';
            var ccSel     = document.getElementById('edit_country_code');
            var bestCode  = '', bestLen = 0;
            Array.from(ccSel.options).forEach(function(opt){
                var code = opt.value;
                if(fullPhone.startsWith(code) && code.length > bestLen){
                    bestCode = code; bestLen = code.length;
                }
            });
            if(bestCode){
                ccSel.value = bestCode;
                document.getElementById('edit_phone_num').value = fullPhone.slice(bestLen).trim();
            } else {
                ccSel.value = '+880';
                document.getElementById('edit_phone_num').value = fullPhone;
            }

            var statusVal = user.status ? user.status.toLowerCase() : 'active';
            document.getElementById('edit_status').value = statusVal;

            // Admin/Manager নিজের role ও reporting_to edit করতে পারবে না
            // Manager নিজের designation ও status-ও edit করতে পারবে না
            var currentUserId  = <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;
            var currentRole    = '<?php echo $_currentRole; ?>';
            var isSelf         = (parseInt(user.id) === currentUserId);
            var restrictSelf   = isSelf && (currentRole === 'admin' || currentRole === 'manager');
            var restrictManager = isSelf && (currentRole === 'manager');

            document.getElementById('edit_role').disabled         = restrictSelf;
            document.getElementById('edit_reporting_to').disabled = restrictSelf;
            document.getElementById('edit_designation').disabled  = restrictManager;
            document.getElementById('edit_status').disabled       = restrictManager;

            var fieldsAlwaysRestrict = [
                {id: 'edit_role',         on: restrictSelf},
                {id: 'edit_reporting_to', on: restrictSelf},
                {id: 'edit_designation',  on: restrictManager},
                {id: 'edit_status',       on: restrictManager},
            ];
            fieldsAlwaysRestrict.forEach(function(f) {
                var el = document.getElementById(f.id);
                el.style.opacity = f.on ? '0.5' : '';
                el.style.cursor  = f.on ? 'not-allowed' : '';
            });

            openModal('editUserModal');
        }

        function togglePassword(id, icon) {
            const input = document.getElementById(id);
            if (input.type === "password") {
                input.type = "text";
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = "password";
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        // ── Username: space ও special character block ──────────────────────
        function validateUsername(input) {
            var formatMsg = document.getElementById('username_format_msg');
            var submitBtn = document.getElementById('create_submit_btn');
            // শুধু letters, digits, underscore (_) এবং hyphen (-) allow
            var cleaned = input.value.replace(/[^a-zA-Z0-9_\-]/g, '');
            if (cleaned !== input.value) {
                input.value = cleaned;
                formatMsg.style.display = 'inline-block';
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.5';
                setTimeout(function() { formatMsg.style.display = 'none'; submitBtn.disabled = false; submitBtn.style.opacity = '1'; }, 2500);
            }
        }

        // ── Password: space block ──────────────────────────────────────────
        function blockPasswordSpace(input) {
            var msgId = (input.id === 'create_pass') ? 'create_pass_space_msg' : 'edit_pass_space_msg';
            var spaceMsg = document.getElementById(msgId);
            if (input.value.indexOf(' ') !== -1) {
                input.value = input.value.replace(/ /g, '');
                if (spaceMsg) { spaceMsg.style.display = 'inline-block'; setTimeout(function(){ spaceMsg.style.display = 'none'; }, 2500); }
            }
        }

        // Real-time User ID availability check (debounced)
        var _usernameTimer = null;

        function debounceUsernameCheck(value) {
            clearTimeout(_usernameTimer);
            var msgEl     = document.getElementById('username_check_msg');
            var submitBtn = document.getElementById('create_submit_btn');
            var username  = value.trim();

            if (!username) {
                msgEl.style.display = 'none';
                submitBtn.disabled  = false;
                submitBtn.style.opacity = '1';
                return;
            }

            // সাথে সাথে Checking... দেখাও
            msgEl.style.display  = 'inline-block';
            msgEl.style.color    = '#6b7280';
            msgEl.textContent    = 'Checking...';
            submitBtn.disabled   = true;
            submitBtn.style.opacity = '0.5';

            // 400ms পর AJAX call
            _usernameTimer = setTimeout(function () {
                checkUsernameAvailability(username);
            }, 400);
        }

        function checkUsernameAvailability(username) {
            var msgEl     = document.getElementById('username_check_msg');
            var submitBtn = document.getElementById('create_submit_btn');

            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'user_list.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function () {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    var res = xhr.responseText.trim();
                    if (res === 'taken') {
                        msgEl.style.color       = '#ef4444';
                        msgEl.textContent       = '⚠ "' + username + '" already exists!';
                        submitBtn.disabled      = true;
                        submitBtn.style.opacity = '0.5';
                    } else {
                        msgEl.style.color       = '#10b981';
                        msgEl.textContent       = '✓ Available';
                        submitBtn.disabled      = false;
                        submitBtn.style.opacity = '1';
                    }
                }
            };
            xhr.send('check_username=' + encodeURIComponent(username));
        }

        function checkPasswordMatch(passId, confirmId, errorId, submitBtnId) {
            const pass = document.getElementById(passId).value;
            const confirm = document.getElementById(confirmId).value;
            const errorMsg = document.getElementById(errorId);
            const submitBtn = document.getElementById(submitBtnId);

            if (confirm === "") {
                errorMsg.style.display = "none";
                submitBtn.disabled = false;
                submitBtn.style.opacity = "1";
            } else if (pass !== confirm) {
                errorMsg.style.display = "block";
                submitBtn.disabled = true;
                submitBtn.style.opacity = "0.5";
            } else {
                errorMsg.style.display = "none";
                submitBtn.disabled = false;
                submitBtn.style.opacity = "1";
            }
        }

        function confirmDeleteDesignation(desigId, btnEl) {
            var desigTitle = btnEl.getAttribute('data-title');
            // modal বন্ধ করো, তারপর alert দেখাও
            closeModal('createDesignationModal');
            setTimeout(function() {
            Swal.fire({
                title: 'Delete Designation?',
                html: '<b>' + desigTitle + '</b> designation টি permanently delete হবে!',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel',
                customClass: { container: 'swal-on-top' }
            }).then((result) => {
                if (result.isConfirmed) {
                    var form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'user_list.php';

                    var f1 = document.createElement('input');
                    f1.type = 'hidden'; f1.name = 'desig_id'; f1.value = desigId;
                    form.appendChild(f1);

                    var f2 = document.createElement('input');
                    f2.type = 'hidden'; f2.name = 'delete_designation'; f2.value = '1';
                    form.appendChild(f2);

                    document.body.appendChild(form);
                    form.submit();
                }
            });
            }, 200); // modal close হওয়ার পর alert আসবে
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

        // Hamburger & Dark Mode handled by sidebar.php

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

        <?php if (!empty($bulkResults)): ?>
        document.addEventListener('DOMContentLoaded', function() {

            <?php
            $res        = $bulkResults;
            $inserted   = (int)$res['inserted'];
            $skipped    = (int)$res['skipped'];
            $duplicates = $res['duplicates'] ?? [];
            $errors     = $res['errors']     ?? [];
            ?>

            <?php if (!empty($duplicates)): ?>
            // ── Duplicate username alert ──────────────────────────────
            var dupList = <?php echo json_encode($duplicates); ?>;
            var dupHtml = dupList.map(function(u) {
                return '<li style="padding:4px 0;border-bottom:1px solid #fee2e2;font-family:monospace;font-size:13px;color:#dc2626;">'
                     + '<i class="fa-solid fa-user-xmark" style="margin-right:6px;color:#f87171;"></i>' + u + '</li>';
            }).join('');

            Swal.fire({
                icon: 'warning',
                title: '<span style="font-size:18px;">Duplicate Username<?php echo count($duplicates) > 1 ? 's' : ''; ?> Found!</span>',
                html:
                    '<p style="font-size:13px;color:#374151;margin-bottom:12px;">'
                    + 'The following <b>' + dupList.length + ' username(s)</b> already exist in the database. '
                    + 'These rows have been skipped.</p>'
                    + '<ul style="list-style:none;padding:0;max-height:200px;overflow-y:auto;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:8px 12px;text-align:left;">'
                    + dupHtml
                    + '</ul>'
                    <?php if ($inserted > 0): ?>
                    + '<p style="margin-top:14px;font-size:12px;color:#6b7280;">✅ The remaining <b><?php echo $inserted; ?> user(s)</b> were imported successfully.</p>'
                    <?php else: ?>
                    + '<p style="margin-top:14px;font-size:12px;color:#ef4444;">❌ No new users were imported.</p>'
                    <?php endif; ?>
                    <?php if (!empty($errors)): ?>
                    + '<p style="margin-top:6px;font-size:11px;color:#f87171;"><?php echo count($errors); ?> other error(s) also occurred.</p>'
                    <?php endif; ?>,
                confirmButtonText: 'Got it, I\'ll fix them',
                confirmButtonColor: '#ef4444',
                customClass: { container: 'swal2-container', popup: 'swal-on-top' },
                width: '480px'
            });

            <?php elseif ($inserted > 0 && empty($errors)): ?>
            // ── All success ───────────────────────────────────────────
            Swal.fire({
                icon: 'success',
                title: 'Import Successful!',
                html: '<p style="font-size:14px;color:#374151;"><b><?php echo $inserted; ?> user(s)</b> imported successfully.</p>',
                confirmButtonText: 'OK',
                confirmButtonColor: '#10b981',
                timer: 3000,
                timerProgressBar: true,
                customClass: { container: 'swal2-container' }
            });

            <?php elseif ($inserted > 0 && !empty($errors)): ?>
            // ── Partial success with errors ───────────────────────────
            var errList = <?php echo json_encode($errors); ?>;
            var errHtml = errList.map(function(e) {
                return '<li style="padding:4px 0;font-size:12px;color:#dc2626;">' + e + '</li>';
            }).join('');
            Swal.fire({
                icon: 'warning',
                title: 'Partial Import',
                html: '<p style="font-size:13px;margin-bottom:10px;">✅ <b><?php echo $inserted; ?></b> imported &nbsp;|&nbsp; ⚠️ <b><?php echo $skipped; ?></b> skipped</p>'
                    + '<ul style="list-style:none;padding:8px 12px;background:#fef2f2;border-radius:8px;text-align:left;max-height:160px;overflow-y:auto;">' + errHtml + '</ul>',
                confirmButtonText: 'OK',
                confirmButtonColor: '#f59e0b',
                customClass: { container: 'swal2-container' }
            });

            <?php else: ?>
            // ── Total failure ─────────────────────────────────────────
            Swal.fire({
                icon: 'error',
                title: 'Import Failed',
                text: 'No users were imported. Please check your CSV file.',
                confirmButtonText: 'OK',
                confirmButtonColor: '#ef4444',
                customClass: { container: 'swal2-container' }
            });
            <?php endif; ?>

        });
        <?php endif; ?>

        <?php if (!empty($bulkEditResults)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            <?php
            $ber      = $bulkEditResults;
            $updated  = (int)$ber['updated'];
            $skipped  = (int)$ber['skipped'];
            $notFound = $ber['notFound'] ?? [];
            $berrors  = $ber['errors']   ?? [];
            ?>

            <?php if (!empty($notFound)): ?>
            var nfList = <?php echo json_encode($notFound); ?>;
            var nfHtml = nfList.map(function(u) {
                return '<li style="padding:4px 0;border-bottom:1px solid #fee2e2;font-size:13px;color:#dc2626;">'
                     + '<i class="fa-solid fa-circle-xmark" style="margin-right:6px;color:#f87171;"></i>' + u + '</li>';
            }).join('');
            Swal.fire({
                icon: 'warning',
                title: '<span style="font-size:18px;">ID Not Found!</span>',
                html: '<p style="font-size:13px;color:#374151;margin-bottom:12px;">The following rows had <b>no matching user ID</b> in the database and were skipped.</p>'
                    + '<ul style="list-style:none;padding:8px 12px;max-height:200px;overflow-y:auto;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;text-align:left;">'
                    + nfHtml + '</ul>'
                    <?php if ($updated > 0): ?>
                    + '<p style="margin-top:14px;font-size:12px;color:#6b7280;">✅ <b><?php echo $updated; ?> user(s)</b> updated successfully.</p>'
                    <?php else: ?>
                    + '<p style="margin-top:14px;font-size:12px;color:#ef4444;">❌ No users were updated.</p>'
                    <?php endif; ?>,
                confirmButtonText: 'Got it',
                confirmButtonColor: '#f59e0b',
                customClass: { container: 'swal2-container', popup: 'swal-on-top' },
                width: '480px'
            });

            <?php elseif ($updated > 0 && empty($berrors)): ?>
            Swal.fire({
                icon: 'success',
                title: 'Bulk Edit Successful!',
                html: '<p style="font-size:14px;color:#374151;"><b><?php echo $updated; ?> user(s)</b> updated successfully.</p>',
                confirmButtonText: 'OK',
                confirmButtonColor: '#f59e0b',
                timer: 3000, timerProgressBar: true,
                customClass: { container: 'swal2-container' }
            });

            <?php elseif ($updated > 0 && !empty($berrors)): ?>
            var beErrList = <?php echo json_encode($berrors); ?>;
            var beErrHtml = beErrList.map(function(e){ return '<li style="padding:4px 0;font-size:12px;color:#dc2626;">' + e + '</li>'; }).join('');
            Swal.fire({
                icon: 'warning',
                title: 'Partial Bulk Edit',
                html: '<p style="font-size:13px;margin-bottom:10px;">✅ <b><?php echo $updated; ?></b> updated &nbsp;|&nbsp; ⚠️ <b><?php echo $skipped; ?></b> skipped</p>'
                    + '<ul style="list-style:none;padding:8px 12px;background:#fef2f2;border-radius:8px;text-align:left;max-height:160px;overflow-y:auto;">' + beErrHtml + '</ul>',
                confirmButtonText: 'OK', confirmButtonColor: '#f59e0b',
                customClass: { container: 'swal2-container' }
            });

            <?php else: ?>
            Swal.fire({
                icon: 'error', title: 'Bulk Edit Failed',
                text: 'No users were updated. Please check your CSV file and ensure the id column is correct.',
                confirmButtonText: 'OK', confirmButtonColor: '#ef4444',
                customClass: { container: 'swal2-container' }
            });
            <?php endif; ?>
        });
        <?php endif; ?>
    </script>
</body>
</html>