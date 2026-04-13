<?php
namespace SuplaBundle\Controller;

trait AdminUiTrait {
    private function adminUiCss(string $extra = ''): string {
        return ':root{--ui-accent:#0b7a3a;--ui-accent-strong:#095d2d;--ui-accent-soft:#e7f6ee;--ui-accent-soft-2:#f1fbf5;--ui-accent-border:#9dd8b8;--ui-accent-border-soft:#bfe8cf;--ui-accent-alt:#22a65b;--ui-danger:#b00020;--ui-danger-soft:#fdecee;--ui-danger-border:#f2b8bf;--ui-text:#18212a;--ui-muted:#5b6570;--ui-surface:#fff;--ui-surface-2:#f8fafb;}'
            . 'body,.ui-shell{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;margin:0;background:radial-gradient(circle at top left,#f7fbf8 0,#f3f6f8 38%,#eef2f5 100%);color:var(--ui-text);min-height:100vh;}'
            . '.ui-shell h1{margin:0 0 12px 0;font-size:26px;letter-spacing:-0.02em;}'
            . '.ui-top,.top{display:flex;justify-content:space-between;gap:10px;align-items:center;margin:0 0 14px 0;padding:11px 14px;background:rgba(255,255,255,.84);border:1px solid rgba(223,229,234,.88);border-radius:16px;box-shadow:0 6px 16px rgba(16,24,40,.04);backdrop-filter:blur(10px);position:sticky;top:10px;z-index:20;flex-wrap:wrap;}'
            . '.ui-card,.card,.stat{background:var(--ui-surface);border:1px solid #e1e7ec;border-radius:16px;padding:14px;box-shadow:0 1px 1px rgba(16,24,40,.03);}'
            . '.ui-notice,.notice{padding:11px 14px;border-radius:12px;margin:10px 0 14px 0;font-size:13px;box-shadow:0 1px 2px rgba(16,24,40,.04);}'
            . '.ui-notice.ok,.notice.ok{background:var(--ui-accent-soft);color:var(--ui-accent);border:1px solid var(--ui-accent-border-soft);}'
            . '.ui-notice.bad,.notice.bad{background:var(--ui-danger-soft);color:var(--ui-danger);border:1px solid var(--ui-danger-border);}'
            . '.ui-table,table{width:100%;border-collapse:separate;border-spacing:0;}'
            . '.ui-table th,.ui-table td,th,td{padding:8px 10px;border-bottom:1px solid #e9eef2;text-align:left;vertical-align:top;font-size:12px;line-height:1.35;}'
            . '.ui-table th,th{background:var(--ui-surface-2);color:#51606d;font-size:11px;text-transform:uppercase;letter-spacing:.04em;font-weight:700;position:sticky;top:0;z-index:1;}'
            . '.ui-table tbody tr:hover td,tbody tr:hover td{background:#fbfdff;}'
            . '.ui-table tbody tr:last-child td,tbody tr:last-child td{border-bottom-color:transparent;}'
            . 'input,select,textarea{font:inherit;padding:9px 12px;border:1px solid #cfd7de;border-radius:10px;background:#fff;box-sizing:border-box;color:#18212a;}'
            . '.ui-button,button{font:inherit;padding:9px 12px;border:1px solid var(--ui-accent);border-radius:10px;background:var(--ui-accent);color:#fff;cursor:pointer;box-shadow:0 1px 0 rgba(16,24,40,.04);}'
            . '.ui-button:hover,button:hover{filter:brightness(.98);}'
            . '.ui-button.gray,button.gray{background:#333;border-color:#333;}'
            . '.ui-button.danger,button.danger{background:var(--ui-danger);border-color:var(--ui-danger);}'
            . '.ui-link,a.ui-link,a{color:var(--ui-accent);text-decoration:none;}a.ui-link:hover,.ui-link:hover,a:hover{text-decoration:underline;}'
            . '.ui-muted,.sub,.hint{color:var(--ui-muted);}'
            . '.ui-kbd,.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px;}'
            . '.ui-columns,.columns{display:grid;grid-template-columns:1fr 1fr;gap:14px;}'
            . '.ui-columns-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;}'
            . '.ui-row,.filters,.actions,.row{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start;}'
            . '.ui-app{display:grid;grid-template-columns:260px minmax(0,1fr);gap:18px;align-items:start;padding:18px;transition:grid-template-columns .2s ease,gap .2s ease;}'
            . '.ui-sidebar{position:sticky;top:18px;background:rgba(255,255,255,.9);border:1px solid rgba(223,229,234,.88);border-radius:20px;padding:14px;box-shadow:0 6px 16px rgba(16,24,40,.04);backdrop-filter:blur(10px);}'
            . '.ui-sidebar-brand{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;}'
            . '.ui-sidebar-title{font-size:14px;font-weight:800;letter-spacing:-0.01em;color:#18212a;}'
            . '.ui-sidebar-subtitle{font-size:12px;color:#5b6570;margin-top:2px;}'
            . '.ui-nav{display:flex;flex-direction:column;gap:8px;margin-top:10px;}'
            . '.ui-nav a{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:14px;color:var(--ui-text) !important;text-decoration:none !important;border:1px solid transparent;background:transparent;transition:background .15s ease,border-color .15s ease,transform .15s ease;}'
            . '.ui-nav a:hover{background:#f7fbf8;border-color:#d6eadf;text-decoration:none !important;transform:translateX(1px);}'
            . '.ui-nav a.active{background:linear-gradient(90deg,var(--ui-accent-soft) 0,var(--ui-accent-soft-2) 100%);border-color:var(--ui-accent-border);font-weight:700;box-shadow:inset 3px 0 0 var(--ui-accent),0 0 0 1px rgba(11,122,58,.06);transform:none;}'
            . '.ui-nav a.active .ui-nav-icon{background:var(--ui-accent);box-shadow:0 8px 18px rgba(11,122,58,.18);transform:scale(1.03);}'
            . '.ui-nav a.active .ui-nav-label{font-weight:800;}'
            . '.ui-nav-icon{width:28px;height:28px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--ui-accent) 0,var(--ui-accent-alt) 100%);color:#fff;flex:0 0 auto;box-shadow:0 8px 18px rgba(11,122,58,.14);}'
            . '.ui-nav-icon svg{width:16px;height:16px;display:block;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;}'
            . '.ui-nav-text{display:flex;flex-direction:column;min-width:0;}'
            . '.ui-nav-label{font-size:13px;line-height:1.2;}'
            . '.ui-nav-desc{font-size:11px;color:#5b6570;line-height:1.2;margin-top:1px;}'
            . '.ui-main{min-width:0;}'
            . '.ui-brand{display:inline-flex;align-items:center;gap:10px;color:var(--ui-text) !important;text-decoration:none !important;font-weight:800;letter-spacing:-0.02em;}'
            . '.ui-brand:hover{text-decoration:none !important;}'
            . '.ui-brand-mark{width:30px;height:30px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--ui-accent) 0,var(--ui-accent-alt) 100%);color:#fff;font-size:15px;box-shadow:0 6px 12px rgba(11,122,58,.14);flex:0 0 auto;}'
            . '.ui-brand-text{font-size:15px;line-height:1;}'
            . '.ui-sidebar-toggle{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:10px;background:#f6f8f9;border:1px solid #dfe5ea;color:var(--ui-text);cursor:pointer;box-shadow:0 1px 1px rgba(16,24,40,.03);font-size:16px;font-weight:700;line-height:1;flex:0 0 auto;}'
            . '.ui-sidebar-toggle:hover{background:#eef4f0;border-color:#c9dfd1;text-decoration:none;}'
            . '.ui-top a,.top a{color:var(--ui-accent);text-decoration:none;}'
            . '.ui-top a:hover,.top a:hover{text-decoration:underline;}'
            . '.ui-title,h3{margin:0 0 10px 0;font-size:15px;letter-spacing:-0.01em;}'
            . '.ui-danger-zone{border:1px solid var(--ui-danger-border);background:linear-gradient(180deg,#fff 0,var(--ui-danger-soft) 100%);box-shadow:0 1px 1px rgba(16,24,40,.03),0 0 0 1px rgba(176,0,32,.03);}'
            . '.ui-danger-zone h3{color:var(--ui-danger);}'
            . '.ui-shell.sidebar-collapsed .ui-app{grid-template-columns:88px minmax(0,1fr);gap:14px;}'
            . '.ui-shell.sidebar-collapsed .ui-sidebar{padding:12px 10px;overflow:hidden;}'
            . '.ui-shell.sidebar-collapsed .ui-sidebar-brand{justify-content:center;}'
            . '.ui-shell.sidebar-collapsed .ui-sidebar-subtitle,.ui-shell.sidebar-collapsed .ui-nav-label,.ui-shell.sidebar-collapsed .ui-nav-desc{display:none;}'
            . '.ui-shell.sidebar-collapsed .ui-nav{gap:10px;}'
            . '.ui-shell.sidebar-collapsed .ui-nav a{justify-content:center;padding:10px 8px;position:relative;}'
            . '.ui-shell.sidebar-collapsed .ui-nav a:hover::after{content:attr(data-nav-label);position:absolute;left:calc(100% + 10px);top:50%;transform:translateY(-50%);background:#18212a;color:#fff;font-size:12px;line-height:1;padding:7px 9px;border-radius:10px;white-space:nowrap;box-shadow:0 8px 18px rgba(16,24,40,.18);z-index:30;}'
            . '.ui-shell.sidebar-collapsed .ui-nav a:hover::before{content:"";position:absolute;left:calc(100% + 4px);top:50%;transform:translateY(-50%);border:6px solid transparent;border-right-color:#18212a;z-index:31;}'
            . '.ui-shell.sidebar-collapsed .ui-nav-icon{width:32px;height:32px;border-radius:11px;}'
            . '.ui-shell.sidebar-collapsed .ui-brand-text{display:none;}'
            . '.ui-shell.sidebar-collapsed .ui-brand{gap:0;}'
            . '.ui-shell.sidebar-collapsed .ui-sidebar-toggle{margin-left:auto;display:flex;}'
            . '.ui-shell.sidebar-collapsed .ui-main{min-width:0;}'
            . '@media (max-width:900px){body,.ui-shell{margin:0 12px 12px 12px;}.ui-top,.top{position:static;padding:10px 12px;border-radius:14px;}.ui-app{grid-template-columns:1fr;padding:12px 0 0 0;gap:12px;}.ui-sidebar{position:static;padding:12px;border-radius:16px;}.ui-columns,.ui-columns-3,.columns{grid-template-columns:1fr;}}'
            . '@media (max-width:640px){body,.ui-shell{margin:0 8px 8px 8px;}.ui-shell h1{font-size:22px;}.ui-top,.top{padding:9px 10px;gap:8px;border-radius:12px;}.ui-app{padding:10px 0 0 0;gap:10px;}.ui-card,.card,.stat{padding:12px;border-radius:14px;}.ui-sidebar{padding:10px;border-radius:14px;}.ui-nav a{padding:9px 10px;}.ui-nav-desc{display:none;}.ui-nav-label{font-size:12px;}}'
            . $extra;
    }

