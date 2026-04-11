# UlendoPay — Backend API

UlendoPay is a mobile-first cross-border payments platform built for Africa. This repository contains the Laravel REST API powering the UlendoPay app.

## Tech Stack

- **Framework:** Laravel 11 (PHP 8.2+)
- **Database:** MySQL
- **Authentication:** Laravel Sanctum (OTP-based, PIN + password)
- **Payments:** Pawapay (mobile money deposits & payouts)
- **Architecture:** Double-entry ledger, outbox pattern for SMS notifications

## Features

- OTP phone verification & 2FA login
- KYC document submission & verification
- Multi-currency wallets (MWK, KES, TZS, ZMW, GHS, UGX, RWF, MZN, ETB, XOF)
- Mobile money top-up via Pawapay Collections API
- Mobile money withdrawal via Pawapay Payouts API
- Cross-border remittances with rate locking
- Recipient management
- Double-entry bookkeeping via LedgerService
- Webhook signature verification
- Audit logging
- Fraud alerts
- Rate limiting

## Supported Corridors

| Country | Currency | Operators |
|---------|----------|-----------|
| Malawi | MWK | Airtel, TNM, TNM Mpamba |
| Kenya | KES | M-Pesa, Airtel |
| Tanzania | TZS | Vodacom, Airtel, Tigo, Halotel |
| Zambia | ZMW | Airtel, MTN, Zamtel |
| Ghana | GHS | MTN, Vodafone, AirtelTigo |
| Uganda | UGX | MTN, Airtel |
| Rwanda | RWF | MTN, Airtel |
| Mozambique | MZN | Vodacom, Movitel |
| Ethiopia | ETB | Telebirr, M-Pesa |
| Senegal | XOF | Orange, Free, Wave |

## API Endpoints

### Auth
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v1/auth/register` | Register new user |
| POST | `/api/v1/auth/verify-phone` | Verify phone with OTP |
| POST | `/api/v1/auth/login` | Login with PIN/password |
| POST | `/api/v1/auth/verify-login` | Verify login OTP |
| POST | `/api/v1/auth/logout` | Logout |
| GET | `/api/v1/auth/me` | Get authenticated user |

### Wallets
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/wallets` | List user wallets |
| GET | `/api/v1/wallets/{currency}` | Get wallet by currency |

### Top-Up
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/topup/operators` | Get supported operators |
| POST | `/api/v1/topup/initiate` | Initiate mobile money deposit |
| GET | `/api/v1/topup/status/{reference}` | Poll top-up status |
| GET | `/api/v1/topup/history` | Top-up history |
| POST | `/api/v1/topup/webhook` | Pawapay deposit webhook (public) |

### Withdrawals
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/withdraw/operators` | Get supported operators |
| POST | `/api/v1/withdraw/initiate` | Initiate mobile money payout |
| GET | `/api/v1/withdraw/status/{reference}` | Poll withdrawal status |
| GET | `/api/v1/withdraw/history` | Withdrawal history |
| POST | `/api/v1/withdraw/webhook` | Pawapay payout webhook (public) |

### Transactions
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v1/transactions` | Create cross-border transaction |
| GET | `/api/v1/transactions` | List transactions |
| GET | `/api/v1/transactions/{reference}` | Get transaction by reference |

### Recipients
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/recipients` | List recipients |
| POST | `/api/v1/recipients` | Add recipient |
| GET | `/api/v1/recipients/{id}` | Get recipient |
| PUT | `/api/v1/recipients/{id}` | Update recipient |
| DELETE | `/api/v1/recipients/{id}` | Delete recipient |

### KYC
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/kyc/status` | Get KYC status |
| POST | `/api/v1/kyc/submit` | Submit KYC documents |

## Getting Started

### Requirements

- PHP 8.2+
- MySQL 8.0+
- Composer

### Installation

```bash
git clone git@github.com:Senezio/UlendoPay.git
cd UlendoPay
composer install
cp .env.example .env
php artisan key:generate
```

Configure your `.env`:

```env
DB_DATABASE=ulendopay
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password

PAWAPAY_BASE_URL=https://api.sandbox.pawapay.io
PAWAPAY_API_TOKEN=your_pawapay_token
PAWAPAY_TIMEOUT=30
```

Run migrations:

```bash
php artisan migrate
php artisan optimize
```

## Environment

| Variable | Description |
|----------|-------------|
| `PAWAPAY_BASE_URL` | Pawapay API base URL (sandbox or production) |
| `PAWAPAY_API_TOKEN` | Pawapay JWT token |
| `PAWAPAY_TIMEOUT` | HTTP timeout in seconds (default: 30) |

## Frontend

The Vue/Nuxt frontend is maintained in a separate repository:
[ulendopay-web](https://github.com/Senezio/ulendopay-web)

## License

Proprietary — All rights reserved © UlendoPay
