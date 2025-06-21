<?php

require __DIR__ . '/../vendor/autoload.php';

use MongoLitePlus\Client;

// --- Inisialisasi dan Persiapan ---
echo "--- Inisialisasi dan Persiapan ---\n";
$storagesPath = __DIR__ . '/storages';
$backupsPath = __DIR__ . '/backups';

// Pastikan folder tersedia
if (!is_dir($storagesPath)) mkdir($storagesPath, 0777, true);
if (!is_dir($backupsPath)) mkdir($backupsPath, 0777, true);

// Hapus file database dan backup lama
@array_map('unlink', glob("$storagesPath/*.sqlite"));
@array_map('unlink', glob("$backupsPath/*.sqlite*"));
if (is_dir($backupsPath)) @rmdir($backupsPath);
echo "Cleanup selesai. Memulai eksekusi...\n\n";


// Inisialisasi Client
$client = new Client($storagesPath);
// ===================================================================


// --- 1. Fitur-fitur `Client` (Manajer Utama) ---
echo "--- 1. Fitur-fitur Client ---\n";

// Mengakses/membuat database baru.
$dbToko = $client->toko_utama;
$dbPengguna = $client->getDatabase('data_pengguna'); // Cara alternatif

// Menampilkan semua database yang sedang dikelola
echo "List database saat ini:\n";
print_r($client->listDatabases());
echo "\n";

// Menambahkan data untuk keperluan backup
$dbToko->produk->insert(['nama' => 'Laptop Pro', 'harga' => 25000000]);

// Backup satu database
$fileBackup = $client->backupDatabase('toko_utama', $backupsPath);
echo "Database 'toko_utama' berhasil di-backup ke: " . basename($fileBackup) . "\n";

// Backup semua database sekaligus
$semuaBackup = $client->backupAll($backupsPath);
echo "Semua database di-backup:\n";
print_r(array_map('basename', $semuaBackup));
echo "\n";

// Migrasi Data
$dbSistemLama = $client->getDatabase('sistem_lama');
$dbSistemLama->pelanggan->insertMany([
    ['nama' => 'Andi', 'status' => 'aktif'],
    ['nama' => 'Budi', 'status' => 'tidak aktif'],
    ['nama' => 'Citra', 'status' => 'aktif'],
]);
echo "Memigrasi data pelanggan 'aktif' dari 'sistem_lama' ke 'data_pengguna'...\n";
$jumlahMigrasi = $client->migrateData(
    'sistem_lama',
    'data_pengguna',
    'pelanggan',
    function ($dokumen) {
        return isset($dokumen['status']) && $dokumen['status'] === 'aktif';
    }
);
echo "$jumlahMigrasi data berhasil dimigrasi.\n\n";

// Sharding (Memecah data besar)
echo "Contoh Sharding:\n";
// Client akan otomatis memilih file DB (shard) berdasarkan shardKey
$shard1 = $client->getShardedCollection('log_data', 'events', 'user:123');
$shard1->insert(['event' => 'login', 'userId' => 'user:123']);
$shard2 = $client->getShardedCollection('log_data', 'events', 'user:456');
$shard2->insert(['event' => 'click', 'userId' => 'user:456']);
echo "Data sharding telah dimasukkan. Cek folder 'storages' untuk file 'log_data_shardX.sqlite'.\n\n";
// ===================================================================


// --- 2. Fitur-fitur `Database` (Pengelola Gedung) ---
echo "--- 2. Fitur-fitur Database ---\n";

// Mengakses koleksi (lantai) dari sebuah database
$koleksiPengguna = $dbPengguna->pengguna;
$koleksiPengguna->insert(['nama' => 'Dewi']); // Data dummy untuk membuat koleksi

// Melihat daftar koleksi dalam sebuah database
echo "List koleksi di 'data_pengguna':\n";
print_r($dbPengguna->listCollections());
echo "\n";

// Replikasi (Membuat fotokopi database)
$replicaPath = $storagesPath . '/data_pengguna_replica.sqlite';
$dbPengguna->addReplica($replicaPath);
$dbPengguna->replicate();
echo file_exists($replicaPath) ? "Replika berhasil dibuat.\n\n" : "Gagal membuat replika.\n\n";

// Pemeriksaan Kesehatan & Metrik
echo "Health Check untuk 'data_pengguna':\n";
print_r($dbPengguna->healthCheck());
echo "Metrics untuk 'data_pengguna':\n";
print_r($dbPengguna->getMetrics());
echo "\n";
// ===================================================================


// --- 3. Fitur-fitur `Collection` (Operasi Data Inti) ---
echo "--- 3. Fitur-fitur Collection ---\n";
$pengguna = $dbPengguna->pengguna;
$pengguna->remove([]); // Bersihkan data dari contoh sebelumnya

// C - Create: Menambah data
echo "Menambahkan pengguna baru...\n";
$idPengguna1 = $pengguna->insert(['nama' => 'Eka', 'usia' => 25, 'kota' => 'Jakarta', 'keahlian' => ['php', 'js']]);
$pengguna->insertMany([
    ['nama' => 'Fani', 'usia' => 32, 'kota' => 'Bandung', 'keahlian' => ['go', 'php']],
    ['nama' => 'Gita', 'usia' => 28, 'kota' => 'Jakarta', 'keahlian' => ['python', 'sql']],
    ['nama' => 'Hadi', 'usia' => 40, 'kota' => 'Surabaya', 'keahlian' => ['java']],
]);

