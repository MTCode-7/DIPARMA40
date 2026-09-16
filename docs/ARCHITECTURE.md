# Architecture

## High-level flow

```
Dashboard DI PARMA / POS Web / API v1
            │
            ▼
   ChargeHub (DiParmaChargeHub)
            │
            ▼
 pos_run_payment_orchestrator  →  pos_run_standalone_gateway
            │
            ▼
     Gateway Adapter (Square / Nuvei / Stripe / …)
            │
            ▼
     Payment Result → Orders / dp_transactions
            │
            ▼
   Ledger settlement (USDT TRC20)  ← caller / queue / webhook confirm
```

## Key modules

| Path | Role |
|------|------|
| `lib/MySystem/ChargeHub.php` | Single charge entry for all channels |
| `lib/MySystem/PaymentOrchestrator.php` | Orders + customers → charge |
| `lib/MySystem/OrdersService.php` | Order persistence |
| `lib/MySystem/CustomersService.php` | Customer records |
| `lib/PaymentOrchestrator.php` | Compat shim → ChargeHub |
| `lib/DIPARMAOrchestrator.php` | Routing → ChargeHub |
| `pos/lib/gateways.php` | POS gateway dispatch & live checks |
| `lib/Adapters/*` | Provider adapters (Nuvei, Square, Stripe, PayPal, Ledger, …) |
| `lib/LedgerSettlementService.php` | Settlement helpers |
| `lib/HotWalletService.php` / `WalletManager.php` | Wallet ops |
| `api/peer.php` + `includes/peer_link.php` | Local ↔ remote sync |
| `api/webhook.php` | Generic provider confirm → orchestrator / Ledger |

## Databases (remote typical)

- `diparma_gateway` — primary app tables (`dp_transactions`, ledger queue, wallets, gateways, …)
- `diparma_db`, `payments_db` — may exist for legacy / satellite data

Important settlement tables:

- `dp_transactions` — payment records  
- `dp_ledger_transfer_queue` — USDT transfer queue  
- `dp_blockchain_txns` — on-chain confirmations  

Empty queue + zero completed txns means **no money received yet**, even if code is deployed.

## Peer link

`GET /api/peer.php?action=health` returns role (`local` / `remote`), peer URLs, and webhook endpoints.  
HMAC-signed POSTs: `withdraw`, `sync_txn`, `forward_webhook`.

`SITE_URL` in `.env` must match the URL providers and peer use (prefer HTTPS domain in production).

## TronBox vs money receive

[TronBox](https://developers.tron.network/docs/tronbox-1) compiles/deploys TRON contracts (installed local + remote).  
It does **not** by itself receive card settlements. Receiving money requires live gateway capture + webhook/HTTPS + ledger transfer execution.

## Deploy topology

```
Windows XAMPP (dev)  ──peer──►  Lightsail Ubuntu
                                      ├── /var/www/html/DIPARMA40
                                      └── /var/www/diparma
```

Upload: pack without `.env` → `scp` → `rsync` into both trees → reload PHP-FPM / nginx|apache.  
See `deploy/upload_full.ps1` and `deploy/README.md`.
