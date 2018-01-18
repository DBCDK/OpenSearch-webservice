#!/usr/bin/env python3
# -*- coding: utf-8 -*-
# -*- mode: python -*-
"""
search_webservice_tester.py is a test generator designed to test the opensearch web service.

It conceptually works the following way:

1. For each file found in the request folder, a matching file is identified
   in the response folder. Files are matched by name, and the request
   and response folders can contain subfolders.

2. For each request/response pair a new unittest is generated.
   The request is sent to the designated server and the returned
   actual response is compared the expected response. If the
   comparison produces a diff, the test fails.

example of usage:

    python tester.py --url http://lakiseks.dbc.dk/test/ requests/ responses/

"""
import argparse
import difflib
import os
from xml.etree import ElementTree
from lxml import etree

from configobj import ConfigObj
import subprocess
import requests

####################################################################################################
### Xpaths for nodes that should be filtered from response xml before comparison.
### If these nodes exist in either the expected or actual responses,
### they are removed before comparison is done.
NAMESPACES = {'SOAP-ENV': 'http://schemas.xmlsoap.org/soap/envelope/',
              'oss': 'http://oss.dbc.dk/ns/opensearch'}

IGNORE = ['/SOAP-ENV:Envelope/SOAP-ENV:Body/oss:searchResponse/oss:result/oss:statInfo',
          '/SOAP-ENV:Envelope/SOAP-ENV:Body/oss:searchResponse/oss:result/oss:searchResult/oss:collection/oss:object/oss:queryResultExplanation']

####################################################################################################
CONFIG_FILE = 'config.ini'  ### used internally to store testfolder paths




def retrieve_requests_and_response_files(request_folder, response_folder):
    """ Generator function that yields pairs of request and response file paths"""

    for root, dirs, files in os.walk(request_folder):
        if '.svn' in dirs:
            dirs.remove('.svn')

        relpath = os.path.relpath(root, request_folder)
        for f in files:

            request_file = os.path.abspath(os.path.join(request_folder, relpath, f))
            response_file = os.path.abspath(os.path.join(response_folder, relpath, f))

            if not os.path.exists(response_file):
                err_mesg = "could not find response matching request file at %s. (expected response file: %s)" % (request_file, response_file)
                raise RuntimeError(err_mesg)

            yield (request_file, response_file)


def read_file(path):
    """ Reads a file and returns data """
    data = ''
    with open(path) as filepath:
        data = filepath.read()
    return data


def read_config(config_file):
    """ Reads config and returns the appropriate keys"""
    config = ConfigObj(config_file)
    return (config['request_folder'], config['response_folder'], config['url'])


def generate_diff(actual_response, expected_response):
    """ generates diff based on actual_response and expected_response strings"""
    rm_blanks = lambda x: x != ''
    diff = difflib.unified_diff(filter(rm_blanks, actual_response.split('\n')),
                                filter(rm_blanks, expected_response.split('\n')), lineterm='')
    return '\n'.join([x for x in diff])


def retrieve_response(url, request_string):
    """ POSTS request to server at url and returns response"""

    headers={'content-type': 'application/x-www-form-urlencoded'}
    r=requests.post(url, data=request_string, headers=headers, allow_redirects=False)
    print("ja7 2: ", r.headers['content-type'], " ", r.status_code)
    print("ja7 3: ", r.content)
    return r.content


def prune_and_prettyprint(xml_string):
    """ removed nodes found in the IGNORE list and pretty print xml"""
    #xml = ElementTree.fromstring(xml_string)
    from io import StringIO, BytesIO

    xml = etree.parse(BytesIO(xml_string))

    for path in IGNORE:
        nodes = xml.xpath(path, namespaces=NAMESPACES)
        for node in nodes:
            print(" found node ", node)
            #node.getparent().remove(node)

    res=etree.tostring(xml, pretty_print=True)
    print("ja7 - 4 : ", res[0:100])

    return res


def compare(request_file, response_file, url):
    """ Compare function. Raises an assertionError if diff is found between request_file and response_file """
    actual_response = prune_and_prettyprint(retrieve_response(url, read_file(request_file)))
    expected_response = prune_and_prettyprint(retrieve_response(url, read_file(request_file)))

    diff = generate_diff(actual_response, expected_response)
    if diff != '':
        raise AssertionError("comparison produced diff: (expected response: %s)\n%s" % (response_file, diff))


def test_webservice():
    """ Test Generator """
    requests_folder, responses_folder, url = read_config(CONFIG_FILE)

    for request_file, response_file in retrieve_requests_and_response_files(requests_folder, responses_folder):
        yield compare, request_file, response_file, url


def parse_arguments():
    global args
    parser = argparse.ArgumentParser("compare results ")
    parser.add_argument("actualUrl", help="Url of opensearch-webservice generating actual results")
    parser.add_argument("expectedUrl", help="Url of opensearch-webservice generating expected results")
    parser.add_argument("requests", help="Url of opensearch-webservice generating expected results")

    args = parser.parse_args()


if __name__ == '__main__':
    
    parse_arguments()

    ### The config file is read by the test generator, when nosetests is called.
    config = ConfigObj()
    config['request_folder'] = args.requests
    config['response_folder'] = args.requests
    config['url'] = args.actualUrl
    config.filename = CONFIG_FILE
    config.write()

    print("Running Tests:")
    print("request-folder  : %s" % config['request_folder'])
    print("webservice      : %s" % config['url'])

    import nose

    #nose.main(argv=["-s", __file__])
    nose.runmodule( argv=["-vv","-s"])
    #nose.main(argv=[__file__, "-vv" "-s", "--with-xunit", "--xunit-file=FILE.xml"])
    #nose.main(argv=["-s", "--with-xunit", "--xunit-file=FILE.xml", __file__])
    #subprocess.call(["nosetests", "-s", "--with-xunit", __file__])

    print("ja7 Exit is run")
    os.remove(CONFIG_FILE)
