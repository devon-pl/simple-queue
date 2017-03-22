<?php

require_once __DIR__.'/../vendor/autoload.php';

use Aws\Sqs\SqsClient;
use SimpleQueue\Adapter\AwsSqsQueueAdapter;
use SimpleQueue\Job;
use SimpleQueue\Queue;

class AwsSqsQueueAdapterTest extends PHPUnit_Framework_TestCase
{
    /**
     * @var Queue
     */
    protected $queue;

    /**
     * @var SqsClient
     */
    protected $sqsClient;

    public function setUp()
    {
        if (version_compare(PHP_VERSION, '5.5', '<')) {
            $this->markTestSkipped('Test skipped: PHP '.PHP_VERSION);
        }

        $this->sqsClient = $this
            ->getMockBuilder('Aws\Sqs\SqsClient')
            ->disableOriginalConstructor()
            ->setMethods(
                array('getQueueUrl', 'sendMessage', 'receiveMessage', 'deleteMessage', 'changeMessageVisibility')
            )
            ->getMock();

        $mockWithGet = $this->getMockBuilder('Stdclass')
            ->setMethods(array('get'))
            ->getMock();

        $mockWithGet
            ->expects($this->once())
            ->method('get')
            ->will($this->returnValue('MyQueueUrl'));

        $this->sqsClient
            ->method('getQueueUrl')
            ->will($this->returnValue($mockWithGet));

        $this->queue = new Queue(new AwsSqsQueueAdapter('MyQueue', $this->sqsClient));
    }

    public function testPush()
    {
        $this->sqsClient
            ->expects($this->at(0))
            ->method('sendMessage')
            ->with(array(
                'QueueUrl' => 'MyQueueUrl',
                'MessageBody' => '"JobA"'
            ));

        $this->sqsClient
            ->expects($this->at(1))
            ->method('sendMessage')
            ->with(array(
                'QueueUrl' => 'MyQueueUrl',
                'MessageBody' => '"JobB"'
            ));

        $this->queue
            ->push(new Job('JobA'))
            ->push(new Job('JobB'))
        ;
    }

    public function testSchedule()
    {
        $this->sqsClient
            ->expects($this->once())
            ->method('sendMessage')
            ->with(array(
                'QueueUrl' => 'MyQueueUrl',
                'MessageBody' => '"JobA"',
                'DelaySeconds' => 3600
            ));

        $this->queue->schedule(new Job('JobA'), new DateTime('+1hour'));
    }

    public function testPull()
    {
        $this->sqsClient
            ->expects($this->once())
            ->method('receiveMessage')
            ->with(array(
                'QueueUrl' => 'MyQueueUrl',
                'WaitTimeSeconds' => 0
            ))
            ->willReturn(array(
                'Messages' => array(
                    array(
                        'ReceiptHandle' => 123,
                        'Body' => '"SomeData"'
                    )
                )
            ))
        ;

        $job = $this->queue->pull();
        $this->assertInstanceOf(Job::class, $job);
        $this->assertEquals(123, $job->getId());
        $this->assertEquals('SomeData', $job->getBody());
    }

    public function testCompleted()
    {
        $this->sqsClient
            ->expects($this->once())
            ->method('deleteMessage')
            ->with(array(
                'QueueUrl' => 'MyQueueUrl',
                'ReceiptHandle' => 1234
            ));

        $job = new Job('JobA');
        $job->setId(1234);

        $this->queue->completed($job);
    }

    public function testFailed()
    {
        $this->sqsClient
            ->expects($this->once())
            ->method('changeMessageVisibility')
            ->with(array(
                'QueueUrl' => 'MyQueueUrl',
                'ReceiptHandle' => 1234,
                'VisibilityTimeout' => 0
            ));

        $this->queue->failed(new Job('JobA', 1234));
    }
}
