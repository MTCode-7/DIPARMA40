#!/bin/bash
# قراءة فقط: يوضّح حالة السيرفر مقابل المستودع قبل أي دمج.
APP="${APP:-/var/www/diparma}"
cd "$APP" || { echo "خطأ: $APP غير موجود"; exit 1; }

echo "===== HEAD الحالي ====="
sudo git log --oneline -3

echo
echo "===== هل commit السيرفر سلف للجديد؟ ====="
if sudo git merge-base --is-ancestor HEAD origin/main 2>/dev/null; then
  echo "نعم — fast-forward ممكن بعد معالجة التعديلات المحلية"
else
  echo "لا — تاريخ متفرّع، يحتاج دمجاً"
fi

echo
echo "===== حجم التعديلات المحلية ====="
sudo git diff --shortstat

echo
echo "===== أكثر 15 ملفاً تعديلاً ====="
sudo git diff --numstat | sort -k1,1nr | head -15 | awk '{printf "  +%-6s -%-6s %s\n", $1, $2, $3}'

echo
echo "===== الملفات الحرجة لمسار PayPal ====="
for f in checkout/paypal.php lib/PayPalService.php lib/PaymentOrchestrator.php \
         lib/Adapters/GatewayAdapterFactory.php api/orchestrator.php api/paypal.php; do
  if sudo git diff --quiet -- "$f" 2>/dev/null; then
    echo "  غير معدّل محلياً : $f"
  else
    N=$(sudo git diff --numstat -- "$f" | awk '{print "+"$1" -"$2}')
    echo "  معدّل محلياً $N : $f"
  fi
done

echo
echo "===== ملفات غير متتبَّعة تمنع الدمج ====="
sudo git status --porcelain | grep '^??' | head -20

echo
echo "===== آخر تعديل زمني لملفات مفتاحية ====="
for f in checkout/paypal.php lib/PaymentOrchestrator.php; do
  echo "  $(stat -c '%y' "$f" 2>/dev/null) $f"
done
