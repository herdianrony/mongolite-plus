<?php
require __DIR__ . '/../vendor/autoload.php';

use MongoLitePlus\FeatureDatabase;
use MongoLitePlus\CrossDatabase;

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "===== MONGOLITEPLUS INTEGRATION TEST =====\n\n";

// 1. Setup Fitur Database
$featureDB = new FeatureDatabase(__DIR__ . '/data');
$crossDB = new CrossDatabase($featureDB);

// 2. Setup Database per Fitur
$userDB = $featureDB->getDatabase('user');
$marketDB = $featureDB->getDatabase('marketplace');
$financeDB = $featureDB->getDatabase('finance');

// 3. Koleksi
$users = $userDB->users;
$products = $marketDB->products;
$transactions = $financeDB->transactions;

// Bersihkan data lama
$users->remove([]);
$products->remove([]);
$transactions->remove([]);

echo "=== SCENARIO 1: CRUD DASAR ===\n";

// Insert user
$userId = $users->insert([
    'name' => 'Alice',
    'email' => 'alice@example.com',
    'premium' => true,
    'balance' => 500.00
]);
echo "Inserted User ID: $userId\n";

// Insert product
$productId = $products->insert([
    'name' => 'Smartphone',
    'price' => 300.00,
    'stock' => 10,
    'tags' => ['electronics', 'mobile']
]);
echo "Inserted Product ID: $productId\n";

// Find user
$user = $users->findOne(['_id' => $userId]);
echo "User found: {$user['name']}\n";

// Update user
$users->update(
    ['_id' => $userId],
    ['balance' => 750.00]
);
$updatedUser = $users->findOne(['_id' => $userId]);
echo "Updated balance: {$updatedUser['balance']}\n";

// Delete product
$deleted = $products->remove(['_id' => $productId]);
echo "Deleted $deleted product\n\n";

echo "=== SCENARIO 2: OPERASI MASSA ===\n";

// Bulk insert users
$userDocs = [
    ['name' => 'Bob', 'email' => 'bob@example.com', 'premium' => false],
    ['name' => 'Charlie', 'email' => 'charlie@example.com', 'premium' => true],
    ['name' => 'Diana', 'email' => 'diana@example.com', 'premium' => true]
];
$userIds = $users->insertMany($userDocs);
echo "Inserted " . count($userIds) . " users\n";

// Bulk update
$updated = $users->update(
    ['premium' => true],
    ['bonus' => 100.00]
);
echo "Updated $updated premium users\n";

// Bulk delete
$deleted = $users->remove(['premium' => false]);
echo "Deleted $deleted non-premium users\n\n";

echo "=== SCENARIO 3: QUERY KOMPLEKS ===\n";

// Insert sample products
$products->insertMany([
    ['name' => 'Laptop', 'price' => 1200, 'stock' => 5, 'category' => 'electronics'],
    ['name' => 'T-shirt', 'price' => 25, 'stock' => 50, 'category' => 'clothing'],
    ['name' => 'Book', 'price' => 15, 'stock' => 100, 'category' => 'education']
]);

// Query dengan operator
$expensive = $products->find([
    'price' => ['$gt' => 100],
    'stock' => ['$gt' => 0]
]);
echo "Expensive products in stock: " . $expensive->count() . "\n";

// Query boolean
$premiumUsers = $users->find(['premium' => true]);
echo "Premium users: " . $premiumUsers->count() . "\n";

// Query nested (PHP filter)
$electronics = $products->find(function ($doc) {
    return ($doc['category'] ?? null) === 'electronics' && ($doc['stock'] ?? 0) > 0;
});
echo "Electronics in stock: " . $electronics->count() . "\n\n";

echo "=== SCENARIO 4: INDEKS MANUAL ===\n";

// Buat indeks
$users->createIndex('email', ['unique' => true]);
$products->createIndex('price');
$products->createIndex('category');

// List indeks
echo "User indexes: " . implode(', ', $users->listIndexes()) . "\n";
echo "Product indexes: " . implode(', ', $products->listIndexes()) . "\n";

// Test indeks unik
try {
    $users->insert(['email' => 'alice@example.com']); // Duplikat
    echo "ERROR: Duplicate email allowed\n";
} catch (Exception $e) {
    echo "Unique index works: Duplicate email blocked - " . $e->getMessage() . "\n";
}

// Hapus indeks
$products->dropIndex('category');
echo "After drop, product indexes: " . implode(', ', $products->listIndexes()) . "\n\n";

