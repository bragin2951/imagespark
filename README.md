# imagespark
Some code examples for ImageSaprk

Папка Chat - самописный чат. Работает с помощью широковещания, очереди в Redis и Laravel Echo Server.

Папка Clickhouse - модель для ClickHouse по примеру Eloquent. Есть еще Builder, QueryBuilder и некоторые отношения.
В качестве транспорта выступает <a href="https://github.com/smi2/phpClickHouse">smi2/phpClickHouse</a>.
В будущем лучше переделать под поддержку <a href="https://github.com/the-tinderbox/ClickhouseBuilder">the-tinderbox/ClickhouseBuilder</a>.

Папка Payment - класс для работы с Яндекс.Кассой.

Папка Rules - Правило для валидации количества медиа для разных типов постов.
