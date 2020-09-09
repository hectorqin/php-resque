<?php
namespace Resque\Excetion;

use Resque\Exception;

/**
* Exception thrown whenever an invalid timestamp has been passed to a job.
*
* @package		ResqueScheduler
* @author		Chris Boulton <chris@bigcommerce.com>
* @copyright	(c) 2012 Chris Boulton
* @license		http://www.opensource.org/licenses/mit-license.php
*/
class InvalidTimestampException extends Exception
{

}