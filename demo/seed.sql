-- =============================================================================
-- DEMO SEED DATA — Phase 3
-- =============================================================================
-- All emails: @example.test or @demo.local only — no real domains.
-- No real names, no real products, no real pricing.
-- MySQL 5.7 compatible. No window functions, no CTEs, no JSON_TABLE.
-- Payment.transaction_id: 'DEMO-SP-' + 8 hex chars (NOT NULL, explicit per row).
-- Orders.order_id: 'ORD-NNNN' sequential.
-- Orders.meta: JSON literal (gateway/ip/ua — safe demo values).
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET time_zone = '+08:00';

-- =============================================================================
-- ADMIN / AUTH
-- =============================================================================

INSERT INTO `admins` (`username`, `email`, `password_hash`, `role`, `last_login_at`) VALUES
('demo_admin', 'admin@demo.local', '$2y$12$790m5eM6eNdp4tcAKXwe6.Xiw5SUnR0Bg2AT27nV.AZ0SEtx0rR7G', 'admin', '2026-05-20 09:00:00');

-- =============================================================================
-- PAYMENT MODULE — Products
-- =============================================================================

INSERT INTO `Products`
    (`id`, `name`, `base_price`, `status`, `has_categories`, `description`, `poster`,
     `is_subscription`, `duration_value`, `duration_unit`,
     `first_month_price`, `remaining_month_price`,
     `allow_full_payment`, `allow_installment`, `installment_count`, `installment_interval_unit`)
VALUES
(1, 'Demo Masterclass',   997.00, 'active', 0, 'Flagship one-time course for demo purposes.',          '/img/demo/product-1.svg', 0, NULL, NULL,    NULL,  NULL,  1, 0, NULL, 'month'),
(2, 'Pro Membership',      97.00, 'active', 1, 'Monthly subscription programme with tiered plans.',    '/img/demo/product-2.svg', 1,    1, 'month', 47.00, 97.00, 0, 0, NULL, 'month'),
(3, 'Business Bootcamp', 1997.00, 'active', 0, 'Instalment-based intensive business programme.',      '/img/demo/product-3.svg', 0, NULL, NULL,    NULL,  NULL,  1, 1,    6, 'month'),
(4, 'Starter Course',    197.00, 'inactive', 0, 'Entry-level one-time purchase for beginners.',        NULL,                      0, NULL, NULL,    NULL,  NULL,  1, 0, NULL, 'month'),
(5, 'Premium Coaching Bundle', 2288.00, 'active', 0, 'Premium 12-month coaching programme. Pay RM 99 today then RM 199/month for 11 months.', '/img/demo/product-4.svg', 0, NULL, NULL, 99.00, 199.00, 1, 1, 11, 'month');

-- Product variants (for Product 2 — Pro Membership)
INSERT INTO `Product_Categories`
    (`id`, `product_id`, `sort_order`, `name`, `price_modifier`, `variant_type`,
     `is_subscription`, `duration_value`, `duration_unit`, `first_month_price`, `remaining_month_price`)
VALUES
(1, 2, 1, 'Solo Plan', 0.00, 'subscription', 1, 1, 'month',  47.00,  97.00),
(2, 2, 2, 'Team Plan', 50.00,'subscription', 1, 1, 'month',  97.00, 147.00);

-- =============================================================================
-- DISCOUNT CODES
-- =============================================================================

INSERT INTO `Discount_Codes`
    (`id`, `code`, `discount_type`, `discount_value`, `status`,
     `valid_from`, `valid_until`, `max_redemptions`, `per_email_limit`)
VALUES
(1, 'DEMO10',  'percent', 10.00, 'active',   '2026-01-01 00:00:00', '2026-12-31 23:59:59', 100, 1),
(2, 'FLAT50',  'fixed',   50.00, 'active',   '2026-01-01 00:00:00', '2026-12-31 23:59:59',  50, 1),
(3, 'WELCOME', 'percent', 15.00, 'disabled', NULL,                  NULL,                   200, 1);

-- =============================================================================
-- PAYMENT (16 rows)
-- transaction_id = 'DEMO-SP-' + 8 uppercase hex chars — NOT NULL, no DEFAULT
-- =============================================================================

INSERT INTO `Payment`
    (`codeid`, `product_type`, `product_category_id`, `variant_type`,
     `name`, `email`, `phone`, `item`, `package`, `channel`,
     `price`, `transaction_id`, `sid`, `referred_by`,
     `status`, `verified`,
     `discount_code`, `discount_amount`, `subtotal_before_discount`,
     `subscription_id`, `subscription_mode`, `is_subscription`,
     `timestamp`)
VALUES
-- paid — one-time purchases
('1','1', NULL,  'normal',       'Alice Demo',  'alice@example.test',  '+60111000001', 'Demo Masterclass',   'Demo Masterclass', 'online',  997.00, 'DEMO-SP-A1B2C3D4', '', '', 'completed', 1, NULL,     0.00, NULL,    NULL, NULL,      0, '2026-01-10 10:15:00'),
('1','1', NULL,  'normal',       'Bob Demo',    'bob@example.test',    '+60111000002', 'Demo Masterclass',   'Demo Masterclass', 'online',  997.00, 'DEMO-SP-E5F6A7B8', '', '', 'completed', 1, NULL,     0.00, NULL,    NULL, NULL,      0, '2026-01-15 11:20:00'),
-- paid — subscription first month
('2','2', '1',   'subscription', 'Carol Demo',  'carol@example.test',  '+60111000003', 'Pro Membership',     'Solo Plan',        'online',   47.00, 'DEMO-SP-C9D0E1F2', '', '', 'completed', 1, NULL,     0.00, NULL,       1, 'new',     1, '2026-01-20 09:30:00'),
('2','2', '1',   'subscription', 'David Demo',  'david@demo.local',    '+60111000004', 'Pro Membership',     'Solo Plan',        'online',   47.00, 'DEMO-SP-A3B4C5D6', '', '', 'completed', 1, NULL,     0.00, NULL,       2, 'new',     1, '2026-02-01 08:00:00'),
('2','2', '2',   'subscription', 'Emma Demo',   'emma@example.test',   '+60111000005', 'Pro Membership',     'Team Plan',        'online',   97.00, 'DEMO-SP-E7F8A9B0', '', '', 'completed', 1, NULL,     0.00, NULL,       3, 'new',     1, '2026-02-05 14:00:00'),
-- paid — instalment payments (Frank, 2 of 6 paid)
('3','3', NULL,  'normal',       'Frank Demo',  'frank@demo.local',    '+60111000006', 'Business Bootcamp',  'Business Bootcamp','online',  333.00, 'DEMO-SP-C1D2E3F4', '', '', 'completed', 1, NULL,     0.00, NULL,       4, 'new',     0, '2026-02-10 10:00:00'),
('3','3', NULL,  'normal',       'Frank Demo',  'frank@demo.local',    '+60111000006', 'Business Bootcamp',  'Business Bootcamp','online',  333.00, 'DEMO-SP-A5B6C7D8', '', '', 'completed', 1, NULL,     0.00, NULL,       4, 'renewal', 0, '2026-03-10 10:00:00'),
-- paid — e-learning one-time
('4','4', NULL,  'normal',       'Henry Demo',  'henry@demo.local',    '+60111000008', 'Starter Course',     'Starter Course',   'online',  197.00, 'DEMO-SP-E9F0A1B2', '', '', 'completed', 1, NULL,     0.00, NULL,    NULL, NULL,      0, '2026-02-20 15:30:00'),
('4','4', NULL,  'normal',       'Iris Demo',   'iris@example.test',   '+60111000009', 'Starter Course',     'Starter Course',   'online',  197.00, 'DEMO-SP-C3D4E5F6', '', '', 'completed', 1, NULL,     0.00, NULL,    NULL, NULL,      0, '2026-03-01 09:00:00'),
-- pending
('1','1', NULL,  'normal',       'Jack Demo',   'jack@demo.local',     '+60111000010', 'Demo Masterclass',   'Demo Masterclass', 'online',  997.00, 'DEMO-SP-A7B8C9D0', '', '', 'pending', 0, NULL,     0.00, NULL,    NULL, NULL,      0, '2026-04-05 11:00:00'),
('1','1', NULL,  'normal',       'Kate Demo',   'kate@example.test',   '+60111000011', 'Demo Masterclass',   'Demo Masterclass', 'online',  997.00, 'DEMO-SP-E1F2A3B4', '', '', 'pending', 0, NULL,     0.00, NULL,    NULL, NULL,      0, '2026-04-10 14:30:00'),
('2','2', '1',   'subscription', 'Liam Demo',   'liam@demo.local',     '+60111000012', 'Pro Membership',     'Solo Plan',        'online',   47.00, 'DEMO-SP-C5D6E7F8', '', '', 'pending', 0, NULL,     0.00, NULL,    NULL, 'new',     1, '2026-04-15 16:00:00'),
-- failed
('3','3', NULL,  'normal',       'Alice Demo',  'alice@example.test',  '+60111000001', 'Business Bootcamp',  'Business Bootcamp','online',  333.00, 'DEMO-SP-A9B0C1D2', '', '', 'failed',  0, NULL,     0.00, NULL,    NULL, NULL,      0, '2026-03-20 10:00:00'),
('3','3', NULL,  'normal',       'Bob Demo',    'bob@example.test',    '+60111000002', 'Business Bootcamp',  'Business Bootcamp','online',  333.00, 'DEMO-SP-E3F4A5B6', '', '', 'failed',  0, NULL,     0.00, NULL,    NULL, NULL,      0, '2026-03-22 11:00:00'),
-- paid with discounts
('1','1', NULL,  'normal',       'Carol Demo',  'carol@example.test',  '+60111000003', 'Demo Masterclass',   'Demo Masterclass', 'online',  897.30, 'DEMO-SP-C7D8E9F0', '', '', 'completed', 1, 'DEMO10', 99.70, 997.00,  NULL, NULL,      0, '2026-04-01 09:00:00'),
('4','4', NULL,  'normal',       'David Demo',  'david@demo.local',    '+60111000004', 'Starter Course',     'Starter Course',   'online',  147.00, 'DEMO-SP-A1B2C3E4', '', '', 'completed', 1, 'FLAT50', 50.00, 197.00,  NULL, NULL,      0, '2026-04-02 10:00:00');

