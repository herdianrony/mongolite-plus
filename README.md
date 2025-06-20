# MongoLitePlus

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Packagist](https://img.shields.io/packagist/v/herdianrony/mongolite-plus.svg)](https://packagist.org/packages/herdianrony/mongolite-plus)
[![PHP Version](https://img.shields.io/badge/php-%3E=8.0-blue.svg)](https://www.php.net/releases/)

# MongoLitePlus

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Packagist](https://img.shields.io/packagist/v/herdianrony/mongolite-plus.svg)](https://packageto.org/packages/herdianrony/mongolite-plus)
[![PHP Version](https://img.shields.io/badge/php-%3E=8.0-blue.svg)](https://www.php.net/releases/)

```mermaid
graph TD
    subgraph Client
        A[Client] -->|getDatabase('db1')| B[Database: db1.sqlite]
        A -->|getDatabase('db2')| C[Database: db2.sqlite]
        A -->|getCrossDatabase()| CD[CrossDatabase Service]
    end

    subgraph Database Operations
        B -->|getCollection('users')| B1[Collection: users]
        B -->|getCollection('products')| B2[Collection: products]
        C -->|getCollection('orders')| C1[Collection: orders]
    end

    subgraph Cross-Database Features
        CD -->|getDocument(db, coll, id)| D1[Cached Document Access]
        CD -->|getRelated(sourceDoc, def)| D2[Fetch Related Data]
        CD -->|embedData(sourceDb, sourceColl, id, rules)| D3[Embed Data Across DBs]
        CD -->|migrateData(fromDb, toDb, coll, filter)| D4[Migrate Data]
        D2 -- Relates To --> B1 & B2 & C1
        D3 -- Embeds From --> B1 & B2 & C1
        D4 -- Moves Data Between --> B & C
    end

    subgraph Advanced Features
        B -->|backupDatabase()| E1[Backup Management]
        A -->|backupAll()| E2[Full System Backup]
        A -->|getShardedCollection()| E3[Sharding Support (Logical)]
        B -->|getDatabaseMetrics()| E4[Database Metrics]
        B -- Optional --> B_ENC(Encrypted Database)
    end

    B1 -->|CRUD/Query/Aggregate| F[Document Operations]
    B2 -->|CRUD/Query/Aggregate| G[Document Operations]
    C1 -->|CRUD/Query/Aggregate| H[Document Operations]

```

## Overview

MongoLitePlus adalah pustaka database ringan dan berorientasi dokumen untuk PHP, dibangun di atas SQLite.

> 📌 _MongoLitePlus terinspirasi dari [Mongo-Lite](https://github.com/agentejo/mongo-lite) oleh Agentejo. Proyek ini merupakan versi yang dibangun ulang dengan fitur tambahan seperti relasi, indeksasi lanjutan, dan dukungan multi-database modular._ Ia menyediakan antarmuka seperti MongoDB dengan kesederhanaan dan portabilitas dari SQLite.

---

## 🚀 Fitur

- **Penyimpanan Dokumen**: Simpan dokumen JSON dalam koleksi
- **API Mirip MongoDB**: Sintaks yang familiar bagi pengguna MongoDB
- **Dukungan Multi-Database**: Database terisolasi per fitur/modul
- **Indeksasi**: Indeks otomatis dan manual
- **Relasi**: Hubungan antar dokumen lintas koleksi
- **Operasi Lintas Database**: Akses data antar database fitur
- **Ringan**: Database file tunggal, tanpa server

---

## ⚙️ Instalasi

```bash
composer require herdianrony/mongolite-plus
```

## 📦 Penggunaan Dasar

```php
require 'vendor/autoload.php';
use MongoLitePlus\Client;

$client = new Client(__DIR__ . '/data');
$db = $client->mydb;
$collection = $db->users;

// Menyisipkan dokumen
$id = $collection->insert([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'age' => 30
]);

// Mencari dokumen
$user = $collection->findOne(['email' => 'john@example.com']);

// Memperbarui dokumen
$collection->update(
    ['_id' => $id],
    ['age' => 31]
);

// Menghapus dokumen
$collection->remove(['_id' => $id]);
```

## 🧹 Database per Fitur

Pisahkan database berdasarkan fitur aplikasi untuk menjaga modularitas dan isolasi data.

```php
use MongoLitePlus\FeatureDatabase;

$featureDB = new FeatureDatabase(__DIR__ . '/data');

// Fitur pengguna
$userDB = $featureDB->getDatabase('user');
$users = $userDB->users;

// Fitur marketplace
$marketDB = $featureDB->getDatabase('marketplace');
$products = $marketDB->products;
```

## 🔄 Operasi Lintas Database

Operasi ini memungkinkan kamu untuk mengakses data dari berbagai database berbeda dan menghubungkannya dengan mudah.

Contoh kasus: Mengambil semua produk dari database "marketplace" yang terkait dengan user tertentu di database "user".

```php
use MongoLitePlus\CrossDatabase;

$crossDB = new CrossDatabase($featureDB);

// Ambil user dari database user
$user = $crossDB->getDocument('user', 'users', $userId);

// Ambil produk yang dijual oleh user tersebut
echo "Produk dari penjual $userId:\n";
$products = $crossDB->getManyRelated(
    $userId,
    'marketplace:products:seller_id'
);

foreach ($products as $product) {
    echo $product['name'] . PHP_EOL;
}
```

## 🔗 Relasi Dokumen

Relasi digunakan untuk menghubungkan dokumen antar koleksi, mirip seperti foreign key namun tetap fleksibel dan tidak mengikat.

```php
// Buat relasi dari user ke post
$users->relateTo($userId, 'posts', $postId);

// Ambil semua relasi user tersebut
$relations = $users->getRelations($userId);
foreach ($relations as $rel) {
    $post = $posts->findOne(['_id' => $rel['to_id']]);
    echo 'Judul: ' . $post['title'] . PHP_EOL;
}
```

## 📊 Indeksasi

Indeks membantu mempercepat pencarian data dalam koleksi dan mencegah duplikasi data saat diset sebagai unik.

```php
// Buat indeks unik berdasarkan email
$collection->createIndex('email', ['unique' => true]);

// Tampilkan semua indeks
$indexes = $collection->listIndexes();
print_r($indexes);

// Hapus indeks jika tidak diperlukan
$collection->dropIndex('email');
```

## 💾 Backup & Migrasi

Backup digunakan untuk menyimpan salinan data database, sedangkan migrasi digunakan untuk memindahkan data antar fitur.

```php
// Backup database user
$backupFile = $featureDB->backupFeature('user', __DIR__ . '/backups');
echo "Backup tersimpan di: $backupFile\n";

// Migrasi data dari marketplace ke premium_marketplace berdasarkan kondisi
$featureDB->migrateData(
    'marketplace',
    'premium_marketplace',
    'products',
    function($doc) {
        return $doc['price'] > 100; // Hanya produk mahal
    }
);
```

## 📚 Contoh Lengkap

Tersedia di folder `/examples`:

- `basic-usage.php`
- `feature-database.php`
- `cross-database.php`
- `relations.php`

## ✅ Pengujian Otomatis

Untuk menjalankan pengujian otomatis menggunakan PestPHP, pastikan struktur berikut tersedia:

```php
// tests/FullIntegrationTest.php
use MongoLitePlus\Client;

test('Full Integration', function () {
    $client = new Client(__DIR__ . '/../data');
    $db = $client->testdb;
    $col = $db->users;

    $id = $col->insert([
        'name' => 'Test',
        'email' => 'test@example.com'
    ]);

    expect($id)->not->toBeNull();

    $user = $col->findOne(['email' => 'test@example.com']);
    expect($user['name'])->toBe('Test');

    $col->remove(['_id' => $id]);
});
```

Tambahkan ke `composer.json`:

```json
"require-dev": {
  "pestphp/pest": "^2.0"
},
"autoload-dev": {
  "psr-4": {
    "MongoLitePlus\\Tests\\": "tests/"
  }
},
"scripts": {
  "test": "pest"
}
```

Jalankan test dengan:

```bash
./vendor/bin/pest
```

---

## 🧠 Kontribusi

Kontribusi terbuka untuk siapa pun. Silakan fork repo ini dan kirimkan pull request.

## 💬 Diskusi & Dukungan

Untuk pertanyaan, bug, atau diskusi lainnya, silakan gunakan tab [Issues](https://github.com/herdianrony/mongolite-plus/issues).

## 🚧 Status Pengembangan

MongoLitePlus masih terus dikembangkan dan dapat berubah sewaktu-waktu. Disarankan untuk menggunakan versi rilis stabil untuk aplikasi produksi.
