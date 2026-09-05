#!/bin/bash
# حذف كل البوابات الوهمية والمكررة وإبقاء البوابات الحقيقية فقط
mysql -u diparma_user -pdiparma_secure_2024 diparma_gateway << 'SQL'

-- 1. عرض البوابات النشطة حالياً
SELECT code, name, status, connection_status FROM dp_payment_gateways WHERE status='active' ORDER BY code;

-- 2. حذف كل البوابات ما عدا البوابات الحقيقية الـ 13
DELETE FROM dp_payment_gateways
WHERE code NOT IN (
  'nuvei',
  'stripe',
  'paypal',
  'wise',
  'myfatoorah',
  'binance',
  'gate_io',
  'mashreq',
  'hsbc_uae',
  'nbe_egypt',
  'jpmorgan',
  'whop',
  'payram'
);

-- 3. التأكد من وجود البوابات الـ 13 — أضف الناقص
INSERT IGNORE INTO dp_payment_gateways (code, name, type, status, connection_status, setup_complete, sort_order, created_at)
VALUES
  ('nuvei',      'Nuvei (Mashreq)',     'electronic', 'active', 'verified', 1, 1,  NOW()),
  ('stripe',     'Stripe',             'electronic', 'active', 'verified', 1, 2,  NOW()),
  ('paypal',     'PayPal',             'electronic', 'active', 'verified', 1, 3,  NOW()),
  ('wise',       'Wise',               'bank',       'active', 'verified', 1, 4,  NOW()),
  ('myfatoorah', 'MyFatoorah',         'electronic', 'active', 'verified', 1, 5,  NOW()),
  ('binance',    'Binance Pay',        'wallet',     'active', 'verified', 0, 6,  NOW()),
  ('gate_io',    'Gate.io',            'wallet',     'active', 'verified', 0, 7,  NOW()),
  ('mashreq',    'Mashreq Bank',       'bank',       'active', 'verified', 1, 8,  NOW()),
  ('hsbc_uae',   'HSBC UAE',           'bank',       'active', 'verified', 1, 9,  NOW()),
  ('nbe_egypt',  'NBE Egypt',          'bank',       'active', 'verified', 1, 10, NOW()),
  ('jpmorgan',   'JP Morgan Chase',    'bank',       'active', 'verified', 1, 11, NOW()),
  ('whop',       'Whop',               'wallet',     'active', 'verified', 1, 12, NOW()),
  ('payram',     'PayRam',             'wallet',     'active', 'verified', 0, 13, NOW());

-- 4. التحقق النهائي
SELECT code, name, status, connection_status, setup_complete FROM dp_payment_gateways ORDER BY sort_order;

SQL
echo "✅ تم تنظيف البوابات"
