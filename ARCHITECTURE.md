# Telegram-бот: Учёт рабочих часов учителя

## 1. Архитектура проекта

### Структура папок

```
telegram-bot/
├── public/
│   └── index.php              # Единственная точка входа (webhook)
├── config/
│   └── config.php             # Конфигурация (токен, БД, white-list)
├── src/
│   ├── Database.php           # Обёртка над SQLite (PDO)
│   ├── TelegramApi.php        # HTTP-клиент для Telegram Bot API
│   ├── Router.php             # Маршрутизация: команды → handlers
│   ├── AuthMiddleware.php     # Middleware проверки авторизации
│   ├── Handlers/
│   │   ├── StartHandler.php   # /start — авторизация
│   │   ├── LessonHandler.php  # Парсинг "A 1.5" → запись урока
│   │   ├── SalaryHandler.php  # /salary — расчёт зарплаты
│   │   ├── StatsHandler.php   # /stats — статистика
│   │   └── UndoHandler.php    # /undo — отмена последнего
│   └── Services/
│       ├── LessonService.php  # Бизнес-логика уроков
│       └── SalaryService.php  # Расчёт зарплаты по периодам
├── database/
│   ├── migrate.php            # Создание таблиц
│   └── bot.sqlite             # SQLite-файл (создаётся автоматически)
├── scripts/
│   └── set_webhook.php        # Установка webhook
├── .env.example               # Пример переменных окружения
├── .gitignore
├── composer.json
└── ARCHITECTURE.md            # Этот файл
```

### Принципы разделения

| Слой | Ответственность |
|------|----------------|
| `public/index.php` | Получает webhook, парсит JSON, запускает Router |
| `Router` | Определяет тип сообщения, вызывает middleware, делегирует handler |
| `AuthMiddleware` | Проверяет авторизован ли user, блокирует если нет |
| `Handlers/*` | Обработка конкретной команды / сообщения |
| `Services/*` | Бизнес-логика, работа с БД |
| `Database` | PDO-обёртка, prepared statements |
| `TelegramApi` | Отправка сообщений, клавиатур |
| `config/config.php` | Все настройки в одном месте |

---

## 2. База данных — SQLite

### Почему SQLite, а не MySQL?

| Критерий | SQLite | MySQL |
|----------|--------|-------|
| Установка | Ничего, встроен в PHP | Нужен сервер |
| Один пользователь | Идеально | Избыточно |
| Бэкап | Скопировать 1 файл | mysqldump |
| Производительность | Достаточно для 1 user | Избыточно |
| Деплой | Просто | Нужна настройка |

**Вывод:** SQLite — идеальный выбор для персонального бота.

### Схема таблиц

```sql
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    telegram_id INTEGER NOT NULL UNIQUE,
    phone TEXT NOT NULL,
    is_authorized INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS lessons (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    date TEXT NOT NULL,                          -- YYYY-MM-DD
    level TEXT NOT NULL CHECK (level IN ('A', 'B', 'C')),
    hours REAL NOT NULL CHECK (hours IN (0.5, 1.0, 1.5)),
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE INDEX idx_lessons_user_date ON lessons(user_id, date);
```

### Связи

```
users.id  ←──  lessons.user_id  (1 : N)
```

| Поле | Тип | Описание |
|------|-----|----------|
| `users.telegram_id` | INTEGER UNIQUE | Telegram user ID |
| `users.phone` | TEXT | Номер телефона (проверен через contact) |
| `users.is_authorized` | INTEGER | 1 = авторизован |
| `lessons.date` | TEXT | Дата урока (YYYY-MM-DD) |
| `lessons.level` | TEXT | A / B / C |
| `lessons.hours` | REAL | 0.5 / 1.0 / 1.5 |

---

## 3. Авторизация (подробно)

### Поток авторизации

```
Пользователь → /start
    ↓
Бот: "Для авторизации отправьте номер телефона"
    + KeyboardButton с request_contact = true
    ↓
Пользователь нажимает кнопку → Telegram отправляет contact
    ↓
Бот получает update.message.contact
    ↓
Проверка: contact.user_id === message.from.id
    (защита от отправки чужого контакта)
    ↓
Проверка: phone в white-list (config)
    ↓
Сохранение в users: telegram_id + phone
    ↓
Бот: "Вы авторизованы! ✅"
```

### Кнопка "Отправить номер"

```php
$keyboard = [
    'keyboard' => [[
        [
            'text' => '📱 Отправить номер',
            'request_contact' => true,
        ]
    ]],
    'resize_keyboard' => true,
    'one_time_keyboard' => true,
];
$this->telegram->sendMessage($chatId, $text, $keyboard);
```

