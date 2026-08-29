# 🎯 Master Wallet Setup - Complete Guide

## 📋 Overview

Master Wallet Setup هي واجهة رسومية حديثة لإدارة المحافظ الرقمية في DIPARMA40، مطابقة تماماً لـ Payram Deposit Wallet.

**الميزات الرئيسية:**
- ✅ عرض رصيد المحفظة الحالي
- ✅ إرسال الأموال إلى عناوين أخرى
- ✅ طلب إيداع عبر Payram
- ✅ إنشاء محافظ جديدة لعملات مختلفة
- ✅ تتبع سجل المعاملات الكامل
- ✅ تحديث أسعار العملات الحية
- ✅ واجهة استجابية وآمنة

---

## 🚀 الوصول إلى الصفحة

```
http://localhost/DIPARMA40/master_wallet_setup.php
```

**المتطلبات:**
- ✓ يجب أن تكون مسجل دخول
- ✓ جلسة صحيحة
- ✓ قاعدة بيانات متصلة

---

## 📊 المحافظ المستخدمة

### HOT_WALLET (محفظة الإرسال)
```
العنوان: TKST5Ug2UtAq6iQ8wVzy7tTah1FRgWaWYn
الاستخدام: إرسال الأموال للمستخدمين
الحالة: ✅ نشطة
المفتاح: يجب تعيينه في .env (HOT_WALLET_TRC20_KEY)
```

### LEDGER_WALLET (محفظة الاستقبال)
```
العنوان: TFyAQPrTRdP7zp46RPmE1iiCac1Lh6Bu58
الاستخدام: استقبال الأموال من المستخدمين
الحالة: ✅ نشطة
المفتاح: غير مطلوب (read-only)
```

---

## 🎨 واجهة المستخدم

### الأقسام الرئيسية:

#### 1. **Overview Cards** (عرض الأرصدة)
```
┌─────────────────────────────────────┐
│  💰 USDT Balance  │  🪙 TRX Value  │  📊 Market Price  │
│  ─────────────────────────────────────  │
│  • الرصيد الحالي                      │
│  • عنوان المحفظة                      │
│  • زر تحديث الرصيد                    │
└─────────────────────────────────────┘
```

#### 2. **Tab: Operations** (العمليات)
ثلاث نماذج رئيسية:

**أ) Send Funds (إرسال أموال)**
```
المبلغ (USDT)
عنوان الاستقبال (T...)
الوصف (اختياري)
[إرسال]
```

**ب) Request Deposit (طلب إيداع)**
```
المبلغ (USD)
الوصف (اختياري)
[طلب إيداع]
```

**ج) Create Wallet (إنشاء محفظة)**
```
اختر العملة: [USDT, USD, EUR, AED]
[إنشاء]
```

#### 3. **Tab: History** (السجل)**
قائمة آخر 10 معاملات مع:
- رقم المرجع
- التاريخ والوقت
- المبلغ والعملة
- حالة المعاملة

#### 4. **Tab: Settings** (الإعدادات)**
معلومات المحافظ المتصلة:
- Hot Wallet Address
- Ledger Wallet Address
- حالة الاتصال
- الحالة العامة

---

## 💻 API Endpoints

### 1. **Refresh Balance** (تحديث الرصيد)
```http
POST /api/wallet.php?action=refresh_balance
Content-Type: application/json

{
  "csrf_token": "..."
}

Response:
{
  "success": true,
  "balance": 100.00,
  "balance_usd": 100.00,
  "balance_trx": 25.50,
  "usdt_price": 3.92
}
```

### 2. **Send Funds** (إرسال أموال)
```http
POST /api/master-wallet-api.php
Content-Type: application/json

{
  "action": "send_funds",
  "csrf_token": "...",
  "amount": 50.00,
  "destination": "TFyAQPrTRdP7zp46RPmE1iiCac1Lh6Bu58",
  "description": "Payment for services"
}

Response:
{
  "success": true,
  "message": "تم إرسال الأموال بنجاح",
  "reference": "TXN-A1B2C3D4",
  "new_balance": 50.00
}
```

### 3. **Request Deposit** (طلب إيداع)
```http
POST /api/master-wallet-api.php
Content-Type: application/json

{
  "action": "request_deposit",
  "csrf_token": "...",
  "amount": 100.00,
  "description": "Top up wallet"
}

Response:
{
  "success": true,
  "message": "تم إنشاء طلب الإيداع",
  "reference": "PR-XYZ123",
  "payram_url": "http://65.2.184.57:8080/pay/...",
  "amount": 100.00
}
```

### 4. **Create Wallet** (إنشاء محفظة)
```http
POST /api/master-wallet-api.php
Content-Type: application/json

{
  "action": "create_wallet",
  "csrf_token": "...",
  "currency": "EUR"
}

Response:
{
  "success": true,
  "message": "تم إنشاء محفظة جديدة بنجاح"
}
```

---

## 📝 معالجة الطلبات (POST Requests)

