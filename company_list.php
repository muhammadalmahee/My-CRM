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

$toastMessage = "";
$toastType    = "";

// ========================================================================
// AJAX: Get contacts by company_id
// ========================================================================
if (isset($_GET['get_contacts']) && isset($_GET['company_id']) && isset($conn)) {
    header('Content-Type: application/json');
    $cid = (int)$_GET['company_id'];
    $res = mysqli_query($conn, "SELECT id, name, email, phone, designation FROM contacts WHERE company_id = $cid ORDER BY id ASC");
    $out = [];
    if ($res) while ($r = mysqli_fetch_assoc($res)) $out[] = $r;
    echo json_encode($out);
    exit();
}

// ========================================================================
// CSV EXPORT
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['export_companies_csv'])) {
    if (isset($conn) && ($_SESSION['role'] ?? '') !== 'agent') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=companies_export_' . date('Y-m-d') . '.csv');
        $out = fopen("php://output", "w");
        fputcsv($out, ['ID','Company Name','Assigned Agent','Total Contacts','Email','Phone','Website']);
        $q = mysqli_query($conn, "SELECT * FROM companies ORDER BY id DESC");
        if ($q) while ($r = mysqli_fetch_assoc($q))
            fputcsv($out, [$r['id'],$r['company_name'],$r['assigned_agent'],$r['total_contacts'],$r['company_email']??'',$r['company_number']??'',$r['company_website']??'']);
        fclose($out);
        exit();
    }
}

// ========================================================================
// CREATE COMPANY
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_company'])) {
    if (isset($conn) && ($_SESSION['role'] ?? '') !== 'agent') {
        $comp_name     = mysqli_real_escape_string($conn, $_POST['company_name']    ?? '');
        // Multi-select: assigned_agent[] array → comma-join
        $assigned_raw  = $_POST['assigned_agent'] ?? [];
        if (is_array($assigned_raw)) {
            $assigned_raw = array_filter(array_map('trim', $assigned_raw));
            $assigned = mysqli_real_escape_string($conn, !empty($assigned_raw) ? implode(',', $assigned_raw) : 'Unassigned');
        } else {
            $assigned = mysqli_real_escape_string($conn, trim($assigned_raw) ?: 'Unassigned');
        }
        $country_code  = mysqli_real_escape_string($conn, $_POST['company_country_code'] ?? '');
        $raw_number    = mysqli_real_escape_string($conn, $_POST['company_number'] ?? '');
        $comp_number   = trim($country_code . ' ' . $raw_number);
        $comp_email    = mysqli_real_escape_string($conn, $_POST['company_email']   ?? '');
        $comp_website  = mysqli_real_escape_string($conn, $_POST['company_website'] ?? '');
        $fb_url        = mysqli_real_escape_string($conn, $_POST['fb_url']          ?? '');
        $linkedin_url  = mysqli_real_escape_string($conn, $_POST['linkedin_url']    ?? '');
        $insta_url     = mysqli_real_escape_string($conn, $_POST['insta_url']       ?? '');
        $twitter_url   = mysqli_real_escape_string($conn, $_POST['twitter_url']     ?? '');

        $creator_id = intval($_SESSION['user_id'] ?? 0);
        $sql = "INSERT INTO companies (company_name, assigned_agent, total_contacts, company_email, company_number, company_website, fb_url, linkedin_url, insta_url, twitter_url, created_by)
                VALUES ('$comp_name', '$assigned', 0, '$comp_email', '$comp_number', '$comp_website', '$fb_url', '$linkedin_url', '$insta_url', '$twitter_url', $creator_id)";
        try {
            if (mysqli_query($conn, $sql)) {
                $toastMessage = "Company added successfully!"; $toastType = "success";
            } else {
                $toastMessage = "Failed: " . mysqli_error($conn); $toastType = "error";
            }
        } catch (mysqli_sql_exception $e) {
            $toastMessage = "DB Error: " . $e->getMessage(); $toastType = "error";
        }
    }
}

// ========================================================================
// UPDATE COMPANY
// ========================================================================
// super_admin/admin → company name + assigned_agent update করতে পারবে
// manager → শুধু assigned_agent update করতে পারবে
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_company']) && !in_array($_SESSION['role'] ?? '', ['agent'])) {
    if (isset($conn)) {
        $id       = (int)($_POST['edit_company_id'] ?? 0);
        $name     = mysqli_real_escape_string($conn, $_POST['edit_company_name'] ?? '');
        // Multi-select: edit_assigned_agent[] array → comma-join
        $agent_raw = $_POST['edit_assigned_agent'] ?? [];
        if (is_array($agent_raw)) {
            $agent_raw = array_filter(array_map('trim', $agent_raw));
            $agent = mysqli_real_escape_string($conn, !empty($agent_raw) ? implode(',', $agent_raw) : 'Unassigned');
        } else {
            $agent = mysqli_real_escape_string($conn, trim($agent_raw) ?: 'Unassigned');
        }
        // manager শুধু assigned_agent update করতে পারবে, company name পরিবর্তন করতে পারবে না
        if (($_SESSION['role'] ?? '') === 'manager') {
            $sql = "UPDATE companies SET assigned_agent='$agent' WHERE id=$id";
        } else {
            $sql = "UPDATE companies SET company_name='$name', assigned_agent='$agent' WHERE id=$id";
        }
        try {
            if (mysqli_query($conn, $sql)) {
                // ── Auto-assign: company-র সব contacts-এ সব agents যোগ করো ──
                if ($agent !== 'Unassigned' && $agent !== '') {
                    $newAgents = array_filter(array_map('trim', explode(',', $agent)));
                    $ctRes = mysqli_query($conn, "SELECT id, assigned_agents FROM contacts WHERE company_id = $id");
                    if ($ctRes) {
                        while ($ctRow = mysqli_fetch_assoc($ctRes)) {
                            $existing    = $ctRow['assigned_agents'] ?? '';
                            $existingArr = array_filter(array_map('trim', explode(',', $existing)));
                            $changed = false;
                            foreach ($newAgents as $ag) {
                                if (!in_array($ag, $existingArr)) {
                                    $existingArr[] = $ag;
                                    $changed = true;
                                }
                            }
                            if ($changed) {
                                $newVal = mysqli_real_escape_string($conn, implode(',', $existingArr));
                                mysqli_query($conn, "UPDATE contacts SET assigned_agents='$newVal' WHERE id={$ctRow['id']}");
                            }
                        }
                    }
                }
                $toastMessage = "Company updated & contacts auto-assigned!"; $toastType = "success";
            } else {
                $toastMessage = "Update failed: " . mysqli_error($conn); $toastType = "error";
            }
        } catch (mysqli_sql_exception $e) {
            $toastMessage = "DB Error: " . $e->getMessage(); $toastType = "error";
        }
    }
}

// ========================================================================
// TEMPLATE DOWNLOAD
// ========================================================================
if (isset($_GET['download_company_template'])) {
    if (($_SESSION['role'] ?? '') === 'agent') { header("Location: company_list.php"); exit(); }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="company_upload_template.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    echo "company_name,assigned_agent,company_email,company_number,company_website,fb_url,linkedin_url,insta_url,twitter_url\n";
    exit();
}

// ========================================================================
// BULK EDIT TEMPLATE DOWNLOAD
// ========================================================================
if (isset($_GET['download_edit_template'])) {
    if (($_SESSION['role'] ?? '') === 'agent') { header("Location: company_list.php"); exit(); }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="company_bulk_edit_template.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    $out = fopen("php://output", "w");
    fputcsv($out, ['id', 'company_name', 'assigned_agent', 'company_email', 'company_number', 'company_website', 'fb_url', 'linkedin_url', 'insta_url', 'twitter_url']);
    // Real company data থেকে example দেখাও
    if (isset($conn)) {
        $ex = mysqli_query($conn, "SELECT id, company_name, assigned_agent, company_email, company_number, company_website, fb_url, linkedin_url, insta_url, twitter_url FROM companies ORDER BY id ASC LIMIT 3");
        while ($r = mysqli_fetch_assoc($ex)) {
            fputcsv($out, [
                $r['id'], $r['company_name'], $r['assigned_agent'] ?? 'Unassigned',
                $r['company_email'] ?? '', $r['company_number'] ?? '', $r['company_website'] ?? '',
                $r['fb_url'] ?? '', $r['linkedin_url'] ?? '', $r['insta_url'] ?? '', $r['twitter_url'] ?? ''
            ]);
        }
    }
    fclose($out);
    exit();
}

// ========================================================================
// BULK UPLOAD
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_upload_companies'])) {
    if (isset($conn) && ($_SESSION['role'] ?? '') !== 'agent' && isset($_FILES['company_csv']) && $_FILES['company_csv']['error'] == 0) {
        $handle = fopen($_FILES['company_csv']['tmp_name'], "r");
        $cnt = 0; $skipped = 0;
        try {
            $first = true;
            while (($data = fgetcsv($handle, 2000, ",")) !== FALSE) {
                if ($first) { $first = false; continue; } // skip header row
                $cn  = mysqli_real_escape_string($conn, trim($data[0] ?? ''));
                if (empty($cn)) { $skipped++; continue; }
                $ca  = mysqli_real_escape_string($conn, trim($data[1] ?? 'Unassigned'));
                $ce  = mysqli_real_escape_string($conn, trim($data[2] ?? ''));
                $cp  = mysqli_real_escape_string($conn, trim($data[3] ?? ''));
                $cw  = mysqli_real_escape_string($conn, trim($data[4] ?? ''));
                $fb  = mysqli_real_escape_string($conn, trim($data[5] ?? ''));
                $li  = mysqli_real_escape_string($conn, trim($data[6] ?? ''));
                $ig  = mysqli_real_escape_string($conn, trim($data[7] ?? ''));
                $tw  = mysqli_real_escape_string($conn, trim($data[8] ?? ''));
                $ok = mysqli_query($conn, "INSERT INTO companies (company_name, assigned_agent, total_contacts, company_email, company_number, company_website, fb_url, linkedin_url, insta_url, twitter_url, created_by)
                    VALUES ('$cn','$ca',0,'$ce','$cp','$cw','$fb','$li','$ig','$tw'," . intval($_SESSION['user_id'] ?? 0) . ")");
                $ok ? $cnt++ : $skipped++;
            }
            fclose($handle);
            $toastMessage = "$cnt company/companies uploaded successfully!" . ($skipped ? " ($skipped skipped)" : "");
            $toastType = "success";
        } catch (mysqli_sql_exception $e) {
            $toastMessage = "Upload Failed!"; $toastType = "error";
        }
    } else {
        $toastMessage = "Please select a valid CSV file."; $toastType = "error";
    }
}

// ========================================================================
// BULK EDIT CSV HANDLER
// ========================================================================
$bulkEditResults = [];
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_edit_companies'])) {
    if (isset($conn) && !in_array($_SESSION['role'] ?? '', ['agent', 'manager']) && isset($_FILES['company_edit_csv']) && $_FILES['company_edit_csv']['error'] == 0) {
        $handle = fopen($_FILES['company_edit_csv']['tmp_name'], "r");
        $updated = 0; $skipped = 0;
        $notFound = []; $berrors = [];
        try {
            $first = true;
            while (($data = fgetcsv($handle, 2000, ",")) !== FALSE) {
                if ($first) { $first = false; continue; } // skip header row
                $id = (int)trim($data[0] ?? 0);
                if ($id <= 0) { $skipped++; continue; }
                
                $cn  = mysqli_real_escape_string($conn, trim($data[1] ?? ''));
                $ca  = mysqli_real_escape_string($conn, trim($data[2] ?? 'Unassigned'));
                $ce  = mysqli_real_escape_string($conn, trim($data[3] ?? ''));
                $cp  = mysqli_real_escape_string($conn, trim($data[4] ?? ''));
                $cw  = mysqli_real_escape_string($conn, trim($data[5] ?? ''));
                $fb  = mysqli_real_escape_string($conn, trim($data[6] ?? ''));
                $li  = mysqli_real_escape_string($conn, trim($data[7] ?? ''));
                $ig  = mysqli_real_escape_string($conn, trim($data[8] ?? ''));
                $tw  = mysqli_real_escape_string($conn, trim($data[9] ?? ''));
                
                // Check if company exists
                $chk = mysqli_query($conn, "SELECT id FROM companies WHERE id=$id LIMIT 1");
                if (!$chk || mysqli_num_rows($chk) === 0) {
                    $notFound[] = "ID $id";
                    $skipped++; continue;
                }
                
                $sql = "UPDATE companies SET company_name='$cn', assigned_agent='$ca', company_email='$ce', company_number='$cp', company_website='$cw', fb_url='$fb', linkedin_url='$li', insta_url='$ig', twitter_url='$tw' WHERE id=$id";
                if (mysqli_query($conn, $sql)) {
                    $updated++;
                    // ── Auto-assign: company-র সব contacts-এ সব agents যোগ করো ──
                    if ($ca !== 'Unassigned' && $ca !== '') {
                        $newAgents2 = array_filter(array_map('trim', explode(',', $ca)));
                        $ctRes2 = mysqli_query($conn, "SELECT id, assigned_agents FROM contacts WHERE company_id = $id");
                        if ($ctRes2) {
                            while ($ctRow2 = mysqli_fetch_assoc($ctRes2)) {
                                $existing2 = $ctRow2['assigned_agents'] ?? '';
                                $existArr2 = array_filter(array_map('trim', explode(',', $existing2)));
                                $changed2  = false;
                                foreach ($newAgents2 as $ag2) {
                                    if (!in_array($ag2, $existArr2)) {
                                        $existArr2[] = $ag2;
                                        $changed2 = true;
                                    }
                                }
                                if ($changed2) {
                                    $newVal2 = mysqli_real_escape_string($conn, implode(',', $existArr2));
                                    mysqli_query($conn, "UPDATE contacts SET assigned_agents='$newVal2' WHERE id={$ctRow2['id']}");
                                }
                            }
                        }
                    }
                } else {
                    $berrors[] = "ID $id: " . mysqli_error($conn);
                    $skipped++;
                }
            }
            fclose($handle);
            $bulkEditResults = [
                'updated'  => $updated,
                'skipped'  => $skipped,
                'notFound' => $notFound,
                'errors'   => $berrors,
            ];
        } catch (mysqli_sql_exception $e) {
            $toastMessage = "Edit Failed!"; $toastType = "error";
        }
    } else {
        $toastMessage = "Please select a valid CSV file."; $toastType = "error";
    }
}

