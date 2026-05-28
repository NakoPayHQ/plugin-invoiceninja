# NakoPay for Invoice Ninja

Payment driver for [Invoice Ninja](https://invoiceninja.com/) v5.

## Requirements

- Invoice Ninja 5.x (self-hosted)
- PHP 8.1+
- NakoPay merchant account

## Installation

1. Copy the `NakoPay` folder to `app/PaymentDrivers/`
2. Register the gateway in the database (see below)
3. Go to **Settings > Payment Settings > Online Payments**
4. Configure the NakoPay gateway

### Database Registration

Run this SQL to add NakoPay as a gateway option:

```sql
INSERT INTO gateways (id, name, provider, key, fields)
VALUES (
    UUID(),
    'NakoPay',
    'NakoPay',
    'nakopay',
    '{"apiKey":"","webhookSecret":"","testMode":false}'
);
```

## Configuration

| Field | Description |
|-------|-------------|
| apiKey | Your `sk_live_*` or `sk_test_*` key |
| webhookSecret | HMAC secret for webhook verification |
| testMode | Enable test mode |

## Webhook URL

```
https://your-invoiceninja.com/payment/webhook/nakopay/{company_key}
```

## Links

- [NakoPay Website](https://nakopay.com)
- [Documentation](https://nakopay.com/docs)
- [Integration Guide](https://nakopay.com/integrations/invoiceninja)
- [API Reference](https://nakopay.com/docs/api-reference)

## About Invoice Ninja

[Invoice Ninja](https://invoiceninja.com/) - free open-source invoicing, expenses, and time-tracking platform. Visit their website to learn more about the platform and its features.

## License

MIT
