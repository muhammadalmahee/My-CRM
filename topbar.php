<?php
/**
 * ============================================================
 * UNIFIED TOPBAR — Systellio CRM
 * ============================================================
 * সব পেজে এভাবে include করুন (sidebar.php include করার ঠিক পরে):
 *
 *   <?php include 'topbar.php'; ?>
 *
 * ⚙️  Optional — include এর আগে যেকোনো একটি সেট করতে পারেন:
 *
 *   $topbarTitle  = 'Dashboard';          // বাম পাশে page title দেখাতে চাইলে
 *   $topbarTitle  = '';                   // শুধু toggle button (default)
 *
 * ✅  এই file টা নিজেই:
 *     • CSS inject করে (একবার, define guard দিয়ে)
 *     • Toggle / Dark Mode / Notification Bell / Profile HTML render করে
 *     • JS attach করে (dark mode, hamburger)
 *     • notifications.php include করে (bell + badge)
 *
 * সব পেজে শুধু একটা line — পরিবর্তন দরকার হলে
 * শুধু topbar.php এডিট করলেই সব পেজে reflect করবে।
 * ============================================================
 */

if (!isset($topbarTitle)) $topbarTitle = '';

/* ── লগড-ইন ইউজারের তথ্য session থেকে ── */
$_tb_name = $_SESSION['name']  ?? 'User';
$_tb_role = $_SESSION['role']  ?? '';

/* Role display label */
$_tb_role_labels = [
    'super_admin' => 'Super Admin',
    'admin'       => 'Admin',
    'manager'     => 'Manager',
    'agent'       => 'Agent',
];
$_tb_role_display = $_tb_role_labels[$_tb_role] ?? ucfirst(str_replace('_', ' ', $_tb_role));

/* Avatar initials (প্রথম অক্ষর / দুটো অক্ষর) */
$_tb_parts    = explode(' ', trim($_tb_name));
$_tb_initials = strtoupper(substr($_tb_parts[0], 0, 1));
if (count($_tb_parts) > 1) {
    $_tb_initials .= strtoupper(substr($_tb_parts[count($_tb_parts) - 1], 0, 1));
}
?>

<?php /* ── CSS — একবারই inject হয় ── */ ?>
<?php if (!defined('TB_CSS_LOADED')): define('TB_CSS_LOADED', true); ?>
<style>
/* ================================================================
   SYSTELLIO TOPBAR — topbar.php
   (sidebar.php এর মতোই — কোনো page-specific CSS নেই)
   ================================================================ */

/* ── Topbar container ── */
.top-navbar {
    background-color: #ffffff;
    padding: 0 28px;
    height: 64px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 1px 0 #e5e7eb, 0 4px 12px rgba(0,0,0,0.04);
    position: sticky;
    top: 0;
    z-index: 900;
    flex-shrink: 0;
    transition: background-color 0.3s, box-shadow 0.3s;
}

/* ── Left side ── */
.tb-left {
    display: flex;
    align-items: center;
    gap: 16px;
}

/* Hamburger toggle */
.tb-toggle {
    width: 38px;
    height: 38px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    border-radius: 8px;
    color: #4b5563;
    font-size: 18px;
    transition: background 0.2s, color 0.2s;
    flex-shrink: 0;
}
.tb-toggle:hover {
    background: #f3f4f6;
    color: #111827;
}

/* Optional page title */
.tb-page-title {
    font-size: 15px;
    font-weight: 700;
    color: #111827;
    letter-spacing: -0.2px;
    white-space: nowrap;
    transition: color 0.3s;
}

/* ── Right side actions ── */
.tb-actions {
    display: flex;
    align-items: center;
    gap: 6px;
}

/* Generic icon button */
.tb-icon-btn {
    width: 38px;
    height: 38px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    border-radius: 8px;
    color: #6b7280;
    font-size: 18px;
    transition: background 0.2s, color 0.2s;
    flex-shrink: 0;
    border: none;
    background: transparent;
}
.tb-icon-btn:hover {
    background: #f3f4f6;
    color: #111827;
}

/* Dark mode icon active state */
.tb-icon-btn.tb-dark-active {
    color: #3b82f6;
    background: #eff6ff;
}

/* ── Divider ── */
.tb-divider {
    width: 1px;
    height: 24px;
    background: #e5e7eb;
    margin: 0 6px;
    flex-shrink: 0;
}

