<?php
require __DIR__ . '/../vendor/autoload.php';

use MongoLitePlus\Client;

class AdvancedTest
{
    protected $client;
    protected $db;
    protected $products;

    public function __construct()
    {
        $this->client = new Client(__DIR__ . '/data');
        $this->db = $this->client->performance_db;
        $this->products = $this->db->products;
        $this->cleanup();
    }

    protected function cleanup()
    {
        try {
            $this->products->remove([]);
        } catch (\Exception $e) {
            // Tidak ada masalah jika koleksi kosong
        }
    }

    public function runAllTests()
    {
        $this->testBulkOperations();
        $this->testComplexQueries();
        $this->testNestedDocuments();
        $this->testConcurrency();
        $this->testEdgeCases();
    }

    // 1. Test Operasi Bulk
    protected function testBulkOperations()
    {
        echo "\n=== TEST BULK OPERATIONS ===\n";

        // Insert 1000 dokumen
        $docs = [];
        for ($i = 0; $i < 1000; $i++) {
            $docs[] = [
                'name' => "Product $i",
                'price' => rand(10, 1000),
                'category' => ($i % 2 == 0) ? 'Electronics' : 'Clothing',
                'in_stock' => ($i % 3 != 0),
                'tags' => ['tag' . rand(1, 5), 'tag' . rand(6, 10)]
            ];
        }

        $start = microtime(true);
        $ids = $this->products->insertMany($docs);
        $insertTime = microtime(true) - $start;

        echo "Inserted 1000 documents in {$insertTime} seconds\n";
        echo "Memory usage: " . memory_get_peak_usage(true) / 1024 . " KB\n";

        // Update massal
        $start = microtime(true);
        $updated = $this->products->update(
            ['category' => 'Electronics'],
            ['discount' => 0.1]
        );
        $updateTime = microtime(true) - $start;

        echo "Updated $updated electronics with discount in {$updateTime} seconds\n";

        // Delete massal
        $start = microtime(true);
        $deleted = $this->products->remove(['price' => ['$lt' => 500]]);
        $deleteTime = microtime(true) - $start;

        echo "Deleted $deleted cheap products in {$deleteTime} seconds\n";

        // Verifikasi akhir
        $remaining = $this->products->count();
        $expected = 1000 - $deleted;
        echo "Final count: $remaining (Expected: $expected)\n";
    }

    // 2. Test Query Kompleks (Perbaikan boolean query)
    protected function testComplexQueries()
    {
        echo "\n=== TEST COMPLEX QUERIES ===\n";

        // PERBAIKAN: Gunakan boolean langsung, bukan string 'true'
        $results = $this->products->find([
            'category' => 'Electronics',
            'price' => ['$gt' => 700],
            'in_stock' => true  // Boolean langsung
        ]);

        echo "High-value electronics in stock: " . $results->count() . "\n";

        // Query OR implisit
        $results = $this->products->find(function ($doc) {
            return $doc['price'] > 900 || $doc['price'] < 100;
        });

        echo "Premium or budget products: " . $results->count() . "\n";

        // Sorting + Pagination
        $results = $this->products->find()
            ->sort(['price' => -1])
            ->skip(5)
            ->limit(10);

        echo "Top 6-15 most expensive products:\n";
        foreach ($results as $i => $doc) {
            echo ($i + 6) . ". {$doc['name']} - \${$doc['price']}\n";
        }
    }

    // 3. Test Nested Documents (Perbaikan query array of objects)
    protected function testNestedDocuments()
    {
        echo "\n=== TEST NESTED DOCUMENTS ===\n";

        // Insert dokumen nested
        $id = $this->products->insert([
            'name' => 'Smartphone',
            'specs' => [
                'ram' => 8,
                'storage' => 256,
                'os' => 'Android'
            ],
            'reviews' => [
                ['user' => 'Alice', 'rating' => 5],
                ['user' => 'Bob', 'rating' => 4]
            ]
        ]);

        // Update nested field
        $this->products->update(
            ['_id' => $id],
            ['specs.ram' => 12]
        );

        // Query nested field
        $product = $this->products->findOne(['specs.ram' => 12]);
        echo "Product with 12GB RAM: {$product['name']}\n";

        // PERBAIKAN: Gunakan PHP filtering untuk array of objects
        $products = $this->products->find(function ($doc) {
            if (isset($doc['reviews'])) {
                foreach ($doc['reviews'] as $review) {
                    if (($review['rating'] ?? 0) == 5) {
                        return true;
                    }
                }
            }
            return false;
        });

        echo "Products with 5-star reviews: " . count($products->toArray()) . "\n";
    }

    // 4. Test Concurrency (Perbaikan tanpa transaksi)
    protected function testConcurrency()
    {
        echo "\n=== TEST CONCURRENCY ===\n";

        $id = $this->products->insert(['name' => 'Race Item', 'stock' => 10]);

        // Simulasi 5 concurrent requests
        $promises = [];
        for ($i = 0; $i < 5; $i++) {
            $promises[] = function () use ($id) {
                $client = new Client(__DIR__ . '/data');
                $products = $client->performance_db->products;

                try {
                    // Operasi atomik tanpa transaksi
                    $products->update(
                        ['_id' => $id],
                        ['$inc' => ['stock' => -1]]  // Decrement atomik
                    );

                    return "Success: Stock decremented by 1";
                } catch (\Exception $e) {
                    return "Failed: " . $e->getMessage();
                }
            };
        }

        // Eksekusi paralel (simulasi)
        $results = [];
        foreach ($promises as $promise) {
            $results[] = $promise();
        }

        // Verifikasi hasil akhir
        $final = $this->products->findOne(['_id' => $id]);
        echo "Final stock: {$final['stock']}\n";
        echo "Transaction results:\n" . print_r($results, true);
    }

    // 5. Test Edge Cases (Perbaikan boolean handling)
    protected function testEdgeCases()
    {
        echo "\n=== TEST EDGE CASES ===\n";

        // 1. Empty document
        $id = $this->products->insert([]);
        echo "Empty document inserted: " . ($id ? "Yes" : "No") . "\n";

        // 2. Large document
        $largeDoc = [
            'name' => 'Large Data',
            'content' => str_repeat('A', 1024 * 1024) // 1MB data
        ];

        try {
            $id = $this->products->insert($largeDoc);
            echo "1MB document inserted: " . ($id ? "Yes" : "No") . "\n";
        } catch (\Exception $e) {
            echo "Large document failed: " . $e->getMessage() . "\n";
        }

        // 3. Special characters
        $id = $this->products->insert([
            'name' => 'Document "with quotes"',
            'data' => "Special \0 chars \n \t"
        ]);

        $doc = $this->products->findOne(['_id' => $id]);
        echo "Special chars preserved: "
            . (($doc['name'] === 'Document "with quotes"') ? "Yes" : "No") . "\n";

        // 4. Boolean handling (Perbaikan)
        $this->products->insert(['flag' => false]);
        $count = $this->products->find(['flag' => false])->count();
        echo "Boolean false query: $count documents\n";
    }
}

// Jalankan advanced test
$test = new AdvancedTest();
$test->runAllTests();
