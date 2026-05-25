<?php
declare(strict_types=1);

$path = parse_url($_SERVER["REQUEST_URI"] ?? "", PHP_URL_PATH) ?: "";

// page config (page akan set sebelum include nav.php)
$pageTitle = $pageTitle ?? "Dashboard";
$pageDesc  = $pageDesc  ?? "";
$headerActionsHtmlDesktop = $headerActionsHtmlDesktop ?? "";
$headerActionsHtmlMobile  = $headerActionsHtmlMobile ?? "";

// show notification bell on all admin pages
$showPaymentNotif = str_starts_with($path, "/admin/");

function nav_active(string $needle, string $path): string {
  $active = str_contains($path, $needle);
  return $active
    ? "bg-yellow-100 text-yellow-900"
    : "text-slate-800 hover:bg-yellow-200 hover:text-slate-900";
}

function nav_icon(string $name): string {
  return match ($name) {
    'payment' => '<svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
      <path d="M15.75 14.5a.75.75 0 0 0 0 1.5h2.5a.75.75 0 0 0 0-1.5h-2.5ZM2 8.25A3.25 3.25 0 0 1 5.25 5h13.5A3.25 3.25 0 0 1 22 8.25v7.5A3.25 3.25 0 0 1 18.75 19H5.25A3.25 3.25 0 0 1 2 15.75v-7.5ZM20.5 9.5V8.25a1.75 1.75 0 0 0-1.75-1.75H5.25A1.75 1.75 0 0 0 3.5 8.25V9.5h17ZM3.5 11v4.75c0 .966.784 1.75 1.75 1.75h13.5a1.75 1.75 0 0 0 1.75-1.75V11h-17Z"/>
    </svg>',
    'webinar' => '<svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M23 7l-7 5 7 5V7z" />
      <rect x="1" y="5" width="15" height="14" rx="2" ry="2" />
    </svg>',
    'elearn' => '<svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M2.459 9c1.277-4.057 5.077-7 9.566-7c5.198 0 9.472 3.947 9.975 9l-2-.407"/>
      <path d="M21.541 15a10.03 10.03 0 0 1-9.566 7C6.777 22 2.503 18.053 2 13l2 .407"/>
      <path d="M9.002 13.528v1.992a.95.95 0 0 0 .432.81c.844.528 1.485.683 2.571.716c1.001.027 1.629-.13 2.563-.714a.96.96 0 0 0 .44-.82v-1.984"/>
      <path d="M16.005 11.015v3.015"/>
      <path d="M7.05 10.844c.362-.764 2.605-2.094 4.652-2.751a.93.93 0 0 1 .604.014c1.81.662 3.824 1.665 4.555 2.478c.381.425.008.967-.453 1.302c-.937.681-1.97 1.21-4.058 2.013a.98.98 0 0 1-.688.005c-2.14-.795-4.142-1.82-4.595-2.723a.39.39 0 0 1-.017-.338"/>
    </svg>',
    'mail' => '<svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M4 6.75A2.75 2.75 0 0 1 6.75 4h10.5A2.75 2.75 0 0 1 20 6.75v10.5A2.75 2.75 0 0 1 17.25 20H6.75A2.75 2.75 0 0 1 4 17.25V6.75Z"/>
      <path d="m5 7 7 5 7-5"/>
    </svg>',
    'chev' => '<svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
    </svg>',
    'student' => '<svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
      <polyline points="15 3 21 3 21 9"/>
      <line x1="10" y1="14" x2="21" y2="3"/>
    </svg>',
    'logout' => '<svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
      <polyline points="16 17 21 12 16 7"/>
      <line x1="21" y1="12" x2="9" y2="12"/>
    </svg>',
    default => '<svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6l4 2"/></svg>',
  };
}

