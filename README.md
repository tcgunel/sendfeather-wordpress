# Sendfeather SMTP (WordPress plugin)

Development home of **Sendfeather SMTP**, the WordPress plugin that routes
`wp_mail()` through [Sendfeather](https://sendfeather.com) — over the HTTP API
with an API key, or over plain SMTP with SMTP-user credentials.

The distributed plugin lives on wordpress.org (`sendfeather` slug); this
repository is where it is developed and released from. The Sendfeather
platform itself is a separate, private repository — nothing in this plugin
depends on it beyond the public API and SMTP endpoint.

## Layout

```
sendfeather.php                 Plugin bootstrap + header
includes/
  class-plugin.php              Activation and wiring
  class-settings.php            Settings screen (Settings API) + test email
  class-api-client.php          HTTP client for POST /api/emails
  class-mailer.php              pre_wp_mail (API mode) + phpmailer_init (SMTP mode)
  assets/css/admin.css          Settings screen styles
  assets/js/admin.js            Settings screen behavior (no dependencies)
uninstall.php                   Removes the plugin's option on uninstall
readme.txt                      wordpress.org directory readme
dev/                            Dev-only: wp-env overrides + build script (never shipped)
```

## Development environment

Requires Docker and Node (tested with Node 22).

```sh
npm install
npx wp-env start     # WordPress at http://localhost:8888 (admin / password)
```

`wp-env` auto-activates the plugin and loads `dev/mu-plugins/`, which:

- points the plugin's API base URL at a local platform stack
  (`http://host.docker.internal:8091/api` — adjust to your stack's port),
- logs `wp_mail_failed` messages to stderr so CLI tests can assert failures.

Configure both connection modes with WP-CLI:

```sh
npx wp-env run cli wp option update sendfeather_settings --format=json '{...}'
npx wp-env run cli wp eval 'var_dump( wp_mail( "you@example.com", "Test", "Hello" ) );'
```

## Verification checklist (mirrors the wordpress.org review requirements)

- Activate on a clean install with `WP_DEBUG` on; no notices in `debug.log`.
- API mode sends; the message appears in the platform's Activity timeline.
- SMTP mode sends; same.
- A bad key / bad password makes `wp_mail()` return `false` and fires
  `wp_mail_failed`.
- Test email works from the settings screen (nonce + capability enforced).
- `wp plugin check sendfeather-wordpress` reports no errors.
- `readme.txt` passes the wordpress.org readme validator.

## Releasing

```sh
npm run build:zip    # builds dist/sendfeather.zip (excludes dev/ and images)
```

Upload the zip through the wordpress.org "Add your plugin" form. After the
first approval, releases are deployed to the plugin's SVN
(`https://plugins.svn.wordpress.org/sendfeather/`); keep this repository as
the development home and mirror tagged releases to SVN `trunk` + `tags/`.
Directory assets (icons, banners, screenshots) live in SVN `assets/` only and
are kept here under `assets/images/` as the source of truth.

## License

GPL-2.0-or-later (wordpress.org requires GPLv2-or-later for hosted plugins).
The Sendfeather platform repository remains MIT — the two codebases do not
share license terms.
