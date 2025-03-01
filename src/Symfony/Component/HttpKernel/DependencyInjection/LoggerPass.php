<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpKernel\DependencyInjection;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Log\Logger;

/**
 * Registers the default logger if necessary.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
class LoggerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $container->setAlias(LoggerInterface::class, 'logger');

        if ($container->has('logger')) {
            return;
        }

        if ($debug = $container->getParameter('kernel.debug')) {
            // Build an expression that will be equivalent to `!in_array(PHP_SAPI, ['cli', 'phpdbg'])`
            $debug = (new Definition('bool'))
                ->setFactory('in_array')
                ->setArguments([
                    (new Definition('string'))->setFactory('constant')->setArguments(['PHP_SAPI']),
                    ['cli', 'phpdbg'],
                ]);
            $debug = (new Definition('bool'))
                ->setFactory('in_array')
                ->setArguments([$debug, [false]]);
        }

        $container->register('logger', Logger::class)
            ->setArguments([null, null, null, new Reference(RequestStack::class), $debug]);
    }
}