-- Payment rows for Premium Coaching Bundle (product 5 — installment)
-- Mike: initial 99 (completed) + second 199 (completed); Nina: initial 99 (pending)
INSERT INTO `Payment`
    (`codeid`, `product_type`, `product_category_id`, `variant_type`,
     `name`, `email`, `phone`, `item`, `package`, `channel`,
     `price`, `transaction_id`, `sid`, `referred_by`,
     `status`, `verified`,
     `discount_code`, `discount_amount`, `subtotal_before_discount`,
     `subscription_id`, `subscription_mode`, `is_subscription`,
     `timestamp`)
VALUES
('5','5', NULL, 'normal', 'Mike Demo',  'mike@example.test', '+60111000013', 'Premium Coaching Bundle', 'Premium Coaching Bundle', 'online',  99.00, 'DEMO-SP-B2C3D4E5', '', '', 'completed', 1, NULL, 0.00, NULL, NULL, 'installment', 0, '2026-03-15 10:00:00'),
('5','5', NULL, 'normal', 'Mike Demo',  'mike@example.test', '+60111000013', 'Premium Coaching Bundle', 'Premium Coaching Bundle', 'online', 199.00, 'DEMO-SP-F5A6B7C8', '', '', 'completed', 1, NULL, 0.00, NULL, NULL, 'installment', 0, '2026-04-15 10:00:00'),
('5','5', NULL, 'normal', 'Nina Demo',  'nina@demo.local',   '+60111000014', 'Premium Coaching Bundle', 'Premium Coaching Bundle', 'online',  99.00, 'DEMO-SP-D9E0F1A2', '', '', 'pending',   0, NULL, 0.00, NULL, NULL, 'installment', 0, '2026-05-10 14:00:00');

-- =============================================================================
-- ORDERS (16 rows, 1:1 with Payment)
-- meta JSON: gateway/ip/ua safe demo values
-- =============================================================================

INSERT INTO `Orders`
    (`order_id`, `codeid`, `product_id`, `category_id`, `product_name`, `variant`,
     `subtotal`, `amount`, `name`, `email`, `phone`,
     `mode`, `status`, `referred_by`, `created_at`, `transaction_id`,
     `discount_code`, `discount_amount`, `subtotal_before_discount`,
     `meta`, `subscription_id`, `subscription_mode`, `is_subscription`)
VALUES
('ORD-0001','1','1', NULL, 'Demo Masterclass',  'Demo Masterclass',   997.00,  997.00, 'Alice Demo', 'alice@example.test', '+60111000001', 'live','paid',    '', '2026-01-10 10:15:00', 'DEMO-SP-A1B2C3D4', NULL,     0.00,   NULL, '{"gateway":"demo","ip":"127.0.0.1","ua":"Demo Browser"}', NULL, NULL,      0),
('ORD-0002','1','1', NULL, 'Demo Masterclass',  'Demo Masterclass',   997.00,  997.00, 'Bob Demo',   'bob@example.test',   '+60111000002', 'live','paid',    '', '2026-01-15 11:20:00', 'DEMO-SP-E5F6A7B8', NULL,     0.00,   NULL, '{"gateway":"demo","ip":"127.0.0.1","ua":"Demo Browser"}', NULL, NULL,      0),
('ORD-0003','2','2','1',   'Pro Membership',     'Solo Plan',           47.00,   47.00, 'Carol Demo', 'carol@example.test', '+60111000003', 'live','paid',    '', '2026-01-20 09:30:00', 'DEMO-SP-C9D0E1F2', NULL,     0.00,   NULL, '{"gateway":"demo","ip":"127.0.0.1","ua":"Demo Browser"}',    1, 'new',     1),
('ORD-0004','2','2','1',   'Pro Membership',     'Solo Plan',           47.00,   47.00, 'David Demo', 'david@demo.local',   '+60111000004', 'live','paid',    '', '2026-02-01 08:00:00', 'DEMO-SP-A3B4C5D6', NULL,     0.00,   NULL, '{"gateway":"demo","ip":"127.0.0.1","ua":"Demo Browser"}',    2, 'new',     1),
('ORD-0005','2','2','2',   'Pro Membership',     'Team Plan',           97.00,   97.00, 'Emma Demo',  'emma@example.test',  '+60111000005', 'live','paid',    '', '2026-02-05 14:00:00', 'DEMO-SP-E7F8A9B0', NULL,     0.00,   NULL, '{"gateway":"demo","ip":"127.0.0.1","ua":"Demo Browser"}',    3, 'new',     1),
('ORD-0006','3','3', NULL, 'Business Bootcamp', 'Business Bootcamp',  333.00,  333.00, 'Frank Demo', 'frank@demo.local',   '+60111000006', 'live','paid',    '', '2026-02-10 10:00:00', 'DEMO-SP-C1D2E3F4', NULL,     0.00,   NULL, '{"gateway":"demo","ip":"127.0.0.1","ua":"Demo Browser"}',    4, 'new',     0),
('ORD-0007','3','3', NULL, 'Business Bootcamp', 'Business Bootcamp',  333.00,  333.00, 'Frank Demo', 'frank@demo.local',   '+60111000006', 'live','paid',    '', '2026-03-10 10:00:00', 'DEMO-SP-A5B6C7D8', NULL,     0.00,   NULL, '{"gateway":"demo","ip":"127.0.0.1","ua":"Demo Browser"}',    4, 'renewal', 0),
('ORD-0008','4','4', NULL, 'Starter Course',    'Starter Course',     197.00,  197.00, 'Henry Demo', 'henry@demo.local',   '+60111000008', 'live','paid',    '', '2026-02-20 15:30:00', 'DEMO-SP-E9F0A1B2', NULL,     0.00,   NULL, '{"gateway":"demo","ip":"127.0.0.1","ua":"Demo Browser"}', NULL, NULL,      0),
('ORD-0009','4','4', NULL, 'Starter Course',    'Starter Course',     197.00,  197.00, 'Iris Demo',  'iris@example.test',  '+60111000009', 'live','paid',    '', '2026-03-01 09:00:00', 'DEMO-SP-C3D4E5F6', NULL,     0.00,   NULL, '{"gateway":"demo","ip":"127.0.0.1","ua":"Demo Browser"}', NULL, NULL,      0),
('ORD-0010','1','1', NULL, 'Demo Masterclass',  'Demo Masterclass',   997.00,  997.00, 'Jack Demo',  'jack@demo.local',    '+60111000010', 'live','pending', '', '2026-04-05 11:00:00', 'DEMO-SP-A7B8C9D0', NULL,     0.00,   NULL, '{"gateway":"demo","ip":"127.0.0.1","ua":"Demo Browser"}', NULL, NULL,      0),
('ORD-0011','1','1', NULL, 'Demo Masterclass',  'Demo Masterclass',   997.00,  997.00, 'Kate Demo',  'kate@example.test',  '+60111000011', 'live','pending', '', '2026-04-10 14:30:00', 'DEMO-SP-E1F2A3B4', NULL,     0.00,   NULL, '{"gateway":"demo","ip":"127.0.0.1","ua":"Demo Browser"}', NULL, NULL,      0),
('ORD-0012','2','2','1',   'Pro Membership',     'Solo Plan',           47.00,   47.00, 'Liam Demo',  'liam@demo.local',    '+60111000012', 'live','pending', '', '2026-04-15 16:00:00', 'DEMO-SP-C5D6E7F8', NULL,     0.00,   NULL, '{"gateway":"demo","ip":"127.0.0.1","ua":"Demo Browser"}', NULL, 'new',     1),
('ORD-0013','3','3', NULL, 'Business Bootcamp', 'Business Bootcamp',  333.00,  333.00, 'Alice Demo', 'alice@example.test', '+60111000001', 'live','failed',  '', '2026-03-20 10:00:00', 'DEMO-SP-A9B0C1D2', NULL,     0.00,   NULL, '{"gateway":"demo","ip":"127.0.0.1","ua":"Demo Browser"}', NULL, NULL,      0),
('ORD-0014','3','3', NULL, 'Business Bootcamp', 'Business Bootcamp',  333.00,  333.00, 'Bob Demo',   'bob@example.test',   '+60111000002', 'live','failed',  '', '2026-03-22 11:00:00', 'DEMO-SP-E3F4A5B6', NULL,     0.00,   NULL, '{"gateway":"demo","ip":"127.0.0.1","ua":"Demo Browser"}', NULL, NULL,      0),
('ORD-0015','1','1', NULL, 'Demo Masterclass',  'Demo Masterclass',   897.30,  897.30, 'Carol Demo', 'carol@example.test', '+60111000003', 'live','paid',    '', '2026-04-01 09:00:00', 'DEMO-SP-C7D8E9F0', 'DEMO10', 99.70, 997.00, '{"gateway":"demo","ip":"127.0.0.1","ua":"Demo Browser"}', NULL, NULL,      0),
('ORD-0016','4','4', NULL, 'Starter Course',    'Starter Course',     147.00,  147.00, 'David Demo', 'david@demo.local',   '+60111000004', 'live','paid',    '', '2026-04-02 10:00:00', 'DEMO-SP-A1B2C3E4', 'FLAT50', 50.00, 197.00, '{"gateway":"demo","ip":"127.0.0.1","ua":"Demo Browser"}', NULL, NULL,      0);

-- e-Learning course purchases (product_name = course id — matched by progress.php via order_products view)
INSERT INTO `Orders`
    (`order_id`, `codeid`, `product_id`, `category_id`, `product_name`, `variant`,
     `subtotal`, `amount`, `name`, `email`, `phone`,
     `mode`, `status`, `referred_by`, `created_at`, `transaction_id`,
     `discount_code`, `discount_amount`, `subtotal_before_discount`,
     `meta`, `subscription_id`, `subscription_mode`, `is_subscription`)
