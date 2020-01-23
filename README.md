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

This project depends on a project still in subversion. After checkout from gitlab, 
run the script [`script/bootstrap`](script/bootstrap)
to retrieve this svn dependency:
```bash
$ ./script/bootstrap 
INFO: Checking out svn repo 'https://svn.dbc.dk/repos/php/OpenLibrary/class_lib/trunk' into directory './src/OLS_class_lib'
INFO: Svn repo 'https://svn.dbc.dk/repos/php/OpenLibrary/class_lib/trunk' checked out into directory './src/OLS_class_lib'
INFO: Svn info:
Revision: 122504
Last Changed Author: fvs
Last Changed Rev: 122080
Last Changed Date: 2018-10-02 15:59:39 +0200 (tir, 02 okt 2018)
```

As the name suggests, you can also run this script to update the contents. Changes to the external svn project is handled
as ordinary svn changes.

See the [script/README](script/README.md) for additional info about build scripts.

## Building

The project can be run "as is" in a properly configured Apache webserver, or you can build a docker image to test in.

To build the docker image, in the root directory, use `script/build`. Remember to 
check the options, using `--help`.

The build script requires the [build-dockers.py](https://gitlab.dbc.dk/i-scrum/build-tools) script. You can use this directly, 
e.g. like this:

```bash
build-dockers.py --debug --use-cache
```

Alternatively, you can build the docker image yourself, using plain docker, 
like this, in the top directory:

```bash
docker build -f docker/Dockerfile -t opensearch-ws-local/opensearch-webservice:latest .
``` 

## Running a Server During Development

You can start a server from the docker image, using the scripts

```bash
script/server
```

This uses the compose file set up for the (not yet made) systemtest. The output from 
the log files will be shown in your console. You will have to ask docker for the port
for the system, like this:

```bash
docker inspect --format='{{(index (index .NetworkSettings.Ports "80/tcp") 0).HostPort}}' docker_opensearch-webservice_1
```

The script `client` does this, and tries to start your favorite browser:

```bash
script/client
```
 
If you wish to do it manually, you can do something like this instead:

```bash
firefox localhost:$(docker inspect --format='{{(index (index .NetworkSettings.Ports "80/tcp") 0).HostPort}}' docker_opensearch-webservice_1)/5.2
``` 
 
### Alternative

If you wish to use a different configuration, you can start with one of the two 
environment files in [docker](docker):

```bash
docker run -ti -p 8080:80 --env-file=docker/boble.env opensearch-ws-local/opensearch-webservice:latest
```

## Installation Without Docker
The webservice requires the following files from [class_lib](https://github.com/DBCDK/class_lib-webservice)
to be installed in ./OLS_class_lib
 * aaa_class.php
 * cql2tree_class.php
 * curl_class.php
 * format_class.php (only if openFormat functionality is included in ini-file)
 * inifile_class.php
 * ip_class.php
 * jsonconvert_class.php
 * memcache_class.php
 * objconvert_class.php
 * object_class.php
 * oci_class.php
 * open_agency_v2_class.php
 * registry_class.php
 * restconvert_class.php
 * solr_query_class.php
 * timer_class.php
 * verbose_class.php
 * webServiceServer_class.php
 * xmlconvert_class.php

In the php.ini file:
- make sure that always_populate_raw_post_data = On

Copy opensearch.ini_INSTALL to opensearch.ini and edit it to reflect your setup - inifile settings may be set by environment variables.

Copy opensearch.wsdl_INSTALL to opensearch.wsdl 

Create a symbolic link from index.php to server.php or modify your webserver to default to server.php

Consider copying robots.txt_INSTALL to robots.txt


