<?php

namespace Evence\Bundle\SoftDeleteableExtensionBundle\EventListener;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Dom\Entity;
use Emdesk\Base\Interfaces\SoftDelete;
use Evence\Bundle\SoftDeleteableExtensionBundle\Exception\OnSoftDeleteUnknownTypeException;
use Evence\Bundle\SoftDeleteableExtensionBundle\Mapping\Annotation\onSoftDelete;
use Gedmo\Mapping\ExtensionMetadataFactory;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\PropertyAccess\PropertyAccess;

use Secondred\Base\Interfaces\SoftDelete as SecondredSoftDelete;
use Emdesk\Base\Interfaces\SoftDelete as EmdeskSoftDelete;
use Zend\Log\LoggerServiceFactory;


/**
 * Soft delete listener class for onSoftDelete behaviour.
 *
 * @author Ruben Harms <info@rubenharms.nl>
 *
 * @link http://www.rubenharms.nl
 * @link https://www.github.com/RubenHarms
 */
class SoftDeleteListener
{
    use ContainerAwareTrait;

    private static array $softDeleteMap = [];

    /**
     * Build (and cache) the soft-delete config once per EM configuration.
     * We store: property name, type (CASCADE/SET NULL), and the relation annotations.
     */
    private function getSoftDeleteConfig(EntityManagerInterface $em): array
    {
        $hash = spl_object_hash($em->getConfiguration());
        if (!isset(self::$softDeleteMap[$hash])) {
            $reader = new \Doctrine\Common\Annotations\AnnotationReader();
            $map = [];

            foreach ($em->getMetadataFactory()->getAllMetadata() as $meta) {
                $rc = $meta->getReflectionClass();
                foreach ($rc->getProperties() as $p) {
                    /** @var \Evence\Bundle\SoftDeleteableExtensionBundle\Mapping\Annotation\onSoftDelete|null $anno */
                    $anno = $reader->getPropertyAnnotation($p, \Evence\Bundle\SoftDeleteableExtensionBundle\Mapping\Annotation\onSoftDelete::class);
                    if (!$anno) {
                        continue;
                    }

                    $m2o = $reader->getPropertyAnnotation($p, \Doctrine\ORM\Mapping\ManyToOne::class);
                    $m2m = $reader->getPropertyAnnotation($p, \Doctrine\ORM\Mapping\ManyToMany::class);

                    $map[$meta->getName()][] = [
                        'property'   => $p->getName(),
                        'type'       => strtoupper($anno->type),
                        'manyToOne'  => $m2o,
                        'manyToMany' => $m2m,
                        // store the declaring class namespace so we can resolve targetEntity later
                        'declaring_ns' => $rc->getNamespaceName(),
                    ];
                }
            }

            self::$softDeleteMap[$hash] = $map;
        }
        return self::$softDeleteMap[$hash];
    }

    /**
     * Resolve a targetEntity string (can be FQN, \FQN, or relative) to a class name.
     */
    private function resolveTargetClass(string $target, string $baseNs): ?string
    {
        if (class_exists($target))                 return $target;
        if (class_exists('\\' . $target))          return '\\' . $target;
        $relative = rtrim($baseNs, '\\') . '\\' . ltrim($target, '\\');
        if (class_exists($relative))               return $relative;
        return null;
    }

    /**
     * @param object|string $entity Class FQN or instance
     */
    public static function isSoftDeleteEntity($entity): bool
    {
        // If it's already an object, instanceof handles proxies just fine.
        if (is_object($entity)) {
            return $entity instanceof EmdeskSoftDelete || $entity instanceof SecondredSoftDelete;
        }

        // If it's a class name, check interface implementation.
        if (!is_string($entity)) {
            return false;
        }

        $class = ltrim($entity, '\\');
        if (!class_exists($class)) {
            return false;
        }

        // Unwrap Doctrine proxy FQNs if we’re given those as strings
        if (is_subclass_of($class, Proxy::class)) {
            $parent = get_parent_class($class);
            if ($parent) {
                $class = $parent;
            }
        }

        // is_subclass_of works for interfaces too
        return is_subclass_of($class, EmdeskSoftDelete::class)
            || is_subclass_of($class, SecondredSoftDelete::class);
    }

