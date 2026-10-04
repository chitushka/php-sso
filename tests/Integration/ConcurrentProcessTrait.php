<?php

namespace tests\Integration;

trait ConcurrentProcessTrait
{
    private function runConcurrentWorkers(array $commands): array
    {
        $script = dirname(__DIR__) . '/Fixtures/auth_race_worker.php';
        $startAt = sprintf('%.6F', microtime(true) + 0.75);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $workers = [];

        foreach ($commands as $arguments) {
            $process = proc_open(
                array_merge([PHP_BINARY, $script], $arguments, [$startAt]),
                $descriptorSpec,
                $pipes
            );
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $workers[] = [$process, $pipes[1], $pipes[2]];
        }

        $outputs = [];
        foreach ($workers as [$process, $stdout, $stderr]) {
            $output = stream_get_contents($stdout);
            $error = stream_get_contents($stderr);
            fclose($stdout);
            fclose($stderr);
            $this->assertSame(0, proc_close($process), $error);
            $outputs[] = trim($output);
        }

        return $outputs;
    }
}
