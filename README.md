# Locker Room

Acquire mutually exclusive locks in WordPress, using an underlying Memcache
object cache.

Created by Ryan McCue (@rmccue) and Theo Savage (@tcrsavage), at
[Human Made Limited](http://hmn.md/) (@humanmade).


## Requirements

* WordPress 3.9+
* [Memcached Object Cache](http://wordpress.org/plugins/memcached/) 2.0.2+
* [Memcache](http://php.net/memcache) PHP extension
* [memcached](http://memcached.org/) server


## Using Locker Room

Locking involves three main steps: before starting a task, you **acquire** a
lock; next, you **run** the task; once you're finished, you **release** the
lock. We call this the acquire-run-release cycle.

Here's how that looks with Locker Room (test):

```php
// Attempt to **acquire** the lock
if ( ! HM\Locker_Room\acquire( 'my_test_hook' ) ) {
    // Someone else is already performing this action, skip for now
    return true;
}

// **Run** a long-running request
$error = download_internet( '/tmp/internet-copy' );

// **Release** the lock as soon as possible
HM\Locker_Room\release( 'my_test_hook' );

// Return error if we have one
if ( is_wp_error( $error ) ) {
    return $error;
}

return true;
```

More complicated examples are available in `example.php`, included with
the plugin.


### Bonus Features

Locker Room ships with a few niceties that can aid you in using locks correctly:

* **Autoreleasing**

  Typically, lock acquisition follows the acquire-run-release cycle. However,
  it's possible to accidentally `exit` or fatal error during the run phase.
  Locker Room includes the ability to autorelease, which automatically releases
  the lock when your script exits.

  This feature is not enabled by default, due to the "magical" nature of it, so
  you need to be explicit about using it:

  ```php
  $lock_opts = array(
      'autorelease' => true,
  );
  $lock = HM\Locker_Room\acquire( 'my_test_hook', $lock_opts );
  ```

* **Expiration**

  Even when using autoreleasing, it's still possible for locks to never be
  released (such as segfaults in PHP). Typically, you don't want this to stop
  your system from running permanently. Locker Room lets you set an expiration
  for locks (using memcached's expiration time), allowing these locks to be
  released after a reasonable time.

  This feature is enabled by default, with a timeout of 15 minutes. You can
  change this by passing the expiration in seconds:

  ```php
  $lock_opts = array(
      'expiration' => 30 * MINUTE_IN_SECONDS,
  );
  $lock = HM\Locker_Room\acquire( 'my_test_hook', $lock_opts );
  ```

  You can pass an expiration of 0 seconds to disable expiration. The
  `hm_lockerroom_default_expiration` filter can be used to change the default
  expiration.

  **Important note:** Memcache treats expiration times larger than 30 days as
  Unix timestamps. If you want to set the expiration to e.g. 60 days, you need
  to specify this as a Unix timestamp instead (that is, add it to the
  current time):

  ```php
  $lock_opts = array(
      'expiration' => time() + ( 60 * DAY_IN_SECONDS ),
  );
  $lock = HM\Locker_Room\acquire( 'my_test_hook', $lock_opts );
  ```

* **Cache Groups**

  Locker Room allows specifying custom object cache groups.

  By default, `hm_lockerroom_locks` is used as the cache group, however this can
  be overridden by passing a `group` parameter:

  ```php
  $lock_opts = array(
      'group' => 'my_custom_group',
  );
  $lock = HM\Locker_Room\acquire( 'my_test_hook', $lock_opts );
  ```

  For installations with multisite enabled, these locking groups are per-site.
  If you want to use locks globally, you need to use a custom group, and
  register that group with WordPress as global:

  ```php
  wp_cache_add_global_groups( 'global_locks' );
  ```

  You can then use this group as you would any other:

  ```
  $lock_opts = array(
      'group' => 'global_locks',
  );
  $lock = HM\Locker_Room\acquire( 'my_test_hook', $lock_opts );
  ```


## Best Practices

* **Enable autorelease and expiration**

  As a safe-guard against crashing requests, you should always activate both
  autorelease and expiration. Expiration should be set at roughly double your
  average task run time; that is, if your task typically takes 5 minutes to run,
  set the expiration to 10 minutes. This allows your task ample room for edge
  cases, but ensures that your system does not wait around forever.

  Autorelease should almost always be enabled, except when you need to set locks
  that persist after a request. This is a rare use case, however, autoreleasing
  is disabled by default due to the magic of the behind-the-scenes work.

* **Tighten the acquire-run-release cycle**

  Typically, you should acquire and release the lock as close as possible to the
  actual run phase as possible. That means that you should avoid acquiring
  before you do any other checks.

  For example, a cache-busting endpoint may want to ensure that only one
  cache-bust request is sent to the upstream server at a time. In this case, any
  extra HTTP parameters should be checked **before** acquiring the lock, and
  error reporting or response building should be done **after** releasing
  the lock.

  This ensures that your tasks can run as frequently as possible, and that only
  the truly conflicting part of the task needs to be locked out.

  (Obviously, for long-running/slow tasks, the run phase should include these
  expensive requests. That is to say, don't over-tighten your cycle.)


## How It Works

In a nutshell, this plugin relies on the Memcache backend supporting an atomic
command: `add`. This command creates the key and returns successfully, but only
if the key does not already exist.

Check the source of `plugin.php` for more documentation on the specifics of
using the command.


## License

Backdrop is licensed under the GPL version 2.

Copyright 2014 Human Made Limited
