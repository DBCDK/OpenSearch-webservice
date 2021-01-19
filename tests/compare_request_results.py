#!/usr/bin/env python3
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
import io
import codecs
import json
import csv
import os
import sys
from lxml import etree
import urllib.request
import requests # Used for get.
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
          + 'searchResult/oss:collection/oss:object/oss:queryResultExplanation',
          '/oss:searchResponse/oss:result/oss:statInfo']


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
# GLobal count of tests, used in output, etc.
count = 1

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


def retrieve_post_response(url, request_string, desc, d: dict):
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
    d["last_timing"] = (stop_time - start_time)
    return response.read()


def retrieve_get_response(url: str, params: dict, desc, d: dict):
    """ GETS request to server at url and returns response"""
    trace()
    start_time = datetime.datetime.now()
    request = requests.get(url, params=params)
    stop_time = datetime.datetime.now()
    debug("URL: " + request.url)
    info("Time passed retrieving from '" + desc + "' : " + str(stop_time - start_time))
    d["res"] += (stop_time - start_time)
    d["last_timing"] = (stop_time - start_time)
    debug("Result is '" + request.text + "'")
    return request.content


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


def compare_xml(request_file, url1, url2):
    trace()
    global count
    global elapsed_time_url1
    global elapsed_time_url2

    info("Test num: " + str(count) + ". Getting results for request_file " + request_file)

    debug("Calling url1: " + url1)
    # Sometimes I think Python is really, really heavy
    d1 = {'res': elapsed_time_url1, 'last_timing': 0}
    response1 = prune_and_prettyprint(retrieve_post_response(url1, read_file(request_file), "golden", d1))
    elapsed_time_url1 = d1["res"]
    response("Response1 is \n" + response1.decode())

    debug("Calling url2: " + url2)
    d2 = {'res': elapsed_time_url2, 'last_timing': 0}
    response2 = prune_and_prettyprint(retrieve_post_response(url2, read_file(request_file), "tested", d2))
    elapsed_time_url2 = d2["res"]
    response("Response2 is \n" + response2.decode())

#    info("Timing difference, url1 - url2: " + str((d1["last_timing"] - d2["last_timing"]).total_seconds()))
    info("Timing difference, test num, abs url1, abs url2, url1 - url2: "
         + "\"" + str(count) + "\";"
         + "\"" + str(d1["last_timing"].total_seconds()) + "\";"
         + "\"" + str(d2["last_timing"].total_seconds()) + "\";"
         + "\"" + str((d1["last_timing"] - d2["last_timing"]).total_seconds()) + "\"")
    count += 1

    debug("Generating diff")
    diff = generate_diff(response1, response2)
    if diff != '':
        error("Test failed")
        debug("reponse from " + url1 + "\n" + response1.decode())
        debug("reponse from " + url2 + "\n" + response2.decode())
        raise AssertionError("comparison produced diff: \n%s" % diff)
    else:
        info("No differences found for request_file " + request_file)


def compare_get(params: dict, url1: str, url2: str):
    trace()
    global count
    global elapsed_time_url1
    global elapsed_time_url2

    info("Test num: " + str(count) + ". Getting results for get request: " + json.dumps(params))

    debug("Calling url1: " + url1)
    # Sometimes I think Python is really, really heavy
    d1 = {'res': elapsed_time_url1, 'last_timing': 0}

    response1 = prune_and_prettyprint(retrieve_get_response(url1, params, "golden", d1))
    elapsed_time_url1 = d1["res"]
    response("Response1 is \n" + response1.decode())

    debug("Calling url2: " + url2)
    d2 = {'res': elapsed_time_url2, 'last_timing': 0}
    response2 = prune_and_prettyprint(retrieve_get_response(url2, params, "tested", d2))
    elapsed_time_url2 = d2["res"]
    response("Response2 is \n" + response2.decode())

#    info("Timing difference, url1 - url2: " + str((d1["last_timing"] - d2["last_timing"]).total_seconds()))
    info("Timing difference, test num, abs url1, abs url2, url1 - url2: "
         + "\"" + str(count) + "\";"
         + "\"" + str(d1["last_timing"].total_seconds()) + "\";"
         + "\"" + str(d2["last_timing"].total_seconds()) + "\";"
         + "\"" + str((d1["last_timing"] - d2["last_timing"]).total_seconds()) + "\"")
    count += 1

    debug("Generating diff")
    diff = generate_diff(response1, response2)
    if diff != '':
        error("Test failed")
        debug("reponse from " + url1 + "\n" + response1.decode())
        debug("reponse from " + url2 + "\n" + response2.decode())
        error("diff:\n%s" % diff)
        raise AssertionError("comparison produced diff: \n%s" % diff)
    else:
        info("No differences found for request")


