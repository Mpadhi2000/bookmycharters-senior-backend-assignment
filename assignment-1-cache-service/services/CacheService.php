<?php

declare(strict_types=1);

namespace app\services;

use Yii;
use app\models\Product;

/**
 * Centralized cache management service.
 */
class CacheService
{
    /**
     * Generates the cache key for a single product.
     */
    public static function getProductKey(int $id): string
    {
        return 'product:' . $id;
    }

    /**
     * Generates the cache key for a category's product list.
     */
    public static function getCategoryProductsKey(int $categoryId): string
    {
        return 'category:' . $categoryId . ':products';
    }

    /**
     * Invalidates all cache entries related to a product.
     *
     * @param Product $product The product being updated/deleted.
     * @param mixed $oldCategoryId The category ID before the update, if it changed.
     */
    public static function invalidateProduct(Product $product, $oldCategoryId = null): void
    {
        $cache = Yii::$app->cache;

        // 1. Invalidate the product itself
        $cache->delete(self::getProductKey($product->id));

        // 2. Invalidate the current category list
        $cache->delete(self::getCategoryProductsKey($product->category_id));

        // 3. If the category changed, invalidate the old category list too
        if ($oldCategoryId !== null && $oldCategoryId !== $product->category_id) {
            $cache->delete(self::getCategoryProductsKey((int)$oldCategoryId));
        }
    }
}
