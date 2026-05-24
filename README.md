# FreshRSS — KillTheNews extension

Subscribe to email newsletters as RSS feeds, powered by your own
[kill-the-news](https://github.com/juherr/kill-the-news) instance. Inspired by
Feedly's and Inoreader's "newsletters as feeds" feature.

## Install

Clone (or download) this repository into your FreshRSS `extensions/` directory:

```sh
cd FreshRSS/extensions
git clone https://github.com/juherr/xExtension-KillTheNews.git
```

Then enable **KillTheNews** in *Settings -> Extensions*.

## Configure

Open the extension's configuration and set:

- **Instance URL** — the base URL of your kill-the-news instance (e.g. `https://news.example.com`).
- **API token** — your instance's admin password (used as a Bearer token).
- **Verify TLS certificate** — leave on unless you use a self-signed certificate.

Use **Test connection** to verify the settings.

## Use

Go to **Subscription management -> Add a subscription**. A *Receive a newsletter by
email* panel appears at the top:

1. Enter a name and click **Create newsletter**.
2. Copy the generated email address and use it to subscribe to the newsletter.
3. FreshRSS is automatically subscribed to the matching feed, in the localized
   extension category (`Newsletters` in English, `Lettres d’information` in
   French). New emails show up as articles.

Newsletter management (rename, delete, sender rules) lives in the kill-the-news
admin dashboard.

## Security notes

- Your kill-the-news API token stays server-side: it is stored in your FreshRSS
  user config and proxied through the extension. It is never sent to the browser.
- The internal endpoint that lists your newsletter addresses is login-gated and
  protected with FreshRSS CSRF validation, even though it only reads data.

## Development

The API client (`KillTheNewsClient`) is framework-free and unit-tested. The
extension runtime supports PHP 8.1+, while the development toolchain currently
requires PHP 8.3+ because of PHPUnit, PHPStan, Psalm, and GrumPHP versions:

```sh
composer install
composer test
```

Run the full local quality gate with:

```sh
composer check
```

Use `composer cs:fix` to apply source formatting fixes. GrumPHP is configured
to run `composer check` as a Git pre-commit hook after dependencies are
installed.

## Release

Releases are automated by GitHub Actions from version tags.

1. Update `metadata.json` and `CHANGELOG.md`.
2. Run `composer update` when dependency constraints changed, then commit
   `composer.lock` with the dependency changes.
3. Run `composer check`.
4. Create and push a tag matching the metadata version:

```sh
git tag v0.1.0
git push origin v0.1.0
```

The release workflow runs the full quality gate, validates that the tag matches
`metadata.json`, builds a `KillTheNews/` plugin ZIP, writes a SHA-256 checksum,
and publishes both files as GitHub release assets. It can also be run manually
from **Actions -> Release** with an existing tag.

`composer.lock` is intentionally versioned so CI, Dependabot, and local hooks
run the same toolchain versions.