VALUES
('EL-0001','','dmf-2024',NULL,'dmf-2024','', 197.00,197.00,'Alice Demo', 'alice@example.test','+60111000001','live','completed','','2026-01-10 10:30:00','DEMO-EL-001',NULL,0.00,NULL,NULL,NULL,NULL,0),
('EL-0002','','bgs-2024',NULL,'bgs-2024','', 397.00,397.00,'Alice Demo', 'alice@example.test','+60111000001','live','completed','','2026-01-10 10:31:00','DEMO-EL-002',NULL,0.00,NULL,NULL,NULL,NULL,0),
('EL-0003','','adi-2024',NULL,'adi-2024','', 497.00,497.00,'Alice Demo', 'alice@example.test','+60111000001','live','completed','','2026-01-10 10:32:00','DEMO-EL-003',NULL,0.00,NULL,NULL,NULL,NULL,0),
('EL-0004','','dmf-2024',NULL,'dmf-2024','', 197.00,197.00,'Bob Demo',   'bob@example.test',  '+60111000002','live','completed','','2026-01-15 11:30:00','DEMO-EL-004',NULL,0.00,NULL,NULL,NULL,NULL,0),
('EL-0005','','adi-2024',NULL,'adi-2024','', 497.00,497.00,'Bob Demo',   'bob@example.test',  '+60111000002','live','completed','','2026-01-15 11:31:00','DEMO-EL-005',NULL,0.00,NULL,NULL,NULL,NULL,0),
('EL-0006','','dmf-2024',NULL,'dmf-2024','', 197.00,197.00,'Carol Demo', 'carol@example.test','+60111000003','live','completed','','2026-01-20 09:45:00','DEMO-EL-006',NULL,0.00,NULL,NULL,NULL,NULL,0),
('EL-0007','','bgs-2024',NULL,'bgs-2024','', 397.00,397.00,'Carol Demo', 'carol@example.test','+60111000003','live','completed','','2026-01-20 09:46:00','DEMO-EL-007',NULL,0.00,NULL,NULL,NULL,NULL,0),
('EL-0008','','bgs-2024',NULL,'bgs-2024','', 397.00,397.00,'David Demo', 'david@demo.local',  '+60111000004','live','completed','','2026-02-01 08:30:00','DEMO-EL-008',NULL,0.00,NULL,NULL,NULL,NULL,0),
('EL-0009','','adi-2024',NULL,'adi-2024','', 497.00,497.00,'David Demo', 'david@demo.local',  '+60111000004','live','completed','','2026-02-01 08:31:00','DEMO-EL-009',NULL,0.00,NULL,NULL,NULL,NULL,0),
('EL-0010','','bgs-2024',NULL,'bgs-2024','', 397.00,397.00,'Emma Demo',  'emma@example.test', '+60111000005','live','completed','','2026-02-05 14:30:00','DEMO-EL-010',NULL,0.00,NULL,NULL,NULL,NULL,0),
('EL-0011','','bgs-2024',NULL,'bgs-2024','', 397.00,397.00,'Frank Demo', 'frank@demo.local',  '+60111000006','live','completed','','2026-02-10 10:30:00','DEMO-EL-011',NULL,0.00,NULL,NULL,NULL,NULL,0),
('EL-0012','','dmf-2024',NULL,'dmf-2024','', 197.00,197.00,'Grace Demo', 'grace@example.test','+60111000007','live','completed','','2026-02-15 09:00:00','DEMO-EL-012',NULL,0.00,NULL,NULL,NULL,NULL,0),
('EL-0013','','dmf-2024',NULL,'dmf-2024','', 197.00,197.00,'Henry Demo', 'henry@demo.local',  '+60111000008','live','completed','','2026-02-20 15:45:00','DEMO-EL-013',NULL,0.00,NULL,NULL,NULL,NULL,0),
('EL-0014','','dmf-2024',NULL,'dmf-2024','', 197.00,197.00,'Iris Demo',  'iris@example.test', '+60111000009','live','completed','','2026-03-01 09:15:00','DEMO-EL-014',NULL,0.00,NULL,NULL,NULL,NULL,0),
('EL-0015','','bgs-2024',NULL,'bgs-2024','', 397.00,397.00,'Iris Demo',  'iris@example.test', '+60111000009','live','completed','','2026-03-01 09:16:00','DEMO-EL-015',NULL,0.00,NULL,NULL,NULL,NULL,0);

-- Orders for Premium Coaching Bundle (product 5 — installment)
INSERT INTO `Orders`
    (`order_id`, `codeid`, `product_id`, `category_id`, `product_name`, `variant`,
     `subtotal`, `amount`, `name`, `email`, `phone`,
     `mode`, `status`, `referred_by`, `created_at`, `transaction_id`,
     `discount_code`, `discount_amount`, `subtotal_before_discount`,
     `meta`, `subscription_id`, `subscription_mode`, `is_subscription`)
VALUES
('ORD-0017','5','5', NULL, 'Premium Coaching Bundle', 'Premium Coaching Bundle',  99.00,  99.00, 'Mike Demo', 'mike@example.test', '+60111000013', 'live','paid',    '', '2026-03-15 10:00:00', 'DEMO-SP-B2C3D4E5', NULL, 0.00, NULL, '{"gateway":"demo","ip":"127.0.0.1","ua":"Demo Browser"}', NULL, 'installment', 0),
('ORD-0018','5','5', NULL, 'Premium Coaching Bundle', 'Premium Coaching Bundle', 199.00, 199.00, 'Mike Demo', 'mike@example.test', '+60111000013', 'live','paid',    '', '2026-04-15 10:00:00', 'DEMO-SP-F5A6B7C8', NULL, 0.00, NULL, '{"gateway":"demo","ip":"127.0.0.1","ua":"Demo Browser"}', NULL, 'installment', 0),
('ORD-0019','5','5', NULL, 'Premium Coaching Bundle', 'Premium Coaching Bundle',  99.00,  99.00, 'Nina Demo', 'nina@demo.local',   '+60111000014', 'live','pending', '', '2026-05-10 14:00:00', 'DEMO-SP-D9E0F1A2', NULL, 0.00, NULL, '{"gateway":"demo","ip":"127.0.0.1","ua":"Demo Browser"}', NULL, 'installment', 0);

-- =============================================================================
-- ORDER_PRODUCTS (denormalised snapshot populated from Orders)
-- =============================================================================

INSERT INTO `order_products` (`id`, `customer_email`, `status`, `product_name`, `amount`, `product_type`, `created_at`)
SELECT `id`, `email`, `status`, `product_name`, `amount`, NULL, `created_at`
FROM `Orders`;

-- =============================================================================
-- DISCOUNT REDEMPTIONS
-- =============================================================================

INSERT INTO `Discount_Redemptions` (`discount_code_id`, `email`, `order_id`, `status`) VALUES
(1, 'carol@example.test', 'ORD-0015', 'confirmed'),
(2, 'david@demo.local',   'ORD-0016', 'confirmed'),
(3, 'emma@example.test',  NULL,       'pending');

-- =============================================================================
-- SUBSCRIPTIONS (6 rows)
-- =============================================================================

INSERT INTO `Subscriptions`
    (`subscription_no`, `customer_name`, `customer_email`,
     `product_id`, `product_category_id`, `status`, `amount`, `gateway`,
     `gateway_subscription_id`, `start_date`, `expiry_date`, `next_renewal_date`,
     `last_paid_at`, `renewal_count`, `duration_value`, `duration_unit`,
     `remaining_month_price`, `product_name_snapshot`, `variant_name_snapshot`,
     `customer_phone`, `first_month_price`, `last_reminder_sent_at`)
VALUES
('SUB-0001','Carol Demo', 'carol@example.test','2','1','active',    97.00,'senangpay','DEMO-GW-SUB001','2026-01-20','2026-07-20','2026-06-20','2026-05-20 09:30:00',4,1,'month', 97.00,'Pro Membership','Solo Plan', NULL,              47.00, NULL),
('SUB-0002','David Demo', 'david@demo.local',  '2','1','active',    97.00,'senangpay','DEMO-GW-SUB002','2026-02-01','2026-08-01','2026-07-01','2026-05-01 08:00:00',3,1,'month', 97.00,'Pro Membership','Solo Plan', NULL,              47.00, NULL),
('SUB-0003','Emma Demo',  'emma@example.test', '2','2','active',   147.00,'senangpay','DEMO-GW-SUB003','2026-02-05','2026-08-05','2026-07-05','2026-05-05 14:00:00',3,1,'month',147.00,'Pro Membership','Team Plan', NULL,              97.00, NULL),
('SUB-0004','Frank Demo', 'frank@demo.local',  '3', NULL,'active',  333.00,'senangpay','DEMO-GW-SUB004','2026-02-10','2026-08-10','2026-07-10','2026-03-10 10:00:00',2,6,'month',333.00,'Business Bootcamp',NULL,  '+60 17-234 5678', 333.00, NULL),
('SUB-0005','Grace Demo', 'grace@example.test','3', NULL,'paused',  333.00,'senangpay','DEMO-GW-SUB005','2026-01-15','2026-07-15','2026-06-15','2026-01-15 10:00:00',1,6,'month',333.00,'Business Bootcamp',NULL,  NULL,              333.00, NULL),
('SUB-0006','Henry Demo', 'henry@demo.local',  '2','1','cancelled', 97.00,'senangpay','DEMO-GW-SUB006','2025-11-01','2026-05-01', NULL,        '2026-04-01 10:00:00',6,1,'month', 97.00,'Pro Membership','Solo Plan', '+60 12-345 6789', 47.00, '2026-04-15 09:00:00');

-- Billing history (10 rows)
INSERT INTO `Subscription_Billing_History`
    (`subscription_id`, `payment_id`, `order_id`, `transaction_ref`, `amount`, `status`, `billing_type`, `paid_at`, `notes`)
