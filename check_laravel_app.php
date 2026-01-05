<?php

require __DIR__.'/vendor/autoload.php';

$reflection = new ReflectionClass(\Illuminate\Console\Application::class);

echo "File: " . $reflection->getFileName() . "\n\n";

if ($reflection->hasMethod('addCommand')) {
    $method = $reflection->getMethod('addCommand');
    echo "addCommand method exists\n";
    echo "Declaring class: " . $method->getDeclaringClass()->getName() . "\n";
} else {
    echo "addCommand method does NOT exist in Laravel's Application\n";
}

if ($reflection->hasMethod('add')) {
    $method = $reflection->getMethod('add');
    echo "\nadd method exists\n";
    echo "Declaring class: " . $method->getDeclaringClass()->getName() . "\n";
}
