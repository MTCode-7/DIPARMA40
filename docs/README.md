# DI PARMA Documentation

Payment platform: **card / wallet / bank / crypto → provider → settlement to Ledger TRC20 (USDT)**.

| Document | Purpose |
|----------|---------|
| [GETTING_STARTED.md](./GETTING_STARTED.md) | Overview, auth, first charge, environments |
| [ARCHITECTURE.md](./ARCHITECTURE.md) | System map, ChargeHub, settlement, peer link |
| [POS.md](./POS.md) | POS web, Direct Advice, Offline SAF, terminals |
| [openapi.yaml](./openapi.yaml) | OpenAPI 3 for ReadMe / API Reference |
| [../deploy/README.md](../deploy/README.md) | Server deploy & upload |

**Live docs (ReadMe):** [di-parma.readme.io](https://di-parma.readme.io/docs/getting-started)  
**Built-in API HTML:** `/api/v1/docs.php` · JSON: `/api/v1/docs.php?format=json`

## Stack

- PHP (XAMPP local · Linux Lightsail remote)
- MySQL / MariaDB (`diparma_gateway`)
- Gateways: Nuvei, Square, Stripe, PayPal, Wise, banks, crypto, Ledger
- POS: web + Verifone VX675 path · ChargeHub orchestration
- TronBox (local + remote) for TRON contract tooling — separate from payment receive path

## Environments

| Role | Typical URL |
|------|-------------|
| Local | `http://localhost/DIPARMA40` |
| Remote IP | `http://65.2.184.57` |
| Production domain | `https://diparmas.com` (requires working HTTPS) |

Do **not** commit `.env`. Deploy with `deploy/upload_full.ps1` (excludes secrets, logs, cache).
