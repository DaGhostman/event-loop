# Introduction

This is the async event loop that entirely relies on PHP 8.1 Fibers
in order to provide native async functionality. By:

1.  Introducing a `coroutine` function to do execution on the event
    loop
2.  Add `tick` function that allows for handling control back to the
    event loop to play nice with other coroutines
3.  ~~Introduce custom stream wrapper (`async://`) that uses signals
    to better break IO operations transparently for the applications.~~
4.  ~~Patching the native `file://` stream to handle all (except `.php` files, since it causes race conditions with autoloader/file inclusion) transparently.~~

# Known issues
When working with `symplely/uv-ffi` you should manually call
`scheduler()->start()` to start the event loop explicitly and
not rely on the automated start, because the FFI extension gets
unloaded, before the full script termination.

---

# Functions

`channel` - a function that creates either buffered or un-buffered
channel for exchanging data between 2 or more coroutines. The
type of channel is determined if any value is provided to its `$size`
parameter

`coroutine` - a function that adds the provided function to the event
loop to be executed with the provided arguments

`tick` - a signal wrapper, that is intended for complex/heavy code in
order to "mark" point at which the function can handle back control to
the event loop in order to allow it to execute other scheduled tasks.
(this should be used for "low level" code and thus transparent for the
end-user)

`signal` - this this function is used to "signal" the event loop to
interrupt the currently running task until the provided callback
indicates that it should be resumed and optionally passing a value
that is the result of the execution

`read` - wait for the given stream to become readable and execute the function provided

`write` - same as `read` but for writing

`is_pending` - checks if a given stream has data that is to be read/written at the time of execution

`sleep` - non-blocking sleep function that suspends the coroutine it is executed in until a given time
