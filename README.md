# glitter-social_login

Glitter.kr이 유지보수하는 Gnuboard7 플러그인으로 Naver/Kakao/Google/Facebook/GitHub OAuth 로그인, 가입, 계정 연동을 제공합니다.

- Plugin ID: `glitter-social_login`
- Version: `1.5.0`
- Vendor / copyright holder: Glitter.kr
- License: MIT

## Routes

| Purpose | Route |
| --- | --- |
| Naver redirect | `GET /api/plugins/glitter-social_login/naver/redirect` |
| Naver callback | `GET /api/plugins/glitter-social_login/naver/callback` |
| Kakao redirect | `GET /api/plugins/glitter-social_login/kakao/redirect` |
| Kakao callback | `GET /api/plugins/glitter-social_login/kakao/callback` |
| Google redirect | `GET /api/plugins/glitter-social_login/google/redirect` |
| Google callback | `GET /api/plugins/glitter-social_login/google/callback` |
| Facebook redirect | `GET /api/plugins/glitter-social_login/facebook/redirect` |
| Facebook callback | `GET /api/plugins/glitter-social_login/facebook/callback` |
| GitHub redirect | `GET /api/plugins/glitter-social_login/github/redirect` |
| GitHub callback | `GET /api/plugins/glitter-social_login/github/callback` |
| OAuth exchange | `POST /api/plugins/glitter-social_login/exchange` |

OAuth consoles must register:

- Naver: `https://your-domain.com/api/plugins/glitter-social_login/naver/callback`
- Kakao: `https://your-domain.com/api/plugins/glitter-social_login/kakao/callback`
- Google: `https://your-domain.com/api/plugins/glitter-social_login/google/callback`
- Facebook: `https://your-domain.com/api/plugins/glitter-social_login/facebook/callback`
- GitHub: `https://your-domain.com/api/plugins/glitter-social_login/github/callback`

## Settings

Open Admin → Plugins → Social Login → Settings at `/admin/plugins/glitter-social_login/settings`. Enable each provider and enter its client ID and secret. Provider visibility is read from:

```text
_global.plugins['glitter-social_login'].naver_enabled
_global.plugins['glitter-social_login'].kakao_enabled
_global.plugins['glitter-social_login'].google_enabled
_global.plugins['glitter-social_login'].facebook_enabled
_global.plugins['glitter-social_login'].github_enabled
```

Provider buttons and linked-account rows use the saved Social Login Display Order. The default order is Naver, Kakao, Google, Facebook, GitHub. The admin ordering control is shown only when at least two providers are enabled; it changes presentation only and does not change provider availability, credentials, routes, or OAuth behavior.

Drag enabled providers to change their order on the login, registration, and linked-account screens. Disabled providers retain their saved positions and return to those positions when re-enabled.

GitHub uses Laravel Socialite's built-in `GithubProvider`. The bundled v5.31.0 implementation requests
the `user:email` scope and selects only a primary, verified email from `/user/emails`. That selected
email may participate in existing-account matching; GitHub provider-ID matching remains authoritative.
If GitHub does not return a usable email, the existing no-email signup fallback is used. GitHub is
rendered with the plugin-local `resources/images/github.svg` through the same `BrandIcons` data-URI
architecture used by the other local social-provider assets.

For Facebook, the App ID and App Secret are issued by Meta for Developers. Facebook is the fourth
provider and uses Laravel Socialite's built-in Facebook provider. The callback URL must be registered
in the Facebook Login settings.

Facebook email is not eligible for automatic existing-account email matching in version 1.3.0. The
authoritative identity is `(provider, provider_user_id)`, where the provider is `facebook`. If no
Facebook email is available, the existing no-email signup fallback remains available. Facebook's
raw `verified` field is not interpreted as Google's `email_verified` or Kakao's
`is_email_verified`.

For Naver, the stable provider identity is the string returned as `response.id` by the authenticated
`GET https://openapi.naver.com/v1/nid/me` profile response. The optional `response.email` may take
part in the existing-account email matching flow only after the OAuth state, token, and validated
profile response have succeeded. Naver does not expose Google's `email_verified` field; the plugin
does not fabricate one. If Naver does not return a valid email, login continues without email matching
and the existing no-email signup fallback is used. Optional profile fields are not persisted beyond the
existing social-account email field.

Naver Developers must be configured with the Naver Login API and the callback URL above. Enter the
Naver Client ID and Client Secret in the plugin settings page. No database migration is required for
Naver because the existing `(provider, provider_user_id)` account key stores provider IDs as strings.

The plugin does not modify templates. `LoginPageWidgetListener` subscribes to `core.layout_extension.after_apply` and injects the widget for `auth/login` and `auth/register`. It identifies each auth container by its direct semantic children, so visual classes such as `dark:bg-gray-800` or `dark:bg-slate-800` do not determine whether injection occurs.

## Installation lifecycle

Run the project lifecycle commands from the project root after review:

```bash
/usr/local/bin/php83 artisan extension:update-autoload
/usr/local/bin/php83 artisan plugin:update glitter-social_login --force --source=bundled
```

Do not edit generated hook/cache files manually. The lifecycle operation must rebuild the active extension discovery state.

## Existing settings and data

Gnuboard7 stores plugin settings under the plugin identifier. Renaming the plugin does not automatically copy the old identifier's settings. Before activating this identity, an operator must explicitly migrate or restore the existing settings into the new identifier's settings storage according to the site's approved deployment procedure. This source package does not silently retain a permanent runtime dependency on the previous identity.

The database migration preserves existing social-login rows by renaming legacy tables to the Glitter.kr table names during the normal plugin migration lifecycle. No production migration is run by this source change.

## Development

Static syntax checks are safe; tests must run only in an isolated non-production environment. The plugin's unit regression test covers both the current slate login card styling and the previously supported gray styling.

See [LICENSE](./LICENSE) for the MIT license text.
