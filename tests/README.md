# Compare Tests

This directory contains two scripts that can be used to perform compare testing.

The directory [requests](requests) contain a number of XML/SOAP requests that can be 
sent to a running OpenSearch server. The directory [responses](responses) contains
a set of files matching the content of the [requests](requests) directory, with 
expected responses from the requests.

Note, that responses are heavily dependent on the Datawell used, and other
configuration for the OpenSearch server. This makes it hard to do a comparision
between the actual and expected results. As an alternative, you can test
two servers against eachother. 

## Compare Testing two Servers

You can use the script [compare_request_results.py](compare_request_results.py)
to run requests from a folder against two different servers, and compare the
results.

Most often you will use this to compare a feature branch against the master, 
for the same configuration.

Example of use:

```bash
./compare_request_results.py -d http://localhost:22222/5.2/ http://localhost:33333/5.2/ requests
```

Of, if started using one of the docker-compose files made to start servers locally:

```bash
./compare_request_results.py -d http://localhost:22222/ http://localhost:33333/ requests/issues/
```

Run the script with the `--help` option to get more information.

## Compare Actual Against Expected 

*Note:* As of this writing (January 2020) the script below has not been migrated
to Python 3, and the reponses may be outdated.

You can use the script [search_webservice_tester.py](search_webservice_tester.py) 
as a test generator designed to test the opensearch web service.

It conceptually works the following way:

1. For each file found in the request folder, a matching file is identified
   in the response folder. Files are matched by name, and the request
   and response folders can contain subfolders.

2. For each request/response pair a new unittest is generated.
   The request is sent to the designated server and the returned
   actual response is compared the expected response. If the
   comparison produces a diff, the test fails.

example of usage:

```bash
    python search_webservice_tester.py --url http://lakiseks.dbc.dk/opensearch/ requests/ responses/
```