    private function adminUiTopBar(string $leftHtml, string $rightHtml = ''): string {
        $brand = '<a class="ui-brand" href="/admin/dashboard" aria-label="Dashboard">'
            . '<span class="ui-brand-mark">S</span>'
            . '<span class="ui-brand-text">SUPLA Admin</span>'
            . '</a>';
        return '<div class="ui-top"><div>' . $brand . '<span style="display:inline-block;width:14px;"></span>' . $leftHtml . '</div><div>' . $rightHtml . '</div></div>';
    }

    private function adminUiPageOpen(string $title, string $extraCss = '', string $bodyClass = 'ui-shell'): string {
        return '<!doctype html><html><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" />'
            . '<title>' . $title . '</title>'
            . '<style>' . $this->adminUiCss($extraCss) . '</style>'
            . '<script>'
            . '(function(){var k="supla-admin-sidebar-collapsed";var c="sidebar-collapsed";var a=function(){try{if(localStorage.getItem(k)==="1"&&document.body){document.body.classList.add(c);}}catch(e){}};'
            . 'window.addEventListener("DOMContentLoaded",function(){a();var b=document.querySelector("[data-ui-sidebar-toggle]");if(!b)return;b.addEventListener("click",function(){if(!document.body)return;var r=document.body.classList.toggle(c);try{localStorage.setItem(k,r?"1":"0")}catch(e){}});});})();'
            . '</script>'
            . '</head><body class="' . $bodyClass . '">';
    }