### Обработка contact

```php
// В Router — если есть contact в message
if (isset($message['contact'])) {
    $contact = $message['contact'];
    $fromId = $message['from']['id'];

    // ВАЖНО: проверяем что пользователь отправил СВОЙ контакт
    if ($contact['user_id'] !== $fromId) {
        $this->telegram->sendMessage($chatId, '❌ Отправьте свой контакт.');
        return;
    }

    $phone = $contact['phone_number'];
    // далее → StartHandler::handleContact($fromId, $phone, $chatId)
}
```

### White-list (конфиг)

```php
// config/config.php
'allowed_phones' => [
    '+491234567890',
    '491234567890',   // Telegram может слать без "+"
],
```

При проверке нормализуем номер (убираем `+`, пробелы):

```php
function normalizePhone(string $phone): string {
    return preg_replace('/[^0-9]/', '', $phone);
}
```

### Middleware проверки авторизации

```php
class AuthMiddleware {
    public function check(int $telegramId): bool {
        $stmt = $this->db->prepare(
            'SELECT id FROM users WHERE telegram_id = ? AND is_authorized = 1'
        );
        $stmt->execute([$telegramId]);
        return $stmt->fetch() !== false;
    }
}
```

Вызывается в Router **перед** каждым handler (кроме /start и contact):

```php
if ($command !== '/start' && !isset($message['contact'])) {
    if (!$this->auth->check($fromId)) {
        $this->telegram->sendMessage($chatId, '⛔ Сначала авторизуйтесь: /start');
        return;
    }
}
```

### Почему нельзя ввести номер вручную

Telegram отправляет объект `contact` только через кнопку `request_contact`. Если пользователь просто напишет номер текстом — это будет обычное `text` сообщение, у которого нет поля `contact`. Бот **игнорирует текст** от неавторизованных — работает только contact.

---

## 4. Обработка сообщений (парсинг)

### Формат ввода

```
[LEVEL] [HOURS] [DATE?]
```

Примеры:
- `A 1.5` → уровень A, 1.5 часа, дата = сегодня
- `B 1` → уровень B, 1 час, дата = сегодня
- `C 0.5 2026-03-15` → уровень C, 0.5 часа, 15 марта

### Regex для парсинга

```php
$pattern = '/^([ABC])\s+(0\.5|1|1\.5)(?:\s+(\d{4}-\d{2}-\d{2}))?$/i';

if (preg_match($pattern, trim($text), $matches)) {
    $level = strtoupper($matches[1]);
    $hours = (float) $matches[2];
    $date  = $matches[3] ?? date('Y-m-d');
}
```

### Валидация

1. Level ∈ {A, B, C}
2. Hours ∈ {0.5, 1.0, 1.5}
3. Дата — валидная (`checkdate`) и не в далёком будущем
4. Дата не раньше, чем 30 дней назад (защита от опечаток)

### Обработка ошибок

```
"X 2"       → "❌ Неверный уровень. Допустимо: A, B, C"
"A 3"       → "❌ Неверная длительность. Допустимо: 0.5, 1, 1.5"
"A 1 abc"   → "❌ Неверный формат даты. Используйте YYYY-MM-DD"
"hello"     → "❓ Не понимаю. Отправьте урок: A 1.5 или команду /help"
```

---

## 5. Логика расчёта зарплаты

### Периоды

| Период | Даты |
|--------|------|
| Первая половина | 1–15 числа текущего месяца |
| Вторая половина | 16–последний день месяца |

### Определение текущего периода

```php
$day = (int) date('d');
$year = date('Y');
$month = date('m');

if ($day <= 15) {
    $from = "$year-$month-01";
    $to   = "$year-$month-15";
} else {
    $from = "$year-$month-16";
    $to   = date('Y-m-t'); // последний день месяца
}
```

### SQL-запрос

```sql
SELECT level, SUM(hours) as total_hours, COUNT(*) as lesson_count
FROM lessons
WHERE user_id = ?
  AND date BETWEEN ? AND ?
GROUP BY level
ORDER BY level;
```

### Расчёт суммы

```php
$rate = 25.0; // евро за час (из конфига)
$totalHours = 0;
$lines = [];

foreach ($rows as $row) {
    $sum = $row['total_hours'] * $rate;
    $totalHours += $row['total_hours'];
    $lines[] = "  {$row['level']}: {$row['total_hours']}ч × {$rate}€ = {$sum}€"
             . " ({$row['lesson_count']} уроков)";
}

$totalSum = $totalHours * $rate;
```

### Пример ответа бота

