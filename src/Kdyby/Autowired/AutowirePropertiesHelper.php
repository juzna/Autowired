<?php

/**
 * This file is part of the Kdyby (http://www.kdyby.org)
 *
 * Copyright (c) 2008 Filip Procházka (filip@prochazka.su)
 *
 * For the full copyright and license information, please view the file license.txt that was distributed with this source code.
 */

namespace Kdyby\Autowired;

use Nette;
use Nette\Reflection\Method;
use Nette\Reflection\Property;
use Nette\Reflection\ClassType;
use Nette\Utils\Strings;



/**
 * Automagically autowire properties
 *
 * @author Filip Procházka <filip@prochazka.su>
 */
class AutowirePropertiesHelper extends Nette\Object
{

	/**
	 * @var Nette\DI\Container
	 */
	private $container;

	/**
	 * @var bool
	 */
	private $strict;



	public function __construct(Nette\DI\Container $container, $strict = TRUE)
	{
		$this->container = $container;
		$this->strict = $strict;
	}



	/**
	 * @param Nette\Application\UI\Presenter|object $object
	 * @throws MemberAccessException
	 * @throws MissingServiceException
	 * @throws InvalidStateException
	 * @throws UnexpectedValueException
	 */
	public function injectHerePlease($object)
	{
		if ($this->strict && !$object instanceof Nette\Application\UI\PresenterComponent) {
			throw new MemberAccessException('Magic can be used only in descendants of PresenterComponent.');
		}

		$cache = new Nette\Caching\Cache($this->container->getByType('Nette\Caching\IStorage'), 'Kdyby.Autowired.AutowireProperties-juzna');
		$properties = $cache->load($class = get_class($object), function(&$dp) use ($class) {
			return $this->parseProperties($class, $dp);
		});

		// inject them all
		foreach ($properties as $property) {
			$rp = new Property($property['class'], $property['name']);
			$rp->setAccessible(TRUE);
			$rp->setValue($object, $this->createInstance($property));
		}
	}



	/**
	 * Find property definitions for a given class (so that it can be cached)
	 * @param string $class
	 * @param array $dp See Cache::save()
	 * @return array [ { class, name, type, factory, arguments } ]
	 */
	private function parseProperties($class, &$dp = array())
	{
		$properties = array();

		$rc = new ClassType($class);
		$ignore = class_parents('Nette\Application\UI\Presenter') + array('ui' => 'Nette\Application\UI\Presenter');
		foreach ($rc->getProperties() as $prop) {
			/** @var Property $prop */
			if (!$this->validateProperty($prop, $ignore)) {
				continue;
			}

			$properties[] = $this->resolveProperty($prop);
		}


		// cache deps
		$files = array_map(function ($class) {
			return ClassType::from($class)->getFileName();
		}, array_diff(array_values(class_parents($class) + array('me' => $class)), $ignore));

		$files[] = ClassType::from($this->container)->getFileName();

		$dp[Nette\Caching\Cache::FILES] = $files;


		return array_filter($properties);
	}



	/**
	 * @param array $property Property definition
	 * @throws MemberAccessException
	 * @return mixed
	 */
	private function createInstance($property)
	{
		if (!empty($property['factory'])) {
			list ($className, $methodName) = $property['factory'];
			$factory = callback($this->container->getService($className), $methodName);
			return $factory->invokeArgs($property['arguments']);

		} else {
			return $this->container->getByType($property['type']);
		}
	}



	private function validateProperty(Property $property, array $ignore)
	{
		if (in_array($property->getDeclaringClass()->getName(), $ignore)) {
			return FALSE;
		}

		foreach ($property->getAnnotations() as $name => $value) {
			if (!in_array(Strings::lower($name), array('autowire', 'autowired'), TRUE)) {
				continue;
			}

			if (Strings::lower($name) !== $name || $name !== 'autowire') {
				throw new UnexpectedValueException("Annotation @$name on $property should be fixed to lowercase @autowire.");
			}

			if ($this->strict && $property->isPrivate()) {
				throw new MemberAccessException("Autowired properties must be protected or public. Please fix visibility of $property or remove the @autowire annotation.");
			}

			return TRUE;
		}

		return FALSE;
	}



	/**
	 * Trala lala?? TODO
	 *
	 * @param string $type
	 * @return string|bool
	 */
	private function findByTypeForProperty($type)
	{
		if (method_exists($this->container, 'findByType')) {
			$found = $this->container->findByType($type);

			return reset($found);
		}

		$type = ltrim(strtolower($type), '\\');

		return !empty($this->container->classes[$type])
			? $this->container->classes[$type]
			: FALSE;
	}



	/**
	 * Parse out property info which will be needed for autowiring
	 *
	 * @param Property $prop
	 * @return array|NULL { class, name, type, factory, arguments }
	 * @throws MissingServiceException
	 * @throws UnexpectedValueException
	 */
	private function resolveProperty(Property $prop)
	{
		$type = $this->resolveAnnotationClass($prop, $prop->getAnnotation('var'), 'var');
		$metadata = array(
			'class' => $prop->declaringClass->name,
			'name' => $prop->name,
			'value' => NULL,
			'type' => $type,
		);

		if (($args = (array) $prop->getAnnotation('autowire')) && !empty($args['factory'])) {
			if (strpos($args['factory'], '::') !== FALSE) {
				list($factoryClass, $factoryMethodName) = explode('::', $args['factory'], 2);
			} else {
				$factoryClass = $args['factory'];
				$factoryMethod = 'create';
			}
			$factoryType = $this->resolveAnnotationClass($prop, $factoryClass, 'autowire');

			if (!$this->findByTypeForProperty($factoryType)) {
				throw new MissingServiceException("Factory of type \"$factoryType\" not found for $prop in annotation @autowire.");
			}

			$factoryMethod = Method::from($factoryType, $factoryMethodName);
			$createsType = $this->resolveAnnotationClass($factoryMethod, $factoryMethod->getAnnotation('return'), 'return');
			if ($createsType !== $type) {
				throw new UnexpectedValueException("The property $prop requires $type, but factory of type $factoryType, that creates $createsType was provided.");
			}

			unset($args['factory']);
			$metadata['arguments'] = array_values($args);
			$metadata['factory'] = array($this->findByTypeForProperty($factoryType), $factoryMethodName);

		} elseif (!$this->findByTypeForProperty($type)) {
			throw new MissingServiceException("Service of type \"$type\" not found for $prop in annotation @var.");
		}

		return $metadata;
	}



	private function resolveAnnotationClass(\Reflector $prop, $annotationValue, $annotationName)
	{
		/** @var Property|Method $prop */

		if (!$type = ltrim($annotationValue, '\\')) {
			throw new InvalidStateException("Missing annotation @{$annotationName} with typehint on {$prop}.");
		}

		if (!class_exists($type) && !interface_exists($type)) {
			if (substr(func_get_arg(1), 0, 1) === '\\') {
				throw new MissingClassException("Class \"$type\" was not found, please check the typehint on {$prop} in annotation @{$annotationName}.");
			}

			if (!class_exists($type = $prop->getDeclaringClass()->getNamespaceName() . '\\' . $type) && !interface_exists($type)) {
				throw new MissingClassException("Neither class \"" . func_get_arg(1) . "\" or \"{$type}\" was found, please check the typehint on {$prop} in annotation @{$annotationName}.");
			}
		}

		return ClassType::from($type)->getName();
	}

}
