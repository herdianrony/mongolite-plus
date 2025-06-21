<?php
require 'vendor/autoload.php';

use MongoLitePlus\Client;

// --- Inisialisasi dan Persiapan ---
echo "--- Inisialisasi dan Persiapan ---\n";
$storagesPath = __DIR__ . '/storages';
$backupsPath = __DIR__ . '/backups';

// Hapus file database dan backup lama agar contoh ini bisa dijalankan berulang kali
// Use @ to suppress warnings if files/dirs don't exist
@array_map('unlink', glob("$storagesPath/*.sqlite"));
@array_map('unlink', glob("$backupsPath/*.sqlite*"));
if (is_dir($backupsPath)) @rmdir($backupsPath);
echo "Cleanup selesai. Memulai eksekusi...\n\n";

// Inisialisasi Client, menunjuk ke folder tempat menyimpan semua database
$client = new Client($storagesPath);
$secretDB = $client->getDatabase('secret_data', 'my-super-secret-key-123!');

// Buat koleksi dan tambahkan data
$sensitive = $secretDB->sensitive_info;
$sensitive->insert([
    'name' => 'John Doe',
    'credit_card' => '4111-1111-1111-1111',
    'ssn' => '123-45-6789'
]);

echo "Data sensitif berhasil disimpan di database terenkripsi!\n";

// 2. Akses database dengan kunci yang benar
try {
    $correctKeyDB = $client->getDatabase('secret_data', 'my-super-secret-key-123!');
    // PERBAIKAN: Tambahkan array kosong sebagai parameter
    $data = $correctKeyDB->sensitive_info->findOne([]);

    echo "\nAkses dengan kunci benar:\n";
    print_r($data);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// 3. Coba akses dengan kunci salah
try {
    echo "\nMencoba akses dengan kunci salah:\n";
    $wrongKeyDB = $client->getDatabase('secret_data', 'wrong-key-here');
    // PERBAIKAN: Tambahkan array kosong sebagai parameter
    $data = $wrongKeyDB->sensitive_info->findOne([]);
    print_r($data);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// 4. Ubah kunci enkripsi
try {
    $secretDB = $client->getDatabase('secret_data', 'my-super-secret-key-123!');

    if ($secretDB->changeEncryptionKey('new-stronger-key-456@')) {
        echo "\nKunci enkripsi berhasil diubah!\n";

        // Coba akses dengan kunci baru
        $newKeyDB = $client->getDatabase('secret_data', 'new-stronger-key-456@');
        // PERBAIKAN: Tambahkan array kosong sebagai parameter
        $data = $newKeyDB->sensitive_info->findOne([]);
        echo "Data masih bisa diakses dengan kunci baru:\n";
        print_r($data);
    }
} catch (Exception $e) {
    echo "Error mengubah kunci: " . $e->getMessage() . "\n";
}