```
💰 Зарплата за 01.03 – 15.03.2026:

  A: 6ч × 25€ = 150€ (4 урока)
  B: 3ч × 25€ = 75€ (3 урока)

Итого: 9ч = 225€
```

---

## 6. Команды и handlers

### /start

1. Проверить, авторизован ли пользователь
2. Если да → «Вы уже авторизованы. Отправляйте уроки!»
3. Если нет → показать кнопку «Отправить номер»

### /salary [период]

- `/salary` → текущий период
- `/salary 1-15` → первая половина текущего месяца
- `/salary 16-31` → вторая половина

### /stats

Общая статистика:
- Всего уроков / часов за всё время
- По уровням
- За текущий месяц

### /undo

Удаляет последний добавленный урок. Показывает что было удалено:
```
🗑 Удалён урок: A 1.5ч (2026-03-15)
```

---

## 7. Работа с Telegram API

### Webhook endpoint

`https://your-domain.com/index.php` (или просто `/` если настроен nginx)

### Установка webhook

```bash
curl "https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://your-domain.com/index.php"
```

Или через скрипт `scripts/set_webhook.php`.

### Отправка сообщений

```php
public function sendMessage(int $chatId, string $text, ?array $keyboard = null): void
{
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];

    if ($keyboard) {
        $params['reply_markup'] = json_encode($keyboard);
    }

    $this->request('sendMessage', $params);
}

private function request(string $method, array $params): array
{
    $ch = curl_init("https://api.telegram.org/bot{$this->token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?: [];
}
```

---

## 8. План разработки (пошагово)

### MVP (день 1)

1. ✅ Создать структуру папок
2. ✅ Настроить config.php
3. ✅ Реализовать Database.php + миграция
4. ✅ Реализовать TelegramApi.php
5. ✅ index.php — принять webhook, вернуть 200
6. ✅ /start + авторизация через contact
7. ✅ Парсинг "A 1.5" → запись в БД

### Фич 2 (день 2)

8. /salary — расчёт за текущий период
9. /salary 1-15 / 16-31
10. /stats
11. /undo

### Деплой (день 3)

12. VPS + nginx + HTTPS
13. Установить webhook
14. Проверить авторизацию на проде

---

## 9. Деплой

### Требования на VPS

- PHP 8.1+ с расширениями: `pdo_sqlite`, `curl`, `mbstring`
- nginx
- certbot (HTTPS)

### Nginx конфигурация

```nginx
server {
    listen 443 ssl;
    server_name bot.yourdomain.com;

    ssl_certificate /etc/letsencrypt/live/bot.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/bot.yourdomain.com/privkey.pem;

    root /var/www/telegram-bot/public;
    index index.php;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Запрещаем доступ к sqlite и конфигам
    location ~* \.(sqlite|env|md)$ {
        deny all;
    }
}
```

### Установка webhook

```bash
php scripts/set_webhook.php
```

### Права на файлы

```bash
chown -R www-data:www-data /var/www/telegram-bot/database/
chmod 750 /var/www/telegram-bot/database/
chmod 640 /var/www/telegram-bot/database/bot.sqlite
```

---

## 10. Edge cases

| Сценарий | Поведение |
|----------|-----------|
| Неавторизованный отправляет текст | «⛔ Сначала авторизуйтесь: /start» |
| Отправляет чужой контакт | «❌ Отправьте свой контакт» (проверяем `contact.user_id === from.id`) |
| Номер не в white-list | «❌ Ваш номер не разрешён» |
| Неверный формат | «❓ Не понимаю. Пример: A 1.5» |
| Двойная авторизация | «Вы уже авторизованы!» |
| /undo без уроков | «Нечего отменять» |
| Дата в будущем (>7 дней) | «❌ Дата слишком далеко в будущем» |
| Дата старше 30 дней | «❌ Дата слишком далеко в прошлом» |
| Timezone | Используем `date_default_timezone_set('Europe/Berlin')` — вы в Германии? |
| Дубли | Проверяем: тот же user + date + level + hours за последние 60 секунд |

---

## 11. Идеи для расширения

- **Разные ставки по уровням** — добавить таблицу `rates(level, rate)` или поле в config
- **Экспорт в Excel/CSV** — команда `/export`
- **Inline-кнопки** — быстрый ввод уровня и часов через кнопки
- **Напоминания** — если не было уроков > 3 дней, бот спросит «Всё ок?»
- **Редактирование** — `/edit 5 B 1.0` — изменить урок #5
- **Мультивалюта** — хранить currency в конфиге
- **График** — отправка изображения-графика через Chart API
- **Backup** — автоматический бэкап SQLite в Telegram (отправка файла самому себе)
