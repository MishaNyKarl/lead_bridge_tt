# Lead Bridge

TikTok Instant Forms → Google Sheets → очередь PHP → Binom v1/v2 → Lemonad. Обратный путь: Lemonad → мост → Sheets → TikTok Signal postback.

Панель: https://bridge.naturalsolution.help/. Доступ выдаёт администратор. Рабочие настройки, секреты и лиды в репозиторий не входят.

## Возможности

Баер видит только свои трекеры, партнёрки, связки и лиды. Администратор создаёт аккаунты, видит все записи и фильтрует журнал по пользователю. Один публичный action.php, настройки в браузере, зашифрованные данные SQLite вне web-root. Руководство доступно по кнопке «?».

Клик Binom подтверждается до вызова ПП. Заявки дедуплицируются по баеру и TikTok Lead ID, настройки сохраняются в неизменяемом snapshot. Неопределённые сетевые результаты требуют сверки, а не слепого повтора.

UI-даты: `18:12:21 18.09.2026`, UTC. Три состояния сортировки: возрастание → убывание → исходный порядок. Журнал сортируется до пагинации. Иконки Lucide и подсказки доступны мышью и клавиатурой.

## Подключение баера

1. **Трекеры.** v1: API key из Settings → API. v2: API_KEY из Settings → Click API. Click URL — полный HTTPS URL без параметров; в v1 сохраняйте настоящее имя PHP-файла, в v2 обычно `/click`.
2. **Партнёрки.** Webmaster token из профиля Lemonad. Для всех связок аккаунта используйте одну запись партнёрки.
3. Скопируйте её URL постбэка в Lemonad → профиль → Global postback and API. Включите `new`, `approved`, `rejected`, `trash`, `paid`. Сохраните отдельный постбэк ПП → Binom: мост сам не регистрирует конверсии в трекере.
4. **Связки.** Выберите трекер/партнёрку, значение `key` из ссылки кампании и API `offerId` Lemonad. Проверьте точные Parameter в Traffic Source и активируйте связку. Номер Token используется для диагностики, отправку определяет Parameter.
5. Создайте отдельную Google-таблицу. Обязательны `Lead ID` (или `TikTok Lead ID`) и `Phone`. Опциональны `Name`, `Campaign ID`, `Ad Group ID`, `Ad ID`, `Advertiser ID`, `Form ID`. ID и телефон должны поступать текстом, без округления.
6. Установите Apps Script из панели, сохраните, обновите таблицу. JSON вставляется в **Lead Bridge → Настроить**, не в код. Выполните **Подготовить и проверить**.
7. Подключите TikTok Lead export. Удалите старые отправляющие триггеры, включая триггеры другого владельца. Включите один минутный `bridgeTick`.
8. Signal postback: Lead status → `TikTok Lead Status`, TikTok lead ID → исходный TikTok ID. Настройте события в Events Manager. Binom Click ID не является TikTok Click ID.

После обновления Apps Script выполните «Подготовить и проверить». JSON и работающий триггер сохраняются. Для старых статусов нужен повтор постбэка из ПП, не повтор лида.

## Статусы и ограничения

| Значение | Значение |
|---|---|
| QUEUED | Заявка сохранена в очередь |
| SENT | ПП подтвердила приём, не апрув |
| REVIEW | Нужна сверка внешнего результата |
| RETRY | Проверить прежний Lead ID / квитанцию |
| TikTok Lead Status | Статус из постбэка ПП |
| Partner Status Updated | Время принятого изменения статуса |

CRM-статус не выводится из SENT. Поздний new не отменяет решение; paid окончателен. Для approved/rejected/trash действует порядок получения: шаблон не содержит времени события. Повтор текущего статуса ничего не меняет. Неоднозначный clickid сохраняется для проверки.

