# Changelog
## 1.1.0 - 2026-05-17

### Changed
- Default API base URL is now https://api.nakopay.com/v1 (branded primary). Added API_BASE_FALLBACK constant pointing at Supabase functions URL.

## [1.0.0] - 2026-05-01

### Added
- Payment driver for Invoice Ninja v5
- Hosted checkout redirect flow
- Webhook processing with HMAC-SHA256 verification
- Refund support
- Idempotency key support
- System log integration for failed events
