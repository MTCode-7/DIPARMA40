# POS

## Entry points

| Surface | Path |
|---------|------|
| POS web UI | `/pos/index.php` (also `pos.php` shim) |
| Transaction API | `/pos/api/transaction.php` |
| SAF sync | `/pos/api/saf_sync.php` |
| Auth holds | `/pos/api/auth_holds.php` |
| Verifone | `/pos/api/verifone.php` + native VX675 payload |
| Wrapper | `/api/pos_transaction.php` |

## Charge path

1. POS request selects **gateway** (user/merchant choice).  
2. `pos_run_payment_orchestrator` / ChargeHub loads adapter.  
3. Live host response only — no fake SUCCESS.  
4. On success, order/txn updated; Ledger settlement is triggered by caller / confirm path.

## Direct Advice (Purchase Advice)

- Class: `pos/lib/DirectAdvicePOSProcessor.php`
- ISO-style purchase advice flow (e.g. MTI **0220**)
- **Bank cap: 5,000,000** per advice — hard limit
- Gateway is **selected**, not forced to Nuvei
- Declines above cap or when host declines

## Offline SAF (Store and Forward)

- Class: `pos/lib/RealOfflineSalesManager.php`
- Local SQLite store under `cache/saf_storage.db` (or configured path)
- **Bank cap: 2,000,000** per offline sale — do not raise
- Sync endpoint: `POST /pos/api/saf_sync.php` when connectivity returns
- Gateway at sync time = user-selected provider

## Terminals & merchants

- `pos/lib/company_terminals.php` — company TID fleet
- `pos/lib/merchant.php` — merchant context
- `pos/lib/devices.php` / `device_models.php` — device catalog
- Activity ↔ terminal linking (operations / activity flow) lives in shared includes

## Related admin / ops

- Connection & gateway credentials: `admin/connection_manager.php`, `admin/api_dashboard.php`
- Company wallet / Ledger: `admin/company_wallet.php`, `admin/wallets.php`
- Reports UI: `reports.php`
