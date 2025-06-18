<?php
require __DIR__ . '/../vendor/autoload.php';

use MongoLitePlus\FeatureDatabase;

// Setup feature-based databases
$featureDB = new FeatureDatabase(__DIR__ . '/data');

// User feature
$userDB = $featureDB->getDatabase('user');
$users = $userDB->users;

// Marketplace feature
$marketDB = $featureDB->getDatabase('marketplace');
$products = $marketDB->products;

// Insert data
$userId = $users->insert([
    'name' => 'Sarah Johnson',
    'email' => 'sarah@example.com',
    'balance' => 1000.00
]);

$productId = $products->insert([
    'name' => 'Wireless Headphones',
    'price' => 99.99,
    'stock' => 50
]);

echo "User created: $userId\n";
echo "Product created: $productId\n";

// Create index
$products->createIndex('price');
$products->createIndex('name');

echo "Product indexes: " . implode(', ', $products->listIndexes()) . "\n";

// Backup user database
$backupFile = $featureDB->backupFeature('user', __DIR__ . '/backups');
echo "User DB backup: " . basename($backupFile) . "\n";
