OpenSearch WebService, Copyright(c) 2009, DBC

Introduction
------------

OpenSearch webservice


License
-------
DBC-Software Copyright (c). 2009, Danish Library Center, dbc as.

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


Documentation
-------------
http://oss.dbc.dk/services/open-search-web-service

Use Doxygen to get code documentation

Build
-----

To fetch The Needed OLS_class_lib files run the build.sh script. this script also builds 
the opensearch-webservice.tar.gz file needed for the docker build. 

```bash
./build.sh
(cd docker; docker build -t opensearch:devel . )
docker run -ti -p 8080:80 --env-file=boble.env opensearch:devel
```


Installation
------------
The webservice requires the following files from [class_lib](https://github.com/DBCDK/class_lib-webservice)
to be installed in ./OLS_class_lib
 * aaa_class.php
 * cql2tree_class.php
 * curl_class.php
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


