<?php

require __DIR__ . '/vendor/autoload.php';

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Configuration
 */
$inputDir = __DIR__ . '/src';

/**
 * AST Visitor to collect architecture metadata
 */
class ArchitectureCollector extends NodeVisitorAbstract
{
    public array $namespaces = [];
    private ?string $currentNamespace = null;

    public function enterNode(Node $node)
    {
        if ($node instanceof Namespace_) {
            $this->currentNamespace = $node->name ? $node->name->toString() : 'Global';
            if (!isset($this->namespaces[$this->currentNamespace])) {
                $this->namespaces[$this->currentNamespace] = [
                    'classes' => [],
                    'interfaces' => [],
                    'traits' => [],
                    'enums' => [],
                ];
            }
        }

        if ($node instanceof Class_ || $node instanceof Interface_ || $node instanceof Trait_ || $node instanceof Enum_) {
            $ns = $this->currentNamespace ?? 'Global';
            if (!isset($this->namespaces[$ns])) {
                $this->namespaces[$ns] = ['classes' => [], 'interfaces' => [], 'traits' => [], 'enums' => []];
            }

            $name = $node->name?->toString() ?? 'Anonymous';

            $type = match (true) {
                $node instanceof Class_ => 'classes',
                $node instanceof Interface_ => 'interfaces',
                $node instanceof Trait_ => 'traits',
                default => 'enums',
            };

            $methods = [];
            $properties = [];
            $dependencies = [];
            $cases = [];

            foreach ($node->stmts as $stmt) {
                // Collect Enum Cases
                if ($stmt instanceof EnumCase) {
                    $cases[] = $stmt->name->toString();
                }

                // Collect Properties
                if ($stmt instanceof Property) {
                    $propType = $this->getTypeString($stmt->type);
                    foreach ($stmt->props as $prop) {
                        $properties[] = [
                            'name' => $prop->name->toString(),
                            'type' => $propType,
                            'visibility' => $stmt->isPublic() ? 'public' : ($stmt->isProtected() ? 'protected' : 'private')
                        ];
                    }
                }

                // Collect Methods
                if ($stmt instanceof ClassMethod && $stmt->isPublic()) {
                    $methodName = $stmt->name->toString();
                    $params = [];

                    foreach ($stmt->params as $param) {
                        $paramType = $this->getTypeString($param->type);
                        $params[] = [
                            'name' => $param->var instanceof Node\Expr\Variable ? (string)$param->var->name : (string)$param->var,
                            'type' => $paramType
                        ];

                        if ($methodName === '__construct' && $paramType && !$this->isBuiltinType($paramType)) {
                            foreach (preg_split('/[|&]/', $paramType) as $typePart) {
                                $typePart = ltrim($typePart, '?');
                                if (!$this->isBuiltinType($typePart)) {
                                    $dependencies[] = $typePart;
                                }
                            }
                        }
                    }

                    $methods[] = [
                        'name' => $methodName,
                        'parameters' => $params,
                        'returnType' => $this->getTypeString($stmt->returnType)
                    ];
                }
            }

            $this->namespaces[$ns][$type][$name] = [
                'methods' => $methods,
                'properties' => $properties,
                'dependencies' => array_unique($dependencies),
                'cases' => $cases
            ];
        }

        return null;
    }

    public function getTypeString($type): ?string
    {
        if ($type === null) return null;
        if ($type instanceof Node\Identifier) return $type->toString();
        if ($type instanceof Node\Name) return $type->toString();
        if ($type instanceof Node\NullableType) return '?' . $this->getTypeString($type->type);
        if ($type instanceof Node\UnionType) {
            return implode('|', array_map([$this, 'getTypeString'], $type->types));
        }
        if ($type instanceof Node\IntersectionType) {
            return implode('&', array_map([$this, 'getTypeString'], $type->types));
        }
        return (string)$type;
    }

    private function isBuiltinType(string $type): bool
    {
        $builtins = [
            'string', 'int', 'float', 'bool', 'array', 'object', 'callable',
            'iterable', 'mixed', 'void', 'never', 'false', 'null', 'self', 'static', 'parent'
        ];
        $type = ltrim($type, '?');
        return in_array(strtolower($type), $builtins);
    }
}

