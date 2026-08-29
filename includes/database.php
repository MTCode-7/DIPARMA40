<?php
// ============================================================
// اتصال قاعدة البيانات - XAMPP
// ============================================================

require_once __DIR__ . '/config.php';

class Database {
    private static $instance = null;
    private $connection = null;
    private $queries    = [];
    private $queryCount = 0;
    private $totalTime  = 0;
    private $stmtCache  = []; // Prepared statements cache

    private function __construct() {
        $this->connect();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function connect() {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                DB_HOST, DB_PORT, DB_NAME
            );

            $this->connection = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_general_ci, time_zone='+04:00', wait_timeout=60, interactive_timeout=60",
            ]);

            // ضبط الـ session للأداء الأمثل
            $this->connection->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
            $this->connection->exec("SET collation_connection = 'utf8mb4_general_ci'");
            $this->connection->exec("SET time_zone = '+04:00'");
            try {
                $this->connection->exec("SET SESSION query_cache_type = ON");
            } catch (PDOException $e) {
                // Ignore query cache errors on MySQL versions where it is disabled globally.
            }
            $this->connection->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");


        } catch (PDOException $e) {
            // عرض صفحة خطأ مناسبة بدلاً من die() المجردة
            $this->connection = null;
            if (!defined('DB_SILENT_FAIL')) {
                http_response_code(503);
                echo '<!DOCTYPE html><html lang="ar" dir="rtl">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DI PARMA | خطأ في الاتصال</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Cairo,sans-serif;background:#0a0f1e;color:#FFDFA0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.box{background:rgba(10,16,39,.95);border:1px solid rgba(255,215,0,.25);border-radius:20px;padding:40px 35px;max-width:480px;width:100%;text-align:center}
.icon{font-size:3rem;margin-bottom:16px}
h1{font-size:1.4rem;background:linear-gradient(135deg,#FFE066,#FFD700);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:12px}
p{color:#aaa;font-size:.9rem;line-height:1.7;margin-bottom:20px}
.btn{display:inline-block;padding:10px 24px;background:linear-gradient(135deg,#FFE066,#FFD700);color:#000;border-radius:10px;text-decoration:none;font-weight:700;font-size:.9rem}
.err{background:rgba(217,83,79,.1);border:1px solid rgba(217,83,79,.3);border-radius:8px;padding:10px;color:#EF9A9A;font-size:.78rem;margin-bottom:18px;text-align:right;direction:ltr}
</style></head>
<body><div class="box">
<div class="icon">🔌</div>
<h1>تعذّر الاتصال بقاعدة البيانات</h1>
<p>تأكد من تشغيل <strong>XAMPP</strong> وأن خدمة <strong>MySQL</strong> نشطة، ثم أعد تحميل الصفحة.</p>
<div class="err">' . htmlspecialchars($e->getMessage()) . '</div>
<a href="javascript:location.reload()" class="btn">🔄 إعادة المحاولة</a>
</div></body></html>';
                exit();
            }
        }
    }

    public function query($sql, $params = []) {
        $start = microtime(true);
        try {
            // Prepared Statement Cache
            $cacheKey = md5($sql);
            if (!isset($this->stmtCache[$cacheKey])) {
                if (count($this->stmtCache) > 100) {
                    array_shift($this->stmtCache); // LRU eviction
                }
                $this->stmtCache[$cacheKey] = $this->connection->prepare($sql);
            }
            $stmt = $this->stmtCache[$cacheKey];
            $stmt->execute($params);
            $result = $stmt->fetchAll();
            
            $this->queries[] = [
                'sql' => $sql,
                'params' => $params,
                'time' => microtime(true) - $start
            ];
            $this->queryCount++;
            $this->totalTime += microtime(true) - $start;
            
            return $result;
        } catch (PDOException $e) {
            error_log('Query error: ' . $e->getMessage() . ' | SQL: ' . $sql);
            throw $e;
        }
    }

    public function execute($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log('Execute error: ' . $e->getMessage() . ' | SQL: ' . $sql);
            throw $e;
        }
    }

    public function insert($table, $data) {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            DB_PREFIX . $table,
            implode(', ', array_map(function($col) { return "`$col`"; }, $columns)),
            implode(', ', $placeholders)
        );
        
        $this->execute($sql, array_values($data));
        return $this->connection->lastInsertId();
    }

    public function update($table, $data, $where) {
        $set = [];
        $params = [];
        
        foreach ($data as $col => $val) {
            $set[] = "`$col` = ?";
            $params[] = $val;
        }
        
        $whereClause = $this->buildWhere($where, $params);
        
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            DB_PREFIX . $table,
            implode(', ', $set),
            $whereClause
        );
        
        return $this->execute($sql, $params);
    }

    public function delete($table, $where) {
        $params = [];
        $whereClause = $this->buildWhere($where, $params);
        
        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            DB_PREFIX . $table,
            $whereClause
        );
        
        return $this->execute($sql, $params);
    }

    public function select($table, $columns = ['*'], $where = [], $order = [], $limit = 0, $offset = 0) {
        $params = [];
        $cols = $columns === ['*'] ? '*' : implode(', ', array_map(function($col) { return "`$col`"; }, $columns));
        
        $sql = sprintf('SELECT %s FROM %s', $cols, DB_PREFIX . $table);
        
        if (!empty($where)) {
            $sql .= ' WHERE ' . $this->buildWhere($where, $params);
        }
        
        if (!empty($order)) {
            $orderClauses = [];
            foreach ($order as $col => $dir) {
                $orderClauses[] = "`$col` " . ($dir === 'DESC' ? 'DESC' : 'ASC');
            }
            $sql .= ' ORDER BY ' . implode(', ', $orderClauses);
        }
        
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int)$limit;
            if ($offset > 0) {
                $sql .= ' OFFSET ' . (int)$offset;
            }
        }
        
        return $this->query($sql, $params);
    }

    public function find($table, $where, $columns = ['*']) {
        $result = $this->select($table, $columns, $where, [], 1);
        return $result[0] ?? null;
    }

    private function buildWhere($where, &$params) {
        $conditions = [];
        foreach ($where as $col => $val) {
            $conditions[] = "`$col` = ?";
            $params[] = $val;
        }
        return implode(' AND ', $conditions);
    }

    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }

    public function commit() {
        return $this->connection->commit();
    }

    public function rollback() {
        return $this->connection->rollBack();
    }

    public function getLastInsertId() {
        return $this->connection->lastInsertId();
    }

    public function getQueryCount() {
        return $this->queryCount;
    }

    public function getTotalTime() {
        return $this->totalTime;
    }

    public function getQueries() {
        return $this->queries;
    }
}

