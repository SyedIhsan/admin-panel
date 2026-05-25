<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/auth.php';
require_once rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/api/db_router.php";

$payConn = (isset($conn) && $conn instanceof mysqli) ? $conn : null;

if (!$payConn instanceof mysqli) {
  http_response_code(500);
  exit('Main product database is unavailable.');
}

ce_ensure_email_templates_schema($payConn);

$templateId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = false;

$formData = [
  'product_type'        => '',
  'product_category_id' => '',
  'target_scope'        => '',
  'variant_name'        => '',
  'subject'             => '',
  'preheader'           => '',
  'badge_text'          => '',
  'greeting'            => '',
  'content'             => '',
  'media_id'            => '',
  'button_link'         => '',
  'button_text'         => 'Click Here',
  'closing'             => '',
  'brand_name' => 'Demo',
  'brand_email'         => 'noreply@demo.local',
  'support_email'       => 'support@demo.local',
  'footer_note'         => '',
  'is_active'           => 1,
];

if ($templateId > 0) {
  $stmt = $payConn->prepare("
    SELECT
      id,
      product_type,
      product_category_id,
      target_scope,
      variant_name,
      subject,
      preheader,
      badge_text,
      greeting,
      content,
      media_id,
      button_link,
      button_text,
      closing,
      brand_name,
      brand_email,
      support_email,
      footer_note,
      is_active
    FROM `email_templates`
    WHERE id = ?
    LIMIT 1
  ");

  if ($stmt) {
    $stmt->bind_param('i', $templateId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if ($row) {
      $isEdit = true;
      $formData = [
        'product_type'        => (string)($row['product_type'] ?? ''),
        'product_category_id' => (string)($row['product_category_id'] ?? ''),
        'target_scope'        => (string)($row['target_scope'] ?? ''),
        'variant_name'        => (string)($row['variant_name'] ?? ''),
        'subject'             => (string)($row['subject'] ?? ''),
        'preheader'           => (string)($row['preheader'] ?? ''),
        'badge_text'          => (string)($row['badge_text'] ?? ''),
        'greeting'            => (string)($row['greeting'] ?? ''),
        'content'             => (string)($row['content'] ?? ''),
        'media_id'            => (string)($row['media_id'] ?? ''),
        'button_link'         => (string)($row['button_link'] ?? ''),
        'button_text'         => (string)($row['button_text'] ?? 'Click Here'),
        'closing'             => (string)($row['closing'] ?? ''),
        'brand_name'          => (string)($row['brand_name'] ?? 'Demo'),
        'brand_email'         => (string)($row['brand_email'] ?? 'noreply@demo.local'),
        'support_email'       => (string)($row['support_email'] ?? 'support@demo.local'),
        'footer_note'         => (string)($row['footer_note'] ?? ''),
        'is_active'           => (int)($row['is_active'] ?? 1),
      ];
    }
  }
}

function ce_table_exists(mysqli $conn, string $table): bool {
  $table = trim($table);
  if ($table === '') return false;
  $safe = $conn->real_escape_string($table);
  $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function ce_column_exists(mysqli $conn, string $table, string $column): bool {
  $table = trim($table);
  $column = trim($column);
  if ($table === '' || $column === '') return false;

  $safeTable = $conn->real_escape_string($table);
  $safeColumn = $conn->real_escape_string($column);

  $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function ce_index_exists(mysqli $conn, string $table, string $index): bool {
  $table = trim($table);
  $index = trim($index);

  if ($table === '' || $index === '') {
    return false;
  }

  $safeTable = $conn->real_escape_string($table);
  $safeIndex = $conn->real_escape_string($index);

  $res = $conn->query("SHOW INDEX FROM `{$safeTable}` WHERE Key_name = '{$safeIndex}'");
  return $res instanceof mysqli_result && $res->num_rows > 0;
}

function ce_ensure_email_templates_schema(mysqli $conn): void {
  if (!ce_table_exists($conn, 'email_templates')) {
    return;
  }

  if (!ce_column_exists($conn, 'email_templates', 'product_category_id')) {
    $conn->query("ALTER TABLE `email_templates` ADD COLUMN `product_category_id` VARCHAR(60) NOT NULL DEFAULT '' AFTER `product_type`");
  }

  if (!ce_column_exists($conn, 'email_templates', 'target_scope')) {
    $conn->query("ALTER TABLE `email_templates` ADD COLUMN `target_scope` ENUM('default','product','variant') NOT NULL DEFAULT 'product' AFTER `product_category_id`");
  }

  if (!ce_column_exists($conn, 'email_templates', 'variant_name')) {
    $conn->query("ALTER TABLE `email_templates` ADD COLUMN `variant_name` VARCHAR(150) NULL AFTER `product_name`");
  }

  $conn->query("UPDATE `email_templates` SET `product_category_id` = '' WHERE `product_category_id` IS NULL");
  $conn->query("ALTER TABLE `email_templates` MODIFY `product_category_id` VARCHAR(60) NOT NULL DEFAULT ''");

  if (ce_index_exists($conn, 'email_templates', 'uq_product_type')) {
    $conn->query("ALTER TABLE `email_templates` DROP INDEX `uq_product_type`");
  }

  if (ce_index_exists($conn, 'email_templates', 'uq_product_type_name')) {
    $conn->query("ALTER TABLE `email_templates` DROP INDEX `uq_product_type_name`");
  }

  if (!ce_index_exists($conn, 'email_templates', 'uq_template_target')) {
    $conn->query("ALTER TABLE `email_templates` ADD UNIQUE KEY `uq_template_target` (`target_scope`, `product_type`, `product_category_id`)");
  }
}

function ce_default_template(?array $target = null): array {
  $productPrice = trim((string)($target['price'] ?? ''));
  $priceLine    = $productPrice !== '' ? ('RM ' . $productPrice) : 'your recent purchase';

  return [
    'subject'       => 'A special update for your order 🎁',
    'preheader'     => 'We prepared something extra for your recent purchase.',
    'badge_text'    => 'Customer Update',
    'greeting'      => 'Hi {{customer_name}},',
    'body'          => "Thank you for your purchase of {{product_name}}.\n\nWe have prepared an extra update, bonus, or follow-up message for you. Click the button below to continue.",
    'button_text'   => 'View Details',
    'button_url'    => 'https://example.com/',
    'closing'       => "Best regards,\nDemo Team",
    'brand_name' => 'Demo',
    'brand_email'   => 'noreply@demo.local',
    'support_email' => 'support@demo.local',
    'footer_note'   => 'You are receiving this email because of your recent purchase of {{product_name}} (' . $priceLine . ').',
    'updated_at'    => '',
    'updated_by'    => '',
  ];
}

function ce_load_targets(mysqli $payConn): array {
  $groups = [
    'default' => [
      'id' => '__default',
      'title' => 'All Products (Default)',
      'type' => 'default',
      'product_id' => '',
      'product_category_id' => '',
      'target_scope' => 'default',
      'variant_name' => '',
    ],
    'products' => [],
  ];

  if (!ce_table_exists($payConn, 'Products')) {
    return $groups;
  }

  $hasElearningCourseCol = ce_column_exists($payConn, 'Products', 'elearning_course_id');
  $hasCategoriesTable = ce_table_exists($payConn, 'Product_Categories');
  $hasCatVariantTypeCol = $hasCategoriesTable && ce_column_exists($payConn, 'Product_Categories', 'variant_type');

  $sql = "
    SELECT
      p.id,
      p.name,
      " . ($hasElearningCourseCol ? "p.elearning_course_id" : "NULL AS elearning_course_id") . ",
      " . ($hasCatVariantTypeCol ? "
      EXISTS (
        SELECT 1
        FROM `Product_Categories` pc
        WHERE pc.product_id = p.id
          AND LOWER(TRIM(pc.variant_type)) = 'elearning'
      ) AS has_elearning_variant
      " : "0 AS has_elearning_variant") . "
    FROM `Products` p
    ORDER BY p.name ASC, p.id DESC
  ";

  $res = $payConn->query($sql);
  if (!$res instanceof mysqli_result) {
    return $groups;
  }

  $products = [];
  while ($row = $res->fetch_assoc()) {
    $productId = trim((string)($row['id'] ?? ''));
    if ($productId === '') {
      continue;
    }

    $productName = trim((string)($row['name'] ?? 'Untitled Product'));
    $linkedCourseId = trim((string)($row['elearning_course_id'] ?? ''));
    $hasElearningVariant = (int)($row['has_elearning_variant'] ?? 0) === 1;

    $productType = ($linkedCourseId !== '' || $hasElearningVariant)
      ? 'elearning_product'
      : 'standard_product';

    $products[$productId] = [
      'group_label' => $productName,
      'type' => $productType,
      'items' => [
        [
          'id' => 'product:' . $productId,
          'title' => 'Product Default',
          'type' => $productType,
          'product_id' => $productId,
          'product_category_id' => '',
          'target_scope' => 'product',
          'variant_name' => '',
          'product_name' => $productName,
        ],
      ],
    ];
  }
  $res->close();

  if ($hasCategoriesTable && !empty($products)) {
    $sqlVariants = "
      SELECT
        pc.id,
        pc.product_id,
        pc.name,
        " . ($hasCatVariantTypeCol ? "pc.variant_type" : "'normal' AS variant_type") . "
      FROM `Product_Categories` pc
      ORDER BY pc.product_id ASC, pc.name ASC, pc.id DESC
    ";

    $varRes = $payConn->query($sqlVariants);
    if ($varRes instanceof mysqli_result) {
      while ($varRow = $varRes->fetch_assoc()) {
        $variantId = trim((string)($varRow['id'] ?? ''));
        $productId = trim((string)($varRow['product_id'] ?? ''));
        $variantName = trim((string)($varRow['name'] ?? 'Variant'));
        $variantType = strtolower(trim((string)($varRow['variant_type'] ?? 'normal')));

        if ($variantId === '' || $productId === '' || !isset($products[$productId])) {
          continue;
        }

        $products[$productId]['items'][] = [
          'id' => 'variant:' . $variantId,
          'title' => $variantName,
          'type' => $variantType === 'elearning' ? 'elearning_product' : 'standard_product',
          'product_id' => $productId,
          'product_category_id' => $variantId,
          'target_scope' => 'variant',
          'variant_name' => $variantName,
          'product_name' => $products[$productId]['group_label'],
        ];
      }
      $varRes->close();
    }
  }

  uasort($products, static function (array $a, array $b): int {
    return strcasecmp(
      trim((string)($a['group_label'] ?? '')),
      trim((string)($b['group_label'] ?? ''))
    );
  });

  $groups['products'] = $products;
  return $groups;
}

function ce_find_target(array $targets, string $selectedTargetId): ?array {
  foreach ($targets as $target) {
    if ((string)($target['id'] ?? '') === $selectedTargetId) {
      return $target;
    }
  }
  return $targets[0] ?? null;
}

function ce_post(string $key, string $default = ''): string {
  return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function ce_normalize_quill_list_html(string $html): string {
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

function ce_sanitize_editor_html(string $html): string {
  $html = trim($html);
  if ($html === '') return '';

  $allowed = '<p><br><strong><em><u><ul><ol><li><a>';
  $clean = strip_tags($html, $allowed);

  $clean = str_ireplace(['<b>', '</b>', '<i>', '</i>'], ['<strong>', '</strong>', '<em>', '</em>'], $clean);
  $clean = ce_normalize_quill_list_html($clean);

  $clean = preg_replace('/<(\/?)(p|br|strong|em|u|ul|ol|li)(\s[^>]*)?>/i', '<$1$2>', $clean);
  $clean = preg_replace('/<br\s*\/?>/i', '<br>', $clean);

  $clean = preg_replace_callback('/<a\b([^>]*)>(.*?)<\/a>/is', static function (array $m): string {
    $attrs = (string)($m[1] ?? '');
    $label = strip_tags((string)($m[2] ?? ''), '<strong><em><u><br>');
    $href = '';

    if (preg_match('/\shref\s*=\s*(["\'])(.*?)\1/i', $attrs, $mm)) {
      $href = trim(html_entity_decode((string)$mm[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    if (!preg_match('~^(https?://|mailto:)~i', $href)) {
      $href = '#';
    }

    return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>';
  }, $clean);

  return $clean;
}

$targetData = ce_load_targets($payConn);
$defaultTarget = $targetData['default'] ?? null;
$productGroups = $targetData['products'] ?? [];

$targets = [];
$variantsByProduct = [];

if (is_array($defaultTarget)) {
  $targets[] = $defaultTarget;
}

foreach ($productGroups as $productId => $group) {
  $groupItems = is_array($group['items'] ?? null) ? $group['items'] : [];

  $variantsByProduct[$productId] = [];

  foreach ($groupItems as $index => $item) {
    $targets[] = $item;

    if ($index > 0) {
      $variantsByProduct[$productId][] = $item;
    }
  }
}

$targetIds = array_map(static fn(array $item): string => (string)($item['id'] ?? ''), $targets);

$selectedTargetId = trim((string)($_GET['target'] ?? $_POST['target_id'] ?? '__default'));
if (!in_array($selectedTargetId, $targetIds, true)) {
  $selectedTargetId = '__default';
}

$selectedTarget = ce_find_target($targets, $selectedTargetId);
$flash = null;

if ($isEdit) {
  $dbProductType = trim((string)($formData['product_type'] ?? ''));
  $dbProductCategoryId = trim((string)($formData['product_category_id'] ?? ''));
  $dbTargetScope = trim((string)($formData['target_scope'] ?? ''));

  if ($dbTargetScope === 'variant' && $dbProductCategoryId !== '') {
    $selectedTargetId = 'variant:' . $dbProductCategoryId;
  } elseif ($dbTargetScope === 'product' && $dbProductType !== '' && $dbProductType !== '__default') {
    $selectedTargetId = 'product:' . $dbProductType;
  } else {
    $selectedTargetId = '__default';
  }

  if (!in_array($selectedTargetId, $targetIds, true)) {
    $selectedTargetId = '__default';
  }

  $selectedTarget = ce_find_target($targets, $selectedTargetId) ?? $selectedTarget;
}

$template = ce_default_template($selectedTarget);
$template['enabled'] = '1';

$selectedProductId = '__default';
$selectedVariantId = '';

if ($selectedTargetId === '__default') {
  $selectedProductId = '__default';
} elseif (str_starts_with($selectedTargetId, 'product:')) {
  $selectedProductId = trim((string)($selectedTarget['product_id'] ?? ''));
} elseif (str_starts_with($selectedTargetId, 'variant:')) {
  $selectedProductId = trim((string)($selectedTarget['product_id'] ?? ''));
  $selectedVariantId = trim((string)($selectedTarget['product_category_id'] ?? ''));
}

$standardProductOptions = [];
$elearningProductOptions = [];

foreach ($productGroups as $productId => $group) {
  $groupLabel = trim((string)($group['group_label'] ?? 'Untitled Product'));
  $groupType = trim((string)($group['type'] ?? 'standard_product'));

  if ($groupLabel === '') {
    continue;
  }

  $option = [
    'product_id' => (string)$productId,
    'title' => $groupLabel,
  ];

  if ($groupType === 'elearning_product') {
    $elearningProductOptions[] = $option;
  } else {
    $standardProductOptions[] = $option;
  }
}

$sortProductOptions = static function (array $a, array $b): int {
  return strcasecmp(
    trim((string)($a['title'] ?? '')),
    trim((string)($b['title'] ?? ''))
  );
};

usort($standardProductOptions, $sortProductOptions);
usort($elearningProductOptions, $sortProductOptions);

if ($isEdit) {
  $template['subject']       = (string)$formData['subject'];
  $template['preheader']     = (string)$formData['preheader'];
  $template['badge_text']    = (string)$formData['badge_text'];
  $template['greeting']      = (string)$formData['greeting'];
  $template['body']          = (string)$formData['content'];
  $template['button_text']   = (string)$formData['button_text'];
  $template['button_url']    = (string)$formData['button_link'];
  $template['closing']       = (string)$formData['closing'];
  $template['brand_name']    = (string)$formData['brand_name'];
  $template['brand_email']   = (string)$formData['brand_email'];
  $template['support_email'] = (string)$formData['support_email'];
  $template['footer_note']   = (string)$formData['footer_note'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate();

  $templateId = (int)($_POST['id'] ?? 0);
  $selectedTargetId = trim((string)($_POST['target_id'] ?? ''));
  if (!in_array($selectedTargetId, $targetIds, true)) {
    $selectedTargetId = '__default';
  }

  $selectedTarget = ce_find_target($targets, $selectedTargetId);

  $productType = '';
  $productCategoryId = '';
  $targetScope = 'product';
  $variantName = null;

  if (str_starts_with($selectedTargetId, 'product:')) {
    $productType = trim(substr($selectedTargetId, 8));
    $productCategoryId = '';
    $targetScope = 'product';
  } elseif (str_starts_with($selectedTargetId, 'variant:')) {
    $productCategoryId = trim(substr($selectedTargetId, 8));
    $targetScope = 'variant';
    $productType = trim((string)($selectedTarget['product_id'] ?? ''));
    $variantName = trim((string)($selectedTarget['variant_name'] ?? ''));
  } elseif ($selectedTargetId === '__default') {
    $productType = '__default';
    $productCategoryId = '';
    $targetScope = 'default';
  } else {
    $productType = trim($selectedTargetId);
    $productCategoryId = '';
  }

  $subject    = ce_post('subject');
  $preheader    = ce_post('preheader');
  $badgeText    = ce_post('badge_text');
  $greeting     = ce_post('greeting');
  $content    = ce_sanitize_editor_html(ce_post('body'));
  $buttonText = ce_post('button_text');
  $buttonLink = ce_post('button_url');
  $closing      = ce_post('closing');
  $brandName    = ce_post('brand_name', 'Demo');
  $brandEmail   = ce_post('brand_email', 'noreply@demo.local');
  $supportEmail = ce_post('support_email', 'support@demo.local');
  $footerNote   = ce_post('footer_note');
  $mediaId    = null;
  $isActive   = 1;

  $category = 'both';
  if ($selectedTargetId !== '__default') {
    $category = (($selectedTarget['type'] ?? '') === 'elearning_product') ? 'elearning' : 'non_elearning';
  }

  if ($selectedTargetId === '__default') {
    $productName = 'All Products (Default)';
  } else {
    $productName = trim((string)($selectedTarget['product_name'] ?? $selectedTarget['title'] ?? 'Untitled Product'));
  }

  $variantName = $targetScope === 'variant'
    ? trim((string)($selectedTarget['variant_name'] ?? ''))
    : null;

  $lastUpdatedBy = trim((string)(
    $_SESSION['admin_email']
    ?? $_SESSION['admin_username']
    ?? $_SESSION['admin_name']
    ?? $_SESSION['admin_id']
    ?? 'admin'
  ));

  if ($productType === '') {
    $flash = ['type' => 'error', 'message' => 'Target product cannot be empty.'];
  } elseif ($subject === '') {
    $flash = ['type' => 'error', 'message' => 'The subject line cannot be empty.'];
  } elseif ($content === '') {
    $flash = ['type' => 'error', 'message' => 'The email body cannot be empty.'];
  } else {
    if ($templateId > 0) {
      $stmt = $payConn->prepare("
        UPDATE `email_templates`
        SET product_type = ?,
            product_category_id = ?,
            target_scope = ?,
            category = ?,
            product_name = ?,
            variant_name = ?,
            subject = ?,
            preheader = ?,
            badge_text = ?,
            greeting = ?,
            content = ?,
            media_id = ?,
            button_link = ?,
            button_text = ?,
            closing = ?,
            brand_name = ?,
            brand_email = ?,
            support_email = ?,
            footer_note = ?,
            is_active = ?,
            last_updated_by = ?,
            updated_at = NOW()
        WHERE id = ?
        LIMIT 1
      ");

      if ($stmt) {
        $stmt->bind_param(
          'sssssssssssssssssssisi',
          $productType,
          $productCategoryId,
          $targetScope,
          $category,
          $productName,
          $variantName,
          $subject,
          $preheader,
          $badgeText,
          $greeting,
          $content,
          $mediaId,
          $buttonLink,
          $buttonText,
          $closing,
          $brandName,
          $brandEmail,
          $supportEmail,
          $footerNote,
          $isActive,
          $lastUpdatedBy,
          $templateId
        );

        try {
          if ($stmt->execute()) {
            header('Location: /admin/email/email-templates.php?updated=1', true, 303);
            exit;
          } else {
            $flash = ['type' => 'error', 'message' => 'Failed to update template: ' . $stmt->error];
          }
        } catch (mysqli_sql_exception $e) {
          if ((int)$e->getCode() === 1062) {
            $flash = ['type' => 'error', 'message' => 'Another template already exists for this exact product target or variant.'];
          } else {
            throw $e;
          }
        }
        $stmt->close();
      } else {
        $flash = ['type' => 'error', 'message' => 'Failed to prepare the update query: ' . $payConn->error];
      }
    } else {
      $stmt = $payConn->prepare("
        INSERT INTO `email_templates`
          (
            product_type,
            product_category_id,
            target_scope,
            category,
            product_name,
            variant_name,
            subject,
            preheader,
            badge_text,
            greeting,
            content,
            media_id,
            button_link,
            button_text,
            closing,
            brand_name,
            brand_email,
            support_email,
            footer_note,
            is_active,
            last_updated_by,
            updated_at
          )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
      ");

      if ($stmt) {
        $stmt->bind_param(
          'sssssssssssssssssssis',
          $productType,
          $productCategoryId,
          $targetScope,
          $category,
          $productName,
          $variantName,
          $subject,
          $preheader,
          $badgeText,
          $greeting,
          $content,
          $mediaId,
          $buttonLink,
          $buttonText,
          $closing,
          $brandName,
          $brandEmail,
          $supportEmail,
          $footerNote,
          $isActive,
          $lastUpdatedBy
        );

        try {
          if ($stmt->execute()) {
            header('Location: /admin/email/email-templates.php?saved=1', true, 303);
            exit;
          } else {
            $flash = ['type' => 'error', 'message' => 'Failed to save template: ' . $stmt->error];
          }
        } catch (mysqli_sql_exception $e) {
          if ((int)$e->getCode() === 1062) {
            $flash = ['type' => 'error', 'message' => 'A template already exists for this exact product target or variant.'];
          } else {
            throw $e;
          }
        }
        $stmt->close();
      } else {
        $flash = ['type' => 'error', 'message' => 'Failed to prepare the insert query: ' . $payConn->error];
      }
    }
  }

  $template = array_merge($template, [
    'subject'       => $subject,
    'preheader'     => $preheader,
    'badge_text'    => $badgeText,
    'greeting'      => $greeting,
    'body'          => $content,
    'button_text'   => $buttonText,
    'button_url'    => $buttonLink,
    'closing'       => $closing,
    'brand_name'    => $brandName,
    'brand_email'   => $brandEmail,
    'support_email' => $supportEmail,
    'footer_note'   => $footerNote,
  ]);
}

$title = 'Custom Email';
$pageTitle = 'Custom Email';
$pageDesc = 'Manage automated email templates and preview them before sending.';

$backUrl = '/admin/email/email-templates.php';
$backLabel = 'Back to Templates';

$headerActionsHtmlDesktop = '
  ' . ($isEdit ? '
  <a href="' . e($backUrl) . '"
    class="flex items-center gap-2 px-4 py-2 text-slate-600 hover:text-slate-900 font-bold transition-all group">
    <svg class="w-5 h-5 transition-transform group-hover:-translate-x-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
    </svg>
    ' . e($backLabel) . '
  </a>
  ' : '') . '

  <button type="submit" form="emailTemplateForm" class="inline-flex items-center gap-2 px-4 py-3 rounded-2xl bg-yellow-500 text-white font-bold text-sm shadow-lg shadow-yellow-100 hover:bg-yellow-400 transition">
    <svg class="w-4 h-4 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512" aria-hidden="true" fill="currentColor">
      <path d="M64 32C28.7 32 0 60.7 0 96v320c0 35.3 28.7 64 64 64h320c35.3 0 64-28.7 64-64V173.3c0-17-6.7-33.3-18.7-45.3L352 50.7c-12-12-28.3-18.7-45.3-18.7zm32 96c0-17.7 14.3-32 32-32h160c17.7 0 32 14.3 32 32v64c0 17.7-14.3 32-32 32H128c-17.7 0-32-14.3-32-32zm128 160a64 64 0 1 1 0 128a64 64 0 1 1 0-128"/>
    </svg>
    Save Template
  </button>';

$headerActionsHtmlMobile = '
  ' . ($isEdit ? '
  <a href="' . e($backUrl) . '"
    class="inline-flex items-center justify-center w-11 h-11 rounded-2xl border border-slate-200 bg-white text-slate-700 shadow-sm hover:bg-slate-50 transition"
    title="' . e($backLabel) . '" aria-label="' . e($backLabel) . '">
    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
    </svg>
  </a>
  ' : '') . '

  <button type="submit" form="emailTemplateForm" class="inline-flex items-center justify-center w-11 h-11 rounded-2xl bg-yellow-500 text-white shadow-lg shadow-yellow-100 hover:bg-yellow-400 transition">
    <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512" aria-hidden="true" fill="currentColor">
      <path d="M64 32C28.7 32 0 60.7 0 96v320c0 35.3 28.7 64 64 64h320c35.3 0 64-28.7 64-64V173.3c0-17-6.7-33.3-18.7-45.3L352 50.7c-12-12-28.3-18.7-45.3-18.7zm32 96c0-17.7 14.3-32 32-32h160c17.7 0 32 14.3 32 32v64c0 17.7-14.3 32-32 32H128c-17.7 0-32-14.3-32-32zm128 160a64 64 0 1 1 0 128a64 64 0 1 1 0-128"/>
    </svg>
  </button>';

include dirname(__DIR__) . '/partials/header.php';
include dirname(__DIR__) . '/partials/nav.php';

$previewBase = '/admin/email/preview.php';

$panelClass = 'overflow-hidden rounded-[24px] border border-slate-200 bg-white shadow-sm';
$panelHeaderClass = 'border-b border-slate-200 bg-white px-6 py-5';
$panelBodyClass = 'p-6';
$subLabelClass = 'mb-2 block text-[11px] font-bold uppercase tracking-[0.16em] text-slate-500';
$inputClass = 'w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-900 outline-none transition placeholder:text-slate-400 focus:border-yellow-400 focus:ring-4 focus:ring-yellow-100';
?>

<div class="md:hidden mb-8">
  <h1 class="text-3xl font-black text-slate-900 tracking-tight">
    <?= e((string)$pageTitle) ?>
  </h1>

  <?php if (trim((string)$pageDesc) !== ''): ?>
    <p class="mt-2 text-sm font-semibold text-slate-500">
      <?= e((string)$pageDesc) ?>
    </p>
  <?php endif; ?>
</div>

<?php if (is_array($flash)): ?>
  <div class="mb-6 rounded-3xl border px-5 py-4 <?= $flash['type'] === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-rose-200 bg-rose-50 text-rose-700' ?>">
    <div class="text-sm font-black"><?= e((string)$flash['message']) ?></div>
  </div>
<?php endif; ?>

<style>
  #bodyToolbar.ql-toolbar.ql-snow,
  #bodyEditor.ql-container.ql-snow {
    border: 0 !important;
  }

  #bodyToolbar {
    position: relative;
    z-index: 30;
    overflow: visible !important;
  }

  #bodyEditor.ql-container {
    position: relative;
    z-index: 10;
    overflow: visible !important;
  }

  #bodyEditor .ql-editor {
    min-height: 220px;
    padding: 14px 16px;
    font-size: 14px;
    line-height: 1.75;
    font-weight: 600;
    color: #0f172a;
    overflow-x: hidden;
  }

  #bodyEditor .ql-editor.ql-blank::before {
    color: #94a3b8;
    font-style: normal;
    left: 16px;
  }

  /* Tooltip link rapat dengan icon link */
  #bodyEditorWrap.ql-snow .ql-tooltip {
    position: absolute !important;
    right: auto !important;
    transform: none !important;
    z-index: 9999 !important;
    margin-top: 0 !important;
    white-space: nowrap;
    width: max-content;
    max-width: min(340px, calc(100vw - 32px));
    background: #ffffff;
    border: 1px solid #d1d5db;
    border-radius: 10px;
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.12);
    padding: 4px 10px;
    min-height: 40px;
    display: flex;
    align-items: center;
    gap: 8px;
  }

  #bodyEditorWrap.ql-snow .ql-tooltip::before {
    display: none !important;
  }

  #bodyEditorWrap.ql-snow .ql-tooltip input[type="text"] {
    width: 180px;
    min-width: 180px;
    height: 30px;
    padding: 4px 10px;
    border: 1px solid #0f172a;
    border-radius: 6px;
    outline: none;
    box-shadow: none;
    margin: 0;
    line-height: 1.2;
  }

  #bodyEditorWrap.ql-snow .ql-tooltip a.ql-preview,
  #bodyEditorWrap.ql-snow .ql-tooltip a.ql-action,
  #bodyEditorWrap.ql-snow .ql-tooltip a.ql-remove {
    margin-left: 0;
    line-height: 1;
    vertical-align: middle;
  }

  #bodyEditorWrap.ql-snow .ql-tooltip a.ql-preview {
    display: inline-block;
    max-width: 120px;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  #bodyEditorWrap.ql-snow .ql-tooltip.ql-editing a.ql-action {
    margin-left: 6px;
  }

  #bodyEditorWrap.ql-snow .ql-tooltip.ql-editing a.ql-preview,
  #bodyEditorWrap.ql-snow .ql-tooltip.ql-editing a.ql-remove {
    display: none !important;
  }

  #bodyEditorWrap.ql-snow .ql-tooltip.ql-hidden {
    display: none !important;
  }
