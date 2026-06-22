<?php
declare(strict_types=1);

function readPdfDeliveryNote(string $path): array
{
    $script = __DIR__ . '/tools/pdf_extract.py';
    if (!is_file($script)) {
        throw new RuntimeException('PDF extractor script was not found.');
    }

    $pythonCandidates = pdfPythonCandidates();
    $errors = [];

    foreach ($pythonCandidates as $python) {
        $checkCommand = buildPythonCommand($python, [
            '-c',
            'import pdfplumber',
        ]);
        $checkOutput = [];
        $checkExitCode = 0;
        exec($checkCommand . ' 2>&1', $checkOutput, $checkExitCode);

        if ($checkExitCode !== 0) {
            $errors[] = buildCommand($python) . ': ' . trim(implode("\n", $checkOutput));
            continue;
        }

        $command = buildPythonCommand($python, [$script, $path]);
        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            $errors[] = buildCommand($python) . ': ' . trim(implode("\n", $output));
            continue;
        }

        $json = trim(implode("\n", $output));
        $data = json_decode($json, true);

        if (!is_array($data)) {
            $errors[] = buildCommand($python) . ': PDF extractor returned invalid JSON.';
            continue;
        }

        if (empty($data['wono'])) {
            throw new RuntimeException('Could not find a delivery note / WO number in the PDF.');
        }

        return $data;
    }

    throw new RuntimeException(
        "Could not extract PDF data. Configure \$pythonPath in db.php to a Python that has pdfplumber installed. Details: "
        . implode("\n---\n", array_filter($errors))
    );
}

function pdfPythonCandidates(): array
{
    global $pythonPath;

    $candidates = [];

    if (!empty($pythonPath)) {
        $candidates[] = [$pythonPath];
    }

    $bundledPython = 'C:\\Users\\Technical Engineer\\.cache\\codex-runtimes\\codex-primary-runtime\\dependencies\\python\\python.exe';
    if (is_file($bundledPython)) {
        $candidates[] = [$bundledPython];
    }

    $candidates[] = ['python'];
    $candidates[] = ['py', '-3'];

    return $candidates;
}

function buildCommand(array $parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

function buildPythonCommand(array $python, array $arguments): string
{
    $pythonPath = 'C:\\Users\\Technical Engineer\\.cache\\codex-runtimes\\codex-primary-runtime\\dependencies\\python';
    $env = 'set "PYTHONIOENCODING=utf-8" && set "PYTHONPATH=' . $pythonPath . '" && ';

    return $env . buildCommand(array_merge($python, $arguments));
}