function db() {
    return Database::getInstance();
}

function find($table, $where = []) {
    $database = db();
    if (method_exists($database, 'find')) {
        return $database->find($table, $where);
    }

    $conditions = [];
    $params = [];
    foreach ($where as $column => $value) {
        $conditions[] = "`{$column}` = :{$column}";
        $params[":{$column}"] = $value;
    }
    $statement = $database->prepare(
        'SELECT * FROM ' . $table . ' WHERE ' . implode(' AND ', $conditions) . ' LIMIT 1'
    );
    $statement->execute($params);
    return $statement->fetch() ?: null;
}

function update($table, $data = [], $where = []) {
    $database = db();
    if (method_exists($database, 'update')) {
        return $database->update($table, $data, $where);
    }

    $set = [];
    $conditions = [];
    $params = [];
    foreach ($data as $column => $value) {
        $set[] = "`{$column}` = :set_{$column}";
        $params[":set_{$column}"] = $value;
    }
    foreach ($where as $column => $value) {
        $conditions[] = "`{$column}` = :where_{$column}";
        $params[":where_{$column}"] = $value;
    }
    $statement = $database->prepare(
        'UPDATE ' . $table . ' SET ' . implode(', ', $set) . ' WHERE ' . implode(' AND ', $conditions)
    );
    $statement->execute($params);
    return $statement->rowCount();
}
?>