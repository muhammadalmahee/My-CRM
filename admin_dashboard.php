<?php

//  Start PHP session — keeps the user's login state alive across page loads
session_start();

//  Include config.php — provides the database connection ($conn) and global settings
// The @ suppresses errors so the page doesn't crash if the file is missing
@include 'config.php'; 



//  Authentication + Authorization check:
// If the user is not logged in (no user_id in SESSION), or their role is not 'admin',
// redirect them immediately to index.php (the login page)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit(); // Stop all further code execution after the redirect
}

//  These variables are initialized here; their values will be set later
// by other included files (e.g. designation/company/task management pages)
$designationsList = "";      // HTML option list for the designation dropdown
$designationTableRows = "";  // HTML rows for the designation table
$assigneeOptions = "";       // HTML options for the task assignee dropdown
$companyOptions = "";        // HTML options for the company dropdown


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Systellio CRM</title>

    <!--  Favicon and Font Awesome icon library -->
    <link rel="icon" type="image/png" href="img/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!--  SweetAlert2 — used for styled popup/confirm dialogs throughout the dashboard -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        
        /*  Import 'Inter' font from Google Fonts — used for all text in the dashboard */
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

        /*  Global CSS reset: zero out margin/padding on all elements, use border-box sizing */
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }

        /*  Body layout: flex row so the sidebar and main content sit side by side
           overflow: hidden prevents scrolling on the body itself — only main-content scrolls */
        body { background-color: #f3f4f6; display: flex; height: 100vh; overflow: hidden; transition: background-color 0.3s, color 0.3s; color: #111827; }

        /*  Toast notification box — appears at the top-right corner of the screen
           When JS adds the .show class, it slides in via a spring-curve animation
           .success = green background, .error = red background */
        #toastBox { visibility: hidden; min-width: 250px; background-color: #333; color: #fff; text-align: center; border-radius: 8px; padding: 16px; position: fixed; z-index: 9999; right: 30px; top: 30px; font-size: 14px; font-weight: 600; box-shadow: 0 4px 12px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 10px; transform: translateX(100%); transition: transform 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55), visibility 0.4s; }
        #toastBox.show { visibility: visible; transform: translateX(0); }
        #toastBox.success { background-color: #10b981; }
        #toastBox.error { background-color: #ef4444; }

        /*  Main content area — takes up remaining space beside the sidebar and scrolls vertically */
        .main-content { flex-grow: 1; display: flex; flex-direction: column; overflow-y: auto; transition: background-color 0.3s ease; background-color: #f3f4f6; }
        .toggle-btn:hover { color: #111827; }
        .nav-icon-btn:hover { color: #3b82f6; }

        /*  Notification bell icon wrapper — position: relative so the badge can be absolutely positioned over it */
        .notif-wrapper { position: relative; overflow: visible !important; }

        /*  Notification dropdown panel — hidden by default, appears below the topbar when the bell is clicked
           position: fixed pins it to the viewport rather than scrolling with content */
        .notif-panel {
            display: none; position: fixed; top: 70px; right: 20px;
            width: 340px; background: #ffffff; border-radius: 12px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.18); border: 1px solid #e5e7eb;
            z-index: 9999; overflow: hidden;
        }
        .notif-panel.open { display: block; } /*  JS toggles .open to show/hide the panel */
        .notif-panel-header {
            padding: 16px 20px; border-bottom: 1px solid #f3f4f6;
            display: flex; justify-content: space-between; align-items: center;
        }
        .notif-panel-header h3 { font-size: 15px; font-weight: 700; color: #111827; }
        .notif-panel-header span { font-size: 11px; color: #6b7280; cursor: pointer; font-weight: 600; }
        .notif-panel-header span:hover { color: #3b82f6; }
        .notif-list { max-height: 360px; overflow-y: auto; } /*  Scrollable list if there are many notifications */
        .notif-item {
            display: flex; gap: 14px; padding: 14px 20px;
            border-bottom: 1px solid #f9fafb; cursor: pointer; transition: background 0.2s;
        }
        .notif-item:hover { background: #f9fafb; }
        .notif-item:last-child { border-bottom: none; }
        .notif-icon {
            width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; font-size: 15px;
        }
        .notif-body { flex: 1; }
        .notif-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #9ca3af; margin-bottom: 2px; }
        .notif-text { font-size: 13px; font-weight: 500; color: #111827; margin-bottom: 3px; line-height: 1.4; }
        .notif-time { font-size: 11px; color: #9ca3af; font-weight: 500; }
        .notif-empty { padding: 30px 20px; text-align: center; color: #9ca3af; font-size: 13px; }
        .notif-panel-footer { padding: 12px 20px; border-top: 1px solid #f3f4f6; text-align: center; }
        .notif-panel-footer a { font-size: 12px; font-weight: 600; color: #3b82f6; text-decoration: none; }

        /*  Dark mode overrides — when body has .dark-mode, the notification panel switches to a dark theme */
        body.dark-mode .notif-panel { background: #1e293b; border-color: #334155; box-shadow: 0 8px 30px rgba(0,0,0,0.4); }
        body.dark-mode .notif-panel-header { border-color: #334155; }
        body.dark-mode .notif-panel-header h3 { color: #f8fafc; }
        body.dark-mode .notif-item { border-color: #1e293b; }
        body.dark-mode .notif-item:hover { background: #0f172a; }
        body.dark-mode .notif-text { color: #e2e8f0; }
        body.dark-mode .notif-panel-footer { border-color: #334155; }

        /*  Red circular badge on the bell icon showing unread notification count */
        .notification-badge { position: absolute; top: -4px; right: -4px; background-color: #ef4444; color: white; font-size: 9px; font-weight: bold; padding: 2px 5px; border-radius: 50%; border: 2px solid #ffffff; }
        body.dark-mode .notification-badge { border-color: #1e293b; } /* Match badge border to dark background */

        /*  User profile icon in the topbar (right side) — blue color */
        .user-profile i { font-size: 24px; color: #3b82f6; }

        /*  Common padding shared by the Dashboard, Task, and Company section containers */
        .dashboard-container, .task-container, .company-container { padding: 30px; }
        .page-title { font-size: 24px; font-weight: 700; margin-bottom: 24px; transition: 0.3s;}

        /*  Responsive card grid — auto-fit columns, each at least 250px wide */
        .cards-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; }
        .card { background-color: #ffffff; padding: 24px; border-radius: 8px; box-shadow: 0px 4px 12px rgba(0, 0, 0, 0.03); display: flex; align-items: center; justify-content: space-between; border: 1px solid #e5e7eb; transition: 0.3s;}
        .card-info h4 { font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }
        .card-info h2 { font-size: 28px; transition: 0.3s;}
        .card-icon { background-color: #eff6ff; width: 50px; height: 50px; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 20px; color: #3b82f6; transition: 0.3s;}

        /*  User list section — hidden by default; JS calls showUserList() to reveal it */
        #userListSection { display: none; padding: 30px; }
        .user-list-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px; }
        .user-list-title h1 { font-size: 26px; font-weight: 800; margin-bottom: 4px; letter-spacing: -0.5px; transition: 0.3s;}
        .user-list-title p { font-size: 11px; color: #6b7280; font-weight: 500; }
        
        /*  Header action buttons: "Create User" (black) and "Designation" (blue) */
        .header-buttons { display: flex; gap: 10px; }
        .create-btn { background-color: #000000; color: #ffffff; padding: 10px 18px; border-radius: 6px; font-size: 12px; font-weight: 600; border: none; cursor: pointer; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); transition: 0.3s;}
        .create-btn:hover { background-color: #1f2937; }
        .desig-btn { background-color: #3b82f6; color: #ffffff; padding: 10px 18px; border-radius: 6px; font-size: 12px; font-weight: 600; border: none; cursor: pointer; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); transition: 0.3s;}
        .desig-btn:hover { background-color: #2563eb; }

        /*  Tab navigation bar — filters the user list by: All / Active / Inactive
           The top color line is a 3-segment gradient: blue | red | gray */
        .tabs-wrapper { margin-bottom: 20px; width: max-content; }
        .tab-top-line { height: 3px; width: 100%; background: linear-gradient(to right, #3b82f6 33%, #ef4444 33%, #ef4444 66%, #d1d5db 66%); border-radius: 3px 3px 0 0; }
        .tabs-container { display: flex; background: #ffffff; padding: 5px; border-radius: 0 0 6px 6px; gap: 5px; transition: 0.3s; border: 1px solid #e5e7eb; border-top: none;}
        .tab-btn { padding: 8px 18px; font-size: 12px; font-weight: 700; border: none; background: transparent; cursor: pointer; border-radius: 4px; color: #6b7280; display: flex; align-items: center; gap: 6px; transition: 0.3s;}
        .tab-btn.active { background: #f3f4f6; color: #111827; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }

        /*  Main data table styling */
        .table-wrapper { border-radius: 8px; overflow: hidden; border: 1px solid #d1d5db; transition: 0.3s; background: #ffffff;}
        .custom-table { width: 100%; border-collapse: collapse; text-align: center; font-size: 12px; }
        .custom-table th { background-color: #c4f042; padding: 14px 10px; font-weight: 700; color: #000000; border-bottom: 1px solid #d1d5db; transition: 0.3s;}
        .custom-table td { padding: 14px 10px; color: #374151; font-weight: 500; vertical-align: middle; border-right: 1px solid rgba(0,0,0,0.05); transition: 0.3s;}
        .custom-table td:last-child { border-right: none; }

        /*  Alternating 4-color row pattern — cycles every 4 rows: green → pink → orange → blue */
        .custom-table tbody tr:nth-child(4n+1) { background-color: #e6fced; } /* greenish */
        .custom-table tbody tr:nth-child(4n+2) { background-color: #fcedf6; } /* pinkish */
        .custom-table tbody tr:nth-child(4n+3) { background-color: #fceddb; } /* orangish */
        .custom-table tbody tr:nth-child(4n+4) { background-color: #e6edff; } /* blueish */

        .status-text { font-weight: 600; }
        /*  Row action buttons — View (blue), Edit (green), Delete (red) */
        .action-btns { display: flex; justify-content: center; gap: 6px; }
        .btn-view { background-color: #60a5fa; color: white; padding: 6px 10px; border-radius: 4px; font-size: 11px; border: none; cursor: pointer; transition: 0.3s;}
        .btn-view:hover { background-color: #3b82f6; }
        .btn-edit { background-color: #4ade80; color: white; padding: 6px 10px; border-radius: 4px; font-size: 11px; border: none; cursor: pointer; transition: 0.3s;}
        .btn-edit:hover { background-color: #22c55e; }
        .btn-delete { background-color: #f87171; color: white; padding: 6px 10px; border-radius: 4px; font-size: 11px; border: none; cursor: pointer; transition: 0.3s;}
        .btn-delete:hover { background-color: #ef4444; }

        /*  Modal overlay — covers the full screen with a semi-transparent backdrop, centers the popup */
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center; }
        .modal-content { background-color: #fff; padding: 30px; border-radius: 10px; width: 100%; max-width: 650px; box-shadow: 0 10px 25px rgba(0,0,0,0.15); max-height: 90vh; overflow-y: auto; transition: 0.3s;}
        .small-modal { max-width: 450px; } /* ✅ Smaller modal variant for compact forms like designation management */
        
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-header h2 { font-size: 20px; font-weight: 700; transition: 0.3s;}
        .close-btn { font-size: 20px; cursor: pointer; color: #6b7280; border: none; background: none; transition: 0.3s;}
        .close-btn:hover { color: #ef4444; } /* ✅ Close button turns red on hover */

        /*  Two-column form grid layout */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-group { margin-bottom: 5px; position: relative; } /* position: relative — needed to position the password eye icon inside the input */
        .full-width { grid-column: span 2; } /* ✅ Makes a field span both columns */
        .form-group label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; transition: 0.3s;}
        
        /*  Styles all form inputs and selects EXCEPT those with task/company-specific classes */
        .form-group input:not(.task-input-dark):not(.comp-input), 
        .form-group select:not(.task-tag-select):not(.comp-select) { 
            width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; outline: none; font-family: 'Inter', sans-serif; background-color: #f9fafb; transition: 0.3s;
        }
        .form-group input[type="file"] { background: transparent; border: none; padding: 5px 0;}
        /*  Focus state — blue border and white background when an input is active */
        .form-group input:focus:not(.task-input-dark):not(.comp-input), 
        .form-group select:focus:not(.task-tag-select):not(.comp-select) { border-color: #3b82f6; background-color: #fff; }
        
        /*  Password show/hide eye icon — positioned absolutely inside the input field on the right */
        .password-toggle { position: absolute; right: 12px; top: 32px; cursor: pointer; color: #6b7280; }
        /*  Password mismatch error message — hidden by default; JS sets display:block when passwords don't match */
        .password-error { color: #ef4444; font-size: 10px; font-weight: 600; margin-top: 4px; display: none; }

        /*  Submit button — full width, black, darkens on hover, grayed out when disabled */
        .submit-btn { background-color: #000000; color: #ffffff; padding: 12px; border: none; border-radius: 6px; width: 100%; font-size: 14px; font-weight: 600; cursor: pointer; transition: 0.3s; }
        .submit-btn:hover { background-color: #1f2937; }
        .submit-btn:disabled { background-color: #9ca3af; cursor: not-allowed; }

        /*  Read-only data display box in view mode — looks like an input but is not editable */
        .view-data-box { background: #f9fafb; padding: 10px 12px; border-radius: 6px; border: 1px solid #e5e7eb; font-size: 13px; font-weight: 500; word-break: break-all; min-height: 40px; display: flex; align-items: center; transition: 0.3s;}
        .desig-text { color: #374151; }

        /*  Import 'DM Mono' monospace font — used for all numeric/stat displays */
        @import url('https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&display=swap');

        /*  Main dashboard overview content wrapper */
        #mainDashboardContent { padding: 28px 30px 36px; transition: background-color 0.3s; }

        /*  Dashboard heading row — title/subtitle on the left, date badge on the right */
        .ov-heading { display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:24px; }
        .ov-heading-left h1 { font-size:22px; font-weight:800; color:#0f172a; letter-spacing:-0.4px; margin-bottom:2px; }
        .ov-heading-left p   { font-size:12px; color:#94a3b8; font-weight:500; }
        /*  Pill-shaped date badge — displays today's date in monospace font */
        .ov-date-badge { background:#f1f5f9; border:1px solid #e2e8f0; color:#64748b; font-size:11px; font-weight:600; padding:6px 14px; border-radius:20px; font-family:'DM Mono',monospace; }

        /*  KPI strip — 6 cards in a single row using a responsive CSS grid */
        .kpi-strip { display:grid; grid-template-columns:repeat(6,1fr); gap:14px; margin-bottom:20px; }
        /*  Each KPI card has a colored top accent line rendered via the ::before pseudo-element */
        .kpi-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:18px 16px; position:relative; overflow:hidden; transition:transform .2s,box-shadow .2s,background-color .3s,border-color .3s; cursor:default; }
        .kpi-card:hover { transform:translateY(-3px); box-shadow:0 8px 24px rgba(0,0,0,.07); } /* ✅ Card lifts up on hover */
        .kpi-card::before { content:''; position:absolute; top:0;left:0;right:0; height:3px; border-radius:12px 12px 0 0; }
        /*  Six color variants for the top accent line */
        .kpi-card.kpi-blue::before   { background:#3b82f6; }
        .kpi-card.kpi-violet::before { background:#8b5cf6; }
        .kpi-card.kpi-green::before  { background:#10b981; }
        .kpi-card.kpi-amber::before  { background:#f59e0b; }
        .kpi-card.kpi-rose::before   { background:#f43f5e; }
        .kpi-card.kpi-cyan::before   { background:#06b6d4; }
        /*  Icon box inside each KPI card — colored background matching the card's variant */
        .kpi-icon { width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;margin-bottom:12px; }
        .kpi-blue   .kpi-icon { background:#eff6ff;color:#3b82f6; }
        .kpi-violet .kpi-icon { background:#ede9fe;color:#8b5cf6; }
        .kpi-green  .kpi-icon { background:#ecfdf5;color:#10b981; }
        .kpi-amber  .kpi-icon { background:#fffbeb;color:#f59e0b; }
        .kpi-rose   .kpi-icon { background:#fff1f2;color:#f43f5e; }
        .kpi-cyan   .kpi-icon { background:#ecfeff;color:#06b6d4; }
        .kpi-label { font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#94a3b8;margin-bottom:4px; }
        /*  Large number displayed in monospace font */
        .kpi-value { font-size:28px;font-weight:800;color:#0f172a;line-height:1;letter-spacing:-1px;font-family:'DM Mono',monospace;margin-bottom:6px; }
        .kpi-value.kpi-value-sm { font-size:18px; margin-top:4px; } /*  Smaller size for longer values like deal amounts */
        .kpi-sub   { font-size:11px;color:#64748b;font-weight:500; }
        .kpi-sub b { color:#0f172a; }

        /*  Middle row — 3 columns: Deal Funnel (wider) | Task Donut | Team Breakdown */
        .ov-mid-row    { display:grid; grid-template-columns:1.6fr 1fr 1.4fr; gap:16px; margin-bottom:20px; }
        /*  Bottom row — 3 columns: Recent Deals | Recent Tasks | Recent Users */
        .ov-bottom-row { display:grid; grid-template-columns:1.6fr 1.2fr 1.2fr; gap:16px; }

        /*  Standard white panel/card component used throughout the overview section */
        .ov-panel { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; transition:background-color .3s,border-color .3s; }
        .ov-panel-title { font-size:13px;font-weight:700;color:#111827;margin-bottom:16px;display:flex;align-items:center;gap:8px; }
        .ov-panel-title i { font-size:13px;color:#94a3b8; }

        /*  Deal pipeline funnel — horizontal bar chart layout */
        .funnel-row   { display:flex;align-items:center;gap:10px;margin-bottom:12px; }
        .funnel-label { width:95px;font-size:11px;font-weight:600;color:#374151;flex-shrink:0; }
        .funnel-bar-wrap { flex:1;background:#f1f5f9;border-radius:20px;height:8px;overflow:hidden; }
        .funnel-bar   { height:100%;border-radius:20px;transition:width .6s ease; } /*  Bar width animates in via CSS transition */
        .funnel-count { font-size:11px;font-weight:700;color:#374151;font-family:'DM Mono',monospace;width:24px;text-align:right;flex-shrink:0; }
        .funnel-deal-total { margin-top:14px;padding-top:14px;border-top:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center; }
        .funnel-deal-total span { font-size:11px;color:#94a3b8;font-weight:500; }
        .funnel-deal-total strong { font-size:18px;font-weight:800;color:#0f172a;font-family:'DM Mono',monospace; }

        /*  Task status donut chart container */
        .task-ring-wrap { display:flex;align-items:center;justify-content:center;flex-direction:column;gap:16px; }
        .donut-svg { transform:rotate(-90deg); } /*  Rotate SVG so arcs start at 12 o'clock instead of 3 o'clock */
        .donut-bg  { fill:none;stroke:#f1f5f9; } /*  Light gray background ring behind the colored segments */
        .trl-row   { display:flex;align-items:center;justify-content:space-between;padding:5px 0;font-size:12px;border-bottom:1px solid #f8fafc; }
        .trl-dot   { width:8px;height:8px;border-radius:50%;flex-shrink:0; }
        .trl-name  { flex:1;margin-left:8px;color:#374151;font-weight:500; }
        .trl-num   { font-weight:700;color:#0f172a;font-family:'DM Mono',monospace; }

        /*  Team breakdown rows — colored role avatar + role info + numeric count */
        .ubl-row    { display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f8fafc; }
        .ubl-row:last-child { border-bottom:none; }
        .ubl-avatar { width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0; }
        .ubl-info   { flex:1; }
        .ubl-role   { font-size:12px;font-weight:700;color:#111827; }
        .ubl-stat   { font-size:11px;color:#94a3b8;font-weight:500; }
        .ubl-count  { font-size:22px;font-weight:800;color:#0f172a;font-family:'DM Mono',monospace; }

        /*  Compact mini tables used inside Recent Deals, Recent Tasks, and Recent Users panels */
        .mini-table { width:100%;border-collapse:collapse;font-size:12px; }
        .mini-table th { background:#f8fafc;padding:8px 10px;font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.5px;text-align:left;border-bottom:1px solid #f1f5f9; }
        .mini-table td { padding:10px;border-bottom:1px solid #f8fafc;color:#374151;font-weight:500;vertical-align:middle; }
        .mini-table tr:last-child td { border-bottom:none; }
        .mini-deal-name { font-weight:700;color:#111827;max-width:130px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; } /* ✅ Long names truncate with ellipsis */
        .ov-panel-footer { margin-top:10px;padding-top:10px;border-top:1px solid #f1f5f9;text-align:center; }
        .ov-panel-footer a { font-size:11px;font-weight:700;color:#3b82f6;text-decoration:none; }
        .ov-panel-footer a:hover { text-decoration:underline; }
        .ov-empty { text-align:center;padding:24px 10px;color:#cbd5e1;font-size:12px; } /* ✅ Empty state shown when no data exists */
        .ov-empty i { font-size:28px;display:block;margin-bottom:8px; }

        .mini-td-title  { font-weight:600;color:#111827;max-width:110px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
        .mini-td-amount { font-family:'DM Mono',monospace;font-weight:700;color:#059669;white-space:nowrap; } /* ✅ Deal amounts displayed in green monospace */
        body.dark-mode .mini-td-title  { color:#f8fafc; }
        body.dark-mode .mini-td-amount { color:#34d399; }

        /*  Dark mode overrides for the entire main dashboard content area */
        body.dark-mode #mainDashboardContent { background-color:#0f172a; }
        body.dark-mode .ov-heading-left h1 { color:#f8fafc; }
        body.dark-mode .ov-heading-left p  { color:#94a3b8; }
        body.dark-mode .ov-date-badge { background:#1e293b;border-color:#334155;color:#94a3b8; }

        body.dark-mode .kpi-card { background:#1e293b;border-color:#334155; }
        body.dark-mode .kpi-value { color:#f8fafc; }
        body.dark-mode .kpi-label { color:#64748b; }
        body.dark-mode .kpi-sub   { color:#94a3b8; }
        body.dark-mode .kpi-sub b { color:#e2e8f0; }
        /*  Dark mode — deeper background colors for each KPI icon box */
        body.dark-mode .kpi-blue   .kpi-icon { background:#1e3a5f; }
        body.dark-mode .kpi-violet .kpi-icon { background:#2e1065; }
        body.dark-mode .kpi-green  .kpi-icon { background:#052e16; }
        body.dark-mode .kpi-amber  .kpi-icon { background:#2d1a00; }
        body.dark-mode .kpi-rose   .kpi-icon { background:#2d0a16; }
        body.dark-mode .kpi-cyan   .kpi-icon { background:#082f49; }

        body.dark-mode .ov-panel { background:#1e293b;border-color:#334155; }
        body.dark-mode .ov-panel-title { color:#f8fafc; }
        body.dark-mode .ov-panel-footer { border-color:#334155; }
        body.dark-mode .ov-panel-footer a { color:#60a5fa; }
        body.dark-mode .ov-empty { color:#475569; }

        body.dark-mode .funnel-label  { color:#94a3b8; }
        body.dark-mode .funnel-count  { color:#94a3b8; }
        body.dark-mode .funnel-bar-wrap { background:#0f172a; }
        body.dark-mode .funnel-deal-total { border-color:#334155; }
        body.dark-mode .funnel-deal-total span  { color:#64748b; }
        body.dark-mode .funnel-deal-total strong { color:#f8fafc; }

        body.dark-mode .trl-row  { border-color:#1e293b; }
        body.dark-mode .trl-name { color:#e2e8f0; }
        body.dark-mode .trl-num  { color:#94a3b8; }

        body.dark-mode .ubl-row    { border-color:#1e293b; }
        body.dark-mode .ubl-role   { color:#f8fafc; }
        body.dark-mode .ubl-stat   { color:#64748b; }
        body.dark-mode .ubl-count  { color:#f8fafc; }

        body.dark-mode .mini-table th { background:#0f172a;color:#64748b;border-color:#1e293b; }
        body.dark-mode .mini-table td { border-color:#1e293b;color:#94a3b8; }
        body.dark-mode .mini-deal-name { color:#f8fafc; }
        body.dark-mode .mini-table td[style*="color:#111827"] { color:#f8fafc !important; }
        body.dark-mode .mini-table td[style*="color:#059669"] { color:#34d399 !important; }

        /*  Responsive breakpoints:
           Below 1280px: KPI strip collapses to 3 columns, mid/bottom rows to 2 columns
           Below 900px:  KPI strip collapses to 2 columns, all rows become single column */
        @media(max-width:1280px){
            .kpi-strip{grid-template-columns:repeat(3,1fr);}
            .ov-mid-row,.ov-bottom-row{grid-template-columns:1fr 1fr;}
        }
        @media(max-width:900px){
            .kpi-strip{grid-template-columns:repeat(2,1fr);}
            .ov-mid-row,.ov-bottom-row{grid-template-columns:1fr;}
        }
    </style>
</head>
<body>

    <!--  Toast notification container — JS dynamically sets the message text and success/error class -->
    <div id="toastBox">
        <i id="toastIcon" class="fa-solid fa-circle-check"></i>
        <span id="toastMsg">Action Successful!</span>
    </div>

    <!--  Include the sidebar, passing 'dashboard' as the active page
         so the sidebar can highlight the correct navigation link -->
    <?php
    $activePage    = 'dashboard';
    $sidebarRole   = 'Admin';
    $dashboardFile = 'admin_dashboard.php';
    include 'sidebar.php';
?>

    <div class="main-content">
        
        <!--  Include the topbar — contains the nav icons, notification bell, and user profile -->
        <?php include 'topbar.php'; ?>

        <?php
        
        //  Initialize the $ov (overview) data array with all values set to 0 or empty arrays
        // This ensures the page renders safely even if the DB connection fails
        $ov = ['total_users'=>0,'active_users'=>0,'inactive_users'=>0,'admins'=>0,'managers'=>0,'agents'=>0,
               'total_companies'=>0,'total_contacts'=>0,'total_deals'=>0,'deal_value'=>0,
               'deals_won'=>0,'deals_lost'=>0,'total_tasks'=>0,'tasks_todo'=>0,'tasks_progress'=>0,
               'tasks_done'=>0,'tasks_overdue'=>0,'total_campaigns'=>0,'camp_active'=>0,'camp_planning'=>0,
               'recent_deals'=>[],'recent_tasks'=>[],'recent_users'=>[]];

        if(isset($conn)){ //  Only run DB queries if a connection is available

            // Current admin's username (used to filter owned data)
            $adminUsername = mysqli_real_escape_string($conn, $_SESSION['username'] ?? '');

            // Find all usernames created by this admin (created_by = adminUsername)
            $teamUsers = [$adminUsername];
            $tuq = mysqli_query($conn, "SELECT username FROM users WHERE created_by='$adminUsername' AND role IN ('manager','agent') AND status='active'");
            if($tuq) while($tu = mysqli_fetch_assoc($tuq)) $teamUsers[] = $tu['username'];
            $teamList = implode("','", array_map(fn($u)=>mysqli_real_escape_string($conn,$u), $teamUsers));

            //  Users table: count only users created by this admin
            $r=mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) t,SUM(status='active') a,SUM(status='inactive') i,SUM(role='manager') mgr,SUM(role='agent') agt FROM users WHERE created_by='$adminUsername' AND role IN ('manager','agent')"));
            if($r){$ov['total_users']=(int)$r['t'];$ov['active_users']=(int)$r['a'];$ov['inactive_users']=(int)$r['i'];$ov['admins']=0;$ov['managers']=(int)$r['mgr'];$ov['agents']=(int)$r['agt'];}

            //  Companies: assigned to admin or any of their team
            $r2=mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM companies WHERE assigned_agent IN ('$teamList')")); if($r2) $ov['total_companies']=(int)$r2['c'];

            //  Contacts: linked to companies assigned to admin or their team
            $r3=mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) c FROM contacts WHERE company_id IN (SELECT id FROM companies WHERE assigned_agent IN ('$teamList'))")); if($r3) $ov['total_contacts']=(int)$r3['c'];

            //  Deals: admin + team members
            $r4=mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) t,COALESCE(SUM(deal_value),0) v,SUM(stage='Won') w,SUM(stage='Lost') l FROM deals WHERE sales_officer IN ('$teamList')"));
            if($r4){$ov['total_deals']=(int)$r4['t'];$ov['deal_value']=(float)$r4['v'];$ov['deals_won']=(int)$r4['w'];$ov['deals_lost']=(int)$r4['l'];}

            //  Tasks: assigned to or assigned_by admin/team members
            $teamNames = [];
            $tnq = mysqli_query($conn, "SELECT name FROM users WHERE username IN ('$teamList')");
            if($tnq) while($tn = mysqli_fetch_assoc($tnq)) $teamNames[] = mysqli_real_escape_string($conn, $tn['name']);
            $teamNameList = implode("','", $teamNames);
            $r5=mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) t,SUM(status='To-Do') td,SUM(status='In-Progress') ip,SUM(status='Done') d,SUM(due_date<CURDATE() AND status!='Done') ov FROM tasks WHERE assigned_to IN ('$teamNameList') OR assigned_by IN ('$teamNameList')"));
            if($r5){$ov['total_tasks']=(int)$r5['t'];$ov['tasks_todo']=(int)$r5['td'];$ov['tasks_progress']=(int)$r5['ip'];$ov['tasks_done']=(int)$r5['d'];$ov['tasks_overdue']=(int)$r5['ov'];}

            //  Campaigns: admin + team members
            $r6=mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) t,SUM(status='Active') a,SUM(status='Planning') p FROM campaigns WHERE assigned_to IN ('$teamList')"));
            if($r6){$ov['total_campaigns']=(int)$r6['t'];$ov['camp_active']=(int)$r6['a'];$ov['camp_planning']=(int)$r6['p'];}

            //  Recent data — all filtered to this admin's scope
            $dq=mysqli_query($conn,"SELECT deal_name,deal_value,currency,stage FROM deals WHERE sales_officer IN ('$teamList') ORDER BY id DESC LIMIT 5"); if($dq) while($row=mysqli_fetch_assoc($dq)) $ov['recent_deals'][]=$row;
            $tq=mysqli_query($conn,"SELECT title,status,priority,due_date FROM tasks WHERE assigned_to IN ('$teamNameList') OR assigned_by IN ('$teamNameList') ORDER BY id DESC LIMIT 4"); if($tq) while($row=mysqli_fetch_assoc($tq)) $ov['recent_tasks'][]=$row;
            $uq=mysqli_query($conn,"SELECT name,role,status FROM users WHERE created_by='$adminUsername' AND role IN ('manager','agent') ORDER BY id DESC LIMIT 4"); if($uq) while($row=mysqli_fetch_assoc($uq)) $ov['recent_users'][]=$row;

            // Deal pipeline stage breakdown — filtered to this admin
            $adminStageFilter = "WHERE sales_officer IN ('$teamList')";
        }
        //  Helper: formats large numbers into K/M notation (e.g. 1500 → USD 1.5K, 2000000 → USD 2.0M)
        function ovFmt($v,$c='USD'){if($v>=1000000)return $c.' '.number_format($v/1000000,1).'M';if($v>=1000)return $c.' '.number_format($v/1000,1).'K';return $c.' '.number_format($v,0);}

        //  Helper: returns a colored pill badge for a deal stage (Lead, Proposal, Negotiation, Won, Lost)
        function ovStage($s){$m=['Lead'=>['#dbeafe','#1d4ed8'],'Proposal'=>['#fef9c3','#a16207'],'Negotiation'=>['#fff7ed','#c2410c'],'Won'=>['#dcfce7','#15803d'],'Lost'=>['#fee2e2','#b91c1c']];$c=$m[$s]??['#f3f4f6','#374151'];return "<span style='background:{$c[0]};color:{$c[1]};padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;'>$s</span>";}

        //  Helper: returns a colored pill badge for a task status (To-Do = gray, In-Progress = blue, Done = green)
        function ovTask($s){$m=['To-Do'=>['#f3f4f6','#6b7280'],'In-Progress'=>['#dbeafe','#1d4ed8'],'Done'=>['#dcfce7','#15803d']];$c=$m[$s]??['#f3f4f6','#374151'];return "<span style='background:{$c[0]};color:{$c[1]};padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;'>$s</span>";}

        //  Helper: returns a colored pill badge for task priority (High = red, Medium = yellow, Low = green)
        function ovPrio($p){$m=['High'=>['#fee2e2','#b91c1c'],'Medium'=>['#fef3c7','#b45309'],'Low'=>['#dcfce7','#15803d']];$c=$m[$p]??['#f3f4f6','#374151'];return "<span style='background:{$c[0]};color:{$c[1]};padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;'>$p</span>";}

        //  Helper: returns a colored pill badge for a user role
        function ovRole($r){$l=ucfirst(str_replace('_',' ',$r));$m=['admin'=>['#dbeafe','#1d4ed8'],'manager'=>['#fef3c7','#b45309'],'agent'=>['#dcfce7','#15803d']];$c=$m[$r]??['#f3f4f6','#374151'];return "<span style='background:{$c[0]};color:{$c[1]};padding:2px 10px;border-radius:20px;font-size:10px;font-weight:700;'>$l</span>";}
        ?>
        <!--  Main dashboard content area — holds all overview panels -->
        <div id="mainDashboardContent">

            <!--  Dashboard heading row: page title + welcome message on the left, today's date on the right -->
            <div class="ov-heading">
                <div class="ov-heading-left">
                    <h1>CRM Overview</h1>
                    <!--  Logged-in user's name pulled from SESSION; falls back to 'Admin' if not set -->
                    <p>Welcome back, <b><?php echo htmlspecialchars($_SESSION['name']??'Admin'); ?></b> — Here's a full breakdown of your CRM.</p>
                </div>
                <!--  Today's date formatted by PHP date() -->
                <div class="ov-date-badge"><i class="fa-regular fa-calendar" style="margin-right:6px;"></i><?php echo date('D, d M Y'); ?></div>
            </div>
            <!--  KPI Strip — 6 summary cards: Users | Companies | Deal Value | Tasks | Campaigns | Clients -->
            <div class="kpi-strip">
                <div class="kpi-card kpi-blue">
                    <div class="kpi-icon"><i class="fa-solid fa-users"></i></div>
                    <div class="kpi-label">Total Users</div>
                    <div class="kpi-value"><?php echo $ov['total_users']; ?></div>
                    <!--  Active and inactive breakdown shown as sub-text -->
                    <div class="kpi-sub"><b><?php echo $ov['active_users']; ?></b> active &nbsp;·&nbsp; <b><?php echo $ov['inactive_users']; ?></b> inactive</div>
                </div>
                <div class="kpi-card kpi-violet">
                    <div class="kpi-icon"><i class="fa-solid fa-building"></i></div>
                    <div class="kpi-label">Companies</div>
                    <div class="kpi-value"><?php echo $ov['total_companies']; ?></div>
                    <div class="kpi-sub"><b><?php echo $ov['total_contacts']; ?></b> total contacts</div>
                </div>
                <div class="kpi-card kpi-green">
                    <div class="kpi-icon"><i class="fa-solid fa-sack-dollar"></i></div>
                    <div class="kpi-label">Total Deal Value</div>
                    <!--  ovFmt() converts the raw number to K/M shorthand -->
                    <div class="kpi-value kpi-value-sm"><?php echo ovFmt($ov['deal_value']); ?></div>
                    <div class="kpi-sub"><b><?php echo $ov['total_deals']; ?></b> deals &nbsp;·&nbsp; <b><?php echo $ov['deals_won']; ?></b> won</div>
                </div>
                <div class="kpi-card kpi-amber">
                    <div class="kpi-icon"><i class="fa-solid fa-list-check"></i></div>
                    <div class="kpi-label">Tasks</div>
                    <div class="kpi-value"><?php echo $ov['total_tasks']; ?></div>
                    <!--  Overdue count is highlighted in red to draw attention -->
                    <div class="kpi-sub"><b style="color:#ef4444;"><?php echo $ov['tasks_overdue']; ?></b> overdue &nbsp;·&nbsp; <b><?php echo $ov['tasks_progress']; ?></b> in progress</div>
                </div>
                <div class="kpi-card kpi-rose">
                    <div class="kpi-icon"><i class="fa-solid fa-bullhorn"></i></div>
                    <div class="kpi-label">Campaigns</div>
                    <div class="kpi-value"><?php echo $ov['total_campaigns']; ?></div>
                    <div class="kpi-sub"><b><?php echo $ov['camp_active']; ?></b> active &nbsp;·&nbsp; <b><?php echo $ov['camp_planning']; ?></b> planning</div>
                </div>
                <div class="kpi-card kpi-cyan">
                    <div class="kpi-icon"><i class="fa-solid fa-address-book"></i></div>
                    <div class="kpi-label">Clients</div>
                    <div class="kpi-value"><?php echo $ov['total_contacts']; ?></div>
                    <div class="kpi-sub">across <b><?php echo $ov['total_companies']; ?></b> companies</div>
                </div>
            </div>
            <!--  Middle row: Deal Pipeline Funnel | Task Status Donut | Team Breakdown -->
            <div class="ov-mid-row">
                <!--  Panel 1: Deal Pipeline — horizontal bar chart grouped by stage -->
                <div class="ov-panel">
                    <div class="ov-panel-title"><i class="fa-solid fa-filter"></i> Deal Pipeline — Stage Breakdown</div>
                    <?php
                    //  Define each pipeline stage with its color and initial count of 0
                    $stages=['Lead'=>['#60a5fa',0],'Proposal'=>['#a78bfa',0],'Negotiation'=>['#f97316',0],'Won'=>['#34d399',0],'Lost'=>['#f87171',0]];

                    //  Query deal counts grouped by stage — filtered to this admin's deals
                    if(isset($conn)){$sq=mysqli_query($conn,"SELECT stage,COUNT(*) c FROM deals WHERE sales_officer IN ('$teamList') GROUP BY stage");if($sq)while($sr=mysqli_fetch_assoc($sq))if(isset($stages[$sr['stage']]))$stages[$sr['stage']][1]=(int)$sr['c'];}

                    //  Find the highest stage count — used to calculate each bar's percentage width
                    $mx=max(1,max(array_column($stages,1)));

                    //  Render one bar row per stage
                    foreach($stages as $lbl=>[$col,$cnt]):$pct=round($cnt/$mx*100); // percentage relative to max
                    ?>
                    <div class="funnel-row">
                        <div class="funnel-label"><?php echo $lbl; ?></div>
                        <!--  Bar width and color are set dynamically via inline styles -->
                        <div class="funnel-bar-wrap"><div class="funnel-bar" style="width:<?php echo $pct; ?>%;background:<?php echo $col; ?>;"></div></div>
                        <div class="funnel-count"><?php echo $cnt; ?></div>
                    </div>
                    <?php endforeach; ?>
                    <!--  Total pipeline value displayed at the bottom of the panel -->
                    <div class="funnel-deal-total">
                        <span>Total pipeline value</span>
                        <strong><?php echo ovFmt($ov['deal_value']); ?></strong>
                    </div>
                </div>
                <!--  Panel 2: Task Status Donut Chart — SVG-based circular chart built in PHP -->
                <div class="ov-panel">
                    <div class="ov-panel-title"><i class="fa-solid fa-chart-pie"></i> Task Status</div>
                    <?php
                    //  Use max(1,...) to avoid division by zero when there are no tasks
                    $tTot=max(1,$ov['total_tasks']); 
                    $r2=$ov['tasks_progress']; $r3=$ov['tasks_todo']; $r4t=$ov['tasks_done']; $r5t=$ov['tasks_overdue'];

                    //  SVG donut math: r=46 is the circle radius, circ is the full circumference (2πr)
                    $rv=46; $circ=2*M_PI*$rv;

                    //  Define each segment: [count, color]
                    //    Done=green, In-Progress=blue, To-Do=gray, Overdue=red
                    $segs=[[$r4t,'#34d399'],[$r2,'#60a5fa'],[$r3,'#d1d5db'],[$r5t,'#f87171']];

                    $off=0; $svgp='';
                    foreach($segs as [$sv,$sc]){
                        //  Calculate arc length for this segment proportional to total tasks
                        $frac=$sv/$tTot; $dash=$frac*$circ; $gap=$circ-$dash;
                        //  stroke-dasharray: dash=visible arc, gap=invisible remainder
                        //    stroke-dashoffset: negative offset to start after the previous segment
                        $svgp.="<circle class='donut-bg' cx='60' cy='60' r='46' stroke-width='12'/>";
                        $svgp.="<circle cx='60' cy='60' r='46' fill='none' stroke='{$sc}' stroke-width='12' stroke-dasharray='{$dash} {$gap}' stroke-dashoffset='-{$off}' stroke-linecap='round'/>";
                        $off+=$dash; //  Advance the offset for the next segment
                    }
                    ?>
                    <div class="task-ring-wrap">
                        <svg class="donut-svg" width="120" height="120" viewBox="0 0 120 120">
                            <?php echo $svgp; /*  Output the PHP-generated SVG circle elements */ ?>
                            <!--  Center label: total task count — the SVG is rotated -90deg so we counter-rotate the text group -->
                            <g transform="rotate(90,60,60)">
                                <text x="60" y="56" text-anchor="middle" font-size="20" font-weight="800" fill="#111827" font-family="DM Mono,monospace"><?php echo $ov['total_tasks']; ?></text>
                                <text x="60" y="70" text-anchor="middle" font-size="9"  font-weight="600" fill="#94a3b8">TASKS</text>
                            </g>
                        </svg>
                        <!--  Legend: color dot + status label + count for each segment -->
                        <div class="task-ring-legend" style="width:100%;">
                            <?php foreach([['Done','#34d399',$ov['tasks_done']],['In Progress','#60a5fa',$ov['tasks_progress']],['To-Do','#d1d5db',$ov['tasks_todo']],['Overdue','#f87171',$ov['tasks_overdue']]] as [$n,$c,$v]): ?>
                            <div class="trl-row">
                                <div class="trl-dot" style="background:<?php echo $c; ?>;"></div>
                                <div class="trl-name"><?php echo $n; ?></div>
                                <div class="trl-num"><?php echo $v; ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <!--  Panel 3: Team Breakdown — user count per role with colored avatars -->
                <div class="ov-panel">
                    <div class="ov-panel-title"><i class="fa-solid fa-user-group"></i> Team Breakdown</div>
                    <?php foreach([
                        //  Each entry: [display label, role key, bg color, icon color, FA icon class, count]
                        ['Managers','manager','#fef3c7','#f59e0b','fa-briefcase',$ov['managers']],
                        ['Agents','agent','#dcfce7','#10b981','fa-headset',$ov['agents']],
                    ] as [$lbl,$key,$bg,$col,$ico,$cnt]):
                    //  Short description for each role shown beneath the role name
                    $desc=['manager'=>'Team supervision','agent'=>'Client handling'];
                    ?>
                    <div class="ubl-row">
                        <!--  Colored icon avatar — background and icon color are set per role -->
                        <div class="ubl-avatar" style="background:<?php echo $bg; ?>;color:<?php echo $col; ?>;"><i class="fa-solid <?php echo $ico; ?>"></i></div>
                        <div class="ubl-info">
                            <div class="ubl-role"><?php echo $lbl; ?></div>
                            <div class="ubl-stat"><?php echo $desc[$key]; ?></div>
                        </div>
                        <!--  Large numeric count displayed on the right -->
                        <div class="ubl-count"><?php echo $cnt; ?></div>
                    </div>
                    <?php endforeach; ?>
                    <!--  Footer link — triggers JS showUserList() to navigate to the full user list -->
                    <div class="ov-panel-footer" style="margin-top:14px;"><a href="#" onclick="showUserList(this)">View all users →</a></div>
                </div>
            </div>
            <!--  Bottom row: Recent Deals | Recent Tasks | Recently Added Users -->
            <div class="ov-bottom-row">
                <!--  Panel 4: Recent Deals — last 5 deals from the DB -->
                <div class="ov-panel">
                    <div class="ov-panel-title"><i class="fa-solid fa-handshake"></i> Recent Deals</div>
                    <?php if(empty($ov['recent_deals'])): ?>
                        <!--  Empty state shown when no deals exist -->
                        <div class="ov-empty"><i class="fa-solid fa-inbox"></i>No deals available. Create a new deal.</div>
                    <?php else: ?>
                    <table class="mini-table">
                        <thead><tr><th>Deal Name</th><th>Value</th><th>Stage</th></tr></thead>
                        <tbody>
                        <?php foreach($ov['recent_deals'] as $d): ?>
                        <tr>
                            <!--  htmlspecialchars() prevents XSS by escaping any HTML in user-generated content -->
                            <td class="mini-deal-name" title="<?php echo htmlspecialchars($d['deal_name']); ?>"><?php echo htmlspecialchars($d['deal_name']); ?></td>
                            <!--  Currency + value — number_format adds comma separators (e.g. 10,000) -->
                            <td class="mini-td-amount"><?php echo htmlspecialchars($d['currency']); ?> <?php echo number_format((float)$d['deal_value'],0); ?></td>
                            <!--  Stage badge rendered by the ovStage() helper -->
                            <td><?php echo ovStage($d['stage']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                    <!--  Footer link — triggers JS showDealPipeline() to navigate to the deals section -->
                    <div class="ov-panel-footer"><a href="#" onclick="showDealPipeline(this)">All deals →</a></div>
                </div>
                <!--  Panel 5: Recent Tasks — last 4 tasks from the DB -->
                <div class="ov-panel">
                    <div class="ov-panel-title"><i class="fa-solid fa-clipboard-list"></i> Recent Tasks</div>
                    <?php if(empty($ov['recent_tasks'])): ?>
                        <div class="ov-empty"><i class="fa-solid fa-inbox"></i>No tasks found.</div>
                    <?php else: ?>
                    <table class="mini-table">
                        <thead><tr><th>Title</th><th>Priority</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach($ov['recent_tasks'] as $t): ?>
                        <tr>
                            <td class="mini-td-title" title="<?php echo htmlspecialchars($t['title']); ?>"><?php echo htmlspecialchars($t['title']); ?></td>
                            <!--  Priority and status badges rendered by their respective helper functions -->
                            <td><?php echo ovPrio($t['priority']); ?></td>
                            <td><?php echo ovTask($t['status']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                    <div class="ov-panel-footer"><a href="task_manager.php">All tasks →</a></div>
                </div>
                <!--  Panel 6: Recently Added Users — last 4 users added to the system -->
              <div class="ov-panel">
                    <div class="ov-panel-title"><i class="fa-solid fa-user-plus"></i> Recently Added Users</div>
                    <?php if(empty($ov['recent_users'])): ?>
                        <div class="ov-empty"><i class="fa-solid fa-inbox"></i>No users found.</div>
                    <?php else: ?>
                    <table class="mini-table">
                        <thead><tr><th>Name</th><th>Role</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach($ov['recent_users'] as $u): ?>
                        <tr>
                            <td class="mini-td-title"><?php echo htmlspecialchars($u['name']); ?></td>
                            <!--  Role badge rendered by ovRole() helper -->
                            <td><?php echo ovRole($u['role']); ?></td>
                            <!-- Inline colored dot: green for Active, red for Inactive -->
                            <td><?php echo strtolower($u['status'])==='active' ? "<span style='color:#10b981;font-size:11px;font-weight:700;'>● Active</span>" : "<span style='color:#ef4444;font-size:11px;font-weight:700;'>● Inactive</span>"; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                    <!--  Footer link — triggers JS showUserList() to navigate to the user list section -->
                    <div class="ov-panel-footer"><a href="#" onclick="showUserList(this)">All users →</a></div>
                </div>
            </div>
        </div>
  </body>
</html>