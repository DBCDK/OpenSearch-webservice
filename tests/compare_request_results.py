#!/usr/bin/env python
# -*- coding: utf-8 -*-
# -*- mode: python -*-
"""
This script compares the result of calling two different services, using the same input.

It conceptually works the following way:

1. For each file found in the request folder (can contain subfolders) a request is
   made against both servers given on the command line.

2. For each response pair the two responses are compared. If the
   comparison produces a diff, the test fails.

"""
import difflib
import os
import sys
from lxml import etree
import urllib.request
import re
import argparse
import datetime
import traceback

####################################################################################################
# Xpaths for nodes that should be filtered from response xml before comparison.
# If these nodes exist in the responses,
# they are removed before comparison is done.
NAMESPACES = {'SOAP-ENV': 'http://schemas.xmlsoap.org/soap/envelope/',
              'oss': 'http://oss.dbc.dk/ns/opensearch'}

IGNORE = ['/SOAP-ENV:Envelope/SOAP-ENV:Body/oss:searchResponse/oss:result/oss:statInfo',
          '/SOAP-ENV:Envelope/SOAP-ENV:Body/oss:searchResponse/oss:result/oss:'
          + 'searchResult/oss:collection/oss:object/oss:queryResultExplanation']


################################################################################
# GLOBAL STUFF
################################################################################

# Some global variables that is mostly to handle default values.

# The name of our script - used in output.
script_name = "compare-request-results"

# These are convenient to not have to pass to all functions, etc.
# Could have been wrapped in a class, though.
do_debug = False
do_trace = False
do_response = False

# Use these global variables to track elapsed time for the urls
elapsed_time_url1 = datetime.timedelta(0)
elapsed_time_url2 = datetime.timedelta(0)


################################################################################
# LOG AND OUTPUT STUFF
################################################################################

# I can't figure out how to make this a (static) method in Colors, that can be called by attributes init.
def build_color(num):
    return '\033[' + str(num) + 'm'


class Colors:
    # Control
    NORMAL = build_color(0)
    BOLD = build_color(1)
    UNDERLINE = build_color(4)
    # Colors
    GREEN = build_color(92)
    BLUE = build_color(34)
    YELLOW = build_color(93)
    RED = build_color(91)
    CYAN = build_color(96)
    MAGENTA = build_color(95)
    # Name is script name, rest is levels
    NAME = GREEN
    INFO = GREEN
    WARN = YELLOW
    DRYRUN = YELLOW
    ERROR = RED
    DEBUG = CYAN
    TRACE = MAGENTA
    RESPONSE = MAGENTA
    UNKNOWN = RED
    STAGENAME = BLUE
    CHECKNAME = GREEN

    @staticmethod
    def remove_colors(string: str):
        """
        Remove any color codes from a string, making it suitable for output to file, instead of terminal.
        :param string: The string to remove color codes from.
        :return: The input string, with color codes removed.
        """
        return re.sub('\\033\\[\\d{1,2}m', '', string)


def output_log_msg(msg: str) -> None:
    print(msg, flush=True)


def format_log_msg(level: str, msg: str) -> str:
    """
    Format a string for log output. The level is colorized, if we are in an ssty.
    The datetime added, is localtime.
    :param level: The level (INFO, WARN, DEBUG, ...)
    :param msg: The msg to output_msg.
    :return: A formatted string.
    """
    output = Colors.NAME + "[" + script_name + "] " + Colors.NORMAL + datetime.datetime.now().strftime("%T.%f") + " "
    if level == "DEBUG":
        output += Colors.DEBUG
    elif level == "TRACE":
        output += Colors.TRACE
    elif level == "RESPONSE":
        output += Colors.RESPONSE
    elif level == "INFO":
        output += Colors.INFO
    elif level == "DRYRUN":
        output += Colors.DRYRUN
    elif level == "WARN":
        output += Colors.WARN
    elif level == "ERROR":
        output += Colors.ERROR
    elif level == "TODO":
        output += Colors.YELLOW
    else:
        output += Colors.UNKNOWN
    output += level + Colors.NORMAL + ": " + msg
    if sys.stdout.isatty():
        return output
    else:
        return Colors.remove_colors(output)