VALUES
(1, 3,    'ORD-0003', 'DEMO-SP-C9D0E1F2',  47.00, 'success', 'initial_full',         '2026-01-20 09:30:00', 'First month — Solo Plan'),
(1, NULL, NULL,       NULL,                 97.00, 'success', 'installment',           '2026-02-20 09:30:00', 'Month 2 renewal'),
(1, NULL, NULL,       NULL,                 97.00, 'success', 'installment',           '2026-03-20 09:30:00', 'Month 3 renewal'),
(2, 4,    'ORD-0004', 'DEMO-SP-A3B4C5D6',  47.00, 'success', 'initial_full',         '2026-02-01 08:00:00', 'First month — Solo Plan'),
(2, NULL, NULL,       NULL,                 97.00, 'success', 'installment',           '2026-03-01 08:00:00', 'Month 2 renewal'),
(3, 5,    'ORD-0005', 'DEMO-SP-E7F8A9B0',  97.00, 'success', 'initial_full',         '2026-02-05 14:00:00', 'First month — Team Plan'),
(4, 6,    'ORD-0006', 'DEMO-SP-C1D2E3F4', 333.00, 'success', 'initial_installment',  '2026-02-10 10:00:00', 'Instalment 1 of 6'),
(4, 7,    'ORD-0007', 'DEMO-SP-A5B6C7D8', 333.00, 'success', 'installment',           '2026-03-10 10:00:00', 'Instalment 2 of 6'),
(5, NULL, NULL,       NULL,               333.00, 'success', 'initial_installment',  '2026-01-15 10:00:00', 'Instalment 1 of 6'),
(5, NULL, NULL,       NULL,               333.00, 'failed',  'installment',           '2026-02-15 10:00:00', 'Card declined — subscription paused');

-- Action tokens (3 rows)
INSERT INTO `Subscription_Action_Tokens`
    (`token_hash`, `subscription_id`, `action_type`, `expires_at`, `used_at`)
VALUES
(SHA2(CONCAT('demo-cancel-sub1-salt-2026'), 256),  1, 'cancel', '2026-07-01 23:59:59', NULL),
(SHA2(CONCAT('demo-pause-sub3-salt-2026'),  256),  3, 'pause',  '2026-07-05 23:59:59', NULL),
(SHA2(CONCAT('demo-resume-sub5-salt-2026'), 256),  5, 'resume', '2026-05-20 23:59:59', '2026-05-15 14:00:00');

-- =============================================================================
-- WEBINAR MODULE
-- =============================================================================

INSERT INTO `sdc_webinars`
    (`id`, `webinar_title`, `webinar_desc`, `start_datetime`, `end_datetime`, `timezone`,
     `poster_url`, `zoom_join_url`, `email_subject`, `status`, `capacity`, `recording_url`)
VALUES
(1, 'Demo Strategy Webinar',
   'An introductory webinar covering demo strategies and best practices.',
   '2026-03-15 14:00:00', '2026-03-15 15:30:00', 'Asia/Kuala_Lumpur',
   '/img/demo/webinar-1.svg', 'https://zoom.example.test/j/00000001',
   'Join Us: Demo Strategy Webinar', 'completed', 100,
   'https://drive.example.test/rec/demo-strategy-webinar'),
(2, 'Advanced Tactics Workshop',
   'Deep-dive workshop on advanced implementation tactics for experienced practitioners.',
   '2026-06-20 15:00:00', '2026-06-20 16:30:00', 'Asia/Kuala_Lumpur',
   '/img/demo/webinar-2.svg', 'https://zoom.example.test/j/00000002',
   'Join Us: Advanced Tactics Workshop', 'published', 50, NULL),
(3, 'Foundations Masterclass',
   'Beginner-friendly masterclass covering core foundations.',
   '2026-08-10 09:00:00', '2026-08-10 11:00:00', 'Asia/Kuala_Lumpur',
   NULL, NULL,
   'Join Us: Foundations Masterclass', 'draft', 200, NULL);

-- Registrations (15 rows, 5 per webinar)
INSERT INTO `sdc_webinar_registrations`
    (`webinar_id`, `name`, `email`, `phone`, `consent`, `attended`)
VALUES
-- webinar 1 completed: 4 attended, 1 no-show
(1, 'Alice Demo',  'alice@example.test',  '+60111000001', 1, 1),
(1, 'Bob Demo',    'bob@example.test',    '+60111000002', 1, 1),
(1, 'Carol Demo',  'carol@example.test',  '+60111000003', 1, 1),
(1, 'David Demo',  'david@demo.local',    '+60111000004', 1, 1),
(1, 'Emma Demo',   'emma@example.test',   '+60111000005', 1, 0),
-- webinar 2 upcoming: registered, not yet attended
(2, 'Frank Demo',  'frank@demo.local',    '+60111000006', 1, 0),
(2, 'Grace Demo',  'grace@example.test',  '+60111000007', 1, 0),
(2, 'Henry Demo',  'henry@demo.local',    '+60111000008', 1, 0),
(2, 'Iris Demo',   'iris@example.test',   '+60111000009', 1, 0),
(2, 'Jack Demo',   'jack@demo.local',     '+60111000010', 1, 0),
-- webinar 3 draft
(3, 'Kate Demo',   'kate@example.test',   '+60111000011', 1, 0),
(3, 'Liam Demo',   'liam@demo.local',     '+60111000012', 1, 0),
(3, 'Alice Demo',  'alice@example.test',  '+60111000001', 1, 0),
(3, 'Bob Demo',    'bob@example.test',    '+60111000002', 1, 0),
(3, 'Carol Demo',  'carol@example.test',  '+60111000003', 1, 0);

-- Reminder logs (8 rows for webinar 1)
INSERT INTO `sdc_webinar_reminders`
    (`webinar_id`, `registration_id`, `email`, `reminder_type`, `due_at`, `sent_at`, `status`, `error_message`)
VALUES
(1, 1, 'alice@example.test', '24h', '2026-03-14 14:00:00', '2026-03-14 14:00:00', 'sent',   NULL),
(1, 2, 'bob@example.test',   '24h', '2026-03-14 14:00:00', '2026-03-14 14:00:01', 'sent',   NULL),
(1, 3, 'carol@example.test', '24h', '2026-03-14 14:00:00', '2026-03-14 14:00:02', 'sent',   NULL),
(1, 4, 'david@demo.local',   '24h', '2026-03-14 14:00:00', '2026-03-14 14:00:03', 'sent',   NULL),
(1, 1, 'alice@example.test', '1h',  '2026-03-15 13:00:00', '2026-03-15 13:00:01', 'sent',   NULL),
(1, 2, 'bob@example.test',   '1h',  '2026-03-15 13:00:00', '2026-03-15 13:00:02', 'sent',   NULL),
(1, 3, 'carol@example.test', '1h',  '2026-03-15 13:00:00', '2026-03-15 13:00:03', 'sent',   NULL),
(1, 5, 'emma@example.test',  '24h', '2026-03-14 14:00:00', NULL,                  'failed', 'SMTP connection refused: max retries exceeded');

-- Reminder logs for webinar 2 (upcoming 2026-06-20) and webinar 3 (draft)
INSERT INTO `sdc_webinar_reminders`
    (`webinar_id`, `registration_id`, `email`, `reminder_type`, `due_at`, `sent_at`, `status`, `error_message`)
VALUES
-- webinar 2: registration confirmations sent at sign-up
(2,  6, 'frank@demo.local',   'registration', NULL,                  '2026-05-10 10:00:01', 'sent',    NULL),
(2,  7, 'grace@example.test', 'registration', NULL,                  '2026-05-10 10:00:02', 'sent',    NULL),
(2,  8, 'henry@demo.local',   'registration', NULL,                  '2026-05-10 10:00:03', 'sent',    NULL),
(2,  9, 'iris@example.test',  'registration', NULL,                  '2026-05-10 10:00:04', 'sent',    NULL),
-- webinar 2: 24h reminders queued (due 2026-06-19, not yet sent)
(2,  6, 'frank@demo.local',   '24h',          '2026-06-19 15:00:00', NULL,                  'pending', NULL),
(2,  7, 'grace@example.test', '24h',          '2026-06-19 15:00:00', NULL,                  'pending', NULL),
(2,  9, 'iris@example.test',  '24h',          '2026-06-19 15:00:00', NULL,                  'pending', NULL),
(2, 10, 'jack@demo.local',    '24h',          '2026-06-19 15:00:00', NULL,                  'pending', NULL),
-- webinar 3: registration confirmations pending (draft, no start_datetime set)
(3, 11, 'kate@example.test',  'registration', NULL,                  NULL,                  'pending', NULL),
(3, 12, 'liam@demo.local',    'registration', NULL,                  NULL,                  'pending', NULL),
-- webinar 3: 24h reminders pending (no start_datetime confirmed yet)
(3, 13, 'alice@example.test', '24h',          NULL,                  NULL,                  'pending', NULL),
(3, 14, 'bob@example.test',   '24h',          NULL,                  NULL,                  'pending', NULL);

-- Marketing email templates (4 rows)
INSERT INTO `sdc_webinar_marketing_emails`
    (`webinar_id`, `title`, `subject`, `body_html`,
     `delay_value`, `delay_unit`, `send_before_webinar_only`, `apply_to_existing`, `status`, `sort_order`)
VALUES
(1, 'Registration Confirmation', 'You are registered for Demo Strategy Webinar',        '<p>Thank you for registering. We look forward to seeing you!</p>', NULL, 'hours', 0, 0, 'active', 1),
(1, '24h Reminder',              'Reminder: Demo Strategy Webinar is tomorrow',          '<p>Just one day away — see you soon!</p>',                          24,   'hours', 1, 1, 'active', 2),
(2, 'Registration Confirmation', 'You are registered for Advanced Tactics Workshop',     '<p>Thank you for registering. We look forward to seeing you!</p>', NULL, 'hours', 0, 0, 'active', 1),
(2, '24h Reminder',              'Reminder: Advanced Tactics Workshop is tomorrow',      '<p>Just one day away — see you soon!</p>',                          24,   'hours', 1, 1, 'active', 2),
(3, 'Registration Confirmation', 'You are registered for Foundations Masterclass',       '<p>Thank you for registering. We look forward to seeing you!</p>', NULL, 'hours', 0, 0, 'active', 1),
(3, '24h Reminder',              'Reminder: Foundations Masterclass is tomorrow',        '<p>Just one day away — see you soon!</p>',                          24,   'hours', 1, 1, 'active', 2);