    private function adminUiLayoutOpen(string $title, string $activeNav, bool $superAdmin = false, string $extraCss = ''): string {
        return $this->adminUiPageOpen($title, $extraCss, 'ui-shell ui-shell--app')
            . '<div class="ui-app">'
            . $this->adminUiSidebar($activeNav, $superAdmin)
            . '<div class="ui-main">';
    }

    private function adminUiLayoutClose(): string {
        return '</div></div>' . $this->adminUiPageClose();
    }

    private function adminUiSidebar(string $activeNav, bool $superAdmin = false): string {
        $items = [
            ['dashboard', 'Dashboard', 'Overview & health', '/admin/dashboard'],
            ['users', 'Users', 'Accounts & limits', '/admin/users'],
            ['system-health', 'Health', 'DB, MQTT, cron, SSL', '/admin/health'],
            ['backup', 'Backup', 'Restore & export', '/admin/backup'],
            ['scheduler', 'Scheduler', 'Automated backups', '/admin/backup/scheduler'],
            ['account', 'Account', 'Admin profile', '/admin/account'],
            ['security-log', 'Security log', 'Admin events', '/admin/security-log'],
        ];
        if ($superAdmin) {
            $items[] = ['admins', 'Admins', 'Admin accounts', '/admin/admins'];
            $items[] = ['admin-history', 'Admin history', 'Change audit', '/admin/admin-history'];
        }

        $html = '<aside class="ui-sidebar">'
            . '<div class="ui-sidebar-brand">'
            . '<a class="ui-brand" href="/admin/dashboard" aria-label="Dashboard">'
            . '<span class="ui-brand-mark">S</span>'
            . '<span class="ui-brand-text">SUPLA Admin</span>'
            . '</a>'
            . '<button class="ui-sidebar-toggle" type="button" data-ui-sidebar-toggle aria-label="Toggle sidebar" title="Toggle sidebar">☰</button>'
            . '</div>'
            . '<div class="ui-sidebar-subtitle">Operations console</div>'
            . '<nav class="ui-nav">';
        foreach ($items as [$key, $label, $desc, $href]) {
            $active = $key === $activeNav ? ' active' : '';
            $html .= '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" class="' . $active . '" data-nav-label="' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" aria-label="' . htmlspecialchars($label . ' - ' . $desc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                . '<span class="sr-only" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;">' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>'
                . '<span class="ui-nav-icon" aria-hidden="true">' . $this->adminUiNavIconSvg($key) . '</span>'
                . '<span class="ui-nav-text"><span class="ui-nav-label">' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span><span class="ui-nav-desc">' . htmlspecialchars($desc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span></span>'
                . '</a>';
        }
        $html .= '</nav></aside>';
        return $html;
    }