/* ── Profile chip ── */
.tb-profile {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 5px 10px 5px 6px;
    border-radius: 10px;
    cursor: pointer;
    transition: background 0.2s;
    position: relative;
}
.tb-profile:hover { background: #f3f4f6; }

/* Avatar circle */
.tb-avatar {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6, #6366f1);
    color: #ffffff;
    font-size: 12px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    letter-spacing: 0.5px;
    flex-shrink: 0;
    box-shadow: 0 2px 6px rgba(59,130,246,0.35);
}

/* Name & role text */
.tb-profile-info {
    display: flex;
    flex-direction: column;
    line-height: 1.2;
}
.tb-profile-name {
    font-size: 13px;
    font-weight: 700;
    color: #111827;
    white-space: nowrap;
    transition: color 0.3s;
}
.tb-profile-role {
    font-size: 10px;
    font-weight: 600;
    color: #9ca3af;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    transition: color 0.3s;
}

/* Profile dropdown */
.tb-profile-dropdown {
    display: none;
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    width: 200px;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.12);
    z-index: 9999;
    overflow: hidden;
    animation: tbFadeIn 0.18s ease;
}
.tb-profile-dropdown.tb-open { display: block; }

@keyframes tbFadeIn {
    from { opacity: 0; transform: translateY(-6px); }
    to   { opacity: 1; transform: translateY(0); }
}

.tb-dd-header {
    padding: 14px 16px 12px;
    border-bottom: 1px solid #f3f4f6;
}
.tb-dd-header-name {
    font-size: 13px;
    font-weight: 700;
    color: #111827;
}
.tb-dd-header-role {
    font-size: 11px;
    color: #9ca3af;
    font-weight: 500;
    margin-top: 2px;
}

