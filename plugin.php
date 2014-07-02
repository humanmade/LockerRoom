<?php
/**
 * Plugin Name: Locker Room
 * Description: Acquire mutually exclusive locks, using the underlying Memcache object cache
 * Author: Human Made Limited
 * Author URI: http://hmn.md/
 *
 * In a nutshell, this plugin relies on the Memcache backend supporting an
 * atomic command: `add`. This command creates the key and returns successfully,
 * but only if the key does not already exist.
 *
 * Internally, Memcache uses a command queue to which all commands are added.
 * With a naive locking system (using "get" and "set"), it's possible to end up
 * with race conditions. Imagine two clients simulatenously issuing a get, then
 * issuing a set. Typically, the command queue would look like:
 *
 *     get my_lock_key     # Client 1
 *     set my_lock_key 1   # Client 1
 *     get my_lock_key     # Client 2
 *
 * In this scenario, the lock key would be set by client 1, then the "get" by
 * client 2 would find that the key exists. However, this is a race condition,
 * and it's easily possible to end up with the following:
 *
 *     get my_lock_key     # Client 1
 *     get my_lock_key     # Client 2
 *     set my_lock_key 1   # Client 1
 *     set my_lock_key 1   # Client 2
 *
 * In this scenario, both client 1 and 2 check the key before setting, then
 * *both* clients believe they have acquired the lock.
 *
 * Instead, we can issue the atomic "add" command from both clients:
 *
 *     add my_lock_key 1   # Client 1
 *     add my_lock_key 1   # Client 2
 *
 * In this case, whichever command is inserted into the queue first
 * checks-and-sets the key atomically.
 *
 * Releasing the lock is done via a simple "delete" command. See {@see acquire}
 * for the various options relating to expiration and releasing.
 *
 * @package Locker Room
 */

namespace HM\Locker_Room;

/**
 * Store "true" as the lock value
 */
const LOCK_VALUE = true;

/**
 * Memcache flags for the lock key
 */
const LOCK_FLAGS = false;

/**
 * Get cache group for locks
 *
 * @return string Cache group
 */
function get_cache_group() {
	return apply_filters( 'hm_lockerroom_cache_group', 'hm_lockerroom_locks' );
}

/**
 * Get default expiration for locks
 *
 * @return int Default expiration
 */
function get_default_expiration() {
	return apply_filters( 'hm_lockerroom_default_expiration', 15 * MINUTE_IN_SECONDS );
}

/**
 * Acquire a lock from Memcache
 *
 * `add` in Memcache is an atomic operation:
 *
 *   "add" means "store this data, but only if the server *doesn't* already
 *   hold data for this key".
 *
 * This means that `add` will fail if the key is already set, and will return
 * `false`. If it succeeds, we've acquired the lock, and get `true` instead.
 *
 * By default, the lock expiration is set to 15 minutes. This ensures that the
 * lock does not remain indefinitely. The expiration can be set to 0 to not
 * release the lock after a time period, however keep in mind that the lock may
 * remain active permanently if your script exits prematurely. You can set the
 * `autorelease` option to `true` to automatically release the lock on shutdown
 * instead, however it is possible for this not to execute in edge cases
 * (segfaults, or other shutdown handlers forcing exit).
 *
 * @param string $name Unique lock name
 * @param array $options {
 *     @type int $expiration Lock expiration (0 for no lock, null for default ({@see get_default_expiration}))
 *     @type string $group Cache group (Default is {@see get_cache_group})
 *     @type boolean $autorelease Should we autorelease the lock on shutdown?
 * }
 * @return boolean Did we acquire the lock?
 */
function acquire( $name, $options = array() ) {
	global $wp_object_cache;

	$defaults = array(
		'expiration'  => get_default_expiration(),
		'group'       => get_cache_group(),
		'autorelease' => false,
	);

	$options = wp_parse_args( $options, $defaults );

	if ( ! method_exists( $wp_object_cache, 'get_mc' ) ) {
		return false;
	}

	$group = $options['group'];
	$key   = $wp_object_cache->key( $name, $group );
	$mc    = $wp_object_cache->get_mc( $group );

	$result = $mc->add( $key, LOCK_VALUE, LOCK_FLAGS, (int) $options['expiration'] );
	if ( ! $result || ! $options['autorelease'] ) {
		return $result;
	}

	global $hm_lockerroom_autorelease;

	$hm_lockerroom_autorelease[ $key ] = function () use ( $name, $key, $options ) {
		release( $name, $options );

		global $hm_lockerroom_autorelease;

		remove_action( 'shutdown', $hm_lockerroom_autorelease[ $key ] );
	};

	// Autorelease on shutdown
	add_action( 'shutdown', $hm_lockerroom_autorelease[ $key ] );

	return $result;
}

/**
 * Release the lock in Memcache
 *
 * @param string $name Unique lock name
 * @param array $options {
 *     @type string $group Cache group (Default is {@see get_cache_group})
 * }
 * @return boolean Did we release the lock?
 */
function release( $name, $options = array() ) {
	global $wp_object_cache;

	$defaults = array(
		'group'      => get_cache_group(),
	);

	$options = wp_parse_args( $options, $defaults );

	if ( ! method_exists( $wp_object_cache, 'get_mc' ) ) {
		return false;
	}

	$group = $options['group'];
	$key   = $wp_object_cache->key( $name, $group );
	$mc    = $wp_object_cache->get_mc( $group );

	$result = $mc->delete( $key );
	if ( ! $result || doing_action( 'shutdown' ) ) {
		return $result;
	}

	global $hm_lockerroom_autorelease;

	if ( ! empty( $hm_lockerroom_autorelease[ $key ] ) ) {
		// Remove existing autorelease hook
		remove_action( 'shutdown', $hm_lockerroom_autorelease[ $key ] );
	}

	return $result;
}
