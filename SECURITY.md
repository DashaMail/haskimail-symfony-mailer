# Security policy

Please report vulnerabilities privately through Haskimail support (https://help.haskimail.ru)
instead of opening a public issue.

## Design notes

- The server token and SMTP password are marked `#[\SensitiveParameter]` and never appear in exception
  messages, logs produced by this package or in the transport's string representation.
- Webhooks are authenticated with Haskimail's `secret` field (`md5(recipient . message_id . signing key)`),
  compared in constant time with `hash_equals()`. A missing signing key fails closed (HTTP 500) instead of
  accepting unsigned events.
- The SMTP transport requires TLS when `symfony/mailer` supports it (7.3+).
- Messages passed to the transports are never mutated, so they can be safely re-sent or routed elsewhere.
