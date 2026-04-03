<?php

session_start();

// Database configuration
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '070817');
define('DB_NAME', 'ecommerce_db');

function getPDOConnection(): PDO
{
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        return new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        die('Connection failed: ' . $e->getMessage());
    }
}

$pdo = getPDOConnection();


$protocal = (!empty($_SERVER['HTTPS']) 
            && $_SERVER['HTTPS'] !=='off' ) ? 'https://' : 'http://';

$domain = $_SERVER['HTTP_HOST'];

$documentRoot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
$projectRoot = rtrim(str_replace('\\', '/', realpath(__DIR__ . '/..') ?: dirname(__DIR__)), '/');
$projectPath = '';

if ($documentRoot !== '' && $projectRoot !== '' && str_starts_with($projectRoot, $documentRoot)) {
    $projectPath = substr($projectRoot, strlen($documentRoot));
}

$base_url = $protocal . $domain . ($projectPath === '' || $projectPath === '/' ? '' : $projectPath);

function public_url(?string $path): string
{
    global $base_url;

    $trimmed = trim((string) $path);

    if ($trimmed === '') {
        return '';
    }

    if (preg_match('/^(?:[a-z][a-z0-9+\-.]*:|\/\/)/i', $trimmed)) {
        return $trimmed;
    }

    return rtrim($base_url, '/') . '/' . ltrim(str_replace('\\', '/', $trimmed), '/');
}

function resolve_image_url(?string $imagePath, string $fallbackPath = 'asset/image/no-image.svg'): string
{
    global $protocal, $domain;

    $normalized = trim((string) $imagePath);
    if ($normalized === '') {
        return public_url($fallbackPath);
    }

    $normalized = str_replace('\\', '/', $normalized);
    $normalized = preg_replace('#^(\./|\.\./)+#', '', $normalized);
    $normalized = preg_replace('#^assets/#i', 'asset/', $normalized);

    if (preg_match('/^(?:https?:)?\/\//i', $normalized) || str_starts_with($normalized, 'data:')) {
        return $normalized;
    }

    if (str_starts_with($normalized, '/')) {
        return $protocal . $domain . $normalized;
    }

    return public_url($normalized);
}
?>