# Assignment 1: Cache Consistency Fix

## 1. Original Bug Reproduction Steps

1. **Prime Category Cache:** `GET /categories/1/products` (Creates `category:1:products` containing the old price).
2. **Update Product:** `PUT /products/1` with payload `{"price": 99999}`.
3. **Verify Product Cache:** `GET /products/1` (Cache miss -> Fetches from DB -> Returns new price `99999`).
4. **Observe Stale Cache:** `GET /categories/1/products` (Cache hit -> Returns stale file cache -> Returns old price).

## 2. Root Cause

The original `ProductController::actionUpdate()` explicitly deleted the individual product cache key (`product:{id}`) but completely ignored the category list cache (`category:{category_id}:products`). Consequently, the category endpoint continued to serve stale data directly from the file cache.

## 3. CacheService Design and Responsibilities

To fix this, a centralized `CacheService` was introduced.

- **Responsibility:** It acts as the single source of truth for cache key generation (`getProductKey`, `getCategoryProductsKey`) and cache invalidation logic.
- **Implementation:** Controllers now delegate invalidation to `CacheService::invalidateProduct(Product $product, $oldCategoryId)`. This removes hardcoded cache keys from the controllers and ensures all related cache entries are cleared simultaneously.

## 4. Handling Product Category Changes

When a product's category is updated (e.g., moved from Category A to Category B), both category lists become stale.

- The `ProductController` captures the old category ID using `$product->getOldAttribute('category_id')` _before_ calling `$product->save()`.
- It passes this old ID to `CacheService::invalidateProduct()`.
- The service invalidates the product key, the _new_ category key, and (if different) the _old_ category key, ensuring the product correctly disappears from the old list and appears in the new one.

## 5. Regression Test and Result

A focused, standalone PHP regression test (`tests/test_cache_bug.php`) was added. It bootstraps the Yii application, simulates the controller actions, and asserts the cache state.

- **Result:** The test successfully proves that updating a product now correctly invalidates both `product:{id}` and `category:{category_id}:products`. The test passes against the current implementation.

## 6. Rejected Alternatives and Why

- **ActiveRecord Behaviors/Events (`afterSave`):** Rejected because it violates the Single Responsibility Principle. Models should represent data and business rules, not application-level caching strategies. It also obscures _when_ cache invalidation happens.
- **Cache Tags (`TagDependency`):** Rejected because the assignment explicitly required using the existing `FileCache`. While `FileCache` supports dependencies, `TagDependency` is complex to set up correctly without a central registry (like Redis/Memcached). Explicit key deletion is simpler, more predictable, and fits the "smallest correct" requirement.

## 7. Response to the 24-hour TTL Suggestion

Adding a 24-hour TTL (Time-To-Live) is a band-aid, not a fix. It guarantees that users will see stale data for up to 24 hours after an update. In an e-commerce or booking context, showing an incorrect price or availability for 24 hours is unacceptable. Explicit cache invalidation upon data mutation is the only way to ensure strong consistency.

## 8. What I Deliberately Did Not Do

- Did not introduce Redis, Memcached, or third-party caching libraries.
- Did not build a generic, over-engineered caching framework.
- Did not push cache logic into the database models.
- Did not modify API behavior unrelated to caching.

## 9. Verification Performed

- **Docker Environment:** Created a minimal Docker setup (`Dockerfile`, `docker-compose.yml`) to run PHP 8.1, MySQL 8.0, and Composer locally.
- **Composer Fix:** Added `asset-packagist.org` to `composer.json` to resolve Yii2 frontend asset dependencies and generated `composer.lock`.
- **Runtime Verification:** Successfully started the Yii server, ran migrations, and verified the API endpoints and cache invalidation flow manually via `curl` and automatically via the regression test.
