# Haskimail для Symfony Mailer

Мост между [Symfony Mailer](https://symfony.com/doc/current/mailer.html) и транзакционным сервисом [Haskimail](https://haskimail.ru): отправка через HTTP API или SMTP, шаблоны, теги, метаданные, трекинг, вложения и приём вебхуков как Symfony RemoteEvent с проверкой подписи.

| Возможность | API (`haskimail+api://`) | SMTP (`haskimail+smtp://`) |
|---|:---:|:---:|
| Текст, HTML, вложения, встроенные картинки | ✓ | ✓ |
| Cc, Bcc, Reply-To, свои заголовки, свой Message-ID | ✓ | ✓ |
| Тег (`TagHeader`) и метаданные (`MetadataHeader`) | ✓ | ✓ |
| Выбор канала (MessageStream) на каждое письмо | ✓ | — (логин SMTP привязан к каналу) |
| Шаблоны Haskimail (`/email/withTemplate`) | ✓ | — |
| Включить/выключить трекинг открытий и кликов | ✓ | открытия — включить/выключить, клики — только выключить |
| Синхронная валидация и коды ошибок API | ✓ | — (ошибки приходят позже баунсами) |

Совместимость: PHP 8.1+, Symfony 6.4 / 7.x / 8.x.

## Установка

```bash
composer require haskimail/symfony-mailer
```

С Symfony Flex бандл подключится сам. Без Flex добавьте его в `config/bundles.php`:

```php
return [
    // ...
    Haskimail\SymfonyMailer\HaskimailMailerBundle::class => ['all' => true],
];
```

Бандл регистрирует фабрику транспорта (DSN `haskimail://` начинает работать в `framework.mailer`) и, если установлен `symfony/webhook`, сервис `haskimail.webhook.request_parser`.

## Настройка

```dotenv
# .env.local — токен сервера: «Сервер → API-ключи» в личном кабинете
MAILER_DSN=haskimail+api://SERVER_TOKEN@default?message_stream=2081
```

| DSN | Что делает |
|---|---|
| `haskimail://TOKEN@default` | HTTP API (то же, что `haskimail+api`) |
| `haskimail+api://TOKEN@default?message_stream=ID` | HTTP API, канал по умолчанию для всех писем |
| `haskimail+smtp://LOGIN:PASSWORD@default` | SMTP `smtp.haskimail.ru:587` со STARTTLS. Логин и пароль — на вкладке «Настройки» канала |
| `haskimail+smtp://LOGIN:PASSWORD@default:2525` | SMTP на другом порту (25, 587, 2525) |

Если токен или пароль содержит `@`, `:`, `/`, `?`, `#` или `%`, закодируйте его через `urlencode()`.

Без `message_stream` Haskimail отправит письмо в транзакционный канал сервера по умолчанию.

Для проверки интеграции без отправки писем используйте тестовый токен `HASKIMAIL_API_TEST` (`haskimail+api://HASKIMAIL_API_TEST@default`): API проверит запрос и вернёт обычный ответ, но ничего не отправит.

## Отправка

```php
use Haskimail\SymfonyMailer\Header\MessageStreamHeader;
use Haskimail\SymfonyMailer\Header\TrackLinksHeader;
use Haskimail\SymfonyMailer\Header\TrackOpensHeader;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

$email = (new Email())
    ->from(new Address('shop@example.com', 'Магазин'))
    ->to(new Address('ivan@example.com', 'Иван'))
    ->replyTo('support@example.com')
    ->subject('Ваш заказ №1042 оформлен')
    ->text('Здравствуйте! Заказ №1042 оформлен.')
    ->html('<p>Здравствуйте! Заказ №1042 оформлен.</p><img src="cid:logo">')
    ->embedFromPath('/path/to/logo.png', 'logo')
    ->attachFromPath('/path/to/invoice.pdf', 'Счёт.pdf');

$email->getHeaders()
    ->add(new TagHeader('orders'))                // один тег на письмо
    ->add(new MetadataHeader('order_id', '1042')) // вернётся в вебхуках
    ->add(new MessageStreamHeader('2081'))        // канал для этого письма
    ->add(new TrackOpensHeader(true))
    ->add(new TrackLinksHeader(false));

$mailer->send($email);
```

Адресаты и отправитель:

- `From` в API — это видимый заголовок письма (`->from()`); `Sender`, `Return-Path` и envelope sender его не подменяют.
- Получателей определяет envelope: если вы переопределили его (например, `framework.mailer.envelope.recipients` на dev-окружении), Cc и Bcc, которых нет в envelope, письмо не получат.

ID письма в Haskimail доступен в `SentMessage::getMessageId()`: его возвращает `TransportInterface::send()`, а при отправке через `MailerInterface` — событие `SentMessageEvent`. Если у письма несколько получателей, Haskimail создаёт отдельное письмо на каждого и возвращает ID через запятую в порядке получателей; `getMessageId()` содержит именно эту строку.

### Шаблоны Haskimail

```php
use Haskimail\SymfonyMailer\Mime\HaskimailTemplateEmail;

$email = (new HaskimailTemplateEmail())
    ->from('shop@example.com')
    ->to('ivan@example.com')
    ->template(4521, ['name' => 'Иван', 'order' => '1042']); // ID или алиас: ->template('welcome', [...])

$mailer->send($email);
```

Haskimail делает буквальную замену, поэтому `template()` оборачивает имена переменных в `{{…}}` (разделители можно передать третьим и четвёртым аргументами). Если ключи уже содержат разделители, используйте `templateWithRawModel(4521, ['{{name}}' => 'Иван'])`. Тема берётся из шаблона, если не задана явно через `->subject()`.

Шаблоны работают только через API. Если вы используете `failover(haskimail+api, другой транспорт)`, запасной транспорт отправит письмо без шаблона, с пустым телом.

Те же возможности доступны для любого `Email` через заголовки `TemplateHeader::id(4521)` / `TemplateHeader::alias('welcome')` и `TemplateModelHeader::fromVariables([...])`.

### Обработка ошибок

API-транспорт проверяет ответ синхронно и бросает `HaskimailApiException` (наследник `HttpTransportException`):

```php
use Haskimail\SymfonyMailer\Exception\HaskimailApiException;

try {
    $transport->send($email);
} catch (HaskimailApiException $e) {
    $e->getErrorCode();   // ErrorCode из ответа API, например 1101 (шаблон не найден)
    $e->getApiMessage();  // текст ошибки от Haskimail
    $e->getStatusCode();  // HTTP-статус
    $e->isRetryable();    // true для 5xx и временных ошибок (36, 100)

    if (HaskimailApiException::ERROR_SENDER_NOT_CONFIRMED === $e->getErrorCode()) {
        // подтвердите адрес или домен отправителя
    }
}
```

Сетевые сбои приходят как `TransportException` / `HttpTransportException` с исходной ошибкой в `getPrevious()`. Токен сервера вырезается из отладочного лога HTTP (`SentMessage::getDebug()`, `HttpTransportException::getDebug()`), не попадает в сообщения об ошибках, `__toString()`, `var_dump()`, а сериализовать транспорт нельзя.

## Вебхуки

Нужны пакеты `symfony/webhook` и `symfony/remote-event`:

```bash
composer require symfony/webhook symfony/remote-event
```

```yaml
# config/packages/webhook.yaml
framework:
    webhook:
        routing:
            haskimail:
                service: haskimail.webhook.request_parser
                secret: '%env(HASKIMAIL_WEBHOOK_SECRET)%'
```

`HASKIMAIL_WEBHOOK_SECRET` — ключ подписи вебхуков: «Сервер → Канал → Вебхуки» в личном кабинете. URL вебхука в Haskimail: `https://ваш-сайт/webhook/haskimail`.

```php
use Haskimail\SymfonyMailer\RemoteEvent\HaskimailPayloadConverter;
use Symfony\Component\RemoteEvent\Attribute\AsRemoteEventConsumer;
use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerDeliveryEvent;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerEngagementEvent;
use Symfony\Component\RemoteEvent\RemoteEvent;

#[AsRemoteEventConsumer('haskimail')]
final class HaskimailWebhookConsumer implements ConsumerInterface
{
    public function consume(RemoteEvent $event): void
    {
        match (true) {
            $event instanceof MailerDeliveryEvent => $this->onDelivery($event),     // delivered, bounce
            $event instanceof MailerEngagementEvent => $this->onEngagement($event), // open, click, spam, unsubscribe
            HaskimailPayloadConverter::RESUBSCRIBE === $event->getName() => $this->onResubscribe($event),
            default => null,
        };
    }
}
```

| Событие Haskimail | Событие Symfony |
|---|---|
| `delivered` | `MailerDeliveryEvent::DELIVERED` |
| `bounced` | `MailerDeliveryEvent::BOUNCE`, текст ошибки в `getReason()` |
| `complained` | `MailerEngagementEvent::SPAM` |
| `opened` | `MailerEngagementEvent::OPEN` |
| `clicked` | `MailerEngagementEvent::CLICK` |
| `subscriptionchange`, `SuppressSending: true` | `MailerEngagementEvent::UNSUBSCRIBE` |
| `subscriptionchange`, `SuppressSending: true`, `SuppressionReason: HardBounce` | `RemoteEvent` с именем `HaskimailPayloadConverter::HARD_BOUNCE` |
| `subscriptionchange`, `SuppressSending: false` | `RemoteEvent` с именем `HaskimailPayloadConverter::RESUBSCRIBE` |

`bounced` с `bounce_category: sft` (мягкий возврат) приходит как `MailerDeliveryEvent::DEFERRED`, а не `BOUNCE`.

У всех событий: `getId()` — стабильный ключ из `event`, `message_id`, `recipient` и времени события (пригоден для дедупликации), `getRecipientEmail()`, `getDate()` (по `event_time_utc`), `getTags()` — тег письма, `getMetadata()` — метаданные из `custom_vars`. Полный исходный JSON — в `getPayload()`: там `bounce_category` (`hrd`, `sft`, `blk`, `spm`), `url_original`, `SuppressionReason` и другие поля.

**Безопасность.**

- Каждое событие проверяется по подписи `md5(recipient . message_id . ключ)` через `hash_equals`; поддельные запросы получают 403, и Haskimail их не повторяет.
- Событие с верной подписью, но неизвестным типом подтверждается статусом 200: Haskimail прекращает его повторять, а исходный JSON сохраняется в журнале отклонённых вебхуков, поэтому ничего не теряется.
- Если ключ не задан, парсер отвечает 500: Haskimail повторяет 5xx до 60 раз раз в минуту, поэтому события не теряются, пока вы исправляете конфигурацию.
- Поле `secret` удаляется из `getPayload()`, чтобы подпись не попала в логи.
- Ограничение схемы Haskimail: подписаны только адрес и ID письма, тип события и время не подписаны. Перехваченное событие можно повторить или превратить в другое событие того же письма, и проверка времени от этого не защитит. Поэтому держите ключ в секрете, принимайте вебхуки только по HTTPS и делайте обработку идемпотентной. Дополнительно можно ограничить IP-адреса отправителя:

```yaml
# config/services.yaml
services:
    haskimail.webhook.request_parser:
        class: Haskimail\SymfonyMailer\Webhook\HaskimailRequestParser
        arguments:
            $allowedIps: ['203.0.113.0/24']    # null — без проверки; пустой список запрещён
```

Если приложение стоит за прокси или балансировщиком, настройте `framework.trusted_proxies`, иначе фильтр по IP увидит адрес прокси.

Haskimail может прислать одно событие повторно, поэтому делайте обработку идемпотентной, например по `event`, `message_id`, `recipient` и `event_time`.

## Без бандла

```yaml
# config/services.yaml
services:
    Haskimail\SymfonyMailer\Transport\HaskimailTransportFactory:
        parent: mailer.transport_factory.abstract
        tags: ['mailer.transport_factory']

    haskimail.webhook.request_parser:
        class: Haskimail\SymfonyMailer\Webhook\HaskimailRequestParser
```

Вне Symfony-приложения:

```php
use Haskimail\SymfonyMailer\Transport\HaskimailApiTransport;
use Symfony\Component\Mailer\Mailer;

$mailer = new Mailer((new HaskimailApiTransport('SERVER_TOKEN'))->setMessageStream('2081'));
```

## Ограничения Haskimail

Эти проверки выполняет API; транспорт передаёт его ответ как есть:

- не больше 50 получателей в To, Cc и Bcc вместе;
- письмо вместе с вложениями до 10 МБ после Base64, `TextBody` и `HtmlBody` до 5 МБ каждое;
- один адрес в From; значения заголовков без переводов строк (иначе транспорт откажется отправлять, чтобы исключить инъекцию заголовков);
- тема до 2000 символов, тег до 1000 символов, один тег на письмо;
- запрещены вложения `exe`, `bat`, `vbs`, `msi`, `scr` и другие исполняемые форматы;
- отправитель должен быть на подтверждённом домене.

По SMTP теги и метаданные передаются заголовками `X-HM-Tag` и `X-HM-Metadata`. Метаданные кодируются в ASCII-безопасный JSON; для тегов по SMTP используйте латиницу.

SMTP-транспорт никогда не отправляет логин, пароль и письмо без шифрования: если сервер не предложил STARTTLS (например, при атаке на понижение), отправка прерывается до команды `AUTH`. Это работает на всех поддерживаемых версиях Symfony; на 7.3+ дополнительно включается встроенный `setRequireTls(true)`.

Теги и метаданные, которые добавляют слушатели `MessageEvent` (в том числе `framework.mailer.headers`), тоже конвертируются в `X-HM-*`.

Дополнительно для SMTP доступны выбор домена DKIM и внутренний ID письма:

```php
use Haskimail\SymfonyMailer\Header\HaskimailDomainHeader;
use Haskimail\SymfonyMailer\Header\HaskimailMessageIdHeader;

$email->getHeaders()
    ->add(new HaskimailDomainHeader('example.com'))      // X-HM-Domain
    ->add(new HaskimailMessageIdHeader('order-1042'));   // X-HM-MessageID
```

`X-HM-MessageID` задаёт внутренний MessageID, который вернётся в вебхуках и логах письма, — так письмо, отправленное по SMTP, можно связать с его событиями. Через API эти два заголовка игнорируются: API возвращает свой `MessageID`, а домен выбирается по `From`.

## Разработка

```bash
composer install
composer check       # php-cs-fixer + PHPStan (max, strict rules) + PHPUnit
composer infection   # мутационное тестирование (MSI ≥ 90 %)
```

Тесты покрывают реальные примеры запросов и вебхуков из документации Haskimail, работу внутри настоящего ядра Symfony (FrameworkBundle + Mailer + Webhook), SMTP-сессию с настоящим STARTTLS и попытку понижения до открытого текста. CI запускает их на PHP 8.1–8.4 и Symfony 6.4–8.0.

## Лицензия

MIT, см. [LICENSE](LICENSE).