### مثال 1: إرسال أموال (JavaScript)
```javascript
fetch('http://localhost/DIPARMA40/master_wallet_setup.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  body: new URLSearchParams({
    action: 'send_funds',
    csrf_token: csrfToken,
    amount: '50.00',
    destination: 'TFyAQPrTRdP7zp46RPmE1iiCac1Lh6Bu58',
    description: 'Test transfer'
  })
})
.then(r => r.json())
.then(d => {
  if (d.success) {
    console.log('Transfer successful:', d.reference);
  } else {
    console.error('Error:', d.message);
  }
});
```

### مثال 2: طلب إيداع (cURL)
```bash
curl -X POST http://localhost/DIPARMA40/master_wallet_setup.php \
  -d "action=request_deposit&csrf_token=YOUR_CSRF_TOKEN&amount=100&description=Wallet top up"
```

---

## 🔒 الأمان

### حماية المطبقة:

1. **Authentication Check**
   - جميع الطلبات تحتاج تسجيل دخول
   - التحقق من `$_SESSION['user_id']`

2. **CSRF Protection**
   - جميع POST requests تحتاج CSRF token
   - التحقق من `verifyCsrfToken()`

3. **Encryption**
   - تشفير AES-256 للبيانات الحساسة
   - تخزين آمن للمفاتيح الخاصة

4. **Database Security**
   - استخدام Prepared Statements
   - حماية من SQL Injection

5. **Rate Limiting**
   - حد أقصى للطلبات
   - منع الهجمات المتكررة

---

## 📊 قاعدة البيانات

### جدول `dp_wallets`
```sql
CREATE TABLE dp_wallets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  currency VARCHAR(10) NOT NULL DEFAULT 'USDT',
  balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status VARCHAR(30) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_wallet (user_id, currency),
  INDEX idx_user_id (user_id)
);
```

### جدول `dp_transactions`
```sql
CREATE TABLE dp_transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(100) NOT NULL UNIQUE,
  gateway VARCHAR(50) NOT NULL,
  user_id INT UNSIGNED DEFAULT 0,
  amount DECIMAL(12,2) NOT NULL,
  currency VARCHAR(10) NOT NULL DEFAULT 'AED',
  status VARCHAR(30) NOT NULL DEFAULT 'pending',
  payment_method VARCHAR(50) DEFAULT 'card',
  description TEXT DEFAULT NULL,
  gateway_response TEXT DEFAULT NULL,
  transaction_data TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_reference (reference),
  INDEX idx_user_id (user_id),
  INDEX idx_status (status)
);
```

---

## 🚨 معالجة الأخطاء

### رسائل الأخطاء الشائعة:

| رسالة | السبب | الحل |
|------|------|-----|
| "CSRF validation failed" | توكن CSRF غير صحيح | أعد تحميل الصفحة |
| "Insufficient balance" | رصيد غير كافي | تأكد من الرصيد أولاً |
| "Invalid amount" | المبلغ غير صحيح | أدخل مبلغ موجب |
| "Recipient address required" | عنوان الاستقبال فارغ | أدخل عنوان صحيح |
| "Wallet not found" | محفظة غير موجودة | أنشئ محفظة جديدة |

---

## 📈 مثال تدفق كامل

```
┌──────────────────────────────────────────┐
│  1. المستخدم يفتح master_wallet_setup.php │
│     ↓                                     │
│  2. يختار "Request Deposit"               │
│     ↓                                     │
│  3. يدخل المبلغ والوصف                    │
│     ↓                                     │
│  4. ينقر "طلب إيداع"                     │
│     ↓                                     │
│  5. يتم إنشاء معاملة Payram               │
│     ↓                                     │
│  6. يفتح الرابط في نافذة جديدة            │
│     ↓                                     │
│  7. يحول USDT من محفظته                  │
│     ↓                                     │
│  8. Payram يرسل webhook تأكيد             │
│     ↓                                     │
│  9. يتم تحديث الرصيد في DIPARMA40         │
│     ↓                                     │
│  10. المستخدم يرى الرصيد الجديد           │
└──────────────────────────────────────────┘
```

---

## 🔧 استكشاف الأخطاء

### 1. الصفحة لا تحميل
```bash
# تحقق من الأذونات
php -l master_wallet_setup.php

# تحقق من السجلات
tail -f logs/php_errors.log
```

### 2. الرصيد لا يحدّث
```bash
# تحقق من الاتصال بـ Payram
curl http://65.2.184.57:8080/api/v1/ticker

# تحقق من قاعدة البيانات
mysql> SELECT * FROM dp_wallets WHERE user_id = ?;
```

### 3. المعاملات لا تُسجّل
```bash
# تحقق من صلاحيات الإدراج
mysql> INSERT INTO dp_transactions (...) VALUES (...);

# تحقق من المفاتيح الأجنبية
mysql> SHOW CREATE TABLE dp_transactions\G
```

---

## 📞 الدعم

للمزيد من المساعدة، راجع:
- `INTEGRATION_REPORT.md` - تقرير التكامل الشامل
- `test_integration.php` - اختبار النظام
- `/logs/` - ملفات السجلات

---

**آخر تحديث:** 27 أغسطس 2026  
**الإصدار:** DIPARMA40 v3.1