.tb-dd-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 16px;
    font-size: 13px;
    font-weight: 500;
    color: #374151;
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
    text-decoration: none;
}
.tb-dd-item i {
    width: 16px;
    text-align: center;
    font-size: 13px;
    color: #9ca3af;
    transition: color 0.15s;
}
.tb-dd-item:hover { background: #f9fafb; color: #111827; }
.tb-dd-item:hover i { color: #3b82f6; }

.tb-dd-separator { height: 1px; background: #f3f4f6; margin: 4px 0; }

.tb-dd-item.tb-logout { color: #ef4444; }
.tb-dd-item.tb-logout i { color: #fca5a5; }
.tb-dd-item.tb-logout:hover { background: #fef2f2; color: #dc2626; }
.tb-dd-item.tb-logout:hover i { color: #ef4444; }

/* ── DARK MODE ── */
body.dark-mode .top-navbar {
    background-color: #0f172a;
    box-shadow: 0 1px 0 #1e293b, 0 4px 12px rgba(0,0,0,0.2);
}
body.dark-mode .tb-toggle { color: #94a3b8; }
body.dark-mode .tb-toggle:hover { background: #1e293b; color: #f8fafc; }
body.dark-mode .tb-page-title { color: #f8fafc; }
body.dark-mode .tb-icon-btn { color: #94a3b8; }
body.dark-mode .tb-icon-btn:hover { background: #1e293b; color: #f8fafc; }
body.dark-mode .tb-icon-btn.tb-dark-active { background: #1e3a8a22; color: #60a5fa; }
body.dark-mode .tb-divider { background: #1e293b; }
body.dark-mode .tb-profile:hover { background: #1e293b; }
body.dark-mode .tb-profile-name { color: #f8fafc; }
body.dark-mode .tb-profile-role { color: #64748b; }
body.dark-mode .tb-profile-dropdown {
    background: #1e293b;
    border-color: #334155;
    box-shadow: 0 8px 24px rgba(0,0,0,0.4);
}
body.dark-mode .tb-dd-header { border-color: #334155; }
body.dark-mode .tb-dd-header-name { color: #f8fafc; }
body.dark-mode .tb-dd-header-role { color: #64748b; }
body.dark-mode .tb-dd-item { color: #cbd5e1; }
body.dark-mode .tb-dd-item:hover { background: #0f172a; color: #f8fafc; }
body.dark-mode .tb-dd-item:hover i { color: #60a5fa; }
body.dark-mode .tb-dd-separator { background: #334155; }
body.dark-mode .tb-dd-item.tb-logout:hover { background: #1a0808; }
</style>
<?php endif; ?>

<?php /* ── HTML ── */ ?>
<div class="top-navbar" id="topNavbar">

    <!-- ===== LEFT: Toggle + optional page title ===== -->
    <div class="tb-left">
        <div class="tb-toggle" id="outerToggle" title="Toggle Sidebar">
            <i class="fa-solid fa-bars"></i>
        </div>
        <?php if (!empty($topbarTitle)): ?>
            <span class="tb-page-title"><?php echo htmlspecialchars($topbarTitle); ?></span>
        <?php endif; ?>
    </div>

    <!-- ===== RIGHT: Dark Mode + Notifications + Profile ===== -->
    <div class="tb-actions">

        <!-- Dark Mode Toggle -->
        <button class="tb-icon-btn" id="darkModeToggle" title="Toggle Dark Mode" aria-label="Toggle dark mode">
            <i class="fa-solid fa-moon" id="darkModeIcon"></i>
        </button>

        <!-- Notification Bell (notifications.php থেকে আসবে) -->
        <?php include 'notifications.php'; ?>

        <!-- Divider -->
        <div class="tb-divider"></div>

        <!-- Profile Chip -->
        <div class="tb-profile" id="tbProfile" onclick="tbToggleProfile(event)">
            <div class="tb-avatar"><?php echo htmlspecialchars($_tb_initials); ?></div>
            <div class="tb-profile-info">
                <span class="tb-profile-name"><?php echo htmlspecialchars($_tb_name); ?></span>
                <span class="tb-profile-role"><?php echo htmlspecialchars($_tb_role_display); ?></span>
            </div>

            <!-- Profile Dropdown -->
            <div class="tb-profile-dropdown" id="tbProfileDropdown" onclick="event.stopPropagation()">
                <div class="tb-dd-header">
                    <div class="tb-dd-header-name"><?php echo htmlspecialchars($_tb_name); ?></div>
                    <div class="tb-dd-header-role"><?php echo htmlspecialchars($_tb_role_display); ?></div>
                </div>

                <a class="tb-dd-item" href="profile.php" onclick="var s=document.getElementById('sidebar');if(s)s.style.pointerEvents='none';">
                    <i class="fa-solid fa-user-pen"></i> My Profile
                </a>
                <div class="tb-dd-separator"></div>
                <a class="tb-dd-item tb-logout" href="logout.php" onclick="var s=document.getElementById('sidebar');if(s)s.style.pointerEvents='none';">
                    <i class="fa-solid fa-right-from-bracket"></i> Logout
                </a>
            </div>
        </div>

    </div>
</div>

<?php /* ── JavaScript ── */ ?>
<script>
(function () {
    'use strict';

    /* ── Dark Mode ── */
    var moonBtn  = document.getElementById('darkModeToggle');
    var moonIcon = document.getElementById('darkModeIcon');

    function tbApplyDark(dark) {
        document.body.classList.toggle('dark-mode', dark);
        if (moonIcon) {
            moonIcon.className = dark ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
        }
        if (moonBtn) {
            moonBtn.classList.toggle('tb-dark-active', dark);
        }
    }

    /* Page load এ localStorage থেকে state নাও */
    tbApplyDark(localStorage.getItem('darkMode') === 'enabled');

    if (moonBtn) {
        moonBtn.addEventListener('click', function () {
            var dark = !document.body.classList.contains('dark-mode');
            localStorage.setItem('darkMode', dark ? 'enabled' : 'disabled');
            tbApplyDark(dark);
        });
    }

    /* ── Hamburger / Sidebar Collapse ── */
    document.addEventListener('DOMContentLoaded', function () {
        var toggle  = document.getElementById('outerToggle');
        var sidebar = document.getElementById('sidebar');
        if (toggle && sidebar) {
            toggle.addEventListener('click', function () {
                sidebar.classList.toggle('collapsed');
            });
        }
    });

    /* ── Profile Dropdown ── */
    window.tbToggleProfile = function (e) {
        if (e) e.stopPropagation();
        var dd = document.getElementById('tbProfileDropdown');
        if (dd) dd.classList.toggle('tb-open');
    };

    /* Outside click এ dropdown বন্ধ */
    document.addEventListener('click', function (e) {
        var profile = document.getElementById('tbProfile');
        var dd      = document.getElementById('tbProfileDropdown');
        if (dd && profile && !profile.contains(e.target)) {
            dd.classList.remove('tb-open');
        }
    });

}());
</script>