function sidebar_sublink(string $href, string $label, string $needle, string $path): void {
  $cls = nav_active($needle, $path);
  echo '<a href="' . htmlspecialchars($href) . '" class="group flex items-center gap-3 px-4 py-2 rounded-2xl text-sm font-extrabold transition ' . $cls . '">'
    . '<span class="w-2 h-2 rounded-full shrink-0 ' . (str_contains($path, $needle) ? 'bg-yellow-500' : 'bg-slate-300') . '"></span>'
    . '<span class="tracking-tight whitespace-nowrap">' . htmlspecialchars($label) . '</span>'
    . '</a>';
}

function group_shell_cls(bool $open): string {
  return $open
    ? "bg-yellow-50/70"
    : "bg-transparent";
}

function render_menu(string $path): void {
  $inPayment      = str_starts_with($path, "/admin/payment/");
  $inSubscription = str_starts_with($path, "/admin/subscription/");
  $inWebinar      = str_starts_with($path, "/admin/webinar/");
  $inELearning    = str_starts_with($path, "/admin/elearning/");
  $inEmail        = str_starts_with($path, "/admin/email/");

  // Desktop collapsed: icon centered, no padding. Desktop expanded: normal layout.
  // Mobile: justify-between + px-4 always (md: classes don't apply on mobile).
  $summaryCls = 'cursor-pointer list-none flex items-center justify-between px-4 py-3 rounded-2xl hover:bg-yellow-200 transition-all duration-300 md:px-2 md:justify-center md:group-hover/sidebar:px-4 md:group-hover/sidebar:justify-between';

  // Label text: hidden (max-w-0) in desktop collapsed, revealed on hover.
  $labelCls = 'font-black text-slate-900 overflow-hidden whitespace-nowrap transition-all duration-300 md:max-w-0 md:group-hover/sidebar:max-w-xs';

  // Chevron wrapper: hidden in desktop collapsed, revealed on hover.
  $chevWrapCls = 'overflow-hidden whitespace-nowrap transition-all duration-300 shrink-0 md:max-w-0 md:group-hover/sidebar:max-w-[1.5rem]';

  // Submenu: height-collapsed in desktop collapsed mode, expanded on hover.
  $submenuCls = 'px-2 pb-3 space-y-2 overflow-hidden transition-[max-height] duration-300 ease-in-out md:max-h-0 md:group-hover/sidebar:max-h-96';

  // ── Product Payment ──────────────────────────────────────────────────────────
  echo '<details class="group rounded-2xl ' . group_shell_cls($inPayment) . '" ' . ($inPayment ? 'open' : '') . '>';
  echo '<summary class="' . $summaryCls . '" title="Product Payment">';
  echo '<span class="flex items-center gap-3 min-w-0">
          <span class="text-slate-400 group-open:text-yellow-600 transition-colors shrink-0">' . nav_icon('payment') . '</span>
          <span class="' . $labelCls . '">Product Payment</span>
        </span>';
  echo '<span class="' . $chevWrapCls . '">
          <span class="text-slate-400 transition-transform duration-200 group-open:rotate-90 inline-block">' . nav_icon('chev') . '</span>
        </span>';
  echo '</summary>';
  echo '<div class="' . $submenuCls . '">';
  sidebar_sublink("/admin/payment/dashboard.php", "Dashboard", "/admin/payment/dashboard.php", $path);
  sidebar_sublink("/admin/payment/admin-products.php", "Products", "/admin/payment/admin-products.php", $path);
  sidebar_sublink("/admin/payment/transactions.php", "Transactions", "/admin/payment/transactions.php", $path);
  echo '</div></details>';

  // ── Subscriptions ────────────────────────────────────────────────────────────
  echo '<details class="group rounded-2xl ' . group_shell_cls($inSubscription) . '" ' . ($inSubscription ? 'open' : '') . '>';
  echo '<summary class="' . $summaryCls . '" title="Subscriptions">';
  echo '<span class="flex items-center gap-3 min-w-0">
          <span class="text-slate-400 group-open:text-yellow-600 transition-colors shrink-0">
            <svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
            </svg>
          </span>
          <span class="' . $labelCls . '">Subscriptions</span>
        </span>';
  echo '<span class="' . $chevWrapCls . '">
          <span class="text-slate-400 transition-transform duration-200 group-open:rotate-90 inline-block">' . nav_icon('chev') . '</span>
        </span>';
  echo '</summary>';
  echo '<div class="' . $submenuCls . '">';
  sidebar_sublink("/admin/subscription/index.php", "All Subscriptions", "/admin/subscription/index.php", $path);
  echo '</div></details>';

  // ── Webinars ─────────────────────────────────────────────────────────────────
  echo '<details class="group rounded-2xl ' . group_shell_cls($inWebinar) . '" ' . ($inWebinar ? 'open' : '') . '>';
  echo '<summary class="' . $summaryCls . '" title="Webinars">';
  echo '<span class="flex items-center gap-3 min-w-0">
          <span class="text-slate-400 group-open:text-yellow-600 transition-colors shrink-0">' . nav_icon('webinar') . '</span>
          <span class="' . $labelCls . '">Webinars</span>
        </span>';
  echo '<span class="' . $chevWrapCls . '">
          <span class="text-slate-400 transition-transform duration-200 group-open:rotate-90 inline-block">' . nav_icon('chev') . '</span>
        </span>';
  echo '</summary>';
  echo '<div class="' . $submenuCls . '">';
  sidebar_sublink("/admin/webinar/index.php", "Webinar Management", "/admin/webinar/index.php", $path);
  echo '</div></details>';

  // ── e-Learning ───────────────────────────────────────────────────────────────
  echo '<details class="group rounded-2xl ' . group_shell_cls($inELearning) . '" ' . ($inELearning ? 'open' : '') . '>';
  echo '<summary class="' . $summaryCls . '" title="e-Learning">';
  echo '<span class="flex items-center gap-3 min-w-0">
          <span class="text-slate-400 group-open:text-yellow-600 transition-colors shrink-0">' . nav_icon('elearn') . '</span>
          <span class="' . $labelCls . '">e-Learning</span>
        </span>';
  echo '<span class="' . $chevWrapCls . '">
          <span class="text-slate-400 transition-transform duration-200 group-open:rotate-90 inline-block">' . nav_icon('chev') . '</span>
        </span>';
  echo '</summary>';
  echo '<div class="' . $submenuCls . '">';
  sidebar_sublink("/admin/elearning/dashboard.php", "Dashboard", "/admin/elearning/dashboard.php", $path);
  sidebar_sublink("/admin/elearning/courses.php", "Courses", "/admin/elearning/courses.php", $path);
  sidebar_sublink("/admin/elearning/contents.php", "Content", "/admin/elearning/contents.php", $path);
  sidebar_sublink("/admin/elearning/progress.php", "Student Progress", "/admin/elearning/progress.php", $path);
  // Google integration removed in demo
  echo '</div></details>';

  // ── Email ────────────────────────────────────────────────────────────────────
  echo '<details class="group rounded-2xl ' . group_shell_cls($inEmail) . '" ' . ($inEmail ? 'open' : '') . '>';
  echo '<summary class="' . $summaryCls . '" title="Email">';
  echo '<span class="flex items-center gap-3 min-w-0">
          <span class="text-slate-400 group-open:text-yellow-600 transition-colors shrink-0">' . nav_icon('mail') . '</span>
          <span class="' . $labelCls . '">Email</span>
        </span>';
  echo '<span class="' . $chevWrapCls . '">
          <span class="text-slate-400 transition-transform duration-200 group-open:rotate-90 inline-block">' . nav_icon('chev') . '</span>
        </span>';
  echo '</summary>';
  echo '<div class="' . $submenuCls . '">';
  sidebar_sublink("/admin/email/custom-email.php", "Custom Email", "/admin/email/custom-email.php", $path);
  sidebar_sublink("/admin/email/email-templates.php", "Email Templates", "/admin/email/email-templates.php", $path);
  sidebar_sublink("/admin/email/campaign-monitoring.php", "Campaign Monitoring", "/admin/email/campaign-", $path);
  sidebar_sublink("/admin/email/email-logs.php", "Email Logs", "/admin/email/email-logs.php", $path);
  echo '</div></details>';
}