</style>

<div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_520px]">
  <form id="emailTemplateForm" method="POST" class="min-w-0">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= e((string)$templateId) ?>">

    <div class="<?= $panelClass ?>">
      <div class="<?= $panelHeaderClass ?>">
        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
          <div class="min-w-0">
            <h2 class="mt-3 text-2xl font-bold tracking-tight text-slate-900">Custom Email Templates</h2>
            <p class="mt-1 text-sm text-slate-500">Create product-specific follow-up emails, bonus gift emails, and marketing-style customer updates.</p>
          </div>

          <?php if ($isEdit): ?>
            <div class="inline-flex items-center gap-1.5 self-start whitespace-nowrap rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-[10px] font-black uppercase tracking-[0.08em] leading-none text-amber-700">
              <span class="inline-block h-2 w-2 rounded-full bg-amber-500"></span>
              Edit Mode
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="<?= $panelBodyClass ?> space-y-8">
        <section class="space-y-4">
          <input type="hidden" name="target_id" id="target_id" value="<?= e((string)$selectedTargetId) ?>">

          <div class="grid gap-4 lg:grid-cols-2">
            <div>
              <label for="ce_product_id" class="<?= $subLabelClass ?>">Target Product</label>
              <div class="relative">
                <select
                  id="ce_product_id"
                  name="ce_product_id"
                  class="<?= $inputClass ?> appearance-none pr-12 font-semibold <?= $isEdit ? 'cursor-not-allowed bg-slate-100 text-slate-500' : '' ?>"
                  <?= $isEdit ? 'disabled' : '' ?>
                >
                  <?php if (is_array($defaultTarget)): ?>
                    <option value="__default" <?= $selectedProductId === '__default' ? 'selected' : '' ?>>
                      <?= e((string)$defaultTarget['title']) ?>
                    </option>
                  <?php endif; ?>

                  <?php if (!empty($standardProductOptions)): ?>
                    <optgroup label="Standard Products">
                      <?php foreach ($standardProductOptions as $product): ?>
                        <option value="<?= e((string)$product['product_id']) ?>" <?= (string)$product['product_id'] === $selectedProductId ? 'selected' : '' ?>>
                          <?= e((string)$product['title']) ?>
                        </option>
                      <?php endforeach; ?>
                    </optgroup>
                  <?php endif; ?>

                  <?php if (!empty($elearningProductOptions)): ?>
                    <optgroup label="e-Learning Products">
                      <?php foreach ($elearningProductOptions as $product): ?>
                        <option value="<?= e((string)$product['product_id']) ?>" <?= (string)$product['product_id'] === $selectedProductId ? 'selected' : '' ?>>
                          <?= e((string)$product['title']) ?>
                        </option>
                      <?php endforeach; ?>
                    </optgroup>
                  <?php endif; ?>
                </select>

                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4 text-slate-400">
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                  </svg>
                </div>
              </div>

              <?php if ($isEdit): ?>
                <p class="mt-2 text-xs font-semibold text-amber-600">Target product is locked while editing this template.</p>
              <?php endif; ?>
            </div>

            <div>
              <label for="ce_variant_id" class="<?= $subLabelClass ?>">Variant</label>
              <div class="relative">
                <select
                  id="ce_variant_id"
                  name="ce_variant_id"
                  class="<?= $inputClass ?> appearance-none pr-12 font-semibold <?= $isEdit ? 'cursor-not-allowed bg-slate-100 text-slate-500' : '' ?>"
                  <?= $isEdit ? 'disabled' : '' ?>
                >
                  <option value="">Product Default</option>

                  <?php foreach (($selectedProductId !== '__default' ? ($variantsByProduct[$selectedProductId] ?? []) : []) as $variant): ?>
                    <option value="<?= e((string)$variant['product_category_id']) ?>" <?= (string)$variant['product_category_id'] === $selectedVariantId ? 'selected' : '' ?>>
                      <?= e((string)$variant['title']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>

                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4 text-slate-400">
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                  </svg>
                </div>
              </div>

              <p class="mt-2 text-xs font-semibold text-slate-500">
                Leave as Product Default to apply the template to the whole product.
              </p>

              <?php if ($isEdit): ?>
                <p class="mt-1 text-xs font-semibold text-amber-600">Variant selection is locked while editing this template.</p>
              <?php endif; ?>
            </div>
          </div>
        </section>

        <?php
        $variantsJson = json_encode(
          $variantsByProduct,
          JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        ?>
        <script>
          window.ceVariantsByProduct = <?= $variantsJson ?: '{}' ?>;
        </script>

        <div>
          <label class="<?= $subLabelClass ?>">Email Content</label>
        </div>
        <section class="space-y-4">
          <div class="flex flex-wrap items-center gap-2">
            <?php foreach (['{{customer_name}}', '{{product_name}}', '{{product_price}}', '{{action_url}}', '{{support_email}}'] as $token): ?>
              <button type="button" class="js-token rounded-full border border-yellow-200 bg-yellow-50 px-3 py-1.5 text-xs font-black text-yellow-800 transition hover:bg-yellow-100" data-token="<?= e($token) ?>">
                <?= e($token) ?>
              </button>
            <?php endforeach; ?>
          </div>
          <p class="text-xs text-slate-500">Click a token to copy it, then paste it into any field below.</p>
        </section>

        <section class="grid gap-4 lg:grid-cols-2">
          <div class="lg:col-span-2">
            <label class="mb-2 block text-sm font-black text-slate-700">Subject Line</label>
            <input type="text" name="subject" value="<?= e((string)$template['subject']) ?>" class="w-full rounded-[1.25rem] border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm font-semibold text-slate-900 outline-none transition focus:border-yellow-300 focus:bg-white focus:ring-4 focus:ring-yellow-100" placeholder="Example: A special update for your order 🎁">
          </div>

          <div>
            <label class="mb-2 block text-sm font-black text-slate-700">Preheader</label>
            <input type="text" name="preheader" value="<?= e((string)$template['preheader']) ?>" class="w-full rounded-[1.25rem] border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm font-semibold text-slate-900 outline-none transition focus:border-yellow-300 focus:bg-white focus:ring-4 focus:ring-yellow-100" placeholder="Short summary text that appears after the subject line in the inbox">
          </div>

          <div>
            <label class="mb-2 block text-sm font-black text-slate-700">Badge Text</label>
            <input type="text" name="badge_text" value="<?= e((string)$template['badge_text']) ?>" class="w-full rounded-[1.25rem] border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm font-semibold text-slate-900 outline-none transition focus:border-yellow-300 focus:bg-white focus:ring-4 focus:ring-yellow-100" placeholder="Example: Customer Update">
          </div>

          <div class="lg:col-span-2">
            <label class="mb-2 block text-sm font-black text-slate-700">Greeting</label>
            <input type="text" name="greeting" value="<?= e((string)$template['greeting']) ?>" class="w-full rounded-[1.25rem] border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm font-semibold text-slate-900 outline-none transition focus:border-yellow-300 focus:bg-white focus:ring-4 focus:ring-yellow-100" placeholder="Hi {{customer_name}},">
          </div>

          <div class="lg:col-span-2">
            <label class="mb-2 block text-sm font-black text-slate-700">Body Text</label>

            <textarea id="bodyInitial" class="hidden"><?= e((string)$template['body']) ?></textarea>
            <input type="hidden" name="body" id="bodyHtml" value="">

            <div id="bodyEditorWrap" class="ql-snow relative rounded-[1.5rem] border border-slate-200 bg-white overflow-visible focus-within:border-yellow-300 focus-within:ring-4 focus-within:ring-yellow-100">
              <div id="bodyToolbar" class="border-b border-slate-200 bg-slate-50">
                <span class="ql-formats">
                  <button class="ql-bold"></button>
                  <button class="ql-italic"></button>
                  <button class="ql-underline"></button>
                </span>

                <span class="ql-formats">
                  <button class="ql-list" value="ordered"></button>
                  <button class="ql-list" value="bullet"></button>
                </span>

                <span class="ql-formats">
                  <button class="ql-link"></button>
                </span>
              </div>

              <div id="bodyEditor" class="min-h-[220px] bg-slate-50"></div>
            </div>

            <p class="mt-2 text-xs font-semibold text-slate-500">
              Supports bold, italic, underline, numbered list, bullet list, and link.
            </p>
          </div>

          <div>
            <label class="mb-2 block text-sm font-black text-slate-700">Button Text</label>
            <input type="text" name="button_text" value="<?= e((string)$template['button_text']) ?>" class="w-full rounded-[1.25rem] border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm font-semibold text-slate-900 outline-none transition focus:border-yellow-300 focus:bg-white focus:ring-4 focus:ring-yellow-100" placeholder="View Details">
          </div>

          <div>
            <label class="mb-2 block text-sm font-black text-slate-700">Button URL</label>
            <input type="text" name="button_url" value="<?= e((string)$template['button_url']) ?>" class="w-full rounded-[1.25rem] border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm font-semibold text-slate-900 outline-none transition focus:border-yellow-300 focus:bg-white focus:ring-4 focus:ring-yellow-100" placeholder="https://...">
          </div>

          <div class="lg:col-span-2">
            <label class="mb-2 block text-sm font-black text-slate-700">Closing</label>
            <textarea name="closing" rows="3" class="w-full rounded-[1.5rem] border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm font-semibold leading-7 text-slate-900 outline-none transition focus:border-yellow-300 focus:bg-white focus:ring-4 focus:ring-yellow-100" placeholder="All the best,
            Demo Team"><?= e((string)$template['closing']) ?></textarea>
          </div>
        </section>

        <section class="grid gap-4 lg:grid-cols-2">
          <div>
            <label class="mb-2 block text-sm font-black text-slate-700">Brand Name</label>
            <input type="text" name="brand_name" value="<?= e((string)$template['brand_name']) ?>" class="w-full rounded-[1.25rem] border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm font-semibold text-slate-900 outline-none transition focus:border-yellow-300 focus:bg-white focus:ring-4 focus:ring-yellow-100">
          </div>

          <div>
            <label class="mb-2 block text-sm font-black text-slate-700">Brand Email</label>
            <input type="email" name="brand_email" value="<?= e((string)$template['brand_email']) ?>" class="w-full rounded-[1.25rem] border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm font-semibold text-slate-900 outline-none transition focus:border-yellow-300 focus:bg-white focus:ring-4 focus:ring-yellow-100">
          </div>

          <div>
            <label class="mb-2 block text-sm font-black text-slate-700">Support Email</label>
            <input type="email" name="support_email" value="<?= e((string)$template['support_email']) ?>" class="w-full rounded-[1.25rem] border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm font-semibold text-slate-900 outline-none transition focus:border-yellow-300 focus:bg-white focus:ring-4 focus:ring-yellow-100">
          </div>

          <div class="lg:col-span-2">
            <label class="mb-2 block text-sm font-black text-slate-700">Footer Note</label>
            <textarea name="footer_note" rows="3" class="w-full rounded-[1.5rem] border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm font-semibold leading-7 text-slate-900 outline-none transition focus:border-yellow-300 focus:bg-white focus:ring-4 focus:ring-yellow-100" placeholder="You are receiving this email because of your recent purchase of {{product_name}}."><?= e((string)$template['footer_note']) ?></textarea>
          </div>
        </section>
      </div>
    </div>
  </form>

  <section class="min-w-0">
    <div class="<?= $panelClass ?>">
      <div class="sticky top-0 z-10 flex items-center justify-between gap-4 border-b border-slate-200 bg-white px-5 py-4">
        <div>
          <div class="text-sm font-black text-slate-900">Live Preview</div>
        </div>

        <div class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-2 text-xs font-black text-slate-600">
          <span class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-yellow-100 text-yellow-700">JD</span>
          Jane Doe
        </div>
      </div>

      <div class="bg-slate-100 p-4 sm:p-5 xl:p-6">
        <iframe
          id="mailPreviewFrame"
          title="Email Preview"
          class="h-[980px] w-full rounded-[24px] border border-slate-200 bg-[#09090b] shadow-sm"
          src="<?= e($previewBase . '?' . http_build_query([
            'target_id'      => $selectedTargetId,
            'subject'        => (string)$template['subject'],
            'preheader'      => (string)$template['preheader'],
            'badge_text'     => (string)$template['badge_text'],
            'greeting'       => (string)$template['greeting'],
            'body'           => (string)$template['body'],
            'button_text'    => (string)$template['button_text'],
            'button_url'     => (string)$template['button_url'],
            'closing'        => (string)$template['closing'],
            'brand_name'     => (string)$template['brand_name'],
            'brand_email'    => (string)$template['brand_email'],
            'support_email'  => (string)$template['support_email'],
            'footer_note'    => (string)$template['footer_note'],
          ])) ?>"
        ></iframe>
      </div>
    </div>
  </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js"></script>
