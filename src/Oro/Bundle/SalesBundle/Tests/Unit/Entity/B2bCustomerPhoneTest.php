<?php

namespace Oro\Bundle\ContactBundle\Tests\Unit\Entity;

use Oro\Bundle\SalesBundle\Entity\B2bCustomer;
use Oro\Bundle\SalesBundle\Entity\B2bCustomerPhone;

class B2bCustomerPhoneTest extends \PHPUnit_Framework_TestCase
{
    /** @var B2bCustomerPhone */
    protected $phone;

    protected function setUp()
    {
        $this->phone = new B2bCustomerPhone();
    }

    public function testOwner()
    {
        $this->assertNull($this->phone->getOwner());

        $customer = new B2bCustomer();
        $this->phone->setOwner($customer);

        $this->assertEquals($customer, $this->phone->getOwner());
        $this->assertContains($this->phone, $customer->getPhones());
    }
}