-- Marketing send logs (6 rows)
INSERT INTO `sdc_webinar_marketing_logs`
    (`webinar_id`, `marketing_email_id`, `recipient`, `status`, `event_at`)
VALUES
(1, 1, 'alice@example.test', 'sent',   '2026-01-20 09:30:01'),
(1, 1, 'bob@example.test',   'sent',   '2026-01-20 09:30:02'),
(1, 1, 'carol@example.test', 'sent',   '2026-01-20 09:30:03'),
(1, 2, 'alice@example.test', 'sent',   '2026-03-14 14:00:01'),
(1, 2, 'bob@example.test',   'sent',   '2026-03-14 14:00:02'),
(1, 2, 'emma@example.test',  'failed', '2026-03-14 14:00:03');

-- Marketing send logs for webinars 2 and 3
INSERT INTO `sdc_webinar_marketing_logs`
    (`webinar_id`, `marketing_email_id`, `recipient`, `status`, `event_at`)
VALUES
-- webinar 2: registration confirmations (marketing_email_id = 3)
(2, 3, 'frank@demo.local',   'sent',   '2026-05-10 10:05:01'),
(2, 3, 'grace@example.test', 'sent',   '2026-05-10 10:05:02'),
(2, 3, 'henry@demo.local',   'sent',   '2026-05-10 10:05:03'),
(2, 3, 'iris@example.test',  'failed', '2026-05-10 10:05:04'),
-- webinar 3: registration confirmations (marketing_email_id = 5)
(3, 5, 'kate@example.test',  'sent',   '2026-05-15 09:00:01'),
(3, 5, 'liam@demo.local',    'sent',   '2026-05-15 09:00:02');

-- =============================================================================
-- E-LEARNING MODULE
-- =============================================================================

-- courses.id is the admin-assigned string course key (code treats it as VARCHAR)
INSERT INTO `courses`
    (`id`, `title`, `slug`, `description`, `level`, `price`, `original_price`,
     `duration`, `instructor`, `image`, `cover_url`, `status`)
VALUES
('dmf-2024', 'Digital Marketing Foundations', 'digital-marketing-foundations',
   'Master the fundamentals of digital marketing in this comprehensive introductory course.',
   'beginner', 197.00, 297.00, '8 hours', 'Demo Instructor',
   '/img/demo/course-1.svg', '/img/demo/course-1.svg', 'published'),
('bgs-2024', 'Business Growth Strategies', 'business-growth-strategies',
   'Advanced strategies for scaling your business and increasing revenue sustainably.',
   'intermediate', 397.00, 597.00, '12 hours', 'Demo Instructor',
   '/img/demo/course-2.svg', '/img/demo/course-2.svg', 'published'),
('adi-2024', 'Analytics and Data Insights', 'analytics-data-insights',
   'Learn to analyse data and make informed, evidence-based business decisions.',
   'advanced', 497.00, 697.00, '15 hours', 'Demo Instructor',
   '/img/demo/course-3.svg', '/img/demo/course-3.svg', 'draft');

-- Course videos (8 rows) — course_id matches courses.id (VARCHAR key)
INSERT INTO `course_videos`
    (`course_id`, `title`, `url`, `duration_sec`, `order_index`, `drive_file_id`)
VALUES
('dmf-2024', 'Introduction to Digital Marketing',   NULL, 1800, 1, 'DEMO_DRIVE_VID_001'),
('dmf-2024', 'SEO Fundamentals',                    NULL, 2400, 2, 'DEMO_DRIVE_VID_002'),
('dmf-2024', 'Social Media Strategy',               NULL, 2100, 3, 'DEMO_DRIVE_VID_003'),
('bgs-2024', 'Growth Frameworks Overview',          NULL, 2700, 1, 'DEMO_DRIVE_VID_004'),
('bgs-2024', 'Customer Acquisition Channels',       NULL, 3000, 2, 'DEMO_DRIVE_VID_005'),
('bgs-2024', 'Scaling Operations Efficiently',      NULL, 2400, 3, 'DEMO_DRIVE_VID_006'),
('adi-2024', 'Introduction to Analytics',           NULL, 1800, 1, 'DEMO_DRIVE_VID_007'),
('adi-2024', 'Data Visualisation Basics',           NULL, 2100, 2, 'DEMO_DRIVE_VID_008');

-- Course ebooks (6 rows) — course_id matches courses.id (VARCHAR key)
INSERT INTO `course_ebooks`
    (`course_id`, `title`, `drive_file_id`, `order_index`)
VALUES
('dmf-2024', 'Digital Marketing Workbook',     'DEMO_DRIVE_EB_001', 1),
('dmf-2024', 'SEO Quick Reference Sheet',      'DEMO_DRIVE_EB_002', 2),
('bgs-2024', 'Growth Hacker Playbook',         'DEMO_DRIVE_EB_003', 1),
('bgs-2024', 'Revenue Optimisation Guide',     'DEMO_DRIVE_EB_004', 2),
('adi-2024', 'Analytics Quick Reference',      'DEMO_DRIVE_EB_005', 1),
('adi-2024', 'Data Storytelling Handbook',     'DEMO_DRIVE_EB_006', 2);

-- Course workbooks (3 rows, 1 per course) — course_id matches courses.id (VARCHAR key)
INSERT INTO `course_workbooks`
    (`course_id`, `title`, `template_file_id`, `sheet_id`)
VALUES
('dmf-2024', 'Marketing Plan Template',          'DEMO_TMPL_001', 'DEMO_SHEET_001'),
('bgs-2024', 'Business Growth Planner',          'DEMO_TMPL_002', 'DEMO_SHEET_002'),
('adi-2024', 'Analytics Dashboard Template',     'DEMO_TMPL_003', 'DEMO_SHEET_003');

-- Course certificates (4 rows)
INSERT INTO `course_certificates`
    (`user_id`, `course_id`, `cert_no`, `issued_at`, `sent_at`, `sent_to`)
VALUES
(1, 'dmf-2024', 'CERT-DMF-0001', '2026-02-10 10:00:00', '2026-02-10 10:05:00', 'alice@example.test'),
(2, 'dmf-2024', 'CERT-DMF-0002', '2026-02-14 15:00:00', '2026-02-14 15:05:00', 'bob@example.test'),
(3, 'dmf-2024', 'CERT-DMF-0003', '2026-02-28 11:00:00', '2026-02-28 11:05:00', 'carol@example.test'),
(1, 'bgs-2024', 'CERT-BGS-0001', '2026-03-20 09:00:00', NULL,                  NULL);

-- Course waitlist (5 rows)
INSERT INTO `course_waitlist`
    (`email`, `level`, `token`, `status`)
VALUES
('mike@example.test',  'advanced',     SHA2('waitlist-mike-advanced-demo',     256), 'subscribed'),
('nina@demo.local',    'beginner',     SHA2('waitlist-nina-beginner-demo',      256), 'subscribed'),
('oscar@example.test', 'intermediate', SHA2('waitlist-oscar-intermediate-demo', 256), 'subscribed'),
('petra@demo.local',   'advanced',     SHA2('waitlist-petra-advanced-demo',     256), 'subscribed'),
('quinn@example.test', 'beginner',     SHA2('waitlist-quinn-beginner-demo',     256), 'unsubscribed');

-- Course notify jobs (2 rows)
INSERT INTO `course_notify_jobs`
    (`level`, `course_key`, `course_title`, `course_url`, `status`)
VALUES
('beginner',  'dmf-2024', 'Digital Marketing Foundations', '/course/digital-marketing-foundations', 'completed'),
('advanced',  'adi-2024', 'Analytics and Data Insights',   '/course/analytics-data-insights',       'pending');

-- =============================================================================
-- E-LEARNING USERS (12 rows)
-- =============================================================================

INSERT INTO `user` (`id`, `name`, `email`, `usertype`, `enrolled_at`) VALUES
( 1, 'Alice Demo',  'alice@example.test',  0, '2026-01-10 10:30:00'),
( 2, 'Bob Demo',    'bob@example.test',    0, '2026-01-15 11:45:00'),
( 3, 'Carol Demo',  'carol@example.test',  0, '2026-01-20 09:45:00'),
( 4, 'David Demo',  'david@demo.local',    0, '2026-02-01 08:30:00'),
( 5, 'Emma Demo',   'emma@example.test',   0, '2026-02-05 14:30:00'),
( 6, 'Frank Demo',  'frank@demo.local',    0, '2026-02-10 10:30:00'),
( 7, 'Grace Demo',  'grace@example.test',  0, '2026-02-15 09:00:00'),
( 8, 'Henry Demo',  'henry@demo.local',    0, '2026-02-20 15:45:00'),
( 9, 'Iris Demo',   'iris@example.test',   0, '2026-03-01 09:15:00'),
(10, 'Jack Demo',   'jack@demo.local',     0, NULL),
(11, 'Kate Demo',   'kate@example.test',   0, NULL),
(12, 'Liam Demo',   'liam@demo.local',     0, NULL);

-- User progress (54 rows, one per content item, includes content_type for progress.php aggregation)
-- dmf-2024: 3 videos + 2 ebooks + 1 workbook = 6 items
-- bgs-2024: 3 videos + 2 ebooks + 1 workbook = 6 items
-- adi-2024: 2 videos + 2 ebooks + 1 workbook = 5 items
INSERT INTO `user_progress`
    (`user_id`, `course_id`, `content_type`, `completed`, `completed_at`, `created_at`)
