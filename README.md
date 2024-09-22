## Swoop HTTP Server
Swoop is a Unix HTTP server for PHP based on Python's Gunicorn. Swoop uses the pre-fork worker model for a lightweight and performant solution.

Swoop implements some workers that can be chosen for the case that suits you best. You can choose already implemented synchronous, threaded or fiber workers or implement your own worker.

## Features

- Multiple workers for multiple use cases. (CPU Bound, Memory bound, I/O bound)
- Integrated stats and monitoring
- Low-level events (pre_fork, post_fork, pre_request, etc.)

## Workers

#### SyncWorker
Handles a single request at a time.

#### ThreadedWorker
The threaded worker. It accepts connections in the 
main loop. Accepted connections are added to the 
thread pool as a connection job.

``` 
Threaded Worker requires PHP with ZTS (Zend Thread Safety) and ext-parallel
```

#### AsyncWorker
The asynchronous workers using Fibers.
