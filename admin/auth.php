<?php
declare(strict_types=1);

require __DIR__ . "/bootstrap.php";

if (!is_admin()) {
  $next = $_SERVER["REQUEST_URI"] ?? "/admin/payment/dashboard.php";
  redirect("/admin/login.php?next=" . urlencode($next));
}