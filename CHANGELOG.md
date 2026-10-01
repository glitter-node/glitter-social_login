# Changelog

All notable changes to `glitter-social_login` are documented here.

## [1.5.1] - 2026-10-01

### Security

- Added provider identity, active-account, lock, and two-factor fail-closed checks before Social Login token issuance.
- Bound login exchange codes to the initiating browser session and rechecked User eligibility immediately before token issuance.
- Redacted OAuth exception messages from plugin logs.
- Made User provisioning and SocialAccount attachment atomic, with identity unique-conflict recovery.
- Made duplicate same-user links idempotent and serialized social-only unlink protection.
- Added plugin-owned G7 Login-purpose IDV completion for existing Social Login accounts without
  copying Core's private authentication methods.
- Bound pending Social 2FA contexts to the initiating session, User, challenge, and one-time
  cache record; final token issuance still goes through the existing exchange boundary.
- Added plugin-local final authentication completion for `last_login_at` without dispatching the
  password-specific `core.auth.after_login` side effects.
- Added browser-bound pending Social Registration with explicit consent, signup IDV policy
  handling, atomic User/SocialAccount provisioning, and fail-closed behavior when Core's
  post-create signup IDV cannot be safely bound to the pending flow.
- Moved final authentication exchange state from cache to a hash-only DB record with
  atomic consume-on-attempt semantics and bounded lazy cleanup.
- Hardened known login/register layout matching, made repeated injection idempotent, removed
  exchange query parameters after processing, and generalized client-side link/exchange errors.

## [1.5.0] - 2026-09-29

### Added

- Added configurable social-login display order for login, registration, and linked-account screens.
- Added a keyboard- and pointer-accessible sortable provider-order control with explicit move buttons to plugin settings.
- Added normalized provider-order persistence that preserves disabled provider positions.

### Fixed

- Invalidate the affected public login, registration, and profile layout caches after Social Login settings are saved.

### Notes

- The canonical provider registry remains Naver, Kakao, Google, Facebook, GitHub; display order does not change routes or provider availability.
- Existing installations without a saved order use the canonical order without a migration.

## [1.4.0] - 2026-09-29

### Added

- Added GitHub OAuth login, signup, and manual account linking/unlinking as the fifth provider.
- Added GitHub settings for OAuth App Client ID and Client Secret with sensitive-value handling.
- Added GitHub redirect and callback support through the existing provider whitelist.
- Added GitHub as the fifth provider in login, registration, and linked-account widgets.

### Notes

- GitHub uses the bundled Laravel Socialite `GithubProvider` and its `user:email` primary/verified email selection.
- GitHub identity is based on `(provider, provider_user_id)` and does not require a database migration.
- Missing GitHub email continues through the existing no-email signup fallback.
- GitHub uses the plugin-local `resources/images/github.svg` through the existing `BrandIcons` data-URI integration.

## [1.3.0] - 2026-09-29

### Added

- Added Facebook OAuth login, signup, and manual account linking/unlinking as the fourth provider.
- Added Facebook settings for App ID and App Secret with sensitive-value handling.
- Added Facebook redirect and callback routes through the existing provider whitelist.
- Added context-aware Facebook labels for login and registration pages.

### Notes

- Facebook identity is based on `(provider, provider_user_id)` and does not require a database migration.
- Facebook email is not used for automatic existing-account email matching in 1.3.0.
- Missing Facebook email continues through the existing no-email signup fallback.
- Facebook's raw `verified` field is not treated as Google's `email_verified` or Kakao's
  `is_email_verified`.

## [1.2.0] - 2026-09-29

### Added

- Added Naver OAuth login, signup, and manual account linking/unlinking.
- Added Naver as the first provider in the canonical Naver, Kakao, Google order.
- Added Naver settings for Client ID and Client Secret with sensitive-value handling.
- Added the Naver callback route `/api/plugins/glitter-social_login/naver/callback`.
- Added validated Naver `response.id` identity mapping and optional profile email handling.

### Notes

- Naver `response.email` may participate in existing-account email matching only after successful
  OAuth state validation, token exchange, and authenticated profile validation. Naver's response is
  not treated as if it contained Google's `email_verified` field.
- No database migration is required; the existing provider and opaque provider-user-ID columns are
  compatible with Naver.

## [1.1.1] - 2026-09-29

### Fixed

- Use a short explicit unique-index name so MariaDB installation succeeds with the `g7_` table prefix.

## [1.1.0] - 2026-09-28

### Changed

- Migrated the plugin identity to `glitter-social_login`, including the PHP namespace `Plugins\\Glitter\\SocialLogin`, settings translations, routes, cache keys, and widget identifiers.
- Updated ownership metadata and documentation to Glitter.kr.
- Preserved structural login-widget injection for both slate and gray login-card styling.
- Added a guarded table-rename migration for existing social-login data.

### Migration notes

- The canonical OAuth callback routes now use `/api/plugins/glitter-social_login/...`.
- Plugin settings are keyed by identifier and require an operator-approved settings migration before activation.
- Generated hook/cache files must be rebuilt through the official plugin lifecycle command.

## [1.0.5] - Previous release

- Previous implementation release retained for source-history context.
