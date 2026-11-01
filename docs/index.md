---
layout: home

hero:
  name: Solo HTTP Client
  text: Fluent requests. Safe defaults.
  tagline: An immutable PSR-18 client on Guzzle. Configure it once and share it; tokens stay with their origin, retries never repeat a payment, and logs never show a secret.
  image:
    src: /logo.svg
    alt: Solo HTTP Client
  actions:
    - theme: brand
      text: Get started
      link: /guide/
    - theme: alt
      text: API reference
      link: /guide/api

features:
  - icon: 🧊
    title: Immutable configuration
    details: timeout(), retry() and every with*() method return a new client. A shared instance never keeps one caller's token, and all copies reuse one connection pool.
  - icon: 🔐
    title: Credentials bound to their origin
    details: Tokens, basic, digest and NTLM credentials go only to the origin of the base URI. Absolute URLs to other hosts and redirects to another origin go without them.
  - icon: 🔁
    title: Safe retries
    details: Opt-in, with capped exponential backoff, jitter and Retry-After. POST and PATCH are repeated only when the server cannot have processed them.
  - icon: 📎
    title: Explicit file uploads
    details: Only File objects become files, so forwarded form data such as avatar[path]=/etc/passwd stays text. Names are percent-encoded and cannot inject part headers.
  - icon: 🕶️
    title: Redacted logs and errors
    details: Query values and URI credentials are masked in log lines and exception messages, path secrets after withRedaction(). The whole exception chain is safe to log, and secrets stay out of stack traces.
  - icon: 📮
    title: PSR-18 on Guzzle 8
    details: Implements ClientInterface with PSR-18 exceptions. Guzzle options, middleware, handlers and promises keep working, and HTTP error statuses are returned, not thrown.
---

<style>
:root {
  --vp-home-hero-name-color: transparent;
  --vp-home-hero-name-background: linear-gradient(135deg, #0ea5e9 0%, #6366f1 100%);
  --vp-home-hero-image-background-image: linear-gradient(135deg, #0ea5e930 0%, #6366f130 100%);
  --vp-home-hero-image-filter: blur(44px);
}

.VPHero .VPImage {
  max-width: 200px;
  max-height: 200px;
}
</style>

## Configure and send

```php
use Solo\HttpClient\HttpClient;

$api = HttpClient::create('https://api.example.com/v1/')
    ->timeout(10)
    ->withToken($token)
    ->retry(3, 500);

$response = $api->get('users', ['query' => ['page' => 2]]);

if ($response->successful()) {
    $users = $response->json('data'); // null when the body is not valid JSON
}

$api->post('users', ['json' => ['name' => 'Ada', 'email' => 'ada@example.com']]);
```

The token goes only to `https://api.example.com`. A 404 or a 503 comes back as a
response; only a failed transfer throws. Read the
[getting-started guide](./guide/index.md) and the [API reference](./guide/api.md)
for credentials, retries, uploads, logging and errors.

## Installation

PHP **8.3+** is required, with Guzzle 8.3 or later.

```sh
composer require solophp/http-client
```

Continue with the [getting-started guide](./guide/index.md).
