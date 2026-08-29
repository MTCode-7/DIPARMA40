#!/bin/bash
# ============================================================
# DI PARMA | Master Wallet Setup Integration Script
# ============================================================

echo "╔════════════════════════════════════════════════════════╗"
echo "║   DI PARMA Master Wallet Setup - Integration Script    ║"
echo "╚════════════════════════════════════════════════════════╝"
echo ""

# الألوان
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# ──────────────────────────────────────────────────────
# 1. فحص الملفات المطلوبة
# ──────────────────────────────────────────────────────
echo -e "${BLUE}[1] Checking Required Files...${NC}"

FILES=(
    "master_wallet_setup.php"
    "lib/PayRamAdapter.php"
    "api/payram_payment.php"
    "api/payram_webhook.php"
    "includes/config.php"
    ".env.example"
)

for file in "${FILES[@]}"; do
    if [ -f "$file" ]; then
        echo -e "${GREEN}✓${NC} $file"
    else
        echo -e "${RED}✗${NC} $file (NOT FOUND)"
    fi
done

echo ""

# ──────────────────────────────────────────────────────
# 2. فحص الإعدادات
# ──────────────────────────────────────────────────────
echo -e "${BLUE}[2] Checking Configuration...${NC}"

if grep -q "HOT_WALLET_TRC20_ADDRESS=TKST5Ug2UtAq6iQ8wVzy7tTah1FRgWaWYn" ".env.example"; then
    echo -e "${GREEN}✓${NC} HOT_WALLET correctly configured"
else
    echo -e "${RED}✗${NC} HOT_WALLET not found in .env.example"
fi

if grep -q "LEDGER_TRC20_ADDRESS=TFyAQPrTRdP7zp46RPmE1iiCac1Lh6Bu58" ".env.example"; then
    echo -e "${GREEN}✓${NC} LEDGER_WALLET correctly configured"
else
    echo -e "${RED}✗${NC} LEDGER_WALLET not found in .env.example"
fi

echo ""

# ──────────────────────────────────────────────────────
# 3. عرض المحافظ المستخدمة
# ──────────────────────────────────────────────────────
echo -e "${BLUE}[3] Configured Wallets:${NC}"
echo -e "  ${YELLOW}HOT_WALLET (Send):${NC}"
echo -e "    TKST5Ug2UtAq6iQ8wVzy7tTah1FRgWaWYn"
echo ""
echo -e "  ${YELLOW}LEDGER_WALLET (Receive):${NC}"
echo -e "    TFyAQPrTRdP7zp46RPmE1iiCac1Lh6Bu58"

echo ""

# ──────────────────────────────────────────────────────
# 4. الخطوات المطلوبة
# ──────────────────────────────────────────────────────
echo -e "${BLUE}[4] Required Next Steps:${NC}"
echo ""
echo -e "  ${YELLOW}Step 1: Copy .env.example to .env${NC}"
echo -e "    ${GREEN}cp .env.example .env${NC}"
echo ""
echo -e "  ${YELLOW}Step 2: Update .env with sensitive values:${NC}"
echo -e "    • PAYRAM_API_KEY=your_api_key"
echo -e "    • PAYRAM_WEBHOOK_SECRET=your_webhook_secret"
echo -e "    • HOT_WALLET_TRC20_KEY=your_private_key"
echo -e "    • TRONGRID_API_KEY=your_trongrid_api_key"
echo ""
echo -e "  ${YELLOW}Step 3: Access Master Wallet Setup:${NC}"
echo -e "    ${GREEN}http://localhost/DIPARMA40/master_wallet_setup.php${NC}"
echo ""
echo -e "  ${YELLOW}Step 4: Test Integration:${NC}"
echo -e "    ${GREEN}http://localhost/DIPARMA40/test_integration.php${NC}"

echo ""

# ──────────────────────────────────────────────────────
# 5. API Endpoints المتاحة
# ──────────────────────────────────────────────────────
echo -e "${BLUE}[5] Available API Endpoints:${NC}"
echo ""
echo -e "  ${YELLOW}POST /api/wallet.php?action=deposit${NC}"
echo -e "    • Create deposit request"
echo ""
echo -e "  ${YELLOW}POST /api/wallet.php?action=transfer${NC}"
echo -e "    • Transfer funds to address"
echo ""
echo -e "  ${YELLOW}GET /api/wallet.php?action=balance&currency=USDT${NC}"
echo -e "    • Get wallet balance"
echo ""
echo -e "  ${YELLOW}GET /api/wallet.php?action=transactions${NC}"
echo -e "    • Get transaction history"

echo ""

# ──────────────────────────────────────────────────────
# 6. ملفات السجلات
# ──────────────────────────────────────────────────────
echo -e "${BLUE}[6] Log Files to Monitor:${NC}"
echo -e "  • logs/payram.log"
echo -e "  • logs/transactions.log"
echo -e "  • logs/webhook.log"
echo -e "  • logs/php_errors.log"

echo ""

# ──────────────────────────────────────────────────────
# الخلاصة
# ──────────────────────────────────────────────────────
echo -e "${GREEN}╔════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║        Master Wallet Setup - READY FOR USE!             ║${NC}"
echo -e "${GREEN}╚════════════════════════════════════════════════════════╝${NC}"
echo ""
