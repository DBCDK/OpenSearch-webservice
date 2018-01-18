#!/usr/bin/env python
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
import difflib
import os
from lxml import etree
from configobj import ConfigObj
import subprocess
import urllib2

####################################################################################################
### Xpaths for nodes that should be filtered from response xml before comparison.
### If these nodes exist in either the expected or actual responses,
### they are removed before comparison is done.
NAMESPACES = {'SOAP-ENV': 'http://schemas.xmlsoap.org/soap/envelope/',
              'oss': 'http://oss.dbc.dk/ns/opensearch'}

IGNORE = [ '/SOAP-ENV:Envelope/SOAP-ENV:Body/oss:searchResponse/oss:result/oss:statInfo',
           '/SOAP-ENV:Envelope/SOAP-ENV:Body/oss:searchResponse/oss:result/oss:searchResult/oss:collection/oss:object/oss:queryResultExplanation' ]

####################################################################################################
CONFIG_FILE = 'config.ini' ### used internally to store testfolder paths


def retrieve_requests_and_response_files(request_folder, response_folder):
    """ Generator function that yields pairs of request and response file paths"""

    for root, dirs, files in os.walk(request_folder):
        if '.svn' in dirs:
            dirs.remove('.svn')

        relpath = os.path.relpath(root, request_folder)
        for f in files:

            request_file = os.path.abspath(os.path.join( request_folder, relpath, f))
            response_file = os.path.abspath(os.path.join( response_folder, relpath, f))

            if not os.path.exists(response_file):
                err_mesg = "could not find response matching request file at %s. (expected response file: %s)"%(request_file, response_file)
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
                                filter(rm_blanks, expected_response.split('\n')), lineterm='' )
    return '\n'.join([x for x in diff])


def retrieve_response(url, request_string):
    """ POSTS request to server at url and returns response"""

    request = urllib2.Request(url, request_string, headers={'Content-type': 'text/xml'})
    response = urllib2.urlopen(request)
    return response.read()


def prune_and_prettyprint(xml_string):
    """ removed nodes found in the IGNORE list and pretty print xml"""
    parser = etree.XMLParser(remove_blank_text=True, encoding="UTF-8")
    xml = etree.fromstring(xml_string, parser)

    for path in IGNORE:
        nodes = xml.xpath(path, namespaces=NAMESPACES)
        for node in nodes:
            node.getparent().remove(node)

    return etree.tostring(xml, pretty_print=True)


def compare(request_file, response_file, url):
    """ Compare function. Raises an assertionError if diff is found between request_file and response_file """
    actual_response = prune_and_prettyprint(retrieve_response(url, read_file(request_file)))
    expected_response = prune_and_prettyprint(read_file(response_file))

    diff = generate_diff(actual_response, expected_response)
    if diff != '':
        raise AssertionError("comparison produced diff: (expected response: %s)\n%s"%(response_file, diff))


def test_webservice():
    """ Test Generator """
    requests_folder, responses_folder, url = read_config(CONFIG_FILE)

    for request_file, response_file in retrieve_requests_and_response_files(requests_folder, responses_folder):
        yield compare, request_file, response_file, url


if __name__ == '__main__':

    from optparse import OptionParser

    url = 'http://lakiseks.dbc.dk/test/'

    usage = "usage: %prog [options] request-folder response-folder"
    parser = OptionParser(usage=usage)
    parser.add_option("-u", "--url", dest="url",
                      help="Base url of the webservice to run test again. Default is '%s'"%url)

    (options, args) = parser.parse_args()

    if len(args) < 2:
        parser.error('request-folder and response-folder must be given as parameters')

    if options.url:
        url = options.url

    ### The config file is read by the test generator, when nosetests is called.
    config = ConfigObj()
    config['request_folder'] = args[0]
    config['response_folder'] = args[1]
    config['url'] = url
    config.filename = CONFIG_FILE
    config.write()

    print "Running Tests:"
    print "request-folder  : %s"%args[0]
    print "response-folder : %s"%args[1]
    print "webservice      : %s"%url

    subprocess.call(["nosetests", "-s", "--with-xunit", "search_webservice_tester.py"])

    os.remove(CONFIG_FILE)
