<?php

declare(strict_types=1);

namespace SourceBroker\T3api\Service;

use DateTime;
use DateTimeImmutable;
use Doctrine\Common\Annotations\AnnotationReader;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;
use SourceBroker\T3api\Annotation\Serializer\Exclude;
use SourceBroker\T3api\Annotation\Serializer\Groups;
use SourceBroker\T3api\Annotation\Serializer\MaxDepth;
use SourceBroker\T3api\Annotation\Serializer\ReadOnlyProperty;
use SourceBroker\T3api\Annotation\Serializer\SerializedName;
use SourceBroker\T3api\Annotation\Serializer\Type\TypeInterface;
use SourceBroker\T3api\Annotation\Serializer\VirtualProperty;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyTypeExtractorInterface;
use Symfony\Component\PropertyInfo\Type;
use Symfony\Component\Yaml\Yaml;

class SerializerMetadataService
{
    /**
     * @var string[]
     */
    protected static $runtimeGeneratedCache = [];

    /**
     * @param string $class
     *
     * @throws ReflectionException
     */
    public static function generateAutoloadForClass(string $class): void
    {
        if (self::isAutoloadGenerated($class) && !SerializerService::isDebugMode()) {
            return;
        }

        foreach (self::getClassHierarchy($class) as $reflectionClass) {
            if (in_array($reflectionClass->getName(), self::$runtimeGeneratedCache, true)) {
                continue;
            }

            $generatedMetadataFile = self::getAutogeneratedFilePath($reflectionClass->getName());

            $classMergedMetadata = array_replace_recursive(
                self::getForClass($reflectionClass),
                self::getMetadataFromMetadataDirs($reflectionClass->getName())
            );

            file_put_contents(
                $generatedMetadataFile,
                Yaml::dump([$reflectionClass->getName() => $classMergedMetadata], 99)
            );

            self::$runtimeGeneratedCache[] = $reflectionClass->getName();
        }
    }

    /**
     * This method should invert encoding done in static::encodeToSingleHandlerParam
     * @see \SourceBroker\T3api\Service\SerializerMetadataService::encodeToSingleHandlerParam()
     *
     * @param string $value
     * @return mixed
     */
    public static function decodeFromSingleHandlerParam(string $value)
    {
        if (is_string($value)) {
            /**
             * @noinspection JsonEncodingApiUsageInspection
             * Do not use JsonException and JSON_THROW_ON_ERROR as it was introduced in PHP 7.3.
             * Code below can be refactored after drop support for PHP 7.2.
             */
            $json = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $json;
            }
        }

