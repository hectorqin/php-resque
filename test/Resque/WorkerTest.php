<?php
namespace Resque\Tests;

use Resque\Job;
use Resque\Log;
use Resque\Resque;
use Resque\Stat;
use Resque\Worker;

/**
 * Worker tests.
 *
 * @package        Resque/Tests
 * @author        Chris Boulton <chris@bigcommerce.com>
 * @license        http://www.opensource.org/licenses/mit-license.php
 */
class WorkerTest extends TestCase
{
    public function testWorkerRegistersInList()
    {
        $worker = new Worker('*');
        $worker->setLogger(new Log());
        $worker->registerWorker();

        // Make sure the worker is in the list
        $this->assertTrue((bool) $this->redis->sismember('resque:workers', (string) $worker));
    }

    public function testGetAllWorkers()
    {
        $num = 3;
        // Register a few workers
        for ($i = 0; $i < $num; ++$i) {
            $worker = new Worker('queue_' . $i);
            $worker->setLogger(new Log());
            $worker->registerWorker();
        }

        // Now try to get them
        $this->assertEquals($num, count(Worker::all()));
    }

    public function testGetWorkerById()
    {
        $worker = new Worker('*');
        $worker->setLogger(new Log());
        $worker->registerWorker();

        $newWorker = Worker::find((string) $worker);
        $this->assertEquals((string) $worker, (string) $newWorker);
    }

    public function testInvalidWorkerDoesNotExist()
    {
        $this->assertFalse(Worker::exists('blah'));
    }

    public function testWorkerCanUnregister()
    {
        $worker = new Worker('*');
        $worker->setLogger(new Log());
        $worker->registerWorker();
        $worker->unregisterWorker();

        $this->assertFalse(Worker::exists((string) $worker));
        $this->assertEquals(array(), Worker::all());
        $this->assertEquals(array(), $this->redis->smembers('resque:workers'));
    }

    public function testPausedWorkerDoesNotPickUpJobs()
    {
        $worker = new Worker('*');
        $worker->setLogger(new Log());
        $worker->pauseProcessing();
        Resque::enqueue('jobs', 'Test_Job');
        $worker->work(0);
        $worker->work(0);
        $this->assertEquals(0, Stat::get('processed'));
    }

    public function testResumedWorkerPicksUpJobs()
    {
        $worker = new Worker('*');
        $worker->setLogger(new Log());
        $worker->pauseProcessing();
        Resque::enqueue('jobs', 'Test_Job');
        $worker->work(0);
        $this->assertEquals(0, Stat::get('processed'));
        $worker->unPauseProcessing();
        $worker->work(0);
        $this->assertEquals(1, Stat::get('processed'));
    }

    public function testWorkerCanWorkOverMultipleQueues()
    {
        $worker = new Worker(array(
            'queue1',
            'queue2',
        ));
        $worker->setLogger(new Log());
        $worker->registerWorker();
        Resque::enqueue('queue1', 'Test_Job_1');
        Resque::enqueue('queue2', 'Test_Job_2');

        $job = $worker->reserve();
        $this->assertEquals('queue1', $job->queue);

        $job = $worker->reserve();
        $this->assertEquals('queue2', $job->queue);
    }

    public function testWorkerWorksQueuesInSpecifiedOrder()
    {
        $worker = new Worker(array(
            'high',
            'medium',
            'low',
        ));
        $worker->setLogger(new Log());
        $worker->registerWorker();

        // Queue the jobs in a different order
        Resque::enqueue('low', 'Test_Job_1');
        Resque::enqueue('high', 'Test_Job_2');
        Resque::enqueue('medium', 'Test_Job_3');

        // Now check we get the jobs back in the right order
        $job = $worker->reserve();
        $this->assertEquals('high', $job->queue);

        $job = $worker->reserve();
        $this->assertEquals('medium', $job->queue);

        $job = $worker->reserve();
        $this->assertEquals('low', $job->queue);
    }

    public function testWildcardQueueWorkerWorksAllQueues()
    {
        $worker = new Worker('*');
        $worker->setLogger(new Log());
        $worker->registerWorker();

        Resque::enqueue('queue1', 'Test_Job_1');
        Resque::enqueue('queue2', 'Test_Job_2');

        $job = $worker->reserve();
        $this->assertEquals('queue1', $job->queue);

        $job = $worker->reserve();
        $this->assertEquals('queue2', $job->queue);
    }

