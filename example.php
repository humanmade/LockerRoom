<?php
/**
 * Examples of how to use Locker Room to acquire locks.
 *
 * Also includes examples of how *not* to use Locker Room.
 *
 * @package Locker Room
 */
namespace HM\Locker_Room\Examples;

/**
 * Typical example of how to use Locker Room
 *
 * Uses the default options for locks, which should simply work for most uses.
 */
function regular() {
	// Attempt to acquire the lock
	if ( ! HM\Locker_Room\acquire( 'my_test_hook' ) ) {
		// Someone else is already performing this action, skip for now
		return true;
	}

	// Run a long-running request
	$error = download_internet( '/tmp/internet-copy' );

	// Release the lock as soon as possible
	HM\Locker_Room\release( 'my_test_hook' );

	// Return error if we have one
	if ( is_wp_error( $error ) ) {
		return $error;
	}

	// Do some other operations now that we're done
	update_option( 'my_test_hook_last_run', time() );

	return true;
}

/**
 * Uses the automatic release system
 *
 * Autoreleasing ensures that the locks are correctly released automatically,
 * even if the script exits prematurely.
 *
 * Note that manually releasing the lock *should* still be done for full
 * control of the lock, with the autorelease only used as a backup.
 */
function automatic() {
	$lock_opts = array(
		// Automatically release lock on shutdown
		'autorelease' => true,

		// Set a worst-case scenario expiration
		'expiration'  => 60 * MINUTE_IN_SECONDS,
	);

	// Attempt to acquire the lock
	if ( ! HM\Locker_Room\acquire( 'my_test_hook', $lock_opts ) ) {
		// Someone else is already performing this action, skip for now
		return true;
	}

	// Run a long-running request
	$error = download_internet( '/tmp/internet-copy' );

	// Typically, we'd release the lock here, but there's now no need
	// (You should still release the lock anyway to have full control, but using
	// the autorelease as a backup.)
	# HM\Locker_Room\release( 'my_test_hook' );

	// Error, let's bail!
	if ( is_wp_error( $error ) ) {
		printf( 'An error occurred: %s', $error->get_error_message() );
		exit;

		// At this point, `exit` begins shutting down the request, which calls
		// our autorelease handler on the "shutdown" hook.
	}

	// Do some other operations now that we're done
	update_option( 'my_test_hook_last_run', time() );

	printf( 'Succeeded!' );
	exit;

	// Again, the shutdown hook will fire and automatically release the lock.
}

/**
 * Uses the automatic release system, but will fail to release the lock.
 *
 * Demonstrates an edge case with locking without expiration.
 *
 * ==============================================================
 * |   DO NOT USE THIS! SEE BELOW FOR WHY THIS IS A BAD IDEA!   |
 * ==============================================================
 */
function automatic_edge_case() {
	$lock_opts = array(
		// Automatically release lock on shutdown
		'autorelease' => true,

		// Set no expiration on the key
		//
		// Don't do this! See below for how this can fail.
		'expiration'  => 0,
	);

	// Attempt to acquire the lock
	if ( ! HM\Locker_Room\acquire( 'my_test_hook', $lock_opts ) ) {
		// Someone else is already performing this action, skip for now
		return true;
	}

	// Imagine another plugin has already registered an early shutdown action:
	$priority = -100;
	add_action( 'shutdown', function () {
		// This shutdown action calls exit:
		exit;

		// This causes the shutdown to exit immediately, as noted in the PHP
		// docs:
		// 
		//     If you call exit() within one registered shutdown function,
		//     processing will stop completely and no other registered
		//     shutdown functions will be called.
		//
		// This causes our autorelease action to never get called!

	}, $priority );

	// Our long-running request hits an error:
	$error = new WP_Error();

	// Oh no, we've hit an error, let's bail!
	printf( 'An error occurred: %s', $error->get_error_message() );
	exit;

	// At this point, `exit` begins shutting down the request. This calls the
	// "shutdown" hook, however the early shutdown handler we registered before
	// is called before the lock can be autoreleased.
	//
	// The PHP script will now completely exit, without having called our
	// autorelease system. The lock will never expire, and the task will never
	// be able to run again.
	//
	// This can be solved by setting a sensibly-high expiration. This
	// expiration will typically never be used, but in a scenario like this
	// one, it acts as a backup, and our lock will now expire after the high
	// expiration time instead.
}
