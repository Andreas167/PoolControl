<?php

declare(strict_types=1);

/**
 * Eigenständiger Test-Runner (PHPUnit-PHAR-Download ist in der Sandbox blockiert).
 * Stellt die in der Suite genutzten Assertions bereit. In CI läuft echtes PHPUnit.
 */

namespace PHPUnit\Framework {

    class AssertionFailedError extends \Exception {}
    class SkippedTest extends \Exception {}

    abstract class TestCase
    {
        protected function setUp(): void {}

        private function fail(string $m): void { throw new AssertionFailedError($m); }

        protected function assertTrue($c, string $m = ''): void
        { $c === true || $this->fail($m ?: 'not true: ' . var_export($c, true)); }

        protected function assertFalse($c, string $m = ''): void
        { $c === false || $this->fail($m ?: 'not false: ' . var_export($c, true)); }

        protected function assertSame($e, $a, string $m = ''): void
        { $e === $a || $this->fail($m ?: 'not same: ' . var_export($e, true) . ' !== ' . var_export($a, true)); }

        protected function assertNotSame($e, $a, string $m = ''): void
        { $e === $a && $this->fail($m ?: 'unexpectedly same'); }

        protected function assertEquals($e, $a, string $m = ''): void
        { $e == $a || $this->fail($m ?: 'not equal: ' . var_export($e, true) . ' != ' . var_export($a, true)); }

        protected function assertEqualsWithDelta($e, $a, $d, string $m = ''): void
        { abs($e - $a) <= $d || $this->fail($m ?: "delta exceeded: |$e-$a|>$d"); }

        protected function assertEmpty($c, string $m = ''): void
        { empty($c) || $this->fail($m ?: 'not empty'); }

        protected function assertContains($n, $h, string $m = ''): void
        { in_array($n, $h, true) || $this->fail($m ?: 'array does not contain ' . var_export($n, true)); }

        protected function assertIsInt($c, string $m = ''): void
        { is_int($c) || $this->fail($m ?: 'not int: ' . gettype($c)); }

        protected function assertIsFloat($c, string $m = ''): void
        { is_float($c) || $this->fail($m ?: 'not float: ' . gettype($c)); }

        protected function assertIsBool($c, string $m = ''): void
        { is_bool($c) || $this->fail($m ?: 'not bool: ' . gettype($c)); }

        protected function markTestSkipped(string $m = ''): void { throw new SkippedTest($m); }
    }
}

namespace {

    require_once __DIR__ . '/DecisionEngineTest.php';

    use PHPUnit\Framework\AssertionFailedError;
    use PHPUnit\Framework\SkippedTest;
    use PoolControl\Tests\DecisionEngineTest;

    $test = new DecisionEngineTest();
    $ref  = new ReflectionClass($test);
    $setUp = $ref->getMethod('setUp');
    $setUp->setAccessible(true);

    $methods = array_filter(
        array_map(fn($m) => $m->name, $ref->getMethods()),
        fn($n) => str_starts_with($n, 'test')
    );

    $pass = 0; $fail = 0; $skip = 0; $fails = [];

    foreach ($methods as $m) {
        $instance = new DecisionEngineTest();
        $setUp->invoke($instance);
        try {
            $instance->$m();
            $pass++;
            echo "  \033[32m✓\033[0m $m\n";
        } catch (SkippedTest $e) {
            $skip++;
            echo "  \033[33m∅\033[0m $m\n";
        } catch (AssertionFailedError $e) {
            $fail++; $fails[] = "$m: {$e->getMessage()}";
            echo "  \033[31m✗\033[0m $m\n      {$e->getMessage()}\n";
        } catch (\Throwable $e) {
            $fail++; $fails[] = "$m ERROR: {$e->getMessage()}";
            echo "  \033[31m✗ ERR\033[0m $m\n      {$e->getMessage()} @ {$e->getFile()}:{$e->getLine()}\n";
        }
    }

    echo "\n════════════════════════════════════════\n";
    echo sprintf("Tests: %d  |  ✓ %d  ✗ %d  ∅ %d\n", count($methods), $pass, $fail, $skip);
    echo "════════════════════════════════════════\n";
    exit($fail > 0 ? 1 : 0);
}
