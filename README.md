# Finance Tracker Bot

Telegram-бот для учёта личных финансов: записывайте доходы и расходы свободным текстом — бот автоматически определяет категорию, ведёт историю, показывает отчёты и присылает еженедельную сводку.

## Возможности

- Интерактивное меню с кнопками (главное меню + подменю категорий)
- Текстовые записи в свободной форме: «кофе 150», «зарплата 120000»
- Категории по ключевым словам: создание, переименование, удаление (кнопками и командами)
- Отчёт за 7 дней: доходы, расходы, баланс, топ категорий
- История последних записей
- Автоматическая еженедельная сводка всем пользователям (понедельник, 09:00 МСК)
- Markdown-форматирование и ретраи при сетевых сбоях Telegram

## Стек

| Компонент | Версия |
|---|---|
| PHP | 8.4 (требуется 8.3+) |
| Laravel | 13 |
| PostgreSQL | 16 (в тестах — SQLite в памяти) |
| Telegram SDK | irazasyed/telegram-bot-sdk 3.x |
| Тесты | PHPUnit 12 + Mockery |
| Стиль кода | Laravel Pint |

## Установка

1. Клонировать репозиторий:

   ```bash
   git clone https://github.com/Yakovlev-Melarn/finance-tracker-bot.git && cd finance-tracker-bot
   ```

2. Установить зависимости:

   ```bash
   composer install
   npm install
   ```

3. Настроить окружение:

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

   Заполнить в `.env`:
   - `DB_*` — доступы к PostgreSQL;
   - `TELEGRAM_BOT_TOKEN` — токен бота от [@BotFather](https://t.me/BotFather);
   - `TELEGRAM_WEBHOOK_URL` — публичный HTTPS-URL вебхука (например `https://bot.example.com/telegram/webhook`);
   - `TELEGRAM_PROXY` (опционально) — исходящий HTTP-прокси, если сервер не имеет прямого доступа к API Telegram.

4. Создать базу и миграции:

   ```bash
   php artisan migrate
   ```

5. Собрать фронтенд (страница `/`):

   ```bash
   npm run build
   ```

6. Запустить приложение (локально `php artisan serve` или nginx; в продакшене — фактический сервер приложения) и зарегистрировать вебхук:

   ```bash
   curl -X POST "https://api.telegram.org/bot<TOKEN>/setWebhook" \
     -d "url=https://bot.example.com/telegram/webhook"
   ```

   Проверить: `curl "https://api.telegram.org/bot<TOKEN>/getWebhookInfo"`.

## Использование бота

| Команда / действие | Что делает |
|---|---|
| `/start` | Регистрация и главное меню (картинка + кнопки) |
| «кофе 150» | Записать расход (категория — по ключевым словам) |
| «зарплата 120000» | Записать доход |
| `/stats` | Отчёт за 7 дней |
| `/history` | Последние записи |
| `/categories` | Список категорий + подменю кнопок |
| `/categories add Кофе, кофе, латте` | Создать категорию |
| `/categories rename Кофе, Капучино, эспрессо` | Переименовать (и заменить ключевые слова) |
| `/categories delete Кофе` | Удалить (записи становятся без категории) |
| Кнопки 📝 📊 📜 🗂 | Меню: запись, отчёт, история, категории |

Кнопки «➕ Добавить / ✏️ Переименовать / 🗑 Удалить» в подменю категорий переводят бота в режим ожидания: следующее текстовое сообщение интерпретируется как соответствующее действие (состояние хранится в кэше, TTL 15 минут; любой другой клик по кнопке отменяет ожидание).

## Архитектура

Запрос:

```
Telegram ──HTTPS──> POST /telegram/webhook
                        │
                        ▼
              TelegramWebhookController
                        │
              CommandRouter::handle(update)
              ┌─────────┴──────────┐
     message (текст)        callback_query (кнопка)
              │                       │
              ▼                       ▼
   команды / действие         CallbackRouter
   категории (pending)        (меню, отчёты, подменю)
              │                       │
              ▼                       │
     TransactionParser ───────────────┤
     + CategoryMatcher                │
              │                       │
              ▼                       ▼
     BotService (BotMessenger) ──> Telegram API
     (отправка, клавиатуры, ретраи)
```

Модули:

- `app/Http/Controllers/TelegramWebhookController.php` — приём апдейтов; при ошибке ответа возвращает 500, чтобы Telegram повторил доставку.
- `app/Services/Bot/`
  - `BotMessenger` / `BotService` — Telegram API: сообщения, фото, редактирование, inline-клавиатуры (JSON `reply_markup`), ответы на callback, ретраи.
  - `CommandRouter` — роутинг текстовых сообщений: команды, записи, действия категорий (pending).
  - `CallbackRouter` — обработка нажатий кнопок.
  - `Menu` — клавиатуры и welcome-текст.
  - `PendingAction` — состояние «ждём ввод» (кэш, TTL 15 минут).
- `app/Services/Parser/` — `TransactionParser` (текст → сумма/тип/комментарий), `CategoryMatcher` (совпадение ключевых слов), `ParsedTransaction`.
- `app/Services/Categories/` — `CategoryManager` (CRUD: уникальность имени/ключей на пользователя, нормализация, снятие категории с записей при удалении), `CategoryFormatter` (Markdown-список).
- `app/Services/Reports/` — `ReportBuilder` (сводка за 7 дней, история), DTO `WeekStats`, `CategorySpend`.
- `app/Models/` — `User`, `Category`, `Transaction`.
- `app/Support/` — `Markdown` (экранирование для Telegram), `RussianPlural` (склонение).
- `app/Console/Commands/WeeklyReportCommand.php` — команда `reports:weekly`; расписание в `routes/console.php` (еженедельно, понедельник 09:00 Europe/Moscow).
- `resources/images/menu.png` — картинка главного меню.

## Тесты и стиль

```bash
php artisan test          # 89 тестов
vendor/bin/pint --test    # проверка стиля
```

## Деплой

- `deploy.sh` (выполняется на VPS после пуша): pull → `composer install` → `npm run build` → `migrate` → кэши конфигурации/маршрутов/Blade → права на `storage`.
- Планировщик: systemd-таймер `laravel-schedule` (каждую минуту `php artisan schedule:run`) — запускает `reports:weekly`.
- HTTPS: nginx + Let's Encrypt (автопродление certbot).
- Webhook настраивается один раз через `setWebhook` на публичный URL; при ошибке обработки контроллер отвечает 500, и Telegram повторно доставляет апдейт.
