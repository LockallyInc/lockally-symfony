# Lockally Mailer transport for Symfony

Official Symfony Mailer transport that sends through the [Lockally](https://lockally.com)
API. Point your `MAILER_DSN` at Lockally and every `$mailer->send($email)` — including
Messenger-queued mail — is delivered by Lockally, with no other code changes.

```bash
composer require lockally/symfony-mailer
```

## Configure

```dotenv
# .env
MAILER_DSN=lockally+api://YOUR_API_KEY@default
```

The API key is the DSN user. Sandbox vs. live is implicit in the key prefix
(`lk_test_…` runs against the sandbox, `lk_live_…` sends real mail) — there is no
separate endpoint to configure. A private base URL can be set with
`?base_url=https://api.internal.example`.

## Register the transport factory

In the full Symfony framework, register the factory as a tagged service so the DSN
scheme resolves (a Flex recipe does this automatically; otherwise add it yourself):

```yaml
# config/services.yaml
services:
    Lockally\Symfony\LockallyTransportFactory:
        parent: mailer.transport_factory.abstract
        tags: ['mailer.transport_factory']
```

Standalone (without the framework), build a transport directly:

```php
use Lockally\Symfony\LockallyTransportFactory;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Dsn;

$transport = (new LockallyTransportFactory())
    ->create(Dsn::fromString('lockally+api://YOUR_API_KEY@default'));
$mailer = new Mailer($transport);
```

## Send

```php
use Symfony\Component\Mime\Email;

$email = (new Email())
    ->from('alerts@yourdomain.com')
    ->to('user@example.com')
    ->replyTo('support@yourdomain.com')
    ->subject('Your code')
    ->text('code 123')
    ->html('<b>code 123</b>');

$mailer->send($email);
```

### Mapping notes

- **Reply-To** — Lockally has no `reply_to` field, so it is sent as a `Reply-To` header.
- **Attachments** — sent as `{filename, content_type, content_base64}` (base64 of the raw
  bytes). Inline/CID parts are delivered as normal attachments in v1.
- **Idempotency** — one `Idempotency-Key` is generated per send; the API dedupes on it for
  24 h.

## Requirements

- PHP 8.1+
- `symfony/mailer` 6.2+ or 7.x
- A Lockally API key ([console](https://lockally.com))

## License

MIT © Lockally
