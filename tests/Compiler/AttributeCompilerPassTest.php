<?php

namespace App\Tests\Compiler;

use Flow\Compiler\AttributeCompilerPass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;

final class AttributeCompilerPassTest extends TestCase
{
    public function testCompilerPassMatchesSymfonyProcessSignature(): void
    {
        $process = new ReflectionMethod(AttributeCompilerPass::class, 'process');

        self::assertContains(CompilerPassInterface::class, class_implements(AttributeCompilerPass::class));
        self::assertTrue($process->hasReturnType());
        self::assertSame('void', (string) $process->getReturnType());
    }
}
