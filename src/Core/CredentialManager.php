<?php

namespace P8P\Core;

use P8P\Core\Database;
use PDO;
use Exception;

class CredentialManager
{
    private PDO $db;
    private string $encryptionKey;

    public function __construct(?PDO $db = null)
    {
        // Resolver el entorno aquí para que HTTP, cron y todos los nodos usen la misma clave.
        EnvLoader::load(__DIR__ . '/../../.env', false);
        $secret = $_ENV['APP_SECRET'] ?? getenv('APP_SECRET');
        if (!is_string($secret) || trim($secret) === '') {
            throw new Exception('APP_SECRET no está configurada. No se pueden cifrar las credenciales.');
        }
        $this->encryptionKey = hash('sha256', $secret, true);

        $this->db = $db ?? Database::getConnection(require __DIR__ . '/../../config/database.php');
    }

    /**
     * Cifra un texto o arreglo con AES-256-GCM
     */
    public function encrypt(mixed $data): string
    {
        $json = is_string($data) ? $data : json_encode($data);
        $iv = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $json,
            'aes-256-gcm',
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Descifra datos almacenados
     */
    public function decrypt(string $encryptedData): mixed
    {
        $decoded = base64_decode($encryptedData, true);
        if ($decoded === false || strlen($decoded) < 28) {
            throw new Exception('Error al descifrar la credencial. Datos inválidos.');
        }
        $iv = substr($decoded, 0, 12);
        $tag = substr($decoded, 12, 16);
        $ciphertext = substr($decoded, 28);

        $decrypted = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($decrypted === false) {
            // Compatibilidad de lectura: credentials.php antes no cargaba .env.
            // getenv() devolvía false y hash() lo convertía en cadena vacía.
            // Nunca se usa esta clave para guardar nuevas credenciales.
            $decrypted = openssl_decrypt(
                $ciphertext,
                'aes-256-gcm',
                hash('sha256', '', true),
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
        }

        if ($decrypted === false) {
            throw new Exception("Error al descifrar la credencial. Clave inválida.");
        }

        $jsonDecoded = json_decode($decrypted, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $jsonDecoded : $decrypted;
    }

    /**
     * Guarda o actualiza una credencial en MariaDB
     */
    public function saveCredential(string $name, string $type, array $data, ?string $id = null): string
    {
        $id = $id ?? Uuid::v4();

        $encryptedValue = $this->encrypt($data);

        $stmt = $this->db->prepare("
            INSERT INTO credentials (id, name, type, data)
            VALUES (:id, :name, :type, :data)
            ON DUPLICATE KEY UPDATE name = :name_up, data = :data_up, updated_at = NOW()
        ");

        $stmt->execute([
            ':id' => $id,
            ':name' => $name,
            ':type' => $type,
            ':data' => $encryptedValue,
            ':name_up' => $name,
            ':data_up' => $encryptedValue,
        ]);

        return $id;
    }

    /**
     * Obtiene la lista pública de credenciales (sin revelar las claves)
     */
    public function getCredentials(): array
    {
        $stmt = $this->db->query("SELECT id, name, type, created_at, updated_at FROM credentials ORDER BY name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Descifra y recupera los datos reales de una credencial para el motor
     */
    public function getCredentialDecrypted(string $id): ?array
    {
        $stmt = $this->db->prepare("SELECT type, data FROM credentials WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return null;

        $decryptedData = $this->decrypt($row['data']);
        return [
            'type' => $row['type'],
            'data' => is_array($decryptedData) ? $decryptedData : ['api_key' => $decryptedData]
        ];
    }

    /**
     * Elimina una credencial
     */
    public function deleteCredential(string $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM credentials WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}
