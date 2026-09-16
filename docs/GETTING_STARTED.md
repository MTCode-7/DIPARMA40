# Welcome to DI PARMA

DI PARMA is a multi-gateway payment platform. Money moves:

**Dashboard / POS / API → Payment Orchestrator (ChargeHub) → Provider → Payment Result → Orders → Ledger USDT (TRC20)**

## What you can do

- Charge cards through the provider the merchant selects (not hard-coded to one acquirer)
- Run POS purchase, Direct Advice (MTI 0220), and Offline Store-and-Forward (SAF)
- Settle successful captures toward a company/client Ledger address on TRON
- Link local XAMPP and remote Lightsail via Peer API for withdraw / webhook forward

## Authentication (API v1)

Protected routes under `/api/v1/*` require:

```http
X-Api-Key: dpk_...
X-Timestamp: <unix_seconds>
X-Signature: HMAC-SHA256(api_secret, "{api_key}:{timestamp}:{sha256(body)}")
Content-Type: application/json
```

- Timestamp skew max: **300 seconds**
- Rate limit: **100 req / 60s** per client (see `ApiAuth`)
- Optional IP whitelist per API client

## First charge

```http
POST /api/v1/charge
```

```json
{
  "amount": 100.00,
  "currency": "USD",
  "card_number": "....",
  "card_name": "CARDHOLDER",
  "card_expiry": "12/28",
  "card_cvv": "123",
  "txn_type": "purchase",
  "sec_mode": "3D",
  "reference": "ORDER-001"
}
```

Settlement target: client `ledger_address` or platform default TRC20 address.

## Other core endpoints

| Method | Path | Notes |
|--------|------|--------|
| GET | `/api/v1/balance` | Ledger TRX/USDT + account stats |
| GET | `/api/v1/transactions` | Filtered txn list |
| GET | `/api/v1/docs` | This API’s HTML/JSON docs |
| POST | `/api/mysystem.php?action=pay` | Orders + orchestrator pay |
| GET | `/api/mysystem.php?action=diagram` | Flow diagram JSON |
| GET | `/api/peer.php?action=health` | Local ↔ remote peer status |
| POST | `/pos/api/transaction.php` | POS charge / advice / SAF paths |
| POST | `/pos/api/saf_sync.php` | Flush offline SAF when online |

Import full schemas from [`openapi.yaml`](./openapi.yaml) into ReadMe → **API Reference**.

## Webhooks

Outbound events (examples): `charge.completed`, `charge.failed`, `ledger.transferred`  
Header: `X-DiParma-Signature: HMAC-SHA256(webhook_secret, raw_body)`

Inbound provider webhooks live under `/api/webhook.php`, `/api/payram_webhook.php`, `/api/paypal.php?action=webhook`, etc.  
**Providers need a public HTTPS URL** pointing at the live host.

## Bank-locked POS limits

| Mode | Cap | Rule |
|------|-----|------|
| Direct Advice (purchase advice) | **5,000,000** | Bank account lock — do not raise in code |
| Offline SAF (single sale) | **2,000,000** | Bank account lock — refuse higher |

No simulated approvals: success only from the selected host/gateway response.

## Next steps

1. Create an API client in Admin → API / Connection Manager  
2. Point webhooks to HTTPS production  
3. Upload [`openapi.yaml`](./openapi.yaml) to ReadMe  
4. Read [ARCHITECTURE.md](./ARCHITECTURE.md) and [POS.md](./POS.md)
