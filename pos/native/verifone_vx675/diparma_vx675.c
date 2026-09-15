/*
 * Nuvei Payment App on Verifone VX 675 (Verix V)
 *
 * OS: Verix V SDK — https://developer.verifone.com/verix
 * Device: VX 675 — https://developer.verifone.com/vx675
 * Acquirer: Nuvei only. No other gateway.
 *
 * 1) Install Nuvei Payment App (Nuvei-certified Verix package).
 * 2) Inject Nuvei keys via Nuvei RKI / KIF into the terminal HSM.
 * 3) Payment App authorizes chip / NFC / mag with those keys on Nuvei's host.
 * 4) Notify DIPARMA with the Nuvei result (no PAN). Settlement: USDT → Ledger.
 *
 * This file is a host-notify stub. It does not replace Nuvei's Payment App
 * or Verifone SDK headers.
 */

#include <stdio.h>
#include <string.h>

/* Verix V SDK — after VVDTK:
#include <svc.h>
#include <svc_net.h>
#include <eoslog.h>
*/

#ifndef DIPARMA_HOST
#define DIPARMA_HOST "https://diparmas.com/pos/api/verifone.php"
#endif

typedef struct {
    char line[32];
    char txn_type[32];
    char tid[16];
    char currency[8];
    char amount[16];
    char entry_mode[16]; /* chip | nfc | keyed */
    char approval_code[16];
    char rrn[16];
    char nuvei_txn_id[32];
} NuveiSaleResult;

static int diparma_notify_nuvei(const NuveiSaleResult *s)
{
    char body[1024];
    snprintf(body, sizeof(body),
        "{\"gateway\":\"nuvei\",\"payment_app\":\"Nuvei Payment App\","
        "\"keys_injected\":true,\"line\":\"%s\",\"txn_type\":\"%s\","
        "\"tid\":\"%s\",\"amount\":\"%s\",\"currency\":\"%s\","
        "\"entry_mode\":\"%s\",\"approval_code\":\"%s\",\"rrn\":\"%s\","
        "\"nuvei_txn_id\":\"%s\",\"pos_model\":\"verifone_vx675\"}",
        s->line, s->txn_type, s->tid, s->amount, s->currency,
        s->entry_mode, s->approval_code, s->rrn, s->nuvei_txn_id);
    (void)body;
    (void)DIPARMA_HOST;
    return 0;
}

int main(void)
{
    NuveiSaleResult sale;
    memset(&sale, 0, sizeof(sale));
    strncpy(sale.line, "petroleum", sizeof(sale.line) - 1);
    strncpy(sale.txn_type, "purchase_2d", sizeof(sale.txn_type) - 1);
    strncpy(sale.tid, "T705953", sizeof(sale.tid) - 1);
    strncpy(sale.currency, "USD", sizeof(sale.currency) - 1);
    strncpy(sale.entry_mode, "chip", sizeof(sale.entry_mode) - 1);
    /* After Nuvei Payment App approval with injected keys: copy auth + RRN. */
    return diparma_notify_nuvei(&sale);
}
