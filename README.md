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
3. FreshRSS is automatically subscribed to the matching RSS feed, in a
   `Newsletters` category. New emails show up as articles.

Newsletter management (rename, delete, sender rules) lives in the kill-the-news
admin dashboard.

## Security notes

- Your kill-the-news API token stays server-side: it is stored in your FreshRSS
  user config and proxied through the extension. It is never sent to the browser.
- The internal endpoint that lists your newsletter addresses is a read-only, login-gated
  `GET`. Those addresses could only be read cross-origin if your FreshRSS install is
  deployed with permissive CORS headers (the browser same-origin policy blocks it
  otherwise, and default FreshRSS sends no such headers). If you run a permissive CORS
  setup, consider adding a CSRF / `X-Requested-With` check to `listAction`.

## Development

The API client (`KillTheNewsClient`) is framework-free and unit-tested:

```sh
composer install
composer test
```