VALUES
-- Alice (1) — dmf-2024 COMPLETED (6/6) — cert CERT-DMF-0001
(1, 'dmf-2024', 'video',    1, '2026-01-25 09:00:00', '2026-01-25 09:00:00'),
(1, 'dmf-2024', 'video',    1, '2026-01-27 09:00:00', '2026-01-27 09:00:00'),
(1, 'dmf-2024', 'video',    1, '2026-01-30 09:00:00', '2026-01-30 09:00:00'),
(1, 'dmf-2024', 'ebook',    1, '2026-01-31 09:00:00', '2026-01-31 09:00:00'),
(1, 'dmf-2024', 'ebook',    1, '2026-02-04 09:00:00', '2026-02-04 09:00:00'),
(1, 'dmf-2024', 'workbook', 1, '2026-02-08 10:00:00', '2026-02-08 10:00:00'),
-- Alice (1) — bgs-2024 COMPLETED (6/6) — cert CERT-BGS-0001
(1, 'bgs-2024', 'video',    1, '2026-02-15 10:00:00', '2026-02-15 10:00:00'),
(1, 'bgs-2024', 'video',    1, '2026-02-20 10:00:00', '2026-02-20 10:00:00'),
(1, 'bgs-2024', 'video',    1, '2026-02-25 10:00:00', '2026-02-25 10:00:00'),
(1, 'bgs-2024', 'ebook',    1, '2026-03-01 10:00:00', '2026-03-01 10:00:00'),
(1, 'bgs-2024', 'ebook',    1, '2026-03-08 10:00:00', '2026-03-08 10:00:00'),
(1, 'bgs-2024', 'workbook', 1, '2026-03-18 09:00:00', '2026-03-18 09:00:00'),
-- Alice (1) — adi-2024 IN-PROGRESS (3/5: 2 videos + 1 ebook)
(1, 'adi-2024', 'video',    1, '2026-04-05 10:00:00', '2026-04-05 10:00:00'),
(1, 'adi-2024', 'video',    1, '2026-04-10 10:00:00', '2026-04-10 10:00:00'),
(1, 'adi-2024', 'ebook',    1, '2026-04-15 10:00:00', '2026-04-15 10:00:00'),
-- Bob (2) — dmf-2024 COMPLETED (6/6) — cert CERT-DMF-0002
(2, 'dmf-2024', 'video',    1, '2026-01-20 11:00:00', '2026-01-20 11:00:00'),
(2, 'dmf-2024', 'video',    1, '2026-01-22 11:00:00', '2026-01-22 11:00:00'),
(2, 'dmf-2024', 'video',    1, '2026-01-25 11:00:00', '2026-01-25 11:00:00'),
(2, 'dmf-2024', 'ebook',    1, '2026-01-28 11:00:00', '2026-01-28 11:00:00'),
(2, 'dmf-2024', 'ebook',    1, '2026-02-02 11:00:00', '2026-02-02 11:00:00'),
(2, 'dmf-2024', 'workbook', 1, '2026-02-14 15:00:00', '2026-02-14 15:00:00'),
-- Bob (2) — adi-2024 JUST-STARTED (1/5: 1 video)
(2, 'adi-2024', 'video',    1, '2026-04-20 14:00:00', '2026-04-20 14:00:00'),
-- Carol (3) — dmf-2024 COMPLETED (6/6) — cert CERT-DMF-0003
(3, 'dmf-2024', 'video',    1, '2026-01-25 09:30:00', '2026-01-25 09:30:00'),
(3, 'dmf-2024', 'video',    1, '2026-01-28 09:30:00', '2026-01-28 09:30:00'),
(3, 'dmf-2024', 'video',    1, '2026-02-01 09:30:00', '2026-02-01 09:30:00'),
(3, 'dmf-2024', 'ebook',    1, '2026-02-04 09:30:00', '2026-02-04 09:30:00'),
(3, 'dmf-2024', 'ebook',    1, '2026-02-10 09:30:00', '2026-02-10 09:30:00'),
(3, 'dmf-2024', 'workbook', 1, '2026-02-28 11:00:00', '2026-02-28 11:00:00'),
-- Carol (3) — bgs-2024 IN-PROGRESS (3/6: 2 videos + 1 ebook)
(3, 'bgs-2024', 'video',    1, '2026-03-10 10:00:00', '2026-03-10 10:00:00'),
(3, 'bgs-2024', 'video',    1, '2026-03-15 10:00:00', '2026-03-15 10:00:00'),
(3, 'bgs-2024', 'ebook',    1, '2026-03-20 10:00:00', '2026-03-20 10:00:00'),
-- David (4) — bgs-2024 IN-PROGRESS (4/6: 3 videos + 1 ebook)
(4, 'bgs-2024', 'video',    1, '2026-02-05 08:30:00', '2026-02-05 08:30:00'),
(4, 'bgs-2024', 'video',    1, '2026-02-10 08:30:00', '2026-02-10 08:30:00'),
(4, 'bgs-2024', 'video',    1, '2026-02-15 08:30:00', '2026-02-15 08:30:00'),
(4, 'bgs-2024', 'ebook',    1, '2026-02-20 08:30:00', '2026-02-20 08:30:00'),
-- David (4) — adi-2024 IN-PROGRESS (3/5: 1 video + 2 ebooks)
(4, 'adi-2024', 'video',    1, '2026-03-10 08:30:00', '2026-03-10 08:30:00'),
(4, 'adi-2024', 'ebook',    1, '2026-03-20 08:30:00', '2026-03-20 08:30:00'),
(4, 'adi-2024', 'ebook',    1, '2026-04-01 08:30:00', '2026-04-01 08:30:00'),
-- Emma (5) — bgs-2024 JUST-STARTED (1/6: 1 video)
(5, 'bgs-2024', 'video',    1, '2026-05-05 14:30:00', '2026-05-05 14:30:00'),
-- Frank (6) — bgs-2024 IN-PROGRESS (4/6: 3 videos + 1 ebook)
(6, 'bgs-2024', 'video',    1, '2026-02-15 10:30:00', '2026-02-15 10:30:00'),
(6, 'bgs-2024', 'video',    1, '2026-02-22 10:30:00', '2026-02-22 10:30:00'),
(6, 'bgs-2024', 'video',    1, '2026-03-01 10:30:00', '2026-03-01 10:30:00'),
(6, 'bgs-2024', 'ebook',    1, '2026-03-08 10:30:00', '2026-03-08 10:30:00'),
-- Grace (7) — dmf-2024 IN-PROGRESS (4/6: 3 videos + 1 ebook)
(7, 'dmf-2024', 'video',    1, '2026-02-20 09:00:00', '2026-02-20 09:00:00'),
(7, 'dmf-2024', 'video',    1, '2026-02-25 09:00:00', '2026-02-25 09:00:00'),
(7, 'dmf-2024', 'video',    1, '2026-03-01 09:00:00', '2026-03-01 09:00:00'),
(7, 'dmf-2024', 'ebook',    1, '2026-03-08 09:00:00', '2026-03-08 09:00:00'),
-- Henry (8) — dmf-2024 IN-PROGRESS (3/6: 2 videos + 1 ebook)
(8, 'dmf-2024', 'video',    1, '2026-02-25 15:45:00', '2026-02-25 15:45:00'),
(8, 'dmf-2024', 'video',    1, '2026-03-02 15:45:00', '2026-03-02 15:45:00'),
(8, 'dmf-2024', 'ebook',    1, '2026-03-10 15:45:00', '2026-03-10 15:45:00'),
-- Iris (9) — dmf-2024 JUST-STARTED (2/6: 1 video + 1 ebook)
(9, 'dmf-2024', 'video',    1, '2026-03-05 09:15:00', '2026-03-05 09:15:00'),
(9, 'dmf-2024', 'ebook',    1, '2026-03-15 09:15:00', '2026-03-15 09:15:00'),
-- Iris (9) — bgs-2024 JUST-STARTED (2/6: 2 videos)
(9, 'bgs-2024', 'video',    1, '2026-03-20 09:15:00', '2026-03-20 09:15:00'),
(9, 'bgs-2024', 'video',    1, '2026-03-28 09:15:00', '2026-03-28 09:15:00');

-- User workbooks (6 rows)
INSERT INTO `user_workbooks`
    (`user_id`, `course_id`, `workbook_id`, `user_file_id`)
VALUES
(1, 'dmf-2024', 1, 'DEMO_USER_FILE_001'),
(2, 'dmf-2024', 1, 'DEMO_USER_FILE_002'),
(3, 'dmf-2024', 1,  NULL),
(1, 'bgs-2024', 2, 'DEMO_USER_FILE_003'),
(4, 'bgs-2024', 2,  NULL),
(1, 'adi-2024', 3,  NULL);

-- =============================================================================
-- EMAIL CAMPAIGN MODULE
-- =============================================================================

INSERT INTO `email_campaigns`
    (`campaign_uid`, `campaign_name`, `subject`, `campaign_type`, `status`,
     `total_recipients`, `sent_count`, `failed_count`, `delivered_count`,
     `opened_count`, `clicked_count`, `open_rate`, `click_rate`,
     `created_by`, `sent_at`, `scheduled_for`,
     `brand_name`, `support_email`, `email_body`,
     `queue_status`)
VALUES
('UID-CAMP-0001', 'Welcome Series July 2025',      'Welcome to the Demo Community',           'manual_blast',       'sent',      50, 47, 3, 45, 22, 8, 46.81, 17.02, 'admin@demo.local', '2025-07-10 09:00:00', NULL,                  'Demo Brand', 'support@demo.local', '<p>Welcome to our community!</p>',                  'completed'),
('UID-CAMP-0002', 'Product Launch Announcement',   'Introducing Our New Programme',           'manual_blast',       'draft',      0,  0, 0,  0,  0, 0,  0.00,  0.00, 'admin@demo.local', NULL,                  NULL,                  'Demo Brand', 'support@demo.local', '<p>We are excited to announce something new.</p>',  'none'),
('UID-CAMP-0003', 'Re-engagement Campaign',        'We Miss You — Come Back',                 'targeted_campaign',  'scheduled',  0,  0, 0,  0,  0, 0,  0.00,  0.00, 'admin@demo.local', NULL,                  '2026-06-01 09:00:00', 'Demo Brand', 'support@demo.local', '<p>We have not seen you in a while!</p>',            'none'),
('UID-CAMP-0004', 'Webinar Reminder Blast',        'Reminder: Demo Strategy Webinar Tomorrow','manual_blast',       'sent',      30, 30, 0, 29, 18, 5, 62.07, 17.24, 'admin@demo.local', '2026-03-14 09:00:00', NULL,                  'Demo Brand', 'support@demo.local', '<p>Do not forget about tomorrows webinar!</p>',     'completed');

