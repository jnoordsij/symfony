<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Serializer\Annotation;

use Symfony\Component\PropertyAccess\Exception\InvalidPropertyPathException;
use Symfony\Component\PropertyAccess\PropertyPath;
use Symfony\Component\Serializer\Exception\InvalidArgumentException;

/**
 * @author Tobias Bönner <tobi@boenner.family>
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_PROPERTY)]
final class SerializedPath
{
    private PropertyPath $serializedPath;

    public function __construct(string $serializedPath)
    {
        try {
            $this->serializedPath = new PropertyPath($serializedPath);
        } catch (InvalidPropertyPathException $pathException) {
            throw new InvalidArgumentException(sprintf('Parameter of annotation "%s" must be a valid property path.', self::class));
        }
    }

    public function getSerializedPath(): PropertyPath
    {
        return $this->serializedPath;
    }
}
