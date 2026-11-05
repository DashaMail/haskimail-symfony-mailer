# Changelog

## 1.0.0

- HTTP API transport (`haskimail://`, `haskimail+api://`): `POST /email` and `POST /email/withTemplate`, Cc/Bcc/Reply-To,
  custom headers, tags, metadata, per-message message stream, open/link tracking, attachments and inline images.
- SMTP transport (`haskimail+smtp://`) for `smtp.haskimail.ru` with STARTTLS, tag and metadata headers.
- `HaskimailTemplateEmail` and `TemplateHeader` / `TemplateModelHeader` for stored templates.
- `HaskimailApiException` exposing the API `ErrorCode`, message, HTTP status and a retry hint.
- Webhook request parser with MD5 signature verification and conversion to Symfony mailer RemoteEvents.
- `HaskimailMailerBundle` registering the transport factory and webhook services.
