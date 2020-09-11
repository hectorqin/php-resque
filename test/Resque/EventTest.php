<?php
namespace Resque\Tests;

use Resque\Event;
use Resque\Job;
use Resque\Job\DontCreate;
use Resque\Job\DontPerform;
use Resque\Log;
use Resque\Resque;
use Resque\Worker;

/**
 * Event tests.
 *
 * @package        Resque/Tests
 * @author        Chris Boulton <chris@bigcommerce.com>
 * @license        http://www.opensource.org/licenses/mit-license.php
 */
class EventTest extends TestCase
{
    private $callbacksHit = array();

    public function setUp()
    {
        Test_Job::$called = false;

        // Register a worker to test with
        $this->worker = new Worker('jobs');
        $this->worker->setLogger(new Log());
        $this->worker->registerWorker();
    }

    public function tearDown()
    {
        Event::clearListeners();
        $this->callbacksHit = array();
    }

    public function getEventTestJob()
    {
        $payload = array(
            'class' => 'Test_Job',
            'args'  => array(
                array('somevar'),
            ),
        );
        $job         = new Job('jobs', $payload);
        $job->worker = $this->worker;
        return $job;
    }

    public function eventCallbackProvider()
    {
        return array(
            array('beforePerformJob', 'beforePerformJobEventCallback'),
            array('afterPerformJob', 'afterPerformJobEventCallback'),
            array('afterForkExecutor', 'afterForkExecutorCallback'),
        );
    }

    /**
     * @dataProvider eventCallbackProvider
     */
    public function testEventCallbacksFire($event, $callback)
    {
        Event::listen($event, array($this, $callback));

        $job = $this->getEventTestJob();
        $this->worker->perform($job);
        $this->worker->work(0);

        $this->assertContains($callback, $this->callbacksHit, $event . ' callback (' . $callback . ') was not called');
    }

    public function testBeforeForkExecutorEventCallbackFires()
    {
        $event    = 'beforeForkExecutor';
        $callback = 'beforeForkExecutorEventCallback';

        Event::listen($event, array($this, $callback));
        Resque::enqueue('jobs', 'Test_Job', array(
            'somevar',
        ));
        $job = $this->getEventTestJob();
        $this->worker->work(0);
        $this->assertContains($callback, $this->callbacksHit, $event . ' callback (' . $callback . ') was not called');
    }

    public function testBeforeEnqueueEventCallbackFires()
    {
        $event    = 'beforeEnqueue';
        $callback = 'beforeEnqueueEventCallback';

        Event::listen($event, array($this, $callback));
        Resque::enqueue('jobs', 'Test_Job', array(
            'somevar',
        ));
        $this->assertContains($callback, $this->callbacksHit, $event . ' callback (' . $callback . ') was not called');
    }

    public function testBeforePerformEventCanStopWork()
    {
        $callback = 'beforePerformJobEventDontPerformCallback';
        Event::listen('beforePerformJob', array($this, $callback));

        $job = $this->getEventTestJob();

        $this->assertFalse($job->perform());
        $this->assertContains($callback, $this->callbacksHit, $callback . ' callback was not called');
        $this->assertFalse(Test_Job::$called, 'Job was still performed though Resque_Job_DontPerform was thrown');
    }

    public function testBeforeEnqueueEventStopsJobCreation()
    {
        $callback = 'beforeEnqueueEventDontCreateCallback';
        Event::listen('beforeEnqueue', array($this, $callback));
        Event::listen('afterEnqueue', array($this, 'afterEnqueueEventCallback'));

        $result = Resque::enqueue('test_job', 'TestClass');
        $this->assertContains($callback, $this->callbacksHit, $callback . ' callback was not called');
        $this->assertNotContains('afterEnqueueEventCallback', $this->callbacksHit, 'afterEnqueue was still called, even though it should not have been');
        $this->assertFalse($result);
    }

    public function testAfterEnqueueEventCallbackFires()
    {
        $callback = 'afterEnqueueEventCallback';
        $event    = 'afterEnqueue';

        Event::listen($event, array($this, $callback));
        Resque::enqueue('jobs', 'Test_Job', array(
            'somevar',
        ));
        $this->assertContains($callback, $this->callbacksHit, $event . ' callback (' . $callback . ') was not called');
    }

    public function testStopListeningRemovesListener()
    {
        $callback = 'beforePerformJobEventCallback';
        $event    = 'beforePerformJob';

        Event::listen($event, array($this, $callback));
        Event::stopListening($event, array($this, $callback));

        $job = $this->getEventTestJob();
        $this->worker->perform($job);
        $this->worker->work(0);

        $this->assertNotContains($callback, $this->callbacksHit,
            $event . ' callback (' . $callback . ') was called though Event::stopListening was called'
        );
    }

    public function beforePerformJobEventDontPerformCallback($instance)
    {
        $this->callbacksHit[] = __FUNCTION__;
        throw new DontPerform();
    }

    public function beforeEnqueueEventDontCreateCallback($queue, $class, $args, $track = false)
    {
        $this->callbacksHit[] = __FUNCTION__;
        throw new DontCreate();
    }

    public function assertValidEventCallback($function, $job)
    {
        $this->callbacksHit[] = $function;
        if (!$job instanceof Job) {
            $this->fail('Callback job argument is not an instance of Resque_Job');
        }
        $args = $job->getArguments();
        $this->assertEquals($args[0], 'somevar');
    }

    public function afterEnqueueEventCallback($class, $args)
    {
        $this->callbacksHit[] = __FUNCTION__;
        $this->assertEquals('Test_Job', $class);
        $this->assertEquals(array(
            'somevar',
        ), $args);
    }

    public function beforeEnqueueEventCallback($job)
    {
        $this->callbacksHit[] = __FUNCTION__;
    }

    public function beforePerformJobEventCallback($job)
    {
        $this->assertValidEventCallback(__FUNCTION__, $job);
    }

    public function afterPerformJobEventCallback($job)
    {
        $this->assertValidEventCallback(__FUNCTION__, $job);
    }

    public function beforeForkExecutorEventCallback($job)
    {
        $this->assertValidEventCallback(__FUNCTION__, $job);
    }

    public function afterForkExecutorCallback($job)
    {
        $this->assertValidEventCallback(__FUNCTION__, $job);
    }
}