-- Campaign recipients (12 rows: 5 for camp 1, 7 for camp 4)
INSERT INTO `email_campaign_recipients`
    (`campaign_id`, `recipient_email`, `recipient_name`,
     `tracking_token`, `delivery_status`,
     `opened`, `first_open_at`, `open_count`,
     `clicked`, `first_click_at`, `click_count`)
VALUES
-- campaign 1 (Welcome Series)
(1, 'alice@example.test', 'Alice Demo', SHA2('track-c1-alice-2025',  256), 'delivered', 1, '2025-07-10 10:15:00', 2, 1, '2025-07-10 10:20:00', 1),
(1, 'bob@example.test',   'Bob Demo',   SHA2('track-c1-bob-2025',    256), 'delivered', 1, '2025-07-10 11:00:00', 1, 0,  NULL,                  0),
(1, 'carol@example.test', 'Carol Demo', SHA2('track-c1-carol-2025',  256), 'delivered', 0,  NULL,                 0, 0,  NULL,                  0),
(1, 'david@demo.local',   'David Demo', SHA2('track-c1-david-2025',  256), 'delivered', 1, '2025-07-10 14:30:00', 1, 1, '2025-07-10 14:35:00', 2),
(1, 'emma@example.test',  'Emma Demo',  SHA2('track-c1-emma-2025',   256), 'failed',    0,  NULL,                 0, 0,  NULL,                  0),
-- campaign 4 (Webinar Reminder)
(4, 'alice@example.test', 'Alice Demo', SHA2('track-c4-alice-2026',  256), 'delivered', 1, '2026-03-14 09:30:00', 1, 0,  NULL,                  0),
(4, 'bob@example.test',   'Bob Demo',   SHA2('track-c4-bob-2026',    256), 'delivered', 1, '2026-03-14 10:00:00', 1, 1, '2026-03-14 10:05:00', 1),
(4, 'carol@example.test', 'Carol Demo', SHA2('track-c4-carol-2026',  256), 'delivered', 0,  NULL,                 0, 0,  NULL,                  0),
(4, 'david@demo.local',   'David Demo', SHA2('track-c4-david-2026',  256), 'delivered', 1, '2026-03-14 10:30:00', 1, 0,  NULL,                  0),
(4, 'emma@example.test',  'Emma Demo',  SHA2('track-c4-emma-2026',   256), 'delivered', 0,  NULL,                 0, 0,  NULL,                  0),
(4, 'frank@demo.local',   'Frank Demo', SHA2('track-c4-frank-2026',  256), 'delivered', 1, '2026-03-14 11:00:00', 1, 1, '2026-03-14 11:05:00', 1),
(4, 'grace@example.test', 'Grace Demo', SHA2('track-c4-grace-2026',  256), 'delivered', 0,  NULL,                 0, 0,  NULL,                  0);

-- Campaign link clicks (6 rows; recipient IDs: 1–5 = camp1, 6–12 = camp4)
INSERT INTO `email_campaign_link_clicks`
    (`campaign_id`, `recipient_id`, `link_url`, `link_hash`, `link_position`, `clicked_at`)
VALUES
(1,  1, 'https://demo.example.test/product/1', SHA2('click-c1-r1-p1', 256), 1, '2025-07-10 10:20:00'),
(1,  4, 'https://demo.example.test/product/1', SHA2('click-c1-r4-p1', 256), 1, '2025-07-10 14:35:00'),
(1,  4, 'https://demo.example.test/product/2', SHA2('click-c1-r4-p2', 256), 2, '2025-07-10 14:40:00'),
(4,  7, 'https://demo.example.test/webinar/1', SHA2('click-c4-r7-p1', 256), 1, '2026-03-14 10:05:00'),
(4,  7, 'https://demo.example.test/register',  SHA2('click-c4-r7-p2', 256), 2, '2026-03-14 10:08:00'),
(4, 11, 'https://demo.example.test/webinar/1', SHA2('click-c4-r11-p1',256), 1, '2026-03-14 11:05:00');

-- Campaign send batches (3 rows)
INSERT INTO `email_campaign_send_batches`
    (`batch_uid`, `campaign_id`, `send_mode`, `initiated_by`,
     `batch_size`, `attempted_count`, `sent_count`, `failed_count`,
     `skipped_count`, `remaining_count`, `status`, `started_at`, `completed_at`)
VALUES
('BATCH-UID-001', 1, 'pending',            'admin@demo.local', 25, 50, 47, 3, 0, 0, 'partial_failed', '2025-07-10 09:00:00', '2025-07-10 09:05:00'),
('BATCH-UID-002', 1, 'failed',             'admin@demo.local', 25,  3,  3, 0, 0, 0, 'completed',      '2025-07-10 09:10:00', '2025-07-10 09:12:00'),
('BATCH-UID-003', 4, 'pending',            'admin@demo.local', 25, 30, 30, 0, 0, 0, 'completed',      '2026-03-14 09:00:00', '2026-03-14 09:03:00');

-- Audience groups (2 rows)
INSERT INTO `email_audience_groups`
    (`group_uid`, `group_name`, `description`, `source_type`,
     `total_members`, `active_members`, `unsubscribed_members`, `created_by`)
VALUES
('GRP-UID-001', 'All Subscribers',   'All email subscribers across all products and campaigns.', 'mixed',  12, 12, 0, 'admin@demo.local'),
('GRP-UID-002', 'Webinar Attendees', 'Subscribers who attended at least one webinar.',           'manual',  8,  8, 0, 'admin@demo.local');

-- Audience group members (20 rows: 12 in group 1, 8 in group 2)
INSERT INTO `email_audience_group_members`
    (`group_id`, `email`, `name`, `phone`, `status`, `added_by`)
VALUES
-- group 1
(1, 'alice@example.test',  'Alice Demo',  '+60111000001', 'active', 'admin@demo.local'),
(1, 'bob@example.test',    'Bob Demo',    '+60111000002', 'active', 'admin@demo.local'),
(1, 'carol@example.test',  'Carol Demo',  '+60111000003', 'active', 'admin@demo.local'),
(1, 'david@demo.local',    'David Demo',  '+60111000004', 'active', 'admin@demo.local'),
(1, 'emma@example.test',   'Emma Demo',   '+60111000005', 'active', 'admin@demo.local'),
(1, 'frank@demo.local',    'Frank Demo',  '+60111000006', 'active', 'admin@demo.local'),
(1, 'grace@example.test',  'Grace Demo',  '+60111000007', 'active', 'admin@demo.local'),
(1, 'henry@demo.local',    'Henry Demo',  '+60111000008', 'active', 'admin@demo.local'),
(1, 'iris@example.test',   'Iris Demo',   '+60111000009', 'active', 'admin@demo.local'),
(1, 'jack@demo.local',     'Jack Demo',   '+60111000010', 'active', 'admin@demo.local'),
(1, 'kate@example.test',   'Kate Demo',   '+60111000011', 'active', 'admin@demo.local'),
(1, 'liam@demo.local',     'Liam Demo',   '+60111000012', 'active', 'admin@demo.local'),
-- group 2 (webinar attendees from webinar 1)
(2, 'alice@example.test',  'Alice Demo',  '+60111000001', 'active', 'admin@demo.local'),
(2, 'bob@example.test',    'Bob Demo',    '+60111000002', 'active', 'admin@demo.local'),
(2, 'carol@example.test',  'Carol Demo',  '+60111000003', 'active', 'admin@demo.local'),
(2, 'david@demo.local',    'David Demo',  '+60111000004', 'active', 'admin@demo.local'),
(2, 'frank@demo.local',    'Frank Demo',  '+60111000006', 'active', 'admin@demo.local'),
(2, 'grace@example.test',  'Grace Demo',  '+60111000007', 'active', 'admin@demo.local'),
(2, 'henry@demo.local',    'Henry Demo',  '+60111000008', 'active', 'admin@demo.local'),
(2, 'iris@example.test',   'Iris Demo',   '+60111000009', 'active', 'admin@demo.local');

-- Email logs (12 rows)
INSERT INTO `email_logs`
    (`campaign_id`, `recipient_email`, `status`, `sent_at`, `opened_at`, `clicked_at`)
VALUES
(1, 'alice@example.test',  'clicked',   '2025-07-10 09:00:01', '2025-07-10 10:15:00', '2025-07-10 10:20:00'),
(1, 'bob@example.test',    'opened',    '2025-07-10 09:00:02', '2025-07-10 11:00:00',  NULL),
(1, 'carol@example.test',  'delivered', '2025-07-10 09:00:03',  NULL,                  NULL),
(1, 'david@demo.local',    'clicked',   '2025-07-10 09:00:04', '2025-07-10 14:30:00', '2025-07-10 14:35:00'),
(1, 'emma@example.test',   'failed',     NULL,                  NULL,                  NULL),
(4, 'alice@example.test',  'opened',    '2026-03-14 09:00:01', '2026-03-14 09:30:00',  NULL),
(4, 'bob@example.test',    'clicked',   '2026-03-14 09:00:02', '2026-03-14 10:00:00', '2026-03-14 10:05:00'),
(4, 'carol@example.test',  'delivered', '2026-03-14 09:00:03',  NULL,                  NULL),
(4, 'david@demo.local',    'opened',    '2026-03-14 09:00:04', '2026-03-14 10:30:00',  NULL),
(4, 'emma@example.test',   'delivered', '2026-03-14 09:00:05',  NULL,                  NULL),
(4, 'frank@demo.local',    'clicked',   '2026-03-14 09:00:06', '2026-03-14 11:00:00', '2026-03-14 11:05:00'),
(4, 'grace@example.test',  'delivered', '2026-03-14 09:00:07',  NULL,                  NULL);

-- Email templates (3 rows)
INSERT INTO `email_templates`
    (`product_type`, `product_category_id`, `target_scope`, `category`, `product_name`, `subject`,
     `preheader`, `badge_text`, `greeting`, `content`, `button_link`, `button_text`,
     `closing`, `brand_name`, `brand_email`, `support_email`, `footer_note`, `is_active`, `last_updated_by`)
