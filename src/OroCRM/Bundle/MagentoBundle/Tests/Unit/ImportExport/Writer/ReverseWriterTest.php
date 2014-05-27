<?php

namespace OroCRM\Bundle\MagentoBundle\Tests\Unit\Importexport\Writer;

use Doctrine\ORM\EntityManager;

use Oro\Bundle\AddressBundle\Entity\Country;
use OroCRM\Bundle\ContactBundle\Entity\ContactAddress;
use OroCRM\Bundle\MagentoBundle\Entity\Address;
use OroCRM\Bundle\MagentoBundle\Entity\Customer;
use OroCRM\Bundle\MagentoBundle\Converter\RegionConverter;
use OroCRM\Bundle\MagentoBundle\ImportExport\Processor\AbstractReverseProcessor;
use OroCRM\Bundle\MagentoBundle\Provider\Transport\SoapTransport;
use OroCRM\Bundle\MagentoBundle\ImportExport\Writer\ReverseWriter;
use OroCRM\Bundle\MagentoBundle\ImportExport\Serializer\CustomerSerializer;
use OroCRM\Bundle\MagentoBundle\ImportExport\Strategy\StrategyHelper\AddressImportHelper;

use Oro\Bundle\IntegrationBundle\Entity\Channel;
use Oro\Bundle\IntegrationBundle\Form\EventListener\ChannelFormTwoWaySyncSubscriber;
use Oro\Bundle\AddressBundle\ImportExport\Serializer\Normalizer\AddressNormalizer;

class ReverseWriterTest extends \PHPUnit_Framework_TestCase
{
    const TEST_FIRSTNAME = 'fname';
    const TEST_LASTNAME  = 'lname';

    const TEST_CUSTOMER_ID        = 123;
    const TEST_CUSTOMER_FIRSTNAME = 'customer fname';
    const TEST_CUSTOMER_LASTNAME  = 'customer lname';

    const TEST_ADDRESS_ID              = 123;
    const TEST_ADDRESS_COUNTRY         = 'US';
    const TEST_ADDRESS_REGION          = 'CA';
    const TEST_ADDRESS_REGION_RESOLVED = 'California';
    const TEST_ADDRESS_STREET          = 'test street';

    /** @var EntityManager|\PHPUnit_Framework_MockObject_MockObject */
    protected $em;

    /** @var CustomerSerializer */
    protected $customerSerializer;

    /** @var AddressNormalizer */
    protected $addressNormalizer;

    /** @var SoapTransport|\PHPUnit_Framework_MockObject_MockObject */
    protected $transport;

    /** @var AddressImportHelper|\PHPUnit_Framework_MockObject_MockObject */
    protected $addressImportHelper;

    /** @var RegionConverter|\PHPUnit_Framework_MockObject_MockObject */
    protected $regionConverter;

    /** @var ReverseWriter */
    protected $writer;

    public function setUp()
    {
        $this->em        = $this->getMockBuilder('Doctrine\ORM\EntityManager')
            ->disableOriginalConstructor()->getMock();
        $this->transport = $this->getMockBuilder('OroCRM\Bundle\MagentoBundle\Provider\Transport\SoapTransport')
            ->setMethods(['init', 'call'])
            ->disableOriginalConstructor()->getMock();
        $this->addressImportHelper = $this
            ->getMockBuilder('OroCRM\Bundle\MagentoBundle\ImportExport\Strategy\StrategyHelper\AddressImportHelper')
            ->disableOriginalConstructor()->getMock();
        $this->regionConverter = $this->getMockBuilder('OroCRM\Bundle\MagentoBundle\Converter\RegionConverter')
            ->disableOriginalConstructor()->getMock();

        $this->customerSerializer = new CustomerSerializer($this->em);
        $this->addressNormalizer  = new AddressNormalizer();

        $this->writer = new ReverseWriter(
            $this->em,
            $this->customerSerializer,
            $this->addressNormalizer,
            $this->transport,
            $this->addressImportHelper,
            $this->regionConverter
        );
    }

    public function tearDown()
    {
        unset(
            $this->em,
            $this->customerSerializer,
            $this->addressImportHelper,
            $this->addressNormalizer,
            $this->regionConverter,
            $this->transport,
            $this->writer
        );
    }

    public function testWriteMinimalChanges()
    {
        $transportSetting = $this->getMock('Oro\Bundle\IntegrationBundle\Entity\Transport');
        $channel          = new Channel();
        $channel->setSyncPriority(ChannelFormTwoWaySyncSubscriber::LOCAL_WINS);
        $channel->setTransport($transportSetting);
        $customer = new Customer();
        $customer->setChannel($channel);
        $customer->setFirstName(self::TEST_CUSTOMER_FIRSTNAME);
        $customer->setLastName(self::TEST_CUSTOMER_LASTNAME);
        $customer->setOriginId(self::TEST_CUSTOMER_ID);

        $self = $this;
        $this->transport->expects($this->once())->method('init');
        $this->em->expects($this->once())->method('flush');
        $this->em->expects($this->once())->method('persist')
            ->will(
                $this->returnCallback(
                    function (Customer $customer) use ($self) {
                        $self->assertEquals($customer->getFirstName(), self::TEST_FIRSTNAME);
                        $self->assertEquals($customer->getLastName(), self::TEST_LASTNAME);
                    }
                )
            );

        $this->transport->expects($this->once())->method('call')
            ->with(
                $this->equalTo(SoapTransport::ACTION_CUSTOMER_UPDATE),
                $this->equalTo(
                    [
                        'customerId' => self::TEST_CUSTOMER_ID,
                        'customerData' => ['firstname' => self::TEST_FIRSTNAME, 'lastname' => self::TEST_LASTNAME]
                    ]
                )
            );

        $data = [];
        array_push(
            $data,
            (object)[
                'entity' => $customer,
                'object' => [
                    'first_name' => self::TEST_FIRSTNAME,
                    'last_name'  => self::TEST_LASTNAME
                ]
            ]
        );

        $this->writer->write($data);
    }

