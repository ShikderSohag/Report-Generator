<?php
declare(strict_types=1);

function readPdfDeliveryNote(string $path): array
{
    $safeScript = __DIR__ . '/tools/pdf_extract_safe.py';
    $script = is_file($safeScript) ? $safeScript : __DIR__ . '/tools/pdf_extract.py';
    if (!is_file($script)) {
        throw new RuntimeException('PDF extractor script was not found.');
    }

    $pythonCandidates = pdfPythonCandidates();
    $errors = [];

    foreach ($pythonCandidates as $python) {
        $checkCommand = buildPythonCommand($python, [
            '-c',
            'import os, sys; errors = []; ok = False; '
                . "\ntry:\n import pdfplumber\n ok = True\nexcept Exception as exc:\n errors.append(\"pdfplumber=\" + repr(exc))\n"
                . "try:\n import pypdf\n ok = True\nexcept Exception as exc:\n errors.append(\"pypdf=\" + repr(exc))\n"
                . "try:\n import PyPDF2\n ok = True\nexcept Exception as exc:\n errors.append(\"PyPDF2=\" + repr(exc))\n"
                . "print(\"executable=\" + sys.executable); print(\"version=\" + sys.version.replace(\"\\n\", \" \")); print(\"PYTHONPATH=\" + str(os.environ.get(\"PYTHONPATH\", \"\"))); print(\"; \".join(errors)); sys.exit(0 if ok else 1)",
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

        $json = extractJsonObject(trim(implode("\n", $output)));
        $data = json_decode($json, true);

        if (!is_array($data)) {
            $errors[] = buildCommand($python) . ': PDF extractor returned invalid JSON. Output: ' . trim(implode("\n", $output));
            continue;
        }

        if (empty($data['wono'])) {
            throw new RuntimeException('Could not find a delivery note / WO number in the PDF.');
        }

        return $data;
    }

    throw new RuntimeException(
        "Could not extract PDF data. Configure \$pythonPath in db.php to a Python that has pdfplumber, pypdf, or PyPDF2 installed. Details: "
        . implode("\n---\n", array_filter($errors))
    );
}

function pdfPythonCandidates(): array
{
    global $pythonPath;

    $candidates = [];

    $envPythonPath = getenv('PDF_PYTHON_PATH');
    if (!empty($envPythonPath)) {
        $candidates[] = [$envPythonPath];
    }

    if (!empty($pythonPath)) {
        $candidates[] = [$pythonPath];
    }

    $macBundledPython = '/Users/sohag/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/bin/python3';
    if (is_file($macBundledPython)) {
        $candidates[] = [$macBundledPython];
    }

    $bundledPython = 'C:\\Users\\Technical Engineer\\.cache\\codex-runtimes\\codex-primary-runtime\\dependencies\\python\\python.exe';
    if (is_file($bundledPython)) {
        $candidates[] = [$bundledPython];
    }

    foreach ([
        __DIR__ . '/venv/bin/python3',
        __DIR__ . '/.venv/bin/python3',
        '/usr/local/bin/python3',
        '/usr/bin/python3',
    ] as $linuxPython) {
        if (is_file($linuxPython)) {
            $candidates[] = [$linuxPython];
        }
    }

    $candidates[] = ['python3'];
    $candidates[] = ['python'];
    $candidates[] = ['py', '-3'];

    return array_values(array_unique($candidates, SORT_REGULAR));
}

function buildCommand(array $parts): string
{
    return implode(' ', array_map('escapeshellarg', $parts));
}

function extractJsonObject(string $output): string
{
    $start = strpos($output, '{');
    $end = strrpos($output, '}');

    if ($start === false || $end === false || $end < $start) {
        return $output;
    }

    return substr($output, $start, $end - $start + 1);
}

function buildPythonCommand(array $python, array $arguments): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        $pythonPath = 'C:\\Users\\Technical Engineer\\.cache\\codex-runtimes\\codex-primary-runtime\\dependencies\\python';
        $env = 'set "PYTHONIOENCODING=utf-8" && set "PYTHONPATH=' . $pythonPath . '" && ';
    } else {
        $home = getenv('HOME') ?: dirname(dirname(__DIR__));
        $env = 'HOME=' . escapeshellarg($home) . ' PYTHONIOENCODING=utf-8 ';
        $pythonPaths = array_filter([
            getenv('PDF_PYTHONPATH') ?: null,
            is_dir(__DIR__ . '/python-libs') ? __DIR__ . '/python-libs' : null,
        ]);

        if ($pythonPaths) {
            $env .= 'PYTHONPATH=' . escapeshellarg(implode(PATH_SEPARATOR, $pythonPaths)) . ' ';
        }
    }

    return $env . buildCommand(array_merge($python, $arguments));
}