def compare_csv(request_file, url1, url2, query_status: dict, limit: int) -> bool:
    """
    Compare all requests in a BOM headed csv file against the two base urls.
    Only search as action is supported.
    :param request_file: The CSV file
    :param url1: The baseurl for the golden services
    :param url2: The baseurl for the service under test
    :param query_status: Return value - lists of passed and failed query objects.
    :return: True if no tests failed, False otherwise
    """
    trace()
    global count
    global elapsed_time_url1
    global elapsed_time_url2

    info("Iterating requests in " + request_file)
    res = True

    with open(request_file, newline='', encoding='utf-8-sig') as csv_file:
        reader = csv.DictReader(csv_file, delimiter=",", fieldnames=["query", "agency", "profile", "count", "extras"])
        # Skip first row
        next(reader)
        for row in reader:
            # Remove the count key, add action:search, start and stepValue
            row.pop("count", None)
            row["action"] = "search"
            row["start"] = 0
            row["stepValue"] = 10

            # If there is an extras value, parse and mix it with the parameters
            if row["extras"]:
                info("Found an extras column:'" + str(row["extras"]) + "'")
                extras = json.loads(row["extras"])
                for key in extras.keys():
                    row[key] = extras[key]
                del row["extras"]

            debug("ROWS is: " + json.dumps(row))
            try:
                compare_get(row, url1, url2)
                query_status["passed"].append(row)
            except Exception:
                # This eats any errors.
                query_status["failed"].append(row)
                res = False
            # print(row["query"] + "\n")
            if count > limit:
                info("Limit " + str(limit) + " for number of tests reached")
                break

    return res


def compare(request_file, url1, url2, query_status: dict, limit: int) -> bool:
    """
    Compare request(s) from as file. If the file is .xml, a single request is posted.
    If it is .csv, then all requests in the file is 'getted' against the urls.
    Return code is a mess: True if nothing failed. False or exception if test failed.
    Problem here is .xml path handles a single file, while .csv can have many queries.
    :param request_file: The name of the file
    :param url1: baseurl for golden service
    :param url2: baseurl for tested service
    :param query_status: Return value, list of passed or failed queries, if request_file is .csv
    :return: Return code is a mess: True if nothing failed. False or exception if test failed.
    """
    # We need to do different things, depending on the formats (file extensions, really).
    # .xml: assume classic post
    # .csv: Assume BOM encoded UTF8 requests, which the first line as header, something like:
    # Top 1000 unusual terms in query.keyword	Top 1000 unusual terms in agency.keyword	Top 1000 unusual terms in profile.keyword	Count
    # That is: query, agency, profile, count
    # Count is ignored.

    filename, file_extension = os.path.splitext(request_file)
    if file_extension.lower() == ".xml":
        compare_xml(request_file, url1, url2)
        return True
    elif file_extension.lower() == ".csv":
        return compare_csv(request_file, url1, url2, query_status, limit)
    else:
        raise AssertionError("Unknown file extension: " + file_extension)


def test_webservice(url1, url2, requests_folder, limit: int) -> dict:
    """ Test Generator """
    trace()
    global count
    passed = 0
    failed = 0
    failed_files = []
    passed_files = []
    query_status = {'passed': [], 'failed': []}
    for request_file in retrieve_requests_files(requests_folder):
        try:
            if not compare(request_file, url1, url2, query_status, limit):
                raise AssertionError("One or more tests failed")
            debug("Test passed")
            passed_files.append(request_file)
            passed += 1
        except Exception:
            debug("Test failed")
            output_log_msg(traceback.format_exc())
            failed_files.append(request_file)
            failed += 1
        if count > limit:
            info("Limit " + str(limit) + " for number of tests reached")
            break

    return {'passed': passed, 'failed': failed, 'passed_files': passed_files, 'failed_files': failed_files,
            'failed_queries': query_status["failed"], 'passed_queries': query_status["passed"],}


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
    parser.add_argument("-l", "--limit", default=1000000, type=int,
                        help="Limit number of tests to this limit [1000000]")
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
        info("Timing difference, count, abs url1, abs url2, url1 - url2: "
             + "\"count\";"
             + "\"url1\";"
             + "\"url2\";"
             + "\"url1 - url2\"")

        result = test_webservice(args.url1, args.url2, args.requests, args.limit)

        info("Passed files: " + " ".join(result["passed_files"]))
        if len(result["failed_files"]) > 0:
            warn("Failed files: " + " ".join(result["failed_files"]))
        info("Passed queries: " + json.dumps(result["passed_queries"]))
        if len(result["failed_queries"]) > 0:
            warn("Failed queries: " + json.dumps(result["failed_queries"]))

        info("Number of test files run    : " + str(result["passed"]+result["failed"]))
        info("Number of test files passed : " + str(result["passed"]))
        info("Number of test files failed : " + str(result["failed"]))
        info("Number of get queries run    : " + str(len(result["passed_queries"])+len(result["failed_queries"])))
        info("Number of get queries passed : " + str(len(result["passed_queries"])))
        info("Number of get queries failed : " + str(len(result["failed_queries"])))

        stop_time = datetime.datetime.now()
        info("Time passed: " + str(stop_time - start_time))
        info("Request time, url1 (golden): " + str(elapsed_time_url1))
        info("Request time, url2 (tested): " + str(elapsed_time_url2))
        info("Timing difference, total, abs url1, abs url2, url1 - url2: "
             + "\"total\";"
             + "\"" + str(elapsed_time_url1.total_seconds()) + "\";"
             + "\"" + str(elapsed_time_url2.total_seconds()) + "\";"
             + "\"" + str((elapsed_time_url1 - elapsed_time_url2).total_seconds()) + "\"")

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