VALUES
('__default', '', 'default', 'both',            'All Products (Default)',
 'Welcome to Demo Brand',
 'We are delighted to have you join us.',
 'Welcome',
 'Hi {{customer_name}},',
 '<p>Thank you for joining us. We are excited to have you on board.</p>',
 '', 'Get Started',
 'Best regards,\nDemo Team',
 'Demo', 'noreply@demo.local', 'support@demo.local',
 'You are receiving this email because of your recent activity.',
 1, 'admin'),

('1', '', 'product', 'non_elearning', 'Demo Product',
 'Your payment has been received',
 'Your order is confirmed and ready.',
 'Order Confirmed',
 'Hi {{customer_name}},',
 '<p>Thank you for your purchase of {{product_name}}. Your order has been confirmed and is being processed.</p>',
 '', 'View Order',
 'Best regards,\nDemo Team',
 'Demo', 'noreply@demo.local', 'support@demo.local',
 'You are receiving this email because of your recent purchase of {{product_name}} (RM {{product_price}}).',
 1, 'admin'),

('2', '', 'product', 'non_elearning', 'Demo Subscription',
 'Your webinar is coming up — see you soon',
 'Just a friendly reminder about your upcoming webinar.',
 'Webinar Reminder',
 'Hi {{customer_name}},',
 '<p>This is a friendly reminder that your webinar is coming up soon. Make sure you have the Zoom link ready.</p>',
 '', 'Get Zoom Link',
 'Best regards,\nDemo Team',
 'Demo', 'noreply@demo.local', 'support@demo.local',
 'You are receiving this email because you registered for an upcoming webinar.',
 1, 'admin');

-- =============================================================================
-- VOLUME TOP-UP
-- Brings showcase tables to spec volumes using information_schema row-generator.
-- MySQL 5.7 compatible: no CTEs, no window functions.
--   email_campaign_recipients    : +188 → ~200 total  (spec: 200-400)
--   email_audience_group_members : +142 → ~162 total  (spec: 150-250)
--   sdc_webinar_registrations    : +25  →  40 total   (spec:  40-60)
--   subscription_billing_history : +5   →  15 total   (spec:  15-25)
-- =============================================================================

-- ── email_campaign_recipients +188 rows (campaign 1) ──────────────────────────
-- Distribution: rows 1-30 delivered+opened+clicked, 31-100 opened, 101-155 delivered,
--               156-175 bounced, 176-188 failed
INSERT INTO `email_campaign_recipients`
    (`campaign_id`, `recipient_email`, `recipient_name`, `tracking_token`,
     `delivery_status`, `opened`, `first_open_at`, `open_count`,
     `clicked`, `first_click_at`, `click_count`)
SELECT
  1,
  CONCAT('c', LPAD(n, 3, '0'), IF(n % 2 = 1, '@example.test', '@demo.local')),
  CONCAT('Contact ', LPAD(n, 3, '0')),
  SHA2(CONCAT('bulk-c1-', LPAD(n, 3, '0')), 256),
  CASE WHEN n <= 155 THEN 'delivered' WHEN n <= 175 THEN 'bounced' ELSE 'failed' END,
  IF(n <= 100, 1, 0),
  IF(n <= 100, DATE_ADD('2025-07-10 09:00:00', INTERVAL n MINUTE), NULL),
  IF(n <= 100, 1, 0),
  IF(n <= 30, 1, 0),
  IF(n <= 30, DATE_ADD('2025-07-10 09:05:00', INTERVAL n MINUTE), NULL),
  IF(n <= 30, 1, 0)
FROM (
  SELECT (@ecr := @ecr + 1) AS n
  FROM information_schema.COLUMNS
  CROSS JOIN (SELECT @ecr := 0) init
  LIMIT 188
) AS nums_ecr;

-- ── email_audience_group_members +130 for group 1, +12 for group 2 ─────────────
INSERT INTO `email_audience_group_members`
    (`group_id`, `email`, `name`, `status`, `added_by`)
SELECT
  1,
  CONCAT('c', LPAD(n, 3, '0'), IF(n % 2 = 1, '@example.test', '@demo.local')),
  CONCAT('Contact ', LPAD(n, 3, '0')),
  'active',
  'admin@demo.local'
FROM (
  SELECT (@eagm := @eagm + 1) AS n
  FROM information_schema.COLUMNS
  CROSS JOIN (SELECT @eagm := 0) init
  LIMIT 130
) AS nums_eagm;

INSERT INTO `email_audience_group_members`
    (`group_id`, `email`, `name`, `status`, `added_by`)
SELECT
  2,
  CONCAT('c', LPAD(n, 3, '0'), IF(n % 2 = 1, '@example.test', '@demo.local')),
  CONCAT('Contact ', LPAD(n, 3, '0')),
  'active',
  'admin@demo.local'
FROM (
  SELECT (@eagm2 := @eagm2 + 1) AS n
  FROM information_schema.COLUMNS
  CROSS JOIN (SELECT @eagm2 := 0) init
  LIMIT 12
) AS nums_eagm2;

-- ── sdc_webinar_registrations +10 webinar 1, +10 webinar 2, +5 webinar 3 ───────
INSERT INTO `sdc_webinar_registrations` (`webinar_id`, `name`, `email`, `phone`, `consent`, `attended`)
SELECT 1,
  CONCAT('Webinar Reg ', LPAD(n, 2, '0')),
  CONCAT('wr', LPAD(n, 2, '0'), '@example.test'),
  NULL, 1, IF(n <= 7, 1, 0)
FROM (
  SELECT (@wr1 := @wr1 + 1) AS n
  FROM information_schema.COLUMNS
  CROSS JOIN (SELECT @wr1 := 0) init
  LIMIT 10
) AS nums_wr1;

INSERT INTO `sdc_webinar_registrations` (`webinar_id`, `name`, `email`, `phone`, `consent`, `attended`)
SELECT 2,
  CONCAT('Webinar Reg ', LPAD(n + 10, 2, '0')),
  CONCAT('wr', LPAD(n + 10, 2, '0'), '@example.test'),
  NULL, 1, 0
FROM (
  SELECT (@wr2 := @wr2 + 1) AS n
  FROM information_schema.COLUMNS
  CROSS JOIN (SELECT @wr2 := 0) init
  LIMIT 10
) AS nums_wr2;

INSERT INTO `sdc_webinar_registrations` (`webinar_id`, `name`, `email`, `phone`, `consent`, `attended`)
SELECT 3,
  CONCAT('Webinar Reg ', LPAD(n + 20, 2, '0')),
  CONCAT('wr', LPAD(n + 20, 2, '0'), '@example.test'),
  NULL, 1, 0
FROM (
  SELECT (@wr3 := @wr3 + 1) AS n
  FROM information_schema.COLUMNS
  CROSS JOIN (SELECT @wr3 := 0) init
  LIMIT 5
) AS nums_wr3;

-- ── subscription_billing_history +5 rows ─────────────────────────────────────
INSERT INTO `Subscription_Billing_History`
    (`subscription_id`, `payment_id`, `order_id`, `transaction_ref`, `amount`, `status`, `billing_type`, `paid_at`, `notes`)
VALUES
(1, NULL, NULL, NULL,  97.00, 'success', 'installment', '2026-04-20 09:30:00', 'Month 4 renewal'),
(2, NULL, NULL, NULL,  97.00, 'success', 'installment', '2026-04-01 08:00:00', 'Month 3 renewal'),
(2, NULL, NULL, NULL,  97.00, 'success', 'installment', '2026-05-01 08:00:00', 'Month 4 renewal'),
(3, NULL, NULL, NULL, 147.00, 'success', 'installment', '2026-03-05 14:00:00', 'Month 2 renewal'),
(3, NULL, NULL, NULL, 147.00, 'success', 'installment', '2026-04-05 14:00:00', 'Month 3 renewal');

-- ── Henry (SUB-0006) billing history — 6 payments before cancellation ─────────
INSERT INTO `Subscription_Billing_History`
    (`subscription_id`, `payment_id`, `order_id`, `transaction_ref`, `amount`, `status`, `billing_type`, `paid_at`, `notes`)
VALUES
(6, NULL, NULL, NULL, 47.00, 'success', 'initial_full', '2025-11-01 10:00:00', 'First month — Solo Plan'),
(6, NULL, NULL, NULL, 97.00, 'success', 'installment',  '2025-12-01 10:00:00', 'Month 2 renewal'),
(6, NULL, NULL, NULL, 97.00, 'success', 'installment',  '2026-01-01 10:00:00', 'Month 3 renewal'),
(6, NULL, NULL, NULL, 97.00, 'success', 'installment',  '2026-02-01 10:00:00', 'Month 4 renewal'),
(6, NULL, NULL, NULL, 97.00, 'success', 'installment',  '2026-03-01 10:00:00', 'Month 5 renewal'),
(6, NULL, NULL, NULL, 97.00, 'success', 'installment',  '2026-04-01 10:00:00', 'Month 6 renewal — subscription cancelled after this');

-- ── Sync aggregate stats to match topped-up row counts ────────────────────────
-- campaign 1: 5 orig + 188 new = 193 total
--   delivered=159, opened=103, clicked=32, bounced=20, failed=14
UPDATE `email_campaigns` SET
  total_recipients = 193,
  sent_count       = 179,
  failed_count     =  14,
  delivered_count  = 159,
  bounced_count    =  20,
  opened_count     = 103,
  clicked_count    =  32,
  open_rate        = 57.54,
  click_rate       = 17.88
WHERE id = 1;

-- group 1: 12 orig + 130 new = 142; group 2: 8 orig + 12 new = 20
UPDATE `email_audience_groups` SET total_members = 142, active_members = 142 WHERE id = 1;
UPDATE `email_audience_groups` SET total_members =  20, active_members =  20 WHERE id = 2;

-- =============================================================================
SET FOREIGN_KEY_CHECKS = 1;
-- =============================================================================
-- END OF SEED DATA
-- =============================================================================
