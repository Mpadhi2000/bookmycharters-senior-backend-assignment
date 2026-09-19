<?php

declare(strict_types=1);

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;
use app\models\Product;
use app\services\CacheService;

/**
 * Products API.
 *
 * Caching is wired directly into the actions using Yii::$app->cache (FileCache).
 * This is deliberately simple and is expected to evolve.
 */
class ProductController extends Controller
{
    public $enableCsrfValidation = false;

    /**
     * GET /products/{id}
     *
     * Returns the product, served from cache when a cached copy exists,
     * otherwise loaded from the database and cached for next time.
     */
    public function actionView(int $id): array
    {
        $cache = Yii::$app->cache;
        $key = CacheService::getProductKey($id);

        $data = $cache->get($key);
        if ($data !== false) {
            return $data;
        }

        $product = Product::findOne($id);
        if ($product === null) {
            throw new NotFoundHttpException('Product not found.');
        }

        $data = $product->toArray();
        $cache->set($key, $data);

        return $data;
    }

    /**
     * PUT /products/{id}
     *
     * Updates the product and refreshes its cached copy so that the next
     * GET /products/{id} reflects the change.
     */
    public function actionUpdate(int $id): array
    {
        $product = Product::findOne($id);
        if ($product === null) {
            throw new NotFoundHttpException('Product not found.');
        }

        $product->load(Yii::$app->request->getBodyParams(), '');

        $oldCategoryId = $product->getOldAttribute('category_id');

        if (!$product->save()) {
            Yii::$app->response->statusCode = 422;
            return ['errors' => $product->getErrors()];
        }

        // Refresh this product's cached copy and related category caches.
        CacheService::invalidateProduct($product, $oldCategoryId);

        return $product->toArray();
    }
}
