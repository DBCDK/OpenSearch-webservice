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
* `server` : Starts the ws container in the docker directory.
* `client` : Starts a client (browser) for the server started using `server`. [extra]
* `test`: Runs the systemtest for the project, using docker compose, etc.

The most used scripts are `build` and `test`.