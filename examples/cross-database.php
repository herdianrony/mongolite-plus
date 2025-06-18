<?php
require __DIR__ . '/../vendor/autoload.php';

use MongoLitePlus\FeatureDatabase;
use MongoLitePlus\CrossDatabase;

// Setup databases
$featureDB = new FeatureDatabase(__DIR__ . '/data');
$crossDB = new CrossDatabase($featureDB);

// User database
$userDB = $featureDB->getDatabase('user');
$users = $userDB->users;

// Marketplace database
$marketDB = $featureDB->getDatabase('marketplace');
$products = $marketDB->products;

// Finance database
$financeDB = $featureDB->getDatabase('finance');
$transactions = $financeDB->transactions;

// Insert data
$sellerId = $users->insert([
    'name' => 'Mike Thompson',
    'email' => 'mike@example.com'
]);

$productId = $products->insert([
    'name' => 'Smart Watch',
    'price' => 199.99,
    'seller_id' => $sellerId
]);

$transactionId = $transactions->insert([
    'user_id' => $sellerId,
    'product_id' => $productId,
    'amount' => 199.99,
    'status' => 'completed'
]);

// Cross-database access
$transaction = $transactions->findOne(['_id' => $transactionId]);
$buyer = $crossDB->getDocument('user', 'users', $transaction['user_id']);
$product = $crossDB->getDocument('marketplace', 'products', $transaction['product_id']);

echo "Transaction Details:\n";
echo "Buyer: {$buyer['name']}\n";
echo "Product: {$product['name']}\n";
echo "Amount: {$transaction['amount']}\n";
echo "Status: {$transaction['status']}\n";

// One-to-many relations
$sellerProducts = $crossDB->getManyRelated(
    $sellerId,
    'marketplace:products:seller_id'
);

echo "\nSeller Products: " . count($sellerProducts) . "\n";
foreach ($sellerProducts as $product) {
    echo "- {$product['name']} (\${$product['price']})\n";
}