    /**
     * @dataProvider removeAddressDataProvider
     *
     * @param bool $remoteResult
     * @param bool $expectedRemove
     */
    public function testRemoveAddress($remoteResult, $expectedRemove)
    {
        $transportSetting = $this->getMock('Oro\Bundle\IntegrationBundle\Entity\Transport');
        $channel          = new Channel();
        $channel->setTransport($transportSetting);
        $customer = new Customer();
        $customer->setChannel($channel);
        $address = new Address();
        $address->setOriginId(self::TEST_ADDRESS_ID);
        $customer->addAddress($address);

        $this->transport->expects($this->once())->method('init');

        $remoteResult = is_bool($remoteResult)
            ? $this->returnValue($remoteResult) : $this->throwException($remoteResult);
        $this->transport->expects($this->at(2))->method('call')
            ->with(
                $this->equalTo(SoapTransport::ACTION_CUSTOMER_ADDRESS_DELETE),
                $this->equalTo(['addressId' => self::TEST_ADDRESS_ID])
            )
            ->will($remoteResult);
        $this->em->expects($this->exactly((int)$expectedRemove))->method('remove');
        $this->em->expects($this->once())->method('flush');

        $data = [];
        array_push(
            $data,
            (object)[
                'entity' => $customer,
                'object' => [
                    'addresses' => [
                        [
                            'entity' => $address,
                            'status' => AbstractReverseProcessor::DELETE_ENTITY
                        ]
                    ],
                ]
            ]
        );

        $this->writer->write($data);
    }

    /**
     * @return array
     */
    public function removeAddressDataProvider()
    {
        $exception         = new \Exception();
        $notFoundException = new \SoapFault(
            (string)SoapTransport::SOAP_FAULT_ADDRESS_DOES_NOT_EXIST,
            'Address not found'
        );

        return [
            'removed on remote side correctly'      => [true, true],
            'not removed on remote side'            => [false, false],
            'address does not exist on remote side' => [$notFoundException, true],
            'remote fault unknown error'            => [$exception, false],
        ];
    }

    public function testAddressCreateDefaultData()
    {
        $transportSetting = $this->getMock('Oro\Bundle\IntegrationBundle\Entity\Transport');
        $channel          = new Channel();
        $channel->setTransport($transportSetting);
        $customer = new Customer();
        $customer->setOriginId(self::TEST_CUSTOMER_ID);
        $customer->setChannel($channel);
        $customer->setFirstName(self::TEST_CUSTOMER_FIRSTNAME);
        $customer->setLastName(self::TEST_CUSTOMER_LASTNAME);
        $address = new ContactAddress();
        $address->setFirstName(self::TEST_FIRSTNAME);
        $address->setCountry(new Country(self::TEST_ADDRESS_COUNTRY));
        $address->setRegionText(self::TEST_ADDRESS_REGION);
        $address->setStreet(self::TEST_ADDRESS_STREET);

        $this->transport->expects($this->once())->method('init');
        $this->regionConverter->expects($this->once())->method('toMagentoData')
            ->with($this->identicalTo($address))
            ->will($this->returnValue(['region' => self::TEST_ADDRESS_REGION_RESOLVED, 'region_id' => null]));

        $this->transport->expects($this->at(2))->method('call')
            ->with(
                $this->equalTo(SoapTransport::ACTION_CUSTOMER_ADDRESS_CREATE),
                $this->equalTo(
                    [
                        'customerId'  => self::TEST_CUSTOMER_ID,
                        'addressData' =>
                            [
                                'telephone'  => 'no phone',
                                'prefix'     => null,
                                'firstname'  => self::TEST_FIRSTNAME,
                                'middlename' => null,
                                'lastname'   => self::TEST_CUSTOMER_LASTNAME,
                                'suffix'     => null,
                                'company'    => null,
                                'street'     =>
                                    [
                                        0 => self::TEST_ADDRESS_STREET,
                                        1 => null,
                                    ],
                                'city'       => null,
                                'postcode'   => null,
                                'country_id' => self::TEST_ADDRESS_COUNTRY,
                                'region'     => self::TEST_ADDRESS_REGION_RESOLVED,
                                'region_id'  => null,
                                'created_at' => null,
                                'updated_at' => null,
                            ],
                    ]
                )
            )
            ->will($this->returnValue(true));
        $this->em->expects($this->atLeastOnce())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $data = [];
        array_push(
            $data,
            (object)[
                'entity' => $customer,
                'object' => [
                    'addresses' => [
                        [
                            'entity'    => $address,
                            'status'    => AbstractReverseProcessor::NEW_ENTITY,
                            'magentoId' => self::TEST_CUSTOMER_ID
                        ]
                    ],
                ]
            ]
        );

        $this->writer->write($data);
    }
}