echo "=== SCENARIO 5: RELASI DOKUMEN ===\n";

// Buat produk baru
$newProductId = $products->insert([
    'name' => 'Headphones',
    'price' => 99.99,
    'stock' => 15,
    'seller_id' => $userId
]);
echo "New product ID: $newProductId\n";

// Buat relasi
$success = $users->relateTo($userId, 'products', $newProductId);
echo "Relation created: " . ($success ? "Yes" : "No") . "\n";

// Dapatkan relasi
$relations = $users->getRelations($userId);
echo "User relations: " . count($relations) . "\n";

if (count($relations) > 0) {
    $firstRel = $relations[0];
    echo "Relation: {$firstRel['from_id']} -> {$firstRel['to_id']} ({$firstRel['to_collection']})\n";

    // Dapatkan produk melalui relasi
    $relatedProduct = $products->findOne(['_id' => $firstRel['to_id']]);
    if ($relatedProduct) {
        echo "Related product: {$relatedProduct['name']}\n";
    } else {
        echo "Related product not found\n";
    }
} else {
    echo "No relations found\n";
}

echo "\n";

echo "=== SCENARIO 6: CROSS-DATABASE OPERATIONS ===\n";

// Buat transaksi
$transactionId = $transactions->insert([
    'user_id' => $userId,
    'product_id' => $newProductId,
    'amount' => 99.99,
    'status' => 'completed'
]);
echo "Transaction ID: $transactionId\n";

// Akses data lintas database
$transaction = $transactions->findOne(['_id' => $transactionId]);
if ($transaction) {
    $buyer = $crossDB->getDocument('user', 'users', $transaction['user_id']);
    $product = $crossDB->getDocument('marketplace', 'products', $transaction['product_id']);

    if ($buyer && $product) {
        echo "Transaction #$transactionId\n";
        echo "Buyer: {$buyer['name']}, Product: {$product['name']}, Amount: {$transaction['amount']}\n";
    } else {
        echo "Failed to get buyer or product data\n";
    }
} else {
    echo "Transaction not found\n";
}

// One-to-many relation (perbaikan format relationDef)
$userProducts = $crossDB->getManyRelated(
    $userId,
    'marketplace:products:seller_id'  // Format: feature:collection:field
);
echo "User products: " . count($userProducts) . "\n";

// Preloading
$crossDB->preloadDocuments([$userId], 'user', 'users');
$preloadedUser = $crossDB->getDocument('user', 'users', $userId);
if ($preloadedUser) {
    echo "Preloaded user: {$preloadedUser['name']}\n";
} else {
    echo "Preloaded user not found\n";
}

echo "\n";

echo "=== SCENARIO 7: EVENT & KONSISTENSI ===\n";

// Daftarkan listener
$crossDB->onUpdate('user', 'users', function ($id, $newData) use ($products) {
    if (isset($newData['name'])) {
        $products->update(
            ['seller_id' => $id],
            ['seller_name' => $newData['name']]
        );
        echo "[EVENT] Updated seller name in products\n";
    }
});

// Update user
$users->update(['_id' => $userId], ['name' => 'Alice Smith']);
$crossDB->handleUpdate('user', 'users', $userId, ['name' => 'Alice Smith']);

// Verifikasi
$updatedProduct = $products->findOne(['_id' => $newProductId]);
if ($updatedProduct && isset($updatedProduct['seller_name'])) {
    echo "Product seller name updated: {$updatedProduct['seller_name']}\n";
} else {
    echo "Product seller name not updated\n";
}

echo "\n";

echo "=== SCENARIO 8: BACKUP & MIGRASI ===\n";

// Buat direktori backup jika belum ada
$backupDir = __DIR__ . '/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0777, true);
}

// Backup fitur
$backupFile = $featureDB->backupFeature('marketplace', $backupDir);
if (file_exists($backupFile)) {
    echo "Backup created: " . basename($backupFile) . "\n";
} else {
    echo "Backup failed\n";
}

// Migrasi data
$migratedCount = $featureDB->migrateData(
    'marketplace',
    'finance',
    'products',
    function ($doc) {
        return ($doc['price'] ?? 0) > 50;
    }
);
echo "Migrated $migratedCount expensive products to finance database\n";

// Verifikasi
$financeProducts = $financeDB->products->find()->toArray();
echo "Finance DB products: " . count($financeProducts) . "\n\n";

echo "===== TEST COMPLETED SUCCESSFULLY =====\n";
