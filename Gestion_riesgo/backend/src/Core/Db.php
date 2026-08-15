<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Conexión PDO perezosa y compartida. Se abre en la primera consulta real, no
 * al arrancar: rutas como /health o una petición CORS de preflight no deben
 * gastar una conexión del cupo del hosting compartido.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function conn(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host   = (string) Config::get('db.host', 'localhost');
        $puerto = (int) Config::get('db.puerto', 3306);
        $nombre = (string) Config::get('db.nombre');
        $charset = (string) Config::get('db.charset', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$puerto};dbname={$nombre};charset={$charset}";

        try {
            self::$pdo = new PDO(
                $dsn,
                (string) Config::get('db.usuario'),
                (string) Config::get('db.password'),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // Sentencias preparadas reales: con emulación, MySQL recibe
                    // los enteros de LIMIT como cadenas entrecomilladas y falla.
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            // El mensaje de PDO trae usuario y host de la base; nunca debe
            // llegar al cliente.
            throw new RuntimeException('No se pudo conectar a la base de datos.', 0, $e);
        }

        return self::$pdo;
    }

    /** @param array<string,mixed> $params */
    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $params */
    public static function first(string $sql, array $params = []): ?array
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }

    /** @param array<string,mixed> $params @return int filas afectadas */
    public static function exec(string $sql, array $params = []): int
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    public static function lastId(): int
    {
        return (int) self::conn()->lastInsertId();
    }
}
