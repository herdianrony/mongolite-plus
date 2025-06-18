<?php
require __DIR__ . '/../vendor/autoload.php';

use MongoLitePlus\Client;

// Setup
$client = new Client(__DIR__ . '/data');
$db     = $client->testdb;
$products = $db->products;
$users = $db->users;

// Tidak ada pembersihan data sama sekali
// Langsung mulai test

// Insert contoh produk
$productId = $products->insert([
    'name'     => 'Apple',
    'price'    => 25,
    'category' => 'Fruit',
    'in_stock' => true
]);
echo "Inserted Product ID: $productId\n";

// Test boolean
echo "\nProducts in stock (boolean test):\n";
foreach ($products->find(['in_stock' => 'true']) as $doc) {
    echo "- {$doc['name']} (in_stock: " . ($doc['in_stock'] ? 'true' : 'false') . ")\n";
}

// Test operator
echo "\nProducts with price > 20:\n";
foreach ($products->find(['price' => ['$gt' => 20]]) as $doc) {
    echo "- {$doc['name']}: \${$doc['price']}\n";
}

// Test update
$products->update(['_id' => $productId], ['price' => 30]);
$updated = $products->findOne(['_id' => $productId]);
echo "\nUpdated price: \${$updated['price']}\n";

// Test relasi
$userId = $users->insert(['name' => 'John', 'email' => 'john@example.com']);
$success = $products->relateTo($productId, 'users', $userId);
echo "\nRelation created: " . ($success ? 'Yes' : 'No') . "\n";

// Test relations
echo "\nProduct relations:\n";
print_r($products->getRelations($productId));

// Test remove
$doc = $products->findOne(['_id' => $productId]);
if ($doc) {
    $removed = $products->remove(['_id' => $productId]);
    echo "\nRemoved $removed product(s)\n";
} else {
    echo "\nDocument not found for removal\n";
}

// Verifikasi penghapusan
$afterRemoval = $products->findOne(['_id' => $productId]);
echo "Document after removal: " . ($afterRemoval ? 'Exists' : 'Not exists') . "\n";

echo "\nTest completed.\n";