def info(msg: str) -> None:
    """
    Output a msg at LOG level.
    :param msg: The message to output.
    """
    output_log_msg(format_log_msg("INFO", msg))


def warn(msg: str) -> None:
    """
    Output a msg at WARN level.
    :param msg: The message to output.
    """
    output_log_msg(format_log_msg("WARN", msg))


def dryrun(msg: str) -> None:
    """
    Output a msg at DRYRUN level.
    :param msg: The message to output.
    """
    output_log_msg(format_log_msg("DRYRUN", msg))


def error(msg: str) -> None:
    """
    Output a msg at ERROR level.
    :param msg: The message to output.
    """
    output_log_msg(format_log_msg("ERROR", msg))


def trace(prefix="") -> None:
    """
    Output a trace at TRACE level, if the global variable "do_trace" is True
    :param: Optional parameter to set before the func name. This can be used by e.g. classes.
    """
    global do_trace
    if do_trace:
        top = traceback.extract_stack(None, 2)[0]
        func_name = top[2]
        output_log_msg(format_log_msg("TRACE", "Entering " + prefix + func_name))


def todo(msg: str) -> None:
    """
    Output a msg at TODO level, if the global variable "do_debug" is True
    :param msg: The message to output.
    """
    global do_debug
    if do_debug:
        output_log_msg(format_log_msg("TODO", msg))


def debug(msg: str) -> None:
    """
    Output a msg at DEBUG level, if the global variable "do_debug" is True
    :param msg: The message to output.
    """
    global do_debug
    if do_debug:
        output_log_msg(format_log_msg("DEBUG", msg))

def response(msg: str) -> None:
    """
    Output a msg at RESPONSE level, if the global variable "do_response" is True
    :param msg: The message to output.
    """
    global do_response
    if do_response:
        output_log_msg(format_log_msg("RESPONSE", msg))


def retrieve_requests_files(request_folder):
    """ Generator function that yields request file paths"""
    trace()
    for root, dirs, files in os.walk(request_folder):
        if '.svn' in dirs:
            dirs.remove('.svn')

        relpath = os.path.relpath(root, request_folder)
        for f in files:

            request_file = os.path.abspath(os.path.join(request_folder, relpath, f))

            yield (request_file)


def read_file(path):
    """ Reads a file and returns data """
    trace()
    with open(path) as filepath:
        data = filepath.read()
    return data


def generate_diff(response1, response2):
    """ generates diff between the responses"""
    trace()
    rm_blanks = lambda x: x != ''
    #debug("Diffing:")
    #debug(response1.decode())
    #debug(response2.decode())

    diff = difflib.unified_diff(list(filter(rm_blanks, response1.decode().split('\n'))),
                                list(filter(rm_blanks, response2.decode().split('\n'))), lineterm='')
    return '\n'.join([x for x in diff])


def retrieve_response(url, request_string, desc, d: dict):
    """ POSTS request to server at url and returns response"""
    trace()
    start_time = datetime.datetime.now()
    request = urllib.request.Request(url,
                                     request_string.encode('utf-8'),
                                     headers={'Content-type': 'text/xml; charset=utf-8'})
    response = urllib.request.urlopen(request)
    stop_time = datetime.datetime.now()
    info("Time passed retrieving from '" + desc + "' : " + str(stop_time - start_time))
    d["res"] += (stop_time - start_time)
    return response.read()


def prune_and_prettyprint(xml_string):
    """ removed nodes found in the IGNORE list and pretty print xml"""
    trace()
    try:
        parser = etree.XMLParser(remove_blank_text=True, encoding="UTF-8")
        xml = etree.fromstring(xml_string, parser)

        for path in IGNORE:
            nodes = xml.xpath(path, namespaces=NAMESPACES)
            for node in nodes:
                node.getparent().remove(node)

        return etree.tostring(xml, pretty_print=True)
    except Exception:
        # Output the argument, so we have a change to determine what happened.
        error("Exception while parsing response. xml_string is : \n" + xml_string.decode())
        # Rethrow to let later stage handle the actual error.
        raise


