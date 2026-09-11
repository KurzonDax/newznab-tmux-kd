<?php

declare(strict_types=1);

if (getenv('CBP_INTEGRATION_DB_DATABASE') !== 'cbp_integration') {
    exit(2);
}
$pdo = new PDO('mysql:host=mariadb;dbname=cbp_integration', 'root', (string) getenv('CBP_INTEGRATION_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->beginTransaction();
$lock = $pdo->prepare('SELECT id FROM collections WHERE id = ? FOR UPDATE');
$lock->execute([(int) $argv[1]]);
file_put_contents($argv[3], 'locked');
$deadline = microtime(true) + 10;
$observer = new PDO('mysql:host=mariadb;dbname=cbp_integration', 'root', (string) getenv('CBP_INTEGRATION_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
do {
    $status = $observer->query('SHOW ENGINE INNODB STATUS')->fetch(PDO::FETCH_ASSOC)['Status'];
    preg_match_all('/---TRANSACTION .*?(?=---TRANSACTION|--------\\nFILE I\\/O)/s', $status, $transactions);
    $waiting = false;
    foreach ($transactions[0] as $transaction) {
        if (str_contains($transaction, 'MariaDB thread id '.$argv[2].',') && str_contains($transaction, 'LOCK WAIT')) {
            $waiting = true;
        }
    }
    if ($waiting) {
        file_put_contents($argv[4], 'observed');
        $pdo->commit();
        exit(0);
    }
    usleep(10000);
} while (microtime(true) < $deadline);
$pdo->rollBack();
exit(3);