    /**
     * Soft-delete cascade handler (uses the cached config; no AnnotationReader here).
     *
     * @throws \Evence\Bundle\SoftDeleteableExtensionBundle\Exception\OnSoftDeleteUnknownTypeException
     */
    public function preSoftDelete(\Doctrine\ORM\Event\LifecycleEventArgs $args)
    {
        $em     = $args->getEntityManager();
        $uow    = $em->getUnitOfWork();
        $entity = $args->getEntity();

        $entityReflection = new \ReflectionObject($entity);
        $configs = $this->getSoftDeleteConfig($em);

        foreach ($configs as $namespace => $props) {
            // skip abstract mapped superclasses
            $meta = $em->getClassMetadata($namespace);
            $rc   = $meta->getReflectionClass();
            if ($rc->isAbstract()) {
                continue;
            }

            foreach ($props as $cfg) {
                $propertyName = $cfg['property'];
                $type         = $cfg['type'];                   // 'CASCADE' or 'SET NULL'
                $manyToOne    = $cfg['manyToOne'];
                $manyToMany   = $cfg['manyToMany'];

                // nothing to do if neither M2O nor M2M
                if (!$manyToOne && !$manyToMany) {
                    continue;
                }

                // resolve target entity as in original listener
                $relationship = $manyToOne ?: $manyToMany;
                $ns = $this->resolveTargetClass($relationship->targetEntity, $entityReflection->getNamespaceName());
                if (!$ns) {
                    // can't resolve target class, skip safely
                    continue;
                }

                // disable version filter if it’s active (your original behavior)
                $enableFilter = false;
                if ($em->getFilters()->isEnabled('ProjectVersionAware')) {
                    $em->getFilters()->disable('ProjectVersionAware');
                    $enableFilter = true;
                }

                try {
                    if ($manyToOne && $entity instanceof $ns) {
                        // --- ManyToOne branch ------------------------------------
                        if ($type === 'CASCADE') {
                            // Bulk-soft-delete related rows that point to $entity
                            // NOTE: adjust "softdeleteDate" to your actual property if different.
                            if (self::isSoftDeleteEntity($namespace)) {
                                echo 'deleted eneity:'.$namespace;
                                $em->createQuery(
                                    "UPDATE $namespace e
                                 SET e.deleted = true, e.softDeleteDate = :d
                                 WHERE e.$propertyName = :entity"
                                )
                                    ->setParameter('d', new \DateTime())
                                    ->setParameter('entity', $entity)
                                    ->execute();
                            }
                        } elseif ($type === 'SET NULL') {
                            // Null out FK in bulk (no hydration)
                            $em->createQuery(
                                "UPDATE $namespace e
                             SET e.$propertyName = NULL
                             WHERE e.$propertyName = :entity"
                            )
                                ->setParameter('entity', $entity)
                                ->execute();
                        } else {
                            throw new \Evence\Bundle\SoftDeleteableExtensionBundle\Exception\OnSoftDeleteUnknownTypeException($type);
                        }
                    } elseif ($manyToMany) {
                        // --- ManyToMany branch (kept close to your original) -----
                        if ($type === 'SET NULL') {
                            throw new \Exception('SET NULL is not supported for ManyToMany relationships');
                        }

                        // we still need the join info; reuse your original logic
                        $reader = new \Doctrine\Common\Annotations\AnnotationReader();
                        /** @var \Doctrine\ORM\Mapping\JoinTable|null $joinTable */
                        $joinTable = $reader->getPropertyAnnotation(
                            $rc->getProperty($propertyName),
                            \Doctrine\ORM\Mapping\JoinTable::class
                        );
                        if (!$joinTable) {
                            throw new \Exception('No joinTable found for the relationship ' . $namespace . '#' . $propertyName);
                        }

                        $columns         = $joinTable->joinColumns;
                        $inversedColumns = $joinTable->inverseJoinColumns;
                        if (count($columns) > 1 || count($inversedColumns) > 1) {
                            throw new \Exception('Only one joinColumn/inverseJoinColumn is supported!');
                        }

                        /** @var \Doctrine\ORM\Mapping\JoinColumn $joinColumn */
                        $joinColumn = $columns[0];
                        $joinProperty = $this->getPropertyByColumName($rc, $joinColumn);

                        /** @var \Doctrine\ORM\Mapping\JoinColumn $inversedColumn */
                        $inversedColumn = $inversedColumns[0];
                        $inversedJoinProperty = $this->getPropertyByColumName($entityReflection, $inversedColumn);

                        if (!$joinProperty || !$inversedJoinProperty) {
                            throw new \Exception('No joinColumn found for the relationship between ' . $ns . ' and ' . get_class($entity));
                        }

                        $propertyAccessor = \Symfony\Component\PropertyAccess\PropertyAccess::createPropertyAccessor();
                        $joinValue = $propertyAccessor->getValue($entity, $inversedJoinProperty->name);

                        $qb = $em->getRepository($namespace)->createQueryBuilder('q')->join('q.' . $propertyName, 'j');
                        $qb->where($qb->expr()->eq('j.' . $joinProperty->name, $joinValue));
                        $objects = $qb->getQuery()->getResult();

                        if ($objects) {
                            foreach ($objects as $object) {
                                $softDelete = ($object instanceof \Emdesk\Base\Interfaces\SoftDelete)
                                    || ($object instanceof \Secondred\Base\Interfaces\SoftDelete);

                                if ($type === 'CASCADE') {
                                    if ($softDelete) {
                                        $this->softDeleteCascade($em, null, $object);
                                    } else {
                                        $em->remove($object);
                                    }
                                } else {
                                    throw new \Evence\Bundle\SoftDeleteableExtensionBundle\Exception\OnSoftDeleteUnknownTypeException($type);
                                }
                            }
                        }
                    }
                } finally {
                    if ($enableFilter) {
                        $em->getFilters()->enable('ProjectVersionAware');
                    }
                }
            }
        }
    }

    /**
     * @param EntityManagerInterface $em
     * @param $config
     * @param SoftDeleteEmdesk|SoftDeleteSecondred $object
     */
    protected function softDeleteCascade($em, $config, $object)
    {
        if ($object->isDeleted()) {
            return;
        }

        // remove next level
        $em->remove($object);
    }

    private function getPropertyByColumName(\ReflectionClass $entityReflection, $name){

        $reader = new AnnotationReader();

        foreach ($entityReflection->getProperties() as $p) {
            /** @var $column Column */
            if (($id = $reader->getPropertyAnnotation($p, Id::class)) &&
                ($column = $reader->getPropertyAnnotation($p, Column::class)) &&
                $column->name == $name
            ) {

                return $p;
            }
        }
    }
}
