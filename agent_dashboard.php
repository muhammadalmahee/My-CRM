<?php
session_start();
@include 'config.php';

// Role check: agent only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'agent') {
    header("Location: index.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agent Dashboard - Systellio CRM</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        @import url('https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&display=swap');

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }

        body {
            background-color: #f3f4f6;
            display: flex; height: 100vh; overflow: hidden;
            transition: background-color 0.3s, color 0.3s; color: #111827;
        }

        /* ── Toast ── */
        #toastBox { display: none; min-width: 250px; background: #333; color: #fff; text-align: center; border-radius: 8px; padding: 16px; position: fixed; z-index: 9999; right: 30px; top: 30px; font-size: 14px; font-weight: 600; box-shadow: 0 4px 12px rgba(0,0,0,.15); align-items: center; gap: 10px; transform: translateX(100%); transition: transform .4s cubic-bezier(.68,-.55,.265,1.55); }
        #toastBox.show { display: flex; transform: translateX(0); }
        #toastBox.success { background: #10b981; }
        #toastBox.error   { background: #ef4444; }

        /* ── Layout ── */
        .main-content { flex-grow: 1; display: flex; flex-direction: column; overflow-y: auto; background-color: #f3f4f6; transition: background-color .3s; }

        /* ── Notification panel ── */
        .notif-wrapper { position: relative; overflow: visible !important; }
        .notif-panel { display: none; position: fixed; top: 70px; right: 20px; width: 340px; background: #fff; border-radius: 12px; box-shadow: 0 8px 30px rgba(0,0,0,.18); border: 1px solid #e5e7eb; z-index: 9999; overflow: hidden; }
        .notif-panel.open { display: block; }
        .notif-panel-header { padding: 16px 20px; border-bottom: 1px solid #f3f4f6; display: flex; justify-content: space-between; align-items: center; }
        .notif-panel-header h3 { font-size: 15px; font-weight: 700; color: #111827; }
        .notif-panel-header span { font-size: 11px; color: #6b7280; cursor: pointer; font-weight: 600; }
        .notif-panel-header span:hover { color: #3b82f6; }
        .notif-list { max-height: 360px; overflow-y: auto; }
        .notif-item { display: flex; gap: 14px; padding: 14px 20px; border-bottom: 1px solid #f9fafb; cursor: pointer; transition: background .2s; }
        .notif-item:hover { background: #f9fafb; }
        .notif-item:last-child { border-bottom: none; }
        .notif-icon { width: 38px; height: 38px; border-radius: 10px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 15px; }
        .notif-body { flex: 1; }
        .notif-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #9ca3af; margin-bottom: 2px; }
        .notif-text { font-size: 13px; font-weight: 500; color: #111827; margin-bottom: 3px; line-height: 1.4; }
        .notif-time { font-size: 11px; color: #9ca3af; font-weight: 500; }
        .notif-empty { padding: 30px 20px; text-align: center; color: #9ca3af; font-size: 13px; }
        .notif-panel-footer { padding: 12px 20px; border-top: 1px solid #f3f4f6; text-align: center; }
        .notif-panel-footer a { font-size: 12px; font-weight: 600; color: #3b82f6; text-decoration: none; }
        .notification-badge { position: absolute; top: -4px; right: -4px; background: #ef4444; color: #fff; font-size: 9px; font-weight: bold; padding: 2px 5px; border-radius: 50%; border: 2px solid #fff; }

        /* ── Dashboard content wrapper ── */
        #mainDashboardContent { padding: 28px 30px 36px; }

        /* ── Agent welcome banner ── */
        .agent-banner {
            background: linear-gradient(135deg, #064e3b 0%, #065f46 45%, #059669 100%);
            border-radius: 16px; padding: 24px 30px;
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 22px; overflow: hidden; position: relative;
            gap: 20px;
        }
        .agent-banner::before {
            content: '';
            position: absolute; left: -60px; bottom: -60px;
            width: 220px; height: 220px; border-radius: 50%;
            background: rgba(255,255,255,.06);
        }
        .agent-banner::after {
            content: '';
            position: absolute; right: 80px; top: -50px;
            width: 160px; height: 160px; border-radius: 50%;
            background: rgba(255,255,255,.04);
        }
        .banner-left { z-index: 1; flex-shrink: 0; }
        .banner-left h2 { font-size: 19px; font-weight: 800; color: #fff; margin-bottom: 4px; }
        .banner-left p  { font-size: 12px; color: #a7f3d0; font-weight: 500; }
        .banner-right { display: flex; gap: 8px; z-index: 1; flex-wrap: nowrap; align-items: center; }
        .banner-stat { background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.18); border-radius: 12px; padding: 10px 14px; text-align: center; backdrop-filter: blur(4px); flex-shrink: 0; }
        .banner-stat-val { font-size: 22px; font-weight: 800; color: #fff; font-family: 'DM Mono', monospace; line-height: 1; }
        .banner-stat-lbl { font-size: 9px; color: #a7f3d0; font-weight: 700; text-transform: uppercase; letter-spacing: .7px; margin-top: 4px; white-space: nowrap; }

        /* ── Page heading ── */
        .ov-heading { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 20px; }
        .ov-heading-left h1 { font-size: 21px; font-weight: 800; color: #0f172a; letter-spacing: -.4px; margin-bottom: 2px; }
        .ov-heading-left p  { font-size: 12px; color: #64748b; font-weight: 500; }
        .ov-date-badge { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 7px 14px; font-size: 12px; font-weight: 600; color: #6b7280; display: flex; align-items: center; gap: 6px; }

        /* ── KPI strip ── */
        .kpi-strip { display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px; margin-bottom: 20px; }

        .kpi-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 14px 16px;
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 12px;
            position: relative;
            overflow: hidden;
            transition: box-shadow .2s, transform .2s;
        }
        .kpi-card:hover { box-shadow: 0 6px 20px rgba(0,0,0,.07); transform: translateY(-2px); }
        .kpi-card::before { content: ''; position: absolute; top: 0; left: 0; bottom: 0; width: 3px; border-radius: 12px 0 0 12px; }
        .kpi-blue::before   { background: linear-gradient(180deg,#3b82f6,#6366f1); }
        .kpi-green::before  { background: linear-gradient(180deg,#10b981,#34d399); }
        .kpi-amber::before  { background: linear-gradient(180deg,#f59e0b,#fbbf24); }
        .kpi-rose::before   { background: linear-gradient(180deg,#f43f5e,#fb7185); }
        .kpi-cyan::before   { background: linear-gradient(180deg,#06b6d4,#22d3ee); }
        .kpi-violet::before { background: linear-gradient(180deg,#8b5cf6,#a78bfa); }

        .kpi-icon {
            width: 36px; height: 36px; border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px; flex-shrink: 0;
        }
        .kpi-blue   .kpi-icon { background: #eff6ff; color: #3b82f6; }
        .kpi-green  .kpi-icon { background: #f0fdf4; color: #10b981; }
        .kpi-amber  .kpi-icon { background: #fffbeb; color: #f59e0b; }
        .kpi-rose   .kpi-icon { background: #fff1f2; color: #f43f5e; }
        .kpi-cyan   .kpi-icon { background: #ecfeff; color: #06b6d4; }
        .kpi-violet .kpi-icon { background: #f5f3ff; color: #8b5cf6; }

        .kpi-body { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
        .kpi-label { font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .7px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .kpi-value { font-size: 22px; font-weight: 800; color: #0f172a; font-family: 'DM Mono', monospace; line-height: 1.1; }
        .kpi-value-sm { font-size: 16px; }
        .kpi-sub { font-size: 10px; color: #94a3b8; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .kpi-sub b { color: #374151; }

        /* ── Mid & bottom rows ── */
        .ov-mid-row    { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 16px; }
        .ov-bottom-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }

        .ov-panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 20px 22px; display: flex; flex-direction: column; min-height: 260px; }
        .ov-panel-title { font-size: 13px; font-weight: 700; color: #374151; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
        .ov-panel-title i { color: #10b981; }

        /* ── Deal pipeline funnel ── */
        .funnel-row { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .funnel-label { font-size: 11px; font-weight: 600; color: #6b7280; width: 90px; flex-shrink: 0; }
        .funnel-bar-wrap { flex: 1; height: 8px; background: #f1f5f9; border-radius: 99px; overflow: hidden; }
        .funnel-bar { height: 100%; border-radius: 99px; transition: width .6s ease; }
        .funnel-count { font-size: 12px; font-weight: 700; color: #374151; width: 24px; text-align: right; flex-shrink: 0; font-family: 'DM Mono', monospace; }
        .funnel-deal-total { display: flex; justify-content: space-between; align-items: center; margin-top: 14px; padding-top: 14px; border-top: 1px solid #f1f5f9; font-size: 12px; }
        .funnel-deal-total span   { color: #94a3b8; font-weight: 500; }
        .funnel-deal-total strong { color: #0f172a; font-weight: 700; font-family: 'DM Mono', monospace; }

        /* ── Task donut ── */
        .task-ring-wrap { display: flex; flex-direction: column; align-items: center; gap: 14px; }
        .donut-svg { transform: rotate(-90deg); }
        .donut-bg  { fill: none; stroke: #f1f5f9; }
        .task-ring-legend { display: flex; flex-direction: column; gap: 8px; width: 100%; }
        .trl-row { display: flex; align-items: center; gap: 8px; padding-bottom: 8px; border-bottom: 1px solid #f8fafc; }
        .trl-row:last-child { border-bottom: none; padding-bottom: 0; }
        .trl-dot  { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
        .trl-name { font-size: 12px; font-weight: 600; color: #374151; flex: 1; }
        .trl-num  { font-size: 12px; font-weight: 700; color: #6b7280; font-family: 'DM Mono', monospace; }

        /* ── Contact list inside panel ── */
        .contact-list { flex: 1; overflow: hidden; }
        .contact-row { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid #f8fafc; min-width: 0; }
        .contact-row:last-of-type { border-bottom: none; }
        .contact-avatar { width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg,#10b981,#059669); color: #fff; font-size: 12px; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .contact-info { flex: 1; min-width: 0; }
        .contact-name { font-size: 12px; font-weight: 700; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .contact-desig { font-size: 11px; color: #94a3b8; font-weight: 500; margin-top: 1px; }
        .contact-badge { font-size: 10px; font-weight: 700; color: #059669; background: #f0fdf4; padding: 2px 8px; border-radius: 20px; flex-shrink: 0; }

        /* ── Mini tables ── */
        .mini-table { width: 100%; border-collapse: collapse; font-size: 12px; table-layout: fixed; }
        .mini-table th { background: #f8fafc; padding: 8px 10px; font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .5px; text-align: left; border-bottom: 1px solid #f1f5f9; }
        .mini-table td { padding: 10px; border-bottom: 1px solid #f8fafc; color: #374151; font-weight: 500; vertical-align: middle; }
        .mini-table tr:last-child td { border-bottom: none; }
        .mini-deal-name { font-weight: 700; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; width: 40%; }
        .mini-td-title  { font-weight: 600; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; width: 45%; }
        .mini-td-amount { font-family: 'DM Mono', monospace; font-weight: 700; color: #059669; white-space: nowrap; width: 30%; }
        .mini-table th:nth-child(1) { width: 42%; }
        .mini-table th:nth-child(2) { width: 28%; }
        .mini-table th:nth-child(3) { width: 30%; }

        .ov-panel-footer { margin-top: auto; padding-top: 10px; border-top: 1px solid #f1f5f9; text-align: center; flex-shrink: 0; }
        .ov-panel-footer a { font-size: 11px; font-weight: 700; color: #10b981; text-decoration: none; }
        .ov-panel-footer a:hover { text-decoration: underline; }
        .ov-empty { text-align: center; padding: 24px 10px; color: #cbd5e1; font-size: 12px; }
        .ov-empty i { font-size: 28px; display: block; margin-bottom: 8px; }

        /* ── Campaign status rows ── */
        .camp-list { flex: 1; overflow: hidden; }
        .camp-row { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid #f8fafc; min-width: 0; }
        .camp-row:last-of-type { border-bottom: none; }
        .camp-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
        .camp-info { flex: 1; min-width: 0; overflow: hidden; }
        .camp-name { font-size: 12px; font-weight: 700; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .camp-type { font-size: 11px; color: #94a3b8; margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .camp-status { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 20px; flex-shrink: 0; white-space: nowrap; }

        /* ── Dark mode ── */
        body.dark-mode { background-color: #0f172a; color: #f8fafc; }
        body.dark-mode .main-content { background-color: #0f172a; }
        body.dark-mode #mainDashboardContent { background-color: #0f172a; }
        body.dark-mode .ov-heading-left h1 { color: #f8fafc; }
        body.dark-mode .ov-heading-left p  { color: #94a3b8; }
        body.dark-mode .ov-date-badge { background: #1e293b; border-color: #334155; color: #94a3b8; }
        body.dark-mode .kpi-card { background: #1e293b; border-color: #334155; }
        body.dark-mode .kpi-value { color: #f8fafc; }
        body.dark-mode .kpi-label { color: #64748b; }
        body.dark-mode .kpi-sub   { color: #94a3b8; }
        body.dark-mode .kpi-sub b { color: #e2e8f0; }
        body.dark-mode .kpi-blue   .kpi-icon { background: #1e3a5f; }
        body.dark-mode .kpi-green  .kpi-icon { background: #052e16; }
        body.dark-mode .kpi-amber  .kpi-icon { background: #2d1a00; }
        body.dark-mode .kpi-rose   .kpi-icon { background: #2d0a16; }
        body.dark-mode .kpi-cyan   .kpi-icon { background: #082f49; }
        body.dark-mode .kpi-violet .kpi-icon { background: #2e1065; }
        body.dark-mode .ov-panel { background: #1e293b; border-color: #334155; }
        body.dark-mode .ov-panel-title { color: #f8fafc; }
        body.dark-mode .ov-panel-footer { border-color: #334155; }
        body.dark-mode .ov-panel-footer a { color: #34d399; }
        body.dark-mode .ov-empty { color: #475569; }
        body.dark-mode .funnel-label  { color: #94a3b8; }
        body.dark-mode .funnel-count  { color: #94a3b8; }
        body.dark-mode .funnel-bar-wrap { background: #0f172a; }
        body.dark-mode .funnel-deal-total { border-color: #334155; }
        body.dark-mode .funnel-deal-total span   { color: #64748b; }
        body.dark-mode .funnel-deal-total strong { color: #f8fafc; }
        body.dark-mode .trl-row  { border-color: #1e293b; }
        body.dark-mode .trl-name { color: #e2e8f0; }
        body.dark-mode .trl-num  { color: #94a3b8; }
        body.dark-mode .contact-list { overflow: hidden; }
        body.dark-mode .camp-list    { overflow: hidden; }
        body.dark-mode .contact-row  { border-color: #1e293b; }
        body.dark-mode .contact-name { color: #f8fafc; }
        body.dark-mode .contact-desig { color: #64748b; }
        body.dark-mode .contact-badge { background: #052e16; color: #34d399; }
        body.dark-mode .camp-row  { border-color: #1e293b; }
        body.dark-mode .camp-name { color: #f8fafc; }
        body.dark-mode .camp-type { color: #64748b; }
        body.dark-mode .mini-table th { background: #0f172a; color: #64748b; border-color: #1e293b; }
        body.dark-mode .mini-table td { border-color: #1e293b; color: #94a3b8; }
        body.dark-mode .mini-deal-name { color: #f8fafc; }
        body.dark-mode .mini-td-title  { color: #f8fafc; }
        body.dark-mode .mini-td-amount { color: #34d399; }
        body.dark-mode .notif-panel { background: #1e293b; border-color: #334155; box-shadow: 0 8px 30px rgba(0,0,0,.4); }
        body.dark-mode .notif-panel-header { border-color: #334155; }
        body.dark-mode .notif-panel-header h3 { color: #f8fafc; }
        body.dark-mode .notif-item { border-color: #1e293b; }
        body.dark-mode .notif-item:hover { background: #0f172a; }
        body.dark-mode .notif-text { color: #e2e8f0; }
        body.dark-mode .notif-panel-footer { border-color: #334155; }
        body.dark-mode .notification-badge { border-color: #1e293b; }

        /* ================================================================
           RESPONSIVE BREAKPOINTS — Full Mobile Support
        ================================================================ */

        /* ── Tablet landscape: 1400px ── */
        @media (max-width: 1400px) {
            .kpi-strip { grid-template-columns: repeat(3, 1fr); }
        }

        /* ── Tablet: 1100px ── */
        @media (max-width: 1100px) {
            .ov-mid-row, .ov-bottom-row { grid-template-columns: 1fr 1fr; }
        }

        /* ── Small tablet / large phone: 900px ── */
        @media (max-width: 900px) {
            body { overflow: auto; }
            .main-content { overflow-y: auto; }
            .kpi-strip { grid-template-columns: repeat(2, 1fr); }
            .ov-mid-row, .ov-bottom-row { grid-template-columns: 1fr; }
            .banner-right { display: none; }
            #mainDashboardContent { padding: 20px 18px 30px; }
        }

        /* ── Mobile: 640px ── */
        @media (max-width: 640px) {
            /* Body & layout */
            body { display: block; height: auto; overflow-x: hidden; }
            .main-content { min-height: 100vh; overflow-y: visible; }
            #mainDashboardContent { padding: 16px 14px 40px; }

            /* Sidebar — hidden by default on mobile, toggled via hamburger */
            .sidebar { position: fixed; top: 0; left: 0; height: 100vh; z-index: 1100;
                       transform: translateX(-100%); transition: transform 0.3s ease; margin-left: 0 !important; }
            .sidebar.mobile-open { transform: translateX(0); }
            .sidebar.collapsed  { transform: translateX(-100%); }

            /* Overlay behind open sidebar */
            #sidebarOverlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45);
                              z-index: 1090; }
            #sidebarOverlay.show { display: block; }

            /* Banner */
            .agent-banner { flex-direction: column; align-items: flex-start;
                            padding: 20px 18px; gap: 14px; }
            .agent-banner .banner-right { display: flex; flex-wrap: wrap; gap: 8px; width: 100%; }
            .banner-stat { flex: 1 1 calc(33% - 8px); min-width: 72px; padding: 8px 10px; }
            .banner-stat-val { font-size: 18px; }
            .banner-left h2 { font-size: 16px; }
            .banner-left p  { font-size: 11px; }

            /* Heading */
            .ov-heading { flex-direction: column; align-items: flex-start; gap: 10px; margin-bottom: 16px; }
            .ov-heading-left h1 { font-size: 18px; }
            .ov-heading-left p  { font-size: 11px; }
            .ov-date-badge { font-size: 11px; padding: 6px 12px; }

            /* KPI — 2 columns on mobile */
            .kpi-strip { grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 16px; }
            .kpi-card  { padding: 12px 12px; gap: 10px; }
            .kpi-icon  { width: 32px; height: 32px; font-size: 13px; border-radius: 8px; }
            .kpi-value { font-size: 20px; }
            .kpi-value-sm { font-size: 14px; }
            .kpi-label { font-size: 9px; }
            .kpi-sub   { font-size: 9px; }

            /* Panels — single column */
            .ov-mid-row, .ov-bottom-row { grid-template-columns: 1fr; gap: 12px; margin-bottom: 12px; }
            .ov-panel { padding: 16px 16px; min-height: auto; }
            .ov-panel-title { font-size: 12px; margin-bottom: 12px; }

            /* Funnel / pipeline */
            .funnel-label { width: 78px; font-size: 10px; }

            /* Task donut — horizontal layout on mobile */
            .task-ring-wrap { flex-direction: row; align-items: center; gap: 16px; }
            .task-ring-legend { flex: 1; }

            /* Mini tables — hide last column on very small screens */
            .mini-table th, .mini-table td { padding: 8px 8px; font-size: 11px; }
            .mini-table th:last-child, .mini-table td:last-child { display: none; }
            .mini-table th:nth-child(1), .mini-table td:nth-child(1) { width: 55%; }
            .mini-table th:nth-child(2), .mini-table td:nth-child(2) { width: 45%; }

            /* Notification panel full-width on mobile */
            .notif-panel { width: calc(100vw - 24px); right: 12px; }

            /* Toast positioning */
            #toastBox { right: 12px; top: 12px; min-width: 0; width: calc(100vw - 24px); font-size: 13px; }
        }

        /* ── Very small phone: 380px ── */
        @media (max-width: 380px) {
            #mainDashboardContent { padding: 12px 10px 32px; }
            .kpi-strip { grid-template-columns: 1fr 1fr; gap: 8px; }
            .kpi-card  { padding: 10px 10px; }
            .kpi-value { font-size: 18px; }
            .banner-stat { flex: 1 1 calc(50% - 8px); }
            .task-ring-wrap { flex-direction: column; }
            .ov-panel { padding: 14px 12px; }
        }
    </style>
</head>
<body>

<!-- Mobile sidebar overlay -->
<div id="sidebarOverlay" onclick="closeMobileSidebar()"></div>

<div id="toastBox">
    <i id="toastIcon" class="fa-solid fa-circle-check"></i>
    <span id="toastMsg">Action Successful!</span>
</div>

<?php
$sidebarRole   = 'Agent';
$dashboardFile = 'agent_dashboard.php';
$activePage    = 'dashboard';
include 'sidebar.php';
?>

<div class="main-content">

    <?php include 'topbar.php'; ?>

    <?php
    // ── Agent info from session ──────────────────────────────────
    $agt     = $_SESSION['username'] ?? '';
    $agtName = $_SESSION['name']     ?? 'Agent';
    $agtSafe = isset($conn) ? mysqli_real_escape_string($conn, $agt) : $agt;
    $agtNameSafe = isset($conn) ? mysqli_real_escape_string($conn, $agtName) : $agtName;

    // ── Default values ───────────────────────────────────────────
    $ov = [
        'total_tasks'    => 0,
        'tasks_todo'     => 0,
        'tasks_progress' => 0,
        'tasks_done'     => 0,
        'tasks_overdue'  => 0,
        'total_contacts' => 0,
        'total_deals'    => 0,
        'deal_value'     => 0,
        'deals_won'      => 0,
        'total_campaigns'=> 0,
        'camp_active'    => 0,
        'camp_planning'  => 0,
        'total_companies'=> 0,
        'total_clients'  => 0,
        'recent_deals'   => [],
        'recent_tasks'   => [],
        'my_contacts'    => [],
        'my_campaigns'   => [],
    ];

    if (isset($conn)) {

        // 1. Tasks assigned TO this agent (match username OR name)
        $r1 = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) t,
                    SUM(status='To-Do') td,
                    SUM(status='In-Progress') ip,
                    SUM(status='Done') d,
                    SUM(due_date < CURDATE() AND status != 'Done') ov
             FROM tasks WHERE assigned_to = '$agtSafe' OR assigned_to = '$agtNameSafe'"
        ));
        if ($r1) {
            $ov['total_tasks']    = (int)$r1['t'];
            $ov['tasks_todo']     = (int)$r1['td'];
            $ov['tasks_progress'] = (int)$r1['ip'];
            $ov['tasks_done']     = (int)$r1['d'];
            $ov['tasks_overdue']  = (int)$r1['ov'];
        }

        // 2. Contacts assigned to this agent (via assigned_agents CSV field)
        $r2 = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) c FROM contacts
             WHERE FIND_IN_SET('$agtSafe', assigned_agents)
                OR FIND_IN_SET('$agtNameSafe', assigned_agents)"
        ));
        if ($r2) $ov['total_contacts'] = (int)$r2['c'];

        // 3. Deals — direct (sales_officer) + linked via agent's campaigns (deal_id)
        $r3 = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) t,
                    COALESCE(SUM(deal_value), 0) v,
                    SUM(stage='Won') w
             FROM deals
             WHERE sales_officer = '$agtSafe'
                OR sales_officer = '$agtNameSafe'
                OR id IN (
                    SELECT deal_id FROM campaigns
                    WHERE deal_id IS NOT NULL
                      AND (assigned_to = '$agtSafe' OR assigned_to = '$agtNameSafe')
                )"
        ));
        if ($r3) {
            $ov['total_deals'] = (int)$r3['t'];
            $ov['deal_value']  = (float)$r3['v'];
            $ov['deals_won']   = (int)$r3['w'];
        }

        // 4. Campaigns assigned to this agent (match username OR name)
        $r4 = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) t, SUM(status='Active') a, SUM(status='Planning') p
             FROM campaigns WHERE assigned_to = '$agtSafe' OR assigned_to = '$agtNameSafe'"
        ));
        if ($r4) {
            $ov['total_campaigns'] = (int)$r4['t'];
            $ov['camp_active']     = (int)$r4['a'];
            $ov['camp_planning']   = (int)$r4['p'];
        }

        // 5. Companies assigned to this agent (match username OR name)
        $r5 = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) c FROM companies
             WHERE assigned_agent = '$agtSafe' OR assigned_agent = '$agtNameSafe'"
        ));
        if ($r5) $ov['total_companies'] = (int)$r5['c'];

        // 6. Clients (contacts) belonging to agent-assigned companies
        $r6 = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) c FROM contacts
             WHERE company_id IN (
                 SELECT id FROM companies
                 WHERE assigned_agent = '$agtSafe' OR assigned_agent = '$agtNameSafe'
             )
             OR FIND_IN_SET('$agtSafe', assigned_agents)
             OR FIND_IN_SET('$agtNameSafe', assigned_agents)"
        ));
        if ($r6) $ov['total_clients'] = (int)$r6['c'];

        // 7. Recent deals (direct + campaign-linked)
        $dq = mysqli_query($conn,
            "SELECT deal_name, deal_value, currency, stage
             FROM deals
             WHERE sales_officer='$agtSafe'
                OR sales_officer='$agtNameSafe'
                OR id IN (
                    SELECT deal_id FROM campaigns
                    WHERE deal_id IS NOT NULL
                      AND (assigned_to = '$agtSafe' OR assigned_to = '$agtNameSafe')
                )
             ORDER BY id DESC LIMIT 5"
        );
        if ($dq) while ($row = mysqli_fetch_assoc($dq)) $ov['recent_deals'][] = $row;

        // 8. Recent tasks assigned to this agent
        $tq = mysqli_query($conn,
            "SELECT title, status, priority, due_date
             FROM tasks WHERE assigned_to='$agtSafe' OR assigned_to='$agtNameSafe'
             ORDER BY id DESC LIMIT 4"
        );
        if ($tq) while ($row = mysqli_fetch_assoc($tq)) $ov['recent_tasks'][] = $row;

        // 7. My assigned contacts (with company name)
        $cq = mysqli_query($conn,
            "SELECT c.name, c.designation, co.company_name
             FROM contacts c
             LEFT JOIN companies co ON c.company_id = co.id
             WHERE FIND_IN_SET('$agtSafe', c.assigned_agents)
                OR FIND_IN_SET('$agtNameSafe', c.assigned_agents)
             ORDER BY c.id DESC LIMIT 5"
        );
        if ($cq) while ($row = mysqli_fetch_assoc($cq)) $ov['my_contacts'][] = $row;

        // 8. My campaigns (recent 4)
        $campq = mysqli_query($conn,
            "SELECT campaign_name, campaign_type, status
             FROM campaigns WHERE assigned_to='$agtSafe' OR assigned_to='$agtNameSafe'
             ORDER BY id DESC LIMIT 4"
        );
        if ($campq) while ($row = mysqli_fetch_assoc($campq)) $ov['my_campaigns'][] = $row;
    }

    // ── Helper functions ─────────────────────────────────────────
    function ovFmt($v, $c = 'USD') {
        if ($v >= 1000000) return $c . ' ' . number_format($v / 1000000, 1) . 'M';
        if ($v >= 1000)    return $c . ' ' . number_format($v / 1000, 1) . 'K';
        return $c . ' ' . number_format($v, 0);
    }
    function ovStage($s) {
        $m = ['Lead'=>['#dbeafe','#1d4ed8'],'Proposal'=>['#fef9c3','#a16207'],'Negotiation'=>['#fff7ed','#c2410c'],'Won'=>['#dcfce7','#15803d'],'Lost'=>['#fee2e2','#b91c1c']];
        $c = $m[$s] ?? ['#f3f4f6','#374151'];
        return "<span style='background:{$c[0]};color:{$c[1]};padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;'>$s</span>";
    }
    function ovTask($s) {
        $m = ['To-Do'=>['#f3f4f6','#6b7280'],'In-Progress'=>['#dbeafe','#1d4ed8'],'Done'=>['#dcfce7','#15803d']];
        $c = $m[$s] ?? ['#f3f4f6','#374151'];
        return "<span style='background:{$c[0]};color:{$c[1]};padding:3px 10px;border-radius:20px;font-size:10px;font-weight:700;'>$s</span>";
    }
    function ovPrio($p) {
        $m = ['High'=>['#fee2e2','#b91c1c'],'Medium'=>['#fef3c7','#b45309'],'Low'=>['#dcfce7','#15803d']];
        $c = $m[$p] ?? ['#f3f4f6','#374151'];
        return "<span style='background:{$c[0]};color:{$c[1]};padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;'>$p</span>";
    }
    function campStatusBadge($s) {
        $m = ['Active'=>['#dcfce7','#15803d','#10b981'],'Planning'=>['#fef3c7','#a16207','#f59e0b'],'On Hold'=>['#fee2e2','#b91c1c','#ef4444'],'Completed'=>['#f0fdf4','#166534','#22c55e']];
        $c = $m[$s] ?? ['#f3f4f6','#374151','#6b7280'];
        return "<span class='camp-status' style='background:{$c[0]};color:{$c[1]};'>$s</span>";
    }
    function campDot($s) {
        $m = ['Active'=>'#10b981','Planning'=>'#f59e0b','On Hold'=>'#ef4444','Completed'=>'#22c55e'];
        return $m[$s] ?? '#94a3b8';
    }
    ?>

    <div id="mainDashboardContent">

        <!-- ── Agent welcome banner ── -->
        <div class="agent-banner">
            <div class="banner-left">
                <h2>Welcome, <?php echo htmlspecialchars($agtName); ?> 👋</h2>
                <p>Agent Dashboard — your personal work summary for today</p>
            </div>
            <div class="banner-right">
                <div class="banner-stat">
                    <div class="banner-stat-val"><?php echo $ov['total_companies']; ?></div>
                    <div class="banner-stat-lbl">Companies</div>
                </div>
                <div class="banner-stat">
                    <div class="banner-stat-val"><?php echo $ov['total_clients']; ?></div>
                    <div class="banner-stat-lbl">Clients</div>
                </div>
                <div class="banner-stat">
                    <div class="banner-stat-val"><?php echo $ov['total_tasks']; ?></div>
                    <div class="banner-stat-lbl">My Tasks</div>
                </div>
                <div class="banner-stat">
                    <div class="banner-stat-val"><?php echo $ov['total_contacts']; ?></div>
                    <div class="banner-stat-lbl">Contacts</div>
                </div>
                <div class="banner-stat">
                    <div class="banner-stat-val"><?php echo $ov['total_deals']; ?></div>
                    <div class="banner-stat-lbl">Deals</div>
                </div>
                <div class="banner-stat">
                    <div class="banner-stat-val"><?php echo $ov['total_campaigns']; ?></div>
                    <div class="banner-stat-lbl">Campaigns</div>
                </div>
            </div>
        </div>

        <!-- ── Heading ── -->
        <div class="ov-heading">
            <div class="ov-heading-left">
                <h1>My Overview</h1>
                <p>Showing only your assigned tasks, contacts, deals &amp; campaigns — <?php echo date('l, d F Y'); ?></p>
            </div>
            <div class="ov-date-badge">
                <i class="fa-regular fa-calendar"></i><?php echo date('D, d M Y'); ?>
            </div>
        </div>

        <!-- ── KPI Strip: 6 cards ── -->
        <div class="kpi-strip">
            <!-- My Companies -->
            <div class="kpi-card kpi-blue">
                <div class="kpi-icon"><i class="fa-solid fa-building"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Companies</div>
                    <div class="kpi-value"><?php echo $ov['total_companies']; ?></div>
                    <div class="kpi-sub">assigned to you</div>
                </div>
            </div>
            <!-- My Clients -->
            <div class="kpi-card kpi-rose">
                <div class="kpi-icon"><i class="fa-solid fa-user-tie"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Clients</div>
                    <div class="kpi-value"><?php echo $ov['total_clients']; ?></div>
                    <div class="kpi-sub">under your accounts</div>
                </div>
            </div>
            <!-- My Tasks -->
            <div class="kpi-card kpi-amber">
                <div class="kpi-icon"><i class="fa-solid fa-list-check"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Tasks</div>
                    <div class="kpi-value"><?php echo $ov['total_tasks']; ?></div>
                    <div class="kpi-sub">
                        <b style="color:#ef4444;"><?php echo $ov['tasks_overdue']; ?></b> overdue &nbsp;·&nbsp;
                        <b><?php echo $ov['tasks_progress']; ?></b> active
                    </div>
                </div>
            </div>
            <!-- My Contacts -->
            <div class="kpi-card kpi-cyan">
                <div class="kpi-icon"><i class="fa-solid fa-address-book"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Contacts</div>
                    <div class="kpi-value"><?php echo $ov['total_contacts']; ?></div>
                    <div class="kpi-sub">assigned to you</div>
                </div>
            </div>
            <!-- My Deals -->
            <div class="kpi-card kpi-green">
                <div class="kpi-icon"><i class="fa-solid fa-sack-dollar"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Deal Value</div>
                    <div class="kpi-value kpi-value-sm"><?php echo ovFmt($ov['deal_value']); ?></div>
                    <div class="kpi-sub">
                        <b><?php echo $ov['total_deals']; ?></b> deals &nbsp;·&nbsp;
                        <b><?php echo $ov['deals_won']; ?></b> won
                        <?php if ($ov['total_deals'] > 0): ?>
                        &nbsp;<span style="color:#10b981;" title="Includes deals linked via your campaigns">+campaigns</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <!-- My Campaigns -->
            <div class="kpi-card kpi-violet">
                <div class="kpi-icon"><i class="fa-solid fa-bullhorn"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Campaigns</div>
                    <div class="kpi-value"><?php echo $ov['total_campaigns']; ?></div>
                    <div class="kpi-sub">
                        <b><?php echo $ov['camp_active']; ?></b> active &nbsp;·&nbsp;
                        <b><?php echo $ov['camp_planning']; ?></b> planned
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Mid Row: Deal Pipeline | Task Donut | My Recent Deals ── -->
        <div class="ov-mid-row">

            <!-- Panel 1: Deal Pipeline -->
            <div class="ov-panel">
                <div class="ov-panel-title"><i class="fa-solid fa-filter"></i> My Deal Pipeline</div>
                <?php
                $stages = ['Lead'=>['#60a5fa',0],'Proposal'=>['#a78bfa',0],'Negotiation'=>['#f97316',0],'Won'=>['#34d399',0],'Lost'=>['#f87171',0]];
                if (isset($conn)) {
                    $sq = mysqli_query($conn,
                        "SELECT stage, COUNT(*) c FROM deals
                         WHERE sales_officer='$agtSafe'
                            OR sales_officer='$agtNameSafe'
                            OR id IN (
                                SELECT deal_id FROM campaigns
                                WHERE deal_id IS NOT NULL
                                  AND (assigned_to = '$agtSafe' OR assigned_to = '$agtNameSafe')
                            )
                         GROUP BY stage"
                    );
                    if ($sq) while ($sr = mysqli_fetch_assoc($sq)) if (isset($stages[$sr['stage']])) $stages[$sr['stage']][1] = (int)$sr['c'];
                }
                $mx = max(1, max(array_column($stages, 1)));
                foreach ($stages as $lbl => [$col, $cnt]):
                    $pct = round($cnt / $mx * 100);
                ?>
                <div class="funnel-row">
                    <div class="funnel-label"><?php echo $lbl; ?></div>
                    <div class="funnel-bar-wrap"><div class="funnel-bar" style="width:<?php echo $pct; ?>%;background:<?php echo $col; ?>;"></div></div>
                    <div class="funnel-count"><?php echo $cnt; ?></div>
                </div>
                <?php endforeach; ?>
                <div class="funnel-deal-total">
                    <span>My total pipeline</span>
                    <strong><?php echo ovFmt($ov['deal_value']); ?></strong>
                </div>
            </div>

            <!-- Panel 2: Task Status Donut -->
            <div class="ov-panel">
                <div class="ov-panel-title"><i class="fa-solid fa-chart-pie"></i> My Task Status</div>
                <?php
                $tTot = max(1, $ov['total_tasks']);
                $rv = 46; $circ = 2 * M_PI * $rv;
                $segs = [
                    [$ov['tasks_done'],     '#34d399'],
                    [$ov['tasks_progress'], '#60a5fa'],
                    [$ov['tasks_todo'],     '#d1d5db'],
                    [$ov['tasks_overdue'],  '#f87171'],
                ];
                $off = 0; $svgp = '';
                foreach ($segs as [$sv, $sc]) {
                    $frac = $sv / $tTot; $dash = $frac * $circ; $gap = $circ - $dash;
                    $svgp .= "<circle class='donut-bg' cx='60' cy='60' r='46' stroke-width='12'/>";
                    $svgp .= "<circle cx='60' cy='60' r='46' fill='none' stroke='{$sc}' stroke-width='12' stroke-dasharray='{$dash} {$gap}' stroke-dashoffset='-{$off}' stroke-linecap='round'/>";
                    $off += $dash;
                }
                ?>
                <div class="task-ring-wrap">
                    <svg class="donut-svg" width="120" height="120" viewBox="0 0 120 120">
                        <?php echo $svgp; ?>
                        <g transform="rotate(90,60,60)">
                            <text x="60" y="56" text-anchor="middle" font-size="20" font-weight="800" fill="#111827" font-family="DM Mono,monospace"><?php echo $ov['total_tasks']; ?></text>
                            <text x="60" y="70" text-anchor="middle" font-size="9" font-weight="600" fill="#94a3b8">TASKS</text>
                        </g>
                    </svg>
                    <div class="task-ring-legend">
                        <?php foreach ([
                            ['Done',        '#34d399', $ov['tasks_done']],
                            ['In Progress', '#60a5fa', $ov['tasks_progress']],
                            ['To-Do',       '#d1d5db', $ov['tasks_todo']],
                            ['Overdue',     '#f87171', $ov['tasks_overdue']],
                        ] as [$n, $c, $v]): ?>
                        <div class="trl-row">
                            <div class="trl-dot" style="background:<?php echo $c; ?>;"></div>
                            <div class="trl-name"><?php echo $n; ?></div>
                            <div class="trl-num"><?php echo $v; ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Panel 3: My Recent Deals -->
            <div class="ov-panel">
                <div class="ov-panel-title"><i class="fa-solid fa-handshake"></i> My Recent Deals</div>
                <div style="flex:1;overflow:hidden;">
                <?php if (empty($ov['recent_deals'])): ?>
                    <div class="ov-empty"><i class="fa-solid fa-inbox"></i>No deals assigned to you yet.</div>
                <?php else: ?>
                <table class="mini-table">
                    <thead><tr><th>Deal Name</th><th>Value</th><th>Stage</th></tr></thead>
                    <tbody>
                    <?php foreach ($ov['recent_deals'] as $d): ?>
                    <tr>
                        <td class="mini-deal-name" title="<?php echo htmlspecialchars($d['deal_name']); ?>"><?php echo htmlspecialchars($d['deal_name']); ?></td>
                        <td class="mini-td-amount"><?php echo htmlspecialchars($d['currency']); ?> <?php echo number_format((float)$d['deal_value'], 0); ?></td>
                        <td><?php echo ovStage($d['stage']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                </div>
                <div class="ov-panel-footer"><a href="deal_pipeline.php">All deals →</a></div>
            </div>

        </div>

        <!-- ── Bottom Row: My Contacts | Recent Tasks | My Campaigns ── -->
        <div class="ov-bottom-row">

            <!-- Panel 4: My Contacts -->
            <div class="ov-panel">
                <div class="ov-panel-title"><i class="fa-solid fa-users"></i> My Contacts</div>
                <div class="contact-list">
                <?php if (empty($ov['my_contacts'])): ?>
                    <div class="ov-empty"><i class="fa-solid fa-user-slash"></i>No contacts assigned to you yet.</div>
                <?php else: ?>
                    <?php foreach ($ov['my_contacts'] as $c):
                        $initials = strtoupper(substr($c['name'], 0, 1));
                    ?>
                    <div class="contact-row">
                        <div class="contact-avatar"><?php echo $initials; ?></div>
                        <div class="contact-info">
                            <div class="contact-name"><?php echo htmlspecialchars($c['name']); ?></div>
                            <div class="contact-desig"><?php echo htmlspecialchars($c['designation'] ?: ($c['company_name'] ?: '—')); ?></div>
                        </div>
                        <?php if (!empty($c['company_name'])): ?>
                        <div class="contact-badge"><?php echo htmlspecialchars(mb_strimwidth($c['company_name'], 0, 10, '…')); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                </div>
                <div class="ov-panel-footer"><a href="client_list.php">All contacts →</a></div>
            </div>

            <!-- Panel 5: My Recent Tasks -->
            <div class="ov-panel">
                <div class="ov-panel-title"><i class="fa-solid fa-clipboard-list"></i> My Recent Tasks</div>
                <div style="flex:1;overflow:hidden;">
                <?php if (empty($ov['recent_tasks'])): ?>
                    <div class="ov-empty"><i class="fa-solid fa-inbox"></i>No tasks assigned to you yet.</div>
                <?php else: ?>
                <table class="mini-table">
                    <thead><tr><th>Title</th><th>Priority</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($ov['recent_tasks'] as $t): ?>
                    <tr>
                        <td class="mini-td-title" title="<?php echo htmlspecialchars($t['title']); ?>"><?php echo htmlspecialchars($t['title']); ?></td>
                        <td><?php echo ovPrio($t['priority']); ?></td>
                        <td><?php echo ovTask($t['status']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                </div>
                <div class="ov-panel-footer"><a href="task_manager.php">All tasks →</a></div>
            </div>

            <!-- Panel 6: My Campaigns -->
            <div class="ov-panel">
                <div class="ov-panel-title"><i class="fa-solid fa-bullhorn"></i> My Campaigns</div>
                <div class="camp-list">
                <?php if (empty($ov['my_campaigns'])): ?>
                    <div class="ov-empty"><i class="fa-solid fa-inbox"></i>No campaigns assigned to you yet.</div>
                <?php else: ?>
                    <?php foreach ($ov['my_campaigns'] as $camp): ?>
                    <div class="camp-row">
                        <div class="camp-dot" style="background:<?php echo campDot($camp['status']); ?>;"></div>
                        <div class="camp-info">
                            <div class="camp-name"><?php echo htmlspecialchars($camp['campaign_name']); ?></div>
                            <div class="camp-type"><?php echo htmlspecialchars($camp['campaign_type']); ?></div>
                        </div>
                        <?php echo campStatusBadge($camp['status']); ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                </div>
                <div class="ov-panel-footer"><a href="campaigns.php">All campaigns →</a></div>
            </div>

        </div>
    </div><!-- /mainDashboardContent -->
</div><!-- /main-content -->

<script>
/* ── Toast ── */
function showToast(msg, type = 'success') {
    const box  = document.getElementById('toastBox');
    const icon = document.getElementById('toastIcon');
    const txt  = document.getElementById('toastMsg');
    box.className = 'show ' + type;
    icon.className = type === 'success' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark';
    txt.textContent = msg;
    setTimeout(() => { box.className = box.className.replace('show','').trim(); }, 3500);
}

/* ── Mobile sidebar: override topbar.php's toggle for mobile ── */
function openMobileSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (sidebar) sidebar.classList.add('mobile-open');
    if (overlay) overlay.classList.add('show');
    document.body.style.overflow = 'hidden';
}
function closeMobileSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (sidebar) sidebar.classList.remove('mobile-open');
    if (overlay) overlay.classList.remove('show');
    document.body.style.overflow = '';
}

document.addEventListener('DOMContentLoaded', function () {
    const toggle  = document.getElementById('outerToggle');
    const sidebar = document.getElementById('sidebar');

    if (toggle && sidebar) {
        /* Remove topbar.php's own listener by cloning the button */
        const newToggle = toggle.cloneNode(true);
        toggle.parentNode.replaceChild(newToggle, toggle);

        newToggle.addEventListener('click', function () {
            if (window.innerWidth <= 640) {
                /* Mobile: slide-in overlay mode */
                if (sidebar.classList.contains('mobile-open')) {
                    closeMobileSidebar();
                } else {
                    openMobileSidebar();
                }
            } else {
                /* Desktop: collapse/expand */
                sidebar.classList.toggle('collapsed');
            }
        });
    }

    /* Close sidebar on resize to desktop */
    window.addEventListener('resize', function () {
        if (window.innerWidth > 640) {
            closeMobileSidebar();
            document.body.style.overflow = '';
        }
    });

    /* Close sidebar on ESC key */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeMobileSidebar();
    });
});
</script>
</body>
</html>