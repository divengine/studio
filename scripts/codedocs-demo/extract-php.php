<?php

/** Reflection-based extractor for these trusted, side-effect-free PHP examples. */
declare(strict_types=1);

$root = realpath($argv[1] ?? '') ?: throw new RuntimeException('Pass the php-shop directory.');
$project = $argv[2] ?? 'demo-shop-php';
// Explicit loading order for the small demo; this is not a general PHP parser.
foreach (['Product', 'Catalog', 'Checkout'] as $file) { require $root . '/src/' . $file . '.php'; }
function documentation(string|false $comment): array {
  $lines = preg_split('/\R/', preg_replace('/^\s*\/\*\*|\*\/\s*$/', '', $comment ?: ''));
  $text = []; $tags = [];
  foreach ($lines as $line) {
    $line = trim(preg_replace('/^\s*\*\s?/', '', $line));
    if (preg_match('/^@(\w+)\s*(.*)$/', $line, $match)) {
      $tags[] = ['kind' => $match[1], 'text' => $match[2]];
    }
    elseif ($line !== '') { $text[] = $line; }
  }
  return ['summary' => $text[0] ?? '', 'description' => implode("\n", $text), 'tags' => $tags];
}
function references(?ReflectionType $type, string $kind): array {
  if (!$type) { return []; }
  $types = $type instanceof ReflectionNamedType ? [$type] : $type->getTypes();
  $refs = [];
  foreach ($types as $part) {
    if ($part instanceof ReflectionNamedType && !$part->isBuiltin()) {
      $refs[] = ['kind' => $kind, 'target' => $part->getName(), 'external' => !str_starts_with($part->getName(), 'StudioDemo\\')];
    }
  }
  return $refs;
}
$units = [];
foreach (array_merge(get_declared_classes(), get_declared_interfaces()) as $name) {
  if (!str_starts_with($name, 'StudioDemo\\Shop\\')) { continue; }
  $class = new ReflectionClass($name);
  $path = 'src/' . basename($class->getFileName());
  $symbols = [['key' => $name, 'qualified_name' => $name, 'name' => $class->getShortName(), 'kind' => $class->isInterface() ? 'interface' : 'class', 'exported' => TRUE, 'signature' => ($class->isInterface() ? 'interface ' : 'class ') . $class->getShortName(), 'file' => $path, 'line' => $class->getStartLine(), 'doc' => documentation($class->getDocComment())]];
  foreach ($class->getMethods() as $method) {
    if ($method->getDeclaringClass()->getName() !== $name) { continue; }
    $params = []; $refs = references($method->getReturnType(), 'return');
    foreach ($method->getParameters() as $param) {
      $params[] = ['name' => $param->getName(), 'type' => (string) $param->getType(), 'optional' => $param->isOptional(), 'rest' => $param->isVariadic(), 'default' => $param->isDefaultValueAvailable() ? var_export($param->getDefaultValue(), TRUE) : NULL];
      array_push($refs, ...references($param->getType(), 'parameter'));
    }
    $signature = $method->getName() . '(' . implode(', ', array_map(fn ($p) => $p['type'] . ' $' . $p['name'], $params)) . ')' . ($method->getReturnType() ? ': ' . $method->getReturnType() : '');
    $symbols[] = ['key' => $name . '::' . $method->getName() . '(' . implode(',', array_column($params, 'type')) . ')', 'parent' => $name, 'kind' => $method->isConstructor() ? 'constructor' : 'method', 'name' => $method->getName(), 'visibility' => $method->isPublic() ? 'public' : ($method->isProtected() ? 'protected' : 'private'), 'signature' => $signature, 'type' => (string) $method->getReturnType(), 'file' => $path, 'line' => $method->getStartLine(), 'end_line' => $method->getEndLine(), 'doc' => documentation($method->getDocComment()), 'params' => $params, 'refs' => $refs];
  }
  foreach ($class->getProperties() as $property) {
    $symbols[] = ['key' => $name . '::$' . $property->getName(), 'parent' => $name, 'kind' => 'property', 'name' => $property->getName(), 'visibility' => $property->isPublic() ? 'public' : 'private', 'type' => (string) $property->getType(), 'doc' => documentation($property->getDocComment()), 'refs' => references($property->getType(), 'type')];
  }
  $units[] = ['key' => $path, 'kind' => 'file', 'name' => basename($path), 'path' => $path, 'language' => 'php', 'symbols' => $symbols];
}
$commit = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD'));
echo json_encode(['contract' => 'divengine.codedocs', 'contract_version' => '1.0', 'project' => $project, 'language' => 'php', 'producer' => 'studio-demo-reflection/1.0', 'commit' => $commit, 'branch' => 'main', 'units' => $units], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