?>

<div class="min-h-screen flex">

  <!-- ═══════════════════════════════════════════════════════════════════════════
       DESKTOP SIDEBAR
       - Fixed position, z-50, overlays content on hover
       - Collapsed: w-20 (80px), icons only
       - Expanded: w-72 (288px), full labels visible
       - group/sidebar drives all child transitions via group-hover/sidebar:
       ═══════════════════════════════════════════════════════════════════════════ -->
  <aside class="group/sidebar hidden md:flex flex-col bg-white
                fixed left-0 top-0 h-screen z-50
                overflow-hidden
                transition-all duration-300 ease-in-out
                w-20 hover:w-72
                shadow-[0_0_0_1px_rgba(15,23,42,0.04)] hover:shadow-xl">

    <!-- Logo -->
    <div class="border-b border-slate-100 shrink-0 overflow-hidden">
      <a href="/admin/payment/dashboard.php"
         class="flex items-center gap-3 py-6 px-4 transition-all duration-300
                md:justify-center md:group-hover/sidebar:justify-start">
        <div class="h-10 w-10 shrink-0">
          <img src="/img/demo_logo.svg" width="40" height="40" alt="Demo" class="h-10 w-10">
        </div>
        <!-- Text hidden in collapsed, fades in on hover -->
        <div class="overflow-hidden whitespace-nowrap transition-all duration-300
                    md:max-w-0 md:opacity-0
                    md:group-hover/sidebar:max-w-xs md:group-hover/sidebar:opacity-100">
          <div class="text-sm font-black text-slate-900 leading-4">Admin Panel</div>
          <div class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Control Center</div>
        </div>
      </a>
    </div>

    <!-- Navigation -->
    <div class="py-5 flex-1 overflow-y-auto overflow-x-hidden sidebar-scrollbar-hidden
                transition-all duration-300 px-4 md:px-0 md:group-hover/sidebar:px-4">

      <!-- "Navigation" heading: collapsed in desktop icon-only mode -->
      <div class="overflow-hidden transition-all duration-300 mb-3
                  md:max-h-0 md:opacity-0 md:mb-0
                  md:group-hover/sidebar:max-h-8 md:group-hover/sidebar:opacity-100 md:group-hover/sidebar:mb-3">
        <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-3 whitespace-nowrap">Navigation</div>
      </div>

      <div class="space-y-3">
        <?php render_menu($path); ?>
      </div>
    </div>

    <!-- Bottom actions -->
    <div class="border-t border-slate-100 shrink-0 transition-all duration-300
                p-4 md:p-2 md:group-hover/sidebar:p-4">
      <a href="https://syedihsan.github.io/e-Learning/"
         data-open-student
         target="_blank" rel="noopener"
         title="Open Student Site"
         class="flex items-center justify-center gap-2 w-full px-3 py-3 rounded-2xl
                bg-slate-900 text-white font-black text-sm hover:bg-slate-800 transition">
        <?= nav_icon('student') ?>
        <span class="overflow-hidden whitespace-nowrap transition-all duration-300
                     md:max-w-0 md:group-hover/sidebar:max-w-xs">Open Student Site</span>
      </a>
      <a href="/admin/logout.php"
         title="Logout"
         class="mt-2 flex items-center justify-center gap-2 w-full px-3 py-3 rounded-2xl
                bg-white border border-slate-200 text-slate-600 font-black text-sm
                hover:border-red-200 hover:text-red-600 hover:bg-red-50 transition">
        <?= nav_icon('logout') ?>
        <span class="overflow-hidden whitespace-nowrap transition-all duration-300
                     md:max-w-0 md:group-hover/sidebar:max-w-xs">Logout</span>
      </a>
    </div>
  </aside>

  <!-- ═══════════════════════════════════════════════════════════════════════════
       RIGHT SIDE — offset by collapsed sidebar width on desktop (ml-20 = 80px)
       Mobile has no offset (ml-20 is md: only so irrelevant on mobile).
       ═══════════════════════════════════════════════════════════════════════════ -->
  <div class="flex-1 flex flex-col min-w-0 md:ml-20">

    <!-- Mobile Top Bar -->
    <div class="md:hidden sticky top-0 z-50 bg-white/80 backdrop-blur-md border-b border-slate-200">
      <div class="h-16 px-4 flex items-center justify-between gap-3">

        <!-- left: burger + title -->
        <div class="flex items-center gap-3 min-w-0">
          <button type="button"
            onclick="window.__sdcToggleAdminDrawer?.()"
            class="inline-flex items-center justify-center w-11 h-11 rounded-2xl bg-white border border-slate-200 text-slate-700">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
          </button>

          <a href="/admin/payment/dashboard.php" class="flex items-center gap-2 font-black text-slate-900 truncate">
            <span>Demo Admin</span>
          </a>
        </div>

        <!-- right: page actions + bell -->
        <div class="flex items-center gap-2 shrink-0">
          <?= $headerActionsHtmlMobile ?>

          <?php if ($showPaymentNotif): ?>
            <button type="button"
              class="js-notif-bell relative inline-flex items-center justify-center w-11 h-11 rounded-2xl bg-white border border-slate-200 text-slate-700 hover:shadow-sm"
              aria-label="Notifications">
              <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0a3 3 0 11-6 0m6 0H9"/>
              </svg>
              <span class="js-notif-badge hidden absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 rounded-full bg-red-500 text-white text-[10px] font-black flex items-center justify-center"></span>
            </button>
          <?php endif; ?>
        </div>

      </div>
    </div>

    <!-- Mobile Drawer (Always Available) -->
    <div id="adminDrawer" class="md:hidden fixed inset-0 z-[60] hidden">
      <div class="absolute inset-0 bg-black/40" onclick="window.__sdcCloseAdminDrawer?.()"></div>
      <aside class="absolute left-0 top-0 h-full w-72 bg-white border-r border-slate-200 flex flex-col">
        <div class="px-6 py-6 border-b border-slate-100 flex items-center justify-between">
          <a href="/admin/payment/dashboard.php" class="flex items-center gap-3">
            <div class="w-10 h-10 shrink-0">
              <img src="/img/demo_logo.svg" width="40" height="40" alt="Demo" class="h-10 w-10">
            </div>
            <div class="font-black text-slate-900">Admin Panel</div>
          </a>
          <button type="button" onclick="window.__sdcCloseAdminDrawer?.()"
            class="w-10 h-10 rounded-2xl border border-slate-200 text-slate-700">
            <svg class="w-5 h-5 mx-auto" viewBox="0 0 24 24" fill="none" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>

        <div class="px-4 py-5 flex-1 overflow-y-auto sidebar-scrollbar-hidden">
          <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-3 mb-3">Navigation</div>
          <div class="space-y-3">
            <?php render_menu($path); ?>
          </div>
        </div>

        <div class="p-4 border-t border-slate-100">
          <a href="https://syedihsan.github.io/e-Learning/"
            data-open-student
            target="_blank" rel="noopener"
            class="block w-full text-center px-4 py-3 rounded-2xl bg-slate-900 text-white font-black text-sm">
            Open Student Site
          </a>
          <a href="/admin/logout.php" class="mt-3 block w-full text-center px-4 py-3 rounded-2xl bg-white border border-slate-200 text-slate-600 font-black text-sm hover:text-red-600">
            Logout
          </a>
        </div>
      </aside>
    </div>

    <script>
      window.__sdcToggleAdminDrawer = function () {
        var d = document.getElementById('adminDrawer');
        if (!d) return;
        d.classList.contains('hidden') ? d.classList.remove('hidden') : d.classList.add('hidden');
      };
      window.__sdcCloseAdminDrawer = function () {
        var d = document.getElementById('adminDrawer');
        if (d) d.classList.add('hidden');
      };
    </script>

    <!-- Desktop Page Header -->
    <div class="hidden md:block sticky top-0 z-40 bg-slate-50/80 backdrop-blur-md">
      <div class="px-4 sm:px-6 lg:px-8 py-6 border-b border-slate-200">
        <div class="flex items-start justify-between gap-4">
          <div class="min-w-0">
            <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight truncate">
              <?= htmlspecialchars((string)$pageTitle, ENT_QUOTES, "UTF-8") ?>
            </h1>
            <?php if (trim((string)$pageDesc) !== ""): ?>
              <p class="mt-2 text-sm font-semibold text-slate-500">
                <?= htmlspecialchars((string)$pageDesc, ENT_QUOTES, "UTF-8") ?>
              </p>
            <?php endif; ?>
          </div>

          <div class="flex items-center gap-2 shrink-0">
            <?= $headerActionsHtmlDesktop ?>

            <?php if ($showPaymentNotif): ?>
              <button type="button"
                class="js-notif-bell relative inline-flex items-center justify-center w-11 h-11 rounded-2xl bg-white border border-slate-200 text-slate-700 hover:shadow-sm"
                aria-label="Notifications">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0a3 3 0 11-6 0m6 0H9"/>
                </svg>
                <span class="js-notif-dot hidden absolute top-2 right-2 flex h-3 w-3">
                  <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                  <span class="relative inline-flex rounded-full h-3 w-3 bg-rose-500 border-2 border-white"></span>
                </span>
              </button>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <?php if ($showPaymentNotif): ?>
      <!-- Notifications Overlay + Panel -->
      <div id="notifOverlay" class="fixed inset-0 bg-black/40 hidden z-[70]"></div>

      <div id="notifPanel" class="fixed right-4 top-20 w-[22rem] max-w-[calc(100vw-2rem)] bg-white border border-slate-200 shadow-2xl rounded-2xl overflow-hidden hidden z-[80]">
        <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between bg-slate-50">
          <div>
            <div class="text-sm font-black text-slate-900">Notifications</div>
            <div class="text-[11px] font-bold text-slate-400">Latest 8 updates</div>
          </div>
          <button type="button" id="notifCloseBtn" class="w-9 h-9 rounded-xl border border-slate-200 text-slate-600 hover:bg-white">
            <svg class="w-4 h-4 mx-auto" viewBox="0 0 24 24" fill="none" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>

        <div id="notifList" class="max-h-[70vh] overflow-y-auto divide-y divide-slate-100"></div>
      </div>

      <script>
      (function () {
        const API = "/admin/api/notifications.php?scope=all";

        const overlay = document.getElementById("notifOverlay");
        const panel   = document.getElementById("notifPanel");
        const listEl  = document.getElementById("notifList");
        const closeBtn = document.getElementById("notifCloseBtn");

        const bellBtns = Array.from(document.querySelectorAll(".js-notif-bell"));
        const dotEls = Array.from(document.querySelectorAll(".js-notif-dot"));

        if (!overlay || !panel || !listEl || bellBtns.length === 0) return;

        function setBadge(n) {
          dotEls.forEach(b => {
            if (!b) return;
            if (n > 0) b.classList.remove("hidden");
            else b.classList.add("hidden");
          });
        }

        function shortName(name, max = 18) {
          const n = String(name || "").trim();
          if (n.length <= max) return n;

          const words = n.split(/\s+/);
          let out = "";
          for (const w of words) {
            const next = out ? out + " " + w : w;
            if (next.length > max) break;
            out = next;
          }
          return (out || n.slice(0, max)).trim() + "...";
        }

        async function pollCount() {
          try {
            const res = await fetch(API + "&mode=count", { credentials: "same-origin" });
            const j = await res.json();
            setBadge(j?.count ? Number(j.count) : 0);
          } catch (e) {}
        }

        async function loadList() {
          listEl.innerHTML = `<div class="p-4 text-sm text-slate-500 font-semibold">Loading...</div>`;
          try {
            const res = await fetch(API + "&mode=list", { credentials: "same-origin" });
            const j = await res.json();
            const items = j?.items || [];

            if (!items.length) {
              listEl.innerHTML = `<div class="p-4 text-sm text-slate-500 font-semibold">No recent updates.</div>`;
              return;
            }

            listEl.innerHTML = items.map(it => {
              const href  = (it.url || "#").replace(/</g,"&lt;");
              const name  = shortName(it.name || "", 18).replace(/</g,"&lt;");
              const item  = (it.item || "").replace(/</g,"&lt;");
              const pkg   = (it.package || "").replace(/</g,"&lt;");
              const amt   = (it.amount || "").replace(/</g,"&lt;");
              const when  = (it.timeAgo || "").replace(/</g,"&lt;");
              const scope = (it.scope || "payment").replace(/</g,"&lt;");

              const badge = scope === "elearning"
                ? `<span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-black bg-indigo-50 text-indigo-600 border border-indigo-100">E-LEARNING</span>`
                : `<span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-black bg-emerald-50 text-emerald-700 border border-emerald-100">PAYMENT</span>`;

              const right = amt
                ? `<div class="text-sm font-black text-yellow-600 whitespace-nowrap">${amt}</div>`
                : `<div class="text-[10px] font-black text-slate-400 whitespace-nowrap">VIEW</div>`;

              return `
                <a href="${href}" class="block p-4 hover:bg-slate-50 transition">
                  <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                      <div class="flex items-center gap-2 mb-1 min-w-0">
                        <div class="text-sm font-black text-slate-900 truncate flex-1 min-w-0">${name}</div>
                        <div class="shrink-0">${badge}</div>
                      </div>
                      <div class="text-[12px] font-bold text-slate-500 truncate">${item}${pkg ? " • " + pkg : ""}</div>
                      <div class="text-[11px] font-bold text-slate-400 mt-1">${when}</div>
                    </div>
                    ${right}
                  </div>
                </a>
              `;
            }).join("");
          } catch (e) {
            listEl.innerHTML = `<div class="p-4 text-sm text-rose-600 font-bold">Failed to load notifications.</div>`;
          }
        }

        function openPanel() {
          overlay.classList.remove("hidden");
          panel.classList.remove("hidden");
          loadList();
          fetch(API + "&mode=mark_seen", { credentials: "same-origin" }).catch(()=>{});
          setTimeout(pollCount, 400);
        }

        function closePanel() {
          overlay.classList.add("hidden");
          panel.classList.add("hidden");
        }

        function togglePanel() {
          panel.classList.contains("hidden") ? openPanel() : closePanel();
        }

        bellBtns.forEach(btn => btn.addEventListener("click", (e) => {
          e.preventDefault();
          togglePanel();
        }));

        overlay.addEventListener("click", closePanel);
        closeBtn && closeBtn.addEventListener("click", closePanel);
        window.addEventListener("keydown", (e) => { if (e.key === "Escape") closePanel(); });

        pollCount();
        setInterval(pollCount, 30000);
      })();
      </script>
    <?php endif; ?>

    <script>
    (function () {
      function hasTruthy(v){ return !!v && v !== "null" && v !== "undefined" && v !== '""'; }
      function hasStudentLocalLogin() {
        try { return hasTruthy(localStorage.getItem("sdc_user")); } catch (e) { return false; }
      }
      document.addEventListener("click", async function (e) {
        const a = e.target.closest('a[data-open-student]');
        if (!a) return;
        e.preventDefault();
        window.open("https://syedihsan.github.io/e-Learning/", "_blank", "noopener");
      });
    })();
    </script>

    <!-- Main Content Area -->
    <main class="flex-1 px-4 sm:px-6 lg:px-8 py-10">
