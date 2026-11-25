# GitHub App · 0.4.0

## Подключение

1. Создайте GitHub App в настройках владельца/организации GitHub. App ID — числовой идентификатор приложения (не client ID). Выберите **Only select repositories** при установке; режим All repositories намеренно отклоняется. Используйте `https://github.com/apps/APP-SLUG/installations/new`. Не нужен OAuth user access token.
2. Права репозитория: Metadata (read), Issues (read), Pull requests (read), Checks (read), Commit statuses (read). Подписки: Issues, Pull request, Check run, Check suite, Status, Repository (если доступно). Installation и Installation repositories доставляются GitHub App автоматически. Push необязателен (потребовал бы дополнительное право Contents).
3. Webhook URL: `https://workspace.example.com/?p=github.webhook`, content type `application/json`, SSL verification включена. Сгенерируйте случайный секрет `openssl rand -hex 32`. На reverse proxy разрешите POST этого маршрута без входа в Workspace; передавайте тело без преобразований, X-Hub-Signature-256, X-GitHub-Delivery и X-GitHub-Event. Лимит входного тела 2 МиБ, `post_max_size` и лимит прокси не ниже 2 МиБ, память PHP не менее 128 МиБ. Большие доставки отклоняются; ручная сверка восстанавливает наблюдаемое состояние. HTTPS обязателен для живого стенда.
4. Сгенерируйте приватный RSA-ключ GitHub App. Храните файл за пределами docroot и репозитория, режим 0600, доступ только сервисному пользователю CLI. Webhook secret передаётся веб-процессу; приватный ключ нужен только CLI worker и оператору. Передайте одинаковые параметры БД в CLI/HTTP, используйте менеджер секретов/защищённый environment-файл.

```ini
WORKSPACE_GITHUB_APP_ID=123456
WORKSPACE_GITHUB_PRIVATE_KEY_FILE=/srv/secrets/github-app.pem
WORKSPACE_GITHUB_WEBHOOK_SECRET=REPLACE_WITH_RANDOM_SECRET
```

```bash
php bin/workspace github:check
# ID проекта из Workspace, installation ID из страницы установки App в GitHub,
# repository ID из GitHub API (например gh api repos/OWNER/REPO --jq .id).
php bin/workspace github:bind 12 345678 987654321
php bin/workspace github:work 10
```

CLI выполняется доверенным системным оператором. Перед привязкой он проверяет согласие владельца целевого проекта/организации и право GitHub на репозиторий. Идентификатор установки из браузера не является доказательством права. GitHub App подтверждает собственную установку, выбранный режим, отсутствие suspend, выданные read permissions, scope токена и список репозиториев. Один проект — один repository ID; один repository ID — один проект Workspace. Это осознанное ограничение изоляции 0.4.0. Смена самого repository ID не поддерживается (необходим перенос связей); повторная привязка того же ID после установки/разблокировки поддерживается, старые снимки удаляются. PAT-адаптер из 0.1.x независим.

В карточке задачи менеджер связывает Issue или PR по номеру. Связи OSS предыдущей версии не импортируются автоматически; это отдельная проверяемая привязка. В разделе проекта GitHub App видны снимки и очередь. PR показывает head SHA, check runs и combined commit status; при отсутствии checks не считать CI успешным. Checks запрашиваются по текущему SHA, до 1000 результатов (10 страниц), неполный набор явно отмечен. Это наблюдение, не gate релиза и не автоматическое закрытие задачи. Заголовки и check names экранируются, тела issue/PR, комментарии и произвольные webhook payload не сохраняются. Внешние OSS contributors/клиенты не видят внутренние снимки GitHub.

## Worker и сверка

Запускайте worker регулярно, например отдельным systemd timer/cron каждую минуту. Пример (путь и сервисного пользователя замените своими):

```cron
* * * * * cd /srv/workspace && /usr/bin/php bin/workspace github:work 10
*/10 * * * * cd /srv/workspace && /usr/bin/php bin/workspace github:sync
```

Либо запускайте worker непрерывно с ограничением числа процессов. Обработчик webhook проверяет HMAC raw bytes, сохраняет идентификатор доставки и ставит задания в транзакции; ответ 202 после commit. События не меняют данные задач напрямую: worker читает актуальный API, поэтому повтор и перестановка событий не перезаписывают снимок старым webhook. Дубликат delivery ID с другим хешем отклоняется. События checks/status запускают сверку всех привязок репозитория, issue/PR — соответствующих связей. Pending-задания объединяются; событие при running создаёт дополнительное задание. Аренда 10 минут, уникальный claim, один выполняющийся job на репозиторий, автоматическое восстановление после истечения аренды, максимум 8 попыток, задержка с jitter и учёт rate limit. Сеть работает вне транзакции. 404/422 конкретного объекта завершают job с ошибкой; 404 при проверке самой установки/репозитория деактивирует подключение. 401/403 без rate-limit hint очищают недоступные снимки, ошибки видны в очереди. При удалении/приостановке установки или исключении репозитория webhook немедленно аннулирует очередь и снимки, даже если worker выполняет запрос. После восстановления оператор повторяет `github:bind`.

Ручная сверка (в UI — MFA, manager/operator/owner; CLI — доверенный оператор):

```bash
php bin/workspace github:sync 12
php bin/workspace github:work 100
```

Команда sync ставит новые задания, включая после исчерпания попыток; CLI work завершится с ненулевым статусом при появлении dead job. Пропущенный webhook восстанавливается периодической/ручной сверкой. При необходимости снизьте частоту сверок или размер batch с учётом rate limits; GitHub выдаёт токены на срок до часа, здесь они остаются только в памяти одного задания и не сохраняются. Для ротации RSA-ключа установите новый файл/переменную и перезапустите worker; секрет webhook смените согласованно на двух сторонах, затем выполните сверку. Журнал доставки и история jobs сохраняются в БД; контролируйте рост, резервируйте и не удаляйте автоматически идентификаторы доставок. В интерфейсе отображаются последние 50 заданий. Снимок старше 15 минут помечается устаревшим, ошибки не скрываются. Лимит 100 связей задач на репозиторий.

## Приёмка

Проверьте установку с выбранным приватным тестовым репозиторием, запрет привязки второго проекта/организации, работу токена и issue/PR/checks. Измените head SHA, выполните webhook с корректной и неверной подписью, повторную доставку, пропустите одну доставку и запустите сверку. Остановите worker после claim и убедитесь в восстановлении после аренды. Исключите репозиторий из установки, проверьте очистку снимков и отмену очереди, затем установите повторно. Проверьте локальный `php tests/github_unit.php`, `php tests/github_db.php`, `python3 tests/github_schema.py`, `python3 tests/github_e2e.py`; PostgreSQL — `php tests/github_db.php --postgres` с пустой тестовой БД. Без зарегистрированной GitHub App/ключа и выбранного репозитория фактическая внешняя приёмка невозможна.

Документация GitHub: [installation tokens](https://docs.github.com/en/apps/creating-github-apps/authenticating-with-a-github-app/generating-an-installation-access-token-for-a-github-app), [проверка webhook](https://docs.github.com/en/webhooks/using-webhooks/validating-webhook-deliveries), [Checks REST](https://docs.github.com/en/rest/checks/runs).

С 0.4.1 для приёмки релиза добавьте Contents: Read-only и подтвердите изменение прав в установке. Старые задания используют прежние разрешения. См. [RELEASES.md](RELEASES.md).
