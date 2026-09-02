# 🔐 دليل إدارة المفاتيح الحساسة
## Secure Configuration Guide

**للمطورين والمديرين**

---

## ⚡ ملخص سريع

```
🔴 تم محاكاة جميع المفاتيح الحساسة في .env
✅ يجب تحديثها بالقيم الفعلية قبل الإنتاج
⚠️ لا تنشر .env في Git أبداً
```

---

## 📋 المفاتيح الحساسة المطلوبة

### 1. PayRam Gateway
```env
# الحالة الحالية (وهمية - آمنة):
PAYRAM_API_KEY=YOUR_PAYRAM_API_KEY_HERE
PAYRAM_WEBHOOK_SECRET=YOUR_PAYRAM_WEBHOOK_SECRET_HERE

# الحالة المطلوبة:
# احصل على المفاتيح من: https://payram.com/dashboard/api
PAYRAM_API_KEY=<ACTUAL_API_KEY_FROM_PAYRAM>
PAYRAM_WEBHOOK_SECRET=<ACTUAL_WEBHOOK_SECRET_FROM_PAYRAM>
```

### 2. Wise Transfer API
```env
# الحالة الحالية (وهمية - آمنة):
WISE_API_KEY=YOUR_WISE_API_KEY_HERE
WISE_ENVIRONMENT=live

# الحالة المطلوبة:
# احصل على المفتاح من: https://wise.com/account/api-tokens
WISE_API_KEY=<ACTUAL_WISE_API_TOKEN>
WISE_ENVIRONMENT=live  # أو sandbox للاختبار
```

### 3. Encryption Keys
```env
# الحالة الحالية (وهمية - آمنة):
ENCRYPTION_KEY=CHANGE_THIS_ENCRYPTION_KEY_IN_PRODUCTION
JWT_SECRET=CHANGE_THIS_JWT_SECRET_IN_PRODUCTION

# الحالة المطلوبة:
# استخدم أداة توليد آمنة:
# على Linux/Mac: openssl rand -base64 32
# على PHP: bin2hex(random_bytes(32))
ENCRYPTION_KEY=<GENERATED_RANDOM_32_BYTES>
JWT_SECRET=<GENERATED_RANDOM_32_BYTES>
```

### 4. Database
```env
# الحالة الحالية (وهمية - آمنة):
DB_PASS=CHANGE_THIS_DB_PASSWORD_IN_PRODUCTION

# الحالة المطلوبة:
# استخدم كلمة مرور قوية:
DB_PASS=<STRONG_RANDOM_PASSWORD_min_16_chars>

# متطلبات كلمة المرور:
✓ 16+ حرف
✓ أحرف كبيرة وصغيرة
✓ أرقام
✓ رموز خاصة (!@#$%^&*)
```

---

## 🔧 خطوات التحديث

### الخطوة 1: نسخ القالب
```bash
cp .env.example .env.local
```

### الخطوة 2: تحديث المفاتيح
```bash
# افتح .env.local وحدث:
nano .env.local

# أو استخدم محرر النصوص:
code .env.local
```

### الخطوة 3: ملء القيم الفعلية
```env
# PayRam
PAYRAM_API_KEY=sk_live_abc123...
PAYRAM_WEBHOOK_SECRET=whsec_abc123...

# Wise
WISE_API_KEY=eyJhbGciOi...

# Encryption
ENCRYPTION_KEY=abcd1234efgh5678ijkl9012mnop3456
JWT_SECRET=wxyz7890abcd1234efgh5678ijkl9012

# Database
DB_PASS=S@feP@ssw0rd!2024#Secure
```

### الخطوة 4: التحقق
```bash
# تأكد من عدم وجود قيم فارغة
grep "YOUR_" .env.local  # يجب أن يكون فارغاً

# تأكد من عدم وجود قيم وهمية
grep "CHANGE_THIS" .env.local  # يجب أن يكون فارغاً
```

---

## 📋 قائمة التحقق

قبل النشر في الإنتاج:

```
□ تحديث PAYRAM_API_KEY
□ تحديث PAYRAM_WEBHOOK_SECRET
□ تحديث WISE_API_KEY
□ توليد ENCRYPTION_KEY جديد
□ توليد JWT_SECRET جديد
□ تحديث DB_PASS بكلمة مرور قوية
□ التحقق من عدم وجود قيم وهمية
□ التحقق من عدم وجود قيم فارغة
□ اختبار الاتصال بجميع الخوادم
□ مراجعة السجلات للأخطاء
□ عمل نسخة احتياطية
□ نشر الكود
```

---

## 🛡️ أفضل الممارسات الأمنية

### ✅ نعم - افعل:
```bash
✓ حفظ .env في .gitignore
✓ استخدام متغيرات البيئة
✓ تطبيق مبدأ المسؤوليات المحدودة
✓ تدوير المفاتيح دورياً
✓ استخدام HTTPS فقط
✓ تسجيل محاولات الوصول
✓ مراقبة الحسابات الحساسة
```

### ❌ لا - لا تفعل:
```bash
✗ نشر .env في Git
✗ مشاركة المفاتيح عبر البريد الإلكتروني
✗ حفظ المفاتيح في التعليقات
✗ استخدام كلمات مرور ضعيفة
✗ استخدام نفس المفاتيح للتطوير والإنتاج
✗ ترك بيانات الاختبار في الإنتاج
✗ إهمال تسجيل الوصول
```

---

## 🔑 توليد مفاتيح آمنة

### PHP
```php
<?php
// توليد مفتاح عشوائي آمن
function generateSecureKey($length = 32) {
    return bin2hex(random_bytes($length));
}

echo "ENCRYPTION_KEY=" . generateSecureKey(32) . "\n";
echo "JWT_SECRET=" . generateSecureKey(32) . "\n";
?>
```

### Bash/Linux
```bash
# توليد مفتاح عشوائي
openssl rand -base64 32

# توليد كلمة مرور قوية
openssl rand -base64 16 | tr -d '=' | cut -c1-16
```

### Python
```python
import secrets
import base64

# توليد مفتاح
key = secrets.token_hex(32)
print(f"ENCRYPTION_KEY={key}")

# توليد كلمة مرور
password = base64.b64encode(secrets.token_bytes(24)).decode()
print(f"DB_PASS={password}")
```

---

## 🔍 التحقق من الأمان

### اختبر .env
```bash
# تأكد من أن .env محمي
ls -la .env
# يجب أن يكون: -rw-r--r-- (600 أو 644)

# تأكد من أنه غير معروض في Git
git status .env
# يجب أن يظهر: .env (من .gitignore)
```

### اختبر الاتصالات
```bash
# اختبر قاعدة البيانات
php -r "require 'config/database.php'; echo 'DB OK';"

# اختبر PayRam API
curl -X GET "https://api.payram.com/v1/health" \
  -H "Authorization: Bearer PAYRAM_API_KEY"

# اختبر Wise API
curl -X GET "https://api.sandbox.transferwise.tech/v1/user" \
  -H "Authorization: Bearer WISE_API_KEY"
```

---

## 📞 دعم فني

### الأسئلة الشائعة

**س: هل أترك .env.example في المشروع؟**
```
ج: نعم! هذا نموذج للمطورين الجدد
- لا تضع مفاتيح فعلية فيه
- احفظه في Git
```

**س: كيف أختبر بدون مفاتيح فعلية؟**
```
ج: استخدم بيئة الاختبار (sandbox):
- PayRam: sandbox mode
- Wise: sandbox environment
- MySQL: قاعدة بيانات محلية
```

**س: ماذا لو تم تسريب المفتاح؟**
```
ج: قم فوراً بـ:
1. تعطيل المفتاح القديم
2. توليد مفتاح جديد
3. تحديث .env
4. إعادة نشر التطبيق
5. فحص السجلات للنشاط المريب
```

---

## 📝 السجل

```
2026-09-02 - أنشئ بدايةً بمفاتيح وهمية آمنة
2026-09-02 - توثيق الإجراءات الأمنية
2026-09-02 - إنشاء هذا الدليل
```

---

**تذكر: الأمان مسؤولية الجميع! 🔐**
