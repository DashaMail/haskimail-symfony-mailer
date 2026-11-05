<?php

declare(strict_types=1);

$header = <<<'TXT'
This file is part of the haskimail/symfony-mailer package.

For the full copyright and license information, please view the LICENSE
file that was distributed with this source code.
TXT;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP81Migration' => true,
        'declare_strict_types' => true,
        'header_comment' => ['header' => $header],
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced', 'strict' => true],
        'global_namespace_import' => ['import_classes' => false, 'import_constants' => false, 'import_functions' => false],
        'php_unit_strict' => true,
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
        'phpdoc_to_comment' => false,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters', 'match']],
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
    ])
    ->setFinder(
        (new PhpCsFixer\Finder())
            ->in([__DIR__.'/src', __DIR__.'/tests']),
    );