    public function testWorkerDoesNotWorkOnUnknownQueues()
    {
        $worker = new Worker('queue1');
        $worker->setLogger(new Log());
        $worker->registerWorker();
        Resque::enqueue('queue2', 'Test_Job');

        $this->assertFalse($worker->reserve());
    }

    public function testWorkerClearsItsStatusWhenNotWorking()
    {
        Resque::enqueue('jobs', 'Test_Job');
        $worker = new Worker('jobs');
        $worker->setLogger(new Log());
        $job = $worker->reserve();
        $worker->workingOn($job);
        $worker->doneWorking();
        $this->assertEquals(array(), $worker->job());
    }

    public function testWorkerRecordsWhatItIsWorkingOn()
    {
        $worker = new Worker('jobs');
        $worker->setLogger(new Log());
        $worker->registerWorker();

        $payload = array(
            'class' => 'Test_Job',
        );
        $job = new Job('jobs', $payload);
        $worker->workingOn($job);

        $job = $worker->job();
        $this->assertEquals('jobs', $job['queue']);
        if (!isset($job['run_at'])) {
            $this->fail('Job does not have run_at time');
        }
        $this->assertEquals($payload, $job['payload']);
    }

    public function testWorkerErasesItsStatsWhenShutdown()
    {
        Resque::enqueue('jobs', 'Test_Job');
        Resque::enqueue('jobs', 'Invalid_Job');

        $worker = new Worker('jobs');
        $worker->setLogger(new Log());
        $worker->work(0);
        $worker->work(0);

        $this->assertEquals(0, $worker->getStat('processed'));
        $this->assertEquals(0, $worker->getStat('failed'));
    }

    public function testWorkerCleansUpDeadWorkersOnStartup()
    {
        // Register a good worker
        $goodWorker = new Worker('jobs');
        $goodWorker->setLogger(new Log());
        $goodWorker->registerWorker();
        $workerId = explode(':', $goodWorker);

        // Register some bad workers
        $worker = new Worker('jobs');
        $worker->setLogger(new Log());
        $worker->setId($workerId[0] . ':1:jobs');
        $worker->registerWorker();

        $worker = new Worker(array('high', 'low'));
        $worker->setLogger(new Log());
        $worker->setId($workerId[0] . ':2:high,low');
        $worker->registerWorker();

        $this->assertEquals(3, count(Worker::all()));

        $goodWorker->pruneDeadWorkers();

        // There should only be $goodWorker left now
        $this->assertEquals(1, count(Worker::all()));
    }

    public function testDeadWorkerCleanUpDoesNotCleanUnknownWorkers()
    {
        // Register a bad worker on this machine
        $worker = new Worker('jobs');
        $worker->setLogger(new Log());
        $workerId = explode(':', $worker);
        $worker->setId($workerId[0] . ':1:jobs');
        $worker->registerWorker();

        // Register some other false workers
        $worker = new Worker('jobs');
        $worker->setLogger(new Log());
        $worker->setId('my.other.host:1:jobs');
        $worker->registerWorker();

        $this->assertEquals(2, count(Worker::all()));

        $worker->pruneDeadWorkers();

        // my.other.host should be left
        $workers = Worker::all();
        $this->assertEquals(1, count($workers));
        $this->assertEquals((string) $worker, (string) $workers[0]);
    }

    public function testWorkerFailsUncompletedJobsOnExit()
    {
        $worker = new Worker('jobs');
        $worker->setLogger(new Log());
        $worker->registerWorker();

        $payload = array(
            'class' => 'Test_Job',
        );
        $job = new Job('jobs', $payload);

        $worker->workingOn($job);
        $worker->unregisterWorker();

        $this->assertEquals(1, Stat::get('failed'));
    }

    public function testBlockingListPop()
    {
        $worker = new Worker('jobs');
        $worker->setLogger(new Log());
        $worker->registerWorker();

        Resque::enqueue('jobs', 'Test_Job_1');
        Resque::enqueue('jobs', 'Test_Job_2');

        $i = 1;
        while ($job = $worker->reserve(true, 1)) {
            $this->assertEquals('Test_Job_' . $i, $job->payload['class']);

            if ($i == 2) {
                break;
            }

            $i++;
        }

        $this->assertEquals(2, $i);
    }
}