// R - Read: Mencari data
echo "\nMencari pengguna bernama 'Eka':\n";
$eka = $pengguna->findOne(['nama' => 'Eka']);
print_r($eka);

echo "\nMencari pengguna dengan usia > 30 tahun:\n";
$penggunaTua = $pengguna->find(['usia' => ['$gt' => 30]])->toArray();
print_r($penggunaTua);

// U - Update: Mengubah data
echo "\nMengubah usia Eka dan menambahkan keahlian baru...\n";
$pengguna->update(
    ['nama' => 'Eka'],
    ['$set' => ['usia' => 26], '$push' => ['keahlian' => 'css']]
);
$ekaUpdated = $pengguna->findOne(['_id' => $idPengguna1]);
print_r($ekaUpdated);

// D - Delete: Menghapus data
echo "\nMenghapus pengguna dari Surabaya...\n";
$jumlahHapus = $pengguna->remove(['kota' => 'Surabaya']);
echo "$jumlahHapus pengguna dihapus.\n";

// Menghitung data
$jumlahTotal = $pengguna->count();
$jumlahJakarta = $pengguna->count(['kota' => 'Jakarta']);
echo "Total pengguna tersisa: $jumlahTotal. Jumlah pengguna di Jakarta: $jumlahJakarta.\n\n";

// Indexing (Mempercepat pencarian)
$pengguna->createIndex('usia');
echo "Indeks yang ada sekarang:\n";
print_r($pengguna->listIndexes());
$pengguna->dropIndex('usia');
echo "Indeks 'usia' dihapus.\n\n";

// Join (Menggabungkan data dari 2 koleksi)
$profil = $dbPengguna->profil;
$profil->insert(['user_id' => $idPengguna1, 'hobi' => 'Membaca Buku']);
$penggunaDenganProfil = $pengguna->join($profil, '_id', 'user_id', 'info_profil');
echo "Data pengguna digabung dengan profil:\n";
print_r($penggunaDenganProfil);
echo "\n";

// Aggregation (Analisis data kompleks)
echo "Agregasi: Kelompokkan pengguna per kota & hitung rata-rata usia.\n";
$pipeline = [
    ['$match' => ['usia' => ['$gt' => 20]]], // Filter dulu
    ['$group' => [ // Kelompokkan
        '_id' => '$kota',
        'jumlahPengguna' => ['$sum' => 1],
        'rataRataUsia' => ['$avg' => '$usia']
    ]],
    ['$sort' => ['jumlahPengguna' => -1]] // Urutkan
];
$hasilAgregasi = $pengguna->aggregate($pipeline);
print_r($hasilAgregasi);
// ===================================================================


// --- 4. Fitur-fitur `Cursor` (Asisten Pencarian) ---
echo "--- 4. Fitur-fitur Cursor ---\n";
// `find()` mengembalikan objek Cursor, memungkinkan chaining method
echo "Mencari semua pengguna, diurutkan berdasarkan usia, lewati 1, ambil 2 hasil:\n";
$hasilCursor = $pengguna->find()
    ->sort(['usia' => 1]) // Urutkan usia dari termuda
    ->skip(1)            // Lewati data pertama
    ->limit(2)           // Ambil 2 data saja
    ->toArray();         // Ambil hasil akhirnya
print_r($hasilCursor);
echo "\n";
// ===================================================================


// --- 5. Fitur `CrossDatabase` (Operasi Lintas Database) ---
echo "--- 5. Fitur CrossDatabase ---\n";
$crossDb = $client->crossDatabase(); // Dapatkan helper

// Skenario: Data pengguna ada di `dbPengguna`, data pesanan ada di `dbToko`
$pesanan = $dbToko->pesanan;
$idPesanan = $pesanan->insert(['user_id' => $idPengguna1, 'barang' => 'Keyboard Mechanical', 'jumlah' => 1]);

// Mengambil dokumen dari database lain
echo "Mengambil data pesanan dari 'dbToko' menggunakan helper:\n";
$dataPesanan = $crossDb->getDocument('toko_utama', 'pesanan', $idPesanan);
print_r($dataPesanan);

// Mencari semua data terkait (one-to-many)
echo "Mencari semua pesanan milik Eka (user_id: $idPengguna1):\n";
$semuaPesananEka = $crossDb->getManyRelated($idPengguna1, 'toko_utama:pesanan:user_id');
print_r($semuaPesananEka);

// Event Listener (Menjalankan fungsi saat ada update)
$crossDb->onUpdate('toko_utama', 'pesanan', function ($id, $dataBaru) {
    echo ">> NOTIFIKASI: Pesanan dengan ID $id diupdate! Data baru: " . json_encode($dataBaru) . "\n";
});

// Pura-pura ada update untuk memicu listener
// Di aplikasi nyata, handleUpdate akan dipanggil setelah operasi update berhasil
$pesanan->update(['_id' => $idPesanan], ['$set' => ['jumlah' => 2]]);
$crossDb->handleUpdate('toko_utama', 'pesanan', $idPesanan, ['jumlah' => 2]);
// ===================================================================