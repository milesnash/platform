<?php

namespace Oro\Bundle\ApiBundle\Tests\Unit\Processor\Shared;

use Oro\Bundle\ApiBundle\Processor\Shared\EntityTypeFeatureCheck;
use Oro\Bundle\FeatureToggleBundle\Checker\FeatureChecker;
use Oro\Bundle\ApiBundle\Processor\Context;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class EntityTypeFeatureCheckTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var FeatureChecker|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $featureChecker;

    /**
     * @var EntityTypeFeatureCheck
     */
    protected $processor;

    protected function setUp()
    {
        $this->featureChecker = $this->getMockBuilder(FeatureChecker::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->processor = new EntityTypeFeatureCheck($this->featureChecker);
    }

    public function testProcessDisabled()
    {
        $className = 'TestClass';

        $context = $this->getMockBuilder(Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        $context->expects($this->once())
            ->method('getClassName')
            ->willReturn($className);
        $this->featureChecker->expects($this->once())
            ->method('isResourceEnabled')
            ->with($className, 'api_resources')
            ->willReturn(false);
        $this->setExpectedException(AccessDeniedException::class);

        $this->processor->process($context);
    }

    public function testProcessEnabled()
    {
        $className = 'TestClass';

        $context = $this->getMockBuilder(Context::class)
            ->disableOriginalConstructor()
            ->getMock();
        $context->expects($this->once())
            ->method('getClassName')
            ->willReturn($className);
        $this->featureChecker->expects($this->once())
            ->method('isResourceEnabled')
            ->with($className, 'api_resources')
            ->willReturn(true);

        $this->processor->process($context);
    }
}
