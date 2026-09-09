# DI PARMA Security Audit

Date: 2026-09-08
Scope: PHP application, payment/API endpoints, webhook handlers, configuration, and repository state.

## Executive Summary

The project contains 248 PHP files. A full PHP syntax scan completed successfully before and after the security changes. The most important operational risk remains credential exposure in the local `.env` file. The file is not tracked by Git, but its values must still be rotated because they are live provider credentials and database credentials.

The following code-level issues were identified and fixed in this pass:

- Session-authenticated POST requests in `api/direct_payment.php` now require a valid CSRF token. API-key requests remain compatible with HMAC authentication.
- `api/v1/transactions.php` now refuses API clients that are not assigned to a user and always applies a `user_id` tenant filter.
- `api/webhook.php` now fails closed when generic webhook verification is enabled but the secret or signature is missing. MoonPay also fails closed when its signing secret is absent.
- `api/v1/diparma_charge.php` now validates outbound webhook URLs, blocks private/reserved IP destinations, and refuses to sign webhooks with a default secret.
- `api/pos_transaction.php` no longer enables wildcard CORS and now requires either session + CSRF or API HMAC authentication tied to a user account.

## Validation Evidence

- Full PHP lint before changes: `ALL_PHP_OK`.
- Focused lint after each edit: no syntax errors in all five modified endpoints.
- Git status showed only the five intended PHP files modified; `.env` and `.env.production` are not tracked, while `.env.example` is tracked.

## Gateway Review

| Area | Endpoint/implementation | Result |
|---|---|---|
| Generic webhooks | `api/webhook.php` | Signature enforcement hardened; provider-specific signature formats should still be tested with real sandbox events. |
| PayRam | `api/payram_webhook.php`, `api/payram_payment.php` | Dedicated signature verification exists; verify that every production webhook is routed to the dedicated handler. |
| Stripe | `api/stripe_charge.php`, `api/webhook.php` | CSRF is present on browser charge flow; Stripe webhook signing must be tested with Stripe's timestamped signature format. |
| PayPal | `api/paypal.php` | Dedicated webhook path exists; confirm certificate/event verification in production. |
| Wise | `api/wise_payment.php`, `api/webhook.php` | Browser action uses CSRF; verify Wise webhook signature and idempotency with a sandbox event. |
| Nuvei | `api/nuvei_create_txn.php`, `checkout/nuvei.php` | Browser transaction creation uses CSRF; verify callback authenticity and amount/currency matching. |
| Whop | `auth/whop_callback.php`, `api/whop_webhook.php` | Callback does not currently validate OAuth `state`; add state generation and comparison before enabling social login in production. |
| POS | `api/pos_transaction.php` | Authentication and CSRF/API HMAC enforcement added. Test hardware integration after supplying credentials. |

## Remaining High-Priority Actions

1. Rotate every live payment, exchange, blockchain, webhook, and database credential that has existed in `.env`.
2. Store production secrets outside the web root or inject them through the server environment/secret manager.
3. Confirm the web server cannot serve `.env`, `.git`, logs, backups, SQL files, or private uploads. The repository has `.htaccess` protections, but this must be tested on the actual Apache configuration.
4. Add OAuth `state` generation at the Whop authorization-start endpoint and compare it in `auth/whop_callback.php`.
5. Run sandbox tests for every gateway: success, failure, timeout, duplicate webhook, wrong amount, wrong currency, and invalid signature.
6. Add idempotency constraints for webhook processing so duplicate provider deliveries cannot repeat settlement or ledger actions.
7. Review production logs for secrets and card data. Do not log raw gateway payloads if they can contain personal or payment data.

## Limitations

This audit did not call live payment providers, modify credentials, modify the database schema, or perform destructive transaction tests. Those steps require a controlled staging environment and provider sandbox credentials.
