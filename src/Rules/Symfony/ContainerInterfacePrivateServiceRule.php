<?php declare(strict_types = 1);

namespace PHPStan\Rules\Symfony;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflection\Adapter\FakeReflectionAttribute;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionAttribute;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Symfony\ServiceDefinition;
use PHPStan\Symfony\ServiceMap;
use PHPStan\TrinaryLogic;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use function class_exists;
use function get_class;
use function sprintf;

/**
 * @implements Rule<MethodCall>
 */
final class ContainerInterfacePrivateServiceRule implements Rule
{

	/** @var ServiceMap */
	private $serviceMap;

	public function __construct(ServiceMap $symfonyServiceMap)
	{
		$this->serviceMap = $symfonyServiceMap;
	}

	public function getNodeType(): string
	{
		return MethodCall::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		if (!$node->name instanceof Node\Identifier) {
			return [];
		}

		if ($node->name->name !== 'get' || !isset($node->getArgs()[0])) {
			return [];
		}

		$argType = $scope->getType($node->var);

		$isTestContainerType = (new ObjectType('Symfony\Bundle\FrameworkBundle\Test\TestContainer'))->isSuperTypeOf($argType);
		$isOldServiceSubscriber = (new ObjectType('Symfony\Component\DependencyInjection\ServiceSubscriberInterface'))->isSuperTypeOf($argType);
		$isServiceSubscriber = $this->isServiceSubscriber($argType, $scope);
		$isServiceLocator = (new ObjectType('Symfony\Component\DependencyInjection\ServiceLocator'))->isSuperTypeOf($argType);
		if ($isTestContainerType->yes() || $isOldServiceSubscriber->yes() || $isServiceSubscriber->yes() || $isServiceLocator->yes()) {
			return [];
		}

		$isControllerType = (new ObjectType('Symfony\Bundle\FrameworkBundle\Controller\Controller'))->isSuperTypeOf($argType);
		$isAbstractControllerType = (new ObjectType('Symfony\Bundle\FrameworkBundle\Controller\AbstractController'))->isSuperTypeOf($argType);
		$isContainerType = (new ObjectType('Symfony\Component\DependencyInjection\ContainerInterface'))->isSuperTypeOf($argType);
		$isPsrContainerType = (new ObjectType('Psr\Container\ContainerInterface'))->isSuperTypeOf($argType);
		if (
			!$isControllerType->yes()
			&& !$isAbstractControllerType->yes()
			&& !$isContainerType->yes()
			&& !$isPsrContainerType->yes()
		) {
			return [];
		}

		$serviceId = $this->serviceMap::getServiceIdFromNode($node->getArgs()[0]->value, $scope);
		if ($serviceId === null) {
			return [];
		}

		$service = $this->serviceMap->getService($serviceId);
		if (!$service instanceof ServiceDefinition) {
			return [];
		}

		$isContainerInterfaceType = $isContainerType->yes() || $isPsrContainerType->yes();
		if (
			$isContainerInterfaceType &&
			$this->isAutowireLocator($node, $scope, $service)
		) {
			return [];
		}

		if (!$service->isPublic()) {
			return [
				RuleErrorBuilder::message(sprintf('Service "%s" is private.', $serviceId))
					->identifier('symfonyContainer.privateService')
					->build(),
			];
		}

		return [];
	}

	private function isServiceSubscriber(Type $containerType, Scope $scope): TrinaryLogic
	{
		$serviceSubscriberInterfaceType = new ObjectType('Symfony\Contracts\Service\ServiceSubscriberInterface');
		$isContainerServiceSubscriber = $serviceSubscriberInterfaceType->isSuperTypeOf($containerType);
		$classReflection = $scope->getClassReflection();
		if ($classReflection === null) {
			return $isContainerServiceSubscriber;
		}
		$containedClassType = new ObjectType($classReflection->getName());
		return $isContainerServiceSubscriber->or($serviceSubscriberInterfaceType->isSuperTypeOf($containedClassType));
	}

	private function isAutowireLocator(Node $node, Scope $scope, ServiceDefinition $service): bool
	{
		if (!class_exists('Symfony\\Component\\DependencyInjection\\Attribute\\AutowireLocator')) {
			return false;
		}

		if (
			!$node instanceof MethodCall
		) {
			return false;
		}

		$nodeParentProperty = $node->var;

		if (!$nodeParentProperty instanceof Node\Expr\PropertyFetch) {
			return false;
		}

		$nodeParentPropertyName = $nodeParentProperty->name;

		if (!$nodeParentPropertyName instanceof Node\Identifier) {
			return false;
		}

		$containerInterfacePropertyName = $nodeParentPropertyName->name;
		$scopeClassReflection = $scope->getClassReflection();

		if (!$scopeClassReflection instanceof ClassReflection) {
			return false;
		}

		$containerInterfacePropertyReflection = $scopeClassReflection
			->getNativeProperty($containerInterfacePropertyName);
		$classPropertyReflection = $containerInterfacePropertyReflection->getNativeReflection();
		$autowireLocatorAttributes = $classPropertyReflection->getAttributes(AutowireLocator::class);

		return $this->isAutowireLocatorService($autowireLocatorAttributes, $service);
	}

	/**
	 * @param  array<int, FakeReflectionAttribute|ReflectionAttribute>  $autowireLocatorAttributes
	 */
	private function isAutowireLocatorService(array $autowireLocatorAttributes, ServiceDefinition $service): bool
	{
		foreach ($autowireLocatorAttributes as $autowireLocatorAttribute) {
			foreach ($autowireLocatorAttribute->getArgumentsExpressions() as $autowireLocatorServices) {
				if (!$autowireLocatorServices instanceof Node\Expr\Array_) {
					continue;
				}

				foreach ($autowireLocatorServices->items as $autowireLocatorServiceNode) {
					/** @var Node\Expr\ArrayItem $autowireLocatorServiceNode */
					$autowireLocatorServiceExpr = $autowireLocatorServiceNode->value;

					switch (get_class($autowireLocatorServiceExpr)) {
						case Node\Scalar\String_::class:
							$autowireLocatorServiceClass = $autowireLocatorServiceExpr->value;
							break;
						case Node\Expr\ClassConstFetch::class:
							$autowireLocatorServiceClass = $autowireLocatorServiceExpr->class instanceof Node\Name
								? $autowireLocatorServiceExpr->class->toString()
								: null;
							break;
						default:
							$autowireLocatorServiceClass = null;
					}

					if ($service->getId() === $autowireLocatorServiceClass) {
						return true;
					}
				}
			}
		}

		return false;
	}

}
