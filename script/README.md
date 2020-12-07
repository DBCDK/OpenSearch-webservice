# Scripts To Rule Them All

This is an experiment, based on the approach from [scripts to rule them all](https://github.com/github/scripts-to-rule-them-all), to
try to make a simple and uniform approach to scripts for a project.

Where name matches the script names from [scripts to rule them all](https://github.com/github/scripts-to-rule-them-all), 
the semantics are the same.

Scripts marked with [extra] are some additional scripts, compared to [scripts to rule them all](https://github.com/github/scripts-to-rule-them-all):

* `boostrap`: Bootstrap the project. Retrieves or update an svn dependency, after initial clone.
* `setup`: Currently calls bootstrap.
* `update` : Currently calls setup.
* `build`: Builds the docker images for the project. [extra]
* `server` : Starts the ws container in the docker directory, using a given datawell
* `client` : Starts a client (browser) for the server started using `server` for a given datawell. [extra]
* `test`: Runs a comparetest for the project, using the server started using `server` and another instance. [extra]
* `test-timings`: Runs a number of requests against a running server, and extract timing information. See below for more information.

The most used scripts are `build` and `test`.

## Analyzing Timings

The script [test-timings](test-timings) will run a number of requests against
a running server (started with `server`) and then extract timing information 
from the logs of the server, and examine this timing information for "coverage", that
is, every non-overlapping timing duration is summed, and it is checked that 
the sum of the information is higher than a given percentage of the total time (98% by default).

The purpose of this script is to verify that any given path taken through the 
server, is covered by timing information. 

The intention is to use this information to be able to develop better timing information, 
when investigating performance of the system.
