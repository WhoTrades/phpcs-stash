# phpcs-stash
Скрипт, для интеграции phpcs и atlassian stash. Скрипт проверяет pull request на соответствию кодстайлу, и отписывается в комментариях пулл реквеста о найденных ошибках
## Установка и настройка
1. Запустить composer install
2. Переименовать configuration.ini-dist в configuration.ini
3. Указать в configuration.ini ссылку и логин-пароль от вашей копии atlassian stash
4. Добавить webhook в atlassian stash с указанием ссылки на index.php из phpcs-stash с аргументами
    index.php?brach=${refChange.refId}&repo=${project.key}&slug=${repository.slug}