    private function adminUiNavIconSvg(string $key): string {
        $icons = [
            'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12h7V4H4z"></path><path d="M13 20h7v-8h-7z"></path><path d="M13 4h7v5h-7z"></path><path d="M4 20h7v-5H4z"></path></svg>',
            'users' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"></path><circle cx="9.5" cy="7" r="3"></circle><path d="M20 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16.5 4.5a3 3 0 0 1 0 5.5"></path></svg>',
            'system-health' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l8 4v6c0 5-3.5 8.4-8 10-4.5-1.6-8-5-8-10V6z"></path><path d="M12 7v5l3 2"></path></svg>',
            'backup' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v10"></path><path d="M8 9l4 4 4-4"></path><path d="M5 15v4h14v-4"></path></svg>',
            'scheduler' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"></circle><path d="M12 8v5l3 2"></path></svg>',
            'account' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7.5" r="3.5"></circle></svg>',
            'security-log' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l8 4v6c0 5-3.5 8.4-8 10-4.5-1.6-8-5-8-10V6z"></path><path d="M12 8v4"></path><circle cx="12" cy="15.5" r="1"></circle></svg>',
            'admins' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20v-2a4 4 0 0 1 4-4h4"></path><circle cx="10" cy="8" r="3"></circle><path d="M14 6h6"></path><path d="M17 3v6"></path></svg>',
            'admin-history' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"></circle><path d="M12 8v4l3 2"></path><path d="M8 4.5h3"></path></svg>',
        ];

        return $icons[$key] ?? '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"></circle></svg>';
    }

    private function adminUiPageClose(): string {
        return '</body></html>';
    }

    private function adminUiCardTitle(string $title): string {
        return '<h3 class="ui-title">' . $title . '</h3>';
    }
}