def compare(request_file, url1, url2):
    """ Compare function. Raises an assertionError if diff is found between request_file and response_file """
    trace()
    global elapsed_time_url1
    global elapsed_time_url2

    info("Getting results for request_file " + request_file)
    debug("Calling url1: " + url1)
    # Sometimes I think Python is really, really heavy
    d = {'res': elapsed_time_url1}
    response1 = prune_and_prettyprint(retrieve_response(url1, read_file(request_file), "golden", d))
    elapsed_time_url1 = d["res"]
    response("Response1 is \n" + response1.decode())
response i
    debug("Calling url2: " + url2)
    d = {'res': elapsed_time_url2}
    response2 = prune_and_prettyprint(retrieve_response(url2, read_file(request_file), "tested", d))
    elapsed_time_url2 = d["res"]
    response("Response2 is \n" + response2.decode())

    debug("Generating diff")
    diff = generate_diff(response1, response2)
    if diff != '':
        error("Test failed")
        debug("reponse from " + url1 + "\n" + response1.decode())
        debug("reponse from " + url2 + "\n" + response2.decode())
        raise AssertionError("comparison produced diff: \n%s" % diff)
    else:
        info("No differences found for request_file " + request_file)


def test_webservice(url1, url2, requests_folder) -> dict:
    """ Test Generator """
    trace()
    passed = 0
    failed = 0
    failed_files = []
    passed_files = []
    for request_file in retrieve_requests_files(requests_folder):
        try:
            compare(request_file, url1, url2)
            debug("Test passed")
            passed_files.append(request_file)
            passed += 1
        except Exception:
            debug("Test failed")
            output_log_msg(traceback.format_exc())
            failed_files.append(request_file)
            failed += 1
    return {'passed': passed, 'failed': failed, 'passed_files': passed_files, 'failed_files': failed_files}


def get_args() -> argparse.Namespace:
    """
    Configure the argument parsing system, and run it, to obtain the arguments given on the commandline.
    :return: The parsed arguments.
    """
    trace()
    parser = argparse.ArgumentParser(formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("url1", help="Server url 1 - golden")
    parser.add_argument("url2", help="Server url 2 - tested")
    parser.add_argument("requests", help="Toplevel request folder")
    parser.add_argument("-d", "--debug", action="store_true",
                        help="Output extra debug information")
    parser.add_argument("-t", "--trace", action="store_true",
                        help="Output trace information - implies debug")
    parser.add_argument("-r", "--response", action="store_true",
                        help="Output response from each call")
    parser.description = "Runs all the requests in the requests folder against both urls, compare results."
    parser.epilog = """
Examples:
    Check two running services using the requests in the requests folder.:
    """ + sys.argv[0] + """ http://localhost:34576 http://localhost:34568 requests/"""
    args = parser.parse_args()
    return args


def main():
    start_time = datetime.datetime.now()
    try:
        global script_name
        args = get_args()

        global do_debug
        do_debug = args.debug

        global do_response
        do_response = args.response

        global do_trace
        do_trace = args.trace
        if do_trace:
            do_debug = True

        debug("cli options: debug:" + str(args.debug))
        info("Comparing '" + args.url1 + "' against '" + args.url2 + "' with request from '" + args.requests + "'")
        result = test_webservice(args.url1, args.url2, args.requests)

        info("Number of tests run    : " + str(result["passed"]+result["failed"]))
        info("Number of tests passed : " + str(result["passed"]))
        info("Number of tests failed : " + str(result["failed"]))

        info("Passed files: " + " ".join(result["passed_files"]))
        info("Failed files: " + " ".join(result["failed_files"]))
        stop_time = datetime.datetime.now()
        info("Time passed: " + str(stop_time - start_time))
        info("Request time, url1 (golden): " + str(elapsed_time_url1))
        info("Request time, url2 (tested): " + str(elapsed_time_url2))

        if result["failed"] > 0:
            error("One or more tests failed. Result is failure.")
            sys.exit(1)
        else:
            info("All requests returned identical answers. Result is success.")
            sys.exit(0)

    except Exception:
        output_log_msg(traceback.format_exc())
        stop_time = datetime.datetime.now()
        info("Time passed: " + str(stop_time - start_time))
        error("Verification " + Colors.RED + "FAILED" + Colors.NORMAL +
              " due to internal error (unhandled exception). Please file a bug report.")
        sys.exit(2)


main()

