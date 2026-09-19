# Product Cache Service

A small Yii2 service that exposes products and category listings over a JSON API.
To keep response times low and reduce database load, responses are cached using
Yii's `FileCache` (no Redis, no Memcached, no third-party cache).

This is the starting point for your assignment. It runs as-is. Please read the
accompanying assignment brief for what we would like you to do with it.

## Requirements

- PHP 8.0+
- MySQL 5.7+ / 8.0
- Composer

## Setup

```bash
# 1. Install dependencies
composer install

# 2. Create the database
mysql -u root -e "CREATE DATABASE bmc_cache CHARACTER SET utf8mb4;"

# 3. Point the app at your database
#    Edit config/db.php if your host / user / password differ from the defaults.

# 4. Run the migrations (schema + seed data)
php yii migrate --interactive=0

# 5. Serve it
php yii serve --docroot=web
#    (or point Nginx/Apache at the web/ directory)
```

The service now runs at `http://localhost:8080` by default.

## API

All responses are JSON.

### GET /products/{id}

Returns a single product. Served from cache when a cached copy exists, otherwise
loaded from the database and cached for subsequent requests.

```bash
curl http://localhost:8080/products/1
```

```json
{
  "id": 1,
  "name": "Airbus H125",
  "category_id": 1,
  "price": "45000.00",
  "description": "Single-engine light utility helicopter."
}
```

### GET /categories/{id}/products

Returns the products in a category. Served from cache when available, otherwise
loaded from the database and cached for subsequent requests.

```bash
curl http://localhost:8080/categories/1/products
```

### PUT /products/{id}

Updates a product. Accepts a JSON body. Returns the updated product.

```bash
curl -X PUT http://localhost:8080/products/1 \
  -H "Content-Type: application/json" \
  -d '{"name": "Airbus H125 (2024)", "price": 47000}'
```

## Notes

- Caching uses `yii\caching\FileCache`; cache files live under `runtime/cache/`.
- The database schema and seed data are created entirely by the migrations in
  `migrations/`.
- This is intentionally a small service. It is not meant to be feature-complete.