<script>
(() => {
  const form = document.getElementById('emailTemplateForm');
  const iframe = document.getElementById('mailPreviewFrame');
  const productSelect = document.getElementById('ce_product_id');
  const variantSelect = document.getElementById('ce_variant_id');
  const targetInput = document.getElementById('target_id');
  const bodyHtml = document.getElementById('bodyHtml');
  const bodyInitial = document.getElementById('bodyInitial');
  const tokenButtons = Array.from(document.querySelectorAll('.js-token'));
  const variantsByProduct = window.ceVariantsByProduct || {};
  const isEditMode = <?= $isEdit ? 'true' : 'false' ?>;
  const previewBase = <?= json_encode($previewBase, JSON_UNESCAPED_SLASHES) ?>;

  if (!form || !iframe || !targetInput) return;

  let timer = null;
  let bodyQuill = null;

  function buildParams() {
    const fd = new FormData(form);
    const params = new URLSearchParams();

    [
      'target_id',
      'subject',
      'preheader',
      'badge_text',
      'greeting',
      'body',
      'button_text',
      'button_url',
      'closing',
      'brand_name',
      'brand_email',
      'support_email',
      'footer_note'
    ].forEach((key) => {
      params.set(key, String(fd.get(key) || ''));
    });

    return params;
  }

  function syncPreview() {
    iframe.src = previewBase + '?' + buildParams().toString() + '&_=' + Date.now();
  }

  function debounceSync() {
    window.clearTimeout(timer);
    timer = window.setTimeout(syncPreview, 180);
  }

  function rebuildVariantOptions(productId, selectedVariantId = '') {
    if (!variantSelect) return;

    variantSelect.innerHTML = '';

    const defaultOption = document.createElement('option');
    defaultOption.value = '';
    defaultOption.textContent = 'Product Default';
    variantSelect.appendChild(defaultOption);

    if (!productId || productId === '__default') {
      variantSelect.disabled = true;
      variantSelect.value = '';
      return;
    }

    const variants = Array.isArray(variantsByProduct[productId])
      ? variantsByProduct[productId]
      : [];

    variants.forEach((variant) => {
      const option = document.createElement('option');
      option.value = String(variant.product_category_id || '');
      option.textContent = String(variant.title || 'Variant');
      variantSelect.appendChild(option);
    });

    variantSelect.disabled = !!isEditMode || variants.length === 0;
    variantSelect.value = selectedVariantId || '';
  }

  function syncTargetId() {
    if (!productSelect) return;

    const productId = productSelect.value || '__default';
    const variantId = variantSelect ? (variantSelect.value || '') : '';

    if (productId === '__default') {
      targetInput.value = '__default';
      return;
    }

    targetInput.value = variantId
      ? ('variant:' + variantId)
      : ('product:' + productId);
  }

  if (window.Quill && bodyHtml && document.getElementById('bodyEditor')) {
    bodyQuill = new Quill('#bodyEditor', {
      theme: 'snow',
      modules: {
        toolbar: '#bodyToolbar'
      }
    });

    const bodyWrap = document.getElementById('bodyEditorWrap');
    const bodyToolbar = document.getElementById('bodyToolbar');
    const linkButton = bodyToolbar ? bodyToolbar.querySelector('.ql-link') : null;
    const quillTheme = bodyQuill.theme || null;
    const quillTooltipObj = quillTheme && quillTheme.tooltip ? quillTheme.tooltip : null;
    const quillTooltip = quillTooltipObj ? quillTooltipObj.root : null;

    function positionLinkTooltip() {
      if (!bodyWrap || !bodyToolbar || !linkButton || !quillTooltip) return;

      if (quillTooltip.parentNode !== bodyWrap) {
        bodyWrap.appendChild(quillTooltip);
      }

      const wrapRect = bodyWrap.getBoundingClientRect();
      const buttonRect = linkButton.getBoundingClientRect();

      const tooltipWidth = quillTooltip.offsetWidth || 260;
      const tooltipHeight = quillTooltip.offsetHeight || 42;
      const gap = 8;

      let left = buttonRect.right - wrapRect.left + gap;
      let top = buttonRect.top - wrapRect.top + ((buttonRect.height - tooltipHeight) / 2);

      const maxLeft = Math.max(8, bodyWrap.clientWidth - tooltipWidth - 8);

      if (left > maxLeft) {
        left = buttonRect.left - wrapRect.left - tooltipWidth - gap;
      }

      if (left < 8) left = 8;
      if (top < 8) top = 8;

      quillTooltip.style.left = left + 'px';
      quillTooltip.style.top = top + 'px';
      quillTooltip.style.right = 'auto';
      quillTooltip.style.transform = 'none';
      quillTooltip.style.zIndex = '9999';
    }

    function openLinkTooltip(currentLink = '') {
      if (!quillTooltipObj) return;

      quillTooltipObj.edit('link', currentLink);

      requestAnimationFrame(() => {
        positionLinkTooltip();

        const input = quillTooltipObj.textbox;
        if (input) {
          input.focus();
          input.select();
        }
      });
    }

    const toolbarModule = bodyQuill.getModule('toolbar');

    if (toolbarModule) {
      toolbarModule.addHandler('link', function (value) {
        if (!value) {
          this.quill.format('link', false);
          return;
        }

        const range = this.quill.getSelection(true);
        if (!range) return;

        const currentFormat = this.quill.getFormat(range);
        const currentLink = typeof currentFormat.link === 'string' ? currentFormat.link : '';

        openLinkTooltip(currentLink);
      });
    }

    if (quillTooltipObj && typeof quillTooltipObj.position === 'function') {
      const originalTooltipPosition = quillTooltipObj.position.bind(quillTooltipObj);

      quillTooltipObj.position = function (...args) {
        const result = originalTooltipPosition(...args);

        requestAnimationFrame(() => {
          if (quillTooltip && !quillTooltip.classList.contains('ql-hidden')) {
            positionLinkTooltip();
          }
        });

        return result;
      };
    }

    bodyQuill.root.addEventListener('click', (event) => {
      const anchor = event.target.closest('a');
      if (!anchor || !bodyQuill.root.contains(anchor)) return;

      event.preventDefault();
      event.stopPropagation();

      const blot = Quill.find(anchor);
      if (blot) {
        const index = bodyQuill.getIndex(blot);
        const length = typeof blot.length === 'function'
          ? blot.length()
          : Math.max(1, (anchor.textContent || '').length);

        bodyQuill.setSelection(index, length, 'silent');
      } else {
        bodyQuill.focus();
      }

      openLinkTooltip(anchor.getAttribute('href') || '');
    }, true);

    window.addEventListener('resize', () => {
      if (quillTooltip && !quillTooltip.classList.contains('ql-hidden')) {
        positionLinkTooltip();
      }
    });

    const initVal = (bodyInitial ? bodyInitial.value : '').trim();

    if (initVal && /<\/?(p|br|strong|em|u|ul|ol|li|a)\b/i.test(initVal)) {
      bodyQuill.clipboard.dangerouslyPasteHTML(initVal);
    } else if (initVal) {
      bodyQuill.setText(initVal);
    }

    const syncBody = () => {
      const plain = bodyQuill.getText().trim();
      bodyHtml.value = plain ? bodyQuill.root.innerHTML : '';
      debounceSync();
    };

    syncBody();
    bodyQuill.on('text-change', syncBody);
    form.addEventListener('submit', syncBody);
  }

  form.addEventListener('input', (e) => {
    const el = e.target;
    if (el && (el.id === 'ce_product_id' || el.id === 'ce_variant_id')) return;
    debounceSync();
  });

  form.addEventListener('change', (e) => {
    const el = e.target;
    if (el && (el.id === 'ce_product_id' || el.id === 'ce_variant_id')) return;
    debounceSync();
  });

  if (!isEditMode && productSelect && variantSelect) {
    productSelect.addEventListener('change', () => {
      rebuildVariantOptions(productSelect.value, '');
      syncTargetId();
      debounceSync();
    });

    variantSelect.addEventListener('change', () => {
      syncTargetId();
      debounceSync();
    });
  }

  tokenButtons.forEach((btn) => {
    btn.addEventListener('click', async () => {
      const token = btn.getAttribute('data-token') || '';
      if (!token) return;

      try {
        await navigator.clipboard.writeText(token);
        btn.classList.add('ring-2', 'ring-yellow-300');
        window.setTimeout(() => {
          btn.classList.remove('ring-2', 'ring-yellow-300');
        }, 700);
      } catch (err) {}
    });
  });

  if (productSelect && variantSelect) {
    rebuildVariantOptions(productSelect.value, variantSelect.value);
  }

  syncTargetId();
  syncPreview();
})();
</script>

<?php include dirname(__DIR__) . '/partials/footer.php'; ?>
