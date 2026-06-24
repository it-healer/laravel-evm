# Laravel EVM

[English](README.md) | **Русский**

Laravel-пакет для работы с **любой EVM-сетью** (Ethereum, BSC, Polygon, Arbitrum, Base, ...):
HD-кошельки, генерация адресов, балансы нативной монеты и ERC-20 токенов, отслеживание входящих
депозитов с вебхуками и исходящие переводы (legacy и EIP-1559).

В отличие от одноцепочечных пакетов, кошельки и адреса здесь **не привязаны к сети** — один и тот же
EVM-адрес работает во всех цепочках. Сети — это полноценные записи: добавьте Polygon, и все
существующие адреса сразу работают в ней, с балансами, транзакциями и депозитами по каждой сети.

## Требования

- PHP 8.2+ с `ext-gmp`
- Laravel 10 / 11 / 12 / 13

## Установка

```bash
composer require it-healer/laravel-evm
php artisan evm:install   # публикует config + миграции
php artisan migrate
```

Расписание синхронизации (в приложении, напр. `routes/console.php`):

```php
Schedule::command('evm:sync')->everyMinute()->withoutOverlapping()->runInBackground();
```

## Сети

Сети можно создавать вручную или брать из каталога [chainid.network](https://chainid.network):

```php
use ItHealer\LaravelEvm\Facades\Evm;

// Вручную
$polygon = Evm::createNetwork(
    chainId: 137,
    name: 'polygon',
    currencySymbol: 'POL',
    title: 'Polygon Mainnet',
);

// Из каталога (подтянет title, валюту, decimals, URL обозревателя)
$bsc = Evm::createNetworkFromChainList(56);

// Просмотр/поиск по каталогу (кэш 24ч) — для пикеров в админке
$chains = app(\ItHealer\LaravelEvm\Services\ChainList\ChainListService::class);
$chains->search('polygon');   // Collection<ChainDTO>
$chains->find(42161);         // ChainDTO|null
```

Настройки сети: `tx_type` (`null` = автоопределение EIP-1559 по baseFeePerGas, `0` = принудительно legacy,
`2` = принудительно EIP-1559), `lag_blocks` (перекрытие синхронизации, увеличьте для быстрых сетей вроде
BSC/Polygon), `confirmations_target`, `active` (неактивные сети пропускаются синхронизацией и не дают переводы).

## Ноды (RPC)

Каждой сети нужна минимум одна RPC-нода. URL для [Alchemy](https://www.alchemy.com) строятся автоматически:

```php
Evm::createNode($polygon, 'public', 'https://polygon-rpc.com');
Evm::createAlchemyNode($polygon, apiKey: 'YOUR_ALCHEMY_KEY', name: 'alchemy');
```

Перед сохранением нода проходит health-check. При нескольких нодах на сеть автоматически выбирается
наименее загруженная рабочая (`Evm::getNode($network)`).

## Обозреватели (история транзакций)

Входящие/исходящие переводы определяются через драйверы обозревателей:

| Драйвер | Покрытие | Примечания |
|---|---|---|
| `etherscan_v2` | 60+ сетей | Один ключ [Etherscan](https://etherscan.io/apis) на все сети (лимит запросов общий!) |
| `alchemy` | Сети Alchemy | Использует `alchemy_getAssetTransfers`; `base_url` должен быть URL Alchemy |

```php
use ItHealer\LaravelEvm\Enums\ExplorerDriver;
use ItHealer\LaravelEvm\Services\AlchemyUrlFactory;

Evm::createExplorer($polygon, ExplorerDriver::EtherscanV2, 'etherscan', apiKey: 'ETHERSCAN_KEY');

Evm::createExplorer($polygon, ExplorerDriver::Alchemy, 'alchemy',
    baseURL: AlchemyUrlFactory::make(137, 'YOUR_ALCHEMY_KEY'));
```

## Токены

Токены отслеживаются по сетям. Создавайте из контракта (3 RPC-вызова) или из
[списка токенов](https://tokenlists.org) вообще без RPC:

```php
// Метаданные из сети
Evm::createToken($polygon, '0xc2132D05D31c914a87C6611C10748AEb04B58e8F');

// Из списков токенов (config: evm.token_lists) — для пикеров в админке
$tokens = app(\ItHealer\LaravelEvm\Services\TokenList\TokenListService::class);
$usdt = $tokens->forChain(137)->firstWhere(fn ($t) => $t->symbol() === 'USDT');
Evm::createTokenFromList($polygon, $usdt);
```

Установите `active = false` у токена, чтобы прекратить его отслеживание, не удаляя историю.

## Кошельки и адреса

```php
// Новый кошелёк (генерируется BIP-39 мнемоника, основной адрес деривируется на индексе 0)
$wallet = Evm::createWallet('main', password: 'secret');

// Импорт существующей мнемоники
$wallet = Evm::createWallet('imported', mnemonic: 'test test ... junk');

// Дополнительные адреса
$address = Evm::createAddress($wallet, 'Deposit #2');

// Watch-only (только наблюдение)
Evm::importAddress($wallet, '0x52908400098527886E0F7030069857D2E4169EE7');

// Валидация / чек-суммы
Evm::validateAddress('0x...');     // с учётом EIP-55
Evm::toChecksumAddress('0x...');
```

### Пути деривации

Разные кошельки используют разные BIP-44 пути. Шаблон пути задаётся **для каждого кошелька**
(`{index}` заменяется индексом адреса):

```php
use ItHealer\LaravelEvm\Evm as EvmCore;

Evm::createWallet('metamask', mnemonic: $m);                                                  // m/44'/60'/0'/0/{index}
Evm::createWallet('ledger', mnemonic: $m, derivationPath: EvmCore::PATH_LEDGER_LIVE);         // m/44'/60'/{index}'/0/0
Evm::createWallet('ledger-old', mnemonic: $m, derivationPath: EvmCore::PATH_LEDGER_LEGACY);   // m/44'/60'/0'/{index}
Evm::createWallet('custom', mnemonic: $m, derivationPath: "m/44'/60'/1'/0/{index}");
```

Шаблон по умолчанию — `config('evm.wallet.default_derivation_path')`.

Секреты (мнемоника, seed, приватные ключи) хранятся в шифровании AES-256; при наличии `password`
у кошелька ключ шифрования выводится из него — разблокируйте через `$wallet->unlockWallet($password)`
перед чтением приватных ключей или переводами.

## Балансы

Балансы хранятся по паре (адрес, сеть) и обновляются синхронизацией:

```php
$row = $address->balanceForNetwork($polygon);   // EvmAddressBalance
$row->balance;                                   // BigDecimal, нативная монета
$row->tokens;                                    // [контракт => '123.45']

$wallet->balanceForNetwork($polygon);            // BigDecimal, сумма по адресам
$wallet->tokensForNetwork($polygon);             // [контракт => BigDecimal]

// Живые запросы в сеть
Evm::getBalance($polygon, $address);
Evm::getBalanceOfToken($polygon, $address, $usdtToken);
```

## Переводы

Все методы перевода принимают сеть первым аргументом. Комиссии оцениваются автоматически:
legacy `gasPrice` либо EIP-1559 `maxFeePerGas = 2 × baseFee + priorityFee` (см. `config('evm.fee')`).
Nonce выделяется под кэш-локом по паре (сеть, адрес), поэтому параллельные переводы не конфликтуют.

```php
// Предпросмотр (без подписи): суммы, gas, комиссия, итоговые балансы, ошибка если есть
$preview = Evm::previewTransfer($polygon, $fromAddress, '0xRecipient', '0.5');

// Нативная монета
$result = Evm::transfer($polygon, $fromAddress, '0xRecipient', '0.5');
$result->txid();

// ERC-20
Evm::transferToken($polygon, $usdtToken, $fromAddress, '0xRecipient', '100');

// transferFrom (комиссию платит collector, требуется предварительный approve)
Evm::transferFromToken($polygon, $usdtToken, $collectorAddress, '0xHolder', '0xRecipient', '100');
```

## Синхронизация и депозиты

`evm:sync` проходит по каждой активной сети → каждому кошельку → каждому адресу: обновляет балансы,
импортирует историю транзакций через драйвер обозревателя (курсор `sync_block_number` на пару
адрес×сеть с перекрытием `lag_blocks`; повторные запуски идемпотентны), записывает входящие
переводы как `EvmDeposit` и вызывает ваш webhook-обработчик для каждого **нового** депозита.

```php
// config/evm.php
'webhook_handler' => \App\Services\WebhookHandlers\EvmWebhookHandler::class,
```

```php
use ItHealer\LaravelEvm\Models\EvmDeposit;
use ItHealer\LaravelEvm\Webhook\WebhookHandlerInterface;

class EvmWebhookHandler implements WebhookHandlerInterface
{
    public function handle(EvmDeposit $deposit): void
    {
        $deposit->network;   // EvmNetwork — ветвление по сети
        $deposit->symbol;    // 'POL' или символ токена
        $deposit->amount;    // BigDecimal
        $deposit->confirmations;
    }
}
```

Команды:

```
evm:sync                                     # всё (под кэш-локом)
evm:network-sync {network}                   # одна сеть (id, chain id или имя)
evm:wallet-sync {wallet_id} [--network=]
evm:address-sync {address_id} [--network=] [--force]
evm:node-sync {node_id}                      # health check
evm:explorer-sync {explorer_id}              # health check
```

Сервисы синхронизации поддерживают хуки прогресса/отмены (для ресинков из UI):

```php
(new AddressNetworkSync($address, $network, force: true))
    ->onProgress(fn (int $count, string $stage) => cache()->put($key, $count))
    ->cancelWhen(fn (): bool => (bool) cache()->get($cancelKey))
    ->run();
```

### Адаптивная синхронизация (touch)

Для крупных инсталляций включите адаптивную синхронизацию (`evm.touch`), чтобы адреса
опрашивались **часто, пока ими пользуются, и редко в покое**, а не на каждом прогоне. Адрес
считается «активным» в течение `waiting_seconds` после последнего `touch_at` (проставляется при
активности пользователя/мерчанта); пока активен — синк не чаще `fast_interval`, в покое — не чаще
`slow_interval`.

```php
// config/evm.php
'touch' => [
    'enabled' => true,
    'waiting_seconds' => 1800,  // оставаться «активным» 30 мин после последнего touch
    'fast_interval' => 60,      // пока активен: не чаще раза в 60с
    'slow_interval' => 3600,    // в покое: не чаще раза в час (null = пропускать покой полностью)
],
```

Отмечайте активность, обновляя `touch_at` при использовании кошелька (просмотр в GUI, вызов API,
разблокировка):

```php
$address->update(['touch_at' => now()]);
// или массово для кошелька:
$wallet->addresses()->update(['touch_at' => now()]);
```

Значения по умолчанию (`fast_interval` 0, `slow_interval` null) сохраняют прежнее поведение:
активные адреса синхронизируются каждый прогон, неактивные пропускаются. `evm:address-sync --force`
игнорирует расписание.

## Депозиты в реальном времени через вебхуки Alchemy

Поллинг платит за скан обозревателя по каждому адресу на каждом прогоне, даже когда ничего не
произошло. [Address Activity вебхуки Alchemy](https://www.alchemy.com/docs/reference/address-activity-webhook)
переворачивают это: Alchemy **присылает** уведомление в момент любой активности по наблюдаемому
адресу — **входящей или исходящей** — пакет проверяет подпись и запускает **точечный**
`AddressNetworkSync` для этого адреса (совпадение ищется и когда адрес отправитель, и когда
получатель, по всем кошелькам, где он есть). Определение депозитов, история исходящих, дедупликация
и ваш `webhook_handler` остаются прежними — вы просто перестаёте платить Compute Units за холостой поллинг.

> Бесплатный тариф: 5 вебхуков (= до 5 сетей), до 100к адресов на вебхук. Notify
> **Auth Token** (dashboard → Webhooks → вверху справа) — это **не** ваш RPC API-ключ.

### 1. Настройка

```dotenv
EVM_ALCHEMY_NOTIFY_AUTH_TOKEN=your-notify-auth-token
EVM_ALCHEMY_WEBHOOK_ENABLED=true
EVM_ALCHEMY_WEBHOOK_URL=https://your-app.com/evm/alchemy/webhook   # публичный HTTPS-приёмник
EVM_ALCHEMY_AUTO_SUBSCRIBE=true                                    # авто-подписка новых адресов
```

Маршрут приёмника регистрируется пакетом (путь `evm.alchemy.webhook.path`, по умолчанию
`evm/alchemy/webhook`). Он **не** входит в middleware-группы `web`/`auth` — аутентификацией
служит HMAC-подпись — поэтому держите его вне локализованных/CSRF-защищённых префиксов.

### 2. Создание вебхука и подписка адресов

```bash
php artisan evm:alchemy-setup polygon --reconcile   # создаёт вебхук + загружает существующие адреса
```

`--reconcile` (или `evm:alchemy-reconcile`) сверяет адреса, которые пакет отслеживает в сети
(доступные адреса кошельков с привязанной сетью), со списком в Alchemy и применяет разницу
(батчами по 500/запрос). Запускайте повторно после привязки сети к кошельку либо полагайтесь
на `evm.alchemy.auto_subscribe` для инкрементального добавления/удаления при создании/удалении адресов.

```bash
php artisan evm:alchemy-reconcile            # все настроенные сети
php artisan evm:alchemy-reconcile polygon    # одна сеть
```

Программный API (фасад):

```php
Evm::ensureAlchemyWebhook($polygon);                 // создать или переиспользовать, вернёт EvmAlchemyWebhook
Evm::subscribeAlchemyAddress($address, $polygon);
Evm::unsubscribeAlchemyAddress($address, $polygon);
Evm::reconcileAlchemyWebhook($polygon);              // ['added' => [...], 'removed' => [...]]
```

### 3. Подтверждения

Address Activity вебхуки срабатывают **один раз** (когда транзакция попала в блок) и не
переотправляются по мере роста подтверждений. Если ваш обработчик ждёт N подтверждений,
поставьте дешёвый точечный «дозревающий» ресинк только тех адресов, у которых есть депозиты
ниже `confirmations_target`:

```php
Schedule::command('evm:confirm-deposits')->everyFiveMinutes()->withoutOverlapping();
```

Оставьте `evm:sync` редким fallback'ом (напр., ежечасно) на случай недоставленных уведомлений —
повторные запуски идемпотентны.

## Compute Units, контроль расхода и балансировка нагрузки

Каждый RPC-вызов ноды и каждый запрос обозревателя учитываются в счётчике `credits`
(Compute Units), который **обнуляется в начале каждого календарного месяца**. При выборе ноды и
обозревателя берётся тот, у кого **меньше всего кредитов в этом месяце**, поэтому нагрузка (и расход
CU у Alchemy) автоматически распределяется между несколькими нодами/обозревателями сети.

```php
$node = Evm::getNode($polygon);   // наименее загруженная нода в этом месяце
$node->credits;                   // потрачено CU в этом месяце
$node->creditsThisMonth();        // то же, но 0, если счётчик с прошлого месяца
```

Стоимость методов в CU берётся из `ItHealer\LaravelEvm\Services\Alchemy\ComputeUnits` и повторяет
опубликованные цены Alchemy; любую из них можно переопределить в `config('evm.compute_units')`.
Для не-Alchemy провайдеров эта таблица — просто весовой коэффициент нагрузки.

### Снижение расхода CU у Alchemy

Поллинг прожорлив по CU, потому что `alchemy_getAssetTransfers` стоит 150 CU и выполняется для обоих
направлений по каждому адресу на каждом прогоне. Варианты, от самого дешёвого:

- **Используйте вебхуки** вместо поллинга (см. выше) — платите CU только когда есть активность.
- **`evm.sync.track_outgoing = false`** — определять только депозиты (запрос лишь по `toAddress`),
  что вдвое сокращает запросы `getAssetTransfers` у обозревателя Alchemy.
- **`evm.sync.block_cache_ttl = N`** (секунды) — получать `eth_blockNumber` один раз на сеть за
  прогон, а не по разу на каждый адрес.
- Сохранённые decimals токена переиспользуются автоматически, поэтому чтение баланса токена больше
  не тратит лишний `eth_call` на `decimals()`.
- Добавьте несколько нод/обозревателей на сеть — запросы распределятся по наименее загруженным.

## Кастомизация моделей

Любую модель можно заменить в `config('evm.models')` подклассом — пакет везде резолвит модели
через эту карту.

## Тестирование

```bash
vendor/bin/pest
```

Деривация адресов и подпись транзакций (legacy + EIP-1559) проверяются по векторам, сгенерированным
ethers.js; драйверы обозревателей и синхронизация — на HTTP-фейках.

## Заказная разработка и контакты / Custom development & contacts

**RU** — Нужен новый проект «под ключ» или интеграция этих модулей в существующее приложение? Свяжитесь с разработчиком напрямую — доступны заказная разработка, интеграция модулей и поддержка.

**EN** — Need a new project built from scratch, or these modules integrated into your existing application? Contact the developer directly — custom development, module integration and ongoing support are available.

- 🌐 Сайт / Website: [it-healer.com](https://it-healer.com)
- ✈️ Telegram: [@biodynamist](https://t.me/biodynamist) · +90 551 629 47 16
- 📱 WhatsApp: [+90 551 629 47 16](https://wa.me/905516294716)
- 📧 Email: [info@it-healer.com](mailto:info@it-healer.com)
- 🐛 Баг-репорты / Issues: [GitHub Issues](https://github.com/it-healer/laravel-evm/issues)

## Лицензия

MIT
