<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

// Mock basic server variables required by yii\web\Application
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
$_SERVER['SCRIPT_NAME'] = '/test_cache_bug.php';

$config = require __DIR__ . '/../config/web.php';
$app = new yii\web\Application($config);

use app\controllers\CategoryController;
use app\controllers\ProductController;
use app\models\Product;
use app\services\CacheService;

echo "Running Cache Regression Test...\n";

// 0. Setup: Reset product 1 to original state and flush cache
$product = Product::findOne(1);
$product->price = 45000;
$product->save(false);
Yii::$app->cache->flush();

// 1. Prime the category products cache
$categoryController = new CategoryController('category', $app);
$products = $categoryController->actionProducts(1);

// Verify it was cached
$categoryCacheKey = CacheService::getCategoryProductsKey(1);
if (Yii::$app->cache->get($categoryCacheKey) === false) {
    echo "FAIL: Category cache was not primed.\n";
    exit(1);
}
echo "✓ Category cache primed.\n";

// 2. Update product 1's price to 99999
Yii::$app->request->setBodyParams([
    'name' => $product->name,
    'price' => 99999,
    'category_id' => 1
]);
$productController = new ProductController('product', $app);
$productController->actionUpdate(1);
echo "✓ Product 1 updated to price 99999.\n";

// 3. Verify that product:1 and category:1:products are invalidated
$productCacheKey = CacheService::getProductKey(1);
if (Yii::$app->cache->get($productCacheKey) !== false) {
    echo "FAIL: product cache was not invalidated.\n";
    exit(1);
}
echo "✓ Product cache invalidated.\n";

if (Yii::$app->cache->get($categoryCacheKey) !== false) {
    echo "FAIL: category cache was not invalidated (Original Bug!).\n";
    exit(1);
}
echo "✓ Category cache invalidated.\n";

// 4. Fetch the category product list again
$productsAfterUpdate = $categoryController->actionProducts(1);

// 5. Assert that product 1 contains the updated price 99999
$updatedProduct = array_filter($productsAfterUpdate, fn($p) => $p['id'] === 1);
$updatedProduct = reset($updatedProduct);

if ($updatedProduct['price'] != 99999) {
    echo "FAIL: Category list returned stale price: {$updatedProduct['price']}\n";
    exit(1);
}
echo "✓ Category list returned fresh price: {$updatedProduct['price']}.\n";

echo "\nPASS: Cache invalidation works correctly. The original bug is fixed.\n";
exit(0);
