<?php
declare(strict_types=1);
require __DIR__ . "/bootstrap.php";

function safe_next(string $next): string {
    $next = trim($next);
    if ($next === "") return "";
    if ($next[0] !== "/") return "";
    if (str_starts_with($next, "//")) return "";
    if (!str_starts_with($next, "/admin/")) return "";
    if (str_contains($next, "\n") || str_contains($next, "\r")) return "";
    return $next;
}

$next = safe_next((string)($_GET["next"] ?? "")) ?: "/admin/payment/dashboard.php";

if (is_admin()) {
    redirect($next);
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    csrf_validate();

    $next = safe_next((string)($_POST["next"] ?? "")) ?: $next;

    $username = trim((string)($_POST["username"] ?? ""));
    $password = (string)($_POST["password"] ?? "");

    if ($username === "" || $password === "") {
        $error = "Username and password are required.";
    } else {
        $stmt = $conn->prepare("SELECT id, password_hash FROM admins WHERE username=? LIMIT 1");

        if (!$stmt) {
            error_log("LOGIN prepare failed: " . ($conn->error ?? "unknown"));
            $error = "DB error. Please try again.";
        } else {
            $stmt->bind_param("s", $username);

            if (!$stmt->execute()) {
                error_log("LOGIN execute failed: " . ($stmt->error ?? "unknown"));
                $error = "DB error. Please try again.";
            } else {
                $res = $stmt->get_result();
                $row = $res ? ($res->fetch_assoc() ?: null) : null;

                if (!$row || !password_verify($password, (string)$row["password_hash"])) {
                    $error = "Invalid username or password.";
                } else {
                    session_regenerate_id(true);
                    $_SESSION["admin_id"] = (int)$row["id"];
                    redirect($next);
                }
            }
            $stmt->close();
        }
    }
}

$title = "Admin Login — Demo";
include __DIR__ . "/partials/header.php";
if (defined('DEMO_MODE') && DEMO_MODE === true) include __DIR__ . '/partials/demo-banner.php';
?>
<div class="min-h-screen flex items-center justify-center px-4">
  <div class="max-w-md w-full space-y-6">

    <div class="bg-white rounded-[2rem] shadow-2xl shadow-slate-200 border border-slate-100 p-8 md:p-12">
      <div class="text-center mb-10">
        <div class="w-16 h-16 rounded-2xl bg-yellow-500 flex items-center justify-center mx-auto mb-6">
          <img src="/img/demo_logo_white.svg" width="40" height="40" alt="Demo" class="h-10 w-10">
        </div>
        <h2 class="text-3xl font-extrabold text-slate-900 mb-2">Admin Sign In</h2>
        <p class="text-slate-500">Credentials: demo_admin / demo123</p>
      </div>

      <form method="POST" class="space-y-6">
        <input type="hidden" name="next" value="<?= e($next) ?>" />
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>" />
        <?php if ($error): ?>
          <div class="bg-red-50 border border-red-100 text-red-600 px-4 py-3 rounded-xl text-sm font-medium"><?= e($error) ?></div>
        <?php endif; ?>

        <div>
          <label class="block text-sm font-bold text-slate-700 mb-2">Username</label>
          <input name="username" type="text" autocomplete="username" value="demo_admin"
            class="w-full px-5 py-4 bg-slate-50 border border-slate-200 rounded-2xl focus:outline-none focus:ring-2 focus:ring-yellow-400 focus:border-transparent transition-all" />
        </div>

        <div>
          <label class="block text-sm font-bold text-slate-700 mb-2">Password</label>
          <input name="password" type="password" autocomplete="current-password" value="demo123"
            class="w-full px-5 py-4 bg-slate-50 border border-slate-200 rounded-2xl focus:outline-none focus:ring-2 focus:ring-yellow-400 focus:border-transparent transition-all" />
        </div>

        <button type="submit"
          class="w-full py-4 bg-yellow-500 text-white font-bold rounded-2xl hover:bg-yellow-600 shadow-xl shadow-yellow-100 transition-all active:scale-95">
          Login
        </button>
      </form>
    </div>
  </div>
</div>
<?php include __DIR__ . "/partials/footer.php"; ?>
