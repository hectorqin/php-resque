<?php
namespace Resque\Tests;

use Resque\Job;
use Resque\Job\JobStatus;
use Resque\Log;
use Resque\Resque;
use Resque\Worker;

/**
 * JobStatus tests.
 *
 * @package        Resque/Tests
 * @author        Chris Boulton <chris@bigcommerce.com>
 * @license        http://www.opensource.org/licenses/mit-license.php
 */
class Resque_Tests_JobStatusTest extends TestCase
{
    /**
     * @var \Resque\Worker
     */
    protected $worker;

    public function setUp()
    {
        parent::setUp();

        // Register a worker to test with
        $this->worker = new Worker('jobs');
        $this->worker->setLogger(new Log());
    }

    public function testJobStatusCanBeTracked()
    {
        $token  = Resque::enqueue('jobs', 'Test_Job', null, true);
        $status = new JobStatus($token);
        $this->assertTrue($status->isTracking());
    }

    public function testJobStatusIsReturnedViaJobInstance()
    {
        $token = Resque::enqueue('jobs', 'Test_Job', null, true);
        $job   = Job::reserve('jobs');
        $this->assertEquals(JobStatus::STATUS_WAITING, $job->getStatus());
    }

    public function testQueuedJobReturnsQueuedStatus()
    {
        $token  = Resque::enqueue('jobs', 'Test_Job', null, true);
        $status = new JobStatus($token);
        $this->assertEquals(JobStatus::STATUS_WAITING, $status->get());
    }

    public function testRunningJobReturnsRunningStatus()
    {
        $token = Resque::enqueue('jobs', 'Failing_Job', null, true);
        $job   = $this->worker->reserve();
        $this->worker->workingOn($job);
        $status = new JobStatus($token);
        $this->assertEquals(JobStatus::STATUS_RUNNING, $status->get());
    }

    public function testFailedJobReturnsFailedStatus()
    {
        $token = Resque::enqueue('jobs', 'Failing_Job', null, true);
        $this->worker->work(0);
        $status = new JobStatus($token);
        $this->assertEquals(JobStatus::STATUS_FAILED, $status->get());
    }

    public function testCompletedJobReturnsCompletedStatus()
    {
        $token = Resque::enqueue('jobs', 'Test_Job', null, true);
        $this->worker->work(0);
        $status = new JobStatus($token);
        $this->assertEquals(JobStatus::STATUS_COMPLETE, $status->get());
    }

    public function testStatusIsNotTrackedWhenToldNotTo()
    {
        $token  = Resque::enqueue('jobs', 'Test_Job', null, false);
        $status = new JobStatus($token);
        $this->assertFalse($status->isTracking());
    }

    public function testStatusTrackingCanBeStopped()
    {
        JobStatus::create('test');
        $status = new JobStatus('test');
        $this->assertEquals(JobStatus::STATUS_WAITING, $status->get());
        $status->stop();
        $this->assertFalse($status->get());
    }

    public function testRecreatedJobWithTrackingStillTracksStatus()
    {
        $originalToken = Resque::enqueue('jobs', 'Test_Job', null, true);
        $job           = $this->worker->reserve();

        // Mark this job as being worked on to ensure that the new status is still
        // waiting.
        $this->worker->workingOn($job);

        // Now recreate it
        $newToken = $job->recreate();

        // Make sure we've got a new job returned
        $this->assertNotEquals($originalToken, $newToken);

        // Now check the status of the new job
        $newJob = Job::reserve('jobs');
        $this->assertEquals(JobStatus::STATUS_WAITING, $newJob->getStatus());
    }
}
