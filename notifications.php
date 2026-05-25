<?php
/**
 * ============================================================
 * UNIFIED NOTIFICATION SYSTEM — Systellio CRM
 * ============================================================
 * এই ফাইল দুটি মোডে কাজ করে:
 *
 * 1. INCLUDE MODE (topbar.php থেকে include করলে):
 *    - Notification bell HTML + CSS + JS render করে
 *
 * 2. API MODE (JavaScript fetch() করলে):
 *    - JSON ডেটা রিটার্ন করে (polling এর জন্য)
 * 
 * Usage:
 *   <?php include 'notifications.php'; ?>
 * ============================================================
 */

if (session_status() === PHP_SESSION_NONE) session_start();

// ── Authentication check ──────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    if (php_sapi_name() === 'cli') die('No CLI access');
    http_response_code(401);
    die(json_encode(['error' => 'Unauthorized']));
}

// ── Database connection check ────────────────────────────────
if (!isset($conn)) {
    if (is_file('config.php')) @include 'config.php';
}

// ── API REQUEST? JSON রিটার্ন করো ──────────────────────────────
if (!empty($_GET['api']) || 
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
     strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (php_sapi_name() !== 'cli' && $_SERVER['REQUEST_METHOD'] === 'GET' && 
     strpos($_SERVER['REQUEST_URI'], 'notifications.php') !== false &&
     php_sapi_name() !== 'cli')) {
    
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    
    // ── Fetch notification data ──────────────────────────────
    $items = [];
    $db_now = time();
    
    if (isset($conn) && $conn) {
        $_current_user = $_SESSION['username'] ?? $_SESSION['name'] ?? '';
        $_current_role = $_SESSION['role'] ?? '';
        
        // ── Interest Updates (custom notifications) ──
        try {
            mysqli_query($conn, "CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                recipient VARCHAR(100) NOT NULL,
                sender VARCHAR(100) NOT NULL DEFAULT 'System',
                type VARCHAR(50) NOT NULL DEFAULT 'interest_update',
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                link VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            
            if (in_array($_current_role, ['super_admin', 'admin'])) {
                $nq = mysqli_query($conn,
                    "SELECT * FROM notifications
                     WHERE type='interest_update' AND created_at >= NOW() - INTERVAL 7 DAY
                     ORDER BY created_at DESC LIMIT 8");
            } else {
                $rec = mysqli_real_escape_string($conn, $_current_user);
                $nq = mysqli_query($conn,
                    "SELECT * FROM notifications
                     WHERE type='interest_update' AND recipient='$rec'
                       AND created_at >= NOW() - INTERVAL 7 DAY
                     ORDER BY created_at DESC LIMIT 8");
            }
            
            if ($nq) while ($r = mysqli_fetch_assoc($nq)) {
                $items[] = [
                    'icon' => 'fa-circle-dot',
                    'color' => '#f59e0b',
                    'label' => 'Interest Update',
                    'text' => $r['message'],
                    'time' => $r['created_at'],
                    'link' => $r['link'] ?? 'user_activity.php?filter=UPDATE',
                    'is_read' => (int)$r['is_read'],
                ];
            }
        } catch (Exception $e) {}
        
        // ── New Tasks (24h) ──────────────────────────────
        try {
            $nq = mysqli_query($conn,
                "SELECT title, assigned_to, created_at FROM tasks
                 WHERE created_at >= NOW() - INTERVAL 24 HOUR
                 ORDER BY created_at DESC LIMIT 5");
            if ($nq) while ($r = mysqli_fetch_assoc($nq)) {
                $items[] = [
                    'icon' => 'fa-clipboard-list',
                    'color' => '#3b82f6',
                    'label' => 'New Task',
                    'text' => $r['title'] . ' → ' . $r['assigned_to'],
                    'time' => $r['created_at'],
                    'link' => 'user_activity.php?filter=CREATE&search=task',
                    'is_read' => 1,
                ];
            }
        } catch (Exception $e) {}
        
        // ── New Deals (48h) ──────────────────────────────
        try {
            $nq = mysqli_query($conn,
                "SELECT deal_name, stage, created_at FROM deals
                 WHERE created_at >= NOW() - INTERVAL 48 HOUR
                 ORDER BY created_at DESC LIMIT 5");
            if ($nq) while ($r = mysqli_fetch_assoc($nq)) {
                $items[] = [
                    'icon' => 'fa-handshake',
                    'color' => '#10b981',
                    'label' => 'New Deal',
                    'text' => $r['deal_name'] . ' — Stage: ' . $r['stage'],
                    'time' => $r['created_at'],
                    'link' => 'deal_pipeline.php',
                    'is_read' => 1,
                ];
            }
        } catch (Exception $e) {}
        
        // ── New Users (72h) ──────────────────────────────
        try {
            $nq = mysqli_query($conn,
                "SELECT name, role, created_at FROM users
                 WHERE created_at >= NOW() - INTERVAL 72 HOUR
                 ORDER BY created_at DESC LIMIT 5");
            if ($nq) while ($r = mysqli_fetch_assoc($nq)) {
                $items[] = [
                    'icon' => 'fa-user-plus',
                    'color' => '#f59e0b',
                    'label' => 'New User',
                    'text' => $r['name'] . ' joined as ' . ucfirst(str_replace('_', ' ', $r['role'])),
                    'time' => $r['created_at'],
                    'link' => 'user_activity.php?filter=CREATE&search=user',
                    'is_read' => 1,
                ];
            }
        } catch (Exception $e) {}
        
        // ── New Campaigns (48h) ──────────────────────────
        try {
            $nq = mysqli_query($conn,
                "SELECT campaign_name, campaign_type, status, created_at FROM campaigns
                 WHERE created_at >= NOW() - INTERVAL 48 HOUR
                 ORDER BY created_at DESC LIMIT 3");
            if ($nq) while ($r = mysqli_fetch_assoc($nq)) {
                $items[] = [
                    'icon' => 'fa-bullhorn',
                    'color' => '#8b5cf6',
                    'label' => 'New Campaign',
                    'text' => $r['campaign_name'] . ' (' . $r['campaign_type'] . ')',
                    'time' => $r['created_at'],
                    'link' => 'campaigns.php',
                    'is_read' => 1,
                ];
            }
        } catch (Exception $e) {}
        
        // ── New Companies (72h) ──────────────────────────
        try {
            $nq = mysqli_query($conn,
                "SELECT company_name, created_at FROM companies
                 WHERE created_at >= NOW() - INTERVAL 72 HOUR
                 ORDER BY created_at DESC LIMIT 3");
            if ($nq) while ($r = mysqli_fetch_assoc($nq)) {
                $items[] = [
                    'icon' => 'fa-building',
                    'color' => '#06b6d4',
                    'label' => 'New Company',
                    'text' => $r['company_name'] . ' added',
                    'time' => $r['created_at'],
                    'link' => 'company_list.php',
                    'is_read' => 1,
                ];
            }
        } catch (Exception $e) {}
    }
    
    // ── Sort by timestamp (newest first) ────────────────────
    usort($items, fn($a, $b) => strtotime($b['time']) - strtotime($a['time']));
    $items = array_slice($items, 0, 15);
    
    // ── Build response with timeAgo ────────────────────────
    $response_items = [];
    foreach ($items as $n) {
        $diff = $db_now - strtotime($n['time']);
        if ($diff < 0) $diff = 0;
        $timeAgo = $diff < 60   ? 'Just now'
                 : ($diff < 3600  ? floor($diff/60).'m ago'
                 : ($diff < 86400 ? floor($diff/3600).'h ago'
                 :                  floor($diff/86400).'d ago'));
        
        // hex → rgb
        [$r, $g, $b] = sscanf(ltrim($n['color'], '#'), '%02x%02x%02x');
        
        $response_items[] = [
            'icon' => $n['icon'],
            'color' => $n['color'],
            'rgb' => "$r,$g,$b",
            'label' => $n['label'],
            'text' => htmlspecialchars($n['text']),
            'timeAgo' => $timeAgo,
            'ts' => strtotime($n['time']),
            'link' => $n['link'],
            'is_read' => $n['is_read'] ?? 1,
        ];
    }
    
    echo json_encode([
        'db_now' => $db_now,
        'items' => $response_items,
        'count' => count($response_items),
    ]);
    exit;
}

// ── INCLUDE MODE: Render notification bell component ─────────

// Load initial notification count
$_notif_items = [];
$_notif_count = 0;
$_latest_time = 0;
$_ts_array = '';

if (isset($conn) && $conn) {
    $_current_user = $_SESSION['username'] ?? $_SESSION['name'] ?? '';
    $_current_role = $_SESSION['role'] ?? '';
    
    // Interest Updates
    try {
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recipient VARCHAR(100) NOT NULL,
            sender VARCHAR(100) NOT NULL DEFAULT 'System',
            type VARCHAR(50) NOT NULL DEFAULT 'interest_update',
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            link VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        if (in_array($_current_role, ['super_admin', 'admin'])) {
            $nq = mysqli_query($conn,
                "SELECT * FROM notifications
                 WHERE type = 'interest_update'
                   AND created_at >= NOW() - INTERVAL 7 DAY
                 ORDER BY created_at DESC LIMIT 8");
        } else {
            $_rec_esc = mysqli_real_escape_string($conn, $_current_user);
            $nq = mysqli_query($conn,
                "SELECT * FROM notifications
                 WHERE type = 'interest_update'
                   AND recipient = '$_rec_esc'
                   AND created_at >= NOW() - INTERVAL 7 DAY
                 ORDER BY created_at DESC LIMIT 8");
        }
        
        if ($nq) while ($r = mysqli_fetch_assoc($nq)) {
            $_notif_items[] = [
                'icon' => 'fa-circle-dot',
                'color' => '#f59e0b',
                'label' => 'Interest Update',
                'text' => htmlspecialchars($r['message']),
                'time' => $r['created_at'],
                'link' => $r['link'] ?? '#',
                'is_read' => (int)$r['is_read'],
            ];
        }
    } catch (Exception $e) {}
    
    // New Tasks
    try {
        $nq = mysqli_query($conn,
            "SELECT title, assigned_to, created_at FROM tasks
             WHERE created_at >= NOW() - INTERVAL 24 HOUR
             ORDER BY created_at DESC LIMIT 5");
        if ($nq) while ($r = mysqli_fetch_assoc($nq)) {
            $_notif_items[] = [
                'icon' => 'fa-clipboard-list',
                'color' => '#3b82f6',
                'label' => 'New Task',
                'text' => htmlspecialchars($r['title']) . ' → ' . htmlspecialchars($r['assigned_to']),
                'time' => $r['created_at'],
            ];
        }
    } catch (Exception $e) {}
    
    // New Deals
    try {
        $nq = mysqli_query($conn,
            "SELECT deal_name, stage, created_at FROM deals
             WHERE created_at >= NOW() - INTERVAL 48 HOUR
             ORDER BY created_at DESC LIMIT 5");
        if ($nq) while ($r = mysqli_fetch_assoc($nq)) {
            $_notif_items[] = [
                'icon' => 'fa-handshake',
                'color' => '#10b981',
                'label' => 'New Deal',
                'text' => htmlspecialchars($r['deal_name']) . ' — Stage: ' . htmlspecialchars($r['stage']),
                'time' => $r['created_at'],
            ];
        }
    } catch (Exception $e) {}
    
    // New Users
    try {
        $nq = mysqli_query($conn,
            "SELECT name, role, created_at FROM users
             WHERE created_at >= NOW() - INTERVAL 72 HOUR
             ORDER BY created_at DESC LIMIT 5");
        if ($nq) while ($r = mysqli_fetch_assoc($nq)) {
            $_notif_items[] = [
                'icon' => 'fa-user-plus',
                'color' => '#f59e0b',
                'label' => 'New User',
                'text' => htmlspecialchars($r['name']) . ' joined as ' . ucfirst(str_replace('_', ' ', $r['role'])),
                'time' => $r['created_at'],
            ];
        }
    } catch (Exception $e) {}
    
    // New Campaigns
    try {
        $nq = mysqli_query($conn,
            "SELECT campaign_name, campaign_type, status, created_at FROM campaigns
             WHERE created_at >= NOW() - INTERVAL 48 HOUR
             ORDER BY created_at DESC LIMIT 3");
        if ($nq) while ($r = mysqli_fetch_assoc($nq)) {
            $_notif_items[] = [
                'icon' => 'fa-bullhorn',
                'color' => '#8b5cf6',
                'label' => 'New Campaign',
                'text' => htmlspecialchars($r['campaign_name']) . ' (' . htmlspecialchars($r['campaign_type']) . ')',
                'time' => $r['created_at'],
            ];
        }
    } catch (Exception $e) {}
    
    // New Companies
    try {
        $nq = mysqli_query($conn,
            "SELECT company_name, assigned_agent, created_at FROM companies
             WHERE created_at >= NOW() - INTERVAL 72 HOUR
             ORDER BY created_at DESC LIMIT 3");
        if ($nq) while ($r = mysqli_fetch_assoc($nq)) {
            $_notif_items[] = [
                'icon' => 'fa-building',
                'color' => '#06b6d4',
                'label' => 'New Company',
                'text' => htmlspecialchars($r['company_name']) . ' added',
                'time' => $r['created_at'],
            ];
        }
    } catch (Exception $e) {}
    
    // Sort & limit
    usort($_notif_items, fn($a, $b) => strtotime($b['time']) - strtotime($a['time']));
    $_notif_items = array_slice($_notif_items, 0, 15);
    $_notif_count = count($_notif_items);
    
    // Build HTML items & timestamps
    $_notif_html = '';
    $_ts_list = [];
    $_latest_time = 0;
    
    foreach ($_notif_items as $_n) {
        $_diff = time() - strtotime($_n['time']);
        $_timeAgo = $_diff < 3600
            ? floor($_diff / 60) . 'm ago'
            : ($_diff < 86400 ? floor($_diff / 3600) . 'h ago' : floor($_diff / 86400) . 'd ago');
        
        [$_r, $_g, $_b] = sscanf(ltrim($_n['color'], '#'), '%02x%02x%02x');
        $_is_unread = isset($_n['is_read']) && $_n['is_read'] == 0;
        $_item_style = $_is_unread ? "style='background:#fffbeb;'" : '';
        
        $_notif_html .= "
        <div class='sn-item sn-clickable' {$_item_style} data-href='" . htmlspecialchars($_n['link'] ?? '#') . "'>
            <div class='sn-icon' style='background:rgba({$_r},{$_g},{$_b},0.12);color:{$_n['color']};'>
                <i class='fa-solid {$_n['icon']}'></i>
            </div>
            <div class='sn-body'>
                <div class='sn-label'>{$_n['label']}</div>
                <div class='sn-text'>{$_n['text']}</div>
                <div class='sn-time'>{$_timeAgo} <span style='color:#3b82f6;font-size:10px;'>View →</span></div>
            </div>
        </div>";
        
        $_ts = strtotime($_n['time']);
        $_ts_list[] = $_ts;
        if ($_ts > $_latest_time) $_latest_time = $_ts;
    }
    
    $_ts_array = implode(',', $_ts_list);
}

?>

<!-- ============================================================
     NOTIFICATION BELL UI — notifications.php
     ============================================================ -->

<div id="snWrapper" style="position:relative;display:inline-block;">

    <!-- Bell Button -->
    <button id="snBell" class="tb-icon-btn" onclick="snToggle(event)" title="Notifications" aria-label="Toggle notifications">
        <i class="fa-solid fa-bell"></i>
        <?php if ($_notif_count > 0): ?>
            <span id="snBadge" class="sn-badge"><?php echo $_notif_count; ?></span>
        <?php else: ?>
            <span id="snBadge" class="sn-badge" style="display:none;">0</span>
        <?php endif; ?>
    </button>

    <!-- Notification Panel -->
    <div id="snPanel" class="sn-panel">
        <div class="sn-header">
            <div class="sn-header-left">
                <h3>Notifications</h3>
                <span id="snCountPill" class="sn-count-pill" <?php echo $_notif_count > 0 ? '' : "style='display:none;'"; ?>>
                    <?php echo $_notif_count; ?> new
                </span>
            </div>
            <div class="sn-header-right">
                <button class="sn-btn-close" onclick="snToggle(event)">
                    <i class="fa-solid fa-times"></i>
                </button>
            </div>
        </div>

        <div id="snList" class="sn-list">
            <?php if ($_notif_count > 0): ?>
                <?php echo $_notif_html; ?>
            <?php else: ?>
                <div class="sn-empty">
                    <i class="fa-regular fa-bell-slash"></i>
                    <p>No notifications</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="sn-footer">
            <a href="user_activity.php" class="sn-btn-view-all">View All Activity</a>
            <button class="sn-btn-mark-read" onclick="snMarkAllRead()">Mark as Read</button>
        </div>
    </div>

</div>

<!-- ============================================================
     STYLES — Notification System
     ============================================================ -->
<style>
/* Badge on bell */
.sn-badge {
    position: absolute;
    top: -6px;
    right: -6px;
    width: 20px;
    height: 20px;
    background: #ef4444;
    color: #ffffff;
    font-size: 10px;
    font-weight: 700;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.35);
}

/* Notification Panel */
.sn-panel {
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    width: 360px;
    max-width: 90vw;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
    z-index: 2000;
    flex-direction: column;
    max-height: 500px;
    display: none;
}
.sn-panel.sn-open { display: flex; }

/* Panel header */
.sn-header {
    padding: 16px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
}
.sn-header h3 {
    margin: 0;
    font-size: 15px;
    font-weight: 700;
    color: #111827;
}
.sn-header-left {
    display: flex;
    align-items: center;
    gap: 10px;
}
.sn-count-pill {
    background: #fee2e2;
    color: #991b1b;
    font-size: 10px;
    font-weight: 700;
    padding: 4px 8px;
    border-radius: 6px;
}
.sn-header-right { display: flex; gap: 8px; }
.sn-btn-close {
    width: 32px;
    height: 32px;
    border: none;
    background: transparent;
    color: #6b7280;
    cursor: pointer;
    border-radius: 6px;
    transition: background 0.2s;
    font-size: 14px;
}
.sn-btn-close:hover {
    background: #f3f4f6;
    color: #111827;
}

/* Notification list */
.sn-list {
    flex: 1;
    overflow-y: auto;
    padding: 8px;
}
.sn-list::-webkit-scrollbar { width: 4px; }
.sn-list::-webkit-scrollbar-track { background: transparent; }
.sn-list::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }

/* Notification item */
.sn-item {
    display: flex;
    gap: 12px;
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 8px;
    transition: background 0.2s, transform 0.15s;
}
.sn-item:hover {
    background: #f9fafb;
    transform: translateX(2px);
}
.sn-item.sn-clickable {
    cursor: pointer;
}

/* Icon container */
.sn-icon {
    width: 40px;
    height: 40px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}

/* Text content */
.sn-body {
    flex: 1;
    min-width: 0;
}
.sn-label {
    font-size: 12px;
    font-weight: 700;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 2px;
}
.sn-text {
    font-size: 13px;
    color: #374151;
    margin-bottom: 4px;
    word-break: break-word;
}
.sn-time {
    font-size: 11px;
    color: #9ca3af;
}

/* Empty state */
.sn-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
    color: #9ca3af;
}
.sn-empty i {
    font-size: 32px;
    margin-bottom: 8px;
    opacity: 0.5;
}
.sn-empty p {
    margin: 0;
    font-size: 13px;
}

/* Panel footer */
.sn-footer {
    padding: 12px;
    border-top: 1px solid #e5e7eb;
    display: flex;
    gap: 8px;
    flex-shrink: 0;
}
.sn-btn-view-all,
.sn-btn-mark-read {
    flex: 1;
    padding: 8px 12px;
    border: 1px solid #e5e7eb;
    background: #ffffff;
    color: #374151;
    font-size: 12px;
    font-weight: 600;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.2s, color 0.2s, border-color 0.2s;
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: center;
}
.sn-btn-view-all {
    color: #3b82f6;
    border-color: #bfdbfe;
    background: #eff6ff;
}
.sn-btn-view-all:hover {
    background: #dbeafe;
    border-color: #93c5fd;
}
.sn-btn-mark-read:hover {
    background: #f3f4f6;
    color: #111827;
}

/* Dark mode */
body.dark-mode .sn-panel {
    background: #1e293b;
    border-color: #334155;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.4);
}
body.dark-mode .sn-header { border-color: #334155; }
body.dark-mode .sn-header h3 { color: #f8fafc; }
body.dark-mode .sn-count-pill { background: #7f1d1d; color: #fecaca; }
body.dark-mode .sn-btn-close { color: #94a3b8; }
body.dark-mode .sn-btn-close:hover { background: #334155; color: #f8fafc; }
body.dark-mode .sn-item:hover { background: #334155; }
body.dark-mode .sn-label { color: #94a3b8; }
body.dark-mode .sn-text { color: #cbd5e1; }
body.dark-mode .sn-time { color: #64748b; }
body.dark-mode .sn-empty { color: #64748b; }
body.dark-mode .sn-footer { border-color: #334155; }
body.dark-mode .sn-btn-view-all,
body.dark-mode .sn-btn-mark-read { border-color: #334155; background: #0f172a; color: #cbd5e1; }
body.dark-mode .sn-btn-view-all { color: #60a5fa; background: #0f2a4a; border-color: #1e40af; }
body.dark-mode .sn-btn-view-all:hover { background: #1a3a52; border-color: #2563eb; }
body.dark-mode .sn-btn-mark-read:hover { background: #334155; color: #f8fafc; }

/* Bell pulse animation */
@keyframes snBellPulse {
    0%   { transform: rotate(0deg) scale(1); }
    20%  { transform: rotate(-15deg) scale(1.2); }
    40%  { transform: rotate(15deg) scale(1.2); }
    60%  { transform: rotate(-10deg) scale(1.1); }
    80%  { transform: rotate(5deg) scale(1.05); }
    100% { transform: rotate(0deg) scale(1); }
}
.sn-bell-pulse {
    animation: snBellPulse 0.6s ease;
    color: #3b82f6 !important;
}
</style>

<!-- ============================================================
     JAVASCRIPT — Polling & Interactions
     ============================================================ -->
<script>
(function() {
    'use strict';
    
    var STORAGE_KEY = 'sn_last_seen';
    var POLL_INTERVAL = 20000; // ২০ সেকেন্ড
    var _pollTimer = null;
    var _latestTs = <?php echo $_latest_time; ?>;
    var _allTs = [<?php echo $_ts_array; ?>];
    
    // ── Build item HTML from API data ────────────────────────
    function buildItemHTML(n) {
        var bgStyle = n.is_read == 0 ? 'background:#fffbeb;' : '';
        return "<div class='sn-item sn-clickable' style='" + bgStyle + "' data-href='" + n.link + "'>" +
            "<div class='sn-icon' style='background:rgba(" + n.rgb + ",0.12);color:" + n.color + ";'>" +
                "<i class='fa-solid " + n.icon + "'></i>" +
            "</div>" +
            "<div class='sn-body'>" +
                "<div class='sn-label'>" + n.label + "</div>" +
                "<div class='sn-text'>" + n.text + "</div>" +
                "<div class='sn-time'>" + n.timeAgo + " <span style='color:#3b82f6;font-size:10px;'>View →</span></div>" +
            "</div>" +
        "</div>";
    }
    
    // ── Update badge ─────────────────────────────────────────
    function snSetBadge(count) {
        var badge = document.getElementById('snBadge');
        var pill = document.getElementById('snCountPill');
        if (count > 0) {
            if (badge) { badge.textContent = count; badge.style.display = 'flex'; }
            if (pill) { pill.textContent = count + ' new'; pill.style.display = 'inline-block'; }
        } else {
            if (badge) badge.style.display = 'none';
            if (pill) pill.style.display = 'none';
        }
    }
    
    // ── Count unread ─────────────────────────────────────────
    function snUnreadCount(timestamps) {
        var lastSeen = parseInt(localStorage.getItem(STORAGE_KEY) || '0', 10);
        return timestamps.filter(function(t) { return t > lastSeen; }).length;
    }
    
    // ── Render list ──────────────────────────────────────────
    function snRenderList(items) {
        var list = document.getElementById('snList');
        if (!list) return;
        if (!items || items.length === 0) {
            list.innerHTML = "<div class='sn-empty'><i class='fa-regular fa-bell-slash'></i><p>No new notifications</p></div>";
            return;
        }
        var html = '';
        items.forEach(function(n) { html += buildItemHTML(n); });
        list.innerHTML = html;
    }
    
    // ── Poll API ─────────────────────────────────────────────
    function snPoll() {
        fetch('notifications.php', { credentials: 'same-origin' })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data || !data.items) return;
                
                var newTs = data.items.map(function(n) { return n.ts; });
                var latestFromServer = newTs.length > 0 ? Math.max.apply(null, newTs) : 0;
                var hadNew = latestFromServer > _latestTs;
                
                _allTs = newTs;
                _latestTs = latestFromServer > _latestTs ? latestFromServer : _latestTs;
                
                var panel = document.getElementById('snPanel');
                if (panel && panel.classList.contains('sn-open')) {
                    snRenderList(data.items);
                } else {
                    var unread = snUnreadCount(newTs);
                    snSetBadge(unread);
                    
                    if (hadNew) {
                        var bell = document.getElementById('snBell');
                        if (bell) {
                            bell.classList.add('sn-bell-pulse');
                            setTimeout(function() { bell.classList.remove('sn-bell-pulse'); }, 1000);
                        }
                    }
                }
                
                window._snCachedItems = data.items;
            })
            .catch(function() {});
    }
    
    // ── Toggle panel ─────────────────────────────────────────
    window.snToggle = function(e) {
        if (e) { e.stopPropagation(); e.preventDefault(); }
        var panel = document.getElementById('snPanel');
        if (!panel) return;
        
        var isOpen = panel.classList.contains('sn-open');
        panel.classList.toggle('sn-open');
        
        if (!isOpen) {
            fetch('notifications.php', { credentials: 'same-origin' })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data && data.items) {
                        window._snCachedItems = data.items;
                        _allTs = data.items.map(function(n) { return n.ts; });
                        _latestTs = _allTs.length > 0 ? Math.max.apply(null, _allTs) : _latestTs;
                        snRenderList(data.items);
                    }
                    if (_latestTs > 0) localStorage.setItem(STORAGE_KEY, _latestTs);
                    snSetBadge(0);
                })
                .catch(function() {
                    if (window._snCachedItems) snRenderList(window._snCachedItems);
                    if (_latestTs > 0) localStorage.setItem(STORAGE_KEY, _latestTs);
                    snSetBadge(0);
                });
        }
    };
    
    // ── Mark as read ────────────────────────────────────────
    window.snMarkAllRead = function() {
        if (_latestTs > 0) localStorage.setItem(STORAGE_KEY, _latestTs);
        snSetBadge(0);
        var list = document.getElementById('snList');
        if (list) list.innerHTML = "<div class='sn-empty'><i class='fa-regular fa-bell-slash'></i><p>No new notifications</p></div>";
    };
    
    // ── Click handlers ───────────────────────────────────────
    document.addEventListener('click', function(e) {
        var item = e.target.closest('.sn-clickable');
        if (item && item.dataset.href) {
            var panel = document.getElementById('snPanel');
            if (panel) panel.classList.remove('sn-open');
            window.location.href = item.dataset.href;
            return;
        }
        
        var wrapper = document.getElementById('snWrapper');
        var panelEl = document.getElementById('snPanel');
        if (panelEl && wrapper && !wrapper.contains(e.target)) {
            panelEl.classList.remove('sn-open');
        }
    });
    
    // ── Visibility change ────────────────────────────────────
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) snPoll();
    });
    
    // ── Initialize ───────────────────────────────────────────
    snSetBadge(snUnreadCount(_allTs));
    snPoll();
    _pollTimer = setInterval(snPoll, POLL_INTERVAL);
})();
</script>