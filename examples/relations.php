<?php
require __DIR__ . '/../vendor/autoload.php';

use MongoLitePlus\Client;

// Setup
$client = new Client(__DIR__ . '/data');
$db = $client->social;
$users = $db->users;
$posts = $db->posts;

// Insert users
$johnId = $users->insert(['name' => 'John', 'email' => 'john@example.com']);
$sarahId = $users->insert(['name' => 'Sarah', 'email' => 'sarah@example.com']);

// Insert posts
$post1Id = $posts->insert([
    'title' => 'First Post',
    'content' => 'Hello world!',
    'user_id' => $johnId
]);

$post2Id = $posts->insert([
    'title' => 'Second Post',
    'content' => 'Another post',
    'user_id' => $sarahId
]);

// Create relations
$users->relateTo($johnId, 'posts', $post1Id);
$users->relateTo($sarahId, 'posts', $post2Id);

// Get relations
$johnRelations = $users->getRelations($johnId);
$sarahRelations = $users->getRelations($sarahId);

echo "John's relations:\n";
foreach ($johnRelations as $rel) {
    $post = $posts->findOne(['_id' => $rel['to_id']]);
    echo "- {$post['title']} ({$rel['to_collection']})\n";
}

echo "\nSarah's relations:\n";
foreach ($sarahRelations as $rel) {
    $post = $posts->findOne(['_id' => $rel['to_id']]);
    echo "- {$post['title']} ({$rel['to_collection']})\n";
}

// Event-like update
$posts->update(
    ['_id' => $post1Id],
    ['content' => 'Updated content!']
);

// Get updated post
$updatedPost = $posts->findOne(['_id' => $post1Id]);
echo "\nUpdated post content: {$updatedPost['content']}\n";
