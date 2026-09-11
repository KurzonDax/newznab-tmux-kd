<?php

declare(strict_types=1);
use App\Services\CollectionReconciliation\CollectionAdmission;

/** Benchmark-only overlay: keep real callers/lifecycle code and replace just admission traversal. */
$loader = require dirname(__DIR__, 3).'/vendor/autoload.php';
if (getenv('ADMISSION_BENCHMARK_REFERENCE') === '1') {
    if (getenv('CBP_INTEGRATION_DB_DATABASE') !== 'cbp_integration') {
        throw new RuntimeException('The reference benchmark requires the disposable integration database.');
    }
    $source = file_get_contents(dirname(__DIR__, 3).'/app/Services/CollectionReconciliation/CollectionAdmission.php');
    $start = strpos($source, '    public function lockAndScreen(');
    $end = strpos($source, '    public function eligibleIds(');
    if ($start === false || $end === false || $end <= $start) {
        throw new RuntimeException('Admission changed; review the reference benchmark overlay.');
    }
    $source = substr($source, 0, $start).'    use \\Tests\\Support\\Reconciliation\\AdmissionBaseline;'.PHP_EOL.substr($source, $end);
    $path = tempnam(sys_get_temp_dir(), 'admission-reference-');
    if ($path === false) {
        throw new RuntimeException('Cannot create the isolated benchmark overlay.');
    }
    file_put_contents($path, $source);
    register_shutdown_function(static fn () => unlink($path));
    $loader->addClassMap([CollectionAdmission::class => $path]);
}
