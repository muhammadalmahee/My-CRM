<?php
// ========================================================================
// AJAX: Get company assigned_agents by company_id
// ========================================================================
if (isset($_GET['get_company_agents']) && isset($_GET['company_id'])) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    @include 'config.php';
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id']) || !isset($conn)) { echo json_encode([]); exit(); }
    $cid = (int)$_GET['company_id'];
    $res = mysqli_query($conn, "SELECT assigned_agent FROM companies WHERE id=$cid AND status='active' LIMIT 1");
    $agents = [];
    if ($res && $row = mysqli_fetch_assoc($res)) {
        $raw = trim($row['assigned_agent'] ?? '');
        if ($raw && $raw !== 'Unassigned') {
            $agents = array_values(array_filter(array_map('trim', explode(',', $raw))));
        }
    }
    echo json_encode($agents);
    exit();
}

// ========================================================================
// 0. EXCEL TEMPLATE DOWNLOAD
// ========================================================================
if (isset($_GET['download_template'])) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    // Security: only logged-in non-agent users can download
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php"); exit();
    }
    if (($_SESSION['role'] ?? '') === 'agent') {
        header("Location: client_list.php"); exit();
    }

    $templateFile = __DIR__ . '/client_upload_template.xlsx';

    // ── Generate template using PhpSpreadsheet if available, else serve pre-built file ──
    if (!file_exists($templateFile)) {
        // Fallback: create a minimal CSV if xlsx file is missing
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="client_upload_template.csv"');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM
        echo "name,email,phone,designation,company,assigned_agents,fb_url,linkedin_url,twitter_url,insta_url\n";
        echo "John Doe,john@example.com,01700000000,Manager,courseplus,admin,https://facebook.com/johndoe,https://linkedin.com/in/johndoe,https://x.com/johndoe,https://instagram.com/johndoe\n";
        echo "Jane Smith,jane@example.com,01800000000,Developer,Peersolution,admin agent,,,\n";
        exit();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="client_upload_template.xlsx"');
    header('Content-Length: ' . filesize($templateFile));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    readfile($templateFile);
    exit();
}

// ========================================================================
// 1. INITIALIZATION & SECURITY CHECK
// ========================================================================
if (session_status() === PHP_SESSION_NONE) session_start();
@include 'config.php'; 

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$toastMessage = "";
$toastType = "";

// ── Role & user info ──
$_currentRole     = $_SESSION['role']     ?? '';
$_currentUsername = $_SESSION['username'] ?? '';
$_currentName     = $_SESSION['name']     ?? '';
$_isAgent         = ($_currentRole === 'agent');

// ========================================================================
// 2. CLIENT LOGIC (CREATE, DELETE)
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_upload_clients']) && !$_isAgent) {
    if(isset($conn)){
        $rows = json_decode($_POST['bulk_rows'] ?? '[]', true);
        $inserted = 0; $skipped = 0;
        if(is_array($rows)){
            // Ensure assigned_agents column exists
            $_cols=[]; $_cr=mysqli_query($conn,"SHOW COLUMNS FROM contacts");
            if($_cr){while($_c=mysqli_fetch_assoc($_cr))$_cols[]=$_c['Field'];}
            if(!in_array('assigned_agents',$_cols)) mysqli_query($conn,"ALTER TABLE contacts ADD COLUMN assigned_agents TEXT DEFAULT NULL");

            // Ensure social URL columns exist
            if(!in_array('fb_url',       $_cols)) mysqli_query($conn,"ALTER TABLE contacts ADD COLUMN fb_url VARCHAR(255) DEFAULT NULL");
            if(!in_array('linkedin_url', $_cols)) mysqli_query($conn,"ALTER TABLE contacts ADD COLUMN linkedin_url VARCHAR(255) DEFAULT NULL");
            if(!in_array('twitter_url',  $_cols)) mysqli_query($conn,"ALTER TABLE contacts ADD COLUMN twitter_url VARCHAR(255) DEFAULT NULL");
            if(!in_array('insta_url',    $_cols)) mysqli_query($conn,"ALTER TABLE contacts ADD COLUMN insta_url VARCHAR(255) DEFAULT NULL");

            foreach($rows as $row){
                $n = trim($row['name'] ?? '');
                if(empty($n)){ $skipped++; continue; }
                $n   = mysqli_real_escape_string($conn, $n);
                $e   = mysqli_real_escape_string($conn, trim($row['email']       ?? ''));
                $p   = mysqli_real_escape_string($conn, trim($row['phone']       ?? ''));
                $d   = mysqli_real_escape_string($conn, trim($row['designation'] ?? ''));
                $c   = mysqli_real_escape_string($conn, trim($row['company']     ?? ''));
                $fb  = mysqli_real_escape_string($conn, trim($row['fb_url']      ?? ''));
                $li  = mysqli_real_escape_string($conn, trim($row['linkedin_url']?? ''));
                $tw  = mysqli_real_escape_string($conn, trim($row['twitter_url'] ?? ''));
                $ig  = mysqli_real_escape_string($conn, trim($row['insta_url']   ?? ''));
                // assigned_agents: comma-separated usernames string
                $ag_raw = trim($row['assigned_agents'] ?? '');
                $ag_arr = array_filter(array_map('trim', explode(',', $ag_raw)));
                // resolve company name → id
                $cid = 'NULL';
                if(!empty($c)){
                    $cr = mysqli_query($conn,"SELECT id, assigned_agent FROM companies WHERE company_name='$c' LIMIT 1");
                    if($cr && mysqli_num_rows($cr)>0){
                        $crRow = mysqli_fetch_assoc($cr);
                        $cid = $crRow['id'];
                        // ── Company-র সব assigned_agents auto-merge (comma-separated) ──
                        $compAg = trim($crRow['assigned_agent'] ?? '');
                        if ($compAg && $compAg !== 'Unassigned') {
                            foreach (array_filter(array_map('trim', explode(',', $compAg))) as $_bag) {
                                if ($_bag && !in_array($_bag, $ag_arr)) $ag_arr[] = $_bag;
                            }
                        }
                    }
                }
                $ag_val = !empty($ag_arr) ? "'".mysqli_real_escape_string($conn, implode(',', $ag_arr))."'" : "NULL";
                $ok = mysqli_query($conn,"INSERT INTO contacts (name,email,phone,designation,company_id,assigned_agents,fb_url,linkedin_url,twitter_url,insta_url,created_by)
                    VALUES ('$n','$e','$p','$d',$cid,$ag_val,'$fb','$li','$tw','$ig'," . intval($_SESSION['user_id'] ?? 0) . ")");
                $ok ? $inserted++ : $skipped++;
            }
        }
        $toastMessage = "$inserted client(s) uploaded successfully!" . ($skipped ? " ($skipped skipped)" : "");
        $toastType = "success";
    }
}


if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_client']) && !$_isAgent) {
    if(isset($conn)){
        $client_name = mysqli_real_escape_string($conn, $_POST['client_name'] ?? '');
        $client_email = mysqli_real_escape_string($conn, $_POST['client_email'] ?? '');
        $client_phone = mysqli_real_escape_string($conn, $_POST['client_phone'] ?? '');
        $client_designation = mysqli_real_escape_string($conn, $_POST['client_designation'] ?? '');
        $fb_url       = mysqli_real_escape_string($conn, $_POST['fb_url']       ?? '');
        $linkedin_url = mysqli_real_escape_string($conn, $_POST['linkedin_url'] ?? '');
        $twitter_url  = mysqli_real_escape_string($conn, $_POST['twitter_url']  ?? '');
        $insta_url    = mysqli_real_escape_string($conn, $_POST['insta_url']    ?? '');

        $company_id = $_POST['company_id'] ?? '';
        $comp_insert_val = !empty($company_id) ? "'".mysqli_real_escape_string($conn, $company_id)."'" : "NULL";

        $assigned_agents_raw = $_POST['assigned_agents'] ?? [];

        // ── Company-র সব assigned_agents auto-merge (comma-separated support) ──
        if (!empty($company_id)) {
            $compAgRes = mysqli_query($conn, "SELECT assigned_agent FROM companies WHERE id=" . intval($company_id) . " LIMIT 1");
            if ($compAgRes) {
                $compAgRow = mysqli_fetch_assoc($compAgRes);
                $compAgRaw = trim($compAgRow['assigned_agent'] ?? '');
                if ($compAgRaw && $compAgRaw !== 'Unassigned') {
                    foreach (array_filter(array_map('trim', explode(',', $compAgRaw))) as $_cag) {
                        if ($_cag && !in_array($_cag, $assigned_agents_raw)) {
                            $assigned_agents_raw[] = $_cag;
                        }
                    }
                }
            }
        }

        $assigned_agents_val = !empty($assigned_agents_raw) ? "'".mysqli_real_escape_string($conn, implode(',', $assigned_agents_raw))."'": "NULL";

        // Ensure columns exist
        $_cols2=[]; $_cr2=mysqli_query($conn,"SHOW COLUMNS FROM contacts");
        if($_cr2){while($_c2=mysqli_fetch_assoc($_cr2))$_cols2[]=$_c2['Field'];}
        if(!in_array('assigned_agents',$_cols2)) mysqli_query($conn,"ALTER TABLE contacts ADD COLUMN assigned_agents TEXT DEFAULT NULL");
        if(!in_array('fb_url',       $_cols2)) mysqli_query($conn,"ALTER TABLE contacts ADD COLUMN fb_url VARCHAR(255) DEFAULT NULL");
        if(!in_array('linkedin_url', $_cols2)) mysqli_query($conn,"ALTER TABLE contacts ADD COLUMN linkedin_url VARCHAR(255) DEFAULT NULL");
        if(!in_array('twitter_url',  $_cols2)) mysqli_query($conn,"ALTER TABLE contacts ADD COLUMN twitter_url VARCHAR(255) DEFAULT NULL");
        if(!in_array('insta_url',    $_cols2)) mysqli_query($conn,"ALTER TABLE contacts ADD COLUMN insta_url VARCHAR(255) DEFAULT NULL");

        $insert_client_sql = "INSERT INTO contacts (name, email, phone, designation, company_id, assigned_agents, fb_url, linkedin_url, twitter_url, insta_url, created_by)
            VALUES ('$client_name', '$client_email', '$client_phone', '$client_designation', $comp_insert_val, $assigned_agents_val,
            '$fb_url', '$linkedin_url', '$twitter_url', '$insta_url', " . intval($_SESSION['user_id'] ?? 0) . ")";
        try {
            if(mysqli_query($conn, $insert_client_sql)){
                $toastMessage = "Client added successfully!";
                $toastType = "success";
            }
        } catch (mysqli_sql_exception $e) {
            $toastMessage = "Database Error! Create 'contacts' table.";
            $toastType = "error";
        }
    }
}

// super_admin / admin → সব field update করতে পারবে
// manager → শুধু assigned_agents update করতে পারবে
// agent → কিছুই করতে পারবে না
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_client']) && !$_isAgent) {
    if(isset($conn)){
        $edit_id             = intval($_POST['edit_client_id'] ?? 0);
        $assigned_agents_raw = $_POST['edit_assigned_agents'] ?? [];

        if ($_currentRole === 'manager') {
            // manager শুধু assigned_agents update করতে পারবে
            $assigned_agents_val = !empty($assigned_agents_raw)
                ? "'".mysqli_real_escape_string($conn, implode(',', $assigned_agents_raw))."'"
                : "NULL";
            $sql = "UPDATE contacts SET assigned_agents=$assigned_agents_val WHERE id=$edit_id";
        } else {
            // super_admin / admin সব field update করতে পারবে
            $client_name        = mysqli_real_escape_string($conn, $_POST['edit_client_name'] ?? '');
            $client_email       = mysqli_real_escape_string($conn, $_POST['edit_client_email'] ?? '');
            $client_phone       = mysqli_real_escape_string($conn, $_POST['edit_client_phone'] ?? '');
            $client_designation = mysqli_real_escape_string($conn, $_POST['edit_client_designation'] ?? '');
            $fb_url             = mysqli_real_escape_string($conn, $_POST['edit_fb_url'] ?? '');
            $linkedin_url       = mysqli_real_escape_string($conn, $_POST['edit_linkedin_url'] ?? '');
            $twitter_url        = mysqli_real_escape_string($conn, $_POST['edit_twitter_url'] ?? '');
            $insta_url          = mysqli_real_escape_string($conn, $_POST['edit_insta_url'] ?? '');
            $company_id_edit    = $_POST['edit_company_id'] ?? '';
            $comp_edit_val      = !empty($company_id_edit) ? intval($company_id_edit) : 'NULL';

            // ── Company change হলে company-র সব assigned_agents auto-merge ──
            if (!empty($company_id_edit)) {
                $compAgRes2 = mysqli_query($conn, "SELECT assigned_agent FROM companies WHERE id=" . intval($company_id_edit) . " LIMIT 1");
                if ($compAgRes2) {
                    $compAgRow2 = mysqli_fetch_assoc($compAgRes2);
                    $compAgRaw2 = trim($compAgRow2['assigned_agent'] ?? '');
                    if ($compAgRaw2 && $compAgRaw2 !== 'Unassigned') {
                        foreach (array_filter(array_map('trim', explode(',', $compAgRaw2))) as $_cag2) {
                            if ($_cag2 && !in_array($_cag2, $assigned_agents_raw)) {
                                $assigned_agents_raw[] = $_cag2;
                            }
                        }
                    }
                }
            }

            $assigned_agents_val = !empty($assigned_agents_raw)
                ? "'".mysqli_real_escape_string($conn, implode(',', $assigned_agents_raw))."'"
                : "NULL";

            $sql = "UPDATE contacts SET
                name='$client_name', email='$client_email', phone='$client_phone',
                designation='$client_designation', company_id=$comp_edit_val,
                assigned_agents=$assigned_agents_val,
                fb_url='$fb_url', linkedin_url='$linkedin_url',
                twitter_url='$twitter_url', insta_url='$insta_url'
                WHERE id=$edit_id";
        }
        if(mysqli_query($conn, $sql)){
            $toastMessage = "Client updated successfully!";
            $toastType = "success";
        } else {
            $toastMessage = "Error updating client!";
            $toastType = "error";
        }
    }
}

// DELETE is disabled for all roles — use active/inactive toggle instead
// if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_client']) ...) { ... }

// ── Active / Inactive toggle (super_admin & admin only) ──
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['toggle_client_status'])) {
    if (isset($conn) && in_array($_currentRole, ['super_admin', 'admin'])) {
        $tog_id     = intval($_POST['toggle_client_id'] ?? 0);
        $tog_status = ($_POST['toggle_client_new_status'] ?? '') === 'active' ? 'active' : 'inactive';
        // Ensure status columns exist
        $_st_cols = []; $_st_cr = mysqli_query($conn, "SHOW COLUMNS FROM contacts");
        if ($_st_cr) { while ($_sc = mysqli_fetch_assoc($_st_cr)) $_st_cols[] = $_sc['Field']; }
        if (!in_array('status',               $_st_cols)) mysqli_query($conn, "ALTER TABLE contacts ADD COLUMN status VARCHAR(10) NOT NULL DEFAULT 'active'");
        if (!in_array('inactive_by',          $_st_cols)) mysqli_query($conn, "ALTER TABLE contacts ADD COLUMN inactive_by INT DEFAULT NULL");
        if (!in_array('inactive_by_role',     $_st_cols)) mysqli_query($conn, "ALTER TABLE contacts ADD COLUMN inactive_by_role VARCHAR(20) DEFAULT NULL");
        if (!in_array('company_inactive_ref', $_st_cols)) mysqli_query($conn, "ALTER TABLE contacts ADD COLUMN company_inactive_ref INT DEFAULT NULL");

        $tog_user_id = intval($_SESSION['user_id'] ?? 0);
        if ($tog_status === 'inactive') {
            // manually inactive — company_inactive_ref NULL রাখো (company-র কারণে নয়)
            $sql = "UPDATE contacts SET status='inactive', inactive_by=$tog_user_id, inactive_by_role='$_currentRole', company_inactive_ref=NULL WHERE id=$tog_id";
        } else {
            // manually active করার সময় সব clear করো
            $sql = "UPDATE contacts SET status='active', inactive_by=NULL, inactive_by_role=NULL, company_inactive_ref=NULL WHERE id=$tog_id";
        }
        if (mysqli_query($conn, $sql)) {
            $toastMessage = "Client marked as " . ucfirst($tog_status) . "!";
            $toastType    = "success";
        } else {
            $toastMessage = "Error updating status!";
            $toastType    = "error";
        }
    } else {
        $toastMessage = "Permission denied!";
        $toastType    = "error";
    }
}

