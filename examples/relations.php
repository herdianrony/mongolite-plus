<?php
require __DIR__ . '/../vendor/autoload.php';

use MongoLitePlus\Client;

// Setup
$client = new Client(__DIR__ . '/data');
$db = $client->social;
$users = $db->users;
$posts = $db->posts;

// Bersihkan data lama
$users->remove([]);
$posts->remove([]);

// Insert users
$johnId = $users->insert(['name' => 'John', 'email' => 'john@example.com']);
$sarahId = $users->insert(['name' => 'Sarah', 'email' => 'sarah@example.com']);
$mikeId = $users->insert(['name' => 'Mike', 'email' => 'mike@example.com']);
$emmaId = $users->insert(['name' => 'Emma', 'email' => 'emma@example.com']);
$davidId = $users->insert(['name' => 'David', 'email' => 'david@example.com']);

// Insert posts
$postsData = [];
for ($i = 1; $i <= 15; $i++) {
    $userId = $johnId; // John memiliki banyak post
    if ($i % 3 == 0) $userId = $sarahId;
    if ($i % 4 == 0) $userId = $mikeId;
    if ($i % 5 == 0) $userId = $emmaId;

    $postsData[] = [
        'title' => "Post $i",
        'content' => "Content for post $i",
        'user_id' => $userId,
        'created_at' => time() - rand(0, 86400 * 30) // Random timestamp dalam 30 hari
    ];
}

// Insert many posts
$postIds = $posts->insertMany($postsData);

// ==================================================
// FITUR JOIN DENGAN PAGINATION
// ==================================================

echo "=== JOIN WITH PAGINATION ===\n\n";

/**
 * Fungsi helper untuk pagination
 */
function paginate(array $items, int $page = 1, int $perPage = 5): array
{
    $total = count($items);
    $totalPages = ceil($total / $perPage);
    $offset = ($page - 1) * $perPage;

    return [
        'data' => array_slice($items, $offset, $perPage),
        'pagination' => [
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'has_more' => $page < $totalPages
        ]
    ];
}

// 1. Pagination sederhana pada hasil join (tidak efisien untuk data besar)
echo "1. Simple Pagination on Join Results:\n";

// Lakukan join untuk semua data
$allUsersWithPosts = $users->join(
    $posts,
    '_id',
    'user_id',
    'posts',
    'left'
);

// Paginasi hasil join
$page = 1;
$perPage = 3;

while (true) {
    $result = paginate($allUsersWithPosts, $page, $perPage);

    echo "\nPage {$page}/{$result['pagination']['total_pages']}:\n";
    foreach ($result['data'] as $user) {
        echo "User: {$user['name']} - Posts: " . count($user['posts'] ?? []) . "\n";
    }

    if (!$result['pagination']['has_more']) break;
    $page++;
}

// 2. Pagination efisien dengan query database langsung
echo "\n\n2. Efficient Database Pagination:\n";

$page = 1;
$perPage = 4;

while (true) {
    // Hitung skip value
    $skip = ($page - 1) * $perPage;

    // Query utama dengan pagination
    $usersPage = $users->find()
        ->sort(['name' => 1])
        ->skip($skip)
        ->limit($perPage)
        ->toArray();

    // Jika tidak ada data, keluar dari loop
    if (empty($usersPage)) break;

    // Kumpulkan ID user
    $userIds = array_column($usersPage, '_id');

    // Ambil semua post untuk user di halaman ini
    $allPosts = $posts->find(['user_id' => ['$in' => $userIds]])->toArray();

    // Buat mapping: user_id => [posts]
    $postsMap = [];
    foreach ($allPosts as $post) {
        $userId = $post['user_id'];
        if (!isset($postsMap[$userId])) {
            $postsMap[$userId] = [];
        }
        $postsMap[$userId][] = $post;
    }

    // Gabungkan data
    foreach ($usersPage as &$user) {
        $user['posts'] = $postsMap[$user['_id']] ?? [];
    }

    // Hitung total halaman
    $totalUsers = $users->count();
    $totalPages = ceil($totalUsers / $perPage);

    // Tampilkan hasil
    echo "\nPage {$page}/{$totalPages}:\n";
    foreach ($usersPage as $user) {
        $postCount = count($user['posts']);
        echo "- {$user['name']} ({$user['email']}): {$postCount} posts\n";

        // Tampilkan 2 post pertama untuk user ini
        if ($postCount > 0) {
            $samplePosts = array_slice($user['posts'], 0, 2);
            foreach ($samplePosts as $post) {
                echo "  * {$post['title']}\n";
            }
            if ($postCount > 2) {
                echo "  * ... and " . ($postCount - 2) . " more\n";
            }
        }
    }

    // Cek apakah masih ada halaman berikutnya
    if ($page >= $totalPages) break;
    $page++;
}

// 3. Pagination dengan join menggunakan teknik batch
echo "\n\n3. Batch Join Pagination:\n";

$perPage = 3;
$page = 1;
$totalUsers = $users->count();
$totalPages = ceil($totalUsers / $perPage);

while ($page <= $totalPages) {
    $skip = ($page - 1) * $perPage;

    // Ambil user untuk halaman ini
    $usersBatch = $users->find()
        ->sort(['_id' => 1])
        ->skip($skip)
        ->limit($perPage)
        ->toArray();

    $userIds = array_column($usersBatch, '_id');

    // Lakukan join batch
    $usersWithPosts = $users->join(
        $posts,
        '_id',
        'user_id',
        'posts',
        'left',
        $perPage,
        $skip
    );

    echo "\nPage {$page}/{$totalPages}:\n";
    foreach ($usersWithPosts as $user) {
        $postCount = count($user['posts'] ?? []);
        echo "- {$user['name']}: {$postCount} posts\n";
    }

    $page++;
}

// 4. Pagination pada nested data (posts per user)
echo "\n\n4. Nested Data Pagination (Posts per User):\n";

// Ambil semua user
$allUsers = $users->find()->sort(['name' => 1])->toArray();

foreach ($allUsers as $user) {
    $userId = $user['_id'];
    $userName = $user['name'];

    // Hitung total post untuk user ini
    $totalPosts = $posts->count(['user_id' => $userId]);

    echo "\nUser: {$userName} (Total posts: {$totalPosts})\n";

    if ($totalPosts === 0) {
        echo "  No posts\n";
        continue;
    }

    $postsPerPage = 2;
    $totalPages = ceil($totalPosts / $postsPerPage);

    for ($page = 1; $page <= $totalPages; $page++) {
        $skip = ($page - 1) * $postsPerPage;

        // Ambil posts untuk user dengan pagination
        $userPosts = $posts->find(['user_id' => $userId])
            ->sort(['created_at' => -1])
            ->skip($skip)
            ->limit($postsPerPage)
            ->toArray();

        echo "  Page {$page}/{$totalPages}:\n";
        foreach ($userPosts as $post) {
            $date = date('Y-m-d', $post['created_at']);
            echo "    - {$post['title']} ({$date})\n";
        }
    }
}

echo "\nAll pagination examples completed successfully!\n";
