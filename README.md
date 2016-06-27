# phpcs-stash
Скрипт, для интеграции phpcs и atlassian stash. Скрипт проверяет pull request на соответствию кодстайлу, и комментирует рецензию найденными ошибками
Больше можно прочесть на https://habrahabr.ru/post/303348/

## Схема работы phpcs-stash
![Схема работы phpcs-stash](https://raw.githubusercontent.com/WhoTrades/phpcs-stash/master/doc/images/architecture.png)

## Результат работы phpcs-stash
Результатом работы приложение явлются комментарии в atlassian stash о найденных ошибках в стилях кода
![скриншот примера результата работы](https://raw.githubusercontent.com/WhoTrades/phpcs-stash/master/doc/images/result.png)

## Установка и настройка
0. Клонировать репозиторий
1. Запустить composer install
2. Переименовать configuration.ini-dist в configuration.ini
3. Указать в configuration.ini ссылку и логин-пароль от вашей копии atlassian stash
4. Добавить webhook в atlassian stash с указанием ссылки на index.php из phpcs-stash с аргументами
    index.php?brach=${refChange.refId}&repo=${project.key}&slug=${repository.slug}
