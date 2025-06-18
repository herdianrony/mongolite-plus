<?php
require __DIR__ . '/../vendor/autoload.php';

use MongoLitePlus\Client;

// Setup database
$client = new Client(__DIR__ . '/data');
$db = $client->testdb;
$users = $db->users;

// Insert user
$userId = $users->insert([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'age' => 30,
    'premium' => true,
    'interests' => ['coding', 'reading', 'hiking']
]);

echo "Inserted User ID: $userId\n";

// Find user
$user = $users->findOne(['email' => 'john@example.com']);
echo "User found: {$user['name']} (Age: {$user['age']})\n";

// Update user
$users->update(
    ['_id' => $userId],
    ['age' => 31, 'premium' => false]
);

// Find updated user
$updatedUser = $users->findOne(['_id' => $userId]);
echo "Updated age: {$updatedUser['age']}, Premium: " . ($updatedUser['premium'] ? 'Yes' : 'No') . "\n";

// Query with conditions
$adults = $users->find([
    'age' => ['$gte' => 18],
    'premium' => false
]);

echo "Non-premium adults:\n";
foreach ($adults as $user) {
    echo "- {$user['name']} ({$user['email']})\n";
}

// Delete user
$deleted = $users->remove(['_id' => $userId]);
echo "Deleted $deleted user\n";

// Insert many
$newUsers = $users->insertMany([
    ['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 25],
    ['name' => 'Bob', 'email' => 'bob@example.com', 'age' => 28],
    ['name' => 'Charlie', 'email' => 'charlie@example.com', 'age' => 32]
]);

echo "Inserted " . count($newUsers) . " users\n";
echo "Total users: " . $users->count() . "\n";
