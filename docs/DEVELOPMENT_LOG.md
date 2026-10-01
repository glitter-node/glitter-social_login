# glitter-social_login development notes

## 2026-09-28 — Glitter.kr identity migration

- The bundled plugin identity is now `glitter-social_login`.
- PHP classes use the PSR-4 namespace `Plugins\\Glitter\\SocialLogin`.
- OAuth routes, translation namespaces, frontend configuration expressions, cache keys, and widget IDs use the new identifier.
- The login widget remains a `core.layout_extension.after_apply` integration restricted to `auth/login`.
- The matcher requires a direct heading, form, and bottom-links row in the login container. It intentionally ignores outer presentation classes, including dark-mode background variants.
- Existing provider behavior, account linking, password flags, and OAuth exchange behavior are unchanged.
- Existing settings must be migrated by the operator because plugin settings storage is keyed by identifier.
