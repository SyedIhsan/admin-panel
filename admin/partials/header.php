<?php
declare(strict_types=1);
/** @var string $title */
$title = $title ?? "Demo Admin";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= e($title) ?></title>
  <link href="/img/demo_logo.svg" rel="icon">
  <!-- Tailwind CDN (same idea as student site) -->
  <script src="https://cdn.tailwindcss.com?plugins=typography,line-clamp,aspect-ratio"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css" rel="stylesheet">
  <link rel="stylesheet" href="/admin/elearning/assets/admin.css" />
</head>
<body class="bg-slate-50 text-slate-900 antialiased overflow-x-hidden"
      style="font-family: Inter, ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial;">
