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
3. Указать в configuration.ini ссылку и логин-пароль от вашей копии atlassian stash, указать стандарт проверки


## Запуск
Запускать приложение можно двумя путями:
1. С помощью консоли: запускаем команду ```php app.php <branch> <slug> <repo>``` (например, для репозитория https://example.com/projects/WT/repos/sparta/browse <slug> будет равно WT, а <repo> - sparta
2. С помощью HTTP запроса: index.php?slug=<slug>&branch=<branch>&repo=<repo>

## Интеграция с pull request
Добавить webhook в atlassian stash с указанием ссылки на index.php из phpcs-stash с аргументами index.php?branch=${refChange.refId}&repo=${project.key}&slug=${repository.slug}
