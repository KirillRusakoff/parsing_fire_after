<?php
// Устанавливаем кодировку и параметры ошибок
header('Content-type: text/html; charset=utf-8');
setlocale(LC_ALL, 'ru_RU.UTF-8');
ini_set('error_reporting', E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

file_put_contents('/var/www/html/after-parser/log.txt', date('Y-m-d H:i:s') . " Script started\n", FILE_APPEND);

// Подключение к базе данных OpenCart
try {
    $db_opencart = new PDO('mysql:host=194.67.105.129;dbname=admin_dimspb;charset=utf8', 'admin_dimspb', 'b9LTly6kn2');
    $db_opencart->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo "Ошибка подключения к базе данных OpenCart: " . $e->getMessage();
    exit;
}

// Подключение к базе данных data_db (сторонняя база данных)
try {
    $db_data = new PDO('mysql:host=80.78.243.144;dbname=data_db;charset=utf8', 'parser_user', 'parser_pass');
    $db_data->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Ошибка подключения к базе данных data_db: " . $e->getMessage());
}

// Массив для категорий и таблиц
$categories = [
    'data' => 271,     // ID категории "Печи-камины"
    'data2' => 283,    // ID категории "Каминные топки"
    'data3' => 273,    // ID категории "Банные печи"
    'data4' => 286,    // ID категории "ТТ котлы"
    'data5' => 279     // ID категории "Прочее"
];

// Функция для добавления или обновления товаров в OpenCart
function addOrUpdateProductsInOpenCart($db_data, $db_opencart, $sourceTable, $categoryId) {
    // Запрашиваем данные товаров из указанной таблицы в data_db
    $products = $db_data->query("SELECT title, status, image, price, description, features FROM {$sourceTable}");

    while ($product = $products->fetch(PDO::FETCH_ASSOC)) {
        // Пропускаем товары со статусом "снят с произв-ва"
        if (strpos(mb_strtolower(trim($product['status'])), 'cнят с произв-ва') !== false) {
            continue;
        }

        // Проверяем, существует ли товар с таким названием в базе OpenCart
        $stmt = $db_opencart->prepare("SELECT product_id FROM oc_product_description WHERE name = :name");
        $stmt->execute(['name' => $product['title']]);
        $existingProduct = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingProduct) {
            // Если товар уже существует, обновляем его данные
            $product_id = $existingProduct['product_id'];

            $stmt = $db_opencart->prepare("UPDATE oc_product SET image = :image, date_modified = NOW() WHERE product_id = :product_id");
            $stmt->execute([
                'image' => htmlspecialchars($product['image']),
                'product_id' => $product_id
            ]);

            $stmt = $db_opencart->prepare("INSERT INTO oc_product_image (product_id, image, sort_order) VALUES (:product_id, :image, 0)");
            $stmt->execute([
                'product_id' => $product_id,
                'image' => htmlspecialchars($product['image'])
            ]);

            $stmt = $db_opencart->prepare("UPDATE oc_product SET price = :price, date_modified = NOW() WHERE product_id = :product_id");
            $stmt->execute([
                'price' => htmlspecialchars($product['price']),
                'product_id' => $product_id
            ]);

            $stmt = $db_opencart->prepare("UPDATE oc_product_description SET description = :description WHERE product_id = :product_id");
            $stmt->execute([
                'description' => nl2br(htmlspecialchars($product['description'])),
                'product_id' => $product_id
            ]);
        } else {
            // Если товара нет, вставляем новый товар
            $product_data = [
                'model' => htmlspecialchars($product['title']),
                'sku' => '',
                'quantity' => 100,
                'stock_status_id' => 7,
                'image' => htmlspecialchars($product['image']),
                'price' => isset($product['price']) ? htmlspecialchars($product['price']) : '0.0000',
                'status' => 1,
                'date_added' => date('Y-m-d H:i:s'),
                'date_modified' => date('Y-m-d H:i:s')
            ];

            $stmt = $db_opencart->prepare("INSERT INTO oc_product (`model`, `sku`, `quantity`, `stock_status_id`, `image`, `price`, `status`, `date_added`, `date_modified`) 
                                            VALUES (:model, :sku, :quantity, :stock_status_id, :image, :price, :status, :date_added, :date_modified)");
            $stmt->execute($product_data);
            $product_id = $db_opencart->lastInsertId();

            $product_description_data = [
                'product_id' => $product_id,
                'language_id' => 1,
                'name' => htmlspecialchars($product['title']),
                'description' => nl2br(htmlspecialchars($product['description'])),
                'meta_description' => 'Описание для SEO',
                'meta_keyword' => 'ключевые, слова, для, SEO'
            ];

            $stmt = $db_opencart->prepare("INSERT INTO oc_product_description (`product_id`, `language_id`, `name`, `description`, `meta_description`, `meta_keyword`) 
                                            VALUES (:product_id, :language_id, :name, :description, :meta_description, :meta_keyword)");
            $stmt->execute($product_description_data);

            $stmt = $db_opencart->prepare("INSERT INTO oc_product_to_category (`product_id`, `category_id`) VALUES (:product_id, :category_id)");
            $stmt->execute(['product_id' => $product_id, 'category_id' => $categoryId]);

            $stmt = $db_opencart->prepare("INSERT INTO oc_product_image (product_id, image, sort_order) VALUES (:product_id, :image, 0)");
            $stmt->execute([
                'product_id' => $product_id,
                'image' => htmlspecialchars($product['image'])
            ]);

            $seo_url = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $product['title']));
            $stmt = $db_opencart->prepare("INSERT INTO oc_url_alias (`query`, `keyword`) VALUES (:query, :keyword)");
            $stmt->execute(['query' => 'product_id=' . $product_id, 'keyword' => $seo_url]);
        }
    }
}

// Запуск добавления или обновления товаров для каждой категории
foreach ($categories as $table => $categoryId) {
    addOrUpdateProductsInOpenCart($db_data, $db_opencart, $table, $categoryId);
}

echo "Товары успешно добавлены или обновлены в OpenCart!";