// ========================================================================
// 2b. BULK EDIT TEMPLATE DOWNLOAD
// ========================================================================
if (isset($_GET['download_client_edit_template'])) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php"); exit();
    }
    if (($_SESSION['role'] ?? '') === 'agent') {
        header("Location: client_list.php"); exit();
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="client_bulk_edit_template.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen("php://output", "w");
    fputcsv($out, ['id','name','email','phone','designation','company_id','assigned_agents','fb_url','linkedin_url','twitter_url','insta_url']);
    if (isset($conn)) {
        $ex = mysqli_query($conn, "SELECT c.id, c.name, c.email, c.phone, c.designation, c.company_id, c.assigned_agents, c.fb_url, c.linkedin_url, c.twitter_url, c.insta_url FROM contacts c ORDER BY c.id ASC LIMIT 3");
        if ($ex) while ($r = mysqli_fetch_assoc($ex)) {
            fputcsv($out, [
                $r['id'], $r['name'], $r['email']??'', $r['phone']??'',
                $r['designation']??'', $r['company_id']??'', $r['assigned_agents']??'',
                $r['fb_url']??'', $r['linkedin_url']??'', $r['twitter_url']??'', $r['insta_url']??''
            ]);
        }
    } else {
        fputcsv($out, [1,'John Doe','john@example.com','01700000000','Manager','5','admin,agent','','','','']);
        fputcsv($out, [2,'Jane Smith','jane@example.com','01800000000','CEO','4','admin','','','','']);
    }
    fclose($out);
    exit();
}

// ========================================================================
// 2c. BULK EDIT CSV HANDLER
// ========================================================================
$clientBulkEditResults = [];
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_edit_clients_csv']) && !$_isAgent && $_currentRole !== 'manager') {
    if (isset($conn) && isset($_FILES['client_edit_csv']) && $_FILES['client_edit_csv']['error'] == 0) {
        // Ensure columns exist
        $_cols=[]; $_cr=mysqli_query($conn,"SHOW COLUMNS FROM contacts");
        if($_cr){while($_c=mysqli_fetch_assoc($_cr))$_cols[]=$_c['Field'];}
        foreach(['assigned_agents TEXT','fb_url VARCHAR(255)','linkedin_url VARCHAR(255)','twitter_url VARCHAR(255)','insta_url VARCHAR(255)'] as $colDef){
            $colName = explode(' ',$colDef)[0];
            if(!in_array($colName,$_cols)) mysqli_query($conn,"ALTER TABLE contacts ADD COLUMN $colDef DEFAULT NULL");
        }

        $handle = fopen($_FILES['client_edit_csv']['tmp_name'], "r");
        $updated = 0; $skipped = 0;
        $notFound = []; $berrors = [];
        try {
            $first = true;
            while (($data = fgetcsv($handle, 2000, ",")) !== FALSE) {
                if ($first) { $first = false; continue; }
                $id = (int)trim($data[0] ?? 0);
                if ($id <= 0) { $skipped++; continue; }

                $n   = mysqli_real_escape_string($conn, trim($data[1] ?? ''));
                $e   = mysqli_real_escape_string($conn, trim($data[2] ?? ''));
                $p   = mysqli_real_escape_string($conn, trim($data[3] ?? ''));
                $d   = mysqli_real_escape_string($conn, trim($data[4] ?? ''));
                $cid = trim($data[5] ?? '');
                $cid_val = (!empty($cid) && is_numeric($cid)) ? (int)$cid : 'NULL';
                $ag  = mysqli_real_escape_string($conn, trim($data[6] ?? ''));
                $ag_val = !empty($ag) ? "'$ag'" : 'NULL';
                $fb  = mysqli_real_escape_string($conn, trim($data[7] ?? ''));
                $li  = mysqli_real_escape_string($conn, trim($data[8] ?? ''));
                $tw  = mysqli_real_escape_string($conn, trim($data[9] ?? ''));
                $ig  = mysqli_real_escape_string($conn, trim($data[10] ?? ''));

                if (empty($n)) { $skipped++; continue; }

                $chk = mysqli_query($conn, "SELECT id FROM contacts WHERE id=$id LIMIT 1");
                if (!$chk || mysqli_num_rows($chk) === 0) {
                    $notFound[] = "ID $id";
                    $skipped++; continue;
                }

                $sql = "UPDATE contacts SET name='$n', email='$e', phone='$p', designation='$d',
                        company_id=$cid_val, assigned_agents=$ag_val,
                        fb_url='$fb', linkedin_url='$li', twitter_url='$tw', insta_url='$ig'
                        WHERE id=$id";
                if (mysqli_query($conn, $sql)) {
                    $updated++;
                } else {
                    $berrors[] = "ID $id: " . mysqli_error($conn);
                    $skipped++;
                }
            }
            fclose($handle);
            $clientBulkEditResults = [
                'updated'  => $updated,
                'skipped'  => $skipped,
                'notFound' => $notFound,
                'errors'   => $berrors,
            ];
        } catch (mysqli_sql_exception $e) {
            $toastMessage = "Bulk Edit Failed!"; $toastType = "error";
        }
    } else {
        $toastMessage = "Please select a valid CSV file."; $toastType = "error";
    }
}