/**
 * Helper to determine if it's an entity
 */
function isEntity($className, $nsName) {
    return str_contains(strtolower($className), 'entity') || str_contains(strtolower($nsName), 'entity');
}

/**
 * Execution Logic
 */
if (!is_dir($inputDir)) {
    fwrite(STDERR, "Error: Directory $inputDir does not exist.\n");
    exit(1);
}

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($inputDir));
$phpFiles = [];
foreach ($files as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $phpFiles[] = $file->getPathname();
    }
}

$parser = (new ParserFactory())->createForNewestSupportedVersion();
$collector = new ArchitectureCollector();
$traverser = new NodeTraverser();
$traverser->addVisitor($collector);

foreach ($phpFiles as $filePath) {
    try {
        $code = file_get_contents($filePath);
        $stmts = $parser->parse($code);
        if ($stmts) {
            $traverser->traverse($stmts);
        }
    } catch (Error $e) {
        // Silently skip parse errors to maintain clean Markdown output
    }
}

// Sort results
ksort($collector->namespaces);
foreach ($collector->namespaces as &$ns) {
    ksort($ns['classes']);
    ksort($ns['interfaces']);
    ksort($ns['traits']);
    ksort($ns['enums']);
}

// Generate Full Markdown Output
$md = "# Codebase Architecture Map\n\n";

// Class List
$md .= "## Class List\n\n";
foreach ($collector->namespaces as $nsName => $content) {
    foreach (['classes', 'interfaces', 'traits', 'enums'] as $type) {
        foreach ($content[$type] as $name => $data) {
            $md .= "- " . ($nsName !== 'Global' ? "$nsName\\" : "") . "$name\n";
        }
    }
}

// Architecture
foreach ($collector->namespaces as $nsName => $content) {
    $md .= "## Namespace: `$nsName`\n\n";

    if (empty($content['classes']) && empty($content['interfaces']) && empty($content['traits']) && empty($content['enums'])) {
        continue;
    }

    foreach (['classes' => 'Class', 'interfaces' => 'Interface', 'traits' => 'Trait', 'enums' => 'Enum'] as $key => $label) {
        foreach ($content[$key] as $name => $data) {
            $md .= "### $label: `$name`\n\n";

            if (isEntity($name, $nsName)  && $key === 'classes') {
                $md .= "**Properties:**\n\n";
                if (empty($data['properties'])) {
                    $md .= "- *None*\n";
                } else {
                    foreach ($data['properties'] as $p) {
                        $md .= "- `{$p['visibility']} " . ($p['type'] ?? 'mixed') . " \${$p['name']}`\n";
                    }
                }
                $md .= "\n";
            }

            if (!empty($data['cases'])) {
                $md .= "**Enum Cases:**\n\n";
                foreach ($data['cases'] as $case) {
                    $md .= "- `$case`\n";
                }
                $md .= "\n";
            }

            $md .= "**Public Methods:**\n\n";
            if (empty($data['methods'])) {
                $md .= "- *None*\n\n";
            } else {
                foreach ($data['methods'] as $m) {
                    $params = array_map(fn($p) => ($p['type'] ?? 'mixed') . ' $' . $p['name'], $m['parameters']);
                    $md .= "- `{$m['name']}(" . implode(', ', $params) . "): " . ($m['returnType'] ?? 'void') . "`\n";
                }
                $md .= "\n";
            }
            $md .= "---\n\n";
        }
    }
}

// Dependency Graph
$md .= "## Dependency Graph\n\n";
$md .= "```mermaid\ngraph TD\n";
$allDeps = [];
foreach ($collector->namespaces as $nsName => $content) {
    foreach ($content['classes'] as $name => $data) {
        foreach ($data['dependencies'] as $dep) {
            $safeName = str_replace(['\\', '-'], '_', $name);
            $safeDep = str_replace(['\\', '-'], '_', $dep);
            $allDeps[] = '    ' . $safeName . '["' . $name . '"] --> ' . $safeDep . '["' . $dep . '"]';
        }
    }
}
sort($allDeps);
$md .= implode("\n", array_unique($allDeps)) . "\n```\n\n";

// Output Markdown
echo $md;
