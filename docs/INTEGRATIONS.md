# Настройка интеграций

Оператор задаёт доверенные адреса в JSON на сервере. Пользователь Workspace привязывает к проекту именованное подключение и открывает его интерфейс под своей учётной записью продукта. Опрос выполняет CLI. Открытие страницы в браузере читает сохранённый снимок из SQLite.

| Ключ | Сервис | Запрос в 0.1.0 | Признак успеха / результат |
| --- | --- | --- | --- |
| `engine` | CajeerEngine | GET `/health/ready` | `status = ready` |
| `logs` | CajeerLogs | GET `/ready` | `status = ok` |
| `forum` | CajeerEngineForum | GET `/health/ready` | `status = ready` |
| `book` | CajeerEngineBook | GET `/health/ready` | `status = ok` |
| `lite` | CajeerEngineLite | GET `/health/ready` | `status = ready` |
| `bots` | CajeerBots | GET `/api/health` | `status = ok` |
| `servers` | CajeerServers | GET `/api/v1/ready` | `status = ready` |
| `github` | GitHub | GET `https://api.github.com/repos/{owner}/{repo}` | Имя совпало с настройкой. Сохраняются имя, ветка по умолчанию, признак архива. |
| `aapanel` | aaPanel | POST `/system?action=GetSystemTotal` | Версия панели, число CPU и общий объём RAM. |

### Указать адреса

Скопируйте `config/connections.example.json` в `config/connections.local.json`. Ключи девяти типов уже известны приложению. Можно указать только нужные. Для второго экземпляра задайте уникальный ключ и поле `type`. Поле `base_url` задаёт корень API, включая префикс развёртывания, если он есть. Для Servers это корень серверного API перед `/api/v1/ready`. Поле `launch_url` предназначено для ссылки на пользовательский интерфейс.

```json
{
  "forum": {
    "base_url": "https://forum.example.org",
    "launch_url": "https://forum.example.org/forum"
  },
  "aapanel": {
    "base_url": "https://panel.example.org:7800",
    "secret_env": "WORKSPACE_AAPANEL_KEY"
  },
  "github": { "repository": "CajeerTeam/CajeerWorkspace" },
  "github_docs": {
    "type": "github",
    "label": "Репозиторий документации",
    "repository": "your-org/docs",
    "secret_env": "WORKSPACE_DOCS_TOKEN"
  }
}
```

Ключи подключения состоят из строчных латинских букв, цифр, дефиса и подчёркивания. Оператор задаёт обычный HTTPS-адрес. Для теста Cajeer на `localhost`, `127.0.0.1` или `[::1]` разрешён HTTP. У панелей с собственной цепочкой TLS установите доверенный CA для CLI PHP. Для aaPanel добавьте IP исходящих запросов Workspace в список разрешённых IP API панели. В `base_url` не передают ключи, query-параметры или URL входа с токеном. Приватные ключи выдайте процессу cron через его окружение. Процесс FPM может работать без этих ключей.

Для открытого GitHub-репозитория токен необязателен. Для частного укажите fine-grained token с доступом к выбранному репозиторию и чтением Metadata. Имя переменной для секрета начинается с `WORKSPACE_`. Для GitHub API зафиксирована версия `2026-03-10`. Для aaPanel подпись POST рассчитывается из времени запроса в секундах и ключа согласно протоколу панели. Путь взят из документации системной статистики. Совместимость с установленной версией панели проверяется при её подключении. [GitHub REST](https://docs.github.com/en/rest/repos/repos#get-a-repository) · [aaPanel API](https://www.aapanel.com/docs/api/api-list.html) · [Системная статистика aaPanel](https://www.aapanel.com/Document/api.pdf)

### Опросить и проверить результат

```sh
php bin/workspace sync
php bin/workspace sync forum
```

Пример записи cron для пути из руководства. В aaPanel Scheduled Tasks ту же команду можно запускать раз в минуту. Пропишите путь к PHP CLI вашей версии и то же окружение, что у команды ручного опроса.

```cron
* * * * * cd /www/wwwroot/cajeer-workspace && /usr/bin/php bin/workspace sync
```

Проверка ожидает HTTP 200 и JSON-объект. Для GitHub отводится до 15 с, для остальных сервисов — до 5,5 с, включая соединение. Размер ответа ограничен 128 КиБ, перенаправления запрещены. Отдельные экземпляры опрашиваются последовательно, один процесс `sync` на установку. При добавлении большого числа адресов учитывайте длительность цикла. Ошибка отдельного сервиса сохраняется в его строке, опрос остальных продолжается. Коды выхода CLI — 0 при успешном опросе настроенных сервисов, 1 при отказе хотя бы одного, 2 при ошибке запуска, конфигурации или попытке параллельного старта.

| Состояние | Когда отображается |
| --- | --- |
| Не настроено | Для типа не задан репозиторий или адрес. |
| Ожидает проверки | Адрес задан, соответствующий результат ещё не записан. Смена целевого адреса или имени репозитория сбрасывает видимость старого снимка. |
| Доступно | Сервис вернул ожидаемые поля и положительный результат. |
| Ошибка | Получен отказ, неожиданный ответ, ошибка TLS, таймаут или отсутствует ключ панели. Краткая причина сохранена без тела ответа. |
| Данные устарели | С момента записи прошло более пяти минут. В таблице видны время и результат последней попытки. |

### Основания для маршрутов Cajeer

Проверки основаны на контроллерах продуктов и таблице маршрутов предыдущего CajeerWorkspace. У Book и производных Engine одноимённый путь имеет разные значения `status`. Префикс `api` у Bots задаётся при запуске NestJS. Для Servers учитывается версия API. Привязка к продукту подтверждает готовность, о которой сообщает сам продукт. Авторизация редактора, отправка ботом и публикация остаются самостоятельными сценариями приёмки в дорожной карте.

- CajeerEngine и Forum — обработчики запросов мониторинга. [Engine](https://github.com/CajeerTeam/CajeerEngine/blob/main/core/Observability/ProbeEndpoint.php) · [Forum](https://github.com/CajeerTeam/CajeerEngineForum/blob/main/core/Observability/ProbeEndpoint.php)
- CajeerEngineBook и Lite — ветка готовности в веб-приложении. [Book](https://github.com/CajeerTeam/CajeerEngineBook/blob/main/src/App.php) · [Lite](https://github.com/CajeerTeam/CajeerEngineLite/blob/main/public/index.php)
- CajeerBots, CajeerLogs и CajeerServers — отдельные сервисные маршруты. [Bots](https://github.com/CajeerTeam/CajeerBots/blob/main/apps/api/src/modules/health/health.controller.ts) · [Logs](https://github.com/CajeerTeam/CajeerLogs/blob/main/core/Kernel/Application.php) · [Servers](https://github.com/CajeerTeam/CajeerServers/blob/main/core/Api/Controller/OperationalHealthController.php)