// ========================================================================
// 3. FETCH DATA FOR UI
// ========================================================================
$companyOptions = "";
if(isset($conn)){
    try {
        $_compRole     = $_SESSION['role']     ?? '';
        $_compUserId   = intval($_SESSION['user_id'] ?? 0);
        $_compName     = mysqli_real_escape_string($conn, $_SESSION['name']     ?? '');
        $_compUsername = mysqli_real_escape_string($conn, $_SESSION['username'] ?? '');

        if ($_compRole === 'super_admin') {
            $comp_drp_query = mysqli_query($conn, "SELECT id, company_name FROM companies WHERE (status='active' OR status IS NULL) ORDER BY company_name ASC");
        } elseif ($_compRole === 'admin') {
            // নিজের create করা + sub-user-দের create করা + নিজেকে assigned + created_by NULL (পুরনো data)
            $_adSubIds2 = [$_compUserId];
            $_adSubQ2 = mysqli_query($conn, "SELECT id FROM users WHERE status='active'
                AND role IN ('manager','agent')
                AND (reporting_to='$_compUsername' OR reporting_to='$_compName'
                     OR manager_id=$_compUserId OR parent_id=$_compUserId
                     OR created_by=$_compUserId)");
            if ($_adSubQ2) { while ($_adSubR2 = mysqli_fetch_assoc($_adSubQ2)) $_adSubIds2[] = (int)$_adSubR2['id']; }
            $_adSubIdsStr2 = implode(',', $_adSubIds2);
            $comp_drp_query = mysqli_query($conn, "SELECT id, company_name FROM companies WHERE (status='active' OR status IS NULL) AND (
                created_by IN ($_adSubIdsStr2)
                OR created_by IS NULL
                OR assigned_agent LIKE '%$_compName%'
                OR assigned_agent LIKE '%$_compUsername%'
            ) ORDER BY company_name ASC");
        } elseif ($_compRole === 'manager') {
            $_mgrParentAdminId = 0;
            $_mgrParQ = mysqli_query($conn, "SELECT reporting_to, created_by FROM users WHERE id = $_compUserId LIMIT 1");
            if ($_mgrParQ && $_mgrPRow = mysqli_fetch_assoc($_mgrParQ)) {
                $_mgrRepTo = mysqli_real_escape_string($conn, $_mgrPRow['reporting_to'] ?? '');
                $_mgrCb    = intval($_mgrPRow['created_by'] ?? 0);
                if (!empty($_mgrRepTo)) {
                    $_mgrAQ = mysqli_query($conn, "SELECT id FROM users WHERE (username='$_mgrRepTo' OR name='$_mgrRepTo') AND role='admin' LIMIT 1");
                    if ($_mgrAQ && $_mgrAR = mysqli_fetch_assoc($_mgrAQ)) $_mgrParentAdminId = (int)$_mgrAR['id'];
                }
                if ($_mgrParentAdminId == 0 && $_mgrCb > 0) {
                    $_mgrAQ2 = mysqli_query($conn, "SELECT id FROM users WHERE id=$_mgrCb AND role='admin' LIMIT 1");
                    if ($_mgrAQ2 && $_mgrAR2 = mysqli_fetch_assoc($_mgrAQ2)) $_mgrParentAdminId = (int)$_mgrAR2['id'];
                }
            }
            $_mgrCreatedByIds = $_mgrParentAdminId > 0 ? "$_compUserId,$_mgrParentAdminId" : "$_compUserId";
            $comp_drp_query = mysqli_query($conn, "SELECT id, company_name FROM companies WHERE (status='active' OR status IS NULL) AND (
                created_by IN ($_mgrCreatedByIds)
                OR assigned_agent LIKE '%$_compName%'
                OR assigned_agent LIKE '%$_compUsername%'
            ) ORDER BY company_name ASC");
        } elseif ($_compRole === 'agent') {
            $comp_drp_query = mysqli_query($conn, "SELECT id, company_name FROM companies WHERE (status='active' OR status IS NULL) AND (
                assigned_agent LIKE '%$_compName%'
                OR assigned_agent LIKE '%$_compUsername%'
            ) ORDER BY company_name ASC");
        } else {
            $comp_drp_query = false;
        }

        if($comp_drp_query && mysqli_num_rows($comp_drp_query) > 0){
            while($cRow = mysqli_fetch_assoc($comp_drp_query)){
                $companyOptions .= "<option value='{$cRow['id']}'>" . htmlspecialchars($cRow['company_name']) . "</option>";
            }
        }
    } catch (mysqli_sql_exception $e) {}
}

// Ensure assigned_agents column exists in contacts
if(isset($conn)){
    $cols = []; $cr = mysqli_query($conn, "SHOW COLUMNS FROM contacts");
    if($cr){ while($c = mysqli_fetch_assoc($cr)) $cols[] = $c['Field']; }
    if(!in_array('assigned_agents', $cols)){
        mysqli_query($conn, "ALTER TABLE contacts ADD COLUMN assigned_agents TEXT DEFAULT NULL");
    }
}

// Fetch agents for dropdown — role-based
$agentOptions = "";
if(isset($conn)){
    try {
        $_agCallerRole     = $_SESSION['role']     ?? '';
        $_agCallerId       = intval($_SESSION['user_id'] ?? 0);
        $_agCallerUsername = mysqli_real_escape_string($conn, $_SESSION['username'] ?? '');

        $_agCallerName = mysqli_real_escape_string($conn, $_SESSION['name'] ?? '');

        if ($_agCallerRole === 'super_admin') {
            $ag_query = mysqli_query($conn, "SELECT username, name, role FROM users WHERE role IN ('agent','manager','admin') AND status='active' ORDER BY role DESC, name ASC");
        } elseif ($_agCallerRole === 'admin') {
            // admin → নিজের direct manager/agent + সেই managers এর under এর agents
            $_agManagerNames = [];
            $_agMQ = mysqli_query($conn, "SELECT name, username FROM users WHERE role='manager' AND status='active'
                AND (reporting_to='$_agCallerUsername' OR reporting_to='$_agCallerName')");
            if ($_agMQ) {
                while ($_agMR = mysqli_fetch_assoc($_agMQ)) {
                    $_agManagerNames[] = "'" . mysqli_real_escape_string($conn, $_agMR['name'])     . "'";
                    $_agManagerNames[] = "'" . mysqli_real_escape_string($conn, $_agMR['username']) . "'";
                }
            }
            $_agMNStr = !empty($_agManagerNames) ? implode(',', $_agManagerNames) : "''";
            $ag_query = mysqli_query($conn, "SELECT username, name, role FROM users WHERE status='active' AND (
                (role IN ('manager','agent') AND (reporting_to='$_agCallerUsername' OR reporting_to='$_agCallerName'))
                OR (role = 'agent' AND reporting_to IN ($_agMNStr))
            ) ORDER BY role DESC, name ASC");
        } elseif ($_agCallerRole === 'manager') {
            $ag_query = mysqli_query($conn, "SELECT username, name, role FROM users
                WHERE status='active' AND role = 'agent'
                  AND (reporting_to = '$_agCallerUsername' OR reporting_to = '$_agCallerName')
                ORDER BY name ASC");
        } else {
            $ag_query = false;
        }

        if($ag_query && mysqli_num_rows($ag_query) > 0){
            while($aRow = mysqli_fetch_assoc($ag_query)){
                $agentOptions .= "<option value='" . htmlspecialchars($aRow['name']) . "'>" . htmlspecialchars($aRow['name']) . " (" . htmlspecialchars($aRow['username']) . ")</option>";
            }
        }
    } catch (mysqli_sql_exception $e) {}
}

// Fetch designations for dropdown
$designationOptions = "";
if(isset($conn)){
    try {
        $desig_query = mysqli_query($conn, "SELECT id, title FROM designations ORDER BY title ASC");
        if($desig_query && mysqli_num_rows($desig_query) > 0){
            while($dRow = mysqli_fetch_assoc($desig_query)){
                $designationOptions .= "<option value='".htmlspecialchars($dRow['title'])."'>".htmlspecialchars($dRow['title'])."</option>";
            }
        }
    } catch (mysqli_sql_exception $e) {}
}

$hasClients = false;
$clientTableRows = "";
$totalClients = "0";

if(isset($conn)){
    // ── Ensure created_by column exists in contacts ──
    $_ct_cols = []; $_ct_cr = mysqli_query($conn, "SHOW COLUMNS FROM contacts");
    if ($_ct_cr) { while ($_cc = mysqli_fetch_assoc($_ct_cr)) $_ct_cols[] = $_cc['Field']; }
    if (!in_array('created_by',           $_ct_cols)) mysqli_query($conn, "ALTER TABLE contacts ADD COLUMN created_by INT DEFAULT NULL");
    if (!in_array('status',               $_ct_cols)) mysqli_query($conn, "ALTER TABLE contacts ADD COLUMN status VARCHAR(10) NOT NULL DEFAULT 'active'");
    if (!in_array('inactive_by',          $_ct_cols)) mysqli_query($conn, "ALTER TABLE contacts ADD COLUMN inactive_by INT DEFAULT NULL");
    if (!in_array('inactive_by_role',     $_ct_cols)) mysqli_query($conn, "ALTER TABLE contacts ADD COLUMN inactive_by_role VARCHAR(20) DEFAULT NULL");
    if (!in_array('company_inactive_ref', $_ct_cols)) mysqli_query($conn, "ALTER TABLE contacts ADD COLUMN company_inactive_ref INT DEFAULT NULL");

    try {
        $_currentUserId   = intval($_SESSION['user_id'] ?? 0);
        $_escapedUsername = mysqli_real_escape_string($conn, $_currentUsername);
        $_escapedName     = mysqli_real_escape_string($conn, $_currentName);

        // ── Inactive visibility logic ──
        // superadmin → সব দেখবে (active + যেকোনো inactive)
        // admin inactive করলে → শুধু ঐ admin + superadmin দেখবে
        // active record → role অনুযায়ী স্বাভাবিক নিয়মে দেখাবে

        if ($_currentRole === 'super_admin') {
            // superadmin সব দেখবে
            $_clientWhere = "";

        } elseif ($_currentRole === 'admin') {
            // admin দেখবে:
            // 1. নিজের create করা clients
            // 2. নিজের under-এর manager/agent-দের create করা clients
            $_inactiveFilter = "(contacts.status = 'active' OR (contacts.status = 'inactive' AND contacts.inactive_by = $_currentUserId))";

            // এই admin-এর under-এর সব manager ও agent-এর id collect করো
            $_subUserIds = [$_currentUserId];
            $_subUQ = mysqli_query($conn, "SELECT id FROM users WHERE status='active'
                AND role IN ('manager','agent')
                AND (reporting_to='$_escapedUsername' OR reporting_to='$_escapedName'
                     OR created_by=$_currentUserId)");
            if ($_subUQ) {
                while ($_subUR = mysqli_fetch_assoc($_subUQ)) {
                    $_subUserIds[] = (int)$_subUR['id'];
                }
            }
            $_subIdsStr = implode(',', $_subUserIds);
            $_clientWhere = "WHERE contacts.created_by IN ($_subIdsStr) AND $_inactiveFilter";

        } elseif ($_currentRole === 'manager') {
            // manager দেখবে:
            // 1. নিজের create করা clients
            // 2. parent admin এর create করা clients
            // 3. যেসব client এ manager কে assigned_agents এ assign করা হয়েছে (admin assign করলেও)
            $_mParentAdminId = 0;
            // reporting_to দিয়ে parent admin খোঁজো (parent_id column নাও থাকতে পারে তাই আলাদা করা হয়েছে)
            $_mParQ = mysqli_query($conn, "SELECT reporting_to FROM users WHERE id = $_currentUserId LIMIT 1");
            if ($_mParQ && $_mpRow = mysqli_fetch_assoc($_mParQ)) {
                $_mRepTo = mysqli_real_escape_string($conn, $_mpRow['reporting_to'] ?? '');
                if (!empty($_mRepTo)) {
                    $_mAQ = mysqli_query($conn, "SELECT id FROM users WHERE (username='$_mRepTo' OR name='$_mRepTo') AND role IN ('admin','super_admin') LIMIT 1");
                    if ($_mAQ && $_mAR = mysqli_fetch_assoc($_mAQ)) $_mParentAdminId = (int)$_mAR['id'];
                }
            }
            // parent_id column থাকলে সেটাও try করো
            $_mPidQ = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'parent_id'");
            if ($_mPidQ && mysqli_num_rows($_mPidQ) > 0) {
                $_mParQ2 = mysqli_query($conn, "SELECT parent_id FROM users WHERE id = $_currentUserId LIMIT 1");
                if ($_mParQ2 && $_mpRow2 = mysqli_fetch_assoc($_mParQ2)) {
                    $_mPid = intval($_mpRow2['parent_id'] ?? 0);
                    if ($_mParentAdminId == 0 && $_mPid > 0) {
                        $_mAQ2 = mysqli_query($conn, "SELECT id FROM users WHERE id=$_mPid AND role IN ('admin','super_admin') LIMIT 1");
                        if ($_mAQ2 && $_mAR2 = mysqli_fetch_assoc($_mAQ2)) $_mParentAdminId = (int)$_mAR2['id'];
                    }
                }
            }
            $_mVisIds = $_currentUserId . ($_mParentAdminId > 0 ? ",$_mParentAdminId" : "");
            // ── Admin assign করলেও manager দেখতে পাবে: FIND_IN_SET দিয়ে name ও username দুটোই check ──
            $_clientWhere = "WHERE (
                contacts.created_by IN ($_mVisIds)
                OR FIND_IN_SET('$_escapedName',     contacts.assigned_agents)
                OR FIND_IN_SET('$_escapedUsername', contacts.assigned_agents)
            ) AND contacts.status = 'active'";

        } elseif ($_currentRole === 'agent') {
            // agent শুধু সেই clients দেখবে যেগুলোতে তাকে assigned_agents এ assign করা হয়েছে
            // FIND_IN_SET দিয়ে exact comma-aware match
            $_clientWhere = "WHERE (
                FIND_IN_SET('$_escapedName',     contacts.assigned_agents)
                OR FIND_IN_SET('$_escapedUsername', contacts.assigned_agents)
            ) AND contacts.status = 'active'";

        } else {
            $_clientWhere = "WHERE contacts.status = 'active'";
        }

        $client_query_str = "SELECT contacts.*, companies.company_name,
                (SELECT note FROM client_notes WHERE client_id = contacts.id ORDER BY created_at DESC LIMIT 1) AS last_note,
                (SELECT created_at FROM client_notes WHERE client_id = contacts.id ORDER BY created_at DESC LIMIT 1) AS last_note_date
            FROM contacts
            LEFT JOIN companies ON contacts.company_id = companies.id
            $_clientWhere
            ORDER BY contacts.id DESC
        ";
        $client_query = mysqli_query($conn, $client_query_str);
        if($client_query && mysqli_num_rows($client_query) > 0){
            $hasClients = true;
            $totalClients = mysqli_num_rows($client_query);
            
            while($row = mysqli_fetch_assoc($client_query)){
                $cl_name        = htmlspecialchars($row['name']);
                $cl_email       = htmlspecialchars($row['email'] ?? '');
                $cl_phone       = htmlspecialchars($row['phone'] ?? '');
                $cl_designation = htmlspecialchars($row['designation'] ?? '');
                $cl_company     = htmlspecialchars($row['company_name'] ?? 'N/A');
                $cl_agents_raw  = $row['assigned_agents'] ?? '';
                $cl_date        = !empty($row['created_at']) ? date('d M Y', strtotime($row['created_at'])) : '—';
                $cl_id          = $row['id'];
                $cl_status      = $row['status'] ?? 'active'; // active or inactive

                // Agent badges
                $agent_badges = '';
                if (!empty($cl_agents_raw)) {
                    foreach (explode(',', $cl_agents_raw) as $ag) {
                        $ag = trim($ag);
                        if ($ag) $agent_badges .= "<span class='agent-badge'>{$ag}</span>";
                    }
                } else {
                    $agent_badges = "<span style='color:#9ca3af;font-size:11px;'>—</span>";
                }

                $email_html = !empty($cl_email) ? "<a href='mailto:{$cl_email}' style='color:#3b82f6;text-decoration:none;'>{$cl_email}</a>" : "<span style='color:#9ca3af;'>—</span>";
                $phone_html = !empty($cl_phone) ? "<a href='tel:{$cl_phone}' style='color:#374151;text-decoration:none;'>{$cl_phone}</a>" : "<span style='color:#9ca3af;'>—</span>";
                $desig_html = !empty($cl_designation) ? "<span class='desig-badge'>{$cl_designation}</span>" : "<span style='color:#9ca3af;'>—</span>";

                $cl_last_note      = $row['last_note'] ?? '';
                $cl_last_note_date = $row['last_note_date'] ?? '';

                // Last conversation html
                if (!empty($cl_last_note)) {
                    $nl_date = !empty($cl_last_note_date) ? date('d M Y', strtotime($cl_last_note_date)) : '';
                    $nl_text = htmlspecialchars(mb_strimwidth($cl_last_note, 0, 55, '…'));
                    $last_conv_html = "<div class='last-conv-wrap'><span class='last-conv-note'>{$nl_text}</span>" . ($nl_date ? "<span class='last-conv-date'>{$nl_date}</span>" : "") . "</div>";
                } else {
                    $last_conv_html = "<span style='color:#9ca3af;font-size:11px;'>—</span>";
                }

                $clientTableRows .= "<tr class='client-row' data-status='{$cl_status}'>
                    <td style='text-align:center;color:#6b7280;font-weight:600;'>#{$cl_id}</td>
                    <td style='text-align:left;'>
                        <a href='client_profile.php?id={$cl_id}' class='client-name-link'>
                            <span class='client-name-avatar'>" . strtoupper(substr($row['name'], 0, 1)) . "</span>
                            <span class='client-name-text'>{$cl_name}</span>
                            <i class='fa-solid fa-arrow-up-right-from-square client-name-icon'></i>
                        </a>
                    </td>
                    <td>{$email_html}</td>
                    <td>{$desig_html}</td>
                    <td><span class='comp-contacts-pill'>{$cl_company}</span></td>
                    <td><div class='agent-badges-wrap'>{$agent_badges}</div></td>
                    <td>{$last_conv_html}</td>
                    <td>
                        <div class='action-btns'>
                            <a href='client_profile.php?id={$cl_id}' class='btn-view' title='View Profile' style='display:inline-flex;align-items:center;justify-content:center;'><i class='fa-regular fa-eye'></i></a>"
                            // Edit button: only super_admin and admin (not manager, not agent)
                            . (in_array($_currentRole, ['super_admin', 'admin', 'manager']) ? "
                            <button type='button' class='btn-edit' title='Edit Client'
                                onclick='openEditModal({$cl_id},
                                    " . json_encode($row['name']) . ",
                                    " . json_encode($row['email'] ?? '') . ",
                                    " . json_encode($row['phone'] ?? '') . ",
                                    " . json_encode($row['designation'] ?? '') . ",
                                    " . json_encode((string)($row['company_id'] ?? '')) . ",
                                    " . json_encode($row['assigned_agents'] ?? '') . ",
                                    " . json_encode($row['fb_url'] ?? '') . ",
                                    " . json_encode($row['linkedin_url'] ?? '') . ",
                                    " . json_encode($row['twitter_url'] ?? '') . ",
                                    " . json_encode($row['insta_url'] ?? '') . "
                                )'><i class='fa-solid fa-pen'></i></button>" : "") . "
                            " // Active / Inactive toggle: only super_admin and admin
                            . (in_array($_currentRole, ['super_admin', 'admin']) ? "
                            <form method='POST' id='toggle-client-{$cl_id}' style='display:inline;'>
                                <input type='hidden' name='toggle_client_id' value='{$cl_id}'>
                                <input type='hidden' name='toggle_client_status' value='1'>
                                <input type='hidden' name='toggle_client_new_status' value='" . (($row['status'] ?? 'active') === 'active' ? 'inactive' : 'active') . "'>
                                <button type='button'
                                    class='" . (($row['status'] ?? 'active') === 'active' ? 'btn-status-active' : 'btn-status-inactive') . "'
                                    title='" . (($row['status'] ?? 'active') === 'active' ? 'Mark Inactive' : 'Mark Active') . "'
                                    onclick='confirmToggleClientStatus(\"toggle-client-{$cl_id}\", \"" . (($row['status'] ?? 'active') === 'active' ? 'inactive' : 'active') . "\")'>
                                    <i class='fa-solid " . (($row['status'] ?? 'active') === 'active' ? 'fa-toggle-on' : 'fa-toggle-off') . "'></i>
                                </button>
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
    <title>Accounts & Clients - Systellio CRM</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background-color: #f3f4f6; display: flex; height: 100vh; overflow: hidden; transition: background-color 0.3s, color 0.3s; color: #111827; }
        
        #toastBox { visibility: hidden; min-width: 250px; background-color: #333; color: #fff; text-align: center; border-radius: 8px; padding: 16px; position: fixed; z-index: 9999; right: 30px; top: 30px; font-size: 14px; font-weight: 600; box-shadow: 0 4px 12px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 10px; transform: translateX(100%); transition: transform 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55), visibility 0.4s; }
        #toastBox.show { visibility: visible; transform: translateX(0); }
        #toastBox.success { background-color: #10b981; }
        #toastBox.error { background-color: #ef4444; }

        /* Sidebar CSS → see sidebar.php */
        .main-content { flex-grow: 1; display: flex; flex-direction: column; overflow-y: auto; transition: background-color 0.3s ease; background-color: #f3f4f6; }
        
        
        
        
        .nav-icon-btn:hover { color: #3b82f6; }
        .notification-badge { position: absolute; top: -4px; right: -4px; background-color: #ef4444; color: white; font-size: 9px; font-weight: bold; padding: 2px 5px; border-radius: 50%; border: 2px solid #ffffff; }
        
        .user-profile i { font-size: 24px; color: #3b82f6; }

        .company-container { padding: 30px; display: block; }
        .comp-header-title h1 { font-size: 26px; font-weight: 800; margin-bottom: 4px; letter-spacing: -0.5px; transition: 0.3s; color: #111827;}
        .comp-header-title p { font-size: 13px; color: #6b7280; font-weight: 500; }

        .user-list-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .header-buttons { display: flex; gap: 10px; }
        .btn-add-client {
            background-color: #0f172a;
            color: #ffffff;
            padding: 10px 18px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 700;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.12);
            transition: background-color 0.2s, transform 0.1s;
        }
        .btn-add-client:hover { background-color: #1e293b; transform: translateY(-1px); }
        .btn-add-client i { font-size: 13px; }
        .btn-export {
            background-color: #16a34a; color: #ffffff; padding: 10px 18px;
            border-radius: 6px; font-size: 13px; font-weight: 700; border: none;
            cursor: pointer; display: flex; align-items: center; gap: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.12); transition: background-color 0.2s, transform 0.1s;
        }
        .btn-export:hover { background-color: #15803d; transform: translateY(-1px); }
        .btn-bulk {
            background-color: #1e293b; color: #ffffff; padding: 10px 18px;
            border-radius: 6px; font-size: 13px; font-weight: 700; border: none;
            cursor: pointer; display: flex; align-items: center; gap: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.12); transition: background-color 0.2s, transform 0.1s;
        }
        .btn-bulk:hover { background-color: #334155; transform: translateY(-1px); }

        .comp-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;}
        .comp-search { position: relative; width: 300px; }
        .comp-search i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #9ca3af; font-size: 14px;}
        .comp-search input { width: 100%; padding: 10px 15px 10px 38px; border: 1px solid #d1d5db; border-radius: 20px; font-size: 13px; font-family: 'Inter', sans-serif; outline: none; transition: 0.3s; color: #374151;}
        .comp-total { font-size: 13px; font-weight: 600; color: #4b5563; background: #ffffff; border: 1px solid #d1d5db; padding: 8px 15px; border-radius: 20px;}

        /* Tabs */
        .tab-container { display: flex; gap: 25px; border-bottom: 1px solid #d1d5db; margin-bottom: 18px; transition: 0.3s; }
        .tab-btn { padding: 10px 5px; font-size: 13px; font-weight: 600; color: #6b7280; cursor: pointer; position: relative; transition: 0.3s; }
        .tab-btn:hover { color: #111827; }
        .tab-btn.active { color: #3b82f6; }
        .tab-btn.active::after { content: ''; position: absolute; bottom: -1px; left: 0; width: 100%; height: 2px; background-color: #3b82f6; }
        body.dark-mode .tab-container { border-color: #334155; }
        body.dark-mode .tab-btn { color: #94a3b8; }
        body.dark-mode .tab-btn:hover { color: #f8fafc; }
        body.dark-mode .tab-btn.active { color: #60a5fa; }
        body.dark-mode .tab-btn.active::after { background: #60a5fa; }

        .table-wrapper { border-radius: 8px; overflow: hidden; border: 1px solid #d1d5db; transition: 0.3s; background: #ffffff;}
        .custom-table { width: 100%; border-collapse: collapse; text-align: center; font-size: 12px; }
        .custom-table th { background-color: #c4f042; padding: 14px 10px; font-weight: 700; color: #000000; border-bottom: 1px solid #d1d5db; transition: 0.3s;}
        .custom-table td { padding: 14px 10px; color: #374151; font-weight: 500; vertical-align: middle; border-right: 1px solid rgba(0,0,0,0.05); transition: 0.3s;}
        .custom-table td:first-child { text-align: left; padding-left: 14px; }
        .custom-table tbody tr:nth-child(4n+1) { background-color: #e6fced; } 
        .custom-table tbody tr:nth-child(4n+2) { background-color: #fcedf6; } 
        .custom-table tbody tr:nth-child(4n+3) { background-color: #fceddb; } 
        .custom-table tbody tr:nth-child(4n+4) { background-color: #e6edff; } 


        /* ── Client Name link — simple ── */
        .client-name-link { text-decoration: none; color: #1d4ed8; font-weight: 600; font-size: 12px; }
        .client-name-link:hover { text-decoration: underline; color: #1e40af; }
        .client-name-avatar { display: none; }
        .client-name-icon  { display: none; }
        body.dark-mode .client-name-link { color: #93c5fd; }
        body.dark-mode .client-name-link:hover { color: #bfdbfe; }

        .comp-contacts-pill { background: #eff6ff; color: #3b82f6; border: 1px solid #bfdbfe; font-size: 12px; font-weight: 600; padding: 4px 12px; border-radius: 20px; display: inline-block;}
        .tbl-checkbox { width: 16px; height: 16px; border: 1px solid #d1d5db; border-radius: 4px; cursor: pointer; accent-color: #3b82f6;}
        .agent-badge { display: inline-block; background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; font-size: 10px; font-weight: 600; padding: 3px 8px; border-radius: 20px; margin: 2px; }
        .agent-badges-wrap { display: flex; flex-wrap: wrap; justify-content: center; gap: 2px; }
        .desig-badge { display: inline-block; background: #fef3c7; color: #b45309; border: 1px solid #fde68a; font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 20px; }
        .action-btns { display: flex; justify-content: center; gap: 6px; }
        .btn-view { background-color: #60a5fa; color: white; padding: 6px 10px; border-radius: 4px; font-size: 11px; border: none; cursor: pointer; transition: 0.3s;}
        .btn-edit { background-color: #f59e0b; color: white; padding: 6px 10px; border-radius: 4px; font-size: 11px; border: none; cursor: pointer; transition: 0.3s;}
        .btn-edit:hover { background-color: #d97706; }
        .btn-status-active { background-color: #10b981; color: white; padding: 6px 10px; border-radius: 4px; font-size: 13px; border: none; cursor: pointer; transition: 0.3s; }
        .btn-status-active:hover { background-color: #059669; }
        .btn-status-inactive { background-color: #9ca3af; color: white; padding: 6px 10px; border-radius: 4px; font-size: 13px; border: none; cursor: pointer; transition: 0.3s; }
        .btn-status-inactive:hover { background-color: #6b7280; }
        .status-badge-active { display:inline-block; background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; }
        .status-badge-inactive { display:inline-block; background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; }

        /* Last Conversation */
        .last-conv-wrap { display: flex; flex-direction: column; align-items: flex-start; gap: 2px; text-align: left; }
        .last-conv-note { font-size: 11px; color: #374151; font-weight: 500; line-height: 1.4; }
        .last-conv-date { font-size: 10px; color: #9ca3af; font-weight: 500; }
        body.dark-mode .last-conv-note { color: #cbd5e1; }
        body.dark-mode .last-conv-date { color: #64748b; }

        /* Custom Agent Multi-Select */
        .custom-multiselect { position: relative; width: 100%; }
        .cms-trigger {
            width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px;
            font-size: 12px; background: #f9fafb; cursor: pointer; display: flex;
            align-items: center; justify-content: space-between; gap: 6px;
            color: #9ca3af; transition: 0.2s; user-select: none; min-height: 36px;
        }
        .cms-trigger:focus, .cms-trigger.open { border-color: #3b82f6; background: #fff; box-shadow: 0 0 0 2px rgba(59,130,246,0.1); }
        .cms-trigger-tags { display: flex; flex-wrap: wrap; gap: 3px; flex: 1; }
        .cms-tag {
            background: #eff6ff; color: #3b82f6; border: 1px solid #bfdbfe;
            font-size: 10px; font-weight: 600; padding: 2px 6px; border-radius: 20px;
            display: inline-flex; align-items: center; gap: 3px;
        }
        .cms-tag-x { cursor: pointer; font-size: 9px; color: #93c5fd; }
        .cms-tag-x:hover { color: #ef4444; }
        .cms-placeholder { font-size: 12px; color: #9ca3af; }
        .cms-arrow { font-size: 10px; color: #9ca3af; flex-shrink: 0; transition: transform 0.2s; }
        .cms-trigger.open .cms-arrow { transform: rotate(180deg); }
        .cms-dropdown {
            display: none; position: absolute; top: calc(100% + 4px); left: 0; right: 0;
            background: #fff; border: 1px solid #d1d5db; border-radius: 8px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.1); z-index: 3000; overflow: hidden;
            max-height: 200px; overflow-y: auto;
        }
        .cms-dropdown.open { display: block; }
        .cms-search-wrap { padding: 8px 10px; border-bottom: 1px solid #f3f4f6; }
        .cms-search-input {
            width: 100%; padding: 5px 8px; border: 1px solid #e5e7eb; border-radius: 5px;
            font-size: 11px; outline: none; background: #f9fafb; color: #374151;
        }
        .cms-option {
            display: flex; align-items: center; gap: 8px; padding: 8px 12px;
            cursor: pointer; font-size: 12px; color: #374151; transition: background 0.15s;
        }
        .cms-option:hover { background: #f0f7ff; }
        .cms-option input[type="checkbox"] { accent-color: #3b82f6; width: 13px; height: 13px; cursor: pointer; flex-shrink: 0; }
        .cms-option.checked { background: #eff6ff; color: #1d4ed8; font-weight: 600; }
        .cms-empty { padding: 10px 12px; font-size: 12px; color: #9ca3af; text-align: center; }
        body.dark-mode .cms-trigger { background: #0f172a; border-color: #334155; color: #64748b; }
        body.dark-mode .cms-trigger.open, body.dark-mode .cms-trigger:focus { background: #1e293b; border-color: #3b82f6; }
        body.dark-mode .cms-dropdown { background: #1e293b; border-color: #334155; box-shadow: 0 8px 20px rgba(0,0,0,0.4); }
        body.dark-mode .cms-search-wrap { border-color: #334155; }
        body.dark-mode .cms-search-input { background: #0f172a; border-color: #334155; color: #f8fafc; }
        body.dark-mode .cms-option { color: #cbd5e1; }
        body.dark-mode .cms-option:hover { background: #0f172a; }
        body.dark-mode .cms-option.checked { background: #1e3a5f; color: #93c5fd; }

        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center; }
        .modal-content { background-color: #fff; padding: 20px 22px; border-radius: 10px; width: 100%; max-width: 500px; box-shadow: 0 10px 25px rgba(0,0,0,0.15); max-height: 90vh; overflow-y: auto;}
        /* Wizard modal — fixed height, no scroll */
        .wizard-modal { overflow: hidden !important; max-height: none !important; }
        .modal-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; }
        .modal-header h2 { font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 2px; }
        .wizard-subtitle { font-size: 11px; color: #9ca3af; font-weight: 500; margin: 0; }
        .close-btn { font-size: 17px; cursor: pointer; color: #6b7280; border: none; background: none; transition: 0.2s; flex-shrink: 0; }
        .close-btn:hover { color: #ef4444; }

        /* Step indicator */
        .wizard-steps {
            display: flex; align-items: center; margin-bottom: 18px;
            background: #f8faff; border-radius: 10px; padding: 12px 16px;
            gap: 0;
        }
        .wstep { display: flex; align-items: center; gap: 8px; flex: 1; }
        .wstep-circle {
            width: 26px; height: 26px; border-radius: 50%;
            background: #e5e7eb; color: #9ca3af;
            font-size: 11px; font-weight: 800;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; transition: .25s;
        }
        .wstep-label { font-size: 11px; font-weight: 600; color: #9ca3af; transition: .25s; }
        .wstep.active .wstep-circle { background: #3b82f6; color: #fff; box-shadow: 0 2px 8px rgba(59,130,246,.35); }
        .wstep.active .wstep-label { color: #1d4ed8; font-weight: 700; }
        .wstep.done .wstep-circle { background: #10b981; color: #fff; }
        .wstep.done .wstep-label { color: #059669; }
        .wstep-line { flex: 1; height: 2px; background: #e5e7eb; border-radius: 2px; margin: 0 10px; transition: background .3s; }
        .wstep-line.done { background: #10b981; }

        /* Wizard panels */
        .wizard-panel { animation: wFadeIn .2s ease; }
        @keyframes wFadeIn { from { opacity:0; transform:translateX(12px); } to { opacity:1; transform:translateX(0); } }

        /* Wizard footer buttons */
        .wizard-footer {
            display: flex; justify-content: space-between; align-items: center;
            margin-top: 16px; padding-top: 14px; border-top: 1px solid #f3f4f6;
        }
        .wizard-btn-next {
            background: #3b82f6; color: #fff; border: none;
            padding: 10px 22px; border-radius: 7px; font-size: 13px;
            font-weight: 700; cursor: pointer; display: flex; align-items: center;
            gap: 8px; transition: .2s; margin-left: auto;
        }
        .wizard-btn-next:hover { background: #2563eb; transform: translateY(-1px); }
        .wizard-btn-back {
            background: #f3f4f6; color: #374151; border: none;
            padding: 10px 18px; border-radius: 7px; font-size: 13px;
            font-weight: 700; cursor: pointer; display: flex; align-items: center;
            gap: 8px; transition: .2s;
        }
        .wizard-btn-back:hover { background: #e5e7eb; }
        .wizard-btn-save {
            background: #0f172a; color: #fff; border: none;
            padding: 10px 22px; border-radius: 7px; font-size: 13px;
            font-weight: 700; cursor: pointer; display: flex; align-items: center;
            gap: 8px; transition: .2s;
        }
        .wizard-btn-save:hover { background: #1e293b; transform: translateY(-1px); }
        /* dark mode wizard */
        body.dark-mode .wizard-steps { background: #1a2340; }
        body.dark-mode .wstep-circle { background: #334155; color: #64748b; }
        body.dark-mode .wstep-label { color: #64748b; }
        body.dark-mode .wstep-line { background: #334155; }
        body.dark-mode .wizard-footer { border-color: #334155; }
        body.dark-mode .wizard-btn-back { background: #1e293b; color: #cbd5e1; }
        body.dark-mode .wizard-btn-back:hover { background: #334155; }
        body.dark-mode .modal-header h2 { color: #f8fafc; }
        
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .form-group { margin-bottom: 0; }
        .full-width { grid-column: span 2; }
        .form-group label { display: block; font-size: 11px; font-weight: 700; color: #374151; margin-bottom: 4px; }
        .form-group input, .form-group select { width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 12px; outline: none; background-color: #f9fafb; transition: 0.2s; color: #111827; font-family: 'Inter', sans-serif; }
        .form-group input:focus, .form-group select:focus { border-color: #3b82f6; background-color: #fff; box-shadow: 0 0 0 2px rgba(59,130,246,0.1); }
        .form-group input::placeholder { color: #9ca3af; }

        /* ── Social Media Section ── */
        .social-section {
            margin-top: 14px;
            background: #f8faff;
            border: 1px solid #e0e7ff;
            border-radius: 10px;
            padding: 14px 16px;
        }
        .social-section-label {
            font-size: 11px; font-weight: 800; color: #4f46e5;
            text-transform: uppercase; letter-spacing: .7px;
            display: flex; align-items: center; gap: 7px;
            margin-bottom: 12px;
        }
        .social-section-label i { font-size: 13px; }
        .social-section-label span { color: #9ca3af; font-weight: 500; text-transform: none; letter-spacing: 0; font-size: 11px; }
        .social-inputs-grid { display: flex; flex-direction: column; gap: 8px; }
        .social-input-row {
            display: flex; align-items: center; gap: 0;
            border: 1px solid #e5e7eb; border-radius: 8px;
            background: #fff; overflow: hidden;
            transition: border-color .2s, box-shadow .2s;
        }
        .social-input-row:focus-within {
            border-color: #6366f1;
            box-shadow: 0 0 0 2px rgba(99,102,241,.1);
        }
        .social-icon-badge {
            width: 36px; height: 36px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; border-right: 1px solid #f3f4f6;
        }
        .social-icon-badge.fb  { background:#1877f215; color:#1877f2; }
        .social-icon-badge.x   { background:#00000010; color:#000; }
        .social-icon-badge.li  { background:#0a66c215; color:#0a66c2; }
        .social-icon-badge.ig  { background:#e10f7715; color:#e10f77; }
        .social-url-input {
            flex: 1; border: none !important; outline: none !important;
            background: transparent !important; box-shadow: none !important;
            padding: 8px 12px !important; font-size: 11px !important;
            color: #374151; font-family: 'Inter', sans-serif;
        }
        .social-url-input::placeholder { color: #c4c4c4; font-size: 11px; }
        /* dark mode social */
        body.dark-mode .social-section { background:#1a2340; border-color:#2d3a6e; }
        body.dark-mode .social-section-label { color:#818cf8; }
        body.dark-mode .social-input-row { background:#0f172a; border-color:#334155; }
        body.dark-mode .social-input-row:focus-within { border-color:#6366f1; }
        body.dark-mode .social-icon-badge { border-color:#1e293b; }
        body.dark-mode .social-icon-badge.x { background:#ffffff10; color:#f8fafc; }
        body.dark-mode .social-url-input { color:#f8fafc; }
        .phone-input-wrap {
            display: flex; align-items: center;
            border: 1px solid #d1d5db; border-radius: 6px;
            background: #f9fafb; overflow: visible;
            transition: border-color .2s, box-shadow .2s;
        }
        .phone-input-wrap:focus-within {
            border-color: #3b82f6; background: #fff;
            box-shadow: 0 0 0 2px rgba(59,130,246,.1);
        }
        /* Trigger button */
        .cc-trigger {
            display: flex; align-items: center; gap: 5px;
            padding: 0 10px; height: 36px; cursor: pointer;
            flex-shrink: 0; user-select: none;
            border-radius: 6px 0 0 6px;
            transition: background .15s;
        }
        .cc-trigger:hover { background: #e9f0ff; }
        .cc-flag { font-size: 16px; line-height: 1; }
        .cc-code { font-size: 12px; font-weight: 700; color: #374151; white-space: nowrap; }
        .cc-chevron { font-size: 9px; color: #9ca3af; transition: transform .2s; }
        .cc-trigger.open .cc-chevron { transform: rotate(180deg); }
        .cc-divider { width: 1px; height: 20px; background: #e5e7eb; flex-shrink: 0; }
        /* Number text input */
        .cc-number-input {
            flex: 1; border: none !important; outline: none !important;
            background: transparent !important; box-shadow: none !important;
            padding: 8px 10px !important; font-size: 12px !important;
            color: #111827; font-family: 'Inter', sans-serif;
            border-radius: 0 6px 6px 0 !important;
        }
        /* Dropdown panel */
        .cc-dropdown {
            display: none; position: absolute;
            top: calc(100% + 4px); left: 0;
            width: 280px; background: #fff;
            border: 1px solid #e5e7eb; border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,.12);
            z-index: 5000; overflow: hidden;
            animation: ccFadeIn .16s ease;
        }
        .cc-dropdown.open { display: block; }
        @keyframes ccFadeIn {
            from { opacity:0; transform:translateY(-6px); }
            to   { opacity:1; transform:translateY(0); }
        }
        .cc-search-wrap {
            display: flex; align-items: center; gap: 8px;
            padding: 10px 12px; border-bottom: 1px solid #f3f4f6;
        }
        .cc-search-icon { font-size: 12px; color: #9ca3af; flex-shrink: 0; }
        .cc-search-input {
            flex: 1; border: none; outline: none; background: transparent;
            font-size: 12px; color: #374151; font-family: 'Inter', sans-serif;
        }
        .cc-search-input::placeholder { color: #c4c4c4; }
        .cc-list { max-height: 220px; overflow-y: auto; }
        .cc-list::-webkit-scrollbar { width: 4px; }
        .cc-list::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 4px; }
        .cc-item {
            display: flex; align-items: center; gap: 10px;
            padding: 9px 14px; cursor: pointer;
            transition: background .12s;
        }
        .cc-item:hover { background: #f0f7ff; }
        .cc-item.selected { background: #eff6ff; }
        .cc-item-flag { font-size: 18px; flex-shrink: 0; }
        .cc-item-name { font-size: 12px; color: #374151; flex: 1; font-weight: 500; }
        .cc-item-code { font-size: 11px; font-weight: 700; color: #6b7280; flex-shrink: 0; }
        .cc-item.selected .cc-item-name { color: #1d4ed8; font-weight: 700; }
        .cc-item.selected .cc-item-code { color: #3b82f6; }
        .cc-no-result { padding: 16px; text-align: center; font-size: 12px; color: #9ca3af; }
        /* Dark mode */
        body.dark-mode .phone-input-wrap { background:#0f172a; border-color:#334155; }
        body.dark-mode .phone-input-wrap:focus-within { background:#1e293b; border-color:#3b82f6; }
        body.dark-mode .cc-trigger:hover { background:#1e293b; }
        body.dark-mode .cc-code { color:#f8fafc; }
        body.dark-mode .cc-divider { background:#334155; }
        body.dark-mode .cc-number-input { color:#f8fafc !important; }
        body.dark-mode .cc-dropdown { background:#1e293b; border-color:#334155; box-shadow:0 10px 30px rgba(0,0,0,.4); }
        body.dark-mode .cc-search-wrap { border-color:#334155; }
        body.dark-mode .cc-search-input { color:#f8fafc; }
        body.dark-mode .cc-item:hover { background:#0f172a; }
        body.dark-mode .cc-item.selected { background:#1e3a5f; }
        body.dark-mode .cc-item-name { color:#cbd5e1; }
        body.dark-mode .cc-item-code { color:#94a3b8; }
        .submit-btn { background-color: #0f172a; color: #ffffff; padding: 10px; border: none; border-radius: 6px; width: 100%; font-size: 13px; font-weight: 700; cursor: pointer; transition: 0.2s; margin-top: 12px; }
        .submit-btn:hover { background-color: #1e293b; }

        body.dark-mode { background-color: #0f172a; color: #f8fafc; }
        body.dark-mode .main-content { background-color: #0f172a; }
        body.dark-mode 
        body.dark-mode 
        body.dark-mode .comp-header-title h1 { color: #f8fafc; }
        body.dark-mode .table-wrapper { border-color: #334155; background: #1e293b; }
        body.dark-mode .custom-table th { background-color: #334155; color: #f8fafc; border-color: #475569; }
        body.dark-mode .custom-table td { color: #cbd5e1; border-color: #334155; }
        body.dark-mode .custom-table tbody tr:nth-child(even) { background-color: #1e293b; } 
        body.dark-mode .custom-table tbody tr:nth-child(odd) { background-color: #0f172a; } 
        body.dark-mode .custom-table tbody tr:hover { background-color: #334155; }
        body.dark-mode .comp-search input { background-color: #0f172a; color: #f8fafc; border-color: #334155; }
        body.dark-mode .comp-total { background-color: #0f172a; color: #cbd5e1; border-color: #334155; }
        body.dark-mode .modal-content { background-color: #1e293b; box-shadow: 0 10px 25px rgba(0,0,0,0.5); border: 1px solid #334155;}
        body.dark-mode .form-group input, body.dark-mode .form-group select { background-color: #0f172a; color: #f8fafc; border-color: #334155; }
        body.dark-mode .form-group input:focus, body.dark-mode .form-group select:focus { border-color: #3b82f6; background-color: #1e293b; }
        
        .form-group select[multiple] { padding: 6px 4px; cursor: pointer; }
        .form-group select[multiple] option { padding: 7px 10px; border-radius: 4px; margin-bottom: 2px; }
        .form-group select[multiple] option:checked { background: #3b82f6 linear-gradient(0deg,#3b82f6 0%,#3b82f6 100%); color:#fff; }
        body.dark-mode .form-group select[multiple] option { color:#f8fafc; }
        .swal2-container { z-index: 9999 !important; }
        body.dark-mode .swal2-popup { background-color: #1e293b; color: #f8fafc; border: 1px solid #334155; }
        body.dark-mode .swal2-title, body.dark-mode .swal2-html-container { color: #f8fafc; }
    </style>
</head>
<body>

    <div id="toastBox"><i id="toastIcon" class="fa-solid fa-circle-check"></i><span id="toastMsg">Action Successful!</span></div>

        <?php
    $activePage    = 'client_list';
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
        <?php include 'topbar.php'; ?>

        <div class="company-container">
            <div class="user-list-header">
                <div class="comp-header-title">
                    <h1>Accounts & Clients</h1>
                    <p>Manage all individual contacts and clients here.</p>
                </div>
                <div class="header-buttons">
                    <?php if (!$_isAgent): ?>
                    <button class="btn-export" onclick="exportCSV()"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
                    <button class="btn-bulk" onclick="openModal('bulkUploadModal')"><i class="fa-solid fa-cloud-arrow-up"></i> Bulk Upload</button>
                    <?php if ($_currentRole !== 'manager'): ?>
                    <button class="btn-bulk" style="background-color:#f59e0b;" onclick="openModal('bulkEditClientModal')"><i class="fa-solid fa-pen-to-square"></i> Bulk Edit</button>
                    <?php endif; ?>
                    <button class="btn-add-client" onclick="openModal('addClientModal')"><i class="fa-solid fa-user-plus"></i> Add Client</button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!in_array($_currentRole, ['manager', 'agent'])): ?>
            <!-- Tabs -->
            <div class="tab-container">
                <div class="tab-btn active" onclick="filterClients('all', this)">All Clients</div>
                <div class="tab-btn" onclick="filterClients('active', this)">Active</div>
                <div class="tab-btn" onclick="filterClients('inactive', this)">In-Active</div>
            </div>
            <?php endif; ?>

            <div class="comp-toolbar">
                <div class="comp-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" placeholder="Search client...">
                </div>
                <div class="comp-total">Total Clients: <?php echo (isset($hasClients) && $hasClients) ? $totalClients : "0"; ?></div>
            </div>

            <div class="table-wrapper">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th style="width:80px;">ID</th>
                            <th style="text-align:left;">Client Name</th>
                            <th>Email</th>
                            <th>Designation</th>
                            <th>Associated Company</th>
                            <th>Assigned Agents</th>
                            <th>Last Conversation</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(isset($hasClients) && $hasClients): ?>
                            <?php echo $clientTableRows; ?>
                        <?php else: ?>
                            <tr><td colspan="8" style="padding: 20px; text-align: center; color: #6b7280;">No clients found. Click "Add Client" to get started.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="addClientModal" class="modal">
        <div class="modal-content wizard-modal">

            <!-- ── Header ── -->
            <div class="modal-header">
                <div>
                    <h2 id="wizardTitle">Add New Client</h2>
                    <p class="wizard-subtitle" id="wizardSubtitle">Step 1 of 2 — Basic Information</p>
                </div>
                <button type="button" class="close-btn" onclick="closeModal('addClientModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <!-- ── Step indicator ── -->
            <div class="wizard-steps">
                <div class="wstep active" id="wstep1">
                    <div class="wstep-circle">1</div>
                    <span class="wstep-label">Basic Info</span>
                </div>
                <div class="wstep-line"></div>
                <div class="wstep" id="wstep2">
                    <div class="wstep-circle">2</div>
                    <span class="wstep-label">Socials & Assign</span>
                </div>
            </div>

            <form action="client_list.php" method="POST" id="addClientForm">

                <!-- ══ STEP 1 ══ -->
                <div class="wizard-panel" id="wpanel1">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Client Name <span style="color:#ef4444;">*</span></label>
                            <input type="text" name="client_name" id="f_name" required placeholder="e.g. Jane Doe">
                        </div>
                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" name="client_email" placeholder="jane@example.com">
                        </div>
                        <div class="form-group" style="position:relative;">
                            <label>Phone Number</label>
                            <div class="phone-input-wrap" id="phoneWrap">
                                <div class="cc-trigger" id="ccTrigger" onclick="ccToggle(event)">
                                    <span class="cc-flag" id="ccFlag">🇧🇩</span>
                                    <span class="cc-code" id="ccCode">+880</span>
                                    <i class="fa-solid fa-chevron-down cc-chevron" id="ccChevron"></i>
                                </div>
                                <div class="cc-divider"></div>
                                <input type="text" id="phoneNumberInput" placeholder="1XXXXXXXXX" class="cc-number-input">
                                <input type="hidden" name="client_phone" id="client_phone_hidden">
                            </div>
                            <div class="cc-dropdown" id="ccDropdown">
                                <div class="cc-search-wrap">
                                    <i class="fa-solid fa-magnifying-glass cc-search-icon"></i>
                                    <input type="text" class="cc-search-input" id="ccSearch" placeholder="Search country or code..." oninput="ccFilter(this.value)">
                                </div>
                                <div class="cc-list" id="ccList"></div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Designation / Title</label>
                            <input type="text" name="client_designation" placeholder="e.g. Manager, CEO..." list="desig-suggestions" autocomplete="off">
                            <datalist id="desig-suggestions">
                                <?php echo $designationOptions; ?>
                            </datalist>
                        </div>
                        <div class="form-group full-width">
                            <label>Associated Company</label>
                            <select name="company_id" id="add_company_id" onchange="autoMergeCompanyAgents(this.value, 'add')">
                                <option value="" selected>No Company (Independent Client)</option>
                                <?php echo $companyOptions; ?>
                            </select>
                        </div>
                    </div>
                    <div class="wizard-footer">
                        <span></span>
                        <button type="button" class="wizard-btn-next" onclick="wizardNext()">
                            Next <i class="fa-solid fa-arrow-right" style="font-size:11px;"></i>
                        </button>
                    </div>
                </div>

                <!-- ══ STEP 2 ══ -->
                <div class="wizard-panel" id="wpanel2" style="display:none;">
                    <!-- Assign Agent -->
                    <div class="form-group" style="margin-bottom:14px;">
                        <label>Assign to Agent</label>
                        <div id="agentMultiSelect" class="custom-multiselect">
                            <div class="cms-trigger" id="cmsTrigger" onclick="cmsToggle()">
                                <div class="cms-trigger-tags" id="cmsTags">
                                    <span class="cms-placeholder" id="cmsPlaceholder">Select agent(s)...</span>
                                </div>
                                <i class="fa-solid fa-chevron-down cms-arrow"></i>
                            </div>
                            <div class="cms-dropdown" id="cmsDropdown">
                                <div class="cms-search-wrap">
                                    <input class="cms-search-input" type="text" placeholder="Search agent..." oninput="cmsSearch(this.value)" id="cmsSearchInput">
                                </div>
                                <div id="cmsOptionsList">
                                    <?php
                                    // Role-based filtered agent list
                                    $_agCallerRoleAdd     = $_SESSION['role']     ?? '';
                                    $_agCallerUsernameAdd = mysqli_real_escape_string($conn, $_SESSION['username'] ?? '');
                                    $_agCallerNameAdd     = mysqli_real_escape_string($conn, $_SESSION['name']     ?? '');
                                    if (isset($conn)) {
                                        if ($_agCallerRoleAdd === 'super_admin') {
                                            $ag_q2 = mysqli_query($conn, "SELECT username, name FROM users WHERE role IN ('agent','manager','admin') AND status='active' ORDER BY role DESC, name ASC");
                                        } elseif ($_agCallerRoleAdd === 'admin') {
                                            $_agMN_add = [];
                                            $_agMQ_add = mysqli_query($conn, "SELECT name, username FROM users WHERE role='manager' AND status='active' AND (reporting_to='$_agCallerUsernameAdd' OR reporting_to='$_agCallerNameAdd')");
                                            if ($_agMQ_add) { while ($_agMR_add = mysqli_fetch_assoc($_agMQ_add)) { $_agMN_add[] = "'".mysqli_real_escape_string($conn,$_agMR_add['name'])."'"; $_agMN_add[] = "'".mysqli_real_escape_string($conn,$_agMR_add['username'])."'"; } }
                                            $_agMNS_add = !empty($_agMN_add) ? implode(',', $_agMN_add) : "''";
                                            $ag_q2 = mysqli_query($conn, "SELECT username, name FROM users WHERE status='active' AND (
                                                (role IN ('manager','agent') AND (reporting_to='$_agCallerUsernameAdd' OR reporting_to='$_agCallerNameAdd'))
                                                OR (role = 'agent' AND reporting_to IN ($_agMNS_add))
                                            ) ORDER BY name ASC");
                                        } elseif ($_agCallerRoleAdd === 'manager') {
                                            $ag_q2 = mysqli_query($conn, "SELECT username, name FROM users WHERE status='active' AND role = 'agent' AND (reporting_to='$_agCallerUsernameAdd' OR reporting_to='$_agCallerNameAdd') ORDER BY name ASC");
                                        } else {
                                            $ag_q2 = false;
                                        }
                                        if ($ag_q2 && mysqli_num_rows($ag_q2) > 0) {
                                            while ($aR = mysqli_fetch_assoc($ag_q2)) {
                                                $uname         = htmlspecialchars($aR['name']);
                                                $uname_display = htmlspecialchars($aR['name']." ({$aR['username']})");
                                                echo "<div class='cms-option' data-value='{$uname}' data-label='{$uname_display}' onclick='cmsToggleOption(this)'>
                                                    <input type='checkbox' tabindex='-1'>
                                                    <span>{$uname_display}</span>
                                                </div>";
                                            }
                                        } else {
                                            echo "<div class='cms-empty'>No agents available</div>";
                                        }
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                        <div id="cmsHiddenInputs"></div>
                    </div>

                    <!-- Social Links -->
                    <div class="social-section">
                        <div class="social-section-label">
                            <i class="fa-solid fa-share-nodes"></i> Social Media <span>(Optional)</span>
                        </div>
                        <div class="social-inputs-grid">
                            <div class="social-input-row">
                                <span class="social-icon-badge fb"><i class="fa-brands fa-facebook-f"></i></span>
                                <input type="url" name="fb_url" placeholder="https://facebook.com/username" class="social-url-input">
                            </div>
                            <div class="social-input-row">
                                <span class="social-icon-badge x">
                                    <svg width="13" height="13" viewBox="0 0 1200 1227" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M714.163 519.284L1160.89 0H1055.03L667.137 450.887L357.328 0H0L468.492 681.821L0 1226.37H105.866L515.491 750.218L842.672 1226.37H1200L714.163 519.284ZM569.165 687.828L521.697 619.934L144.011 79.6944H306.615L611.412 515.685L658.88 583.579L1055.08 1150.3H892.476L569.165 687.828Z" fill="currentColor"/></svg>
                                </span>
                                <input type="url" name="twitter_url" placeholder="https://x.com/username" class="social-url-input">
                            </div>
                            <div class="social-input-row">
                                <span class="social-icon-badge li"><i class="fa-brands fa-linkedin-in"></i></span>
                                <input type="url" name="linkedin_url" placeholder="https://linkedin.com/in/username" class="social-url-input">
                            </div>
                            <div class="social-input-row">
                                <span class="social-icon-badge ig"><i class="fa-brands fa-instagram"></i></span>
                                <input type="url" name="insta_url" placeholder="https://instagram.com/username" class="social-url-input">
                            </div>
                        </div>
                    </div>

                    <div class="wizard-footer">
                        <button type="button" class="wizard-btn-back" onclick="wizardBack()">
                            <i class="fa-solid fa-arrow-left" style="font-size:11px;"></i> Back
                        </button>
                        <button type="submit" name="create_client" class="wizard-btn-save">
                            <i class="fa-solid fa-floppy-disk"></i> Save Client
                        </button>
                    </div>
                </div>

            </form>
        </div>
    </div>

    <!-- EDIT CLIENT MODAL -->
    <div id="editClientModal" class="modal">
        <div class="modal-content wizard-modal">
            <div class="modal-header">
                <div>
                    <h2>Edit Client</h2>
                    <p class="wizard-subtitle" id="editWizardSubtitle">Step 1 of 2 — Basic Information</p>
                </div>
                <button type="button" class="close-btn" onclick="closeModal('editClientModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <!-- Step indicator -->
            <div class="wizard-steps">
                <div class="wstep active" id="ewstep1">
                    <div class="wstep-circle">1</div>
                    <span class="wstep-label">Basic Info</span>
                </div>
                <div class="wstep-line" id="editWstepLine"></div>
                <div class="wstep" id="ewstep2">
                    <div class="wstep-circle">2</div>
                    <span class="wstep-label">Socials &amp; Assign</span>
                </div>
            </div>

            <form action="client_list.php" method="POST" id="editClientForm">
                <input type="hidden" name="update_client" value="1">
                <input type="hidden" name="edit_client_id" id="edit_client_id">

                <!-- STEP 1 -->
                <div class="wizard-panel" id="epanel1">
                    <?php if ($_currentRole === 'manager'): ?>
                    <div style="background:#fef9c3;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:12px;color:#92400e;">
                        <i class="fa-solid fa-lock" style="margin-right:6px;"></i>Manager হিসেবে আপনি শুধু <strong>Assigned Agents</strong> পরিবর্তন করতে পারবেন।
                    </div>
                    <?php endif; ?>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Client Name <span style="color:#ef4444;">*</span></label>
                            <input type="text" name="edit_client_name" id="edit_client_name" required placeholder="e.g. Jane Doe"
                                <?php if ($_currentRole === 'manager') echo 'readonly style="background:#f3f4f6;color:#9ca3af;cursor:not-allowed;"'; ?>>
                        </div>
                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" name="edit_client_email" id="edit_client_email" placeholder="jane@example.com"
                                <?php if ($_currentRole === 'manager') echo 'readonly style="background:#f3f4f6;color:#9ca3af;cursor:not-allowed;"'; ?>>
                        </div>
                        <div class="form-group">
                            <label>Phone Number</label>
                            <input type="text" name="edit_client_phone" id="edit_client_phone" placeholder="+880XXXXXXXXX"
                                <?php if ($_currentRole === 'manager') echo 'readonly style="background:#f3f4f6;color:#9ca3af;cursor:not-allowed;"'; ?>>
                        </div>
                        <div class="form-group">
                            <label>Designation / Title</label>
                            <input type="text" name="edit_client_designation" id="edit_client_designation" placeholder="e.g. Manager, CEO..." list="edit-desig-suggestions" autocomplete="off"
                                <?php if ($_currentRole === 'manager') echo 'readonly style="background:#f3f4f6;color:#9ca3af;cursor:not-allowed;"'; ?>>
                            <datalist id="edit-desig-suggestions">
                                <?php echo $designationOptions; ?>
                            </datalist>
                        </div>
                        <div class="form-group full-width">
                            <label>Associated Company</label>
                            <select name="edit_company_id" id="edit_company_id" required
                                onchange="autoMergeCompanyAgents(this.value, 'edit')"
                                <?php if ($_currentRole === 'manager') echo 'disabled style="background:#f3f4f6;color:#9ca3af;cursor:not-allowed;"'; ?>>
                                <option value="">No Company (Independent Client)</option>
                                <?php echo $companyOptions; ?>
                            </select>
                            <?php if ($_currentRole === 'manager'): ?>
                            <input type="hidden" name="edit_company_id" id="edit_company_id_hidden">
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="wizard-footer">
                        <span></span>
                        <button type="button" class="wizard-btn-next" onclick="editWizardNext()">
                            Next <i class="fa-solid fa-arrow-right" style="font-size:11px;"></i>
                        </button>
                    </div>
                </div>

                <!-- STEP 2 -->
                <div class="wizard-panel" id="epanel2" style="display:none;">
                    <!-- Assign Agent -->
                    <div class="form-group" style="margin-bottom:14px;">
                        <label>Assign to Agent</label>
                        <div id="editAgentMultiSelect" class="custom-multiselect">
                            <div class="cms-trigger" id="editCmsTrigger" onclick="editCmsToggle()">
                                <div class="cms-trigger-tags" id="editCmsTags">
                                    <span class="cms-placeholder" id="editCmsPlaceholder">Select agent(s)...</span>
                                </div>
                                <i class="fa-solid fa-chevron-down cms-arrow"></i>
                            </div>
                            <div class="cms-dropdown" id="editCmsDropdown">
                                <div class="cms-search-wrap">
                                    <input class="cms-search-input" type="text" placeholder="Search agent..." oninput="editCmsSearch(this.value)" id="editCmsSearchInput">
                                </div>
                                <div id="editCmsOptionsList">
                                    <?php
                                    // Role-based filtered agent list (agentOptions already computed above)
                                    if (!empty($agentOptions)) {
                                        // Parse agentOptions to build cms-option divs
                                        // Use same query as agentOptions but build cms-option format
                                        $_agCallerRole2     = $_SESSION['role']     ?? '';
                                        $_agCallerUsername2 = mysqli_real_escape_string($conn, $_SESSION['username'] ?? '');
                                        $_agCallerName2     = mysqli_real_escape_string($conn, $_SESSION['name']     ?? '');
                                        if (isset($conn)) {
                                            if ($_agCallerRole2 === 'super_admin') {
                                                $ag_q3 = mysqli_query($conn, "SELECT username, name FROM users WHERE role IN ('agent','manager','admin') AND status='active' ORDER BY role DESC, name ASC");
                                            } elseif ($_agCallerRole2 === 'admin') {
                                                $_agMN2 = [];
                                                $_agMQ2 = mysqli_query($conn, "SELECT name, username FROM users WHERE role='manager' AND status='active' AND (reporting_to='$_agCallerUsername2' OR reporting_to='$_agCallerName2')");
                                                if ($_agMQ2) { while ($_agMR2 = mysqli_fetch_assoc($_agMQ2)) { $_agMN2[] = "'".mysqli_real_escape_string($conn,$_agMR2['name'])."'"; $_agMN2[] = "'".mysqli_real_escape_string($conn,$_agMR2['username'])."'"; } }
                                                $_agMNS2 = !empty($_agMN2) ? implode(',', $_agMN2) : "''";
                                                $ag_q3 = mysqli_query($conn, "SELECT username, name FROM users WHERE status='active' AND (
                                                    (role IN ('manager','agent') AND (reporting_to='$_agCallerUsername2' OR reporting_to='$_agCallerName2'))
                                                    OR (role = 'agent' AND reporting_to IN ($_agMNS2))
                                                ) ORDER BY name ASC");
                                            } elseif ($_agCallerRole2 === 'manager') {
                                                $ag_q3 = mysqli_query($conn, "SELECT username, name FROM users WHERE status='active' AND role = 'agent' AND (reporting_to='$_agCallerUsername2' OR reporting_to='$_agCallerName2') ORDER BY name ASC");
                                            } else {
                                                $ag_q3 = false;
                                            }
                                            if ($ag_q3 && mysqli_num_rows($ag_q3) > 0) {
                                                while ($aR3 = mysqli_fetch_assoc($ag_q3)) {
                                                    $uname3         = htmlspecialchars($aR3['name']);
                                                    $uname3_display = htmlspecialchars($aR3['name']." ({$aR3['username']})");
                                                    echo "<div class='cms-option' data-value='{$uname3}' data-label='{$uname3_display}' onclick='editCmsToggleOption(this)'>
                                                        <input type='checkbox' tabindex='-1'>
                                                        <span>{$uname3_display}</span>
                                                    </div>";
                                                }
                                            } else {
                                                echo "<div class='cms-empty'>No agents available</div>";
                                            }
                                        }
                                    } else {
                                        echo "<div class='cms-empty'>No agents available</div>";
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                        <div id="editCmsHiddenInputs"></div>
                    </div>

                    <!-- Social Links -->
                    <div class="social-section">
                        <div class="social-section-label">
                            <i class="fa-solid fa-share-nodes"></i> Social Media <span>(Optional)</span>
                        </div>
                        <div class="social-inputs-grid">
                            <div class="social-input-row">
                                <span class="social-icon-badge fb"><i class="fa-brands fa-facebook-f"></i></span>
                                <input type="url" name="edit_fb_url" id="edit_fb_url" placeholder="https://facebook.com/username" class="social-url-input"
                                    <?php if ($_currentRole === 'manager') echo 'readonly style="background:#f3f4f6;color:#9ca3af;cursor:not-allowed;"'; ?>>
                            </div>
                            <div class="social-input-row">
                                <span class="social-icon-badge x">
                                    <svg width="13" height="13" viewBox="0 0 1200 1227" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M714.163 519.284L1160.89 0H1055.03L667.137 450.887L357.328 0H0L468.492 681.821L0 1226.37H105.866L515.491 750.218L842.672 1226.37H1200L714.163 519.284ZM569.165 687.828L521.697 619.934L144.011 79.6944H306.615L611.412 515.685L658.88 583.579L1055.08 1150.3H892.476L569.165 687.828Z" fill="currentColor"/></svg>
                                </span>
                                <input type="url" name="edit_twitter_url" id="edit_twitter_url" placeholder="https://x.com/username" class="social-url-input"
                                    <?php if ($_currentRole === 'manager') echo 'readonly style="background:#f3f4f6;color:#9ca3af;cursor:not-allowed;"'; ?>>
                            </div>
                            <div class="social-input-row">
                                <span class="social-icon-badge li"><i class="fa-brands fa-linkedin-in"></i></span>
                                <input type="url" name="edit_linkedin_url" id="edit_linkedin_url" placeholder="https://linkedin.com/in/username" class="social-url-input"
                                    <?php if ($_currentRole === 'manager') echo 'readonly style="background:#f3f4f6;color:#9ca3af;cursor:not-allowed;"'; ?>>
                            </div>
                            <div class="social-input-row">
                                <span class="social-icon-badge ig"><i class="fa-brands fa-instagram"></i></span>
                                <input type="url" name="edit_insta_url" id="edit_insta_url" placeholder="https://instagram.com/username" class="social-url-input"
                                    <?php if ($_currentRole === 'manager') echo 'readonly style="background:#f3f4f6;color:#9ca3af;cursor:not-allowed;"'; ?>>
                            </div>
                        </div>
                    </div>

                    <div class="wizard-footer">
                        <button type="button" class="wizard-btn-back" onclick="editWizardBack()">
                            <i class="fa-solid fa-arrow-left" style="font-size:11px;"></i> Back
                        </button>
                        <button type="submit" class="wizard-btn-save">
                            <i class="fa-solid fa-floppy-disk"></i> Update Client
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- BULK UPLOAD MODAL -->
    <div id="bulkUploadModal" class="modal">
        <div class="modal-content" style="max-width:560px;">
            <div class="modal-header">
                <h2><i class="fa-solid fa-cloud-arrow-up" style="color:#1e293b;margin-right:8px;"></i>Bulk Upload Clients</h2>
                <button type="button" class="close-btn" onclick="closeModal('bulkUploadModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <!-- Template download -->
            <div style="background:#f0fdf4;border:1px dashed #86efac;border-radius:8px;padding:12px 16px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
                <div>
                    <p style="font-size:13px;font-weight:700;color:#15803d;margin-bottom:2px;"><i class="fa-solid fa-file-excel" style="margin-right:6px;"></i>Need a template?</p>
                    <p style="font-size:11px;color:#6b7280;">Download the Excel template, fill in and upload below.</p>
                </div>
                <button onclick="downloadTemplate()" style="padding:8px 14px;background:#0f172a;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;display:inline-flex;align-items:center;gap:6px;">
                    <i class="fa-solid fa-download"></i> Download Template
                </button>
            </div>

            <!-- File input -->
            <div class="form-group" style="margin-bottom:12px;">
                <label>Select Excel File (.xlsx)</label>
                <input type="file" id="bulkFileInput" accept=".xlsx,.xls,.csv" style="background:#f9fafb;padding:8px 10px;">
            </div>

            <!-- Preview -->
            <div id="bulkPreview" style="max-height:200px;overflow-y:auto;margin-bottom:12px;border-radius:6px;font-size:12px;"></div>

            <div style="display:flex;gap:8px;justify-content:flex-end;">
                <button onclick="closeModal('bulkUploadModal')" style="padding:9px 18px;border:1px solid #d1d5db;background:#fff;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;color:#374151;">Cancel</button>
                <button id="bulkSubmitBtn" style="display:none;padding:9px 18px;background:#16a34a;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;align-items:center;gap:6px;">
                    <i class="fa-solid fa-cloud-arrow-up"></i> Upload Clients
                </button>
            </div>
        </div>
    </div>

    <!-- BULK EDIT CLIENTS MODAL -->
    <div id="bulkEditClientModal" class="modal">
        <div class="modal-content" style="max-width:820px;">
            <div class="modal-header">
                <div>
                    <h2><i class="fa-solid fa-pen-to-square" style="color:#f59e0b;margin-right:8px;"></i>Bulk Edit Clients</h2>
                    <p style="font-size:12px;color:#6b7280;margin-top:3px;">Upload a CSV file to update multiple clients at once.</p>
                </div>
                <button type="button" class="close-btn" onclick="closeModal('bulkEditClientModal')"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <!-- Template download -->
            <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:14px 16px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
                <div>
                    <div style="font-size:13px;font-weight:700;color:#b45309;margin-bottom:3px;"><i class="fa-solid fa-file-csv" style="margin-right:6px;"></i>Download Edit Template</div>
                    <div style="font-size:11px;color:#d97706;">Columns: id, name, email, phone, designation, company_id, assigned_agents, fb_url, linkedin_url, twitter_url, insta_url</div>
                </div>
                <a href="client_list.php?download_client_edit_template=1" style="background:#b45309;color:#fff;padding:9px 16px;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:7px;white-space:nowrap;">
                    <i class="fa-solid fa-download"></i> Download Template
                </a>
            </div>

            <!-- Drop zone -->
            <div id="clientEditDropZone" style="border:2px dashed #d1d5db;border-radius:10px;padding:30px;text-align:center;cursor:pointer;transition:.2s;margin-bottom:16px;"
                onclick="document.getElementById('clientEditCsvFile').click()"
                ondragover="clientEditDragOver(event)"
                ondragleave="clientEditDragLeave(event)"
                ondrop="clientEditDrop(event)">
                <i class="fa-solid fa-cloud-arrow-up" style="font-size:28px;color:#9ca3af;margin-bottom:8px;display:block;"></i>
                <div style="font-size:13px;font-weight:600;color:#374151;">Click to browse or drag & drop CSV here</div>
                <div style="font-size:11px;color:#9ca3af;margin-top:4px;">Only .csv files accepted</div>
                <input type="file" id="clientEditCsvFile" accept=".csv" style="display:none;" onchange="clientEditReadFile(this)">
            </div>

            <div id="clientEditFileInfo" style="display:none;background:#fef3c7;border:1px solid #fde68a;border-radius:7px;padding:10px 14px;margin-bottom:14px;font-size:12px;font-weight:600;color:#b45309;">
                <i class="fa-solid fa-file-csv"></i> <span id="clientEditFileName"></span> — <span id="clientEditRowCount"></span> rows detected
            </div>

            <div id="clientEditPreviewWrap" style="display:none;margin-bottom:16px;">
                <div style="font-size:12px;font-weight:700;color:#374151;margin-bottom:8px;">Preview (first 5 rows):</div>
                <div style="overflow-x:auto;border-radius:7px;border:1px solid #e5e7eb;">
                    <table style="width:100%;border-collapse:collapse;font-size:11px;">
                        <thead id="clientEditPreviewHead" style="background:#f1f5f9;"></thead>
                        <tbody id="clientEditPreviewBody"></tbody>
                    </table>
                </div>
            </div>

            <!-- Upload form -->
            <form action="client_list.php" method="POST" enctype="multipart/form-data" id="clientBulkEditForm">
                <input type="file" name="client_edit_csv" id="clientEditHiddenFile" style="display:none;" accept=".csv">
                <div style="display:flex;gap:10px;margin-top:4px;">
                    <button type="button" onclick="closeModal('bulkEditClientModal')" style="flex:1;background:#f3f4f6;color:#374151;padding:11px;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;">Cancel</button>
                    <button type="button" id="clientEditUploadBtn" onclick="clientEditSubmit()" disabled
                        style="flex:2;background:#f59e0b;color:#fff;padding:11px;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;opacity:.5;transition:.2s;">
                        <i class="fa-solid fa-pen-to-square"></i> Update Clients
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) { document.getElementById(id).style.display = "flex"; }
        function closeModal(id) {
            document.getElementById(id).style.display = "none";
            if(id === 'bulkUploadModal'){
                document.getElementById('bulkFileInput').value = '';
                document.getElementById('bulkPreview').innerHTML = '';
                document.getElementById('bulkSubmitBtn').style.display = 'none';
                window._bulkAllRows = [];
            }
        }

        // ── Tab Filter ──
        function filterClients(status, btnElement) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            btnElement.classList.add('active');
            document.querySelectorAll('.client-row').forEach(row => {
                if (status === 'all') {
                    row.style.display = '';
                } else {
                    row.style.display = (row.getAttribute('data-status') === status) ? '' : 'none';
                }
            });
        }
        window.onclick = function(event) { if (event.target.classList.contains('modal')) event.target.style.display = "none"; }

        function showToast(message, type) {
            const toast = document.getElementById("toastBox");
            document.getElementById("toastMsg").innerText = message;
            toast.className = "show " + type;
            document.getElementById("toastIcon").className = (type === 'success') ? "fa-solid fa-circle-check" : "fa-solid fa-circle-xmark";
            setTimeout(() => toast.className = toast.className.replace("show", ""), 3000);
        }

        window.onload = function() {
            <?php if($toastMessage != ""): ?> showToast("<?php echo $toastMessage; ?>", "<?php echo $toastType; ?>"); <?php endif; ?>
        };

        function confirmDelete(formId, typeName) {
            const isDark = document.body.classList.contains('dark-mode');
            Swal.fire({
                title: 'Are you sure?', text: "You won't be able to revert this!", icon: 'warning', showCancelButton: true,
                confirmButtonColor: '#ef4444', cancelButtonColor: '#6b7280', confirmButtonText: 'Yes, delete it!',
                background: isDark ? '#1e293b' : '#fff', color: isDark ? '#f8fafc' : '#111827'
            }).then((result) => { if (result.isConfirmed) { document.getElementById(formId).submit(); } });
        }

        function confirmToggleClientStatus(formId, newStatus) {
            const isDark = document.body.classList.contains('dark-mode');
            const isActivating = newStatus === 'active';
            Swal.fire({
                title: isActivating ? 'Mark as Active?' : 'Mark as Inactive?',
                text: isActivating ? 'This client will be set to Active.' : 'This client will be set to Inactive.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: isActivating ? '#10b981' : '#9ca3af',
                cancelButtonColor: '#6b7280',
                confirmButtonText: isActivating ? 'Yes, Activate!' : 'Yes, Deactivate!',
                background: isDark ? '#1e293b' : '#fff',
                color: isDark ? '#f8fafc' : '#111827'
            }).then((result) => {
                if (result.isConfirmed) { document.getElementById(formId).submit(); }
            });
        }

        /* ── Search ── */
        document.querySelector('.comp-search input').addEventListener('input', function() {
            const q = this.value.toLowerCase().trim();
            document.querySelectorAll('.custom-table tbody tr').forEach(tr => {
                const tds = tr.querySelectorAll('td');
                if (!tds.length) { tr.style.display = ''; return; }
                const cells = Array.from(tds).map(td => td.textContent.trim().toLowerCase());
                const rowText = cells.join(' ');
                tr.style.display = rowText.includes(q) ? '' : 'none';
            });
        });

        /* ── Export CSV ── */
        function exportCSV() {
            const rows = [['Client Name','Email','Phone','Designation','Associated Company','Assigned Agents','Last Conversation','Date Added']];
            document.querySelectorAll('.custom-table tbody tr').forEach(tr => {
                if (tr.style.display === 'none') return;
                const tds = tr.querySelectorAll('td');
                if (tds.length < 8) return;
                rows.push([
                    tds[0]?.textContent.trim(),
                    tds[1]?.textContent.trim(),
                    tds[2]?.textContent.trim(),
                    tds[3]?.textContent.trim(),
                    tds[4]?.textContent.trim(),
                    tds[5]?.textContent.trim(),
                    tds[6]?.textContent.trim(),
                    tds[7]?.textContent.trim(),
                ].map(v => `"${(v||'').replace(/"/g,'""')}"`));
            });
            const blob = new Blob(['\uFEFF' + rows.map(r => r.join(',')).join('\r\n')], {type:'text/csv;charset=utf-8;'});
            const a = Object.assign(document.createElement('a'), {
                href: URL.createObjectURL(blob), download: 'clients_export.csv'
            });
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
            showToast('CSV exported successfully!', 'success');
        }

        /* ── Custom Multi-Select for Agents ── */
        /* ── 2-Step Wizard ── */
        function wizardNext() {
            var name = document.getElementById('f_name');
            if (!name.value.trim()) { name.focus(); name.style.borderColor='#ef4444'; return; }
            name.style.borderColor='';
            document.getElementById('wpanel1').style.display = 'none';
            document.getElementById('wpanel2').style.display = 'block';
            // update indicators
            document.getElementById('wstep1').className = 'wstep done';
            document.getElementById('wstep1').querySelector('.wstep-circle').innerHTML = '<i class="fa-solid fa-check" style="font-size:10px;"></i>';
            document.getElementById('wstep2').className = 'wstep active';
            document.querySelector('.wstep-line').classList.add('done');
            document.getElementById('wizardSubtitle').textContent = 'Step 2 of 2 — Socials & Assignment';
        }
        function wizardBack() {
            document.getElementById('wpanel2').style.display = 'none';
            document.getElementById('wpanel1').style.display = 'block';
            document.getElementById('wstep1').className = 'wstep active';
            document.getElementById('wstep1').querySelector('.wstep-circle').innerHTML = '1';
            document.getElementById('wstep2').className = 'wstep';
            document.querySelector('.wstep-line').classList.remove('done');
            document.getElementById('wizardSubtitle').textContent = 'Step 1 of 2 — Basic Information';
        }

        /* ── Custom Country Code Picker ── */
        (function () {
            var COUNTRIES = [
                { flag:'🇧🇩', name:'Bangladesh',      code:'+880' },
                { flag:'🇺🇸', name:'United States',   code:'+1'   },
                { flag:'🇬🇧', name:'United Kingdom',  code:'+44'  },
                { flag:'🇮🇳', name:'India',           code:'+91'  },
                { flag:'🇦🇺', name:'Australia',       code:'+61'  },
                { flag:'🇦🇪', name:'UAE',             code:'+971' },
                { flag:'🇸🇦', name:'Saudi Arabia',    code:'+966' },
                { flag:'🇸🇬', name:'Singapore',       code:'+65'  },
                { flag:'🇲🇾', name:'Malaysia',        code:'+60'  },
                { flag:'🇵🇰', name:'Pakistan',        code:'+92'  },
                { flag:'🇱🇰', name:'Sri Lanka',       code:'+94'  },
                { flag:'🇲🇲', name:'Myanmar',         code:'+95'  },
                { flag:'🇮🇩', name:'Indonesia',       code:'+62'  },
                { flag:'🇵🇭', name:'Philippines',     code:'+63'  },
                { flag:'🇯🇵', name:'Japan',           code:'+81'  },
                { flag:'🇰🇷', name:'South Korea',     code:'+82'  },
                { flag:'🇨🇳', name:'China',           code:'+86'  },
                { flag:'🇩🇪', name:'Germany',         code:'+49'  },
                { flag:'🇫🇷', name:'France',          code:'+33'  },
                { flag:'🇮🇹', name:'Italy',           code:'+39'  },
                { flag:'🇷🇺', name:'Russia',          code:'+7'   },
                { flag:'🇧🇷', name:'Brazil',          code:'+55'  },
                { flag:'🇿🇦', name:'South Africa',    code:'+27'  },
                { flag:'🇳🇬', name:'Nigeria',         code:'+234' },
                { flag:'🇪🇬', name:'Egypt',           code:'+20'  },
                { flag:'🇹🇷', name:'Turkey',          code:'+90'  },
                { flag:'🇳🇱', name:'Netherlands',     code:'+31'  },
                { flag:'🇪🇸', name:'Spain',           code:'+34'  },
                { flag:'🇸🇪', name:'Sweden',          code:'+46'  },
                { flag:'🇳🇴', name:'Norway',          code:'+47'  },
                { flag:'🇨🇦', name:'Canada',          code:'+1'   },
                { flag:'🇲🇽', name:'Mexico',          code:'+52'  },
                { flag:'🇦🇷', name:'Argentina',       code:'+54'  },
                { flag:'🇶🇦', name:'Qatar',           code:'+974' },
                { flag:'🇰🇼', name:'Kuwait',          code:'+965' },
                { flag:'🇧🇭', name:'Bahrain',         code:'+973' },
                { flag:'🇴🇲', name:'Oman',            code:'+968' },
                { flag:'🇳🇵', name:'Nepal',           code:'+977' },
                { flag:'🇹🇭', name:'Thailand',        code:'+66'  },
                { flag:'🇻🇳', name:'Vietnam',         code:'+84'  },
            ];

            var selected = COUNTRIES[0];
            var isOpen   = false;

            function renderList(filter) {
                var list = document.getElementById('ccList');
                if (!list) return;
                var q = (filter || '').toLowerCase();
                var filtered = COUNTRIES.filter(function(c) {
                    return c.name.toLowerCase().includes(q) || c.code.includes(q);
                });
                if (!filtered.length) {
                    list.innerHTML = '<div class="cc-no-result">No results found</div>';
                    return;
                }
                list.innerHTML = filtered.map(function(c) {
                    var sel = (c.code === selected.code && c.name === selected.name) ? ' selected' : '';
                    return '<div class="cc-item' + sel + '" data-code="' + c.code + '" data-name="' + c.name + '" data-flag="' + c.flag + '" onclick="ccSelect(this)">' +
                        '<span class="cc-item-flag">' + c.flag + '</span>' +
                        '<span class="cc-item-name">' + c.name + '</span>' +
                        '<span class="cc-item-code">' + c.code + '</span>' +
                    '</div>';
                }).join('');
            }

            window.ccSelect = function(el) {
                selected = { flag: el.dataset.flag, name: el.dataset.name, code: el.dataset.code };
                document.getElementById('ccFlag').textContent = selected.flag;
                document.getElementById('ccCode').textContent = selected.code;
                ccClose();
            };

            window.ccFilter = function(val) { renderList(val); };

            window.ccToggle = function(e) {
                e && e.stopPropagation();
                isOpen ? ccClose() : ccOpen();
            };

            function ccOpen() {
                var dd = document.getElementById('ccDropdown');
                var trigger = document.getElementById('ccTrigger');
                if (!dd) return;
                isOpen = true;
                dd.classList.add('open');
                trigger.classList.add('open');
                renderList('');
                document.getElementById('ccSearch').value = '';
                setTimeout(function() { document.getElementById('ccSearch').focus(); }, 60);
            }

            function ccClose() {
                var dd = document.getElementById('ccDropdown');
                var trigger = document.getElementById('ccTrigger');
                if (!dd) return;
                isOpen = false;
                dd.classList.remove('open');
                if (trigger) trigger.classList.remove('open');
            }

            // Outside click closes
            document.addEventListener('click', function(e) {
                var wrap = document.getElementById('phoneWrap');
                var dd   = document.getElementById('ccDropdown');
                if (isOpen && wrap && dd && !wrap.contains(e.target) && !dd.contains(e.target)) {
                    ccClose();
                }
            });

            // Merge on form submit
            var form = document.querySelector('#addClientModal form');
            if (form) {
                form.addEventListener('submit', function() {
                    var num = (document.getElementById('phoneNumberInput').value || '').trim();
                    if (num.startsWith('0')) num = num.slice(1);
                    document.getElementById('client_phone_hidden').value = num ? selected.code + num : '';
                });
            }

            // Init list on DOMContentLoaded
            document.addEventListener('DOMContentLoaded', function() { renderList(''); });
        })();

        var _cmsSelected = [];

        function cmsToggle() {
            var trigger = document.getElementById('cmsTrigger');
            var dropdown = document.getElementById('cmsDropdown');
            var isOpen = dropdown.classList.contains('open');
            if (isOpen) {
                dropdown.classList.remove('open');
                trigger.classList.remove('open');
            } else {
                dropdown.classList.add('open');
                trigger.classList.add('open');
                document.getElementById('cmsSearchInput').focus();
            }
        }

        function cmsToggleOption(el) {
            var val = el.getAttribute('data-value');
            var label = el.getAttribute('data-label');
            var cb = el.querySelector('input[type="checkbox"]');
            var idx = _cmsSelected.findIndex(function(s){ return s.value === val; });
            if (idx > -1) {
                _cmsSelected.splice(idx, 1);
                el.classList.remove('checked');
                cb.checked = false;
            } else {
                _cmsSelected.push({ value: val, label: label });
                el.classList.add('checked');
                cb.checked = true;
            }
            cmsRenderTags();
            cmsRenderHidden();
        }

        function cmsRenderTags() {
            var tagsEl = document.getElementById('cmsTags');
            var placeholder = document.getElementById('cmsPlaceholder');
            if (_cmsSelected.length === 0) {
                tagsEl.innerHTML = '<span class="cms-placeholder" id="cmsPlaceholder">Select agent(s)...</span>';
                return;
            }
            var html = '';
            _cmsSelected.forEach(function(s) {
                html += '<span class="cms-tag">' + s.label + '<span class="cms-tag-x" onclick="cmsRemove(event,\'' + s.value + '\')">✕</span></span>';
            });
            tagsEl.innerHTML = html;
        }

        function cmsRemove(e, val) {
            e.stopPropagation();
            _cmsSelected = _cmsSelected.filter(function(s){ return s.value !== val; });
            // Uncheck the option
            document.querySelectorAll('#cmsOptionsList .cms-option').forEach(function(opt){
                if (opt.getAttribute('data-value') === val) {
                    opt.classList.remove('checked');
                    opt.querySelector('input[type="checkbox"]').checked = false;
                }
            });
            cmsRenderTags();
            cmsRenderHidden();
        }

        function cmsRenderHidden() {
            var container = document.getElementById('cmsHiddenInputs');
            container.innerHTML = '';
            _cmsSelected.forEach(function(s) {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'assigned_agents[]';
                inp.value = s.value;
                container.appendChild(inp);
            });
        }

        function cmsSearch(q) {
            q = q.toLowerCase();
            document.querySelectorAll('#cmsOptionsList .cms-option').forEach(function(opt) {
                var label = opt.getAttribute('data-label').toLowerCase();
                opt.style.display = label.includes(q) ? '' : 'none';
            });
        }

        // Close dropdown on outside click
        document.addEventListener('click', function(e) {
            var ms = document.getElementById('agentMultiSelect');
            if (ms && !ms.contains(e.target)) {
                document.getElementById('cmsDropdown').classList.remove('open');
                document.getElementById('cmsTrigger').classList.remove('open');
            }
        });

        // Reset multiselect on modal close
        var _origCloseModal = window.closeModal;
        window.closeModal = function(id) {
            if (id === 'addClientModal') {
                // reset wizard to step 1
                wizardBack();
                _cmsSelected = [];
                cmsRenderTags();
                cmsRenderHidden();
                document.querySelectorAll('#cmsOptionsList .cms-option').forEach(function(opt){
                    opt.classList.remove('checked');
                    opt.querySelector('input[type="checkbox"]').checked = false;
                });
                document.getElementById('cmsSearchInput').value = '';
                cmsSearch('');
            }
            if (_origCloseModal) _origCloseModal(id);
            else {
                var el = document.getElementById(id);
                if (el) el.style.display = 'none';
            }
        };

        /* ── Download Template ── */
        /* ── Company select হলে assigned agents auto-merge ── */
        function autoMergeCompanyAgents(companyId, mode) {
            if (!companyId) return;
            fetch('client_list.php?get_company_agents=1&company_id=' + encodeURIComponent(companyId))
                .then(function(r) { return r.json(); })
                .then(function(agents) {
                    if (!agents || !agents.length) return;
                    if (mode === 'add') {
                        // Add modal: _cmsSelected এ merge করো
                        agents.forEach(function(ag) {
                            var already = _cmsSelected.some(function(s){ return s.value === ag; });
                            if (!already) {
                                _cmsSelected.push({ value: ag, label: ag });
                                // dropdown option check করো
                                document.querySelectorAll('#cmsOptionsList .cms-option').forEach(function(opt){
                                    if (opt.getAttribute('data-value') === ag) {
                                        opt.classList.add('checked');
                                        opt.querySelector('input[type="checkbox"]').checked = true;
                                    }
                                });
                            }
                        });
                        cmsRenderTags();
                        cmsRenderHidden();
                    } else if (mode === 'edit') {
                        // Edit modal: _editCmsSelected এ merge করো
                        agents.forEach(function(ag) {
                            if (!_editCmsSelected.includes(ag)) {
                                _editCmsSelected.push(ag);
                                document.querySelectorAll('#editCmsOptionsList .cms-option').forEach(function(opt){
                                    if (opt.getAttribute('data-value') === ag) {
                                        opt.classList.add('checked');
                                        opt.querySelector('input[type="checkbox"]').checked = true;
                                    }
                                });
                            }
                        });
                        editCmsRenderTags();
                        editCmsRenderHidden();
                    }
                })
                .catch(function(){});
        }

        function downloadTemplate() {
            window.location.href = 'client_list.php?download_template=1';
        }

        /* ── Bulk Upload — File parse & preview (xlsx + csv) ── */
        document.getElementById('bulkFileInput').addEventListener('change', function() {
            const file = this.files[0];
            if (!file) return;

            const isXlsx = /\.(xlsx|xls)$/i.test(file.name);

            const parseAndPreview = function(headers, allRows) {
                window._bulkHeaders = headers;
                window._bulkAllRows = allRows;

                let html = `<table style="width:100%;border-collapse:collapse;">
                    <thead><tr>${headers.map(h=>`<th style="padding:6px 10px;background:#f3f4f6;border:1px solid #e5e7eb;font-size:11px;font-weight:700;text-align:left;">${h}</th>`).join('')}</tr></thead><tbody>`;
                const previewRows = allRows.slice(0, 5);
                previewRows.forEach(cols => {
                    html += '<tr>' + cols.map(c=>`<td style="padding:6px 10px;border:1px solid #e5e7eb;font-size:11px;">${c??''}</td>`).join('') + '</tr>';
                });
                if (allRows.length > 5) {
                    html += `<tr><td colspan="${headers.length}" style="padding:6px 10px;font-size:11px;color:#6b7280;font-style:italic;border:1px solid #e5e7eb;">... and ${allRows.length - 5} more rows</td></tr>`;
                }
                html += '</tbody></table>';
                document.getElementById('bulkPreview').innerHTML = html;
                document.getElementById('bulkSubmitBtn').style.display = 'inline-flex';
            };

            if (isXlsx) {
                // Use SheetJS to parse xlsx/xls
                const reader = new FileReader();
                reader.onload = function(e) {
                    const data = new Uint8Array(e.target.result);
                    const workbook = XLSX.read(data, { type: 'array' });
                    const sheet = workbook.Sheets[workbook.SheetNames[0]];
                    const jsonRows = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '' });

                    if (!jsonRows || jsonRows.length < 2) {
                        showToast('Excel file is empty or has no data rows.', 'error');
                        return;
                    }

                    // Find the actual header row (skip any rows before the column names)
                    let headerRowIdx = 0;
                    for (let i = 0; i < Math.min(jsonRows.length, 5); i++) {
                        const row = jsonRows[i].map(c => String(c).toLowerCase().trim());
                        if (row.includes('name') || row.includes('full name') || row.includes('client name')) {
                            headerRowIdx = i;
                            break;
                        }
                    }

                    const headers = jsonRows[headerRowIdx].map(h => String(h).trim());
                    const allRows = [];
                    for (let i = headerRowIdx + 1; i < jsonRows.length; i++) {
                        const row = jsonRows[i].map(c => String(c === null || c === undefined ? '' : c).trim());
                        if (row.some(c => c)) allRows.push(row);
                    }

                    parseAndPreview(headers, allRows);
                };
                reader.readAsArrayBuffer(file);
            } else {
                // CSV fallback
                const reader = new FileReader();
                reader.onload = function(e) {
                    const lines = e.target.result.trim().split('\n');
                    const headers = lines[0].split(',').map(h => h.trim().replace(/^"|"$/g,''));
                    const allRows = [];
                    for (let i = 1; i < lines.length; i++) {
                        const cols = lines[i].split(',').map(c => c.trim().replace(/^"|"$/g,''));
                        if (cols.some(c => c)) allRows.push(cols);
                    }
                    parseAndPreview(headers, allRows);
                };
                reader.readAsText(file);
            }
        });

        /* ── Bulk Upload — Submit ── */
        document.getElementById('bulkSubmitBtn').addEventListener('click', function() {
            if (!window._bulkAllRows || !window._bulkAllRows.length) return;
            const headers = window._bulkHeaders;
            const idx = (names) => { for(const n of names){ const i=headers.findIndex(h=>h.toLowerCase()===n); if(i>-1)return i; } return -1; };
            const ni = idx(['name','full name','client name','contact name']);
            if (ni === -1) { showToast('CSV must have a "name" column.', 'error'); return; }
            const ei  = idx(['email','e-mail']);
            const pi  = idx(['phone','mobile','number']);
            const di  = idx(['designation','role','title','position']);
            const ci  = idx(['company','company name','associated company']);
            const agi = idx(['assigned_agents','assigned agents','agent','agents']);
            const fbi = idx(['fb_url','facebook','facebook url','fb']);
            const lii = idx(['linkedin_url','linkedin','linkedin url']);
            const twi = idx(['twitter_url','twitter','x','x url','twitter url']);
            const igi = idx(['insta_url','instagram','instagram url','insta']);

            const mapped = window._bulkAllRows.map(row => ({
                name:          row[ni]  ?? '',
                email:         ei>-1  ? (row[ei]  ?? '') : '',
                phone:         pi>-1  ? (row[pi]  ?? '') : '',
                designation:   di>-1  ? (row[di]  ?? '') : '',
                company:       ci>-1  ? (row[ci]  ?? '') : '',
                assigned_agents: agi>-1 ? (row[agi] ?? '') : '',
                fb_url:        fbi>-1 ? (row[fbi] ?? '') : '',
                linkedin_url:  lii>-1 ? (row[lii] ?? '') : '',
                twitter_url:   twi>-1 ? (row[twi] ?? '') : '',
                insta_url:     igi>-1 ? (row[igi] ?? '') : '',
            }));

            this.disabled = true;
            this.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Uploading...';

            const form = document.createElement('form');
            form.method = 'POST'; form.action = 'client_list.php';
            const addH = (n,v) => { const i=document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; form.appendChild(i); };
            addH('bulk_upload_clients', '1');
            addH('bulk_rows', JSON.stringify(mapped));
            document.body.appendChild(form);
            form.submit();
        });
        /* ── Edit Modal ── */
        var _editCmsSelected = [];

        function openEditModal(id, name, email, phone, designation, companyId, assignedAgents, fbUrl, liUrl, twUrl, igUrl) {
            document.getElementById('edit_client_id').value       = id;
            document.getElementById('edit_client_name').value     = name;
            document.getElementById('edit_client_email').value    = email;
            document.getElementById('edit_client_phone').value    = phone;
            document.getElementById('edit_client_designation').value = designation;
            document.getElementById('edit_fb_url').value          = fbUrl;
            document.getElementById('edit_linkedin_url').value    = liUrl;
            document.getElementById('edit_twitter_url').value     = twUrl;
            document.getElementById('edit_insta_url').value       = igUrl;

            // Set company
            var compSel = document.getElementById('edit_company_id');
            if (compSel) compSel.value = companyId || '';
            // manager এর hidden input (disabled select form submit করে না)
            var compHid = document.getElementById('edit_company_id_hidden');
            if (compHid) compHid.value = companyId || '';

            // Reset wizard to step 1
            document.getElementById('epanel1').style.display = 'block';
            document.getElementById('epanel2').style.display = 'none';
            document.getElementById('ewstep1').className = 'wstep active';
            document.getElementById('ewstep1').querySelector('.wstep-circle').innerHTML = '1';
            document.getElementById('ewstep2').className = 'wstep';
            document.getElementById('editWstepLine').classList.remove('done');
            document.getElementById('editWizardSubtitle').textContent = 'Step 1 of 2 — Basic Information';

            // Set agents
            _editCmsSelected = assignedAgents ? assignedAgents.split(',').map(a => a.trim()).filter(Boolean) : [];
            editCmsRenderTags();
            editCmsRenderHidden();
            document.querySelectorAll('#editCmsOptionsList .cms-option').forEach(function(opt){
                var val = opt.getAttribute('data-value');
                var checked = _editCmsSelected.includes(val);
                opt.classList.toggle('checked', checked);
                opt.querySelector('input[type="checkbox"]').checked = checked;
            });

            openModal('editClientModal');
        }

        function editWizardNext() {
            var name = document.getElementById('edit_client_name');
            // manager name readonly তাই value validation এড়িয়ে যাও
            if (!name.readOnly && !name.value.trim()) { name.focus(); name.style.borderColor='#ef4444'; return; }
            name.style.borderColor='';
            document.getElementById('epanel1').style.display = 'none';
            document.getElementById('epanel2').style.display = 'block';
            document.getElementById('ewstep1').className = 'wstep done';
            document.getElementById('ewstep1').querySelector('.wstep-circle').innerHTML = '<i class="fa-solid fa-check" style="font-size:10px;"></i>';
            document.getElementById('ewstep2').className = 'wstep active';
            document.getElementById('editWstepLine').classList.add('done');
            document.getElementById('editWizardSubtitle').textContent = 'Step 2 of 2 — Socials & Assignment';
        }

        function editWizardBack() {
            document.getElementById('epanel2').style.display = 'none';
            document.getElementById('epanel1').style.display = 'block';
            document.getElementById('ewstep1').className = 'wstep active';
            document.getElementById('ewstep1').querySelector('.wstep-circle').innerHTML = '1';
            document.getElementById('ewstep2').className = 'wstep';
            document.getElementById('editWstepLine').classList.remove('done');
            document.getElementById('editWizardSubtitle').textContent = 'Step 1 of 2 — Basic Information';
        }

        /* Edit CMS (Agent Multi-Select) */
        function editCmsToggle() {
            var trigger  = document.getElementById('editCmsTrigger');
            var dropdown = document.getElementById('editCmsDropdown');
            var isOpen   = trigger.classList.contains('open');
            trigger.classList.toggle('open', !isOpen);
            dropdown.classList.toggle('open', !isOpen);
        }
        function editCmsToggleOption(el) {
            var val   = el.getAttribute('data-value');
            var label = el.getAttribute('data-label');
            var idx   = _editCmsSelected.indexOf(val);
            if (idx > -1) { _editCmsSelected.splice(idx, 1); el.classList.remove('checked'); el.querySelector('input[type="checkbox"]').checked = false; }
            else          { _editCmsSelected.push(val);       el.classList.add('checked');    el.querySelector('input[type="checkbox"]').checked = true; }
            editCmsRenderTags();
            editCmsRenderHidden();
        }
        function editCmsRenderTags() {
            var container = document.getElementById('editCmsTags');
            var placeholder = document.getElementById('editCmsPlaceholder');
            if (!_editCmsSelected.length) {
                container.innerHTML = '';
                container.appendChild(placeholder);
                placeholder.style.display = '';
                return;
            }
            placeholder.style.display = 'none';
            container.innerHTML = '';
            _editCmsSelected.forEach(function(val) {
                var tag = document.createElement('span');
                tag.className = 'cms-tag';
                tag.innerHTML = val + ' <span class="cms-tag-x" onclick="editCmsRemove(\'' + val + '\',event)">×</span>';
                container.appendChild(tag);
            });
        }
        function editCmsRemove(val, e) {
            if(e) e.stopPropagation();
            _editCmsSelected = _editCmsSelected.filter(function(v){ return v !== val; });
            document.querySelectorAll('#editCmsOptionsList .cms-option').forEach(function(opt){
                if(opt.getAttribute('data-value') === val){
                    opt.classList.remove('checked');
                    opt.querySelector('input[type="checkbox"]').checked = false;
                }
            });
            editCmsRenderTags();
            editCmsRenderHidden();
        }
        function editCmsRenderHidden() {
            var container = document.getElementById('editCmsHiddenInputs');
            container.innerHTML = '';
            _editCmsSelected.forEach(function(val) {
                var inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = 'edit_assigned_agents[]'; inp.value = val;
                container.appendChild(inp);
            });
        }
        function editCmsSearch(q) {
            var lq = q.toLowerCase();
            document.querySelectorAll('#editCmsOptionsList .cms-option').forEach(function(opt){
                var label = opt.getAttribute('data-label').toLowerCase();
                opt.style.display = label.includes(lq) ? '' : 'none';
            });
        }
        document.addEventListener('click', function(e) {
            var trigger  = document.getElementById('editCmsTrigger');
            var dropdown = document.getElementById('editCmsDropdown');
            var wrap     = document.getElementById('editAgentMultiSelect');
            if(trigger && dropdown && wrap && !wrap.contains(e.target)){
                trigger.classList.remove('open');
                dropdown.classList.remove('open');
            }
        });

        // ================================================================
        // BULK EDIT CLIENTS — CSV upload (same style as company_list.php)
        // ================================================================
        let _clientEditFile = null;
        let _clientEditData = [];

        function clientEditDragOver(e)  {
            e.preventDefault();
            document.getElementById('clientEditDropZone').style.borderColor = '#f59e0b';
            document.getElementById('clientEditDropZone').style.background  = '#fef3c7';
        }
        function clientEditDragLeave(e) {
            document.getElementById('clientEditDropZone').style.borderColor = '#d1d5db';
            document.getElementById('clientEditDropZone').style.background  = '';
        }
        function clientEditDrop(e) {
            e.preventDefault();
            clientEditDragLeave(e);
            const f = e.dataTransfer.files[0];
            if (f && f.name.endsWith('.csv')) { _clientEditFile = f; clientEditParseFile(f); }
            else showToast('Please drop a .csv file only!', 'error');
        }
        function clientEditReadFile(input) {
            _clientEditFile = input.files[0];
            if (_clientEditFile) clientEditParseFile(_clientEditFile);
        }
        function clientEditParseFile(file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                const lines = e.target.result.split('\n').filter(l => l.trim());
                if (lines.length < 2) { showToast('CSV has no data rows!', 'error'); return; }
                const headers = lines[0].split(',').map(h => h.replace(/^"|"$/g,'').trim());
                _clientEditData = [];
                for (let i = 1; i < lines.length; i++) {
                    const cols = lines[i].split(',').map(c => c.replace(/^"|"$/g,'').trim());
                    if (!cols[0]) continue;
                    _clientEditData.push(cols);
                }
                // File info
                document.getElementById('clientEditFileName').textContent = file.name;
                document.getElementById('clientEditRowCount').textContent = _clientEditData.length;
                document.getElementById('clientEditFileInfo').style.display = 'block';
                document.getElementById('clientEditDropZone').style.borderColor = '#f59e0b';
                document.getElementById('clientEditDropZone').style.background  = '#fef3c7';

                // Preview table
                const headRow = '<tr>' + headers.map(h => `<th style="padding:8px 10px;font-weight:700;color:#374151;text-align:left;white-space:nowrap;">${h}</th>`).join('') + '</tr>';
                document.getElementById('clientEditPreviewHead').innerHTML = headRow;
                const previewRows = _clientEditData.slice(0, 5).map(cols =>
                    '<tr style="border-top:1px solid #e5e7eb;">' + headers.map((_, idx) => `<td style="padding:7px 10px;color:#374151;white-space:nowrap;">${cols[idx] || '<span style="color:#9ca3af;">—</span>'}</td>`).join('') + '</tr>'
                ).join('');
                document.getElementById('clientEditPreviewBody').innerHTML = previewRows;
                document.getElementById('clientEditPreviewWrap').style.display = 'block';

                // Enable upload button
                const btn = document.getElementById('clientEditUploadBtn');
                btn.disabled = false; btn.style.opacity = '1';
            };
            reader.readAsText(file);
        }
        function clientEditSubmit() {
            if (!_clientEditFile) { showToast('Please select a CSV file first!', 'error'); return; }
            const dt = new DataTransfer();
            dt.items.add(_clientEditFile);
            document.getElementById('clientEditHiddenFile').files = dt.files;
            let hid = document.getElementById('bulk_edit_client_submit_hidden');
            if (!hid) {
                hid = document.createElement('input');
                hid.type = 'hidden'; hid.id = 'bulk_edit_client_submit_hidden';
                hid.name = 'bulk_edit_clients_csv'; hid.value = '1';
                document.getElementById('clientBulkEditForm').appendChild(hid);
            }
            document.getElementById('clientBulkEditForm').submit();
        }

        // ── Show toast + Swal results on page load ──
        window.addEventListener('load', function() {
            <?php if ($toastMessage): ?>
            showToast("<?php echo addslashes($toastMessage); ?>", "<?php echo $toastType; ?>");
            <?php endif; ?>

            <?php if (!empty($clientBulkEditResults)): ?>
            <?php
                $ber     = $clientBulkEditResults;
                $updated = (int)$ber['updated'];
                $skipped = (int)$ber['skipped'];
                $notFound = $ber['notFound'] ?? [];
                $berrors  = $ber['errors']   ?? [];
            ?>
            <?php if (!empty($notFound)): ?>
            var nfList = <?php echo json_encode($notFound); ?>;
            var nfHtml = nfList.map(function(u) {
                return '<li style="padding:4px 0;border-bottom:1px solid #fee2e2;font-size:13px;color:#dc2626;"><i class="fa-solid fa-circle-xmark" style="margin-right:6px;color:#f87171;"></i>' + u + '</li>';
            }).join('');
            Swal.fire({
                icon: 'warning',
                title: '<span style="font-size:18px;">ID Not Found!</span>',
                html: '<p style="font-size:13px;color:#374151;margin-bottom:12px;">The following rows had <b>no matching client ID</b> in the database and were skipped.</p>'
                    + '<ul style="list-style:none;padding:8px 12px;max-height:200px;overflow-y:auto;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;text-align:left;">' + nfHtml + '</ul>'
                    <?php if ($updated > 0): ?>
                    + '<p style="margin-top:14px;font-size:12px;color:#6b7280;">✅ <b><?php echo $updated; ?> client(s)</b> updated successfully.</p>'
                    <?php else: ?>
                    + '<p style="margin-top:14px;font-size:12px;color:#ef4444;">❌ No clients were updated.</p>'
                    <?php endif; ?>,
                confirmButtonText: 'Got it',
                confirmButtonColor: '#f59e0b',
                customClass: { container: 'swal2-container' },
                width: '480px'
            });
            <?php elseif ($updated > 0 && empty($berrors)): ?>
            Swal.fire({
                icon: 'success',
                title: 'Bulk Edit Successful!',
                html: '<p style="font-size:14px;color:#374151;"><b><?php echo $updated; ?> client(s)</b> updated successfully.</p>',
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
                text: 'No clients were updated. Please check your CSV and make sure the id column is correct.',
                confirmButtonText: 'OK', confirmButtonColor: '#ef4444',
                customClass: { container: 'swal2-container' }
            });
            <?php endif; ?>
            <?php endif; ?>
        });

    </script>
</body>
</html>