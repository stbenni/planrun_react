<?php
/**
 * Браузерный renderer карточек шаринга через Node.js + Playwright.
 *
 * Возвращает PNG, собранный из HTML/CSS шаблона. Если renderer недоступен или
 * завершился ошибкой, вызывающий код должен использовать fallback.
 */

class WorkoutShareCardBrowserRendererService {
    private string $repoRoot;
    private string $scriptPath;
    private string $nodeBinary;

    public function __construct(?string $repoRoot = null, ?string $nodeBinary = null) {
        $this->repoRoot = $repoRoot ?: dirname(__DIR__, 2);
        $this->scriptPath = $this->repoRoot . '/planrun-backend/scripts/render_workout_share_card.mjs';
        $this->nodeBinary = $nodeBinary ?: trim((string) getenv('NODE_BINARY')) ?: 'node';
    }

    public function isAvailable(): bool {
        return is_file($this->scriptPath);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{body: string, contentType: string}
     */
    public function render(array $payload): array {
        if (!$this->isAvailable()) {
            throw new RuntimeException('Browser renderer скрипт не найден.');
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Не удалось сериализовать payload карточки шаринга.');
        }

        $inputPath = tempnam(sys_get_temp_dir(), 'planrun-share-input-');
        if ($inputPath === false) {
            throw new RuntimeException('Не удалось создать временный JSON для renderer.');
        }

        $outputBase = tempnam(sys_get_temp_dir(), 'planrun-share-output-');
        if ($outputBase === false) {
            @unlink($inputPath);
            throw new RuntimeException('Не удалось создать временный PNG для renderer.');
        }

        $outputPath = $outputBase . '.png';
        @unlink($outputBase);

        try {
            if (@file_put_contents($inputPath, $json) === false) {
                throw new RuntimeException('Не удалось записать payload карточки шаринга.');
            }

            [$exitCode, $combinedOutput] = $this->runProcess([
                $this->nodeBinary,
                $this->scriptPath,
                '--input',
                $inputPath,
                '--output',
                $outputPath,
            ]);

            if ($exitCode !== 0) {
                $details = trim($combinedOutput);
                if ($details === '') {
                    $details = 'Node renderer завершился с ошибкой без подробностей.';
                }
                throw new RuntimeException($details);
            }

            if (!is_file($outputPath)) {
                throw new RuntimeException('Browser renderer не создал PNG-файл.');
            }

            $body = @file_get_contents($outputPath);
            if ($body === false || $body === '') {
                throw new RuntimeException('Не удалось прочитать PNG, созданный browser renderer.');
            }

            return [
                'body' => $body,
                'contentType' => 'image/png',
            ];
        } finally {
            @unlink($inputPath);
            @unlink($outputPath);
        }
    }

    /**
     * @param array<int, string> $command
     * @return array{0:int,1:string}
     */
    private function runProcess(array $command): array {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptorSpec, $pipes, $this->repoRoot);
        if (!is_resource($process)) {
            throw new RuntimeException('Не удалось запустить Node.js renderer процесс.');
        }

        try {
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $combined = trim((string) $stderr . "\n" . (string) $stdout);
            return [$exitCode, $combined];
        } catch (Throwable $e) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_terminate($process);
            proc_close($process);
            throw $e;
        }
    }
}
