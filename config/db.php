<?php

class Database {
    private $host;
    private $port;
    private $socket;
    private $database;
    private $username;
    private $password;
    private $connection;

    private function loadLocalConfig() {
        $localPath = __DIR__ . '/Database.local.php';
        if (!file_exists($localPath)) {
            return [];
        }
        $config = require $localPath;
        return is_array($config) ? $config : [];
    }

    public function __construct() {
        $local = $this->loadLocalConfig();

        $this->host = appEnvValue('DB_HOST', (string)($local['host'] ?? '127.0.0.1'));
        $this->port = (int)appEnvValue('DB_PORT', (string)($local['port'] ?? 3306));
        $this->socket = appEnvValue('DB_SOCKET', (string)($local['socket'] ?? ''));
        $this->database = appEnvValue('DB_NAME', (string)($local['database'] ?? 'balingasag_shs'));
        $this->username = appEnvValue('DB_USER', (string)($local['username'] ?? 'root'));
        $this->password = appEnvValue('DB_PASS', appEnvValue('DB_PASSWORD', (string)($local['password'] ?? '')));
    }

    public function getConnection() {
        if ($this->connection instanceof PDO) {
            try {
                $this->connection->query('SELECT 1');
                return $this->connection;
            } catch (PDOException $e) {
                $this->connection = null;
            }
        }

        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->database . ";charset=utf8mb4";
            if ($this->socket !== '') {
                $dsn .= ";unix_socket=" . $this->socket;
            } else {
                $dsn .= ";port=" . $this->port;
            }

            $this->connection = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_PERSISTENT => true,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $this->connection->exec("SET NAMES utf8mb4");
        } catch(PDOException $e) {
            error_log("Database connection error: " . $e->getMessage());
        }

        return $this->connection;
    }
}
