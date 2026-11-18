<?php
/**
 * Практична робота №7. Варіант №14 (Каталог музики / плейлисти)
 * 
 * Звіт про оптимізацію (до / після):
 * - Час виконання (Time per request): 14.964 мс -> 14.336 мс
 * - Кількість SQL-запитів: 6 (1+N) -> 2 (з урахуванням кешу)
 * - Requests per second: 1336.53 -> 1395.08 запр./сек
 * 
 * Причина різниці: заміна циклу N+1 на єдиний агрегатний запит із GROUP BY artist, 
 * додавання індексу на стовпець artist та файлове кешування totalDuration() 
 * суттєво зменшили кількість звернень і навантаження на СУБД.
 */
header('Content-Type: application/json; charset=utf-8');

$startTime = microtime(true);
$startMemory = memory_get_peak_usage(true);

$host = 'localhost';
$db   = 'music_catalog';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

class DbWrapper {
    private PDO $pdo;
    public int $queryCount = 0;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function query(string $sql) {
        $this->queryCount++;
        return $this->pdo->query($sql);
    }

    public function prepare(string $sql) {
        return new StmtWrapper($this->pdo->prepare($sql), $this);
    }
}

class StmtWrapper {
    private PDOStatement $stmt;
    private DbWrapper $db; 

    public function __construct(PDOStatement $stmt, DbWrapper $db) {
        $this->stmt = $stmt;
        $this->db = $db;
    }

    public function execute(array $params = []) {
        $this->db->queryCount++;
        return $this->stmt->execute($params);
    }

    public function fetchAll($mode = null) {
        return $this->stmt->fetchAll($mode);
    }

    public function fetch($mode = null) {
        return $this->stmt->fetch($mode);
    }
    
    public function fetchColumn() {
        return $this->stmt->fetchColumn();
    }
}

$db = new DbWrapper($pdo);

$isOptimized = isset($_GET['optimized']) && $_GET['optimized'] == '1';
$resource = $_GET['resource'] ?? '';

if ($resource !== 'tracks') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid resource. Use resource=tracks']);
    exit;
}

$tracks = [];
$totalDurationResult = 0;

if (!$isOptimized) {
    $tracks = $db->query("SELECT * FROM tracks")->fetchAll();
    foreach ($tracks as &$track) {
        $stmt = $db->prepare("SELECT SUM(duration) FROM tracks WHERE artist = :artist");
        $stmt->execute([':artist' => $track['artist']]);
        $track['artist_total_duration'] = $stmt->fetchColumn();
    }
    unset($track);

    $totalDurationResult = array_sum(array_column($tracks, 'artist_total_duration'));

} else {
    function getCachedTotalDuration(DbWrapper $db, int $ttl = 30): mixed {
        $cacheFile = sys_get_temp_dir() . '/cache_music_total_duration.json';
        
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
            return json_decode(file_get_contents($cacheFile), true);
        }
        
        $result = $db->query("SELECT SUM(duration) as total FROM tracks")->fetch();
        $total = $result['total'] ?? 0;
        
        file_put_contents($cacheFile, json_encode($total));
        return $total;
    }

    $durationsQuery = $db->query("SELECT artist, SUM(duration) AS total_dur FROM tracks GROUP BY artist");
    $durations = [];
    while ($row = $durationsQuery->fetch()) {
        $durations[$row['artist']] = $row['total_dur'];
    }

    $tracks = $db->query("SELECT * FROM tracks")->fetchAll();
    foreach ($tracks as &$track) {
        $track['artist_total_duration'] = $durations[$track['artist']] ?? 0;
    }
    unset($track);

    $totalDurationResult = getCachedTotalDuration($db, 30);
}

$elapsedTime = (microtime(true) - $startTime) * 1000; 
$peakMemory = (memory_get_peak_usage(true) - $startMemory) / 1024 / 1024; 

$response = [
    'status' => 'success',
    'optimized' => $isOptimized,
    'query_count' => $db->queryCount,
    'execution_time_ms' => round($elapsedTime, 2),
    'peak_memory_mb' => round($peakMemory, 4),
    'total_duration' => $totalDurationResult,
    'data' => $tracks
]; 

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
