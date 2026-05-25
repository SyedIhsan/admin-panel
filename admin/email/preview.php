<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth.php';
require_once rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/') . '/api/db_router.php';

$publicRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2)) ?: dirname(__DIR__, 2);
$layoutFile = $publicRoot . '/api/mail/layout.php';
if (is_file($layoutFile)) {
  require_once $layoutFile;
}

$customProductTemplateFile = $publicRoot . '/api/mail/templates/custom-product.php';
if (is_file($customProductTemplateFile)) {
  require_once $customProductTemplateFile;
}

$targetConn = (isset($conn) && $conn instanceof mysqli) ? $conn : null;

if ($targetConn instanceof mysqli) {
  $targetConn->set_charset('utf8mb4');
} elseif (function_exists('getBillingConn')) {
  $tmpConn = getBillingConn();
  if ($tmpConn instanceof mysqli) {
    $targetConn = $tmpConn;
    $targetConn->set_charset('utf8mb4');
  }
}

function pep_table_exists(mysqli $conn, string $table): bool {
  $table = trim($table);
  if ($table === '') return false;

  $safe = $conn->real_escape_string($table);
  $res = $conn->query("SHOW TABLES LIKE '{$safe}'");

  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function pep_column_exists(mysqli $conn, string $table, string $column): bool {
  $table = trim($table);
  $column = trim($column);
  if ($table === '' || $column === '') return false;

  $safeTable = $conn->real_escape_string($table);
  $safeColumn = $conn->real_escape_string($column);
  $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");

  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function pep_get(string $key, string $default = ''): string {
  return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

function pep_replace_vars(string $text, array $vars): string {
  return strtr($text, $vars);
}

function pep_html_text(string $text, array $vars): string {
  $text = pep_replace_vars($text, $vars);
  $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  return nl2br($text, false);
}

function pep_normalize_quill_list_html(string $html): string {
  $result = preg_replace_callback('/<(ol|ul)\b[^>]*>(.*?)<\/\1>/is', static function (array $m): string {
    $tag = strtolower((string)($m[1] ?? 'ol'));
    $inner = (string)($m[2] ?? '');

    if (!preg_match('/<li\b/i', $inner)) {
      return $m[0];
    }

    preg_match_all('/<li\b([^>]*)>(.*?)<\/li>/is', $inner, $matches, PREG_SET_ORDER);
    if (!$matches) {
      return $m[0];
    }

    $out = '';
    $buffer = [];
    $currentType = null;

    $flush = static function () use (&$out, &$buffer, &$currentType): void {
      if ($currentType === null || $buffer === []) {
        return;
      }

      $wrapper = $currentType === 'bullet' ? 'ul' : 'ol';
      $out .= '<' . $wrapper . '>' . implode('', $buffer) . '</' . $wrapper . '>';
      $buffer = [];
    };

    foreach ($matches as $liMatch) {
      $attrs = (string)($liMatch[1] ?? '');
      $content = (string)($liMatch[2] ?? '');

      $type = $tag === 'ul' ? 'bullet' : 'ordered';

      if (preg_match('/\sdata-list=(["\']?)(bullet|ordered|checked|unchecked)\1/i', $attrs, $mm)) {
        $value = strtolower((string)$mm[2]);
        $type = $value === 'bullet' ? 'bullet' : 'ordered';
      }

      if ($currentType !== null && $type !== $currentType) {
        $flush();
      }

      $currentType = $type;
      $buffer[] = '<li>' . $content . '</li>';
    }

    $flush();

    return $out !== '' ? $out : $m[0];
  }, $html);

  return is_string($result) ? $result : $html;
}

function pep_compact_email_spacing(string $html): string {
  $html = preg_replace(
    '/<p>\s*(?:<br>\s*|&nbsp;\s*)<\/p>/i',
    '<div style="height:4px;line-height:4px;font-size:4px;">&nbsp;</div>',
    $html
  );

  $html = preg_replace('/<p>/i', '<p style="margin:0 0 8px 0;line-height:1.42;">', $html);
  $html = preg_replace('/<ul>/i', '<ul style="margin:0 0 8px 18px;padding:0;line-height:1.42;">', $html);
  $html = preg_replace('/<ol>/i', '<ol style="margin:0 0 8px 18px;padding:0;line-height:1.42;">', $html);
  $html = preg_replace('/<li>/i', '<li style="margin:0 0 4px 0;">', $html);
  $html = preg_replace('/(?:<br>\s*){3,}/i', '<br><br>', $html);

  return trim($html);
}

function pep_rich_text(string $text, array $vars): string {
  $text = pep_replace_vars($text, $vars);
  $text = str_replace(["\r\n", "\r"], "\n", $text);

  $anchors = [];

  $text = preg_replace_callback(
    '/<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is',
    static function (array $m) use (&$anchors): string {
      $href = trim(html_entity_decode((string)$m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
      if (!preg_match('~^(https?://|mailto:)~i', $href)) {
        $href = '#';
      }

      $label = strip_tags((string)$m[3], '<strong><em><u><br>');

      $key = '%%ANCHOR_' . count($anchors) . '%%';
      $anchors[$key] = '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>';

      return $key;
    },
    $text
  );

  $text = strip_tags($text, '<p><br><strong><em><u><ul><ol><li>');
  $text = str_ireplace(['<b>', '</b>', '<i>', '</i>'], ['<strong>', '</strong>', '<em>', '</em>'], $text);
  $text = pep_normalize_quill_list_html($text);
  $text = preg_replace('/<(\/?)(p|br|strong|em|u|ul|ol|li)(\s[^>]*)?>/i', '<$1$2>', $text);
  $text = preg_replace('/<br\s*\/?>/i', '<br>', $text);
  $text = strtr($text, $anchors);

  if (!preg_match('/<(p|ul|ol|li|br)\b/i', $text)) {
    $text = nl2br($text, false);
  }

  return pep_compact_email_spacing($text);
}

function pep_find_target(?mysqli $conn, string $targetId): ?array {
  if (!$conn instanceof mysqli) return null;
  if ($targetId === '' || $targetId === '__default') return null;
  if (!pep_table_exists($conn, 'Products')) return null;

  // Variant target
  if (str_starts_with($targetId, 'variant:') && pep_table_exists($conn, 'Product_Categories')) {
    $variantId = trim(substr($targetId, 8));
    if ($variantId === '') return null;

    $stmt = $conn->prepare("
      SELECT
        pc.id,
        pc.name AS variant_name,
        pc.price_modifier,
        p.id AS product_id,
        p.name AS item_title,
        p.base_price
      FROM `Product_Categories` pc
      JOIN `Products` p ON p.id = pc.product_id
      WHERE pc.id = ?
      LIMIT 1
    ");
    if (!$stmt) return null;

    $stmt->bind_param('s', $variantId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!is_array($row)) return null;

    $basePrice = (float)($row['base_price'] ?? 0);
    $modifier = (float)($row['price_modifier'] ?? 0);
    $row['item_price'] = $basePrice + $modifier;

    return $row;
  }

  // Product target
  $productId = $targetId;
  if (str_starts_with($targetId, 'product:')) {
    $productId = trim(substr($targetId, 8));
  }

  $productId = trim($productId);
  if ($productId === '') return null;

  $nameExpr = 'NULL AS item_title';
  if (pep_column_exists($conn, 'Products', 'name')) {
    $nameExpr = 'name AS item_title';
  } elseif (pep_column_exists($conn, 'Products', 'title')) {
    $nameExpr = 'title AS item_title';
  } elseif (pep_column_exists($conn, 'Products', 'product_name')) {
    $nameExpr = 'product_name AS item_title';
  }

  $priceExpr = 'NULL AS item_price';
  if (pep_column_exists($conn, 'Products', 'base_price')) {
    $priceExpr = 'base_price AS item_price';
  } elseif (pep_column_exists($conn, 'Products', 'price')) {
    $priceExpr = 'price AS item_price';
  }

  $stmt = $conn->prepare("
    SELECT
      id AS product_id,
      {$nameExpr},
      {$priceExpr}
    FROM `Products`
    WHERE id = ?
    LIMIT 1
  ");
  if (!$stmt) return null;

  $stmt->bind_param('s', $productId);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();

  return is_array($row) ? $row : null;
}

$targetId = pep_get('target_id', '__default');
$target = pep_find_target($targetConn, $targetId);

$productName = trim((string)($target['item_title'] ?? 'Premium Product'));
$variantName = trim((string)($target['variant_name'] ?? ''));

if ($variantName !== '') {
  $productName .= ' (' . $variantName . ')';
}

$productPrice = isset($target['item_price']) && $target['item_price'] !== null
  ? 'RM ' . number_format((float)$target['item_price'], 2)
  : 'RM --';

$subject = pep_get('subject', 'A special update for your order 🎁');
$preheader = pep_get('preheader', 'We prepared something extra for your recent purchase.');
$badgeText = pep_get('badge_text', 'Customer Update');
$greeting = pep_get('greeting', 'Hi {{customer_name}},');
$body = pep_get('body', "Thank you for your purchase of {{product_name}}.\n\nWe have prepared an extra update, bonus, or follow-up message for you. Click the button below to continue.");
$buttonText = pep_get('button_text', 'View Details');
$buttonUrl = pep_get('button_url', 'https://example.com/');
$closing = pep_get('closing', "Best regards,\nDemo Team");
$brandName = pep_get('brand_name', 'Demo');
$brandEmail = pep_get('brand_email', 'noreply@demo.local');
$supportEmail = pep_get('support_email', 'support@demo.local');
$footerNote = pep_get('footer_note', 'You are receiving this email because of your recent purchase of {{product_name}}.');
$enabled = pep_get('enabled', '1') === '1';

$buttonUrlTemplate = $buttonUrl !== '' ? $buttonUrl : 'https://example.com/';

$vars = [
  '{{customer_name}}'   => 'Jane Doe',
  '{{name}}'            => 'Jane Doe',
  '{{order_id}}'        => 'TEST-ORDER-001',
  '{{transaction_ref}}' => 'TEST-TXN-001',
  '{{price}}'           => str_replace('RM ', '', $productPrice),
  '{{product_price}}'   => str_replace('RM ', '', $productPrice),
  '{{amount}}'          => $productPrice,
  '{{date}}'            => date('d M Y, h:i A'),
  '{{product}}'         => $productName,
  '{{product_name}}'    => $productName,
  '{{product_type}}'    => $targetId,
  '{{email}}'           => 'jane@example.com',
  '{{phone}}'           => '0123456789',
  '{{package}}'         => $variantName,
  '{{ticket_count}}'    => '',
  '{{action_url}}'      => $buttonUrlTemplate,
  '{{support_email}}'   => $supportEmail !== '' ? $supportEmail : 'support@demo.local',
];

$resolvedButtonUrl = pep_replace_vars($buttonUrlTemplate, $vars);
$vars['{{action_url}}'] = $resolvedButtonUrl;

$resolvedSubject = pep_replace_vars($subject, $vars);
$resolvedPreheader = pep_replace_vars($preheader, $vars);
$resolvedBadgeText = pep_replace_vars($badgeText, $vars);
$resolvedBrandName = pep_replace_vars($brandName, $vars);
$resolvedBrandEmail = pep_replace_vars($brandEmail, $vars);
$resolvedSupportEmail = pep_replace_vars($supportEmail, $vars);

$greetingHtml = pep_html_text($greeting, $vars);
$contentHtml = pep_rich_text($body, $vars);
$closingHtml = pep_html_text($closing, $vars);
$resolvedFooterNote = pep_replace_vars($footerNote, $vars);

$selectedProductHtml = '
  <div style="
    margin:18px 0 24px 0;
    padding:18px;
    border-radius:18px;
    background:#111113;
    border:1px solid rgba(255,255,255,.08);
  ">
    <div style="
      font-size:12px;
      line-height:1.4;
      color:#a1a1aa;
      font-weight:800;
      text-transform:uppercase;
      letter-spacing:.12em;
    ">Selected Product</div>

    <div style="
      margin-top:8px;
      font-size:20px;
      line-height:1.3;
      color:#ffffff;
      font-weight:800;
    ">' . htmlspecialchars($productName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>

    <div style="
      margin-top:6px;
      font-size:14px;
      line-height:1.6;
      color:#a1a1aa;
      font-weight:600;
    ">' . htmlspecialchars($productPrice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>
  </div>';

$buttonHtml = '';
if ($enabled && $buttonText !== '' && $resolvedButtonUrl !== '') {
  $buttonHtml = '
    <div style="margin:0 0 24px 0;">
      <a href="' . htmlspecialchars($resolvedButtonUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"
         style="
           display:inline-block;
           padding:14px 22px;
           border-radius:14px;
           background:linear-gradient(135deg,#f59e0b,#fbbf24);
           color:#111827;
           text-decoration:none;
           font-size:15px;
           line-height:1.1;
           font-weight:800;
           box-shadow:0 14px 28px rgba(245,158,11,.28);
         ">
        ' . htmlspecialchars(pep_replace_vars($buttonText, $vars), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '
      </a>
    </div>';
}

$bodyHtml = '';
$bodyHtml .= '<h1>' . htmlspecialchars($resolvedSubject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>';
$bodyHtml .= '<div style="margin:0 0 20px 0;line-height:1.5;">' . $greetingHtml . '</div>';
$bodyHtml .= '<div style="margin:0 0 24px 0;line-height:1.58;">' . $contentHtml . '</div>';
$bodyHtml .= $selectedProductHtml;
$bodyHtml .= $buttonHtml;
$bodyHtml .= '<div style="margin:0 0 18px 0;color:#d4d4d8;line-height:1.5;">' . $closingHtml . '</div>';

$footerNoteHtml = '<div style="font-size:12px;line-height:1.6;color:#71717a;">'
  . pep_html_text($footerNote, $vars)
  . '</div>';

if (function_exists('buildCustomProductEmail')) {
  echo buildCustomProductEmail([
    'subject'               => $resolvedSubject,
    'preheader'             => $resolvedPreheader,
    'badge_text'            => $resolvedBadgeText,
    'greeting_html'         => $greetingHtml,
    'content_html'          => $contentHtml,
    'selected_product_html' => $selectedProductHtml,
    'media_html'            => '',
    'button_html'           => $buttonHtml,
    'closing_html'          => $closingHtml,
    'brand_name'            => $resolvedBrandName,
    'brand_email'           => $resolvedBrandEmail,
    'support_email'         => $resolvedSupportEmail,
    'footer_note'           => $resolvedFooterNote,
  ]);
  exit;
}

if (function_exists('buildMailLayout')) {
  echo buildMailLayout([
    'subject'          => $resolvedSubject,
    'preheader'        => $resolvedPreheader,
    'body_html'        => $bodyHtml,
    'brand_name'       => $resolvedBrandName,
    'brand_email'      => $resolvedBrandEmail,
    'support_email'    => $resolvedSupportEmail,
    'badge_text'       => $resolvedBadgeText,
    'footer_note_html' => $footerNoteHtml,

    'bg'               => '#09090b',
    'card'             => '#18181b',
    'card2'            => '#111113',
    'hero_bg'          => '#0b0b0e',
    'border'           => 'rgba(255,255,255,.06)',
    'border2'          => 'rgba(255,255,255,.10)',
    'text'             => '#ffffff',
    'muted'            => '#a1a1aa',
    'muted2'           => '#71717a',
    'muted3'           => '#52525b',
    'accent'           => '#f59e0b',
    'accent2'          => '#fbbf24',
  ]);
  exit;
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($resolvedSubject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
</head>
<body style="font-family:Inter,Arial,sans-serif;background:#09090b;margin:0;padding:40px;color:#ffffff;">
  <div style="max-width:760px;margin:0 auto;background:#18181b;border:1px solid rgba(255,255,255,.06);border-radius:24px;overflow:hidden;box-shadow:0 28px 70px rgba(0,0,0,.60);">
    <div style="height:8px;background:linear-gradient(90deg,#f59e0b,#fbbf24,#f59e0b);"></div>
    <div style="padding:32px;">
      <?= $bodyHtml ?>
    </div>
  </div>
</body>
</html>