Глобальная ссылка относится к одной записи партнёрки. Две записи с одинаковыми API-реквизитами не объединяются автоматически. Поиск использует snapshot.route.partner, поэтому изменение связки не нарушает старые лиды. Посторонние лиды игнорируются. Старые per-route ссылки поддерживаются. Sheets опрашивает до 100 сохранённых квитанций на лист за запуск, включая SENT, без повторной отправки.

## Исходники и тесты

- `central/action.php`: API, очередь и панель.
- `central/accounts.php`: аккаунты и изоляция.
- `central/ui.php`: даты, сортировка, подсказки и SVG.
- `central/LeadBridge.gs`: Google Sheets.
- `central/build.py`: сборка модулей, SVG и скрипта в `central/dist/action.php`.
- `central/*test*`, `scripts/check.sh`: изолированные тесты.
- `deploy/receive-release.sh`: приёмник релиза.
- `.github/workflows/check-deploy.yml`: CI/CD.
- `central/icons/LICENSE`: лицензия Lucide.

Нужны Linux, PHP 8.3 с curl, PDO SQLite, sodium; Python 3 и Node.js. CLI должен разрешать proc_open.

```bash
python3 central/build.py
bash scripts/check.sh
```

Тестируется собранный артефакт во временных каталогах: v1/v2, дедупликация, сетевые ошибки, изоляция баеров, CSRF, постбэки, точность ID, Sheets и сортировка. Внешние ПП/трекеры не вызываются. dist не коммитится.

## Сервер

Web-root `/var/www/lead-bridge/`, исполняемый файл action.php. Приватный каталог `/var/lib/lead-bridge/`; bridge.sqlite и master.key нужны вместе для восстановления. Отдельный FPM-пул leadbridge, сокет `/run/php/leadbridge.sock`. Worker: lead-bridge.service, CLI `action.php worker`. Бэкапы: lead-bridge-backup.service / .timer, `/var/backups/lead-bridge`. Health: `GET /?health=1`.

Nginx: `/etc/nginx/sites-available/lead-bridge`. privacy.html обслуживается отдельным location и не меняется при релизе. Для новой установки подготовьте каталог и права, выполните CLI init с BRIDGE_DATA, BRIDGE_URL, BRIDGE_ADMIN_PASSWORD через защищённое окружение, настройте HTTPS, FPM и systemd. Приёмник релиза рассчитан на подготовленный сервер.

## CI/CD

PR запускает сборку и тесты. Push main после проверок деплоится в environment production. Ручной workflow доступен для main. Внешние PR не получают секреты.

Секреты production: DEPLOY_HOST, проверенный DEPLOY_KNOWN_HOSTS и отдельный DEPLOY_SSH_KEY пользователя leadbridge-deploy. Environment ограничен веткой main. SSH-ключ имеет forced command `sudo -n /usr/local/sbin/lead-bridge-deploy`; forwarding, PTY и произвольные команды запрещены. Sudo разрешает только root-owned приёмник. В `/etc/lead-bridge-health-url` хранится публичный HTTPS-адрес панели.

Деплой получает только PHP по stdin, проверяет синтаксис, делает бэкап, ставит очередь на паузу и ждёт dispatch.lock. Затем атомарно заменяет файл, перезагружает FPM, запускает worker и проверяет SHA256/health. При ошибке возвращается предыдущий PHP. База автоматически не откатывается; миграции должны быть добавочными и совместимыми. Сам приёмник обновляется отдельно администратором.

Скачиваемый/копируемый Apps Script обновляется вместе с сервером, существующие таблицы обновляются вручную. Деплой не меняет настройки рекламы, ПП и TikTok.

## Источники

[Binom v1](https://docs.binom.org/click-api.php), [Binom v2](https://docs.binom.org/click-api-v2.php), [Lemonad](https://docs.limonad.com/doc/en-postback), [TikTok Google Sheets](https://ads.tiktok.com/resources/help/article/how-to-set-up-one-click-crm-integration-for-google-sheets?lang=en), [Lucide](https://lucide.dev/).