        return $value;
    }

    /**
     * @param string $class
     *
     * @return string
     */
    protected static function getAutogeneratedFilePath(string $class): string
    {
        return SerializerService::getAutogeneratedMetadataDirectory() . '/'
            . str_replace('\\', '.', $class) . '.yml';
    }

    /**
     * @param string $class
     *
     * @return bool
     */
    protected static function isAutoloadGenerated(string $class): bool
    {
        return file_exists(self::getAutogeneratedFilePath($class));
    }

    /**
     * @param string $class
     * @return array
     */
    protected static function getMetadataFromMetadataDirs(string $class): array
    {
        static $parsedMetadata;

        if ($parsedMetadata === null) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['t3api']['serializerMetadataDirs'] as $serializerMetadataDir) {
                $files = glob(rtrim($serializerMetadataDir, '/') . '/*.yml');

                if (!empty($files)) {
                    foreach ($files as $file) {
                        $parsedMetadata = array_replace_recursive($parsedMetadata ?? [], Yaml::parseFile($file));
                    }
                }
            }
        }

        return $parsedMetadata[$class] ?? [];
    }

    /**
     * @param ReflectionClass $reflectionClass
     *
     * @return array
     */
    protected static function getForClass(ReflectionClass $reflectionClass): array
    {
        $annotationReader = new AnnotationReader();

        return [
            'properties' => self::getProperties($reflectionClass, $annotationReader),
            'virtual_properties' => self::getVirtualProperties($reflectionClass, $annotationReader),
        ];
    }

    /**
     * @param string $class
     * @throws ReflectionException
     * @return ReflectionClass[]
     */
    protected static function getClassHierarchy(string $class): array
    {
        $classes = [];
        $reflectionClass = new ReflectionClass($class);

        do {
            $classes[] = $reflectionClass;
            $reflectionClass = $reflectionClass->getParentClass();
        } while (false !== $reflectionClass);

        return array_reverse($classes, false);
    }

    /**
     * @param ReflectionClass $reflectionClass
     * @param AnnotationReader $annotationReader
     *
     * @return array
     */
    protected static function getProperties(ReflectionClass $reflectionClass, AnnotationReader $annotationReader): array
    {
        $properties = [];

        /** @var ReflectionProperty $property */
        foreach ($reflectionClass->getProperties() as $reflectionProperty) {
            $properties[$reflectionProperty->getName()] = self::getPropertyMetadataFromAnnotations(
                $annotationReader->getPropertyAnnotations($reflectionProperty)
            );
            if (empty($properties[$reflectionProperty->getName()]['type'])) {
                $type = self::getPropertyType($reflectionClass->getName(), $reflectionProperty->getName());

                if ($type !== null) {
                    $properties[$reflectionProperty->getName()]['type'] = $type;
                }
            }
        }

        return $properties;
    }

    /**
     * @param ReflectionClass $reflectionClass
     * @param AnnotationReader $annotationReader
     *
     * @return array
     */
    protected static function getVirtualProperties(
        ReflectionClass $reflectionClass,
        AnnotationReader $annotationReader
    ): array {
        $virtualProperties = [];

        /** @var ReflectionMethod $property */
        foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {
            /** @var VirtualProperty $virtualProperty */
            $virtualProperty = $annotationReader->getMethodAnnotation($reflectionMethod, VirtualProperty::class);

            if (!$virtualProperty) {
                continue;
            }

            if (strpos($reflectionMethod->getName(), 'is') === 0) {
                $accessorName = lcfirst(substr($reflectionMethod->getName(), 2));
            } elseif (strpos($reflectionMethod->getName(), 'get') === 0) {
                $accessorName = lcfirst(substr($reflectionMethod->getName(), 3));
            } elseif (strpos($reflectionMethod->getName(), 'has') === 0) {
                $accessorName = lcfirst(substr($reflectionMethod->getName(), 3));
            } else {
                $accessorName = $reflectionMethod->getName();
            }

            $propertyName = $virtualProperty->name ?: $accessorName;

            $virtualProperties[$reflectionMethod->getName()] = array_merge(
                [
                    'name' => $propertyName,
                    'serialized_name' => $propertyName,
                ],
                self::getPropertyMetadataFromAnnotations($annotationReader->getMethodAnnotations($reflectionMethod))
            );

            if (empty($virtualProperties[$reflectionMethod->getName()]['type'])) {
                $virtualProperties[$reflectionMethod->getName()]['type'] = self::getPropertyType(
                    $reflectionClass->getName(),
                    $accessorName
                );
            }
        }

        return $virtualProperties;
    }

    /**
     * @param string $className
     * @param string $propertyName
     * @return string
     */
    protected static function getPropertyType(
        string $className,
        string $propertyName
    ): ?string {
        foreach (self::getPropertyTypeExtractors() as $propertyTypeExtractor) {
            $types = $propertyTypeExtractor->getTypes($className, $propertyName);

            if (empty($types)) {
                continue;
            }

            return self::stringifyPropertyType(array_shift($types));
        }

        return null;
    }

    /**
     * @param Type $type
     *
     * @return string
     */
    protected static function stringifyPropertyType(Type $type): string
    {
        if ($type->isCollection()) {
            $collectionValueTypes = $type->getCollectionValueTypes();
            if (!empty($collectionValueTypes)) {
                $subTypes = array_map([self::class, 'stringifyPropertyType'], $collectionValueTypes);
                $subType = implode(', ', $subTypes);

                if ($type->getClassName()) {
                    return sprintf('%s<%s>', $type->getClassName(), $subType);
                }

                return sprintf('array<%s>', $subType);
            }

            return 'array';
        }

        if ($type->getClassName()) {
            if (is_a($type->getClassName(), DateTime::class, true)) {
                return sprintf(
                    'DateTime<\'%s\'>',
                    PHP_VERSION_ID >= 70300 ? DateTime::RFC3339_EXTENDED : 'Y-m-d\TH:i:s.uP'
                );
            }

            if (is_a($type->getClassName(), DateTimeImmutable::class, true)) {
                return sprintf(
                    'DateTimeImmutable<\'%s\'>',
                    PHP_VERSION_ID >= 70300 ? DateTime::RFC3339_EXTENDED : 'Y-m-d\TH:i:s.uP'
                );
            }

            return $type->getClassName();
        }

        return $type->getBuiltinType();
    }

    /**
     * @param object[] $annotations
     *
     * @return array
     */
    protected static function getPropertyMetadataFromAnnotations(array $annotations): array
    {
        $metadata = [];

        foreach ($annotations as $annotation) {
            if ($annotation instanceof Groups) {
                $metadata['groups'] = $annotation->groups;
            } elseif ($annotation instanceof TypeInterface) {
                $metadata['type'] = $annotation->getName();

                if (!empty($annotation->getParams())) {
                    $metadata['type'] .= sprintf('<%s>', static::encodeToHandlerParams($annotation->getParams()));
                }
            } elseif ($annotation instanceof ReadOnlyProperty) {
                $metadata['read_only'] = (bool)$annotation->readOnly;
            } elseif ($annotation instanceof Exclude) {
                if ($annotation->if !== '') {
                    $metadata['exclude_if'] = $annotation->if;
                } else {
                    $metadata['exclude'] = true;
                }
            } elseif ($annotation instanceof MaxDepth) {
                $metadata['max_depth'] = $annotation->depth;
            } elseif ($annotation instanceof SerializedName) {
                $metadata['serialized_name'] = $annotation->name;
            }
        }

        return $metadata;
    }

    /**
     * There is a reason why `\Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor` is returned as the first
     * extractor type. It is most flexible (especially for older PHP versions) and allows to specify more informations
     * than e.g. type hints.
     *
     * @return PropertyTypeExtractorInterface[]
     */
    protected static function getPropertyTypeExtractors(): array
    {
        static $propertyExtractors;

        if (is_null($propertyExtractors)) {
            $propertyExtractors = [
                new PhpDocExtractor(),
                new ReflectionExtractor(),
            ];
        }

        return $propertyExtractors;
    }

    protected static function encodeToHandlerParams(array $params): string
    {
        if (empty($params)) {
            return '';
        }

        $encodedParams = array_map('static::encodeToSingleHandlerParam', $params);

        return '\'' . implode('\',\'', $encodedParams) . '\'';
    }

    /**
     * This method should invert encoding done in static::decodeFromSingleHandlerParam
     * @see \SourceBroker\T3api\Service\SerializerMetadataService::decodeFromSingleHandlerParam()
     * @param $value
     * @return string
     */
    protected static function encodeToSingleHandlerParam($value): string
    {
        if (is_string($value) || is_numeric($value) || is_null($value)) {
            return (string)$value;
        }

        if (is_array($value)) {
            /**
             * @noinspection JsonEncodingApiUsageInspection
             * Do not use JsonException and JSON_THROW_ON_ERROR as it was introduced in PHP 7.3.
             * Code below can be refactored after drop support for PHP 7.2.
             */
            $json = json_encode(
                $value,
                JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS | JSON_HEX_TAG
            );

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Could not encode array parameter to json inside %s::%s',
                        static::class,
                        __METHOD__
                    ),
                    1600582676444
                );
            }

            return $json;
        }

        throw new InvalidArgumentException(
            sprintf('Unsupported handler parameter type'),
            1600582783504
        );
    }
}