// ========================================================================
// DELETE COMPANY — DISABLED (use active/inactive toggle instead)
// ========================================================================
// DELETE has been removed for all roles.
// super_admin and admin can toggle Active / Inactive status.

// ── Active / Inactive toggle ──
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['toggle_company_status'])) {
    $_togRole = $_SESSION['role'] ?? '';
    if (isset($conn) && in_array($_togRole, ['super_admin', 'admin'])) {
        $tog_id      = (int)($_POST['toggle_company_id'] ?? 0);
        $tog_status  = ($_POST['toggle_company_new_status'] ?? '') === 'active' ? 'active' : 'inactive';
        $tog_user_id = intval($_SESSION['user_id'] ?? 0);

        // Ensure columns exist — companies
        $_st_cols = []; $_st_cr = mysqli_query($conn, "SHOW COLUMNS FROM companies");
        if ($_st_cr) { while ($_sc = mysqli_fetch_assoc($_st_cr)) $_st_cols[] = $_sc['Field']; }
        if (!in_array('status',           $_st_cols)) mysqli_query($conn, "ALTER TABLE companies ADD COLUMN status VARCHAR(10) NOT NULL DEFAULT 'active'");
        if (!in_array('inactive_by',      $_st_cols)) mysqli_query($conn, "ALTER TABLE companies ADD COLUMN inactive_by INT DEFAULT NULL");
        if (!in_array('inactive_by_role', $_st_cols)) mysqli_query($conn, "ALTER TABLE companies ADD COLUMN inactive_by_role VARCHAR(20) DEFAULT NULL");

        // Ensure columns exist — contacts
        $_ct_cols = []; $_ct_cr = mysqli_query($conn, "SHOW COLUMNS FROM contacts");
        if ($_ct_cr) { while ($_cc = mysqli_fetch_assoc($_ct_cr)) $_ct_cols[] = $_cc['Field']; }
        if (!in_array('status',               $_ct_cols)) mysqli_query($conn, "ALTER TABLE contacts ADD COLUMN status VARCHAR(10) NOT NULL DEFAULT 'active'");
        if (!in_array('inactive_by',          $_ct_cols)) mysqli_query($conn, "ALTER TABLE contacts ADD COLUMN inactive_by INT DEFAULT NULL");
        if (!in_array('inactive_by_role',     $_ct_cols)) mysqli_query($conn, "ALTER TABLE contacts ADD COLUMN inactive_by_role VARCHAR(20) DEFAULT NULL");
        if (!in_array('company_inactive_ref', $_ct_cols)) mysqli_query($conn, "ALTER TABLE contacts ADD COLUMN company_inactive_ref INT DEFAULT NULL");

        if ($tog_status === 'inactive') {
            // Company inactive করো
            mysqli_query($conn, "UPDATE companies SET status='inactive', inactive_by=$tog_user_id, inactive_by_role='$_togRole' WHERE id=$tog_id");

            // এই company-র সব active client কে auto inactive করো
            // company_inactive_ref দিয়ে mark করো যাতে company active হলে আবার active করা যায়
            mysqli_query($conn, "UPDATE contacts SET
                status='inactive',
                inactive_by=$tog_user_id,
                inactive_by_role='$_togRole',
                company_inactive_ref=$tog_id
                WHERE company_id=$tog_id AND status='active'");

            $toastMessage = "Company and its clients marked as Inactive!";
            $toastType    = "success";

        } else {
            // Company active করো
            mysqli_query($conn, "UPDATE companies SET status='active', inactive_by=NULL, inactive_by_role=NULL WHERE id=$tog_id");

            // শুধু এই company-র কারণে inactive হওয়া client গুলো আবার active করো
            // (অন্য কারণে inactive হওয়া client গুলো touch করা হবে না)
            mysqli_query($conn, "UPDATE contacts SET
                status='active',
                inactive_by=NULL,
                inactive_by_role=NULL,
                company_inactive_ref=NULL
                WHERE company_id=$tog_id AND company_inactive_ref=$tog_id");

            $toastMessage = "Company and its clients marked as Active!";
            $toastType    = "success";
        }
    } else {
        $toastMessage = "Permission denied!";
        $toastType    = "error";
    }
}

// ========================================================================
// FETCH: Assignee Options
// ========================================================================
// ── Role hierarchy: super_admin কখনো assignable নয়
// ── admin/manager → নিজের নিচের roles (manager, agent) দেখাবে
// ── super_admin (global) → admin বাদে সব দেখাবে
$assigneeOptions = "";
$assigneeData    = []; // JS এর জন্য [{id, name, username, role}]
if (isset($conn)) {
    $_callerRole = $_SESSION['role'] ?? '';
    if ($_callerRole === 'super_admin') {
        // super_admin → admin, manager, agent দেখাবে (super_admin বাদ)
        $_aq = mysqli_query($conn, "SELECT id, name, username, role FROM users WHERE role IN ('admin','manager','agent') AND status='active' ORDER BY name ASC");
    } elseif ($_callerRole === 'admin') {
        // admin → নিজের direct manager/agent + সেই managers এর under এর agents দেখাবে
        // hierarchy: reporting_to = admin's username/name
        $_adminId       = intval($_SESSION['user_id'] ?? 0);
        $_adminName     = mysqli_real_escape_string($conn, $_SESSION['name']     ?? '');
        $_adminUsername = mysqli_real_escape_string($conn, $_SESSION['username'] ?? '');
        // admin এর direct under এর managers খোঁজো (reporting_to = admin name/username)
        $_managerNames = [];
        $_mq = mysqli_query($conn, "SELECT id, name, username FROM users WHERE role='manager' AND status='active'
            AND (reporting_to='$_adminUsername' OR reporting_to='$_adminName')");
        if ($_mq) {
            while ($_mr = mysqli_fetch_assoc($_mq)) {
                $_managerNames[] = "'" . mysqli_real_escape_string($conn, $_mr['name'])     . "'";
                $_managerNames[] = "'" . mysqli_real_escape_string($conn, $_mr['username']) . "'";
            }
        }
        // managers এর under এর agents ও include করো (reporting_to = manager name/username)
        $_managerNamesStr = !empty($_managerNames) ? implode(',', $_managerNames) : "''";
        $_aq = mysqli_query($conn, "SELECT id, name, username, role FROM users WHERE status='active' AND (
            (role IN ('manager','agent') AND (reporting_to='$_adminUsername' OR reporting_to='$_adminName'))
            OR (role = 'agent' AND reporting_to IN ($_managerNamesStr))
        ) ORDER BY role DESC, name ASC");
    } elseif ($_callerRole === 'manager') {
        // manager → নিজের under এর agents + নিজের parent admin দেখাবে
        // hierarchy: reporting_to = manager's username/name
        $_managerId       = intval($_SESSION['user_id'] ?? 0);
        $_managerName     = mysqli_real_escape_string($conn, $_SESSION['name']     ?? '');
        $_managerUsername = mysqli_real_escape_string($conn, $_SESSION['username'] ?? '');
        // manager এর parent admin খোঁজো (reporting_to field এ manager এর reporting_to আছে)
        $_mParentAdminId = 0;
        $_mParentQ = mysqli_query($conn, "SELECT reporting_to FROM users WHERE id = $_managerId LIMIT 1");
        if ($_mParentQ && $_mpr = mysqli_fetch_assoc($_mParentQ)) {
            $_mRepTo = mysqli_real_escape_string($conn, $_mpr['reporting_to'] ?? '');
            if (!empty($_mRepTo)) {
                $_aQ = mysqli_query($conn, "SELECT id FROM users WHERE role='admin' AND status='active'
                    AND (username='$_mRepTo' OR name='$_mRepTo') LIMIT 1");
                if ($_aQ && $_ar = mysqli_fetch_assoc($_aQ)) $_mParentAdminId = (int)$_ar['id'];
            }
        }
        // under এর agents + parent admin
        $_aq = mysqli_query($conn, "SELECT id, name, username, role FROM users WHERE status='active' AND (
            (role = 'agent' AND (reporting_to='$_managerUsername' OR reporting_to='$_managerName'))
            " . ($_mParentAdminId > 0 ? "OR (role = 'admin' AND id = $_mParentAdminId)" : "") . "
        ) ORDER BY role DESC, name ASC");
    } else {
        $_aq = false;
    }
    if ($_aq) {
        while ($ur = mysqli_fetch_assoc($_aq)) {
            $assigneeOptions .= "<option value='" . htmlspecialchars($ur['name']) . "'>" . htmlspecialchars($ur['name']) . " (" . htmlspecialchars($ur['username']) . ")</option>";
            $assigneeData[]   = ['id' => $ur['id'], 'name' => $ur['name'], 'username' => $ur['username'], 'role' => $ur['role']];
        }
    }
}
$assigneeDataJson = json_encode($assigneeData);

// ========================================================================
// FETCH: Company Table Rows
// ========================================================================
$hasCompanies    = false;
$companyTableRows = "";
$totalCompanies  = "0";

if (isset($conn)) {
    // ── Ensure required columns exist ──
    $_comp_cols = []; $_comp_cr = mysqli_query($conn, "SHOW COLUMNS FROM companies");
    if ($_comp_cr) { while ($_cc = mysqli_fetch_assoc($_comp_cr)) $_comp_cols[] = $_cc['Field']; }
    if (!in_array('created_by',       $_comp_cols)) mysqli_query($conn, "ALTER TABLE companies ADD COLUMN created_by INT DEFAULT NULL");
    if (!in_array('status',           $_comp_cols)) mysqli_query($conn, "ALTER TABLE companies ADD COLUMN status VARCHAR(10) NOT NULL DEFAULT 'active'");
    if (!in_array('inactive_by',      $_comp_cols)) mysqli_query($conn, "ALTER TABLE companies ADD COLUMN inactive_by INT DEFAULT NULL");
    if (!in_array('inactive_by_role', $_comp_cols)) mysqli_query($conn, "ALTER TABLE companies ADD COLUMN inactive_by_role VARCHAR(20) DEFAULT NULL");

    try {
        $currentUserRole = $_SESSION['role']     ?? '';
        $currentUserId   = intval($_SESSION['user_id'] ?? 0);
        $currentName     = mysqli_real_escape_string($conn, $_SESSION['name']     ?? '');
        $currentUsername = mysqli_real_escape_string($conn, $_SESSION['username'] ?? '');

        // ── Inactive visibility logic ──
        // superadmin inactive করলে → শুধু superadmin দেখবে
        // admin inactive করলে → শুধু ঐ admin + superadmin দেখবে
        // active record → role অনুযায়ী স্বাভাবিক নিয়মে

        if ($currentUserRole === 'super_admin') {
            // superadmin সব দেখবে — active এবং যেকোনো inactive (নিজে বা admin করা)
            $agentWhere = "";

        } elseif ($currentUserRole === 'admin') {
            // admin নিজের + under-এর managers এর create করা companies দেখবে
            $_adSubIds = [$currentUserId];
            $_adSubQ = mysqli_query($conn, "SELECT id FROM users WHERE status='active'
                AND role = 'manager'
                AND (reporting_to='$currentUsername' OR reporting_to='$currentName'
                     OR created_by='$currentUsername' OR created_by='$currentName')");
            if ($_adSubQ) { while ($_adSubR = mysqli_fetch_assoc($_adSubQ)) $_adSubIds[] = (int)$_adSubR['id']; }
            $_adSubIdsStr = implode(',', $_adSubIds);
            $agentWhere = "WHERE (
                               c.created_by = $currentUserId
                               OR c.created_by IN ($_adSubIdsStr)
                               OR c.assigned_agent LIKE '%$currentName%'
                               OR c.assigned_agent LIKE '%$currentUsername%'
                           )
                           AND (c.status = 'active' OR c.status IS NULL OR c.status = '' OR (c.status = 'inactive' AND c.inactive_by = $currentUserId))";

        } elseif ($currentUserRole === 'manager') {
            // manager দেখবে:
            // 1. নিজের create করা companies
            // 2. parent admin এর create করা companies
            //    → parent admin খোঁজা হবে: যে admin এর created_by = manager কে create করেছে
            //      অথবা reporting_to field এ manager এর username/name match করে
            // 3. যেসব company তে manager এর name/username assigned_agent এ আছে
            $_parentAdminId = 0;
            // প্রথমে manager এর reporting_to দিয়ে parent admin খোঁজো
            $_parentQ = mysqli_query($conn, "SELECT reporting_to FROM users WHERE id = $currentUserId LIMIT 1");
            if ($_parentQ && $_pRow = mysqli_fetch_assoc($_parentQ)) {
                $_repTo = mysqli_real_escape_string($conn, $_pRow['reporting_to'] ?? '');
                if (!empty($_repTo)) {
                    $_adminQ = mysqli_query($conn, "SELECT id FROM users WHERE (username='$_repTo' OR name='$_repTo') AND role='admin' LIMIT 1");
                    if ($_adminQ && $_aRow = mysqli_fetch_assoc($_adminQ)) $_parentAdminId = (int)$_aRow['id'];
                }
            }
            // fallback: যে admin এই manager কে created করেছে (created_by = admin's id)
            if ($_parentAdminId == 0) {
                $_cbQ = mysqli_query($conn, "SELECT created_by FROM users WHERE id = $currentUserId LIMIT 1");
                if ($_cbQ && $_cbRow = mysqli_fetch_assoc($_cbQ)) {
                    $_cbId = intval($_cbRow['created_by'] ?? 0);
                    if ($_cbId > 0) {
                        $_adminQ3 = mysqli_query($conn, "SELECT id FROM users WHERE id=$_cbId AND role='admin' LIMIT 1");
                        if ($_adminQ3 && $_aRow3 = mysqli_fetch_assoc($_adminQ3)) $_parentAdminId = (int)$_aRow3['id'];
                    }
                }
            }
            $_createdByIds = $currentUserId . ($_parentAdminId > 0 ? ",$_parentAdminId" : "");
            $agentWhere = "WHERE (
                               c.created_by IN ($_createdByIds)
                               OR c.assigned_agent LIKE '%$currentName%'
                               OR c.assigned_agent LIKE '%$currentUsername%'
                           )
                           AND (c.status = 'active' OR c.status IS NULL)";

        } elseif ($currentUserRole === 'agent') {
            // agent শুধু সেই companies দেখবে যেগুলোতে তাকে assigned_agent এ assign করা হয়েছে
            $agentWhere = "WHERE (c.assigned_agent LIKE '%$currentName%'
                           OR c.assigned_agent LIKE '%$currentUsername%')
                           AND (c.status = 'active' OR c.status IS NULL)";

        } else {
            $agentWhere = "WHERE c.status = 'active'";
        }

        $cq = mysqli_query($conn, "
            SELECT c.id, c.company_name, c.assigned_agent,
                   c.company_email, c.company_number, c.company_website, c.created_at,
                   c.created_by, c.status,
                   (SELECT COUNT(*) FROM contacts WHERE company_id = c.id) AS total_dynamic_contacts
            FROM companies c $agentWhere ORDER BY c.id DESC
        ");
        if ($cq && mysqli_num_rows($cq) > 0) {
            $hasCompanies   = true;
            $totalCompanies = mysqli_num_rows($cq);
            while ($row = mysqli_fetch_assoc($cq)) {
                $c_name     = htmlspecialchars($row['company_name']);
                $c_agent    = htmlspecialchars($row['assigned_agent']);
                $c_contacts = (int)$row['total_dynamic_contacts'];
                $c_id       = (int)$row['id'];
                $c_email    = htmlspecialchars($row['company_email'] ?? '');
                $c_phone    = htmlspecialchars($row['company_number'] ?? '');
                $c_website  = htmlspecialchars($row['company_website'] ?? '');
                $c_date     = !empty($row['created_at']) ? date('d M Y', strtotime($row['created_at'])) : '—';
                $rowData    = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');

                // ── Agent badges HTML (comma-separated → badges) ──
                $c_agent_arr    = array_filter(array_map('trim', explode(',', $row['assigned_agent'] ?? '')));
                $c_agent_badges = '';
                foreach ($c_agent_arr as $_ag) {
                    if ($_ag && $_ag !== 'Unassigned') {
                        $c_agent_badges .= "<span class='comp-agent-badge'>" . htmlspecialchars($_ag) . "</span>";
                    }
                }
                if (empty($c_agent_badges)) $c_agent_badges = "<span style='color:#9ca3af;font-size:11px;'>—</span>";

                $email_html   = !empty($c_email)   ? "<a href='mailto:{$c_email}'   style='color:#3b82f6;text-decoration:none;'>{$c_email}</a>"       : "<span style='color:#9ca3af;'>—</span>";
                $phone_html   = !empty($c_phone)   ? "<a href='tel:{$c_phone}'      style='color:#374151;text-decoration:none;'>{$c_phone}</a>"        : "<span style='color:#9ca3af;'>—</span>";
                $website_html = !empty($c_website) ? "<a href='{$c_website}' target='_blank' style='color:#8b5cf6;text-decoration:none;'><i class='fa-solid fa-arrow-up-right-from-square' style='font-size:10px;margin-right:3px;'></i>Visit</a>" : "<span style='color:#9ca3af;'>—</span>";

                $c_status = $row['status'] ?? 'active';
                $companyTableRows .= "
                <tr class='company-row' data-status='{$c_status}'>
                    <td style='text-align:center;color:#6b7280;font-weight:600;'>#{$c_id}</td>
                    <td style='text-align:left;'>
                        <a href='company_profile.php?id={$c_id}' class='client-name-link'>
                            <span class='client-name-avatar'>" . strtoupper(substr($row['company_name'], 0, 1)) . "</span>
                            <span class='client-name-text'>{$c_name}</span>
                            <i class='fa-solid fa-arrow-up-right-from-square client-name-icon'></i>
                        </a>
                    </td>
                    <td>
                        <div style='display:flex;justify-content:center;align-items:center;gap:5px;flex-wrap:wrap;'>
                            {$c_agent_badges}
                        </div>
                    </td>
                    <td>{$email_html}</td>
                    <td>{$phone_html}</td>
                    <td>{$website_html}</td>
                    <td><span class='comp-contacts-pill'>{$c_contacts} Contacts</span></td>
                    <td style='color:#6b7280;font-size:11px;'><i class='fa-regular fa-calendar' style='margin-right:4px;'></i>{$c_date}</td>
                    <td>
                        <div class='action-btns'>
                            <a href='company_profile.php?id={$c_id}' class='btn-view' title='View Profile' style='display:inline-flex;align-items:center;justify-content:center;'><i class='fa-regular fa-eye'></i></a>";
                // Edit button: manager, admin, super_admin
                if (in_array($currentUserRole ?? '', ['super_admin', 'admin', 'manager'])) {
                    $companyTableRows .= "
                            <button class='btn-edit' title='Edit' onclick='openEditModal({$rowData})'><i class='fa-solid fa-pen'></i></button>";
                }
                // Toggle active/inactive: শুধু super_admin ও admin
                if (in_array($currentUserRole ?? '', ['super_admin', 'admin'])) {
                    $companyTableRows .= "
                            <form method='POST' id='toggle-comp-{$c_id}' style='display:inline;'>
                                <input type='hidden' name='toggle_company_id' value='{$c_id}'>
                                <input type='hidden' name='toggle_company_status' value='1'>
                                <input type='hidden' name='toggle_company_new_status' value='" . (($row['status'] ?? 'active') === 'active' ? 'inactive' : 'active') . "'>
                                <button type='button'
                                    class='" . (($row['status'] ?? 'active') === 'active' ? 'btn-status-active' : 'btn-status-inactive') . "'
                                    title='" . (($row['status'] ?? 'active') === 'active' ? 'Mark Inactive' : 'Mark Active') . "'
                                    onclick='confirmToggleCompanyStatus(\"toggle-comp-{$c_id}\", \"" . (($row['status'] ?? 'active') === 'active' ? 'inactive' : 'active') . "\")'
                                    ><i class='fa-solid " . (($row['status'] ?? 'active') === 'active' ? 'fa-toggle-on' : 'fa-toggle-off') . "'></i>
                                </button>
                            </form>";
                }
                $companyTableRows .= "
                        </div>
                    </td>
                </tr>";
            }
        }
    } catch (mysqli_sql_exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Company & Organization - Systellio CRM</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Inter',sans-serif; }
        body { background:#f3f4f6; display:flex; height:100vh; overflow:hidden; transition:background-color .3s,color .3s; color:#111827; }

        /* Toast */
        #toastBox { visibility:hidden; min-width:250px; background:#333; color:#fff; text-align:center; border-radius:8px; padding:16px; position:fixed; z-index:9999; right:30px; top:30px; font-size:14px; font-weight:600; box-shadow:0 4px 12px rgba(0,0,0,.15); display:flex; align-items:center; gap:10px; transform:translateX(100%); transition:transform .4s cubic-bezier(.68,-.55,.265,1.55),visibility .4s; }
        #toastBox.show  { visibility:visible; transform:translateX(0); }
        #toastBox.success { background:#10b981; }
        #toastBox.error   { background:#ef4444; }

        /* Layout */
        .main-content { flex-grow:1; display:flex; flex-direction:column; overflow-y:auto; background:#f3f4f6; transition:background-color .3s; }
        
        
        
        
        .nav-icon-btn:hover { color:#3b82f6; }
        .notification-badge { position:absolute; top:-4px; right:-4px; background:#ef4444; color:#fff; font-size:9px; font-weight:700; padding:2px 5px; border-radius:50%; border:2px solid #fff; }
        

        /* Page container */
        .company-container { padding:30px; }

        /* ── Header bar ── */
        .page-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; flex-wrap:wrap; gap:16px; }
        .comp-header-title h1 { font-size:26px; font-weight:800; letter-spacing:-.5px; color:#111827; }
        .comp-header-title p  { font-size:13px; color:#6b7280; margin-top:2px; }

        /* Header buttons — matches client_list.php style */
        .header-buttons { display:flex; gap:10px; }
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
        .btn-add-company {
            background-color:#0f172a; color:#ffffff; padding:10px 18px;
            border-radius:6px; font-size:13px; font-weight:700; border:none;
            cursor:pointer; display:flex; align-items:center; gap:8px;
            box-shadow:0 2px 8px rgba(0,0,0,0.12); transition:background-color .2s, transform .1s;
        }
        .btn-add-company:hover { background-color:#1e293b; transform:translateY(-1px); }

        /* Toolbar */
        .comp-toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; }
        .comp-search  { position:relative; width:300px; }
        .comp-search i { position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#9ca3af; font-size:13px; }
        .comp-search input { width:100%; padding:10px 14px 10px 38px; border:1px solid #d1d5db; border-radius:20px; font-size:13px; outline:none; transition:.3s; color:#374151; background:#fff; }
        .comp-search input:focus { border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.1); }
        .comp-total { font-size:13px; font-weight:600; color:#4b5563; background:#fff; border:1px solid #d1d5db; padding:8px 15px; border-radius:20px; }

        /* Tabs */
        .tab-container { display:flex; gap:25px; border-bottom:1px solid #d1d5db; margin-bottom:18px; transition:0.3s; }
        .tab-btn { padding:10px 5px; font-size:13px; font-weight:600; color:#6b7280; cursor:pointer; position:relative; transition:0.3s; }
        .tab-btn:hover { color:#111827; }
        .tab-btn.active { color:#3b82f6; }
        .tab-btn.active::after { content:''; position:absolute; bottom:-1px; left:0; width:100%; height:2px; background-color:#3b82f6; }
        body.dark-mode .tab-container { border-color:#334155; }
        body.dark-mode .tab-btn { color:#94a3b8; }
        body.dark-mode .tab-btn:hover { color:#f8fafc; }
        body.dark-mode .tab-btn.active { color:#60a5fa; }
        body.dark-mode .tab-btn.active::after { background:#60a5fa; }

        /* Table */
        .table-wrapper { border-radius:8px; overflow:hidden; border:1px solid #d1d5db; background:#fff; }
        .custom-table  { width:100%; border-collapse:collapse; text-align:center; font-size:12px; }
        .custom-table th { background:#c4f042; padding:14px 10px; font-weight:700; color:#000; border-bottom:1px solid #d1d5db; }
        .custom-table td { padding:13px 10px; color:#374151; font-weight:500; vertical-align:middle; border-right:1px solid rgba(0,0,0,.05); }
        .custom-table td:last-child { border-right:none; }
        .custom-table tbody tr:nth-child(4n+1) { background:#e6fced; }
        .custom-table tbody tr:nth-child(4n+2) { background:#fcedf6; }
        .custom-table tbody tr:nth-child(4n+3) { background:#fceddb; }
        .custom-table tbody tr:nth-child(4n+4) { background:#e6edff; }

        .comp-contacts-pill { background:#eff6ff; color:#3b82f6; border:1px solid #bfdbfe; font-size:11px; font-weight:600; padding:4px 12px; border-radius:20px; display:inline-block; }

        /* ── Agent badge (table) ── */
        .comp-agent-badge { display:inline-flex; align-items:center; background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; font-size:10px; font-weight:700; padding:3px 9px; border-radius:20px; white-space:nowrap; }
        body.dark-mode .comp-agent-badge { background:#052e16; color:#34d399; border-color:#166534; }

        /* ── Multi-select agent dropdown (no scrollbar) ── */
        .ms-wrap { position:relative; width:100%; }

        /* Trigger button */
        .ms-trigger {
            width:100%; padding:9px 13px; border:1.5px solid #e5e7eb; border-radius:8px;
            font-size:12px; font-family:'Inter',sans-serif; color:#1f2937; background:#f9fafb;
            cursor:pointer; display:flex; align-items:center; justify-content:space-between;
            gap:8px; min-height:42px; transition:.2s; user-select:none;
        }
        .ms-trigger:hover { border-color:#93c5fd; background:#fff; }
        .ms-trigger.ms-open { border-color:#3b82f6; background:#fff; box-shadow:0 0 0 3px rgba(59,130,246,.1); border-radius:8px 8px 0 0; }
        .ms-trigger-left { display:flex; align-items:center; gap:6px; flex:1; flex-wrap:wrap; min-width:0; }
        .ms-placeholder { color:#9ca3af; font-size:12px; }
        .ms-tag { background:#dbeafe; color:#1d4ed8; border:1px solid #bfdbfe; padding:2px 8px 2px 8px; border-radius:20px; font-size:11px; font-weight:600; display:inline-flex; align-items:center; gap:4px; white-space:nowrap; }
        .ms-tag-x { cursor:pointer; font-size:11px; color:#60a5fa; font-weight:900; line-height:1; margin-left:1px; }
        .ms-tag-x:hover { color:#ef4444; }
        .ms-arrow { color:#9ca3af; font-size:10px; flex-shrink:0; transition:transform .2s; }
        .ms-open .ms-arrow { transform:rotate(180deg); color:#3b82f6; }

        /* Dropdown panel — no max-height, no overflow */
        .ms-dropdown {
            display:none; position:absolute; top:100%; left:0; width:100%; z-index:9999;
            background:#fff; border:1.5px solid #3b82f6; border-top:none;
            border-radius:0 0 10px 10px;
            box-shadow:0 12px 28px rgba(59,130,246,.13);
            animation:msFade .15s ease;
        }
        @keyframes msFade { from{opacity:0;transform:translateY(-4px)} to{opacity:1;transform:translateY(0)} }
        .ms-dropdown.ms-show { display:block; }

        /* Search box */
        .ms-search { padding:8px 10px; border-bottom:1px solid #e5e7eb; }
        .ms-search input {
            width:100%; padding:7px 10px 7px 30px; border:1px solid #e5e7eb;
            border-radius:6px; font-size:12px; outline:none; background:#f9fafb;
            background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='13' height='13' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='2.5'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cpath d='m21 21-4.35-4.35'/%3E%3C/svg%3E");
            background-repeat:no-repeat; background-position:9px center;
            transition:.15s;
        }
        .ms-search input:focus { border-color:#3b82f6; background:#fff; box-shadow:0 0 0 2px rgba(59,130,246,.08); }

        /* Options list — single column, one by one */
        .ms-options-grid {
            display:flex; flex-direction:column;
            gap:0; padding:6px;
        }
        .ms-option {
            display:flex; align-items:center; gap:8px; padding:8px 10px;
            font-size:12px; font-weight:500; color:#374151; cursor:pointer;
            border-radius:6px; transition:background .12s; user-select:none;
        }
        .ms-option:hover { background:#f0f9ff; }
        .ms-option.ms-selected { background:#eff6ff; color:#1d4ed8; font-weight:600; }
        .ms-option-check {
            width:15px; height:15px; border-radius:4px; border:1.5px solid #d1d5db;
            flex-shrink:0; display:flex; align-items:center; justify-content:center;
            transition:.12s; background:#fff;
        }
        .ms-option.ms-selected .ms-option-check {
            background:#3b82f6; border-color:#3b82f6;
        }
        .ms-option.ms-selected .ms-option-check::after {
            content:''; display:block; width:8px; height:5px;
            border-left:2px solid #fff; border-bottom:2px solid #fff;
            transform:rotate(-45deg) translateY(-1px);
        }
        .ms-option-name { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .ms-role-badge { font-size:9px; font-weight:700; padding:1px 5px; border-radius:8px; flex-shrink:0; }
        .ms-role-manager { background:#fef3c7; color:#92400e; }
        .ms-role-agent   { background:#f0fdf4; color:#166534; }
        .ms-role-admin   { background:#eff6ff; color:#1e40af; }
        .ms-empty { padding:14px; text-align:center; color:#9ca3af; font-size:12px; }

        /* Dark mode */
        body.dark-mode .ms-trigger { background:#0f172a; border-color:#334155; color:#f8fafc; }
        body.dark-mode .ms-trigger:hover { border-color:#3b82f6; background:#0f172a; }
        body.dark-mode .ms-trigger.ms-open { border-color:#3b82f6; background:#0f172a; }
        body.dark-mode .ms-dropdown { background:#1e293b; border-color:#3b82f6; box-shadow:0 12px 28px rgba(0,0,0,.3); }
        body.dark-mode .ms-search { border-color:#334155; }
        body.dark-mode .ms-search input { background:#0f172a; color:#f8fafc; border-color:#334155; }
        body.dark-mode .ms-search input:focus { border-color:#3b82f6; background:#0f172a; }
        body.dark-mode .ms-option { color:#cbd5e1; }
        body.dark-mode .ms-option:hover { background:#1e3a5f; }
        body.dark-mode .ms-option.ms-selected { background:#1e3a5f; color:#93c5fd; }
        body.dark-mode .ms-option-check { background:#0f172a; border-color:#475569; }
        body.dark-mode .ms-option.ms-selected .ms-option-check { background:#3b82f6; border-color:#3b82f6; }
        .tbl-checkbox { width:16px; height:16px; accent-color:#3b82f6; cursor:pointer; }

        /* Company Name link — simple */
        .client-name-link { text-decoration:none; color:#1d4ed8; font-weight:600; font-size:12px; }
        .client-name-link:hover { text-decoration:underline; color:#1e40af; }
        .client-name-avatar { display:none; }
        .client-name-icon  { display:none; }
        body.dark-mode .client-name-link { color:#93c5fd; }
        body.dark-mode .client-name-link:hover { color:#bfdbfe; }

        /* Action buttons */
        .action-btns { display:flex; justify-content:center; gap:5px; }
        .btn-view   { background:#60a5fa; color:#fff; padding:6px 10px; border-radius:4px; font-size:11px; border:none; cursor:pointer; transition:.2s; }
        .btn-edit   { background:#34d399; color:#fff; padding:6px 10px; border-radius:4px; font-size:11px; border:none; cursor:pointer; transition:.2s; }
        .btn-view:hover   { background:#3b82f6; }
        .btn-edit:hover   { background:#10b981; }
        .btn-status-active { background:#10b981; color:#fff; padding:6px 10px; border-radius:4px; font-size:13px; border:none; cursor:pointer; transition:.2s; }
        .btn-status-active:hover { background:#059669; }
        .btn-status-inactive { background:#9ca3af; color:#fff; padding:6px 10px; border-radius:4px; font-size:13px; border:none; cursor:pointer; transition:.2s; }
        .btn-status-inactive:hover { background:#6b7280; }
        .status-badge-active   { display:inline-block; background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; }
        .status-badge-inactive { display:inline-block; background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; }

        /* ── Modals (shared) ── */
        .modal { display:none; position:fixed; z-index:2000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,.5); align-items:center; justify-content:center; }

        /* ── View / Edit small modals ── */
        .modal-content { 
            background:#fff; padding:28px; border-radius:10px; width:100%; max-width:700px; 
            box-shadow:0 10px 25px rgba(0,0,0,.15); max-height:90vh; overflow-y:auto;
            scroll-behavior:smooth;
        }
        /* Custom scrollbar for modal content */
        .modal-content::-webkit-scrollbar { width:10px; }
        .modal-content::-webkit-scrollbar-track { background:#f1f5f9; border-radius:0 10px 10px 0; }
        .modal-content::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:5px; }
        .modal-content::-webkit-scrollbar-thumb:hover { background:#94a3b8; }
        .small-modal   { max-width:450px; }
        .modal-header  { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; }
        .modal-header h2 { font-size:18px; font-weight:700; }
        .close-btn { font-size:20px; cursor:pointer; color:#6b7280; border:none; background:none; transition:.2s; }
        .close-btn:hover { color:#ef4444; }

        /* View data boxes */
        .view-grid  { display:flex; flex-direction:column; gap:14px; margin-bottom:8px; }
        .view-item  { background:#f9fafb; border:1px solid #e5e7eb; border-radius:7px; padding:12px 14px; }
        .view-label { font-size:10px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; margin-bottom:5px; }
        .view-value { font-size:13px; font-weight:600; color:#111827; }
        .view-full  { grid-column:span 2; }
        .view-badge { display:inline-flex; align-items:center; gap:6px; background:#dbeafe; color:#1d4ed8; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600; }

        /* Sub-contacts table */
        .sub-contacts-table { width:100%; border-collapse:collapse; font-size:12px; }
        .sub-contacts-table th { background:#f1f5f9; padding:9px 12px; font-weight:700; color:#374151; text-align:left; border-bottom:1px solid #e2e8f0; }
        .sub-contacts-table td { padding:10px 12px; color:#374151; font-weight:500; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
        .sub-contacts-table tr:last-child td { border-bottom:none; }
        .sub-contacts-table tr:hover td { background:#f8fafc; }
        .sub-contacts-table .pill { display:inline-block; padding:3px 10px; border-radius:20px; font-size:10px; font-weight:700; }
        .sub-contacts-table .pill-blue { background:#eff6ff; color:#3b82f6; border:1px solid #bfdbfe; }
        .sub-contacts-table .pill-yellow { background:#fef3c7; color:#b45309; border:1px solid #fde68a; }
        .sub-contacts-no { text-align:center; padding:20px; color:#9ca3af; font-size:12px; font-style:italic; }
        body.dark-mode .sub-contacts-table th { background:#1e293b; color:#94a3b8; border-color:#334155; }
        body.dark-mode .sub-contacts-table td { color:#cbd5e1; border-color:#1e293b; }
        body.dark-mode .sub-contacts-table tr:hover td { background:#0f172a; }

        /* Edit form */
        .edit-grid  { display:flex; flex-direction:column; gap:14px; margin-bottom:4px; }
        .edit-group { margin-bottom:0; }
        .edit-group.full { grid-column:span 2; }
        .edit-group label { display:block; font-size:11px; font-weight:700; color:#4b5563; text-transform:uppercase; letter-spacing:.5px; margin-bottom:6px; }
        .edit-group input,
        .edit-group select { width:100%; padding:10px 13px; border:1.5px solid #e5e7eb; border-radius:7px; font-size:13px; font-family:'Inter',sans-serif; color:#1f2937; outline:none; transition:.2s; background:#f9fafb; }
        .edit-group input:focus,
        .edit-group select:focus { border-color:#3b82f6; background:#fff; box-shadow:0 0 0 3px rgba(59,130,246,.1); }
        .edit-footer { display:flex; gap:10px; margin-top:20px; }
        .btn-save-edit { flex:1; background:#3b82f6; color:#fff; padding:12px; border:none; border-radius:7px; font-size:13px; font-weight:700; cursor:pointer; transition:.2s; }
        .btn-save-edit:hover { background:#2563eb; }
        .btn-cancel-edit { flex:1; background:#f3f4f6; color:#374151; padding:12px; border:none; border-radius:7px; font-size:13px; font-weight:600; cursor:pointer; transition:.2s; }
        .btn-cancel-edit:hover { background:#e5e7eb; }

        /* ── Add Company Wizard ── */
        .modal-content.comp-modal-content { 
            max-width:650px; padding:0; overflow:hidden;
        }
        .comp-modal-body { 
            padding:24px 28px; background:#fff; 
            max-height:calc(90vh - 180px); overflow-y:auto;
            scroll-behavior:smooth;
        }
        /* Custom scrollbar for company modal body */
        .comp-modal-body::-webkit-scrollbar { width:8px; }
        .comp-modal-body::-webkit-scrollbar-track { background:#f8fafc; }
        .comp-modal-body::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:4px; }
        .comp-modal-body::-webkit-scrollbar-thumb:hover { background:#94a3b8; }
        .comp-modal-header-wrap { background:#f4f6fb; padding:24px 28px 20px; border-bottom:1px solid #e5e7eb; }
        .comp-modal-top { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:18px; }
        .comp-modal-top h2 { font-size:20px; font-weight:800; color:#111827; margin-bottom:3px; }
        .comp-modal-top p  { font-size:12px; color:#6b7280; }
        .camp-progress-bar { display:flex; justify-content:space-between; position:relative; padding:0 10px; }
        .camp-progress-bar::before { content:''; position:absolute; top:15px; left:0; width:100%; height:2px; background:#e5e7eb; z-index:1; }
        .camp-progress-step { width:32px; height:32px; background:#fff; border:2px solid #e5e7eb; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; color:#9ca3af; z-index:2; position:relative; transition:all .3s; }
        .camp-progress-step.active    { border-color:#2563eb; color:#2563eb; background:#eff6ff; }
        .camp-progress-step.completed { background:#2563eb; border-color:#2563eb; color:#fff; }
        .step-label-row { display:flex; justify-content:space-between; padding:6px 4px 0; }
        .step-label-row span { font-size:10px; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:.4px; text-align:center; flex:1; }
        .step-label-row span.active-lbl { color:#2563eb; }
        .comp-modal-body { padding:24px 28px; background:#fff; }
        .comp-step-container { display:none; }
        .comp-step-container.comp-step-active { display:block; animation:compFade .35s ease; }
        @keyframes compFade { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }
        .step-section-title { display:flex; align-items:center; gap:8px; font-size:12px; font-weight:700; color:#2563eb; text-transform:uppercase; letter-spacing:.6px; margin-bottom:14px; padding-bottom:8px; border-bottom:1px solid #dbeafe; }
        .comp-form-grid     { display:flex; flex-direction:column; gap:14px; }
        .comp-form-grid.full { grid-template-columns:1fr; }
        .comp-form-group    { margin-bottom:4px; }
        .comp-form-group label { display:block; font-size:11px; font-weight:700; color:#4b5563; text-transform:uppercase; letter-spacing:.5px; margin-bottom:6px; }
        .comp-form-group input,
        .comp-form-group select { width:100%; padding:11px 13px; border:none; background:#f4f6fb; border-radius:6px; font-size:13px; font-family:'Inter',sans-serif; color:#1f2937; outline:none; transition:.25s; box-shadow:inset 0 0 0 1px transparent; }
        .comp-form-group input:focus,
        .comp-form-group select:focus { box-shadow:inset 0 0 0 1.5px #2563eb; background:#fff; }
        .comp-phone-wrap { display:flex; gap:8px; }
        .comp-phone-wrap select { max-width:110px; }
        .comp-modal-footer { display:flex; justify-content:space-between; align-items:center; padding:18px 28px; border-top:1px solid #e5e7eb; background:#fff; }
        .comp-btn-cancel,.comp-btn-back { background:transparent; border:none; color:#6b7280; font-size:13px; font-weight:600; cursor:pointer; transition:.2s; padding:0; }
        .comp-btn-cancel:hover,.comp-btn-back:hover { color:#111827; }
        .comp-btn-next { background:#2563eb; color:#fff; padding:10px 22px; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:8px; transition:.2s; }
        .comp-btn-next:hover { background:#1d4ed8; }
        .comp-btn-save { background:#10b981; color:#fff; padding:10px 22px; border:none; border-radius:6px; font-size:13px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:8px; transition:.2s; }
        .comp-btn-save:hover { background:#059669; }

        /* ── DARK MODE ── */
        body.dark-mode { background:#0f172a; color:#f8fafc; }
        body.dark-mode .main-content  { background:#0f172a; }
        body.dark-mode 
        body.dark-mode 
        body.dark-mode .comp-header-title h1 { color:#f8fafc; }
        body.dark-mode .btn-export { background-color:#15803d; }
        body.dark-mode .btn-bulk   { background-color:#334155; }
        body.dark-mode .btn-add-company { background-color:#1e293b; border:1px solid #334155; }
        body.dark-mode .comp-search input { background:#0f172a; color:#f8fafc; border-color:#334155; }
        body.dark-mode .comp-total    { background:#0f172a; color:#cbd5e1; border-color:#334155; }
        body.dark-mode .table-wrapper { border-color:#334155; background:#1e293b; }
        body.dark-mode .custom-table th { background:#334155; color:#f8fafc; border-color:#475569; }
        body.dark-mode .custom-table td { color:#cbd5e1; border-color:#334155; }
        body.dark-mode .custom-table tbody tr:nth-child(n) { background:#1e293b; }
        body.dark-mode .custom-table tbody tr:nth-child(odd) { background:#0f172a; }
        body.dark-mode .modal-content { background:#1e293b; }
        /* Dark mode scrollbar for modal */
        body.dark-mode .modal-content::-webkit-scrollbar-track { background:#0f172a; }
        body.dark-mode .modal-content::-webkit-scrollbar-thumb { background:#475569; }
        body.dark-mode .modal-content::-webkit-scrollbar-thumb:hover { background:#64748b; }
        body.dark-mode .view-item     { background:#0f172a; border-color:#334155; }
        body.dark-mode .view-label    { color:#94a3b8; }
        body.dark-mode .view-value    { color:#f8fafc; }
        body.dark-mode .edit-group label { color:#cbd5e1; }
        body.dark-mode .edit-group input,
        body.dark-mode .edit-group select { background:#0f172a; color:#f8fafc; border-color:#334155; }
        body.dark-mode .btn-cancel-edit { background:#334155; color:#cbd5e1; }
        body.dark-mode .comp-modal-header-wrap { background:#0f172a; border-color:#334155; }
        body.dark-mode .comp-modal-top h2 { color:#f8fafc; }
        body.dark-mode .camp-progress-step { background:#1e293b; border-color:#334155; color:#94a3b8; }
        body.dark-mode .camp-progress-bar::before { background:#334155; }
        body.dark-mode .comp-modal-body   { background:#1e293b; }
        /* Dark mode scrollbar for company modal body */
        body.dark-mode .comp-modal-body::-webkit-scrollbar-track { background:#0f172a; }
        body.dark-mode .comp-modal-body::-webkit-scrollbar-thumb { background:#475569; }
        body.dark-mode .comp-modal-body::-webkit-scrollbar-thumb:hover { background:#64748b; }
        body.dark-mode .comp-modal-footer { background:#1e293b; border-color:#334155; }
        body.dark-mode .comp-form-group label { color:#cbd5e1; }
        body.dark-mode .comp-form-group input,
        body.dark-mode .comp-form-group select { background:#0f172a; color:#f8fafc; }
        body.dark-mode .step-section-title { color:#60a5fa; border-color:#1e3a8a; }
        body.dark-mode .step-label-row span { color:#475569; }
        body.dark-mode .step-label-row span.active-lbl { color:#60a5fa; }
        body.dark-mode .comp-btn-cancel,
        body.dark-mode .comp-btn-back { color:#94a3b8; }
        body.dark-mode #bulkDropZone { border-color:#334155 !important; background:#0f172a !important; color:#94a3b8; }
        body.dark-mode #bulkFileInfo { background:#052e16; border-color:#166534; color:#34d399; }
        body.dark-mode #bulkPreviewTable thead { background:#1e293b; }
        body.dark-mode #bulkPreviewTable th { color:#94a3b8 !important; background:#1e293b !important; }
        body.dark-mode #bulkPreviewTable td { color:#cbd5e1; border-color:#334155; }
        body.dark-mode #bulkEditDropZone { border-color:#334155 !important; background:#0f172a !important; color:#94a3b8; }
        body.dark-mode #bulkEditFileInfo { background:#78350f; border-color:#92400e; color:#fbbf24; }
        body.dark-mode #bulkEditPreviewTable thead { background:#1e293b; }
        body.dark-mode #bulkEditPreviewTable th { color:#94a3b8 !important; background:#1e293b !important; }
        body.dark-mode #bulkEditPreviewTable td { color:#cbd5e1; border-color:#334155; }
        .swal2-container { z-index:9999 !important; }
        body.dark-mode .swal2-popup { background:#1e293b; color:#f8fafc; border:1px solid #334155; }
        body.dark-mode .swal2-title,
        body.dark-mode .swal2-html-container { color:#f8fafc; }
    </style>
</head>
<body>

<div id="toastBox"><i id="toastIcon" class="fa-solid fa-circle-check"></i><span id="toastMsg">Done!</span></div>

<?php
    $activePage    = 'company_list';
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
    <!-- Navbar -->
    <?php include 'topbar.php'; ?>

    <div class="company-container">

        <!-- ── Page Header ── -->
        <div class="page-header">
            <div class="comp-header-title">
                <h1>Company Database</h1>
                <p>Manage companies, contacts and assignments</p>
            </div>

            <!-- Header Buttons — matches client_list.php style -->
            <div class="header-buttons">
                <?php if (($_SESSION['role'] ?? '') !== 'agent'): ?>
                <form method="POST" style="margin:0;">
                    <button type="submit" name="export_companies_csv" class="btn-export">
                        <i class="fa-solid fa-file-csv"></i> Export CSV
                    </button>
                </form>

                <button class="btn-bulk" onclick="openModal('bulkUploadCompanyModal')">
                    <i class="fa-solid fa-cloud-arrow-up"></i> Bulk Upload
                </button>

                <?php if (!in_array($_SESSION['role'] ?? '', ['manager'])): ?>
                <button class="btn-bulk" onclick="openModal('bulkEditCompanyModal')" style="background-color:#f59e0b;">
                    <i class="fa-solid fa-pen-to-square"></i> Bulk Edit
                </button>
                <?php endif; ?>

                <button class="btn-add-company" onclick="openModal('addCompanyModal')">
                    <i class="fa-solid fa-plus"></i> Add Company
                </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!in_array($_SESSION['role'] ?? '', ['manager', 'agent'])): ?>
        <!-- Tabs -->
        <div class="tab-container">
            <div class="tab-btn active" onclick="filterCompanies('all', this)">All Companies</div>
            <div class="tab-btn" onclick="filterCompanies('active', this)">Active</div>
            <div class="tab-btn" onclick="filterCompanies('inactive', this)">In-Active</div>
        </div>
        <?php endif; ?>

        <!-- Toolbar -->
        <div class="comp-toolbar">
            <div class="comp-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="compSearchInput" placeholder="Search company or agent..." oninput="searchTable()">
            </div>
            <div class="comp-total">Total: <b><?php echo $hasCompanies ? $totalCompanies : '0'; ?></b> Companies</div>
        </div>

        <!-- Table -->
        <div class="table-wrapper">
            <table class="custom-table" id="companyTable">
                <thead>
                    <tr>
                        <th style="width:80px;">ID</th>
                        <th style="text-align:left;">Company Name</th>
                        <th>Assigned Agent</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Website</th>
                        <th>Total Contacts</th>
                        <th>Date Added</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($hasCompanies): ?>
                        <?php echo $companyTableRows; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" style="padding:30px;color:#9ca3af;font-style:italic;">
                                <i class="fa-solid fa-building" style="font-size:24px;margin-bottom:8px;display:block;"></i>
                                No companies found. Click <b>Add Company</b> to get started.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div><!-- /company-container -->
</div><!-- /main-content -->


<!-- ══════════════════════════════════════════
     VIEW COMPANY MODAL
══════════════════════════════════════════ -->
<div id="viewCompanyModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fa-solid fa-building" style="color:#3b82f6;margin-right:8px;"></i>Company Details</h2>
            <button type="button" class="close-btn" onclick="closeModal('viewCompanyModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="view-grid">
            <div class="view-item view-full">
                <div class="view-label">Company Name</div>
                <div class="view-value" id="view_comp_name" style="font-size:16px;">—</div>
            </div>
            <div class="view-item">
                <div class="view-label">Assigned Agent</div>
                <div class="view-value" id="view_comp_agent">—</div>
            </div>
            <div class="view-item">
                <div class="view-label">Total Contacts</div>
                <div class="view-value" id="view_comp_contacts">—</div>
            </div>
            <div class="view-item">
                <div class="view-label">Email</div>
                <div class="view-value" id="view_comp_email">—</div>
            </div>
            <div class="view-item">
                <div class="view-label">Phone</div>
                <div class="view-value" id="view_comp_phone">—</div>
            </div>
            <div class="view-item view-full">
                <div class="view-label">Website</div>
                <div class="view-value" id="view_comp_website">—</div>
            </div>
            <div class="view-item">
                <div class="view-label">Company ID</div>
                <div class="view-value" id="view_comp_id">—</div>
            </div>
            <div class="view-item">
                <div class="view-label">Date Added</div>
                <div class="view-value" id="view_comp_date">—</div>
            </div>
        </div>

        <!-- Contacts Sub-Table -->
        <div style="margin-top:24px;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;padding-bottom:10px;border-bottom:2px solid #e5e7eb;">
                <i class="fa-solid fa-users" style="color:#3b82f6;font-size:14px;"></i>
                <span style="font-size:13px;font-weight:800;color:#111827;text-transform:uppercase;letter-spacing:.5px;">Accounts & Clients</span>
                <span id="view_contacts_count_badge" style="background:#eff6ff;color:#3b82f6;border:1px solid #bfdbfe;font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;margin-left:4px;">0</span>
            </div>
            <div id="view_contacts_table_wrap">
                <div style="text-align:center;padding:20px;color:#9ca3af;font-size:13px;">
                    <i class="fa-solid fa-spinner fa-spin"></i> Loading contacts...
                </div>
            </div>
        </div>

        <div style="display:flex;gap:10px;margin-top:20px;">
            <button class="btn-save-edit" style="background:#34d399;" onclick="switchViewToEdit()">
                <i class="fa-solid fa-pen"></i> Edit Company
            </button>
            <button class="btn-cancel-edit" onclick="closeModal('viewCompanyModal')">Close</button>
        </div>
    </div>
</div>


<!-- ══════════════════════════════════════════
     EDIT COMPANY MODAL
══════════════════════════════════════════ -->
<div id="editCompanyModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fa-solid fa-pen" style="color:#10b981;margin-right:8px;"></i>Edit Company</h2>
            <button type="button" class="close-btn" onclick="closeModal('editCompanyModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form action="company_list.php" method="POST" id="editCompanyForm">
            <input type="hidden" name="edit_company_id" id="edit_company_id">
            <input type="hidden" name="update_company"  value="1">

            <div class="edit-grid">
                <div class="edit-group full" id="editCompNameGroup">
                    <label>Company Name <?php if(($_SESSION['role']??'')!=='manager'): ?><span style="color:#ef4444;">*</span><?php endif; ?></label>
                    <input type="text" name="edit_company_name" id="edit_company_name"
                        <?php if(($_SESSION['role']??'')!=='manager'): ?>required<?php endif; ?>
                        placeholder="e.g. Acme Corporation"
                        <?php if(($_SESSION['role']??'')==='manager'): ?>readonly style="background:#f3f4f6;color:#9ca3af;cursor:not-allowed;"<?php endif; ?>>
                </div>
                <div class="edit-group full">
                    <label>Assigned Agents <span style="font-size:10px;font-weight:500;color:#9ca3af;">(multiple select)</span></label>
                    <!-- Hidden inputs যোগ হবে JS দিয়ে -->
                    <div id="editAgentMsWrap" class="ms-wrap">
                        <div class="ms-trigger" id="editAgentTrigger" onclick="msToggle('editAgent')">
                            <div class="ms-trigger-left" id="editAgentTags"><span class="ms-placeholder">Select agents...</span></div>
                            <i class="fa-solid fa-chevron-down ms-arrow"></i>
                        </div>
                        <div class="ms-dropdown" id="editAgentDropdown">
                            <div class="ms-search"><input type="text" placeholder="Search by name..." oninput="msSearch(this,'editAgent')" id="editAgentSearch"></div>
                            <div class="ms-options-grid" id="editAgentOptions"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="edit-footer">
                <button type="submit" class="btn-save-edit">
                    <i class="fa-solid fa-floppy-disk"></i> Save Changes
                </button>
                <button type="button" class="btn-cancel-edit" onclick="closeModal('editCompanyModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>


<!-- ══════════════════════════════════════════
     ADD COMPANY — 3-Step Wizard
══════════════════════════════════════════ -->
<div id="addCompanyModal" class="modal">
    <div class="modal-content comp-modal-content">

        <div class="comp-modal-header-wrap">
            <div class="comp-modal-top">
                <div>
                    <h2>Add New Company</h2>
                    <p>Fill in the details to create a new company record</p>
                </div>
                <button type="button" class="close-btn" onclick="closeCompanyModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="camp-progress-bar">
                <div class="camp-progress-step active" id="compStep1">1</div>
                <div class="camp-progress-step"        id="compStep2">2</div>
                <div class="camp-progress-step"        id="compStep3">3</div>
            </div>
            <div class="step-label-row">
                <span class="active-lbl" id="compLbl1">Basic Info</span>
                <span id="compLbl2">Contact</span>
                <span id="compLbl3">Social Media</span>
            </div>
        </div>

        <form action="company_list.php" method="POST" id="addCompanyForm">

            <div class="comp-modal-body">

                <!-- Step 1 -->
                <div class="comp-step-container comp-step-active" id="compStepBody1">
                    <div class="step-section-title"><i class="fa-solid fa-building"></i> Company Identity</div>
                    <div class="comp-form-grid full">
                        <div class="comp-form-group">
                            <label>Company Name <span style="color:#ef4444;">*</span></label>
                            <input type="text" name="company_name" id="comp_name_input" placeholder="e.g. Acme Corporation" required>
                        </div>
                    </div>
                    <div class="comp-form-grid" style="margin-top:14px;">
                        <div class="comp-form-group">
                            <label>Company Website</label>
                            <input type="url" name="company_website" placeholder="https://company.com">
                        </div>
                    </div>
                    <div class="comp-form-grid full" style="margin-top:14px;">
                        <div class="comp-form-group">
                            <label>Assigned Agents <span style="font-size:10px;font-weight:500;color:#9ca3af;">(multiple)</span></label>
                            <div id="addAgentMsWrap" class="ms-wrap">
                                <div class="ms-trigger" id="addAgentTrigger" onclick="msToggle('addAgent')">
                                    <div class="ms-trigger-left" id="addAgentTags"><span class="ms-placeholder">Select agents...</span></div>
                                    <i class="fa-solid fa-chevron-down ms-arrow"></i>
                                </div>
                                <div class="ms-dropdown" id="addAgentDropdown">
                                    <div class="ms-search"><input type="text" placeholder="Search by name..." oninput="msSearch(this,'addAgent')" id="addAgentSearch"></div>
                                    <div class="ms-options-grid" id="addAgentOptions"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 2 -->
                <div class="comp-step-container" id="compStepBody2">
                    <div class="step-section-title"><i class="fa-solid fa-address-card"></i> Contact Details</div>
                    <div class="comp-form-grid full">
                        <div class="comp-form-group">
                            <label>Company Email</label>
                            <input type="email" name="company_email" placeholder="info@company.com">
                        </div>
                    </div>
                    <div class="comp-form-grid full" style="margin-top:14px;">
                        <div class="comp-form-group">
                            <label>Phone Number</label>
                            <div class="comp-phone-wrap">
                                <select name="company_country_code">
                                    <option value="+880">🇧🇩 +880</option>
                                    <option value="+1">🇺🇸 +1</option>
                                    <option value="+44">🇬🇧 +44</option>
                                    <option value="+91">🇮🇳 +91</option>
                                    <option value="+971">🇦🇪 +971</option>
                                    <option value="+65">🇸🇬 +65</option>
                                    <option value="+61">🇦🇺 +61</option>
                                    <option value="+49">🇩🇪 +49</option>
                                </select>
                                <input type="text" name="company_number" placeholder="017XX XXX XXX" style="flex:1;">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 3 -->
                <div class="comp-step-container" id="compStepBody3">
                    <div class="step-section-title"><i class="fa-solid fa-share-nodes"></i> Social Media Profiles</div>
                    <div class="comp-form-grid" style="margin-bottom:14px;">
                        <div class="comp-form-group">
                            <label><i class="fa-brands fa-facebook" style="color:#1877F2;"></i> Facebook URL</label>
                            <input type="url" name="fb_url" placeholder="https://facebook.com/...">
                        </div>
                        <div class="comp-form-group">
                            <label><i class="fa-brands fa-linkedin" style="color:#0A66C2;"></i> LinkedIn URL</label>
                            <input type="url" name="linkedin_url" placeholder="https://linkedin.com/company/...">
                        </div>
                        <div class="comp-form-group">
                            <label><i class="fa-brands fa-instagram" style="color:#E4405F;"></i> Instagram URL</label>
                            <input type="url" name="insta_url" placeholder="https://instagram.com/...">
                        </div>
                        <div class="comp-form-group">
                            <label><i class="fa-brands fa-x-twitter"></i> Twitter / X URL</label>
                            <input type="url" name="twitter_url" placeholder="https://x.com/...">
                        </div>
                    </div>
                    <p style="font-size:11px;color:#9ca3af;"><i class="fa-solid fa-circle-info"></i> Social fields are optional.</p>
                </div>
            </div>

            <!-- Footer nav (inside form) -->
            <div class="comp-modal-footer">
                <div>
                    <button type="button" class="comp-btn-cancel" id="compBtnCancel" onclick="closeCompanyModal()">Cancel</button>
                    <button type="button" class="comp-btn-back"   id="compBtnBack"   style="display:none;margin-left:14px;" onclick="compPrevStep()">
                        <i class="fa-solid fa-arrow-left"></i> Back
                    </button>
                </div>
                <div style="display:flex;gap:10px;">
                    <button type="button" class="comp-btn-next" id="compBtnNext" onclick="compNextStep()">
                        Next Step <i class="fa-solid fa-arrow-right"></i>
                    </button>
                    <button type="submit" name="create_company" value="1" class="comp-btn-save" id="compBtnSave" style="display:none;">
                        <i class="fa-solid fa-floppy-disk"></i> Save Company
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>


<!-- ══════════════════════════════════════════
     BULK UPLOAD MODAL
══════════════════════════════════════════ -->
<div id="bulkUploadCompanyModal" class="modal">
    <div class="modal-content" style="max-width:820px;">
        <div class="modal-header">
            <div>
                <h2><i class="fa-solid fa-cloud-arrow-up" style="color:#3b82f6;margin-right:8px;"></i>Bulk Upload Companies</h2>
                <p style="font-size:12px;color:#6b7280;margin-top:3px;">Upload a CSV file to add multiple companies at once.</p>
            </div>
            <button type="button" class="close-btn" onclick="closeModal('bulkUploadCompanyModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <!-- Step 1: Download template + upload -->
        <div id="bulkStep1">
            <!-- Template Download -->
            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 16px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
                <div>
                    <div style="font-size:13px;font-weight:700;color:#1d4ed8;margin-bottom:3px;"><i class="fa-solid fa-file-csv" style="margin-right:6px;"></i>Download CSV Template</div>
                    <div style="font-size:11px;color:#3b82f6;">Columns: company_name, assigned_agent, company_email, company_number, company_website, fb_url, linkedin_url, insta_url, twitter_url</div>
                </div>
                <a href="company_list.php?download_company_template=1" style="background:#1d4ed8;color:#fff;padding:9px 16px;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:7px;white-space:nowrap;">
                    <i class="fa-solid fa-download"></i> Download Template
                </a>
            </div>

            <!-- File drop zone -->
            <div id="bulkDropZone" style="border:2px dashed #d1d5db;border-radius:10px;padding:30px;text-align:center;cursor:pointer;transition:.2s;margin-bottom:16px;" onclick="document.getElementById('bulkCsvFile').click()" ondragover="bulkDragOver(event)" ondragleave="bulkDragLeave(event)" ondrop="bulkDrop(event)">
                <i class="fa-solid fa-cloud-arrow-up" style="font-size:28px;color:#9ca3af;margin-bottom:8px;display:block;"></i>
                <div style="font-size:13px;font-weight:600;color:#374151;">Click to browse or drag & drop CSV here</div>
                <div style="font-size:11px;color:#9ca3af;margin-top:4px;">Only .csv files accepted</div>
                <input type="file" id="bulkCsvFile" accept=".csv" style="display:none;" onchange="bulkReadFile(this)">
            </div>

            <div id="bulkFileInfo" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:7px;padding:10px 14px;margin-bottom:14px;font-size:12px;font-weight:600;color:#15803d;">
                <i class="fa-solid fa-file-csv"></i> <span id="bulkFileName"></span> — <span id="bulkRowCount"></span> rows detected
            </div>

            <div id="bulkPreviewWrap" style="display:none;margin-bottom:16px;">
                <div style="font-size:12px;font-weight:700;color:#374151;margin-bottom:8px;">Preview (first 5 rows):</div>
                <div style="overflow-x:auto;border-radius:7px;border:1px solid #e5e7eb;">
                    <table id="bulkPreviewTable" style="width:100%;border-collapse:collapse;font-size:11px;">
                        <thead id="bulkPreviewHead" style="background:#f1f5f9;"></thead>
                        <tbody id="bulkPreviewBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Upload button -->
        <form action="company_list.php" method="POST" enctype="multipart/form-data" id="bulkUploadForm">
            <input type="file" name="company_csv" id="bulkHiddenFile" style="display:none;" accept=".csv">
            <div style="display:flex;gap:10px;margin-top:4px;">
                <button type="button" onclick="closeModal('bulkUploadCompanyModal')" style="flex:1;background:#f3f4f6;color:#374151;padding:11px;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;">Cancel</button>
                <button type="button" id="bulkUploadBtn" onclick="bulkSubmit()" disabled
                    style="flex:2;background:#10b981;color:#fff;padding:11px;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;opacity:.5;transition:.2s;">
                    <i class="fa-solid fa-upload"></i> Upload Companies
                </button>
            </div>
        </form>
    </div>
</div>


<!-- ══════════════════════════════════════════
     BULK EDIT MODAL
══════════════════════════════════════════ -->
<div id="bulkEditCompanyModal" class="modal">
    <div class="modal-content" style="max-width:820px;">
        <div class="modal-header">
            <div>
                <h2><i class="fa-solid fa-pen-to-square" style="color:#f59e0b;margin-right:8px;"></i>Bulk Edit Companies</h2>
                <p style="font-size:12px;color:#6b7280;margin-top:3px;">Upload a CSV file to update multiple companies at once.</p>
            </div>
            <button type="button" class="close-btn" onclick="closeModal('bulkEditCompanyModal')"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <!-- Step 1: Download template + upload -->
        <div id="bulkEditStep1">
            <!-- Template Download -->
            <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:14px 16px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
                <div>
                    <div style="font-size:13px;font-weight:700;color:#b45309;margin-bottom:3px;"><i class="fa-solid fa-file-csv" style="margin-right:6px;"></i>Download Edit Template</div>
                    <div style="font-size:11px;color:#d97706;">Columns: id, company_name, assigned_agent, company_email, company_number, company_website, fb_url, linkedin_url, insta_url, twitter_url</div>
                </div>
                <a href="company_list.php?download_edit_template=1" style="background:#b45309;color:#fff;padding:9px 16px;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:7px;white-space:nowrap;">
                    <i class="fa-solid fa-download"></i> Download Template
                </a>
            </div>

            <!-- File drop zone -->
            <div id="bulkEditDropZone" style="border:2px dashed #d1d5db;border-radius:10px;padding:30px;text-align:center;cursor:pointer;transition:.2s;margin-bottom:16px;" onclick="document.getElementById('bulkEditCsvFile').click()" ondragover="bulkEditDragOver(event)" ondragleave="bulkEditDragLeave(event)" ondrop="bulkEditDrop(event)">
                <i class="fa-solid fa-cloud-arrow-up" style="font-size:28px;color:#9ca3af;margin-bottom:8px;display:block;"></i>
                <div style="font-size:13px;font-weight:600;color:#374151;">Click to browse or drag & drop CSV here</div>
                <div style="font-size:11px;color:#9ca3af;margin-top:4px;">Only .csv files accepted</div>
                <input type="file" id="bulkEditCsvFile" accept=".csv" style="display:none;" onchange="bulkEditReadFile(this)">
            </div>

            <div id="bulkEditFileInfo" style="display:none;background:#fef3c7;border:1px solid #fde68a;border-radius:7px;padding:10px 14px;margin-bottom:14px;font-size:12px;font-weight:600;color:#b45309;">
                <i class="fa-solid fa-file-csv"></i> <span id="bulkEditFileName"></span> — <span id="bulkEditRowCount"></span> rows detected
            </div>

            <div id="bulkEditPreviewWrap" style="display:none;margin-bottom:16px;">
                <div style="font-size:12px;font-weight:700;color:#374151;margin-bottom:8px;">Preview (first 5 rows):</div>
                <div style="overflow-x:auto;border-radius:7px;border:1px solid #e5e7eb;">
                    <table id="bulkEditPreviewTable" style="width:100%;border-collapse:collapse;font-size:11px;">
                        <thead id="bulkEditPreviewHead" style="background:#f1f5f9;"></thead>
                        <tbody id="bulkEditPreviewBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Upload button -->
        <form action="company_list.php" method="POST" enctype="multipart/form-data" id="bulkEditUploadForm">
            <input type="file" name="company_edit_csv" id="bulkEditHiddenFile" style="display:none;" accept=".csv">
            <div style="display:flex;gap:10px;margin-top:4px;">
                <button type="button" onclick="closeModal('bulkEditCompanyModal')" style="flex:1;background:#f3f4f6;color:#374151;padding:11px;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;">Cancel</button>
                <button type="button" id="bulkEditUploadBtn" onclick="bulkEditSubmit()" disabled
                    style="flex:2;background:#f59e0b;color:#fff;padding:11px;border:none;border-radius:7px;font-size:13px;font-weight:700;cursor:pointer;opacity:.5;transition:.2s;">
                    <i class="fa-solid fa-pen-to-square"></i> Update Companies
                </button>
            </div>
        </form>
    </div>
</div>


<script>
// ── Modal helpers ──────────────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }

window.onclick = function(e) {
    if (e.target.classList.contains('modal')) {
        e.target.style.display = 'none';
        if (e.target.id === 'addCompanyModal') compResetWizard();
    }
};

// ── Tab Filter ──────────────────────────────────────────────────────────────
function filterCompanies(status, btnElement) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    btnElement.classList.add('active');
    document.querySelectorAll('.company-row').forEach(row => {
        if (status === 'all') {
            row.style.display = '';
        } else {
            row.style.display = (row.getAttribute('data-status') === status) ? '' : 'none';
        }
    });
}

// ── Current row data (for view→edit switch) ────────────────────────────────
let _currentCompany = null;

// ── VIEW modal ─────────────────────────────────────────────────────────────
function openViewModal(data) {
    _currentCompany = data;
    document.getElementById('view_comp_name').textContent     = data.company_name      || '—';
    // agent badges
    const agStr = data.assigned_agent || '';
    const agArr = agStr.split(',').map(s => s.trim()).filter(s => s && s !== 'Unassigned');
    document.getElementById('view_comp_agent').innerHTML = agArr.length
        ? agArr.map(a => `<span style="display:inline-flex;align-items:center;background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;margin:2px 3px 2px 0;">${a}</span>`).join('')
        : '<span style="color:#9ca3af;">Unassigned</span>';
    document.getElementById('view_comp_contacts').innerHTML   = `<span class="view-badge"><i class="fa-solid fa-users"></i> ${data.total_dynamic_contacts || 0} Contacts</span>`;
    document.getElementById('view_comp_id').textContent       = '#' + (data.id || '—');
    document.getElementById('view_comp_date').textContent     = data.created_at
        ? new Date(data.created_at).toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'})
        : '—';
    // New fields
    const email = data.company_email || '';
    const phone = data.company_number || '';
    const web   = data.company_website || '';
    document.getElementById('view_comp_email').innerHTML   = email ? `<a href="mailto:${email}" style="color:#3b82f6;">${email}</a>` : '—';
    document.getElementById('view_comp_phone').innerHTML   = phone ? `<a href="tel:${phone}" style="color:#374151;">${phone}</a>` : '—';
    document.getElementById('view_comp_website').innerHTML = web   ? `<a href="${web}" target="_blank" style="color:#8b5cf6;">${web}</a>` : '—';

    // Load contacts sub-table
    const wrap = document.getElementById('view_contacts_table_wrap');
    const badge = document.getElementById('view_contacts_count_badge');
    wrap.innerHTML = '<div style="text-align:center;padding:20px;color:#9ca3af;font-size:13px;"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div>';
    badge.textContent = '…';

    fetch('company_list.php?get_contacts=1&company_id=' + encodeURIComponent(data.id))
        .then(r => r.json())
        .then(contacts => {
            badge.textContent = contacts.length;
            if (contacts.length === 0) {
                wrap.innerHTML = '<div class="sub-contacts-no"><i class="fa-solid fa-user-slash" style="margin-right:6px;"></i>No contacts linked to this company yet.</div>';
                return;
            }
            let rows = contacts.map(c => {
                const desig = c.designation ? `<span class="pill pill-yellow">${c.designation}</span>` : '<span style="color:#9ca3af;">—</span>';
                const emailCell = c.email ? `<a href="mailto:${c.email}" style="color:#3b82f6;text-decoration:none;">${c.email}</a>` : '<span style="color:#9ca3af;">—</span>';
                const phoneCell = c.phone ? `<a href="tel:${c.phone}" style="color:#374151;text-decoration:none;">${c.phone}</a>` : '<span style="color:#9ca3af;">—</span>';
                return `<tr>
                    <td><b>${c.name}</b></td>
                    <td>${emailCell}</td>
                    <td>${phoneCell}</td>
                    <td>${desig}</td>
                    <td><a href="client_profile.php?id=${c.id}" style="display:inline-flex;align-items:center;gap:4px;background:#60a5fa;color:#fff;padding:4px 10px;border-radius:4px;font-size:11px;font-weight:600;text-decoration:none;"><i class="fa-regular fa-eye" style="font-size:10px;"></i> View</a></td>
                </tr>`;
            }).join('');
            wrap.innerHTML = `<div style="border-radius:8px;overflow:hidden;border:1px solid #e2e8f0;">
                <table class="sub-contacts-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Designation</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>`;
        })
        .catch(() => {
            wrap.innerHTML = '<div class="sub-contacts-no">Could not load contacts.</div>';
        });

    openModal('viewCompanyModal');
}

function switchViewToEdit() {
    closeModal('viewCompanyModal');
    if (_currentCompany) openEditModal(_currentCompany);
}

// ════════════════════════════════════════════════════════
// MULTI-SELECT AGENT DROPDOWN (no scroll) — JS ENGINE
// ════════════════════════════════════════════════════════
const _msAgentData = <?php echo $assigneeDataJson; ?>;
const _msSelected  = { editAgent: [], addAgent: [] };

function msInit(instance, selectedValues) {
    _msSelected[instance] = selectedValues ? selectedValues.slice() : [];
    msRenderOptions(instance);
    msRenderTags(instance);
}

function msRenderOptions(instance) {
    const wrap = document.getElementById(instance + 'Options');
    if (!wrap) return;
    const q = (document.getElementById(instance + 'Search')?.value || '').toLowerCase();
    const filtered = _msAgentData.filter(u =>
        !q || u.name.toLowerCase().includes(q) || u.username.toLowerCase().includes(q)
    );
    if (!filtered.length) {
        wrap.innerHTML = '<div class="ms-empty" style="grid-column:1/-1;">No agents found</div>';
        return;
    }
    wrap.innerHTML = filtered.map(u => {
        const sel     = _msSelected[instance].includes(u.name);
        const roleLbl = u.role === 'manager' ? 'manager' : (u.role === 'admin' ? 'admin' : 'agent');
        const roleCls = 'ms-role-' + roleLbl;
        const safe    = u.name.replace(/'/g, "\\'");
        return `<div class="ms-option${sel ? ' ms-selected' : ''}" onclick="msToggleOption('${instance}','${safe}',this)">
            <span class="ms-option-check"></span>
            <span class="ms-option-name" title="${u.name}">${u.name}</span>
            <span class="ms-role-badge ${roleCls}">${roleLbl}</span>
        </div>`;
    }).join('');
}

function msRenderTags(instance) {
    const el  = document.getElementById(instance + 'Tags');
    if (!el) return;
    const sel = _msSelected[instance];
    el.innerHTML = sel.length
        ? sel.map(v => `<span class="ms-tag">${v}<span class="ms-tag-x" onclick="msRemove('${instance}','${v.replace(/'/g,"\'")}',event)">×</span></span>`).join('')
        : '<span class="ms-placeholder">Select agents...</span>';
    msSyncHidden(instance);
}

function msToggleOption(instance, value, el) {
    const idx = _msSelected[instance].indexOf(value);
    if (idx > -1) { _msSelected[instance].splice(idx, 1); el.classList.remove('ms-selected'); }
    else           { _msSelected[instance].push(value);   el.classList.add('ms-selected'); }
    msRenderTags(instance);
}

function msRemove(instance, value, e) {
    e.stopPropagation();
    const idx = _msSelected[instance].indexOf(value);
    if (idx > -1) _msSelected[instance].splice(idx, 1);
    msRenderTags(instance);
    msRenderOptions(instance);
}

function msToggle(instance) {
    const dd = document.getElementById(instance + 'Dropdown');
    const tr = document.getElementById(instance + 'Trigger');
    if (!dd) return;
    const open = dd.classList.contains('ms-show');
    document.querySelectorAll('.ms-dropdown').forEach(d => d.classList.remove('ms-show'));
    document.querySelectorAll('.ms-trigger').forEach(t => t.classList.remove('ms-open'));
    if (!open) {
        dd.classList.add('ms-show'); tr.classList.add('ms-open');
        msRenderOptions(instance);
        setTimeout(() => document.getElementById(instance + 'Search')?.focus(), 50);
    }
}

function msSearch(input, instance) { msRenderOptions(instance); }

// বাইরে click করলে বন্ধ
document.addEventListener('click', e => {
    if (!e.target.closest('.ms-wrap')) {
        document.querySelectorAll('.ms-dropdown').forEach(d => d.classList.remove('ms-show'));
        document.querySelectorAll('.ms-trigger').forEach(t => t.classList.remove('ms-open'));
    }
});


function msSyncHidden(instance) {
    // form এর পুরনো hidden inputs মুছো
    const formId   = instance === 'editAgent' ? 'editCompanyForm' : 'addCompanyForm';
    const fieldName = instance === 'editAgent' ? 'edit_assigned_agent[]' : 'assigned_agent[]';
    const form = document.getElementById(formId);
    if (!form) return;
    form.querySelectorAll(`input[name="${fieldName}"]`).forEach(el => el.remove());
    const selected = _msSelected[instance];
    if (selected.length === 0) {
        // Unassigned পাঠাও
        const h = document.createElement('input');
        h.type = 'hidden'; h.name = fieldName.replace('[]',''); h.value = 'Unassigned';
        form.appendChild(h);
    } else {
        selected.forEach(v => {
            const h = document.createElement('input');
            h.type = 'hidden'; h.name = fieldName; h.value = v;
            form.appendChild(h);
        });
    }
}

// page load এ init
msInit('editAgent', []);
msInit('addAgent', []);

// ── EDIT modal ─────────────────────────────────────────────────────────────
function openEditModal(data) {
    _currentCompany = data;
    document.getElementById('edit_company_id').value   = data.id           || '';
    document.getElementById('edit_company_name').value = data.company_name || '';

    // assigned_agent comma-split করে multi-select init
    const agentStr = data.assigned_agent || '';
    const agentArr = agentStr.split(',').map(s => s.trim()).filter(s => s && s !== 'Unassigned');
    msInit('editAgent', agentArr);

    openModal('editCompanyModal');
}

// ── ADD COMPANY wizard ─────────────────────────────────────────────────────
let compCurrentStep = 1;
const compTotalSteps = 3;

function compUpdateUI() {
    for (let i = 1; i <= compTotalSteps; i++)
        document.getElementById('compStepBody' + i).classList.toggle('comp-step-active', i === compCurrentStep);

    const circles = [document.getElementById('compStep1'), document.getElementById('compStep2'), document.getElementById('compStep3')];
    const labels  = [document.getElementById('compLbl1'),  document.getElementById('compLbl2'),  document.getElementById('compLbl3')];
    circles.forEach((c, idx) => {
        c.classList.remove('active','completed');
        labels[idx].classList.remove('active-lbl');
        if      (idx + 1 <  compCurrentStep) { c.classList.add('completed'); c.innerHTML = '<i class="fa-solid fa-check"></i>'; }
        else if (idx + 1 === compCurrentStep) { c.classList.add('active');    c.innerHTML = idx + 1; labels[idx].classList.add('active-lbl'); }
        else                                  { c.innerHTML = idx + 1; }
    });

    document.getElementById('compBtnCancel').style.display = compCurrentStep === 1 ? 'inline-block' : 'none';
    document.getElementById('compBtnBack').style.display   = compCurrentStep > 1   ? 'inline-block' : 'none';
    document.getElementById('compBtnNext').style.display   = compCurrentStep < compTotalSteps ? 'flex' : 'none';
    document.getElementById('compBtnSave').style.display   = compCurrentStep === compTotalSteps ? 'flex' : 'none';
}

function compValidateStep() {
    if (compCurrentStep === 1) {
        const n = document.getElementById('comp_name_input');
        if (!n.value.trim()) {
            n.style.boxShadow = 'inset 0 0 0 1.5px #ef4444';
            showToast('Company Name is required!', 'error');
            return false;
        }
        n.style.boxShadow = '';
    }
    return true;
}

function compNextStep() { if (!compValidateStep()) return; if (compCurrentStep < compTotalSteps) { compCurrentStep++; compUpdateUI(); } }
function compPrevStep() { if (compCurrentStep > 1) { compCurrentStep--; compUpdateUI(); } }

function compResetWizard() {
    compCurrentStep = 1;
    const f = document.getElementById('addCompanyForm');
    if (f) { f.reset(); f.querySelectorAll('input,select').forEach(el => el.style.boxShadow = ''); }
    msInit('addAgent', []); // multi-select reset
    compUpdateUI();
}

function closeCompanyModal() { closeModal('addCompanyModal'); compResetWizard(); }

const _origOpen = openModal;
openModal = function(id) { if (id === 'addCompanyModal') compResetWizard(); _origOpen(id); };

// ── Live search ────────────────────────────────────────────────────────────
function searchTable() {
    const q = document.getElementById('compSearchInput').value.toLowerCase().trim();
    document.querySelectorAll('#companyTable tbody tr').forEach(tr => {
        const tds = tr.querySelectorAll('td');
        if (!tds.length) { tr.style.display = ''; return; }
        const cells = Array.from(tds).map(td => td.textContent.trim().toLowerCase());
        const rowText = cells.join(' ');
        tr.style.display = rowText.includes(q) ? '' : 'none';
    });
}

// ── Select all checkboxes ──────────────────────────────────────────────────
function toggleAll(master) {
    document.querySelectorAll('.tbl-checkbox').forEach(cb => cb.checked = master.checked);
}

// ── Toast ──────────────────────────────────────────────────────────────────
function showToast(msg, type) {
    const t = document.getElementById('toastBox');
    document.getElementById('toastMsg').innerText = msg;
    t.className = 'show ' + type;
    document.getElementById('toastIcon').className = type === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark';
    setTimeout(() => t.className = t.className.replace('show',''), 3500);
}

// ── Confirm delete ─────────────────────────────────────────────────────────
function confirmDelete(formId, typeName) {
    const dark = document.body.classList.contains('dark-mode');
    Swal.fire({
        title:'Are you sure?', text:"This action cannot be undone!", icon:'warning',
        showCancelButton:true, confirmButtonColor:'#ef4444', cancelButtonColor:'#6b7280',
        confirmButtonText:'Yes, delete!', background: dark ? '#1e293b' : '#fff', color: dark ? '#f8fafc' : '#111827'
    }).then(r => { if (r.isConfirmed) document.getElementById(formId).submit(); });
}

function confirmToggleCompanyStatus(formId, newStatus) {
    const dark = document.body.classList.contains('dark-mode');
    const isActivating = newStatus === 'active';
    Swal.fire({
        title: isActivating ? 'Mark as Active?' : 'Mark as Inactive?',
        text: isActivating ? 'This company will be set to Active.' : 'This company will be set to Inactive.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: isActivating ? '#10b981' : '#9ca3af',
        cancelButtonColor: '#6b7280',
        confirmButtonText: isActivating ? 'Yes, Activate!' : 'Yes, Deactivate!',
        background: dark ? '#1e293b' : '#fff',
        color: dark ? '#f8fafc' : '#111827'
    }).then(r => { if (r.isConfirmed) document.getElementById(formId).submit(); });
}

// ── Bulk Upload ────────────────────────────────────────────────────────────
let _bulkFile = null;
let _bulkData = [];

function bulkDragOver(e)  { e.preventDefault(); document.getElementById('bulkDropZone').style.borderColor='#3b82f6'; document.getElementById('bulkDropZone').style.background='#eff6ff'; }
function bulkDragLeave(e) { document.getElementById('bulkDropZone').style.borderColor='#d1d5db'; document.getElementById('bulkDropZone').style.background=''; }
function bulkDrop(e) {
    e.preventDefault();
    bulkDragLeave(e);
    const f = e.dataTransfer.files[0];
    if (f && f.name.endsWith('.csv')) { _bulkFile = f; bulkParseFile(f); }
    else showToast('Please drop a .csv file only!', 'error');
}
function bulkReadFile(input) {
    _bulkFile = input.files[0];
    if (_bulkFile) bulkParseFile(_bulkFile);
}
function bulkParseFile(file) {
    const reader = new FileReader();
    reader.onload = function(e) {
        const lines = e.target.result.split('\n').filter(l => l.trim());
        if (lines.length < 2) { showToast('CSV has no data rows!', 'error'); return; }
        const headers = lines[0].split(',').map(h => h.replace(/^"|"$/g,'').trim());
        _bulkData = [];
        for (let i = 1; i < lines.length; i++) {
            const cols = lines[i].split(',').map(c => c.replace(/^"|"$/g,'').trim());
            if (!cols[0]) continue;
            _bulkData.push(cols);
        }
        // File info
        document.getElementById('bulkFileName').textContent = file.name;
        document.getElementById('bulkRowCount').textContent = _bulkData.length;
        document.getElementById('bulkFileInfo').style.display = 'block';
        document.getElementById('bulkDropZone').style.borderColor = '#10b981';
        document.getElementById('bulkDropZone').style.background  = '#f0fdf4';

        // Preview table
        const headRow = '<tr>' + headers.map(h => `<th style="padding:8px 10px;font-weight:700;color:#374151;text-align:left;white-space:nowrap;">${h}</th>`).join('') + '</tr>';
        document.getElementById('bulkPreviewHead').innerHTML = headRow;
        const previewRows = _bulkData.slice(0, 5).map(cols =>
            '<tr style="border-top:1px solid #e5e7eb;">' + headers.map((_, idx) => `<td style="padding:7px 10px;color:#374151;white-space:nowrap;">${cols[idx] || '<span style="color:#9ca3af;">—</span>'}</td>`).join('') + '</tr>'
        ).join('');
        document.getElementById('bulkPreviewBody').innerHTML = previewRows;
        document.getElementById('bulkPreviewWrap').style.display = 'block';

        // Enable upload button
        const btn = document.getElementById('bulkUploadBtn');
        btn.disabled = false; btn.style.opacity = '1';
    };
    reader.readAsText(file);
}
function bulkSubmit() {
    if (!_bulkFile) { showToast('Please select a CSV file first!', 'error'); return; }
    // Transfer file to hidden input via DataTransfer
    const dt = new DataTransfer();
    dt.items.add(_bulkFile);
    document.getElementById('bulkHiddenFile').files = dt.files;
    // Add hidden submit field
    let hid = document.getElementById('bulk_submit_hidden');
    if (!hid) { hid = document.createElement('input'); hid.type='hidden'; hid.id='bulk_submit_hidden'; hid.name='bulk_upload_companies'; hid.value='1'; document.getElementById('bulkUploadForm').appendChild(hid); }
    document.getElementById('bulkUploadForm').submit();
}

// ── Bulk Edit ────────────────────────────────────────────────────────
let _bulkEditFile = null;
let _bulkEditData = [];

function bulkEditDragOver(e)  { e.preventDefault(); document.getElementById('bulkEditDropZone').style.borderColor='#f59e0b'; document.getElementById('bulkEditDropZone').style.background='#fef3c7'; }
function bulkEditDragLeave(e) { document.getElementById('bulkEditDropZone').style.borderColor='#d1d5db'; document.getElementById('bulkEditDropZone').style.background=''; }
function bulkEditDrop(e) {
    e.preventDefault();
    bulkEditDragLeave(e);
    const f = e.dataTransfer.files[0];
    if (f && f.name.endsWith('.csv')) { _bulkEditFile = f; bulkEditParseFile(f); }
    else showToast('Please drop a .csv file only!', 'error');
}
function bulkEditReadFile(input) {
    _bulkEditFile = input.files[0];
    if (_bulkEditFile) bulkEditParseFile(_bulkEditFile);
}
function bulkEditParseFile(file) {
    const reader = new FileReader();
    reader.onload = function(e) {
        const lines = e.target.result.split('\n').filter(l => l.trim());
        if (lines.length < 2) { showToast('CSV has no data rows!', 'error'); return; }
        const headers = lines[0].split(',').map(h => h.replace(/^"|"$/g,'').trim());
        _bulkEditData = [];
        for (let i = 1; i < lines.length; i++) {
            const cols = lines[i].split(',').map(c => c.replace(/^"|"$/g,'').trim());
            if (!cols[0]) continue;
            _bulkEditData.push(cols);
        }
        // File info
        document.getElementById('bulkEditFileName').textContent = file.name;
        document.getElementById('bulkEditRowCount').textContent = _bulkEditData.length;
        document.getElementById('bulkEditFileInfo').style.display = 'block';
        document.getElementById('bulkEditDropZone').style.borderColor = '#f59e0b';
        document.getElementById('bulkEditDropZone').style.background  = '#fef3c7';

        // Preview table
        const headRow = '<tr>' + headers.map(h => `<th style="padding:8px 10px;font-weight:700;color:#374151;text-align:left;white-space:nowrap;">${h}</th>`).join('') + '</tr>';
        document.getElementById('bulkEditPreviewHead').innerHTML = headRow;
        const previewRows = _bulkEditData.slice(0, 5).map(cols =>
            '<tr style="border-top:1px solid #e5e7eb;">' + headers.map((_, idx) => `<td style="padding:7px 10px;color:#374151;white-space:nowrap;">${cols[idx] || '<span style="color:#9ca3af;">—</span>'}</td>`).join('') + '</tr>'
        ).join('');
        document.getElementById('bulkEditPreviewBody').innerHTML = previewRows;
        document.getElementById('bulkEditPreviewWrap').style.display = 'block';

        // Enable upload button
        const btn = document.getElementById('bulkEditUploadBtn');
        btn.disabled = false; btn.style.opacity = '1';
    };
    reader.readAsText(file);
}
function bulkEditSubmit() {
    if (!_bulkEditFile) { showToast('Please select a CSV file first!', 'error'); return; }
    // Transfer file to hidden input via DataTransfer
    const dt = new DataTransfer();
    dt.items.add(_bulkEditFile);
    document.getElementById('bulkEditHiddenFile').files = dt.files;
    // Add hidden submit field
    let hid = document.getElementById('bulk_edit_submit_hidden');
    if (!hid) { hid = document.createElement('input'); hid.type='hidden'; hid.id='bulk_edit_submit_hidden'; hid.name='bulk_edit_companies'; hid.value='1'; document.getElementById('bulkEditUploadForm').appendChild(hid); }
    document.getElementById('bulkEditUploadForm').submit();
}

// ── Show toast on page load if PHP message ─────────────────────────────────
window.onload = function() {
    <?php if ($toastMessage): ?>
    showToast("<?php echo addslashes($toastMessage); ?>", "<?php echo $toastType; ?>");
    <?php endif; ?>
    
    <?php if (!empty($bulkEditResults)): ?>
    (function() {
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
            html: '<p style="font-size:13px;color:#374151;margin-bottom:12px;">The following rows had <b>no matching company ID</b> in the database and were skipped.</p>'
                + '<ul style="list-style:none;padding:8px 12px;max-height:200px;overflow-y:auto;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;text-align:left;">'
                + nfHtml + '</ul>'
                <?php if ($updated > 0): ?>
                + '<p style="margin-top:14px;font-size:12px;color:#6b7280;">✅ <b><?php echo $updated; ?> company/companies</b> updated successfully.</p>'
                <?php else: ?>
                + '<p style="margin-top:14px;font-size:12px;color:#ef4444;">❌ No companies were updated.</p>'
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
            html: '<p style="font-size:14px;color:#374151;"><b><?php echo $updated; ?> company/companies</b> updated successfully.</p>',
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
            text: 'No companies were updated. Please check your CSV file and ensure the id column is correct.',
            confirmButtonText: 'OK', confirmButtonColor: '#ef4444',
            customClass: { container: 'swal2-container' }
        });
        <?php endif; ?>
    })();
    <?php endif; ?>
};
</script>
</body>
</html>