# OpenSearch WebService

## Introduction

The OpenSearch webservice can be used to perform searches in the DBC datawell.

## License

DBC-Software Copyright © 2009-2020, Danish Library Center, dbc as.

This library is Open source middleware/backend software developed and distributed 
under the following licenstype:

GNU, General Public License Version 3. If any software components linked 
together in this library have legal conflicts with distribution under GNU 3 it
will apply to the original license type.

Software distributed under the License is distributed on an "AS IS" basis,
WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
for the specific language governing rights and limitations under the
License.

Around this software library an Open Source Community is established. Please
leave back code based upon our software back to this community in accordance to
the concept behind GNU. 

You should have received a copy of the GNU Lesser General Public
License along with this library; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA

## Documentation

See [the online docs](http://oss.dbc.dk/services/open-search-web-service) for information on using the service.

For code documentation, use [Doxygen](http://www.doxygen.nl/) similar to this:
```bash
cd doc
doxygen opensearch.doxygen
firefox api/html/classOpenSearch.html
```

## Getting Started with Development

As of php8+, class_lib is now included as part of this repository, so dependency of subversion is history.

Note, you need the `composer` PHP dependency manager installed for this to work. 
For debian based distros, something like: `sudo apt install composer` should get you going. If it complains
about a missing memcached extension, try: `sudo apt install php-memcached` and you should be good to go.

As the `bootstrap` name suggests, you can also run this script to update the contents. 
Changes to the external svn project is handled as ordinary svn changes.

See the [script/README](script/README.md) for additional info about build scripts.

## Building

The project can be run "as is" in a properly configured Apache webserver, or you can build a docker image to test in.

To build the docker image, in the root directory, use `script/build`. Remember to 
check the options, using `--help`.

```bash
script/build --help
```

The build script requires the [build-dockers.py](https://gitlab.dbc.dk/i-scrum/build-tools) script. You can use this directly, 
e.g. like this:

```bash
build-dockers.py --debug --use-cache
```

Alternatively, you can build the docker image yourself, using plain docker, 
like this, in the top directory:

```bash
docker build -f docker/Dockerfile -t opensearch-ws-local/opensearch-webservice:master .
``` 

Note, however, that if you build the docker images "manually", that the scripts for starting
servers, etc, expects the docker containers to be tagged with the name of the branch. In
the example above, this was "master". So, if you are not on "master", substitute the branch name -- or
use the [build-dockers.py](https://gitlab.dbc.dk/i-scrum/build-tools) script.

## Running a Server During Development

You can start a server from the docker image, using the scripts

```bash
script/server fbstest
```

This uses the compose file in [docker/docker-compose.yml](docker/docker-compose.yml), which is configured
to use the Datawell called `fbstest`. The output from 
the log files will be shown in your console. 

The argument to the `server` script, is the datawell to connect to, one of `fbstest`, 
`boblebad`, or `cisterne`.

## Connecting to the Server Using a Browser

To connect to the server, you will have to ask docker for the port for the system, like this:

```bash
docker inspect --format='{{(index (index .NetworkSettings.Ports "80/tcp") 0).HostPort}}' fbstest_1
```

The script `client` does this, and tries to start your favorite browser:

```bash
script/client fbstest
```

Here, the argument to the `client` script, is the datawell connected to by the server command you 
have issued earlier, again one of `fbstest`, `boblebad`, or `cisterne`.

If you wish to do it manually, you can do something like this instead:

```bash
firefox localhost:$(docker inspect --format='{{(index (index .NetworkSettings.Ports "80/tcp") 0).HostPort}}' fbstest_1)
``` 
 
You can then use the example request to test that the server is functioning.

## Test

There is some functionality to check two OpenSearch servers against eachother, where one
is assumed to be started using `script/server`, in the script `script/test`. This is quite useful, 
when checking e.g. a branch against a "golden master":

### Start a Golden Master

Start the master from a temporary directory somewhere, and get the url for the service:

```bash
$ cd /tmp
$ git clone git@github.com:DBCDK/OpenSearch-webservice.git
...
$ cd OpenSearch-webservice
$ script/bootstrap
$ script/build --pull
...
    opensearch-webservice => opensearch-ws-local/opensearch-webservice:latest
$ script/server <datawell>
```

The above assumes that the master branch is golden (functions correctly).

In another window:
```bash
$ cd /tmp/OpenSearch-webservice
$ script/client <datawell>
...
[client] 15:23:24.290870903 INFO: Starting ws client on http://localhost:32835/5.2/
```

You need to copy/paste the url, inclusive the trailing /.

### Test against a Golden Master

Go back to your branch and build, etc, and start a server:

```bash
...
$ script/server
[server] 15:25:46.194356647 INFO: GIT branch name found in interactive mode, using it for naming docker artifacts, and docker-compose sessions
[server] 15:25:46.196122019 INFO: GIT_BRANCH: SE-2929
[server] 15:25:46.200179706 INFO: Starting redis service, used for caching
Creating network "se-2929_default" with the default driver
Creating se-2929_redis_1 ... done
[server] 15:25:47.991134309 INFO: Starting ws service, based on compose file in /home/mabd/Kode/OpenSearch-webservice/docker
Creating se-2929_opensearch-webservice_1 ... done
...
```

In another window, run the test (from the branch directory):

```bash
$ script/test http://localhost:32835/5.2/ 
[test] 15:28:02.296973529 INFO: GIT branch name found in interactive mode, using it for naming docker artifacts, and docker-compose sessions
[test] 15:28:02.302806127 INFO: GIT_BRANCH: SE-2929
[compare-request-results] 15:28:02.790747 INFO: Comparing 'http://localhost:32835/5.2/' against 'http://localhost:32837/5.2/' with request from '/home/mabd/Kode/OpenSearch-webservice/script/../tests/requests'
...
```

### Test two Different Builds Against Eachother

The file [docker-compose-compare-builds-boblebad.yml](docker/docker-compose-compare-builds-boblebad.yml)
declares a docker-compose network, that grabs two built versions of the OpenSearch webservice, and 
starts them against the boblebad staging environment. This can be used, together with the 
[compare_results_results](tests/compare_request_results.py) script to check these against eachother.

First, edit the [docker-compose-compare-builds-boblebad.yml](docker/docker-compose-compare-builds-boblebad.yml)
file to match the images you wish to test against eachother. Make sure to pull the images:

```bash
cd docker
docker-compose -f docker-compose-compare-builds-boblebad.yml pull
```

Now, in two different terminals, start the containers, like this:

```bash
cd docker
docker-compose -f docker-compose-compare-builds-boblebad.yml up golden
# use different terminal for the next step
docker-compose -f docker-compose-compare-builds-boblebad.yml up tested
```

Then run the compare script like this:

```bash
./tests/compare_request_results.py http://localhost:22222/ http://localhost:33333/ tests/requests/example/
```

*NOTE:* The configuration differs from the boblebad configuration in that AAA is disabled. Also, a number
of the requests actually fail, because the assume repository names from fbstest, etc. To get an overview, use
the `--response` option to track responses.

There is also a similar file for the cisterne environment.

### Additional test options

In the [tests](tests) directory 
there are a number of requst/response pairs that at some time probably worked together.
However, these are heavily dependent on the data, and are probably bit-rot by now.

There is a script called [diff_os](diff_os) that originally could be used to compare 
two OpenSearch servers against eachother. State of this script is unknown.
 
## Alternative Server Start

If you wish to use a different configuration, you can start with one of the two 
environment files in the [docker](docker) directory:

```bash
docker run -ti -p 8080:80 --env-file=docker/boble.env opensearch-ws-local/opensearch-webservice:latest
```

Currently these environment files may not work.

